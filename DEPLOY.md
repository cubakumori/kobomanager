# Deployment — KoboManager

Guide for deploying to a VPS or hosting with **Apache + PHP 8.1+ + MySQL/MariaDB**.

## 1. Server requirements

- PHP 8.1 or higher with the `sodium`, `pdo_mysql` and `curl` extensions.
- MySQL 5.7+ / MariaDB 10.4+.
- Apache with `mod_rewrite` enabled (or Nginx with equivalent rules).
- HTTPS (Let's Encrypt). **Required**: the session cookie uses `Secure`.

## 2. Layout on the server

### Domain, subdomain or subfolder?

KoboManager must be served from the **root of an origin** — a domain
(`https://kobomanager.example`) or a subdomain (`https://demo.example.org`). Both work
with the exact same procedure in this guide; the only differences are the DNS record,
the vhost `DocumentRoot`/`server_name` and the values you put in `APP_URL` /
`CORS_ALLOWED_ORIGINS` (and the HTTPS certificate covering that hostname).

Installing under a **subfolder** (`https://example.org/kobomanager/`) is **not
supported**: the SPA build, the router, the API client (`/api/v1/...`) and the PWA
service-worker scope all assume the site root. If you only have one domain, use a
subdomain instead — it is also cleaner to move or retire later.

The compiled frontend lives in the public root and the backend in `/api`:

```
/public_html            (or /var/www/html)
  index.html            ← Vue build (dist/)
  assets/               ← Vue build (dist/assets)
  sw.js, registerSW.js, manifest.webmanifest   ← PWA (service worker + manifest)
  .htaccess             ← SPA rewrite — shipped inside dist/ (Apache; see §6)
  /api                  ← PHP backend (upload as-is)
    config.php          ← create on the server, NOT committed
    .htaccess
    index.php, lib/, v1/, cron/, cli/
```

> **PWA note**: the build ships a service worker (`sw.js`) that precaches the app shell and
> caches API GETs so the app stays usable on flaky connections. `sw.js` must be served with
> `Cache-Control: no-cache` (or a short max-age) so updates roll out promptly — on Apache
> the shipped root `.htaccess` already does it; on nginx add a
> `location = /sw.js { add_header Cache-Control "no-cache"; }` block. Everything under
> `assets/` is content-hashed and safe to cache aggressively.

## 3. Build and upload

Locally:

```bash
npm install
npm run build          # generates dist/
```

Upload to the server:

1. The **contents** of `dist/` → to the public root (`index.html`, `assets/`, …).
   This includes a ready-to-use root **`.htaccess`** (SPA rewrite + `sw.js` cache
   header) — mind that dotfiles are easy to miss when copying, and see §6 if you need
   to edit it (or if you serve with nginx, which ignores it).
2. The `api/` folder → under the public root (`/api`). You can safely skip the
   development-only baggage: `api/vendor/`, `api/tests/`, the hidden
   `api/.phpunit.cache/`, `phpunit.xml` and `composer.json`/`composer.lock` (the
   runtime has **no** PHP dependencies; those exist only to run the test suite). Uploading them anyway is not a security problem — the
   `api/.htaccess` denies direct access to `lib|cron|cli|tests|vendor` and everything is
   routed through `index.php` — just dead weight.
3. The `db/` folder — these are the **schema files** that §4 runs once to create the
   tables. Upload it only if you'll run §4 from a shell **on the server** (you can
   delete it afterwards; the app never reads it at runtime). If you'd rather pipe the
   SQL from your machine over SSH, skip the upload (see §4).

> Do not upload `node_modules/`, `src/`, or your development `api/config.php`.

## 4. Database

`db/*.sql` is the complete schema, meant to be applied **once, in filename order**, on
an empty database (there are no incremental migrations to track). From a shell on the
server, with the `db/` folder uploaded:

```bash
mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in db/*.sql; do mysql kobomanager < "$f"; done
```

Or from your local machine, without uploading `db/` at all:

```bash
ssh user@server 'mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
for f in db/*.sql; do ssh user@server mysql kobomanager < "$f"; done
```

Create a dedicated MySQL user with privileges only on `kobomanager`.

## 5. Configuration (`api/config.php`)

Copy `api/config.example.php` to `api/config.php` and adjust for **production**:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kobomanager');
define('DB_USER', 'kobomanager');
define('DB_PASS', '••••••');

