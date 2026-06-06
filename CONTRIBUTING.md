# Contributing — KoboManager

Thanks for your interest! This guide covers conventions and common tasks. For the design
overview read [`ARCHITECTURE.md`](./ARCHITECTURE.md); for setup read [`README.md`](./README.md).

## Getting set up

1. `npm install` and copy `api/config.example.php` → `api/config.php` (fill DB + generated
   keys; see README).
2. Create the database and apply `db/*.sql` in order.
3. `npm run dev` runs the PHP API (`127.0.0.1:8787`) and Vite (`localhost:5173`) together.
4. Backend tests: `cd api && composer install && composer test` (uses a separate
   `kobomanager_test` DB — see README).

## Principles

- **No runtime dependencies in the backend.** The PHP API uses only the standard library
  (PDO, cURL, libSodium). PHPUnit is the *only* dev dependency. Don't add Composer/runtime
  packages without discussion — prefer a small, readable implementation.
- **Frontend dependencies stay lean.** Vue, Vite, Pinia, vue-router, vue-i18n, Tailwind,
  axios, plus chart.js and leaflet for the stats/map views. Justify new ones.
- **Security first.** The Kobo token is never sent to the browser. Respect the auth/permission
  guards and the CSRF check; validate input on the server.

## Conventions

### Database
- **No incremental migrations** (pre‑public). Add a new `db/NNN_name.sql` with
  `CREATE TABLE IF NOT EXISTS` rather than `ALTER`. The full schema = all `db/*.sql` applied in
  order. Runtime‑configurable behavior goes in the `settings` table, not the schema.

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

## Commits & PRs

- Small, focused commits with clear messages (the history uses an
  `area: short summary` style, e.g. `seguridad: …`, `ux: …`, `docs: …`).
- Update [`CHANGELOG.md`](./CHANGELOG.md) (under *Sin publicar*) and, when relevant,
  [`ROADMAP.md`](./ROADMAP.md) in the same change.
- Verify your change runs (the app and, for backend logic, the test suite) before opening a PR.

## License of contributions

By contributing you agree your contributions are licensed under the project's
**AGPL-3.0-or-later** (see [`LICENSE`](./LICENSE)).
