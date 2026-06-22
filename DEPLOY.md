# Deployment — KoboManager

Deploy to a VPS or shared hosting with **Apache + PHP 8.1+ + MySQL/MariaDB**.

## 1. Server requirements

- PHP 8.1+ with the `sodium`, `pdo_mysql` and `curl` extensions.
- MySQL 5.7+ / MariaDB 10.4+.
- Apache with `mod_rewrite` (or Nginx with the equivalent rules — see §6).
- HTTPS (Let's Encrypt). **Required**: the session cookie is `Secure`.

## 2. Layout on the server

### Domain or subdomain (not a subfolder)

KoboManager must be served from the **root of an origin** — a domain
(`https://kobomanager.example`) or a subdomain (`https://demo.example.org`). Both use the
exact procedure in this guide; only the DNS record, the vhost
`DocumentRoot`/`server_name`, the HTTPS certificate and the values of
`APP_URL` / `CORS_ALLOWED_ORIGINS` (§5) differ.

A **subfolder** (`https://example.org/km/`) is **not supported**: the SPA build, the
router, the API client (`/api/v1/...`) and the PWA service-worker scope all assume the
site root. Neither config constant rescues it:

- `CORS_ALLOWED_ORIGINS` takes *origins* (`scheme://host[:port]`) — **no path** — so it
  literally cannot say "under `/km`".
- `APP_URL` would *form* email links like `…/km/reset-password`, but they wouldn't
  resolve (the SPA isn't built with `base=/km` and the API lives at the root). The value
  looks right while the site stays broken.

If you only have one domain, use a subdomain — also cleaner to move or retire later.

### File tree

The compiled frontend goes in the public root, the backend under `/api`:

```
/public_html            (or /var/www/html)
  index.html            ← Vue build (dist/)
  assets/               ← Vue build (dist/assets), content-hashed
  sw.js, registerSW.js, manifest.webmanifest   ← PWA
  .htaccess             ← SPA rewrite, shipped inside dist/ (Apache; §6)
  /api                  ← PHP backend (upload as-is)
    config.php          ← create on the server, NOT committed (§5)
    .htaccess
    index.php, lib/, v1/, cron/, cli/
```

> **PWA:** the build ships a service worker (`sw.js`) that precaches the app shell and
> caches API GETs so the app survives flaky connections. It must be served with
> `Cache-Control: no-cache` so updates roll out promptly — the shipped root `.htaccess`
> does this on Apache; on Nginx add the `location = /sw.js` block from §6. Everything
> under `assets/` is content-hashed and safe to cache hard.

## 3. Build and upload

Build locally:

```bash
npm install
npm run build          # generates dist/
```

Upload:

1. **Contents of `dist/`** → the public root (`index.html`, `assets/`, `sw.js`,
   manifest, and the root **`.htaccess`**). Dotfiles are easy to miss when copying —
   verify with `ls -a` (without `.htaccess`, internal routes 404 on reload; §6).
2. **`api/`** → under the public root. Skip the dev-only baggage (`vendor/`, `tests/`,
   `.phpunit.cache/`, `phpunit.xml`, `composer.json`/`composer.lock`): the runtime has
   **no** PHP dependencies. Uploading them anyway is harmless (the `api/.htaccess`
   blocks them) — just dead weight.
3. **`db/`** → only if you'll apply the schema *on the server* (§4). The app never reads
   it at runtime; deletable afterwards. Skip it if you pipe the SQL over SSH instead.

> Never upload `node_modules/`, `src/`, or your dev `api/config.php`.

## 4. Database

Create an empty database and a dedicated MySQL user with privileges only on it.

**Installer (recommended).** With `api/config.php` filled in (§5) and `db/` uploaded, one
command checks requirements, applies the schema and creates the first admin:

```bash
php api/cli/install.php
# or non-interactively:
php api/cli/install.php --admin admin@yourdomain.com 'StrongPassword' 'Admin Name'
```

Re-running is safe (an installed schema is left untouched). If you **already applied the
schema by hand** (below), the installer detects it and just creates the admin — `db/`
need not even be present. `--clean` removes the no-longer-needed `db/` afterwards.

**Manual schema.** `db/*.sql` is the complete schema, applied **once, in filename
order**, to an empty database (no incremental migrations). On the server:

```bash
mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in db/*.sql; do mysql kobomanager < "$f"; done
```

…or from your machine over SSH, without uploading `db/`:

```bash
ssh user@server 'mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
for f in db/*.sql; do ssh user@server mysql kobomanager < "$f"; done
```

Then create the first admin with the installer (it'll skip the schema) or directly:

```bash
php api/cli/create_user.php admin@yourdomain.com 'StrongPassword' 'Admin Name' admin
```

## 5. Configuration (`api/config.php`)

Copy `api/config.example.php` to `api/config.php` and set it for **production**:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kobomanager');
define('DB_USER', 'kobomanager');
define('DB_PASS', '••••••');

// Generate once. Rotating CONFIG_TOKEN_KEY is supported (§12); changing JWT_SECRET
// logs everyone out.
define('CONFIG_TOKEN_KEY',     '<64 hex>'); // php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
define('CONFIG_TOKEN_KEY_NEW', '');         // only during key rotation (§12); empty otherwise
define('JWT_SECRET',           '<64 hex>'); // php -r 'echo bin2hex(random_bytes(32));'

// Sliding session: renews on activity (idle TTL) up to an absolute cap, then re-login.
define('JWT_TTL',               8 * 60 * 60);      // max idle time
define('SESSION_ABSOLUTE_TTL',  7 * 24 * 60 * 60); // max lifetime since login
define('SESSION_REFRESH_THRESHOLD', JWT_TTL / 2);  // renew the cookie below this

define('COOKIE_SECURE', true);          // ← true in production (HTTPS)
define('APP_ENV', 'prod');              // hides error details

// APP_URL is used ONLY for absolute links emailed out of the app (password reset, daily
// summary). A wrong value doesn't break browsing — it breaks those links.
define('APP_URL', 'https://yourdomain.com');

// CORS_ALLOWED_ORIGINS: your public HTTPS origin(s). See the CSRF/CORS note below.
define('CORS_ALLOWED_ORIGINS', ['https://yourdomain.com']);

define('RESEND_API_KEY', 're_••••••');  // email (§8); leave '' to disable
define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>');

define('APP_TIMEZONE', 'America/Havana');  // stats hour/weekday in local time (IANA; default 'UTC')
define('APP_TIMEZONE_LABEL', 'La Habana'); // UI label; '' falls back to the IANA id

// Optional — public demo (DEMO_MODE, DEMO_RESET_MINUTES, DEMO_LOGIN_ADMIN/VIEWER): see DEMO.md.
```

> **Keep `CONFIG_TOKEN_KEY` safe.** If it's lost or changed, the encrypted Kobo tokens
> can no longer be decrypted.

> **CSRF / CORS.** State-changing requests (POST/PUT/DELETE) are rejected unless their
> `Origin`/`Referer` matches an allowed origin — `CORS_ALLOWED_ORIGINS` plus the server's
> own host. In a single-domain install (frontend and `/api` on the same origin) the
> browser never does CORS and this works out of the box; the list still matters as part
> of the CSRF check, so set it to your **public HTTPS origin**. Served from several
> hostnames (e.g. with and without `www`)? Add each one.

## 6. SPA rewrite (`.htaccess`) and Nginx

The build **ships** the root `.htaccess` inside `dist/` (source: `public/.htaccess`), so
on Apache there's nothing to create — just confirm the dotfile got copied (§3). Without
it the landing loads but reloading any internal route (e.g. `/dashboard`) 404s, and SW
updates lag. For reference, in case you edit it:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteRule ^api/ - [L]                  # leave /api alone (own .htaccess)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]             # otherwise serve the SPA
</IfModule>

<IfModule mod_headers.c>
  <Files "sw.js">
    Header set Cache-Control "no-cache"    # SW must revalidate; assets/ are hashed
  </Files>
</IfModule>
```

The `/api/.htaccess` ships with the repo (routes through `index.php`, blocks `config.php`
and the internal `lib/`, `cron/`, `cli/`, `tests/`, `vendor/`).

### Nginx (no `.htaccess`)

`.htaccess` is Apache-only. On Nginx replicate the same two protections — SPA fallback to
`index.html`, every `/api/v1/...` through the front controller, and **block direct access
to internal code and config**:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location = /sw.js { add_header Cache-Control "no-cache"; }   # PWA: revalidate promptly

location /api/ {
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

> Without the `deny` blocks, Nginx serves `api/lib/*.php` and `api/config.php` as plain
> text. Verify after deploy: `curl https://yourdomain.com/api/config.php` must **not**
> return the file (expect 403/404), and `…/api/lib/DB.php` likewise.

## 7. Cron jobs

```cron
*/15 * * * *  php /path/api/cron/sync_submissions.php   # Kobo submissions → cache
0    7 * * *  php /path/api/cron/daily_summary.php       # daily email summary
```

Both run only from the CLI (they reject web requests). The daily summary needs
`RESEND_API_KEY` and `MAIL_FROM` (§8).

## 8. Email (Resend)

KoboManager sends email through the **Resend HTTP API** (`api/lib/Mailer.php`) — no SDK,
no SMTP server/port. Email powers:

- the **daily summary** of new submissions (cron, §7),
- **password recovery** (when enabled), and
- the **contact form** on the public `/apoyar` page (sends to `CONTACT_TO`; messages are
  also stored in `contact_messages`, so nothing is lost if delivery fails — admins read
  them at `/admin/messages`).

Setup:

1. Create an account at [resend.com](https://resend.com) and generate an **API key**.
2. **Verify your sending domain** (DNS records); until then delivery is limited/blocked.
3. In `api/config.php`:
   ```php
   define('RESEND_API_KEY', 're_••••••');
   define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>'); // at the verified domain
   define('CONTACT_TO', 'contact@yourdomain.com');              // inbox for /apoyar
   ```

If `RESEND_API_KEY` is empty, sending is **skipped gracefully** (logged, no error) — the
app keeps working but email-dependent features don't send. Handy for staging.

> The secret lives only in `config.php` — never in the database or exposed to the frontend.

## 9. Post-deployment checks

- `https://yourdomain.com/api/v1/health` → `status: ok` (php, sodium, pdo_mysql, database).
- Sign in with your admin; the cookie must travel as `Secure` + `HttpOnly`.
- Add a real Kobo account and click **Sync** under *Forms*.
- Run `sync_submissions.php` manually once and check `submissions_cache`.

## 10. Subsequent updates

1. `npm run build` locally.
2. Replace the **contents of `dist/`** (`index.html`, `assets/`, `sw.js`, manifest, and
   the root `.htaccess` if it changed) and the `api/` folder — **without touching
   `config.php`**.
3. Schema changes (only if the release notes mention them) ship as **edits to the
   canonical `CREATE TABLE`s** in `db/001_schema.sql`; re-applying does nothing
   (`CREATE TABLE IF NOT EXISTS`). Apply the change by hand per the changelog, or recreate
   the database from `db/*.sql` and re-sync from Kobo.
4. PWA: the **first** load after an update still serves the previous version from the SW
   precache (the new one activates in the background) — reload once before judging.

## 11. Backups

Two things need backing up; **everything else is in git** and rebuilds with
`npm run build` + re-uploading `api/`.

1. **Database** (`kobomanager`) — users, permissions, Kobo accounts (encrypted tokens),
   submissions cache, settings, audit log, share links.
2. **`api/config.php`** — the only secret outside git: `CONFIG_TOKEN_KEY` (without it the
   encrypted tokens are unrecoverable), `JWT_SECRET`, the DB password, the Resend key.
   Keep a copy separate from the DB dump.

No uploaded files on disk: attachments are streamed from Kobo on demand, never stored
locally.

**Nightly dump (cron), 14-day retention:**

```cron
30 3 * * *  mysqldump --single-transaction --quick kobomanager | gzip > /var/backups/km/km-$(date +\%F).sql.gz && find /var/backups/km -name 'km-*.sql.gz' -mtime +14 -delete
```

(Put the password in `~/.my.cnf` (a `[mysqldump]` block), not on the command line.
`--single-transaction` gives a consistent dump without locking InnoDB.)

**Restore:**

```bash
gunzip < km-2026-01-31.sql.gz | mysql kobomanager
# then put back api/config.php (same CONFIG_TOKEN_KEY as when the tokens were encrypted)
```

> Test a restore into a scratch database now and then — a backup you've never restored is
> a guess, not a backup.

## 12. Rotating `CONFIG_TOKEN_KEY`

`CONFIG_TOKEN_KEY` encrypts the Kobo API tokens (`kobo_accounts.api_token`, libSodium).
The CLI rotates it without losing the tokens, re-encrypting every account from the **old**
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
5. Rotate for real (transactional; rolls back on any error):
   ```bash
   php api/cli/rotate_token_key.php
   ```
6. **Promote** the new key: set `CONFIG_TOKEN_KEY` to the new key and `CONFIG_TOKEN_KEY_NEW`
   back to `''`.
7. Verify: open *Kobo accounts* and **Sync** (forces a decrypt with the new key), or check
   `/api/v1/health`.

**Rollback.** If something looks wrong *before* step 6 (config still on the old key), the
DB is already on the new key — finish step 6 to match them. To go back to the old key,
swap the two keys (`CONFIG_TOKEN_KEY` = new, `CONFIG_TOKEN_KEY_NEW` = old), run the CLI
again, then promote the old key. Last resort: restore the DB + `config.php` from step 1.

## 13. Running a demo instance

KoboManager ships a built-in **demo mode** for running a public sandbox — a throwaway
instance where visitors log in with shared credentials and click around real features
without breaking anything or reading your secrets.

It's a specialized deployment with its own runbook (what the flag blocks, setup order,
seeding synthetic submissions, the reset cron, hardening). See **[DEMO.md](DEMO.md)**.
