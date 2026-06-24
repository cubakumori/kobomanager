# Architecture — KoboManager

A technical overview for contributors. For setup and commands see [`README.md`](./README.md);
for conventions see [`CONTRIBUTING.md`](./CONTRIBUTING.md).

## What it is

KoboManager is a thin management layer **between KoboToolbox accounts and a small team**.
An administrator connects Kobo accounts (API tokens stored encrypted); other users get
per‑form permissions and review submissions **without a Kobo account and without ever seeing
the token**. Submissions are mirrored into a local cache for fast browsing and an internal
review (approve / on-hold / reject) flow decoupled from Kobo.

## Stack & layout

```
/                Vue 3 + Vite SPA (frontend at the repo root)
  index.html
  src/
    main.js, App.vue
    router/index.js          flat routes; authed area wrapped by meta.shell
    stores/auth.js           Pinia store + apiError() helper
    services/api.js          axios instance (baseURL /api/v1, cookies)
    i18n/locales/{es,en}/    catalogs, one file per area (every string lives in BOTH locales)
    composables/             confirm.js, labels.js, dialogA11y.js
    components/, views/
  style.css                  Tailwind v4 + theme tokens (see Theming)
/api             PHP 8.1+ REST API (no runtime dependencies)
  index.php                  front controller (routing, CORS, CSRF)
  config.php                 secrets — NOT committed (see config.example.php)
  lib/                       one class per file, no namespaces
  v1/                        endpoint scripts, grouped by area
  cron/                      CLI jobs (daily_summary, sync_submissions)
  cli/                       create_user.php, rebuild_search_text.php, rotate_token_key.php
  tests/                     PHPUnit (only dev dependency)
/db              *.sql schema files, applied in order (see Database)
```

The frontend lives at the repo root because that mirrors the deployment layout: the Vite
build (`dist/`) goes to the web root and `/api` sits alongside it (see [`DEPLOY.md`](./DEPLOY.md)).

## Backend

