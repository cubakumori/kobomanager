# Contributing — KoboManager

Thanks for your interest! This guide covers conventions and common tasks. For the design
overview read [`ARCHITECTURE.md`](./ARCHITECTURE.md); for setup read [`README.md`](./README.md).

## Getting set up

1. `npm install` and copy `api/config.example.php` → `api/config.php` (fill DB + generated
   keys; see README).
2. Create the database and apply `db/*.sql` in order.
3. `npm run dev` runs the PHP API (`127.0.0.1:8787`) and Vite (`localhost:5173`) together.
   To test the **PWA** (service worker, offline reads) you need the build: `npm run build`
   then `npx vite preview --port 4173` (the SW is disabled in dev on purpose), and add
   `http://localhost:4173` to `CORS_ALLOWED_ORIGINS` in your local `api/config.php`.
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
  guards and the CSRF check; validate input on the server. To report a vulnerability, see
  [`SECURITY.md`](./SECURITY.md) (please don't open a public issue for security problems).

## Conventions

- **No incremental migrations** (pre‑public). The schema lives in TWO files:
  `db/001_schema.sql` (every `CREATE TABLE`, canonical) and `db/002_defaults.sql`
  (idempotent `INSERT`s seeding the `settings` defaults); never `ALTER`, and **only
  portable DDL** — it must run on both MySQL 5.7+ and MariaDB (no MariaDB‑only syntax
  like `ADD COLUMN IF NOT EXISTS`). New column → edit the canonical `CREATE TABLE`;
  new table → add it to `001` with `CREATE TABLE IF NOT EXISTS`; new setting default →
  `002`. To pick up a change on an existing DB you recreate it (or apply the same DDL
  by hand in dev/test). Runtime‑configurable behavior goes in the `settings` table,
  not the schema.

### Backend
- One class per file in `lib/`, no namespaces (autoloaded by classmap for tests).
- New endpoint = add a route in `api/index.php` and a script in `api/v1/...`. Start with the
  auth guard (`Auth::require()` / `requireAdmin()` / `requireForm()`), validate input via
  `Request`, return through `ErrorResponse::ok()/send()`. Add new error codes to the table in
  `lib/ErrorResponse.php`.
- Audit state‑changing admin actions with `Audit::log(...)`.

### Frontend
- **i18n is mandatory**: every user‑facing string is a key present in **both**
  `src/i18n/locales/es/` and `src/i18n/locales/en/` (one JSON file per area —
  `common`, `auth`, `admin`, `sharing`, … — each holding whole top‑level
  namespaces, so `$t('ns.key')` never carries a file prefix). Add new keys to
  the matching area file in both locales. No hardcoded text.
- Use the color **tokens** (`primary`/`accent`/`success`), not raw `blue-*`/`emerald-*`/`green-*`,
  so theming keeps working. Success/approved states use the `success-*` token (a themable green,
  default Tailwind `green`); for chart colors set in JS, read the CSS variable
  (`getComputedStyle(...).getPropertyValue('--color-success-600')`) instead of hardcoding a hex.
- **Dark mode**: neutrals (`white`/`slate-*`) flip automatically under `.dark`, so build with
  them and most components need nothing extra. If a component must stay dark in light mode
  (sidebar-style surfaces), add `.km-pin-neutrals`. Use the class-based `dark:` variant only
  for spot fixes (e.g. a light `accent-50` background that must darken). Never hardcode grays.
- Loading states in list/detail views use `Skeleton.vue` (variants `table`/`lines`/`cards`)
  instead of a plain "Loading…" text. In filterable lists, gate the skeleton behind a
  `loaded` flag (first successful load) so filter changes don't flash it; dim the table
  (`opacity-60` + transition) while refreshing.
- New tables follow the **frozen-column pattern**: import `useTableFreeze()`
  (`composables/appConfig.js`) and give the first `th`/`td` a conditional
  `freezeFirst() ? 'sticky left-0 z-10 bg-…' : ''` class (solid background, plus
  `group`/`group-hover` when rows highlight on hover, and a `max-w-[calc(40vw-2rem)]`
  cap with `truncate` on small screens for wide first cells).
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
