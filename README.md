# KoboManager

An intermediate layer between KoboToolbox accounts and a small group of users, letting
them view, edit and validate submissions without needing a KoboToolbox account.

- Changelog: [`CHANGELOG.md`](./CHANGELOG.md)
- Pending work and future ideas: [`ROADMAP.md`](./ROADMAP.md)
- Production deployment: [`DEPLOY.md`](./DEPLOY.md)

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

### 3. Create the first administrator

Creating users via the API requires being authenticated as an admin, so the first
admin is created from the CLI:

```bash
php api/cli/create_user.php <email> <password> <name> admin
```

## Languages

The interface is available in **Spanish** and **English**. The admin sets the default
language in *Settings*; each user can override it in their profile.

## Status

First complete functional version. See [`CHANGELOG.md`](./CHANGELOG.md) for what's shipped
and [`ROADMAP.md`](./ROADMAP.md) for what's pending.
