# Architecture — KoboManager

A technical overview for contributors. For setup and commands see [`README.md`](./README.md);
for conventions see [`CONTRIBUTING.md`](./CONTRIBUTING.md).

## What it is

KoboManager is a thin management layer **between KoboToolbox accounts and a small team**.
An administrator connects Kobo accounts (API tokens stored encrypted); other users get
per‑form permissions and review submissions **without a Kobo account and without ever seeing
the token**. Submissions are mirrored into a local cache for fast browsing and an internal
review (approve / on-hold / reject) flow that is **synced with Kobo's native validation
status** in both directions (see below).

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
  cron/                      CLI jobs (daily_summary, sync_submissions, demo_reset)
  cli/                       create_user.php, install.php, migrate.php, doctor.php, seed_demo.php, …
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
  `requireForm(...)` where `cap ∈ {view,edit,validate,settings,sample}` (admins bypass). `settings`
  (`user_form_permissions.can_settings`, the *Settings* checkbox in the permissions UI) lets a
  non‑admin edit that form's per‑form settings (team breakdown + quality‑control thresholds):
  `admin/forms/{id}` `GET`/`PATCH` accept it, `DELETE` stays admin‑only. `sample`
  (`can_sample`, the *Sample* checkbox) gates the **sample‑plan editor** and is **hierarchical**:
  it implies `can_settings` (whoever plans the sample also configures the team field the plan
  depends on) — normalized server‑side on every permissions save, and mirrored in the UI (checking
  *Sample* checks and disables *Settings*). Any extra capability implies `can_view` (enforced on
  save). The forms list and the quality endpoint expose the flags so the UI can offer the form
  card's *Settings* shortcut and the threshold link.

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
`expose_detail` / `expose_map` / `expose_stats` / `expose_attachments` /
`expose_review_summary`), an optional
`row_filter` (reuses
`RowScope`), an optional `field_filter` (reuses `FieldScope` — hide columns in the public
view), an optional `password_hash`, and optional `expires_at` / `revoked_at`. `resolve()`
returns the row only while active (not revoked, not expired, form active). The public endpoints
live under `v1/public/` and **skip `Auth`** (like `v1/config.php`); `ShareLink::requireAccess()`
is their guard — it resolves the token, checks the requested capability, and for
password‑protected links requires a short‑lived **HMAC ticket** (issued by the rate‑limited
`unlock` endpoint, sent back via the `X-Share-Ticket` header **or** the `?k=` query param so
plain `<img>`/`<audio>` requests can carry it). Out‑of‑scope or other‑form submissions return
404; the internal review status is never exposed by default. The one **opt‑in** exception
(1.27.0) is `expose_review_summary`: `GET /public/share/{token}/review-summary`
(`share_review_summary.php`) returns the **aggregate review‑status counts** per
team/enumerator — no submission data — reusing `Quality::compute` with the link's row
scope (`row_filter` AND `team_filter`, the latter via the `$extraScope` param) and
returning only `review_summary`. It requires the form to have a team or enumerator field
(enforced both at creation in `parseSettings()` and live in the endpoint) and deliberately
ignores the link's `stats_status` scope — showing review progress is the whole point of
the flag, which stays `DEFAULT 0` so existing links never start exposing it. Admin CRUD is
in `v1/admin/shares*`; the password policy is the `share_password_policy` setting.

**Bulk creation.** `POST /admin/shares/bulk` (`shares_bulk.php`) creates one link per chosen
value of a **`select_one`** field: each link is pinned to `field = value` via a `row_filter`
condition **AND‑combined** with the (optional) base filter — `ShareLink::withScopeValue()`
appends the distinctive group, and `RowFilterEditor.vue` excludes the distinctive field and
forces the root connector to AND so the result stays expressible in RowScope's two levels. Both
the simple and bulk endpoints share `ShareLink::parseSettings()` for the common fields (what it
exposes, columns, teams, status, password, expiry). Per‑value submission counts come from
`GET /admin/forms/{id}/scope-fields?counts=…`. Capped at 50 links, all inserted in one transaction.

