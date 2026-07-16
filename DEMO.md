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
define('DEMO_RESET_MINUTES', 60);   // reset cycle: drives the reset cron AND the welcome dialog
// Credentials shown in the dialog, per role ('' hides that line). The app adds the
// role label translated to the visitor's language.
define('DEMO_LOGIN_ADMIN', 'admin@demo.org / demo1234');
define('DEMO_LOGIN_VIEWER', 'viewer@demo.org / demo1234');
// Where the app writes/reads the demo seed (outside the web root).
define('DEMO_SEED_PATH', '/opt/km-demo/seed.sql');
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
- **Manual sync against Kobo** ("Update"/"Resync"/account discovery, and the
  login-triggered background pass `POST /forms/sync-stale`) — saves the demo
  account's API quota.
- **Contact inbox management** (`PUT`/`DELETE /admin/messages/:id`) — the public demo
  can receive *real* messages from interested visitors via the support page; an
  anonymous visitor must not be able to archive or delete them before the operator
  reads them. (The reset never deletes them either — see *Periodic reset*.)
- **Generating the demo seed** (`POST /admin/demo/seed`) — it belongs to the
  maintenance loop with the flag *off*; enabled, it would snapshot whatever mess
  visitors have made.

Everything else stays enabled on purpose — it is what the demo is for: browsing, search
and filters, single and batch review, the quality-control page (including its batch
"put on hold" button — the review it rides is local in demo, Kobo is never touched) and
its **drill-down export** (CSV/xlsx), the **risk index**, the **review-comments panel**,
CSV export, statistics, the map, creating and revoking share links, language and theme…
All of it is local and restored by the reset.

---

## Setup order (the flag goes last)

The demo locks apply to **everyone, admins included** — there is no "owner bypass". So
prepare the instance with `DEMO_MODE` **off** (or the constants absent), because setup
needs exactly the actions the demo blocks (connecting the Kobo account, creating users,
changing settings, manual sync):

1. Install normally (§§1–10) with `DEMO_MODE = false` and set `DEMO_SEED_PATH`.
2. Connect the disposable Kobo account, discover the forms (sync), create the demo
   users, permissions, an example share link, settings — leave everything the way
   visitors should find it.
3. Seed synthetic submissions (next section).
4. Generate the seed from **Settings → Database → Generate demo seed** (one click),
   add the reset cron (see *Periodic reset*), set `DEMO_MODE = true`.
5. Only then publish the URL.

