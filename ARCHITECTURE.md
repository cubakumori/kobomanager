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
    i18n/{es,en}.json        catalogs (every string lives in BOTH)
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
`Request::param()`. Each endpoint script checks the HTTP method itself.

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

### Public share links (`lib/ShareLink.php`)
Read‑only links let anyone browse a form's submissions **without a session** (M1). A
`share_links` row carries an unguessable URL `token`, what it exposes (`expose_list` /
`expose_detail` / `expose_map` / `expose_attachments`), an optional `row_filter` (reuses
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

### Internal review flow & customizable statuses
Review is an append‑only history (`submission_reviews`); a submission's **current** status
is its most recent row (`MAX(id)`), defaulting to `pending` when none exists. It is fully
**decoupled from Kobo** — these rows are never written back, and the native
`_validation_status` is surfaced read‑only via `lib/Derived`.

Statuses live in a **global catalog** (`review_statuses`, served by `lib/ReviewStatus`):
four seeded built‑ins (`pending` / `on_hold` / `approved` / `rejected`) plus admin‑defined
custom statuses. Each has a color (from a closed palette mirrored in
`composables/reviewColors.js`), an optional label override (built‑ins fall back to the
`review.<key>` i18n key), an `active` flag and an **`is_open`** flag — *open* statuses
(like `pending`/`on_hold`) count as unresolved in stats; the rest are final. `status` is a
`VARCHAR` referencing a catalog key (not an ENUM); `pending` cannot be deactivated.
Admin CRUD is in `v1/admin/review_statuses*` (built‑ins can be relabeled/recolored/reordered
but not deleted; a custom status in use can't be deleted, only deactivated). The catalog is
fetched once into a Pinia store (`stores/reviewStatuses`) that drives badges, review buttons
(single + batch), the list filter, CSV labels and stats — nothing hardcodes the status set.

**Automatic initial status**: when `SubmissionSync` caches a *new* submission, it inserts one
**system** review row (`user_id NULL`) with the form's effective initial status
(`ReviewStatus::initialFor`: per‑form `forms.initial_review_status` override → global
`settings.initial_review_status`; `null`/`pending` = disabled). Only genuinely new rows are
seeded (detected via `rowCount()===1` + a `WHERE NOT EXISTS` guard), so updates and existing
reviews are never overwritten. Disabled by default.

### Batch review & CSV export
`POST /forms/{id}/review` (`forms/review_batch.php`) applies one review status
(any active catalog key, e.g. `approved` / `on_hold` / `rejected` / `pending` or a custom one) to many
submissions in a single transaction; it requires `validate` once and **re‑checks**, per uid,
form membership and row scope server‑side (out‑of‑scope/foreign uids are silently skipped),
returning `{applied, skipped}`. `GET /forms/{id}/export` (`forms/export.php`) streams a
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
`KoboException` with standard codes. The token is decrypted on the fly with `TokenVault`
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
- **Statistics** (`v1/forms/stats.php`): besides total / per‑day / review‑status counts, a
  single in‑scope pass over the payloads computes per‑question distributions (`select_one`,
  labelled), per‑enumerator counts, fill‑in duration (mean/median + histogram), activity by
  hour/weekday, attachment and geo coverage, and freshness — reusing `Derived`, `FormSchema`
  and `RowScope`. (Week/month aggregation, cumulative and trend are deferred.)
- **Search** (`lib/SubmissionSearch.php`, M4a): submission‑table search no longer does a `LIKE`
  over the whole JSON. `textFor()` builds a plain‑text projection of the answer **values**
  (skipping `_*` metadata keys) into the indexed `submissions_cache.search_text` column,
  populated on every sync and on edit; `clause($alias, $term)` builds the WHERE fragment using a
  `FULLTEXT` `MATCH … AGAINST (… IN BOOLEAN MODE)` with per‑word prefix matching (falling back to
  `LIKE` for terms shorter than InnoDB's min token size). Reused by the list, the CSV export and
  the public share list. Backfill / recompute: `cli/rebuild_search_text.php`.

### Settings & audit
- `lib/Settings.php`: global key/value settings (JSON) — sync statuses, default locale, label
  mode, field‑name truncation (`field_truncate_enabled`/`field_truncate_chars`, display‑only),
  password‑reset flag, self‑service audit flag (`audit_self_view_enabled`), viewer action
  flags, share password policy, share attachments policy (`share_attachments_policy`), and
  `cron_runs`
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
- **i18n**: `vue-i18n`, catalogs in `src/i18n/{es,en}.json`. Every new string must be added to
  **both** files. Effective locale = user preference → system default → `es`.
- **Theming**: Tailwind v4 `@theme` in `src/style.css` defines semantic `primary`/`accent`
  color scales as CSS variables; components use `bg-primary-600`, `text-accent-700`, etc.
  Recoloring = editing those scales (or applying a `.theme-*` class on `<html>`). Success green
  stays on Tailwind's `green` on purpose. The mobile hamburger is themed via `.km-hamburger`
  + `--km-burger-*` tokens.
- **Reusable UI**: `Modal.vue` + `ConfirmDialog.vue` (`composables/confirm.js`), with
  `composables/dialogA11y.js` providing Escape‑to‑close, focus trap and focus restore for
  modals and drawers.

## Database

MySQL/MariaDB. **Pre‑public policy: there are no incremental migrations.** The schema is the
set of `db/*.sql` files applied **in order** — together they are the full schema. New tables
are added as new `db/NNN_*.sql` files using `CREATE TABLE IF NOT EXISTS` (not `ALTER`); to get
a fresh database you drop and re‑apply all files. Runtime‑configurable behavior lives in the
`settings` table, not in schema changes.

Key tables: `kobo_accounts`, `users`, `user_sessions`, `forms`, `submissions_cache`,
`submission_reviews`, `user_form_permissions`, `notification_config`, `audit_log`,
`login_attempts`, `rate_hits`, `settings`, `password_resets`, `share_links`.

## Tests

PHPUnit (`api/tests/`), the only dev dependency. They run against a **separate** database
(`kobomanager_test`); each test runs in a transaction that is rolled back. Coverage today:
auth/permissions + JWT session lifecycle, rate limiting, settings, token encryption, geo
parsing, derived metrics, attachment classification (`Attachments`), search projection/clause
(`SubmissionSearch`, incl. the visible‑fields clause), row scoping, column‑level permissions
(`FieldScope`: payload/attachment/geo stripping and schema redaction) and share‑link
resolution/tickets/attachment access.
Endpoint‑level (HTTP) integration
tests are a known gap (e.g. batch review, CSV export, the audit viewer) — see
[`ROADMAP.md`](./ROADMAP.md).