**Fixed scope (status + teams).** Beyond `row_filter`/`field_filter`, a link can be frozen at
creation to a subset that applies to **every** view it exposes (list, map, detail, attachments,
stats): `stats_status` (`all` | `approved`) restricts by latest review status, and `team_filter`
(a list of `forms.stats_team_field` values; `__none__` = no team) restricts by team. The team
filter is expressed as a `RowScope` rule (`RowScope::teamRule`) **combined in AND** with
`row_filter` — `ShareLink::rule()` / `rowSql()` / `matchesScope()` are the single sources the
endpoints consume, so teams ride the existing row‑scope path for free. The status filter is a
separate SQL fragment over the denormalised `submissions_cache.review_status` column
(`ValidationStatus::statusFilterSql`, shared with `lib/Stats`); it is **decoupled from review exposure** — an `approved`‑only link narrows the set
without ever revealing the `by_status` breakdown. The public meta announces the scope
(`status_scope` in `GET /public/share/{token}`, rendered as *"approved submissions only"*
plus an "N approved submission(s)" list count), and the public stats override the `total`
card with the status‑scoped count — `Stats::compute`'s total is deliberately unfiltered
because the internal header cards act as the status selector, but the public view has no
selector and an unscoped total would both confuse and reveal how many unapproved rows exist.

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
this is the server‑side backstop). Editing is unaffected (still gated by `can_edit`). `GET /forms/{id}/export` (`forms/export.php`) streams the submissions in the format
chosen by `?format=` — **`xlsx`** (native spreadsheet, default in the UI) or **`csv`**
(UTF‑8 with BOM, default at the endpoint) — requiring `view` and honoring row scope and
the list filters (`?review` accepts all four statuses); it bypasses the JSON envelope and
resolves question/option labels per the global label mode. Column discovery and cell
rendering are shared; only the emitter branches. The `.xlsx` path uses
**`lib/XlsxWriter`** — a minimal, dependency‑free writer (an `.xlsx` is a ZIP of XML
parts via `ZipArchive`) that streams the sheet row‑by‑row to a temp file (O(1‑row memory,
like the CSV) and packs the ZIP on close; text cells are inline strings — so a leading
`=` is not a formula, no CSV‑injection surface — and duration is a real numeric cell. It
solves the recurring «CSV doesn't split into columns»: real columns, no delimiter
ambiguity (standard comma CSV can fail to split in European‑locale Excel, which expects
`;`). CSV note: PHP 8.4+ requires the `fputcsv` `$escape` argument explicitly (passed as
`''` → standard quoting). The frontend triggers export from a modal (scope: all /
approved‑only; format: Excel/CSV). The list endpoint also returns **`scope_total`** (the
in‑row‑scope total, ignoring search/filter/status) next to the filtered `total`, so the
UI can show an «N / total» count.

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

### Demo mode (`lib/Demo.php`, `lib/DemoSeed.php`)
Optional `DEMO_MODE` / `DEMO_RESET_MINUTES` / `DEMO_LOGIN_ADMIN`+`DEMO_LOGIN_VIEWER` /
`DEMO_SEED_PATH` constants (guarded with
`defined()`, so existing configs behave as demo off) turn the instance into a public
sandbox: `GET /config` exposes the demo values, the frontend shows a welcome dialog on
the homepage plus a clickable **DEMO** badge next to the brand, and blocked buttons are
disabled with a tooltip. Enforcement is central: a route-pattern + method denylist in the
front controller returns 403 `DEMO_LOCKED` for anything that would break the demo or leak
secrets (Kobo account CRUD, user/password/session management, global settings, submission
editing, manual sync). Everything local that the periodic reset restores stays enabled.
Seed and reset are managed by the app (`lib/DemoSeed`): `POST /admin/demo/seed` (blocked
in demo; part of the flag-off maintenance loop) exports a data-only SQL snapshot to
`DEMO_SEED_PATH` — private tables (sessions, IPs, audit, contact messages) are never
included — and `cron/demo_reset.php` (crontab every minute, self-paced by
`DEMO_RESET_MINUTES`) restores it in one transaction, preserving live sessions and
contact messages; it refuses to run with the flag off. The SQL split helper is shared
with the installer (`lib/SqlScript`).

### Database backup & restore (`lib/DbSnapshot.php`, `lib/DbBackup.php`)
`lib/DbSnapshot` is the shared data-only snapshot engine (consistent-read multi-row
INSERT dump streamed by chunks; validated single-transaction restore with rollback)
under both the demo seed and the **backup** feature: `GET /admin/db/export?scope=full|settings`
streams a download (full = the seed tables + audit trail + contact messages; sessions,
login attempts and password-reset tokens never travel; `settings` = portable
configuration only) and `POST /admin/db/import` (multipart upload) restores it — scope
is read from the file header, content is validated against that scope's table
whitelist before touching the DB, and a full restore purges sessions of users absent
from the backup. Each consumer has its own header line, so a demo seed is not accepted
as a backup nor vice versa. Both endpoints are admin-only, audited and in the demo
denylist (the export would hand any demo visitor the password hashes and the encrypted
Kobo token).
Operational guide (synthetic seeding via `api/cli/seed_demo.php`, seed generation, reset cron, hardening): [`DEMO.md`](DEMO.md).

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
  **Anti‑wipe guard** (both sweep modes): if Kobo returns **zero** live submissions while the
  cache has rows, nothing is deleted of its own accord — an empty live list is more likely an
  upstream failure than a real 100 % deletion. The sync result carries `wipe_available` +
  `cached`, the **manual** sync endpoints surface it, and the UI offers a confirmation modal
  ("empty and sync"); confirming repeats the sync with `confirm_wipe: true`, which re‑checks
  against Kobo in that same pass before emptying (audited as `cache_wipe`). The cron never
  confirms. Review comments/history are kept, as with partial deletions.
- **Sync on login** (`v1/forms/sync_stale.php`, global `sync_on_login` setting, OFF by
  default): after a successful login the SPA fires a background `POST /forms/sync-stale`
  that syncs the user's visible forms whose submissions are >10 min old — a staleness safety
  net for installs **without** a cron. No‑op when the setting is off; per‑form errors don't
  abort the pass; the per‑form lock absorbs overlaps with the cron or concurrent logins;
  `ignore_user_abort` + no time limit so the fire‑and‑forget pass (and its `sync_stale` audit
  entry) survives the browser navigating away. Blocked in demo mode like all manual sync.
- **Validation status sync** (`lib/ValidationStatus.php` + `SubmissionSync::reconcileValidation`):
  the internal review status is kept in sync with Kobo's native `_validation_status` in **both
  directions**.
  - *Push* (blocking, like edits): `v1/submissions/review.php` and `v1/forms/review_batch.php`
    `PATCH …/data/validation_statuses/` (or `DELETE …/{id}/validation_status/` to clear). If Kobo
    rejects, **no local review is written** — both sides stay identical. Skipped under `DEMO_MODE`
    (review stays local‑only, the real account is never touched). The push needs the account's
    token to hold the *Validate Submissions* permission on the form (automatic for owned forms;
    must be granted for **shared** ones); a `403` is surfaced as `KOBO_VALIDATE_FORBIDDEN`
    (distinct from `KOBO_UNAUTHORIZED`, thanks to `KoboException::$httpStatus`).
  - *Pull* (every sync, both modes): a cheap `fields=["_uuid","_id","_validation_status"]` sweep
    feeds a **3‑way merge** per submission — `koboNow` vs the baseline `submissions_cache.kobo_validation_seen`
    vs the latest local review. If Kobo changed externally, the baseline is updated and (when it
    also differs from local) a synthetic review row is inserted with `source='kobo'`, `user_id=NULL`,
    which becomes the latest by `MAX(id)` ⇒ **Kobo wins** on conflict. The incremental cursor
    doesn't re‑fetch old submissions, so this sweep is what makes external validation changes land.
  - The **current** status is denormalised into `submissions_cache.review_status` (indexed by
    form, `'pending'` by default). `ValidationStatus::recordReview` is the **single write path**
    (review endpoints, the pull sweep, demo seed, tests): it inserts the log row and updates the
    column atomically enough that filtered reads (list, export, stats, quality, public links)
    never have to materialise the whole review log again.
  - `submission_reviews.source` (`'app'`/`'kobo'`) records the origin; `user_id` is `NULL` for
    Kobo‑sourced rows (shown as “Kobo” in the history).
- **Readable labels** (`lib/FormSchema.php`): caches a normalized XLSForm schema per form
  (`forms.schema_json`) so the UI shows question/option labels instead of raw codes.
  **Score ("Rating") and rank questions** (`begin_score`/`begin_rank`) are *groups* whose rows
  (`score__row`/`rank__level`) share one choice list carried on the `begin_*` row itself
  (`kobo--score-choices` / `kobo--rank-items`): `normalize()` treats them as groups (path stack,
  the group itself is not a data field) and registers each row at its **full payload path**
  (`Q6/carrt`) as a plain `select_one` with the shared list and a composed
  *"{question} · {row}"* label. Registering rows under their real payload key is
  load‑bearing for column‑level permissions: `FieldScope` matches keys exactly, so a
  wrong path in the schema (what the hide‑columns editor offers) would silently fail to
  hide the value (fixed in 1.27.1; `api/cli/fix_field_filters.php` renames stale keys in
  stored filters after schemas re‑normalize on the next sync).
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
- **Materialised cache columns** (`Derived::cacheColumns`, written by every sync/edit/seed):
  `submissions_cache` carries `kobo_id`, `duration_s`, `att_count` and `has_geo`, so sorting by
  calculated columns, the "has map?" check and the deletion sweep never parse the whole form's
  JSON. They are **global** (FieldScope‑independent) — views that must respect per‑user hidden
  fields still compute over the trimmed payload. Backfill on upgrade: `migrate.php`.
- **Streaming reads** (`DB::stream`): stats, quality, both maps and the CSV export iterate an
  unbuffered cursor (one row in memory at a time) instead of `fetchAll`, so per‑request memory
  no longer grows with form size. The consuming loop must be pure PHP (no nested queries) —
  documented on the helper.
- **Statistics** (`v1/forms/stats.php`): besides total / per‑day / review‑status counts, a
  single in‑scope pass over the payloads computes per‑question distributions (`select_one`,
  labelled), **per‑question numeric summaries** (`integer`/`decimal`/`range`: min/max/mean/
  median + an 8‑bucket histogram, in `by_numeric`), a **non‑response ranking** (`no_response`:
  the 10 most‑skipped questions with their % over the filtered base), per‑enumerator counts,
  fill‑in duration (mean/median + histogram), activity by hour/weekday, attachment and geo
  coverage, and freshness — reusing `Derived`, `FormSchema` and `RowScope`. It also returns a
  **cumulative** running total per period point and a **trend** object (last 7/30 days vs the
  previous equal period, with % change). The submission list can be **sorted by a calculated
  column** (duration, attachment count, has‑geo — over the materialised columns) or by
  **review status** (`sort=review_asc`/`review_desc`, ordered along the flow
  pending→on_hold→approved→rejected via `FIELD()` on the denormalised `review_status`), so the
  order is global rather than per‑page. The whole computation lives in
  `lib/Stats::compute($formId, $schema, $scope, $fieldScope, $locale, $includeReview, $teamField,
  $enumField, $filterStatus, $teamSel, $extraScope, $dateFrom, $dateTo)` — a
  single source of truth reused by the authenticated endpoint and by the public share endpoint
  (`public/share/{token}/stats`), which passes the link's scope/field rules and
  `$includeReview = false` so the **internal review status (`by_status`) is never exposed
  publicly** (the opt‑in `expose_review_summary` endpoint is the one deliberate exception —
  see *Public share links*). The frontend render is the shared `StatsPanels.vue` component (authenticated
  `StatsView` + public `PublicShareView`), which omits the review cards/chart when `by_status`
  is absent and hides the attachments/geo cards when the shown subset has none.
- **Filtering the stats** (authenticated view): every metric is computed over a subset, with the
  full header counts (`total` + `by_status`) always returned so the user can switch. Three
  independent, composable filters:
  - **By date range** — `?from` / `?to` (`YYYY-MM-DD`, inclusive, UTC calendar days, like the
    per‑day chart) narrow every metric **except** the header counts and the 7/30‑day trend
    (which keeps its own window relative to today). Malformed dates are ignored (fail‑open); the
    applied range is echoed back in `date_from`/`date_to`. The UI offers presets
    (All / 7 / 30 / 90 days / custom with two date inputs) and a chip to clear it.
  - **By review status** — the five header cards (Total / Pending / Approved / On hold /
    Rejected) act as a single‑choice toggle; the active one re‑scopes all charts. The filter
    is a SQL fragment over the denormalised `submissions_cache.review_status` column
    (`ValidationStatus::statusFilterSql`); `$filterStatus` is
    **independent of `$includeReview`** (so it works on public links too). The default scope on
    open is the global `stats_default_scope` setting (`all` | `approved`, default `approved`),
    overridable per request via `?status=`. `base` is the filtered denominator the charts use.
  - **By team** — each bar of the team breakdown has a toggle; unchecking subtracts that team
    from all aggregates (`$teamSel`, applied to the series/trend in SQL and the in‑PHP pass via
    `RowScope::teamRule` + `RowScope::matches`) **while the bars stay complete** (their shares
    use `teamBase`, the status‑filtered all‑teams count). For a **share link** the team scope is
    instead frozen and uniform — passed as `$extraScope` (a `RowScope` rule AND‑ed into the
    scope SQL), so it restricts the breakdown too (the viewer never sees excluded teams).
- **Team → enumerator breakdown** (optional, per form): when `forms.stats_team_field` is set,
  the same in‑scope pass also groups submissions by a **team** field and, within each team, by
  an **enumerator** (`forms.stats_enumerator_field`, or `_submitted_by` by default), yielding
  `by_team` — for every team and enumerator: volume (count + %), median duration, mean
  completeness, last activity and the review‑status mix. Because it rides the existing
  `RowScope`/`FieldScope` pass, a team lead with a row filter only sees their own team, and a
  team field hidden by column permissions disables the breakdown for that user. The review mix
  is gated by `$includeReview` (so public links get the volume/quality but not the review mix,
  mirroring `by_status`). The two fields are configured from a per‑form **settings screen**
  (`admin/forms/{id}` `GET`/`PATCH`, view `FormSettingsView.vue`, route `admin-form-settings`).
- **Quality control** (`lib/Quality.php`, `v1/forms/quality.php`, view `QualityView.vue` at
  `forms/{id}/quality`): flags submissions outside the form's admissible thresholds — four
  per‑form columns edited from the same settings screen (`forms.qc_min_duration` /
  `qc_max_duration` / `qc_min_gap`, minutes, `NULL` = check off; and `qc_dup_min_answers`, a
  count, `NULL` = duplicate signal off) — grouped by the same team/enumerator pair as the stats
  breakdown, with a drill‑down to each offending submission. Non‑admissible counts are always
  shown over a **single denominator** — the total *received* (all submissions in the user's row
  scope, unfiltered by review status), per form, team and enumerator — so the `n / total`
  fraction and its % always agree at a glance.
  Six flags (`Quality::FLAGS`): **short**/**long** (duration from the schema's `start`/`end`
  meta keys, same as the duration sort), **short gap**/**overlap** per enumerator
  *consecutiveness* (gap between a survey's start and the max `end` seen so far in that
  enumerator's start‑ordered chain; a negative gap — overlapping surveys, a fabrication signal —
  is always flagged, regardless of the threshold), **duplicate** (another submission of the form,
  from any enumerator, with identical content — team/enumerator fields excluded; a submission
  participates only with at least `qc_dup_min_answers` non‑empty content answers, default 2, so
  the sensitivity is tunable per form and `NULL`/0 turns the signal off) and **gps** ("GPS
  clavado": the exact same point repeated in ≥`GPS_MIN_REPEATS`=3 submissions of the same
  enumerator; only submissions **with** coordinates take part, so the signal is inactive —
  `gps_enabled=false` — on forms without geo). Duration/consecutiveness flags need timestamps;
  a duplicate/GPS flag can apply to a submission with no valid `start`/`end` (its time columns
  render as "—"). Submissions without valid timestamps are counted apart (`untimed`). The
  response also carries a **weekly trend** (`by_week`: % of received submissions with any flag
  per ISO week, over *all* received — the flag physics don't depend on the review scope, so
  approving a submission doesn't erase it from the history) and the current thresholds
  (including `dup_min_answers`). A companion endpoint **`GET /forms/{id}/quality/suggest`**
  (`quality_suggest.php`, admin or `can_settings`) proposes admissible min/max duration in
  minutes from the p5/p95 percentiles of the form's real `duration_s` (respecting row scope; no
  suggestion under 10 timed submissions) — it only pre‑fills the settings form, never saves. It
  also returns `enumerator_median` / `enumerators` (median submissions per enumerator), which the
  settings page surfaces as an informative hint for the risk index's minimum-N (never auto‑filled).
  Start/end render in `APP_TIMEZONE` via `Derived::formatLocal`. A global **scope**
  setting (`qc_scope`: `pending_hold` default | `all`, Settings → Panels tab) decides which
  submissions get *reported*: by default only pending/on-hold ones (approved/rejected already
  passed human review, so QC counts never contradict the stats review cards). Since 1.27.0
  the page carries a **transient toggle**: `?scope=all|pending_hold` overrides the global
  setting **for that request only** (anyone with `can_view`; unrecognised values fall back
  to the global; nothing is persisted — the response's `scope` is the effective one). Consecutiveness
  chains are always built over **all** of an enumerator's surveys regardless of scope — a
  pending survey overlapping an approved one is still flagged (against its real end), the
  approved one just isn't listed. The analysis is
  **read‑only**: the *"put the N non‑admissible on hold"* button rides the existing batch review
  endpoint (`forms/{id}/review`, attribution = the admin who clicks, normal Kobo push, chunks of
  1000, already‑on‑hold ones excluded), so `submission_reviews` needs no schema change. Respects
  `RowScope`/`FieldScope` like `lib/Stats` (a hidden team field drops the team level); the page
  requires `can_view`, the batch button `can_validate` on a non‑archived form.
- **Approve the admissible in bulk** (symmetric to "put on hold", but with more care because
  approving is *terminal and asserts quality* whereas passing the thresholds is necessary but not
  sufficient — passing QC ≠ a verified survey). `lib/Quality::compute` also emits
  **`admissible_pending`**: the pending submissions *in scope with no flag at all* (carrying the
  resolved team/enumerator names). `Quality::admissiblePendingUids()` is a **pure** helper that
  takes that list plus (optionally) a `Risk::compute` result and returns the approvable uids,
  **excluding high‑risk enumerators** (composite index ≥ `Risk::SUSPICION_Z`, matched by the
  resolved (team, enumerator) name pair that Quality and Risk derive identically). It is the
  single source of truth shared by two consumers: the submissions table's **"admissible only"**
  filter (`?admissible=1`, applied as an `IN (…)` over those uids — flags are *derived, never
  stored*, so there is no SQL column to index; see note below) and the QC page's **"approve the N
  admissible"** button (both ride `forms/{id}/review` with `approved`, `can_validate`, chunks of
  1000, the confirmation spelling out that they only passed the automatic thresholds). A global
  setting **`qc_admit_batch`** (`table` default | `qc` | `both` | `off`, Settings → Panels tab)
  governs *only this admissible shortcut* — the table's generic bulk review is untouched. **Why
  flags stay derived, not persisted:** the thresholds are per‑form and user‑editable, and some
  signals (exact‑answer duplicates, "stuck GPS") are cohort‑relative — a new sync can flip another
  submission's flag — so a stored flag would be a materialized view needing recompute on every
  sync and every threshold edit, plus a schema change, to solve a problem that today is just a
  bounded on‑demand uid list. Persisting would only earn its keep for the deferred *auto‑on‑hold
  on sync* milestone (which is inherently historical, `source='auto'`). Entry points: a
  button next to the stats team breakdown (internal view only — share links never expose it) and
  the submission table's actions. `lib/Quality` also returns a **`review_summary`**: submission
  counts per review status (pending/on-hold/approved/rejected) by team → enumerator over **all**
  received submissions (scope‑independent), so an already‑reviewed enumerator still shows up
  (`QualityView` renders it with counts and %). The drill‑down is downloadable via
  **`GET /forms/{id}/quality/export`** (`quality_export.php`, `can_view`): one row per flagged
  submission (team, enumerator, uid, submitted, start, end, duration s, gap s, flags, review
  status), in CSV or native `.xlsx` (`?format=csv|xlsx`, same writer as the submissions export —
  BOM + formula‑injection neutralization for CSV, numeric duration/gap cells for xlsx). It reuses
  `lib/Quality` verbatim, so it honors the same row/field scope, `qc_scope` and team/enumerator
  gating as the page — including the transient `?scope=` override, which the view propagates in
  the download URL so the file always matches what's on screen; headers and flag/status values
  follow the user's locale.
- **Review comments panel** (`lib/Comments.php`, `v1/forms/comments.php`, view
  `CommentsView.vue` at `forms/{id}/comments`, `can_view`): gathers the comments that
  already live in `submission_reviews` (`source='app'` with an author, or `source='kobo'`
  imported by the sync, author `null`) — only reviews with a non-empty `comment` — and groups
  them by team → enumerator (same `stats_*_field` pair and FieldScope team-gating as Quality),
  so you can read what's been commented without opening submissions one by one. One row per
  comment (a submission with several commented reviews shows several), each with date, review
  status, author, source and text, plus a deep-link to the submission. Optional `?status=` and
  `?search=` (comment substring) filters. Joins `submission_reviews` ↔ `submissions_cache` on
  `submission_uid` + `form_id` with RowScope in the join, so only comments of the user's visible
  submissions of this form appear (orphans of deleted submissions drop out); the comment text
  isn't a form field, so FieldScope doesn't censor it. Read-only, internal (share links never
  expose it), no schema change. Entry point: a link in the Quality-control header.
- **Risk index** (`lib/Risk.php`, `v1/forms/risk.php`, view `RiskView.vue` at `forms/{id}/risk`,
  `can_view`): heuristic fabrication ("curbstoning") detection that aggregates **peer‑relative**
  signals into an index prioritising who to back‑check. **Opt‑in** per form via `forms.risk_min_n`
  (the minimum submissions per enumerator/team to be scored; `NULL` = index off — the endpoint
  returns `enabled: false` and the view shows an empty state inviting configuration). One
  streaming pass over `submissions_cache` (`FieldScope::apply` per row like `lib/Stats`), grouped
  by team → enumerator via `stats_team_field`/`stats_enumerator_field`; the review‑status scope
  reuses `qc_scope`. Signals: **percentmatch** (per‑enumerator answer similarity — mean of each
  submission's best pairwise match; O(n²) bounded by a `PM_SAMPLE=200` sample, reported), skips /
  "don't know" rate, straight‑lining, answer‑distribution TVD vs. peers and (team level) vs. the
  pool of teams, Benford first‑digit TVD, productivity (interviews/day) and GPS clustering. Each
  metric is z‑scored **robustly** (median/MAD, so the cheater can't inflate their own baseline)
  against the enumerator's **team peers**; only positive z contributes to the weighted index
  (percentmatch dominant). Team index is not the members' mean but "how many are over the suspicion
  threshold + the worst" plus the team's distributional outlier. Never opaque: the response carries,
  per component, the value, the peer median and the z/level for a plain‑language UI, plus a
  methodological warning (signal to prioritise, not proof; needs volume). Metrics self‑gate on data
  availability (Benford needs numeric volume, GPS needs geo, percentmatch ≥2 content submissions).
  Read‑only; respects `RowScope`/`FieldScope`. **Phase 2** (Kobo `audit.csv` per‑submission timing)
  and a persisted weekly history are deferred (see ROADMAP).
- **Sample by team** (`lib/Sample.php`, `v1/forms/sample.php`, view `SampleView.vue` at
  `forms/{id}/sample`, `can_view`): planned‑sample compliance — for each **team × value** cell of
  a sampling `select_one`, counts what is **done** vs a **target** and projects a completion date at
  the current pace. Twin of `lib/Stats`: one streaming pass over `submissions_cache` with the same
  `RowScope`/`FieldScope` (a team lead sees only their team). The team axis reuses
  `forms.stats_team_field`; the sampling field is `forms.sample_field` (+ optional
  `sample_field2/3`, **observed distribution only** in stage 1), all **required to be `select_one`**
  (closed value set; enforced in `admin/sample_plan.php` and in the editor). "Done" is scoped by
  `forms.sample_denominator` (`approved` default | `approved_pending`) over the denormalised
  `review_status`. Cells with data but **no target** are flagged *out of plan* (kept, not dropped);
  the empty team/value bucket is `Sample::NONE` (`__none__`). The **current plan** lives in
  `sample_targets` (one target row per `(form_id, team_value, sample_value)`, target > 0 only); each
  save **archives** a full snapshot in `sample_target_history` (the plan changes mid‑campaign, so
  history is never overwritten). The plan is edited on its **own page**
  (`/admin/forms/{id}/sample-plan` → `SamplePlanView.vue` wrapping `SamplePlanEditor.vue`;
  `GET`/`PUT /admin/forms/{id}/sample-plan`, admin or the hierarchical `can_sample` permission —
  plain `can_settings` gets 403 here): a team × value matrix with per‑team quick‑fill +
  even/proportional distribution, mutually‑exclusive field selectors, a live coverage line, a
  clear‑targets button and an empty‑plan confirmation; the settings screen links to it and warns
  when changing the team field would strand an existing plan's targets. The read‑only panel has a
  **view‑type selector** (linear/table/heatmap/grouped bars/traffic light/summary doughnut,
  per‑device preference in `localStorage`). A public read‑only link is deferred (see ROADMAP).
  Not cached (per‑user scope + review‑status‑dependent denominator, like `forms/stats.php`).
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
  password‑reset flag, self‑service audit flag (`audit_self_view_enabled`), audit‑log
  retention (`audit_retention_days`, 0 = keep forever; drives opportunistic pruning in
  `Audit::log`), viewer action
  flags, share password policy, share attachments policy (`share_attachments_policy`),
  the default notification frequency (`notifications_default_frequency`) and the optional
  global quiet‑hours window (`notifications_quiet_start`/`notifications_quiet_end`), the login‑triggered
  background sync (`sync_on_login`, served by the public `GET /config` — see *Sync on login*),
  table display
  (`table_freeze`, `table_header_lines`), stats/QC display (`stats_default_scope` —
  default review‑status scope on opening stats; `qc_scope` — which submissions quality
  control reports on; `stats_team_cap` — team‑breakdown cap, `20`|`50`|`all`; `pct_format`
  — app‑wide percent rendering, integer or two decimals, served by the public `GET /config`
  so public share views honor it too), the `show_view_submissions_link` toggle (also on
  `GET /config`), the **Samples** block (`sample_show_quick_fill` — show/hide the plan
  editor's quick‑fill column; `sample_palette` — instance‑wide compliance palette of the
  sample panel, `classic`|`soft`|`accessible`|`mono`, with `sample_mono_color` for the
  mono preset, `''` = theme primary; all three on `GET /config`, consumed by
  `composables/samplePalette.js`), public-surface toggles (`support_page_enabled` /
  `landing_cta_enabled`, served by the public `GET /config`), and `cron_runs`
  (last run per cron, written by `recordCronRun()` at the end of each cron job).
- **Email notifications**: a per‑user × form **frequency** (`notification_config.frequency`:
  `off` | `daily` | `hourly` | `every_sync`; `NULL` = no explicit preference → the global
  `notifications_default_frequency` applies, `COALESCE(nc.frequency, default)`), scoped to
  **active** forms the user can view (admins: all active; viewers: `can_view`, honoring
  their row scope). All email goes through `lib/Mailer` (Resend REST, no SDK; a no‑op
  returning `false` when `RESEND_API_KEY` is empty), localized per `users.locale`, and is
  always **count + link** — never submission content (nothing the row/column scoping
  protects leaves by email). Two senders share the model:
  - `cron/daily_summary.php` (e.g. `0 7 * * *`) serves `daily`: one digest of the previous
    day's new submissions per form, over `submissions_cache` (not Kobo).
  - `lib/Notifier` serves `hourly`/`every_sync` ("near‑immediate"): invoked at the end of
    each `cron/sync_submissions.php` pass (manual/login syncs never send — only the cron
    sets the cadence). Per (user, form) it keeps a UTC watermark
    (`notification_config.last_notified_at`): it counts in‑scope rows with `submitted_at`
    after the watermark, sends **one grouped email per user**, and advances the watermark
    only for the forms actually notified and only if the send succeeded (a failed send
    regroups next pass). A `NULL` watermark is baselined to "now" without sending (no
    history flood on opt‑in; for default‑subscribed users without a row it lazily inserts
    one with `frequency NULL`, still inheriting the default). Guardrails: `hourly` users get
    at most one email per hour (their most recent hourly watermark is the clock); the
    optional global **quiet hours** window (`notifications_quiet_start/end`, `HH:MM` in
    `APP_TIMEZONE`, may cross midnight) skips the run entirely, so everything accumulates
    and goes out grouped once it ends.
  `GET/PUT /notifications` reads/writes the preferences: PUT stores an explicit frequency
  for every currently‑visible form (an opt‑out persists even when the default is on, a
  newly‑added form inherits the default until the user next saves) and preserves the
  watermark — except when a form *enters* a live frequency, where it re‑anchors to "now".
- **Web Push** (near‑immediate alerts as system notifications; requires VAPID keys in
  config — `php api/cli/vapid_keys.php` — and HTTPS): `lib/WebPush` is a **dependency‑free**
  implementation of the Web Push protocol — RFC 8291 payload encryption (ECDH P‑256 +
  HKDF‑SHA256 + AES‑128‑GCM, `aes128gcm`, pinned to the RFC's official test vector in
  `WebPushTest`) and RFC 8292 VAPID (ES256 JWT via OpenSSL) — keeping the runtime
  composer‑free. Opt‑in is **per device** from the profile (`GET/POST/DELETE
  /push/subscriptions`, table `push_subscriptions`, unique by `sha256(endpoint)`); the
  browser's `applicationServerKey` (public VAPID key) travels on the public `GET /config`.
  The same `Notifier` pass fans out to the user's devices with the same items, frequency
  and guardrails as the email; the watermark advances when **any** channel delivered. Dead
  subscriptions (404/410) are pruned on send; other failures increment `failed_count`
  (pruned past a threshold). In the demo, `push_subscriptions` is an *ephemeral* table
  (visitor trace): wiped on every reset, never exported with the seed.
- `lib/Audit.php`: writes to `audit_log` (who did what) via `log()` — which also prunes
  opportunistically (1 % of writes, `LIMIT 5000`) when `audit_retention_days` > 0, so the
  log doesn't grow without bound (a row per attachment view) — and reads it back via
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
- **Theming**: Tailwind v4 `@theme` in `src/style.css` defines semantic `primary`/`accent`/
  `success` color scales as CSS variables; components use `bg-primary-600`, `text-accent-700`,
  `ring-success-200`, etc. Recoloring = editing those scales (or applying a `.theme-*` class
  on `<html>`). `success` is its own themable scale (default: Tailwind's `green`), separate
  from `accent` so "success" never gets tied to the brand color; chart colors set in JS read
  the same variables via `getComputedStyle`. The mobile hamburger is themed via `.km-hamburger`
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
  freezes from 540 px up. A sibling global setting `table_header_lines` (`1` | `2` default
  | `3`, same Settings → `/config` → `appConfig` path, `useTableHeaderLines()`) caps how
  many lines a long column **header** wraps to (`line-clamp` + max-width; full text in the
  `title`), so a long question no longer stretches the column to one wide line. Applied to
  the submissions table and the public share table.
- **PWA / offline**: `vite-plugin-pwa` in `injectManifest` mode with a hand-written service
  worker (`src/sw.js`): app shell precached, SPA navigations fall back to `index.html`
  (denylisting `/api` so CSV/attachment downloads hit the network), API GETs cached
  network-first (4 s timeout; attachments in a separate bounded `CacheFirst` cache). On
  *client offline* or timeout it falls back to the last seen data; a server **5xx is
  returned to the app** (not masked as a network error) so real errors stay visible. Only
  200s are cached. `composables/offline.js` exposes
  `isOnline` (banner in `App.vue`) and `clearDataCaches()`, called on logout so no sensitive
  data outlives the session on shared devices. The SW is build-only (disabled in dev). It
  also carries the **Web Push handlers**: `push` shows the system notification from the
  (end‑to‑end‑encrypted) payload `{title, body, url, tag}` — count + link only — and
  `notificationclick` focuses an open app tab (or opens one) at the target URL.
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

**Schema‑drift safety net.** Because upgrades are hand‑applied, deploying new code over a
DB that wasn't migrated would otherwise fail with a cryptic `Unknown column` 500.
`lib/SchemaCheck` is the single declarative list of post‑1.0 columns **and whole tables**
the code expects (columns in `CHECKS` with their idempotent `ALTER`, tables in `TABLE_CHECKS`
with a verbatim copy of the canonical `CREATE TABLE` — kept in sync with `001` by test); an
entry may declare a `backfill` statement that populates the new column from existing data,
and `migrate.php` additionally recomputes the payload‑derived cache columns in PHP
(`SubmissionSync::recomputeCacheColumns`). It powers three things: `php api/cli/doctor.php` (reports drift +
the exact `ALTER`s, exit 1), `php api/cli/migrate.php` (idempotently applies only the missing
ones — run it on every deploy; honours `KM_CONFIG` like the front controller and the
installer, so an alternate DB — e.g. the test one — can be migrated too), and an
admin‑only **"DB out of date" banner** (`/auth/me`
returns `schema_missing` for admins; the shell shows it). Defense in depth: the front
controller maps a `42S22`/`42S02` `PDOException` to a clear `DB_SCHEMA_OUTDATED` error
(no raw SQL leak), and the service worker no longer masks API `5xx` as an opaque network
error — the real response reaches the app.

Key tables: `kobo_accounts`, `users`, `user_sessions`, `forms`, `submissions_cache`,
`submission_reviews`, `user_form_permissions`, `notification_config`, `audit_log`,
`login_attempts`, `rate_hits`, `settings`, `password_resets`, `share_links`,
`contact_messages` (messages from the public contact form on the «Apoyar» page; admins read
and manage them from the `/admin/messages` inbox — statuses `new`/`read`/`archived`),
`push_subscriptions`, and the sample‑monitoring pair `sample_targets` (current plan) /
`sample_target_history` (plan snapshots).

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
edit path — and, since it also serves the `GET` asset/data endpoints (empty lists), full
submission syncs — can be exercised without real Kobo. Tests make real HTTP calls (cURL + cookie jar,
self‑Origin to pass CSRF). Because the server runs in another process, fixtures are committed:
each test truncates the working tables and seeds what it needs (`setUp`/`tearDown`). Coverage:
login/`/auth/me`/logout/login rate‑limit, CSRF enforcement, password reset (forgot → seeded
token → reset), single + batch review (incl. `can_validate` gating and RowScope 404), list/
detail/export with RowScope + FieldScope, submission editing (uuid migration, review
migration, `KOBO_EDIT_FAILED` on a forced bulk failure), and the sync flows (the anti‑wipe
confirmation and the login‑triggered stale pass). CI runs both layers (see below).

### CI
`.github/workflows/ci.yml` (GitHub Actions, **no Docker**) runs three jobs on push/PR:
`lint` (`php -l` sweep + `composer validate`), `frontend` (`npm ci` + `npm run build` + i18n
parity via `scripts/check-i18n-parity.mjs`), and `phpunit` (MariaDB provisioned on the runner
with `ankane/setup-mariadb`, `db/*.sql` applied to `kobomanager_test`, then the full suite with
`TEST_DB_*` env pointing at it).
