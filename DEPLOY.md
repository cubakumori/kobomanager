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
  .htaccess             ← SPA rewrite (see §6)
  /api                  ← PHP backend (upload as-is)
    config.php          ← create on the server, NOT committed
    .htaccess
    index.php, lib/, v1/, cron/, cli/
```

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

// Generate once and do NOT change later (would invalidate tokens/sessions):
define('CONFIG_TOKEN_KEY', '<64 hex>'); // php -r 'echo sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));'
define('JWT_SECRET',       '<64 hex>'); // php -r 'echo bin2hex(random_bytes(32));'

define('COOKIE_SECURE', true);          // ← true in production (HTTPS)
define('APP_ENV', 'prod');              // hides error details
define('APP_URL', 'https://yourdomain.com');
define('CORS_ALLOWED_ORIGINS', ['https://yourdomain.com']);

define('RESEND_API_KEY', 're_••••••');  // email (see §8); leave '' to disable
define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>');
```

> **Important:** keep `CONFIG_TOKEN_KEY` somewhere safe. If it's lost or changed, the
> encrypted Kobo tokens can no longer be decrypted.

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
`lib/`, `cron/`, `cli/` and `config.php`).

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

- the **daily summary** of new submissions (cron, §7), and
- **password recovery** (the "forgot password" flow), when enabled.

Setup:

1. Create an account at [resend.com](https://resend.com) and generate an **API key**.
2. **Verify your sending domain** in Resend (DNS records). Until the domain is verified,
   delivery is limited/blocked by Resend.
3. In `api/config.php` set:
   ```php
   define('RESEND_API_KEY', 're_••••••');
   define('MAIL_FROM', 'KoboManager <noreply@yourdomain.com>'); // address at the verified domain
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
3. Apply any new `db/` migrations.
```
