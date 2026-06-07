# Architecture — KoboManager

A technical overview for contributors. For setup and commands see [`README.md`](./README.md);
for conventions see [`CONTRIBUTING.md`](./CONTRIBUTING.md).

## What it is

KoboManager is a thin management layer **between KoboToolbox accounts and a small team**.
An administrator connects Kobo accounts (API tokens stored encrypted); other users get
per‑form permissions and review submissions **without a Kobo account and without ever seeing
the token**. Submissions are mirrored into a local cache for fast browsing and an internal
review (approve/reject) flow decoupled from Kobo.

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
  cli/                       create_user.php (first admin)
  tests/                     PHPUnit (only dev dependency)
/db              *.sql schema files, applied in order (see Database)
```

The frontend lives at the repo root because that mirrors the deployment layout: the Vite
build (`dist/`) goes to the web root and `/api` sits alongside it (see [`DEPLOY.md`](./DEPLOY.md)).

## Backend

### Front controller & routing
All API requests enter `api/index.php`. It loads `config.php` + `lib/*`, applies CORS,
enforces CSRF, resolves the path after `/api/v1/` against a **routes table** (patterns with
`:param` segments) and `require`s the matching script in `v1/`. Dynamic params are read via
`Request::param()`. Each endpoint script checks the HTTP method itself.

### Responses
`ErrorResponse::ok($data)` and `ErrorResponse::send($code, $msg?, $status?)` emit the JSON
envelope (`{success, data}` / `{success, error:{code,message}}`) and `exit`. Error codes map
to HTTP statuses in one table; the frontend maps codes → localized messages (`errors.<CODE>`).

### Auth & sessions (`lib/Auth.php`)
- Hand‑rolled **JWT (HS256)** — no libraries. Token travels in an **HttpOnly cookie**
  (`SameSite=Lax`, `Secure` in prod).
- Every session is a row in `user_sessions` keyed by the JWT `jti`. `currentUser()` validates
  signature + expiry **and** that the `jti` row still exists **and** the user is active — so
  deleting the row (logout / admin remote revoke / deactivation) invalidates the token.
- Guards: `require()`, `requireAdmin()`, and per‑form `canForm($user,$id,$cap)` /
  `requireForm(...)` where `cap ∈ {view,edit,validate}` (admins bypass).

### Row‑level scoping (`lib/RowScope.php`)
A per‑(user, form) filter (`user_form_permissions.row_filter`, JSON) can restrict **which
submissions** a viewer sees, on top of the form capability. The rule is a list of
conditions (`{field, values}`) combined with **AND**; a submission passes when, for every
condition, its value at `field` is in `values`. `NULL`/empty → unrestricted; a condition
with no values matches nothing (fail‑closed); admins are never restricted. The same rule is
applied as a SQL predicate over `submissions_cache.json_payload` (lists, stats, map, form
counts, daily summary) and as an in‑PHP `matches()` check on the detail/edit/validate path,
where an out‑of‑scope submission returns 404. Note: MariaDB stores JSON as text and keeps the
`\/` escape, so group‑path keys (`G01/P1_3`) are matched with an escaped JSON path.

### Public share links (`lib/ShareLink.php`)
Read‑only links let anyone browse a form's submissions **without a session** (M1). A
`share_links` row carries an unguessable URL `token`, what it exposes (`expose_list` /
`expose_detail` / `expose_map`), an optional `row_filter` (reuses `RowScope`), an optional
`password_hash`, and optional `expires_at` / `revoked_at`. `resolve()` returns the row only
while active (not revoked, not expired, form active). The public endpoints live under
`v1/public/` and **skip `Auth`** (like `v1/config.php`); `ShareLink::requireAccess()` is
their guard — it resolves the token, checks the requested capability, and for
password‑protected links requires a short‑lived **HMAC ticket** (issued by the rate‑limited
`unlock` endpoint, sent back via the `X-Share-Ticket` header). Out‑of‑scope or other‑form
submissions return 404; attachments and internal review status are never exposed. Admin CRUD
is in `v1/admin/shares*`; the password policy is the `share_password_policy` setting.

### CSRF
For mutating methods (POST/PUT/DELETE/PATCH) the front controller requires the request
`Origin`/`Referer` to match an allowed origin (`CORS_ALLOWED_ORIGINS` + the server's own
host); requests with neither header (CLI/cron) are exempt. Reinforces the `SameSite=Lax` cookie.

### Rate limiting (`lib/RateLimit.php`)
IP‑based counting over `login_attempts` within a time window. Used on login and on the
forgot‑password endpoint.

### Kobo integration (`lib/KoboClient.php`)
Talks to the **Kobo API v2** with the account token (cURL, no SDK). Discovery lists `survey`
assets; submissions are fetched paginated; edits use `PATCH .../data/bulk/`. Errors become
`KoboException` with standard codes. The token is decrypted on the fly with `TokenVault`
(libSodium `secretbox`; master key only in `config.php`).

### Sync model
- **Forms discovery** (`v1/admin/forms_sync.php`): upserts `forms`, filters by the
  `sync_deployment_statuses` setting, **deactivates** forms that fall outside the filter and
  **deletes** ones removed from Kobo.
- **Submissions** (`lib/SubmissionSync.php`, cron + on demand): *incremental* (cursor =
  `MAX(submitted_at)` in cache) with a deletion sweep, or *full* (re‑download + reconcile by
  `_uuid`, reflecting edits made in Kobo). Reused by admin and viewer sync endpoints.
- **Readable labels** (`lib/FormSchema.php`): caches a normalized XLSForm schema per form
  (`forms.schema_json`) so the UI shows question/option labels instead of raw codes.
- **Geo** (`lib/Geo.php`): parses geopoint/geotrace/geoshape for the map view.

### Settings & audit
- `lib/Settings.php`: global key/value settings (JSON) — sync statuses, default locale, label
  mode, password‑reset flag, viewer action flags.
- `lib/Audit.php`: writes to `audit_log` (who did what).

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
`login_attempts`, `settings`, `password_resets`, `share_links`.

## Tests

PHPUnit (`api/tests/`), the only dev dependency. They run against a **separate** database
(`kobomanager_test`); each test runs in a transaction that is rolled back. Coverage today:
auth/permissions + JWT session lifecycle, rate limiting, settings, token encryption, geo
parsing. Endpoint‑level (HTTP) integration tests are a known gap — see [`ROADMAP.md`](./ROADMAP.md).