To adjust something later, do the same loop: flip the flag off (over SSH), change what
you need in the app, click **Generate demo seed** again, flip it back on.

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
# php api/cli/seed_demo.php <form_id> <count> [--days N] [--reviews PCT] [--comments PCT] [--risk N] [--clear]
php api/cli/seed_demo.php 1 40 --days 90 --reviews 40 --comments 50 --risk 5
php api/cli/seed_demo.php 2 30 --days 60                        # a second form
```

- `--days N` spreads submissions across the last N days (default 60).
- `--reviews PCT` marks that percentage as reviewed (approved/on-hold/rejected) so the
  review badges and the "review status" chart are not empty (default 35; `0` = none).
- `--comments PCT` gives that percentage of the reviews a status-appropriate example
  comment (default 50; `0` = none), so the **review-comments panel** is not empty.
- `--risk N` turns on the **risk index** for the form by setting `forms.risk_min_n = N`
  (omit = leave as is). For a meaningful per-enumerator breakdown the form also needs
  `stats_enumerator_field` set (form settings) to a question with a handful of values.
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
- **Showcasing risk and comments.** Use `--comments PCT` (populates the review-comments
  panel) and `--risk N` (turns on the risk index) so those two landing-advertised pages
  aren't empty. Two things the seeder can't infer, so set them in **form settings** before
  generating the seed: `stats_enumerator_field` (so risk / QC / comments group by a real
  enumerator instead of a single "—" bucket — seeded rows have no `_submitted_by`) and the
  **`qc_*` thresholds** (so the quality-control page flags something). The *Suggest* button
  there reports the median submissions per enumerator to pick `risk_min_n`.

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
they see everything an admin sees — starting with the user list (names and emails).
Therefore the demo database must contain **only** the published demo users: make the
demo admin itself (`admin@demo.org`) the first user you create, and do the whole setup
logged in as that account — don't create a personal admin on this instance (if you
already did, delete it before generating the seed, with `DEMO_MODE` still off).

Your setup *trail*, on the other hand, needs no manual cleanup: the seed export
**never includes** the private tables (sessions, login attempts, rate-limit hits,
password-reset tokens, the audit trail, contact messages), so your IPs and browser
fingerprints cannot end up in the seed even if you forget about them. The reset
empties the visitor-generated trail each cycle (see next section).

---

## Periodic reset

Both halves are managed by the app — no `mysqldump`, no SQL by hand.

1. **Generate the seed** with the demo ready and the flag still off: **Settings →
   Database → Generate demo seed** (the tab appears once `DEMO_SEED_PATH` is set). The app
   writes a data-only SQL snapshot of the instance (accounts, users, forms, submissions
   cache, reviews, permissions, share links, settings) to `DEMO_SEED_PATH` — atomically,
   so the cron can never read a half-written file. Keep the path **outside the web
   root**, and keep an off-server copy of `seed.sql` + `config.php` (hours of setup live
   in that pair; the `CONFIG_TOKEN_KEY` in the config is the only thing that can decrypt
   the Kobo token stored in the seed).

   **Preparing the seed on another machine.** The seed is portable with ONE condition:
   the Kobo token inside it is encrypted with the `CONFIG_TOKEN_KEY` of the instance
   that created it, so the demo server must use the **same** `CONFIG_TOKEN_KEY`. With
   that in place you can build the whole demo comfortably on your dev machine (Kobo
   account, sync, users, permissions, seeding), click *Generate demo seed* there and
   upload the file to the server's `DEMO_SEED_PATH`. The app generates plain,
   portable SQL — the MariaDB→MySQL `mysqldump` incompatibilities (sandbox line) are
   gone, and in an emergency the file can still be imported by hand with
   `mysql`/phpMyAdmin.

2. **Add the reset cron** — every minute; the script paces itself:
   ```cron
   * * * * *  php /var/www/kobomanager/api/cron/demo_reset.php >/dev/null 2>&1
   ```
   `DEMO_RESET_MINUTES` now *drives* the cycle: the script records the last reset and
   only restores when the cycle has elapsed, so the welcome dialog and the actual reset
   can never disagree, and there is no crontab syntax to get wrong. To change the
   cadence, edit the constant — the cron follows. `php api/cron/demo_reset.php --force`
   restores immediately (useful for a first run or after abuse).

   What a reset does — in **one transaction**, so visitors browsing at that moment see
   the previous state until the commit and a mid-restore failure rolls back (the demo is
   never left half-empty):

   - restores the seeded tables (users, permissions, reviews, share links, settings and
     the submissions cache — the encrypted Kobo token is restored as-is; the server's
     `CONFIG_TOKEN_KEY` does not change);
   - empties the visitor trail (audit log, login attempts, rate-limit hits,
     password-reset tokens);
   - **preserves live sessions** (visitors are *not* logged out mid-click; the data
     under them just resets) and **preserves contact messages** (real messages from
     interested visitors survive every cycle until you read them — clearing that inbox
     is part of the maintenance loop, with the flag off).

   `DEMO_MODE` itself lives in `config.php`, not in the DB — the reset never touches
   it. As a safety net the cron also **refuses to run when `DEMO_MODE` is off** (a
   forgotten crontab can no longer overwrite a real instance), and each run is recorded
   for `/api/v1/health`, so a failing reset is visible there.

> ⚠️ **Do not add a submissions-sync cron (§7) to a seeded demo.** Seeded submissions
> exist only in the local cache, not in Kobo. A sync reconciles the cache against the
> Kobo account and would **delete** every seeded row (their `_id`/`_uuid` are not in
> Kobo), leaving the demo empty between resets. A seeded demo's data is a frozen snapshot:
> the **reset** cron is the only one it needs. (Only run a sync cron if you chose the
> other route — real submissions living in the disposable Kobo account. Running both is
> safe: the reset is one transaction, and if it collides with a sync mid-write it simply
> retries and, at worst, waits for the next minute's pass.)

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
- Keep `DEMO_SEED_PATH` outside the web root, readable/writable only by the PHP and
  cron user (it contains password hashes and the encrypted Kobo token).
- If the demo gets abused, shorten `DEMO_RESET_MINUTES` (15–30 min) — the reset cron
  and the welcome dialog both follow it automatically. `php api/cron/demo_reset.php
  --force` resets right now.
