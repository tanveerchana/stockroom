# Stockroom

A tenant-scoped inventory and reservation workspace built with **Core PHP 8.3, PDO SQLite and server-rendered PHP templates** by [Tanveer Singh](https://singhtanveer.com).

Stockroom is a working engineering portfolio project. All inventory is synthetic. There is no commerce, payment processing, shipping or external warehouse integration.

## Workflows

- Create an owner account and an isolated workspace, or open a separate 24-hour guest demo with eight seeded catalog items.
- Add catalog SKUs, record inbound receipts and inspect exact on-hand, reserved and available quantities.
- Reserve available units, release a hold or fulfil it. Fulfilment reduces both on-hand and reserved stock.
- Inspect low-stock items using **available ≤ reorder level**.
- Review the append-only stock movement ledger, including actor, reference, deltas and balances after each operation. Export it as formula-safe CSV.
- Invite an operator or viewer with a single-use, 24-hour link. Change member roles or revoke access; the owner role is protected.

| Role     | Read / export | Receive / reserve / release / fulfil | Add catalog items | Manage members |
| -------- | ------------- | ------------------------------------ | ----------------- | -------------- |
| Owner    | Yes           | Yes                                  | Yes               | Yes            |
| Operator | Yes           | Yes                                  | No                | No             |
| Viewer   | Yes           | No                                   | No                | No             |

All permissions and tenant scopes are checked on the server. Disabled controls are only a UI aid. Every account belongs to one workspace; multi-workspace accounts and ownership transfer are intentionally outside this version.

## Run locally

Requirements: PHP 8.3 with `pdo_sqlite` and `mbstring`. The HTTP test additionally needs `curl`. Composer is optional at runtime: the application has **no third-party PHP packages** and includes a small namespace autoloader. `composer.lock` records the platform requirements and the empty dependency graph.

```bash
composer install --no-plugins --no-scripts
APP_ENV=local DB_PATH=storage/stockroom.sqlite php -S 127.0.0.1:5123 -t public
```

Open `http://127.0.0.1:5123`. PHP does not load `.env` files automatically; set variables explicitly. `APP_ENV=local` allows a development session cookie over local HTTP. The default production configuration requires HTTPS and uses a Secure, HttpOnly, SameSite=Lax `__Host-` session cookie.

The schema is initialized on the first connection outside the public document root. New accounts start with an empty catalog. Use **Try synthetic demo** to explore sample data. No demo credentials are published or required.

## Verify

```bash
composer validate --strict
composer --no-plugins lint
composer --no-plugins test
php tests/http.php
STOCKROOM_TEST_EPHEMERAL=true php tests/http.php
```

The native suite covers exact quantity validation, receipts, fulfilment/release balances, oversell rejection, idempotent receipt commands, tenant boundaries, role enforcement, audit immutability, capacity limits, password hashing, invitations, access revocation, session actor expiry and rate limiting. It also starts **two simultaneous PHP processes** that both attempt to reserve seven of ten units: exactly one succeeds and no partial movement is committed.

The HTTP test starts a temporary PHP server and verifies registration, session cookie attributes, CSRF denial, stored-output escaping, receipt replay, one-use invitation acceptance, viewer mutation denial, audit export, cross-tenant rejection, logout and a separate seeded demo. It deletes its temporary databases, cookies and sessions. See [verification details](docs/verification.md).

GitHub Actions runs platform validation, PHP syntax checks, the business suite and the HTTP smoke suite. No production credentials are needed.

## Architecture

- `src/Inventory.php`: quantity validation, tenant-scoped queries and transactional business operations.
- `src/Auth.php`: account registration/sign-in, guest seeding, hashed invitations, role management and rate limiting.
- `src/Actor.php`: current session actor loaded from the database on every request; disabled users and expired demos are rejected.
- `src/Database.php` and `schema.sql`: SQLite configuration, schema version and database constraints/triggers.
- `public/index.php`: HTTP/session/CSRF boundary and explicit action dispatch.
- `views/`: escaped server-rendered templates and reusable receipt, reservation and table partials.
- `public/app.css`: readable white/slate/blue UI, 16px base type, 14px tables, 12px minimum metadata and 44px controls. No JavaScript or external assets are required.

### Atomic stock changes

Every inventory mutation acquires `BEGIN IMMEDIATE` before inspecting or changing stock. Reservations use an additional guarded `UPDATE ... WHERE on_hand - reserved >= quantity`, with explicit integer comparison. Balance changes, reservation status and the audit movement commit together. Errors roll back all of them. SQLite busy handling is bounded.

Receipt and reservation forms carry a random request key. The same tenant/key/normalized request returns the original result; conflicting reuse fails. Releases and fulfilments are terminal transitions and cannot execute twice. `CHECK` constraints prevent negative or over-reserved balances. Composite foreign keys prevent cross-tenant references. Triggers reject every audit-row update or delete, even if application code tries one.

Limits: 200 catalog items, 1,000 reservations, 5,000 movements, 20 active members, 20 pending invitations per workspace, and at most 1,000,000 on-hand units per item. Quantities are whole units; fractions, exponents and silently rounded input are rejected.

## Render deployment

`Dockerfile` uses the official `php:8.3.36-apache-bookworm` image, installs SQLite/mbstring extensions and serves only `public/`. Apache listens on Render's `PORT`; `/health.php` checks database readiness. Production sessions use secure cookies. Access logs omit query strings so invitation tokens are not written into them.

Create a Docker web service from this repository, or use the included `render.yaml` blueprint. The parent deployment workflow manages publishing; this repository does not automatically provision a service.

**Free Render storage is ephemeral.** SQLite data and file sessions can disappear when a free service restarts, redeploys or spins down. The Render blueprint sets `STOCKROOM_EPHEMERAL_HOSTING=true`, which displays an explicit reset/no-real-data notice before sign-in and on every workspace screen. The Docker and self-host defaults keep this flag `false`; it is a disclosure flag and does not delete, reset or alter the database. Do not use it for real inventory or sensitive data. Durable deployment needs a paid persistent disk mounted outside `public/` with `DB_PATH` and `SESSION_PATH` pointing to it, or a deliberate database migration. SQLite should run as a single service instance, not multiple independently stored replicas.

No Docker daemon was available in the implementation environment, so native PHP behavior was verified but the image still needs a hosted build/boot check. See [deployment notes](docs/deployment.md).

## Boundaries

This is a synthetic portfolio application, not a production inventory SaaS. Accounts do not have email verification, password reset, SMTP delivery, MFA, multi-workspace switching, ownership transfer, SSO or integration APIs. Invitation links are bearer capabilities: share them privately with their intended recipients. They bind a new account to the invited email but do not prove mailbox ownership. Guest demos expire after 24 hours; expired data is inaccessible but is not automatically purged. The immutable audit cannot be deleted through application flows.

Laravel was investigated first. The configured package proxy could not reach Packagist, so the authorized Core PHP fallback was implemented and tested rather than shipping an unverified framework skeleton. The project is labelled **Core PHP** throughout. See [design](docs/design.md) and [implementation plan](docs/implementation-plan.md).

## Author

[Tanveer Singh](https://singhtanveer.com) · [GitHub](https://github.com/tanveerchana)
