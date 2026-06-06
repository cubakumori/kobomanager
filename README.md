# KoboManager

An intermediate layer between KoboToolbox accounts and a small group of users, letting
them view, edit and validate submissions without needing a KoboToolbox account.

- Changelog: [`CHANGELOG.md`](./CHANGELOG.md)
- Pending work and future ideas: [`ROADMAP.md`](./ROADMAP.md)
- Production deployment: [`DEPLOY.md`](./DEPLOY.md)

## Who it's for

KoboManager is aimed at organizations that need to give reviewers or field staff scoped
access to KoboToolbox submissions — view, edit and validate — **without spending Kobo
licenses/seats for every user and without exposing the API token**. The administrator
holds the Kobo credentials (stored encrypted); end users work through KoboManager with
per-form permissions and an internal review workflow decoupled from Kobo.

## Repository layout

The frontend lives at the repo root (same as in deployment); the backend in `/api`.

```
/            Vue 3 + Vite (SPA): index.html, src/, public/, vite.config.js
/api         PHP 8 backend (REST API)
/db          SQL migrations
```

On deployment, the `dist/` build goes to the server root and `/api` is uploaded
as-is (see [`DEPLOY.md`](./DEPLOY.md)).

## Requirements

- PHP 8.1+ with the `sodium` and `pdo_mysql` extensions
- MySQL / MariaDB
- Node.js 18+ and npm

## Getting started (development)

### 1. Database

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Apply all migrations in order
for f in db/*.sql; do mysql kobomanager < "$f"; done
```

> In this repo `api/config.php` already exists with development keys. Do **not** commit
> that file (it's in `.gitignore`). For a fresh environment:
>
> ```bash
> cp api/config.example.php api/config.php   # then fill in the values
> php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'   # CONFIG_TOKEN_KEY
> php -r 'echo bin2hex(random_bytes(32));'                                         # JWT_SECRET
> ```

### 2. Run the app (single command)

```bash
npm install
npm run dev
```

This starts **both at once** (via `concurrently`):

- **api** → `php -S 127.0.0.1:8787 api/index.php` (backend)
- **web** → `vite` at http://localhost:5173 (proxies `/api` → backend)

Open http://localhost:5173. The dashboard shows the result of `/api/v1/health`.

Standalone scripts if needed: `npm run dev:api`, `npm run dev:web`, `npm run build`.

> A `predev` step frees ports `8787`/`5173` automatically before starting (so a leftover
> server doesn't cause *"Address already in use"*). It uses `lsof`/`kill` (macOS/Linux);
> on Windows, remove it or stop the previous process manually.

### 3. Create the first administrator

Creating users via the API requires being authenticated as an admin, so the first
admin is created from the CLI:

```bash
php api/cli/create_user.php <email> <password> <name> admin
```

## Theming (colors)

Brand colors are centralized as theme tokens in [`src/style.css`](./src/style.css), so
recoloring the whole app means editing one place — no find-and-replace across components.

Two semantic color scales drive the UI (Tailwind v4 `@theme`):

- **`primary`** — actions, links, buttons, focus rings (default: blue).
- **`accent`** — brand/secondary color: *My forms* cards and a form's table header
  (default: emerald green).

(The "success" green used for *Saved ✓* messages stays on Tailwind's `green` on purpose,
independent of the theme.)

### Change the default colors

Edit the `primary` / `accent` scales (50–900) inside the `@theme { … }` block in
`src/style.css`, then rebuild (`npm run build`). The utility classes (`bg-primary-600`,
`text-accent-700`, …) resolve to these CSS variables automatically.

### Switch to a bundled alternate theme

`src/style.css` also ships two ready-made palettes as classes: **`theme-teal`**
(teal + amber) and **`theme-violet`** (violet + emerald). Activate one by adding the class
to the `<html>` element in [`index.html`](./index.html):

```html
<html lang="es" class="theme-teal">
```

…or at runtime: `document.documentElement.classList.add('theme-violet')`. With no class,
the default blue/green theme applies. To add your own, copy one of those classes and change
the values.

## Tests (backend)

Backend unit tests use **PHPUnit** (the only dev dependency — the runtime stays
dependency-free). They run against a **separate** database so your dev data is untouched.

```bash
# 1. One-time: create the test database and load the schema
mysql -e "CREATE DATABASE kobomanager_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in db/*.sql; do mysql kobomanager_test < "$f"; done
# (grant your DB user access to kobomanager_test if it isn't the socket/admin user)

# 2. Install PHPUnit and run the suite
cd api
composer install
composer test         # or: ./vendor/bin/phpunit
```

Each test runs inside a transaction that is rolled back, so the test DB stays clean.
Connection settings default to `kobomanager_test` on `127.0.0.1` and can be overridden
with `TEST_DB_*` environment variables (see `api/tests/bootstrap.php`). Current coverage:
auth/permissions and JWT session lifecycle, rate limiting, settings, token encryption and
the geo parser.

## Languages

The interface is available in **Spanish** and **English**. The admin sets the default
language in *Settings*; each user can override it in their profile.

## Status

First complete functional version. See [`CHANGELOG.md`](./CHANGELOG.md) for what's shipped
and [`ROADMAP.md`](./ROADMAP.md) for what's pending.
