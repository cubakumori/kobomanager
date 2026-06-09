# Contributing — KoboManager

Thanks for your interest! This guide covers conventions and common tasks. For the design
overview read [`ARCHITECTURE.md`](./ARCHITECTURE.md); for setup read [`README.md`](./README.md).

## Getting set up

1. `npm install` and copy `api/config.example.php` → `api/config.php` (fill DB + generated
   keys; see README).
2. Create the database and apply `db/*.sql` in order.
3. `npm run dev` runs the PHP API (`127.0.0.1:8787`) and Vite (`localhost:5173`) together.
4. Backend tests: `cd api && composer install && composer test` (uses a separate
   `kobomanager_test` DB — see README). This runs both the unit/DB tests and the **HTTP
   integration tests** (`api/tests/http/`), which spin up an ephemeral `php -S` server +
   a Kobo stub automatically — no extra setup beyond the test DB. CI runs the same suite
   plus `npm run build` and the i18n parity check (`npm run i18n:check`); see
   `.github/workflows/ci.yml`.

## Principles

- **No runtime dependencies in the backend.** The PHP API uses only the standard library
  (PDO, cURL, libSodium). PHPUnit is the *only* dev dependency. Don't add Composer/runtime
  packages without discussion — prefer a small, readable implementation.
- **Frontend dependencies stay lean.** Vue, Vite, Pinia, vue-router, vue-i18n, Tailwind,
  axios, plus chart.js and leaflet for the stats/map views. Justify new ones.
- **Security first.** The Kobo token is never sent to the browser. Respect the auth/permission
  guards and the CSRF check; validate input on the server.

## Conventions

- **No incremental migrations** (pre‑public). The full schema = all `db/*.sql` applied in
  order; never `ALTER`. Edit the **canonical `CREATE TABLE`** where the table lives: add new
  columns there (e.g. `user_form_permissions.field_filter`, `forms.submissions_synced_at`,
  `submissions_cache.search_text`, `share_links.expose_attachments`), and
  add a sibling table to its thematic file (e.g. `rate_hits` next to `login_attempts` in
  `db/002`) or, for a new area, a new `db/NNN_name.sql` — all with `CREATE TABLE IF NOT EXISTS`.
  To pick up a change on an existing DB you recreate it (or apply the same DDL by hand in
  dev/test). Runtime‑configurable behavior goes in the `settings` table, not the schema.

### Backend
- One class per file in `lib/`, no namespaces (autoloaded by classmap for tests).
- New endpoint = add a route in `api/index.php` and a script in `api/v1/...`. Start with the
  auth guard (`Auth::require()` / `requireAdmin()` / `requireForm()`), validate input via
  `Request`, return through `ErrorResponse::ok()/send()`. Add new error codes to the table in
  `lib/ErrorResponse.php`.
- Audit state‑changing admin actions with `Audit::log(...)`.

### Frontend
- **i18n is mandatory**: every user‑facing string is a key present in **both**
  `src/i18n/es.json` and `src/i18n/en.json`. No hardcoded text.
- Use the color **tokens** (`primary`/`accent`), not raw `blue-*`/`emerald-*`, so theming keeps
  working. Success states use Tailwind `green` on purpose.
- Reuse `Modal`/`ConfirmDialog` (via `confirmDialog(...)`) instead of native `alert/confirm`,
  so dialogs get the shared accessibility behavior.
- Inside `<style scoped>`, `@reference "../../style.css"` (not `"tailwindcss"`) so `@apply`
  sees the project's theme tokens.

### Tests
- Add/extend PHPUnit tests for backend logic (especially auth, permissions, anything
  security‑sensitive). DB tests extend `DbTestCase` (transaction‑per‑test). Keep them passing:
  `composer test`.
- For endpoint behaviour, prefer an **HTTP integration test** (`api/tests/http/`, extend
  `HttpTestCase`): seed with the helpers (`seedUser`/`seedForm`/`grant`/`seedSubmission`/…)
  and call `request()` / `login()`. These commit, so they truncate the working tables in
  `setUp`/`tearDown` — don't rely on a fixed email colliding with another suite. Anything
  that talks to Kobo goes through the stub (`tests/kobo_stub.php`); extend it if you need a
  new Kobo response.

## Commits & PRs

- Small, focused commits with clear messages (the history uses an
  `area: short summary` style, e.g. `seguridad: …`, `ux: …`, `docs: …`).
- Update [`CHANGELOG.md`](./CHANGELOG.md) (under *Sin publicar*) and, when relevant,
  [`ROADMAP.md`](./ROADMAP.md) in the same change.
- Verify your change runs (the app and, for backend logic, the test suite) before opening a PR.

## License of contributions

By contributing you agree your contributions are licensed under the project's
**AGPL-3.0-or-later** (see [`LICENSE`](./LICENSE)).
