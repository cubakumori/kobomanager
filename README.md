# KoboManager

An intermediate layer between KoboToolbox accounts and a small group of users, letting
them view, edit and validate submissions without needing a KoboToolbox account.

- Changelog: [`CHANGELOG.md`](./CHANGELOG.md)
- Pending work and future ideas: [`ROADMAP.md`](./ROADMAP.md)
- Production deployment: [`DEPLOY.md`](./DEPLOY.md) — including how to run your own
  public **demo instance** (`DEMO_MODE`)

## Who it's for

KoboManager is aimed at organizations that need to give reviewers or field staff scoped
access to KoboToolbox submissions — view, edit and validate — **without spending Kobo
licenses/seats for every user and without exposing the API token**. The administrator
holds the Kobo credentials (stored encrypted); end users work through KoboManager with
per-form permissions — including multi-condition (AND/OR) row-level scoping and
column-level field hiding — and an internal review workflow synced with Kobo's
native validation status.

## Features

**Access & permissions**

- Your team signs in with app users — **no KoboToolbox account needed**, and the API
  token is never exposed to the browser (stored encrypted on the server).
- Per-form permissions (view / edit / validate).
- **Multi-condition row-level scoping** (AND/OR groups; `in`, `not in`, ranges, empty,
  and `has_any/all/none` for multi-selects) — each user sees only the rows that match.
- **Column-level permissions**: hide fields or mark them read-only, per user.

**Review**

- Internal **review workflow** (pending / approved / on hold / rejected), one-by-one or
  in batches, **synced both ways with Kobo's native validation status**: a decision made
  here is pushed to Kobo, and a change made directly in Kobo is pulled back on the next
  sync (Kobo wins on conflict).

**Sharing**

- **Public read-only links** — share a form without giving anyone an account, optionally
  with a password, an expiry date, and the same row/column scoping you apply to your
  team. Submission list, map, statistics, and attachments are opt-in per link (a
  stats-only link shows charts without exposing individual submissions). You can also
  **freeze a link to a subset** — only approved submissions, and/or specific teams —
  applied across every view it exposes. The public view shows the data's freshness
  ("data as of …").

**Analysis**

- **Statistics**: totals, submissions per day/month, activity by hour and weekday
  (configurable timezone), 7/30-day trends, fill-in duration, and distribution by review
  status and by question. **Filterable** by review status (header cards) and by team
  (toggles on the team breakdown); the default scope on open is configurable in *Settings*
  (all submissions or approved only).
- **Map** of submission geopoints.

**Data**

- **Human-readable labels** from the XLSForm (codes → text, multilingual).
- **Attachments** (photo / audio / video / file) served through an authenticated proxy.
- **Search** and persistent **advanced filters**.
- **CSV export** that honors each user's row/column scoping.
- **Submission editing** that writes back to Kobo.

**Operation**

- Bilingual UI (Spanish / English), light/dark theme, installable **PWA** with offline
  reads, **email notifications**, a built-in **demo mode**, a one-command **CLI
  installer**, and fully **themable** colors (below).

## Repository layout

The frontend lives at the repo root (same as in deployment); the backend in `/api`.

```
/            Vue 3 + Vite (SPA): index.html, src/, public/, vite.config.js
/api         PHP 8 backend (REST API)
/db          SQL schema (001 = all tables, 002 = settings defaults)
```

On deployment, the `dist/` build goes to the server root and `/api` is uploaded
as-is (see [`DEPLOY.md`](./DEPLOY.md)).

## Requirements

- PHP 8.1+ with the `sodium` and `pdo_mysql` extensions
- MySQL / MariaDB
- Node.js 22+ and npm (the build itself runs on 20.19+, but the dev runner needs 22+)

## Getting started (development)

