# Running a demo instance — KoboManager

KoboManager ships with a built-in **demo mode** so you can run a public sandbox of your
own: a throwaway instance where visitors log in with shared credentials and click around
real features without being able to break it or read your secrets.

This is a specialized deployment. Do a normal install first (see [DEPLOY.md](DEPLOY.md)
§§1–10); this document only covers what is specific to a demo: what the flag blocks, the
setup order, seeding synthetic submissions, the periodic reset, and hardening.

---

## What `DEMO_MODE` does

In `api/config.php` (all the constants are optional — a config without them behaves as
demo off):

```php
// --- Public demo ---
define('DEMO_MODE', true);          // demo notice + sensitive actions blocked
define('DEMO_RESET_MINUTES', 60);   // informative: shown in the welcome dialog
// Credentials shown in the dialog, per role ('' hides that line). The app adds the
// role label translated to the visitor's language.
define('DEMO_LOGIN_ADMIN', 'admin@demo.org / demo1234');
define('DEMO_LOGIN_VIEWER', 'viewer@demo.org / demo1234');
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
  account's API quota.

Everything else stays enabled on purpose — it is what the demo is for: browsing, search
and filters, single and batch review, CSV export, statistics, the map, creating and
revoking share links, language and theme… All of it is local and restored by the reset.

---

## Setup order (the flag goes last)

The demo locks apply to **everyone, admins included** — there is no "owner bypass". So
prepare the instance with `DEMO_MODE` **off** (or the constants absent), because setup
needs exactly the actions the demo blocks (connecting the Kobo account, creating users,
changing settings, manual sync):

1. Install normally (§§1–10) with `DEMO_MODE = false`.
2. Connect the disposable Kobo account, discover the forms (sync), create the demo
   users, permissions, an example share link, settings — leave everything the way
   visitors should find it.
3. Seed synthetic submissions (next section).
4. Run the privacy cleanup, take the seed dump, add the reset cron, set `DEMO_MODE = true`.
5. Only then publish the URL.

To adjust something later, do the same loop over SSH: flip the flag off, change what
you need, regenerate the seed dump, flip it back on.

---

## Seeding synthetic submissions

A demo needs data, and that data must be **100 % synthetic** — never real submissions,
not even anonymized (a public demo logs visitors in as admin, so they see everything).
KoboToolbox has no "export from form A, import into form B", and neither does
KoboManager: the app only *reads*, *edits* and *reviews* existing submissions, it never
creates them.

To populate the demo, use the operator CLI **`php api/cli/seed_demo.php`**. It reads the
form's cached schema (`forms.schema_json`) and writes fake submissions **directly into
the local cache** (`submissions_cache`) — it does **not** post anything to KoboToolbox.
That choice is deliberate:

- it controls the dates, so submissions spread across past weeks and the per-day/month/
  hour charts and 7/30-day trends look alive (impossible if you posted to Kobo, which
  stamps `_submission_time` with the moment of receipt — everything would land "today");
- it touches neither the Kobo account nor its API quota;
- it uses the exact payload shape, `submitted_at` (UTC-anchored) and `search_text` that
  the real sync produces, so the app cannot tell the difference.

```bash
# php api/cli/seed_demo.php <form_id> <count> [--days N] [--reviews PCT] [--clear]
php api/cli/seed_demo.php 1 40 --days 90 --reviews 40
php api/cli/seed_demo.php 2 30 --days 60            # a second form
```

- `--days N` spreads submissions across the last N days (default 60).
- `--reviews PCT` marks that percentage as reviewed (approved/on-hold/rejected) so the
  review badges and the "review status" chart are not empty (default 35; `0` = none).
- `--clear` removes previously seeded rows for that form before inserting. Seeded rows
  carry a `_km_seed: true` marker in their payload, so `--clear` never touches genuine
  submissions you may have entered through Enketo.

For each field the seeder picks valid values from the schema (real `select_one`/
`select_multiple` options, geopoints scattered across the country's bounding box,
numbers/text, a fraction left blank so the "empty / not empty" filters have data).

**Limitations, by design:**

- **No attachments.** Seeded submissions reference no media files (they would not exist
  in Kobo, and the attachment proxy would 404). If you want the demo to show photos or
  audio, send a handful of real submissions through Enketo to the disposable account and
  sync them once — those carry genuine attachments.
- **Combinations are not cross-validated.** Cascading choices (e.g. municipality
  depending on province) are filled independently, so a pair may be geographically
  inconsistent. Irrelevant for synthetic demo data.
- **These rows do not exist in Kobo.** This is why a seeded demo must **not** run a sync
  cron — see the warning under *Periodic reset*.

---

## Demo users and privacy

Create the account(s) you publish in `DEMO_LOGIN_ADMIN`/`DEMO_LOGIN_VIEWER` **before**
enabling the demo — those constants are plain text, they do not create anything. Note
that the app validates emails with PHP's `FILTER_VALIDATE_EMAIL`, which requires a dotted
domain: an address like `admin@demo` cannot be created from the admin UI (use
`admin@demo.org` instead, or create it with `php api/cli/create_user.php`, which skips
that validation). A nice touch is a second, viewer-role user with a **multi-condition row
filter** and **hidden columns** configured, so visitors can log in with each one and
compare the access control — advertise it via `DEMO_LOGIN_VIEWER`.

> Note: read-only columns barely show in a demo. Demo mode disables the *Edit* button, so
> the viewer never enters edit mode and the 🔒 read-only marker is never seen. Hidden
> columns, on the other hand, are visible (the column is simply absent), so they make the
> better column-permission showcase.

**Keep your real identity out of the seed.** Demo visitors sign in as an *admin*, so
they see everything an admin sees: the user list (names and emails), the audit trail and
per-user session info (IP, browser). Therefore the demo database must contain **only**
the published demo users:

- Make the demo admin itself (`admin@demo.org`) the first user you create, and do the
  whole setup logged in as that account — don't create a personal admin on this instance
  (if you already did, delete it before the seed, with `DEMO_MODE` still off).
- Just before taking the seed dump, empty the tables that carry your setup trail and
  connection metadata (the demo refills them as visitors use it):
  ```sql
  TRUNCATE user_sessions; TRUNCATE login_attempts; TRUNCATE rate_hits;
  TRUNCATE password_resets; TRUNCATE audit_log; TRUNCATE contact_messages;
  ```
  (Truncating `user_sessions` logs everyone out, you included — sign in again after the
  dump. Skip `contact_messages` if you seeded an example message on purpose.)

---

## Periodic reset

1. With the demo ready, take a seed dump of the demo database:
   ```bash
   mkdir -p /opt/km-demo
   mysqldump --single-transaction kobomanager > /opt/km-demo/seed.sql
   ```
   (Equivalent: export the database from phpMyAdmin or any client connected to it and
   place the file at that path.) Keep it **outside the web root**, readable only by the
   cron user — and keep an off-server copy of `seed.sql` + `config.php` (hours of setup
   live in that pair; the `CONFIG_TOKEN_KEY` in the config is the only thing that can
   decrypt the Kobo token stored in the dump).

   **Preparing the seed on another machine.** The dump is portable with ONE condition:
   the Kobo token inside it is encrypted with the `CONFIG_TOKEN_KEY` of the instance that
   created it, so the demo server must use the **same** `CONFIG_TOKEN_KEY`. With that in
   place you can build the whole demo comfortably on your dev machine (Kobo account, sync,
   users, permissions, seeding, the privacy cleanup above), dump it there, upload
   `seed.sql` and load it once on the server (`mysql kobomanager < seed.sql`). Caveat when
   dumping from **MariaDB** for a **MySQL** server: recent MariaDB `mysqldump` prepends a
   `/*!999999\- enable the sandbox mode */` line that MySQL rejects — delete that first
   line (`sed -i '1{/999999/d}' seed.sql`) before importing.

2. Add a reset cron aligned with `DEMO_RESET_MINUTES`:
   ```cron
   0 * * * *  mysql kobomanager < /opt/km-demo/seed.sql >/dev/null 2>&1
   ```
   The dump restores users, permissions, reviews, share links, settings and the
   submissions cache. The encrypted Kobo token lives in the DB, so it is restored as-is
   (the server's `CONFIG_TOKEN_KEY` does not change). `DEMO_MODE` itself lives in
   `config.php`, not in the DB — the reset never touches it.

> ⚠️ **Do not add a submissions-sync cron (§7) to a seeded demo.** Seeded submissions
> exist only in the local cache, not in Kobo. A sync reconciles the cache against the
> Kobo account and would **delete** every seeded row (their `_id`/`_uuid` are not in
> Kobo), leaving the demo empty between resets. A seeded demo's data is a frozen snapshot:
> the **reset** cron is the only one it needs. (Only run a sync cron if you chose the
> other route — real submissions living in the disposable Kobo account — in which case
> schedule it at minutes that never overlap the reset, e.g. reset at `0`, sync at
> `15,30,45`.)

---

## Demo hardening notes

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
  URLs around the clock, and whoever gets in talks straight to MySQL — full read/write on
  the demo DB, bypassing the app and `DEMO_MODE` entirely. Either restrict who can reach
  it (an IP allowlist — `Require ip <your-ip>` in its Apache config — or HTTP basic auth
  in front of its login), or simply **remove it once setup is done**
  (`apt remove phpmyadmin`); reinstalling it the day you need it takes minutes, and what
  is not there cannot be attacked.
- The audit viewer (Dashboard → Audit) is a handy way to watch what visitors try.
- If the demo gets abused, shorten the reset cycle (15–30 min) — the welcome dialog
  follows `DEMO_RESET_MINUTES` automatically.