// Generate once (rotating CONFIG_TOKEN_KEY is supported — see §12; changing JWT_SECRET
// logs everyone out):
define('CONFIG_TOKEN_KEY',     '<64 hex>'); // php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
define('CONFIG_TOKEN_KEY_NEW', '');         // only set during key rotation (§12); empty otherwise
define('JWT_SECRET',           '<64 hex>'); // php -r 'echo bin2hex(random_bytes(32));'

// Sliding session: it renews on activity (idle TTL) up to an absolute cap, then re-login.
define('JWT_TTL',               8 * 60 * 60);     // max idle time
define('SESSION_ABSOLUTE_TTL',  7 * 24 * 60 * 60); // max lifetime since login
define('SESSION_REFRESH_THRESHOLD', JWT_TTL / 2);  // renew the cookie when less than this remains

define('COOKIE_SECURE', true);          // ← true in production (HTTPS)
define('APP_ENV', 'prod');              // hides error details
// APP_URL builds absolute links sent OUTSIDE the app (e.g. the password-reset link in
// emails) — a wrong value here won't break browsing, it breaks those links.
define('APP_URL', 'https://yourdomain.com');
// In a single-domain install (frontend and /api on the same origin) the browser never
// does CORS, so the site works even before touching this; the list matters if the
// frontend ever lives on another origin (as in dev) and as part of the CSRF check.
// Keep it set to your public HTTPS origin(s) anyway.
define('CORS_ALLOWED_ORIGINS', ['https://yourdomain.com']);

define('RESEND_API_KEY', 're_••••••');  // email (see §8); leave '' to disable
define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>');

define('APP_TIMEZONE', 'America/Havana'); // stats hour/weekday in local time (IANA; default 'UTC')
define('APP_TIMEZONE_LABEL', 'La Habana'); // human label for the UI; '' falls back to the IANA id

// Optional — public demo instance (DEMO_MODE, DEMO_RESET_MINUTES, DEMO_LOGIN_HINT): see §13.
```

> **Important:** keep `CONFIG_TOKEN_KEY` somewhere safe. If it's lost or changed, the
> encrypted Kobo tokens can no longer be decrypted.

> **CSRF / CORS:** state-changing requests (POST/PUT/DELETE) are rejected unless their
> `Origin`/`Referer` matches an allowed origin — `CORS_ALLOWED_ORIGINS` plus the server's
> own host. In a normal single-domain install (frontend and `/api` under the same domain)
> this works out of the box; just make sure `CORS_ALLOWED_ORIGINS` lists your **public
> HTTPS origin** (e.g. `https://yourdomain.com`). If the app is served from several
> hostnames (e.g. with and without `www`), add each one.

Create the first administrator (creating users via the API requires being an admin):

```bash
php api/cli/create_user.php admin@yourdomain.com 'StrongPassword' 'Admin Name' admin
```

## 6. SPA rewrite (root `.htaccess`)

The build **ships** the root `.htaccess` inside `dist/` (source: `public/.htaccess`),
so on Apache there is nothing to create — just make sure the dotfile actually got
copied (`ls -a` in the public root; without it the landing page loads but reloading
any internal route like `/dashboard` returns a 404, and service-worker updates roll
out slowly). What it does, in case you need to edit it:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  # Leave /api alone (it has its own .htaccess)
  RewriteRule ^api/ - [L]
  # If the file doesn't exist, serve the SPA
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>

# PWA: sw.js must revalidate promptly; assets/ are content-hashed (cache hard).
<IfModule mod_headers.c>
  <Files "sw.js">
    Header set Cache-Control "no-cache"
  </Files>
</IfModule>
```

The `/api` `.htaccess` ships with the repo (routes through `index.php` and blocks
`config.php` plus the internal `lib/`, `cron/`, `cli/`, `tests/` and `vendor/` folders).

### nginx (no `.htaccess`)

`.htaccess` is Apache-only. On **nginx** replicate the same two protections — route the
SPA fallback to `index.html`, route every `/api/v1/...` request through the front controller,
and **block direct access to internal code and config**:

```nginx
# SPA
location / {
    try_files $uri $uri/ /index.html;
}