### Front controller & routing
All API requests enter `api/index.php`. It loads `config.php` + `lib/*`, applies CORS,
emits **security headers** (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: no-referrer`, and `Strict-Transport-Security` over HTTPS), enforces CSRF,
resolves the path after `/api/v1/` against a **routes table** (patterns with `:param`
segments) and `require`s the matching script in `v1/`. Dynamic params are read via
`Request::param()`. Each endpoint script checks the HTTP method itself. When demo mode is
on, a central denylist (`Demo::blocks`, keyed by route pattern + method) answers 403
`DEMO_LOCKED` before the endpoint script runs.

### Responses
`ErrorResponse::ok($data)` and `ErrorResponse::send($code, $msg?, $status?)` emit the JSON
envelope (`{success, data}` / `{success, error:{code,message}}`) and `exit`. Error codes map
to HTTP statuses in one table; the frontend maps codes → localized messages (`errors.<CODE>`).

### Auth & sessions (`lib/Auth.php`)
- Hand‑rolled **JWT (HS256)** — no libraries; the decoder rejects any `alg` other than
  HS256. Token travels in an **HttpOnly cookie** (`SameSite=Lax`, `Secure` in prod).
- Every session is a row in `user_sessions` keyed by the JWT `jti`. `currentUser()` validates
  signature + expiry **and** that the `jti` row still exists **and** the user is active — so
  deleting the row (logout / admin remote revoke / self‑service / deactivation) invalidates the
  token.
- **Sliding session.** On each request, if the token is close to expiry
  (`SESSION_REFRESH_THRESHOLD`) `currentUser()` re‑issues it **keeping the same `jti`** (so
  invalidation still works) and pushes `user_sessions.expires_at` forward by the idle TTL,
  bounded by an **absolute cap** from `created_at` (`SESSION_ABSOLUTE_TTL`) after which re‑login
  is forced — limiting a stolen cookie's useful life. No schema change.
- **Self‑service session control.** `GET/DELETE /profile/sessions`: list my active sessions
  (the current one flagged via `Auth::currentTokenId()`) / close all but the current. Mirrors
  the admin remote‑revoke at `/admin/users/{id}/sessions`.
- Guards: `require()`, `requireAdmin()`, and per‑form `canForm($user,$id,$cap)` /
  `requireForm(...)` where `cap ∈ {view,edit,validate}` (admins bypass).

### Row‑level scoping (`lib/RowScope.php`)
A per‑(user, form) filter (`user_form_permissions.row_filter`, JSON) can restrict **which
submissions** a viewer sees, on top of the form capability. The rule is a **2‑level group
tree** — `{match, groups:[{match, conditions:[{field, op, values}]}]}` — where groups are
combined by the root connector and each group's conditions by its own connector (`all`=AND,
`any`=OR). This expresses e.g. *“(province=Habana AND age≥18) OR (province=Santiago AND
sex=F)”*. Each condition has an operator: `in` / `nin` (≠), `lt/lte/gt/gte` (numeric or ISO‑date
range — numeric when the operand is numeric, lexical otherwise), `empty` / `not_empty`, and the
set operators `has_any` / `has_all` / `has_none` for `select_multiple` (codes are space‑separated
in the payload). `NULL`/empty → unrestricted; an `in` with no values matches nothing
(fail‑closed); admins are never restricted. The same rule is applied as a SQL predicate over
`submissions_cache.json_payload` (lists, stats, map, form counts, daily summary) and as an
in‑PHP `matches()` check on the detail/edit/validate path (out‑of‑scope → 404); SQL and PHP
share identical per‑operator semantics (covered by parity tests). The legacy flat format
`{conditions:[{field,values}]}` (AND‑only, implicit `op:in`) is still read and canonicalised by
`normalize()` — no data migration, no schema change. Note: MariaDB stores JSON as text and keeps
the `\/` escape, so group‑path keys (`G01/P1_3`) are matched with an escaped JSON path. The
admin UI builds the rule with a shared `RowFilterEditor.vue` component (reused by both the
Permissions and Share‑links editors), offering operators and value widgets per field type.

**Advanced table filters** reuse the same format and engine: the submissions table (and CSV
export) accept a user‑supplied `filter` query param (JSON, validated by `RowScope::
normalize()`), combined **in AND** with the user's mandatory scope — it can only restrict,
never widen. Conditions referencing fields hidden by `FieldScope` are rejected (422), and
the editor's field/value source for non‑admins is `GET /forms/{id}/scope-fields` (visible
fields only; suggested values constrained to the user's row scope). The filter persists
per form/device in `localStorage` (`km.filter.<formId>`). Map and stats stay on the full
user scope on purpose.

### Column‑level permissions (`lib/FieldScope.php`)
Twin of row scoping: where `RowScope` decides *which submissions* are visible, `FieldScope`
decides *which fields* leave the server. A per‑(user, form) **denylist** of hidden field keys
(`user_form_permissions.field_filter`, JSON `{"hidden":[...]}`) is removed from every read
path; `NULL`/empty → all fields visible (back‑compatible), admins never restricted. The single
source of truth is `FieldScope::apply($rule, $payload, $schema)`, called once per decoded
payload **after** row scoping: it strips hidden data keys, drops `_attachments` whose
`question_xpath` is hidden, and removes `_geolocation` when a geo field is hidden (so the
fallback can’t leak a hidden location). `applySchema()` also strips hidden keys from the
resolved schema so even a field’s **label** isn’t exposed. Enforced in: submission lists,
detail (data + attachments + geo + derived), CSV export (hidden columns dropped), stats
(hidden `select_one` questions excluded), the authenticated and public attachment proxies
(reject a hidden field’s attachment even if the `attId` is guessed), and edit (editing a
hidden field returns 404). **Search**: for a restricted user the global FULLTEXT index over
`search_text` would reveal that a row *contains* a hidden value, so `SubmissionSearch::
clauseVisible()` matches only visible field paths (per‑column `LIKE` with `utf8mb4_unicode_ci`
to stay case/accent‑insensitive; multi‑word = AND). Share links carry the same rule in
`share_links.field_filter`.

**Read‑only fields (third state)**: `field_filter` also accepts `{"readonly":[...]}` —
fields the user *sees* but cannot edit even with `can_edit`. The permissions UI offers a
per‑field tri‑state (Visible / Read‑only / Hidden); `normalize()` keeps the two lists
disjoint (hidden wins). Enforcement: `PUT /submissions/{id}` rejects with 422 any edit
touching a read‑only field (nothing is half‑written to Kobo), and the detail response
returns `readonly_fields` so the UI renders them locked (🔒). Share links don't use
`readonly` (they're read‑only by nature).

### Public share links (`lib/ShareLink.php`)
Read‑only links let anyone browse a form's submissions **without a session** (M1). A
`share_links` row carries an unguessable URL `token`, what it exposes (`expose_list` /
`expose_detail` / `expose_map` / `expose_stats` / `expose_attachments`), an optional
`row_filter` (reuses
`RowScope`), an optional `field_filter` (reuses `FieldScope` — hide columns in the public
view), an optional `password_hash`, and optional `expires_at` / `revoked_at`. `resolve()`
returns the row only while active (not revoked, not expired, form active). The public endpoints
live under `v1/public/` and **skip `Auth`** (like `v1/config.php`); `ShareLink::requireAccess()`
is their guard — it resolves the token, checks the requested capability, and for
password‑protected links requires a short‑lived **HMAC ticket** (issued by the rate‑limited
`unlock` endpoint, sent back via the `X-Share-Ticket` header **or** the `?k=` query param so
plain `<img>`/`<audio>` requests can carry it). Out‑of‑scope or other‑form submissions return
404; the internal review status is never exposed. Admin CRUD is in `v1/admin/shares*`; the
password policy is the `share_password_policy` setting.

**Attachments (P4).** A link may also expose submission attachments through a dedicated public
proxy (`GET /public/share/{token}/submissions/{uid}/attachments/{attId}`,
`share_attachment.php`), guarded by `requireAccess(token, 'attachments')`. The proxy downloads
the file with the Kobo account token (which **never** reaches the browser), after re‑validating
row scope and that the attachment belongs to the submission. Because attachments often carry
sensitive PII, they require **two layers**: the link must have a password **and** the global
`share_attachments_policy` setting (`off` | `require_password`, default `off`) must allow it —
checked both at link creation and **live on every request** (a *kill‑switch*). Attachment
listing/grouping is centralised in `lib/Attachments.php` (`forPayload`/`kind`, five kinds:
image/audio/video/document/file), reused by the authenticated detail and the public detail; the
frontend renders it with the shared `AttachmentsGallery.vue` component. *(Per‑request rate
limiting of the public GETs is still deferred to M4b/M5.)*

### Batch review & CSV export
`POST /forms/{id}/review` (`forms/review_batch.php`) applies one review status
(`approved` / `on_hold` / `rejected` / `pending`) to many
submissions in a single transaction; it requires `validate` once and **re‑checks**, per uid,
form membership and row scope server‑side (out‑of‑scope/foreign uids are silently skipped),
returning `{applied, skipped}`. **Archived forms are read‑only for review**: both this
endpoint and the single `POST /submissions/{id}/review` reject with `FORM_ARCHIVED` (409)
when `forms.deployment_status = 'archived'` — the data and prior review decisions stay
visible, but no new ones are recorded (the frontend hides the selection/review controls;
this is the server‑side backstop). Editing is unaffected (still gated by `can_edit`). `GET /forms/{id}/export` (`forms/export.php`) streams a
UTF‑8 **CSV with BOM** of the submissions (requires `view`, honors row scope and the
list filters); it bypasses the JSON envelope and resolves question/option labels per the
global label mode. Note: PHP 8.4+ requires the `fputcsv` `$escape` argument explicitly
(passed as `''` → standard CSV quoting).

### CSRF
For mutating methods (POST/PUT/DELETE/PATCH) the front controller requires the request
`Origin`/`Referer` to match an allowed origin (`CORS_ALLOWED_ORIGINS` + the server's own
host); requests with neither header (CLI/cron) are exempt. Reinforces the `SameSite=Lax` cookie.

### Rate limiting (`lib/RateLimit.php`)
IP‑based counting within a time window. Two backends: `login_attempts` (login, forgot‑password,
share unlock — supports `clear()` on success) and a generic **bucketed** `rate_hits`
(`tooManyBucket`/`hitBucket`, with opportunistic pruning) kept separate so public‑read
throttling never trips the login throttle. `ShareLink::throttle()` uses the `share` bucket to
cap public share GETs at 240 req/60 s per IP (anti‑scraping/DoS on a leaked link).

### Demo mode (`lib/Demo.php`)
Optional `DEMO_MODE` / `DEMO_RESET_MINUTES` / `DEMO_LOGIN_ADMIN`+`DEMO_LOGIN_VIEWER` constants (guarded with
`defined()`, so existing configs behave as demo off) turn the instance into a public
sandbox: `GET /config` exposes the three values, the frontend shows a welcome dialog on
the homepage plus a clickable **DEMO** badge next to the brand, and blocked buttons are
disabled with a tooltip. Enforcement is central: a route-pattern + method denylist in the
front controller returns 403 `DEMO_LOCKED` for anything that would break the demo or leak
secrets (Kobo account CRUD, user/password/session management, global settings, submission
editing, manual sync). Everything local that a periodic DB reset restores stays enabled.
Operational guide (synthetic seeding via `api/cli/seed_demo.php`, seed dump, reset cron, hardening): [`DEMO.md`](DEMO.md).

### Attachment proxies & CSV hardening
The attachment proxies (`submissions/{id}/attachments/...` and the public share one) stream
third‑party files; they set `Content-Security-Policy: default-src 'none'; sandbox`, serve only
image/audio/video **inline** (everything else `Content-Disposition: attachment`), and rely on
the global `nosniff`. `KoboClient::getAttachment` follows storage redirects only to HTTP(S)
with a hop cap (anti‑SSRF). CSV export (`forms/export.php`) prefixes any cell starting with
`= + - @`/tab/CR with an apostrophe to defuse spreadsheet formula injection.

### Kobo integration (`lib/KoboClient.php`)
Talks to the **Kobo API v2** with the account token (cURL, no SDK). Discovery lists `survey`
assets; submissions are fetched paginated; edits use `PATCH .../data/bulk/`. Errors become
`KoboException` with standard codes. **Submission edits** (`editSubmission`) write to Kobo
first and only then update the cache. Two Kobo behaviours are handled explicitly: (1) the
bulk endpoint returns `HTTP 200` even when the per‑submission update fails (detail lives in
`failures`/`results[].status_code`), so the body is inspected and a failure throws
`KOBO_EDIT_FAILED`; (2) an edit creates a **new submission version with a new `_uuid`** (the
numeric `_id` is preserved), so `editSubmission` returns that new `_uuid` and
`v1/submissions/item.php` migrates the cache key (`submissions_cache.submission_uid`) and the
review history (`submission_reviews.submission_uid`) old→new in a transaction — keeping
continuity across edits and preventing a phantom delete/re‑insert on the next *full* resync.
The token is decrypted on the fly with `TokenVault`
(libSodium `secretbox`; master key only in `config.php`). `TokenVault` takes an optional
explicit key, which lets `cli/rotate_token_key.php` re‑encrypt every account from the old key
to a new one (key rotation; see `DEPLOY.md §12`).

### Sync model
- **Forms discovery** (`v1/admin/forms_sync.php`): upserts `forms`, filters by the
  `sync_deployment_statuses` setting, **deactivates** forms that fall outside the filter and
  **deletes** ones removed from Kobo. For a **newly discovered** form it also pulls its
  submissions once (initial backfill) so it doesn't show «0» until the next cron tick;
  `forms.submissions_synced_at` marks whether submissions have ever been synced (NULL → the
  UI shows “not synced yet” instead of a misleading «0», and triggers the one‑time backfill).
- **Submissions** (`lib/SubmissionSync.php`, cron + on demand): *incremental* (cursor =
  `MAX(submitted_at)` in cache) with a deletion sweep, or *full* (re‑download + reconcile by
  `_uuid`, reflecting edits made in Kobo). Reused by admin and viewer sync endpoints.
- **Readable labels** (`lib/FormSchema.php`): caches a normalized XLSForm schema per form
  (`forms.schema_json`) so the UI shows question/option labels instead of raw codes.
- **Geo** (`lib/Geo.php`): parses geopoint/geotrace/geoshape for the map view.
- **Derived values** (`lib/Derived.php`): pure helper that computes per‑submission metrics not
  shipped by Kobo (duration `end − start`, completeness, upload delay, attachments by kind,
  has‑geo, submission hour/day, `_submitted_by`, `__version__`, Kobo `_validation_status`,
  tags/notes counts). Computed in the backend alongside `label_mode`/`field_truncate` and
  reused identically by the submission list (optional table columns), the detail (a *Summary*
  section) and the CSV export. Operates only on already‑authorized payloads, so it inherits
  permissions/row‑scoping for free. `FormSchema::normalize` records `start`/`end`/`today` meta
  field names (`schema_json.meta`) so durations work even with non‑standard field names.
  Timestamps are anchored as **UTC** when zone‑less (as Kobo ships `_submission_time`), and the
  submission **hour/weekday** are then converted to the display zone `APP_TIMEZONE` (IANA, default
  `UTC`) — per‑instant, so DST is respected. `Derived::tzMeta()` exposes the zone (id, human label
  from `APP_TIMEZONE_LABEL`, and a `UTC±N` offset) to the stats UI.
- **Statistics** (`v1/forms/stats.php`): besides total / per‑day / review‑status counts, a
  single in‑scope pass over the payloads computes per‑question distributions (`select_one`,
  labelled), per‑enumerator counts, fill‑in duration (mean/median + histogram), activity by
  hour/weekday, attachment and geo coverage, and freshness — reusing `Derived`, `FormSchema`
  and `RowScope`. It also returns a **cumulative** running total per period point and a
  **trend** object (last 7/30 days vs the previous equal period, with % change). The
  submission list can be **sorted by a calculated column** (duration, attachment count,
  has‑geo) expressed as SQL over the JSON, so the order is global rather than per‑page.
  The whole computation lives in `lib/Stats::compute($formId, $schema, $scope, $fieldScope,
  $locale, $includeReview)` — a single source of truth reused by the authenticated endpoint
  and by the public share endpoint (`public/share/{token}/stats`), which passes the link's
  scope/field rules and `$includeReview = false` so the **internal review status
  (`by_status`) is never exposed publicly**. The frontend render is the shared
  `StatsPanels.vue` component (authenticated `StatsView` + public `PublicShareView`),
  which simply omits the review cards/chart when `by_status` is absent.
- **Search** (`lib/SubmissionSearch.php`, M4a): submission‑table search no longer does a `LIKE`
  over the whole JSON. `textFor()` builds a plain‑text projection of the answer **values**
  (skipping `_*` metadata keys) into the indexed `submissions_cache.search_text` column,
  populated on every sync and on edit; `clause($alias, $term)` builds the WHERE fragment using a
  `FULLTEXT` `MATCH … AGAINST (… IN BOOLEAN MODE)` with per‑word prefix matching (falling back to
  `LIKE` for terms shorter than InnoDB's min token size). Reused by the list, the CSV export and
  the public share list. `textFor()` also appends the readable **option labels** (all of the
  form's translations, via `FormSchema::searchOptionLabels`) next to the raw codes, so a search
  for «Femenino» matches a row whose value is the code «2». Backfill / recompute:
  `cli/rebuild_search_text.php`.
- **Edit history** (`v1/submissions/history.php`): since each Kobo edit changes the `_uuid`,
  the per‑submission edit log is reconstructed by walking the `_uuid` chain backwards (audit
  `edit` rows where `detail.new_uid` = the current uid → its `submission_uid` is the
  predecessor). Returns each change as `field → before/after` with resolved labels; requires
  `can_edit`, honours row‑scoping and hidden fields.

### Settings & audit
- `lib/Settings.php`: global key/value settings (JSON) — sync statuses, default locale, label
  mode, field‑name truncation (`field_truncate_enabled`/`field_truncate_chars`, display‑only),
  password‑reset flag, self‑service audit flag (`audit_self_view_enabled`), viewer action
  flags, share password policy, share attachments policy (`share_attachments_policy`),
  public-surface toggles (`support_page_enabled` / `landing_cta_enabled`, served by the
  public `GET /config`), and `cron_runs`
  (last run per cron, written by `recordCronRun()` at the end of each cron job).
- `lib/Audit.php`: writes to `audit_log` (who did what) via `log()`, and reads it back via
  `query()` (pagination + filters by action/user/form/date/search, JOINs to users/forms).
  Two endpoints share `query()`: `GET /admin/audit` (admin; full log, optional user filter)
  and `GET /audit/me` (any signed‑in user, gated by `audit_self_view_enabled`; forces
  `user_id` = current user, omits the user filter/column, scopes the action list to the user).
- **Health/observability**: `GET /health` returns basic checks publicly; for an authenticated
  admin it also includes `cron` (last runs) and `sync` (form/submission aggregates) sections.

## Frontend

- **Routing**: flat routes in `src/router/index.js`. Public routes carry `meta.public`
  (landing, login, forgot/reset password, guide); the rest carry `meta.shell` and are wrapped
  by `AppLayout` (sidebar + content). A global `beforeEach` resolves the session once and
  enforces public/admin rules.
- **State**: Pinia `auth` store (`user`, `isAuthenticated`, `isAdmin`). `apiError(e, fallback)`
  turns an axios error into a localized message.
- **API**: single axios instance (`services/api.js`) with `withCredentials`; a 401 interceptor
  redirects to login (skippable for the anonymous `/auth/me` probe).
- **i18n**: `vue-i18n`, catalogs in `src/i18n/locales/{es,en}/*.json` — one file per area
  (`common`, `landing`, `support`, `guide`, `auth`, `account`, `submissions`, `stats`,
  `admin`, `sharing`), each holding whole top‑level namespaces (flat keys, no file prefix in
  `$t()`). `src/i18n/index.js` merges them via `import.meta.glob`, so adding a file needs no
  loader change. Every new key must exist in **both** locales (`npm run i18n:check`).
  Effective locale = user preference → system default → `es`.
- **Theming**: Tailwind v4 `@theme` in `src/style.css` defines semantic `primary`/`accent`
  color scales as CSS variables; components use `bg-primary-600`, `text-accent-700`, etc.
  Recoloring = editing those scales (or applying a `.theme-*` class on `<html>`). Success green
  stays on Tailwind's `green` on purpose. The mobile hamburger is themed via `.km-hamburger`
  + `--km-burger-*` tokens.
- **Dark mode**: the `.dark` class on `<html>` remaps only the **neutrals** (`white` + the
  `slate` scale) in `src/style.css` — brand/semantic tokens don't change, so dark mode is
  orthogonal to `.theme-*`. `composables/darkMode.js` manages the user preference
  (light/dark/auto or *none* = follow the site default) in `localStorage`; the admin sets a
  **default theme** and can **hide the selector** (settings `default_theme` +
  `show_theme_toggle`, served by the public `GET /config` and cached locally so the inline
  no-flash script in `index.html` works on repeat visits). The user's own choice always wins
  over the default. `ThemeToggle.vue` lives in the public header (and a selector in
  `/profile`); both hide when the admin disables the selector. Components that are dark
  **by design** in light mode (panel sidebar, public mobile drawer) pin the original
  neutrals with the `.km-pin-neutrals` class. The `dark:` variant is class-based
  (`@custom-variant dark`) for spot fixes: the accent table header and the muted dark
  variants of tinted surfaces (error/success/notice boxes, status chips, accent cards/pills).
  Chart text colors re-read the slate variables and re-render on toggle. The landing banner
  swaps to a night WebP variant.
- **Loading skeletons**: `Skeleton.vue` (variants `table`/`lines`/`cards`) replaces the
  "Loading…" text in the main list/detail/stats views (initial load only — a `loaded` flag
  per view; filter-driven refreshes keep the table dimmed instead of flashing).
- **Frozen table columns**: the global `table_freeze` setting (`first` default | `none`,
  admin Settings, served by public `GET /config`) pins the first column of every table on
  horizontal scroll. `composables/appConfig.js` fetches/caches it (localStorage) and
  exposes `useTableFreeze()`; each table applies a conditional sticky class to its first
  `th`/`td` (solid background + `group-hover`, capped at ~40% of the viewport width on
  small screens). In the submissions table the second pinned column ("Submitted") only
  freezes from 540 px up.
- **PWA / offline**: `vite-plugin-pwa` in `injectManifest` mode with a hand-written service
  worker (`src/sw.js`): app shell precached, SPA navigations fall back to `index.html`
  (denylisting `/api` so CSV/attachment downloads hit the network), API GETs cached
  network-first (4 s timeout; attachments in a separate bounded `CacheFirst` cache), and a
  custom plugin treats **5xx as network failure** so both *client offline* and *server down*
  fall back to the last seen data. Only 200s are cached. `composables/offline.js` exposes
  `isOnline` (banner in `App.vue`) and `clearDataCaches()`, called on logout so no sensitive
  data outlives the session on shared devices. The SW is build-only (disabled in dev).
- **Reusable UI**: `Modal.vue` + `ConfirmDialog.vue` (`composables/confirm.js`), with
  `composables/dialogA11y.js` providing Escape‑to‑close, focus trap and focus restore for
  modals and drawers.

## Database

MySQL 5.7+/MariaDB. **There are no incremental migrations.** The schema lives in two files
applied in order: `db/001_schema.sql` (all `CREATE TABLE`s, canonical) and
`db/002_defaults.sql` (idempotent seeds for `settings`). Only portable DDL — it must run on
both MySQL and MariaDB. New columns are added to the canonical `CREATE TABLE` (never
`ALTER`); to get a fresh database you drop and re‑apply both files. Runtime‑configurable
behavior lives in the `settings` table, not in schema changes.

Key tables: `kobo_accounts`, `users`, `user_sessions`, `forms`, `submissions_cache`,
`submission_reviews`, `user_form_permissions`, `notification_config`, `audit_log`,
`login_attempts`, `rate_hits`, `settings`, `password_resets`, `share_links`,
`contact_messages` (messages from the public contact form on the «Apoyar» page; admins read
and manage them from the `/admin/messages` inbox — statuses `new`/`read`/`archived`).

## Tests

PHPUnit (`api/tests/`), the only dev dependency. Two layers, both against a **separate**
database (`kobomanager_test`):

**Unit / DB tests** (extend `DbTestCase`): each runs in a transaction that is rolled back.
Coverage: auth/permissions + JWT session lifecycle, rate limiting, settings, token
encryption, geo parsing, derived metrics, attachment classification (`Attachments`), search
projection/clause (`SubmissionSearch`, incl. the visible‑fields clause), row scoping,
column‑level permissions (`FieldScope`: payload/attachment/geo stripping and schema
redaction) and share‑link resolution/tickets/attachment access.

**HTTP integration tests** (`api/tests/http/`, extend `HttpTestCase`): a base class boots the
real front controller in an ephemeral `php -S` server once per run (config isolated via the
`KM_CONFIG` env → `tests/config.http.php`; same constants the unit bootstrap uses) plus a tiny
**Kobo stub** (`tests/kobo_stub.php`) that the test account's `server_url` points at, so the
edit path can be exercised without real Kobo. Tests make real HTTP calls (cURL + cookie jar,
self‑Origin to pass CSRF). Because the server runs in another process, fixtures are committed:
each test truncates the working tables and seeds what it needs (`setUp`/`tearDown`). Coverage:
login/`/auth/me`/logout/login rate‑limit, CSRF enforcement, password reset (forgot → seeded
token → reset), single + batch review (incl. `can_validate` gating and RowScope 404), list/
detail/export with RowScope + FieldScope, and submission editing (uuid migration, review
migration, `KOBO_EDIT_FAILED` on a forced bulk failure). CI runs both layers (see below).

### CI
`.github/workflows/ci.yml` (GitHub Actions, **no Docker**) runs three jobs on push/PR:
`lint` (`php -l` sweep + `composer validate`), `frontend` (`npm ci` + `npm run build` + i18n
parity via `scripts/check-i18n-parity.mjs`), and `phpunit` (MariaDB provisioned on the runner
with `ankane/setup-mariadb`, `db/*.sql` applied to `kobomanager_test`, then the full suite with
`TEST_DB_*` env pointing at it).
