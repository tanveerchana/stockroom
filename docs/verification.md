# Verification record

Verified with native Ubuntu PHP 8.3.6 (security-backported package), PDO SQLite, mbstring and cURL on 1 November 2026.

- The initial inventory suite ran against stubs and all nine tests failed before implementation.
- The final business/auth suite has 17 passing scenarios, including a real two-process reservation race, overflow rollback, immutable audit and tenant/role boundaries.
- A separate HTTP smoke suite passes using a temporary native PHP server, database, session directory and independent cookie jars.
- All checked-in PHP files pass native `php -l` syntax validation.
- `composer validate --strict` passes. There are no third-party PHP package dependencies; the platform requirements are recorded in the lockfile.

The concurrency test uses two independent PHP processes and PDO connections to a shared temporary SQLite file. Both wait behind a barrier, then request seven units from a ten-unit item. Exactly one reserves, one rejects, stock remains 10 on hand / 7 reserved, and exactly one reservation movement is added.

The HTTP suite checks guest redirect, account registration, HttpOnly/SameSite session cookies, content security policy, missing-CSRF rejection, stored HTML escaping, receipt replay, invitation acceptance, viewer 403 response, CSV export, cross-tenant denial, session logout and isolated sample seeding. It does not use a mock database or JavaScript replacement for the PHP implementation.

No Docker daemon was installed in the authoring environment; Render subsequently built and booted the image, as recorded below. Native tests do not establish production load capacity.

The HTTP suite also runs in both hosting modes. It verifies that ephemeral notices appear before registration and within account workspaces only when the explicit deployment flag is true, and that ordinary self-hosted mode does not claim ephemeral storage. The flag leaves the same registration, receipt replay, tenant and session workflows operational.

## Hosted verification — 2 November 2026

- Public app: https://stockroom-kz2t.onrender.com/
- Published runtime: `18c6e55c9063bf11cf0eb0477b256cf5215b7f11`.
- Render service: `srv-dbjukhff3r2c73d6bm90`; free Docker service in Singapore.
- Deploy `dep-dbjukhvf3r2c73d6bnkg` reached `live`; `/health.php` returned HTTP 200 and `{"status":"ok"}`.
- GitHub Actions [run 41373636212](https://github.com/tanveerchana/stockroom/actions/runs/41373636212) passed Composer validation/install, PHP lint, all 17 business/auth scenarios, the HTTP suite and its ephemeral-hosting variant.
- Opened a separate synthetic guest warehouse through the public browser; the storage-reset notice was visible before entry and within the workspace.
- Recorded a ten-unit receipt for the A5 notebook. Total on-hand rose 386 → 396; the item rose 80 → 90 on hand, with eight reserved.
- Reserved five units: on-hand remained 90, reserved rose 8 → 13 and availability fell 82 → 77.
- Fulfilled that hold: the reservation became terminal/fulfilled; the audit recorded -5 on-hand and -5 reserved, leaving 85 on hand and eight reserved.
- Movement audit showed the three operations together. Downloaded CSV contained the same quantities, references and final balances.

No real operational data or payment information was entered. Recent Render error logs were empty. This verifies the hosted Docker boot and sampled workflow, not production scale or durable storage. Both SQLite and file sessions use ephemeral free-service storage and can reset on restart, redeploy or idle spin-down. Free instances may take time to wake. No paid disk or database was provisioned.
