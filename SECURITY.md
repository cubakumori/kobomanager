# Security Policy

KoboManager sits between KoboToolbox accounts and end users, and it handles
**personal data** from survey submissions. We take security reports seriously
and appreciate responsible disclosure.

## Supported versions

KoboManager is released from `main` and there is no long-term support for older
tags. Security fixes land on `main` and ship in the next release. Always run the
**latest release** before reporting — the issue may already be fixed.

| Version            | Supported          |
| ------------------ | ------------------ |
| Latest release     | :white_check_mark: |
| Older tags         | :x:                |

## Reporting a vulnerability

**Please do not open a public issue, pull request, or forum post for security
problems.** Disclose privately first so a fix can ship before the details are
public.

Two private channels, in order of preference:

1. **GitHub private vulnerability reporting** (preferred): on the repository go to
   **Security → Report a vulnerability**
   (<https://github.com/cubakumori/kobomanager/security/advisories/new>). This keeps
   the report private and lets us collaborate on a fix and a coordinated advisory.
2. **Email** (backup): `security@kobomanager.org`. If you want to send encrypted
   details, say so in a first contactless message and we'll arrange a key.

Please include enough to reproduce:

- Affected version / commit and your deployment (PHP version, MySQL or MariaDB,
  Apache or Nginx, whether `DEMO_MODE` is on).
- A clear description of the issue and its impact.
- Step-by-step reproduction (requests, payloads, screenshots) and, if you have it,
  a proof of concept.

### What to expect

- **Acknowledgement** within **5 business days**.
- An initial assessment (severity, whether we can reproduce) within **10 business
  days**.
- We'll keep you updated on the fix and agree on a disclosure date. We aim to
  resolve high-severity issues promptly; please allow up to **90 days** before any
  public disclosure so a fix and an advisory can be published.
- With your consent, we'll **credit you** in the advisory and changelog. This is a
  volunteer open-source project: we don't run a paid bug-bounty program.

## Scope

In scope — the code in this repository, especially:

- The **public, unauthenticated** surface: `api/v1/public/` (read-only share links
  and the contact form).
- **Authentication and sessions** (JWT, the sliding/absolute session lifecycle,
  CSRF protection).
- **Access control**: per-form permissions, row-level scoping (`RowScope`),
  column-level field hiding (`FieldScope`), and share-link scoping — including any
  way to read rows, fields, or attachments beyond what a link or user is granted.
- **Secret handling**: the encrypted KoboToolbox API token must never reach the
  browser; SSRF or token leakage in the attachment proxy.
- **Injection** (SQL, CSV formula injection in exports, XSS), and **`DEMO_MODE`
  denylist** bypasses.

Out of scope:

- Vulnerabilities in **KoboToolbox** itself or other third-party services — report
  those to the respective project.
- Issues that require a pre-existing **administrator** account (admins are trusted
  and bypass scoping by design).
- Findings only reproducible with an **insecure deployment** that contradicts
  [`DEPLOY.md`](./DEPLOY.md) (e.g. `config.php` served as plain text, `COOKIE_SECURE`
  off over HTTPS, missing the documented security headers).
- Best-practice/header reports with no demonstrated impact, and automated-scanner
  output without a working proof of concept.

## Deploying securely

If you self-host, follow [`DEPLOY.md`](./DEPLOY.md): serve over HTTPS with
`COOKIE_SECURE = true`, keep `config.php` and the internal `api/lib|cron|cli|tests|
vendor` directories unreachable from the web, keep the shipped security headers
(CSP, `nosniff`, `X-Frame-Options`, HSTS), and generate your own
`CONFIG_TOKEN_KEY` / `JWT_SECRET`. See [`ARCHITECTURE.md`](./ARCHITECTURE.md) for the
security model.
