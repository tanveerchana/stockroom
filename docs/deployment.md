# Deployment notes

The checked-in Docker image serves Core PHP through Apache. The public document root contains only the front controller, stylesheet and readiness endpoint. SQLite, sessions, source, templates and environment files are outside the public root.

Environment variables:

| Variable       | Production default                       | Purpose                                                                     |
| -------------- | ---------------------------------------- | --------------------------------------------------------------------------- |
| `APP_ENV`      | `production`                             | Secure `__Host-` cookies; only explicit `local` permits local HTTP cookies. |
| `PORT`         | `10000`                                  | Apache listener, bound on all container interfaces.                         |
| `DB_PATH`      | `/var/www/html/storage/stockroom.sqlite` | SQLite database file; keep outside `public/`.                               |
| `SESSION_PATH` | `/var/www/html/storage/sessions`         | Server-side file sessions; keep outside `public/`.                          |

The entrypoint initializes the version-1 schema, sets the database/session directory ownership for Apache's `www-data` worker and starts Apache in the foreground. There are no shared demo passwords, default account credentials, secrets to seed or third-party API calls. A first-run user creates their own account or opens a separate expiring guest demo.

The free Render blueprint intentionally has no persistent disk or paid database. SQLite and sessions are ephemeral, as stated in the UI. A durable single-instance deployment can use a paid mounted disk; both paths must point into that mount. Do not scale this file-backed setup into separate replicas. Future schema upgrades need an explicit migration from `PRAGMA user_version=1`; the code refuses unknown schema versions.

Hosted smoke checks: register two independent owners, confirm catalogs are isolated, add an item, receive ten units, reserve seven, reject a second seven-unit reservation, fulfil the first, inspect 3 on hand / 0 reserved and the audit deltas. Invite a viewer; confirm read/export succeeds and mutation is denied even with a manually submitted request. Sign out and verify prior session access is lost. Check Secure/HttpOnly/SameSite cookie flags over HTTPS and `/health.php` status. Refresh the page on a narrow viewport and inspect table scrolling and form alignment.

Official references checked during implementation:

- [Render free-service filesystem limits](https://render.com/docs/free)
- [Render Docker web services](https://render.com/docs/web-services)
- [Official PHP Apache image](https://hub.docker.com/_/php)
- [PHP PDO transactions](https://www.php.net/manual/en/pdo.transactions.php)
- [PHP session security](https://www.php.net/manual/en/session.security.php)
- [PHP password hashing](https://www.php.net/manual/en/function.password-hash.php)

## Ephemeral demo mode

Set `STOCKROOM_EPHEMERAL_HOSTING=true` for the free Render deployment. It adds a visible notice on sign-in/registration/invitation and workspace pages that inventory, accounts and sessions can reset on restart, redeploy or idle spin-down, and that only synthetic data is permitted. The literal string `true` enables the flag; missing/false values leave it disabled. The flag changes disclosure only: it does not purge or reinitialize existing data. Regular self-hosted installations retain their existing database/session behavior. Data durability still depends on the actual host filesystem or mounted volume.

Exact Docker start configuration: `APP_ENV=production`, `PORT=10000`, `STOCKROOM_EPHEMERAL_HOSTING=true`, `DB_PATH=/var/www/html/storage/stockroom.sqlite`, `SESSION_PATH=/var/www/html/storage/sessions`. The Dockerfile already provides all except the Render-specific true flag. There is no app secret, shared password, SMTP key or third-party API credential to generate; native PHP sessions and CSRF/invitation/request tokens use cryptographically secure random values.
