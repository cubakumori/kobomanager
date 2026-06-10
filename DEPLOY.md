# Deployment — KoboManager

Guide for deploying to a VPS or hosting with **Apache + PHP 8.1+ + MySQL/MariaDB**.

## 1. Server requirements

- PHP 8.1 or higher with the `sodium`, `pdo_mysql` and `curl` extensions.
- MySQL 5.7+ / MariaDB 10.4+.
- Apache with `mod_rewrite` enabled (or Nginx with equivalent rules).
- HTTPS (Let's Encrypt). **Required**: the session cookie uses `Secure`.

## 2. Layout on the server

The compiled frontend lives in the public root and the backend in `/api`:

```
/public_html            (or /var/www/html)
  index.html            ← Vue build (dist/)
  assets/               ← Vue build (dist/assets)
  sw.js, registerSW.js, manifest.webmanifest   ← PWA (service worker + manifest)
  .htaccess             ← SPA rewrite (see §6)
  /api                  ← PHP backend (upload as-is)
    config.php          ← create on the server, NOT committed
    .htaccess
    index.php, lib/, v1/, cron/, cli/
```

> **PWA note**: the build ships a service worker (`sw.js`) that precaches the app shell and
> caches API GETs so the app stays usable on flaky connections. Serve `sw.js` with
> `Cache-Control: no-cache` (or a short max-age) so updates roll out promptly — with Apache,
> e.g. `<Files "sw.js"> Header set Cache-Control "no-cache" </Files>`; with nginx, a
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
2. The whole `api/` folder → under the public root (`/api`).
3. The `db/` folder (migrations) if you'll apply them from the server.

> Do not upload `node_modules/`, `src/`, or your development `api/config.php`.

## 4. Database

```bash
mysql -e "CREATE DATABASE kobomanager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in db/*.sql; do mysql kobomanager < "$f"; done
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
define('APP_URL', 'https://yourdomain.com');
define('CORS_ALLOWED_ORIGINS', ['https://yourdomain.com']);

define('RESEND_API_KEY', 're_••••••');  // email (see §8); leave '' to disable
define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>');

define('APP_TIMEZONE', 'America/Havana'); // stats hour/weekday in local time (IANA; default 'UTC')
define('APP_TIMEZONE_LABEL', 'La Habana'); // human label for the UI; '' falls back to the IANA id
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

Serve the API and, for everything else, return `index.html` (SPA routes):

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
2. Replace `index.html` + `assets/` in the root and the `api/` folder (without touching `config.php`).
3. Apply any new `db/` files. Note that `db/*.sql` uses `CREATE TABLE IF NOT EXISTS`, so on an
   **existing** database column/index changes are not picked up by re‑applying them — pre‑1.0 the
   intended path is to recreate the database from `db/*.sql` and re‑sync, or apply the schema
   change by hand. For the M4a search column specifically, after adding
   `submissions_cache.search_text` + its `FULLTEXT` index, backfill cached rows once with
   `php api/cli/rebuild_search_text.php` (new submissions fill it automatically on sync).
```

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