# API front controller — everything under /api/v1 goes through index.php
location /api/ {
    # Never serve internal code or secrets directly.
    location ~ ^/api/(lib|cron|cli|tests|vendor)/ { deny all; }
    location ~ ^/api/config.*\.php$            { deny all; }

    try_files $uri /api/index.php$is_args$args;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # adjust to your PHP-FPM socket
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

> Without these `deny` blocks, nginx would happily serve `api/lib/*.php` and `api/config.php`
> as plain text. Verify after deploy: `curl https://yourdomain.com/api/config.php` must **not**
> return the file (expect 403/404), and `…/api/lib/DB.php` likewise.

## 7. Cron jobs

```cron
*/15 * * * *  php /path/api/cron/sync_submissions.php   # Kobo submissions → cache
0    7 * * *  php /path/api/cron/daily_summary.php       # daily email summary
```

Both scripts run only from the CLI (they reject web requests). The daily summary
requires `RESEND_API_KEY` and `MAIL_FROM` to be configured.

## 8. Email (Resend)

KoboManager sends email through the **Resend HTTP API** (`api/lib/Mailer.php`) — no SDK
and **no SMTP server/port** to configure. Email powers:

- the **daily summary** of new submissions (cron, §7),
- **password recovery** (the "forgot password" flow), when enabled, and
- the **contact form** on the public `/apoyar` page (sends to `CONTACT_TO`; messages are also
  stored in `contact_messages`, so nothing is lost even if email delivery fails — admins can
  read and manage them from the in-app inbox at `/admin/messages`).

Setup:

1. Create an account at [resend.com](https://resend.com) and generate an **API key**.
2. **Verify your sending domain** in Resend (DNS records). Until the domain is verified,
   delivery is limited/blocked by Resend.
3. In `api/config.php` set:
   ```php
   define('RESEND_API_KEY', 're_••••••');
   define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>'); // address at the verified domain
   define('CONTACT_TO', 'contact@yourdomain.com');              // inbox for the /apoyar contact form
   ```

If `RESEND_API_KEY` is left empty, sending is **skipped gracefully** (logged, no error):
the app keeps working but email-dependent features (daily summary, password recovery) won't
send anything. Handy for staging.

> The secret lives only in `config.php` (never in the database or exposed to the frontend).

## 9. Post-deployment checks

- `https://yourdomain.com/api/v1/health` → `status: ok` (php, sodium, pdo_mysql, database).
- Sign in with the admin you created; the cookie must travel as `Secure` + `HttpOnly`.
- Add a real Kobo account and click **Sync** under *Forms*.
- Run `sync_submissions.php` manually once and check `submissions_cache`.

## 10. Subsequent updates

1. `npm run build` locally.
2. Replace the **contents of `dist/`** in the public root (`index.html`, `assets/`,
   `sw.js`, manifest — and the root `.htaccess` if it changed) and the `api/` folder
   (without touching `config.php`).
3. Schema changes, if the release notes mention any: they ship as **edits to the
   canonical `CREATE TABLE`s** in `db/001_schema.sql`, so re‑applying the file on an
   existing database does nothing (`CREATE TABLE IF NOT EXISTS` skips existing tables).
   Either apply the change by hand (the changelog states what changed), or recreate the
   database from `db/*.sql` and re‑sync from Kobo.
4. PWA note: the **first** load after an update still serves the previous version from
   the service‑worker precache (the new one activates in the background) — reload once
   before judging the deploy.

## 11. Backups

Two things need backing up; **everything else lives in git** and can be rebuilt with
`npm run build` + re-uploading `api/`.

1. **Database** (`kobomanager`) — users, permissions, Kobo accounts (encrypted tokens),
   the submissions cache, settings, audit log, share links.
2. **`api/config.php`** — the only secret outside git. It holds `CONFIG_TOKEN_KEY` (without
   it the encrypted Kobo tokens are unrecoverable), `JWT_SECRET`, the DB password and the
   Resend key. Keep a copy somewhere safe and separate from the DB dump.

There are **no uploaded files on disk** to back up: attachments are streamed from Kobo on
demand (never stored locally).

**Nightly DB dump (cron) with 14-day retention:**

```cron
30 3 * * *  mysqldump --single-transaction --quick kobomanager | gzip > /var/backups/km/km-$(date +\%F).sql.gz && find /var/backups/km -name 'km-*.sql.gz' -mtime +14 -delete
```

(Use a credentials file or a `[mysqldump]` block in `~/.my.cnf` so the password is not on
the command line. `--single-transaction` gives a consistent dump without locking InnoDB.)

**Restore:**

```bash
gunzip < km-2026-01-31.sql.gz | mysql kobomanager
# and put back api/config.php (same CONFIG_TOKEN_KEY as when the tokens were encrypted)
```

> Test a restore into a scratch database now and then — a backup you've never restored is a
> guess, not a backup.

## 12. Rotating `CONFIG_TOKEN_KEY`

`CONFIG_TOKEN_KEY` encrypts the Kobo API tokens (`kobo_accounts.api_token`, libSodium). You
can rotate it without losing the tokens: the CLI re-encrypts every account from the **old**
key to a **new** one in a single transaction.

1. **Back up** the database and `api/config.php` first (§11).
2. Generate the new key:
   ```bash
   php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
   ```
3. In `api/config.php` keep `CONFIG_TOKEN_KEY` as the **current (old)** key and set
   `CONFIG_TOKEN_KEY_NEW` to the **new** key.
4. Dry run (reads + verifies, writes nothing):
   ```bash
   php api/cli/rotate_token_key.php --dry-run
   ```
5. Rotate for real (transactional; on any error it rolls back and changes nothing):
   ```bash
   php api/cli/rotate_token_key.php
   ```
6. **Promote** the new key: in `api/config.php` set `CONFIG_TOKEN_KEY` to the new key and
   `CONFIG_TOKEN_KEY_NEW` back to `''`.
7. Verify: open *Kobo accounts* and run a **Sync** (forces a token decrypt with the new key),
   or check `/api/v1/health`.

**Rollback.** If something looks wrong *before* step 6 (config still on the old key), the DB
is already on the new key — finish step 6 to match them. If you must go back to the old key,
swap the two keys (`CONFIG_TOKEN_KEY` = new, `CONFIG_TOKEN_KEY_NEW` = old) and run the CLI
again, then promote the old key. As a last resort, restore the DB + `config.php` from the
backup taken in step 1.

## 13. Running a demo instance

KoboManager ships with a built-in **demo mode** so you can run a public sandbox of your
own (a throwaway instance where visitors log in with shared credentials and click around
real features) without letting them break it or read your secrets.

### What `DEMO_MODE` does

In `api/config.php` (all three constants are optional — a config without them behaves as
demo off):

```php
// --- Public demo ---
define('DEMO_MODE', true);          // demo notice + sensitive actions blocked
define('DEMO_RESET_MINUTES', 60);   // informative: shown in the welcome dialog
define('DEMO_LOGIN_HINT', 'admin@demo.org / demo1234'); // shown in the dialog ('' = hidden)
```

`DEMO_LOGIN_HINT` is free text; to advertise **several accounts** (say, an admin and a
restricted viewer) separate them with `|` and the dialog renders them as a list:

```php
define('DEMO_LOGIN_HINT', 'Admin: admin@demo.org / demo1234|Viewer (limited): viewer@demo.org / demo1234');
```

With the flag on, `GET /api/v1/config` exposes `demo_mode`, and the frontend shows a
welcome dialog on every homepage load with the reset cycle and the login hint, plus a
small **DEMO** badge next to the brand everywhere (public pages, login page and the
app shell) — clicking the badge reopens that dialog at any time. Blocked buttons are
disabled with a tooltip, and the API enforces the same list centrally (403
`DEMO_LOCKED`), so direct requests are covered too. Blocked in demo:

- **Kobo accounts** — create/edit/delete (protects the API token of the demo account).
- **Users** — create/edit/deactivate, password changes (own and others'), and revoking
  sessions (including your own: the demo user is shared, closing its sessions would log
  out other visitors). The password-recovery flow is blocked as well.
- **Global settings** (`PUT /admin/settings`).
- **Submission editing** — it writes to the real Kobo account; the local DB reset would
  not undo it.
- **Manual sync against Kobo** ("Update"/"Resync"/account discovery) — saves the demo
  account's API quota. The server-side cron jobs (§7) keep syncing normally.

Everything else stays enabled on purpose — it is what the demo is for: browsing, search
and filters, single and batch review, CSV export, statistics, the map, creating and
revoking share links, language and theme… All of it is local and restored by the reset.

### Demo users

Create the account(s) you publish in `DEMO_LOGIN_HINT` **before** enabling the demo —
the hint is plain text, it does not create anything. Note that the app validates emails
with PHP's `FILTER_VALIDATE_EMAIL`, which requires a dotted domain: an address like
`admin@demo` cannot be created from the admin UI (use `admin@demo.org` instead, or
create it with `php api/cli/create_user.php`, which skips that validation). A nice
touch is a second, viewer-role user with a row filter and hidden/read-only columns
configured, so visitors can log in with each one and compare — advertise both via the
`|` separator in `DEMO_LOGIN_HINT` (above).

### Setup order (the flag goes last)

The demo locks apply to **everyone, admins included** — there is no "owner bypass". So
prepare the instance with `DEMO_MODE` **off** (or the constants absent), because setup
needs exactly the actions the demo blocks (connecting the Kobo account, creating users,
changing settings, manual sync):

1. Install normally (§§1–10) with `DEMO_MODE = false`.
2. Connect the disposable Kobo account, sync, create the demo users, permissions,
   an example share link, settings — leave everything the way visitors should find it.
3. Take the seed dump (below), add the reset cron, set `DEMO_MODE = true`.
4. Only then publish the URL.

To adjust something later, do the same loop over SSH: flip the flag off, change what
you need, regenerate the seed dump, flip it back on.

### Periodic reset

1. With the demo ready, take a seed dump **of the demo instance's database** (it must
   be born there: among other things it contains the Kobo token encrypted with **that
   server's** `CONFIG_TOKEN_KEY` — a dump of a database built elsewhere would carry a
   token the server cannot decrypt):
   ```bash
   mkdir -p /opt/km-demo
   mysqldump --single-transaction kobomanager > /opt/km-demo/seed.sql
   ```
   (Equivalent: export the database from phpMyAdmin or any client connected to it and
   place the file at that path.) Keep it **outside the web root**, readable only by the
   cron user — and keep an off-server copy of `seed.sql` + `config.php` (hours of setup
   live in that pair; the `CONFIG_TOKEN_KEY` in the config is the only thing that can
   decrypt the Kobo token stored in the dump).
2. Add a cron aligned with `DEMO_RESET_MINUTES` (schedule the §7 sync crons at other
   minutes — e.g. reset at `0`, submissions sync at `15,30,45` — so a sync never runs
   mid-restore):
   ```cron
   0 * * * *  mysql kobomanager < /opt/km-demo/seed.sql >/dev/null 2>&1
   ```
   The dump restores users, permissions, reviews, share links, settings and the
   submissions cache. The encrypted Kobo token lives in the DB, so it is restored as-is
   (the server's `CONFIG_TOKEN_KEY` does not change). `DEMO_MODE` itself lives in
   `config.php`, not in the DB — the reset never touches it.

### Demo hardening notes

- Use a **dedicated, disposable KoboToolbox account** with 100 % synthetic data — never
  real (not even anonymized) data. Its token is low-value, and demo mode hides it anyway.
- Leave `RESEND_API_KEY` empty: the mailer is a no-op and the demo sends no email.
- The built-in rate limits (login, contact form, share links) stay active.
- `robots.txt`: if the demo runs alongside a separate project website, consider
  `Disallow:` (or a `noindex` meta) so the demo does not compete with it in search
  results. If the demo **is** your public site (its landing page doubles as the project
  homepage), leave it indexable — everything beyond the landing requires login and is
  not crawlable anyway.
- If the server has **phpMyAdmin** (or any DB admin panel), remember it is a second,
  independent door to the same database: bots scan every domain for `/phpmyadmin`-like
  URLs around the clock, and whoever gets in talks straight to MySQL — full read/write
  on the demo DB, bypassing the app and `DEMO_MODE` entirely. Either restrict who can
  reach it (an IP allowlist — `Require ip <your-ip>` in its Apache config — or HTTP
  basic auth in front of its login), or simply **remove it once setup is done**
  (`apt remove phpmyadmin`); reinstalling it the day you need it takes minutes, and
  what is not there cannot be attacked.
- The audit viewer (Dashboard → Audit) is a handy way to watch what visitors try.
- If the demo gets abused, shorten the reset cycle (15–30 min) — the welcome dialog
  follows `DEMO_RESET_MINUTES` automatically.