### 1. Database

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# Apply all schema files in order (they ARE the full schema — no incremental migrations)
for f in db/*.sql; do mysql kobomanager < "$f"; done
# …or let the installer do schema + first admin in one go (after filling api/config.php):
#   php api/cli/install.php
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
> server doesn't cause *"Address already in use"*), and `prepreview` does the same for
> `4173` before `npm run preview` (the production build, where the PWA service worker is
> testable). They use `lsof`/`kill` (macOS/Linux); on Windows, remove them or stop the
> previous process manually.

### 3. Create the first administrator

Creating users via the API requires being authenticated as an admin, so the first
admin is created from the CLI:

```bash
php api/cli/create_user.php <email> <password> <name> admin
```

## Theming (colors)

Brand colors are centralized as theme tokens in [`src/style.css`](./src/style.css), so
recoloring the whole app means editing one place — no find-and-replace across components.

Three semantic color scales drive the UI (Tailwind v4 `@theme`):

- **`primary`** — actions, links, buttons, focus rings (default: blue).
- **`accent`** — brand/secondary color: *My forms* cards and a form's table header
  (default: emerald green).
- **`success`** — success/approved states: *Saved ✓* messages, approved badges, the
  *approved* and *with location* chart slices (default: Tailwind `green`). It's a separate
  token from `accent` (also green) so "success" never gets tied to the brand color, and it's
  themable like the others.

### Dark mode

A **light / dark / auto** switch (header icon + a selector in *My profile*; "auto" follows
`prefers-color-scheme`, persists per device, and always wins over the site default — which
admins set in *Settings*). Dark mode only remaps the **neutral** colors (`white` + the
`slate` scale) under the `.dark` class in `src/style.css`, so brand and semantic tokens are
untouched and it composes freely with the alternate themes below.

### Change the default colors

Edit the `primary` / `accent` / `success` scales (50–900) inside the `@theme { … }` block in
`src/style.css`, then rebuild (`npm run build`). The utility classes (`bg-primary-600`,
`text-accent-700`, `ring-success-200`, …) resolve to these CSS variables automatically. Chart
colors set in JS read the same variables via `getComputedStyle`.

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

Backend tests use **PHPUnit** (the only dev dependency — the runtime stays
dependency-free). They run against a **separate** database so your dev data is untouched.
Two layers: unit/DB tests (transaction-per-test) and **HTTP integration tests**
(`api/tests/http/`) that boot the real API in an ephemeral `php -S` server plus a Kobo
stub and make real HTTP requests. Both run with `composer test`.

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
auth/permissions and the JWT session lifecycle (including the sliding session and absolute
cap, and rejection of non-HS256 tokens), rate limiting (per-IP and bucketed), settings,
token encryption **and key rotation**, the geo parser, derived metrics, attachment
classification, the submission-search projection/clause (incl. the visible-fields variant),
row scoping, column-level permissions (`FieldScope`), and share-link
resolution/tickets/attachment access. The HTTP layer adds end-to-end coverage of
login/JWT/logout/rate-limit, CSRF, password reset, single + batch review, list/detail/
export with scoping and field hiding, and submission editing (against the Kobo stub).
**Continuous integration** (GitHub Actions, no Docker) runs lint + frontend build + the
full PHPUnit suite against MariaDB — see `.github/workflows/ci.yml`.

## Offline / PWA

KoboManager is a **progressive web app**: it can be installed from the browser ("Install
app") and tolerates poor connectivity. The app shell is precached (it opens instantly even
without network) and API reads are cached with a network-first strategy — anything already
viewed (lists, details, stats) can be re-read offline or while the server is unreachable,
with a visible "offline" notice. Writes (editing, reviewing, syncing) require a connection.
On logout the cached data is wiped from the device; the strategy lives in
[`src/sw.js`](./src/sw.js).

## Languages

The interface is available in **Spanish** and **English**. The admin sets the default
language in *Settings*; each user can override it in their profile.

## Status

First complete functional version. See [`CHANGELOG.md`](./CHANGELOG.md) for what's shipped
and [`ROADMAP.md`](./ROADMAP.md) for what's pending.

## Contributing

See [`ARCHITECTURE.md`](./ARCHITECTURE.md) for the design overview and
[`CONTRIBUTING.md`](./CONTRIBUTING.md) for conventions and how to get set up.

Found a security issue? Please report it privately — see [`SECURITY.md`](./SECURITY.md).

---

## Support this project

If you've found this **KoboManager** app useful, please consider supporting me:

[![PayPal](https://img.shields.io/badge/PayPal-Donar-blue?style=for-the-badge&logo=paypal)](https://paypal.me/ernestortiz)

[![Ko-fi](https://img.shields.io/badge/BUY_ME_A-KO_FI-darkseagreen?style=for-the-badge&logo=ko-fi)](https://ko-fi.com/kumoricuba)

---

## License

Licensed under the **GNU Affero General Public License v3.0 or later** (AGPL-3.0-or-later) —
see [`LICENSE`](./LICENSE). In short: you may use, study, modify and redistribute it, but if
you run a modified version as a network service you must offer your users its source code.
The copyright holder may also offer the software under separate commercial terms.

## Disclaimer

KoboManager is an independent open-source project and is **not affiliated with, endorsed
by, or sponsored by** [KoboToolbox](https://www.kobotoolbox.org) or Rakuten Kobo.
"KoboToolbox" and "Kobo" are trademarks of their respective owners.

Copyright (C) 2026 Ernesto Ortiz and KoboManager contributors.
