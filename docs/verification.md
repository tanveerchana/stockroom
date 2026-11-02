# Verification record

Verified with native Ubuntu PHP 8.3.6 (security-backported package), PDO SQLite, mbstring and cURL on 1 November 2026.

- The initial inventory suite ran against stubs and all nine tests failed before implementation.
- The final business/auth suite has 17 passing scenarios, including a real two-process reservation race, overflow rollback, immutable audit and tenant/role boundaries.
- A separate HTTP smoke suite passes using a temporary native PHP server, database, session directory and independent cookie jars.
- All checked-in PHP files pass native `php -l` syntax validation.
- `composer validate --strict` passes. There are no third-party PHP package dependencies; the platform requirements are recorded in the lockfile.

The concurrency test uses two independent PHP processes and PDO connections to a shared temporary SQLite file. Both wait behind a barrier, then request seven units from a ten-unit item. Exactly one reserves, one rejects, stock remains 10 on hand / 7 reserved, and exactly one reservation movement is added.

The HTTP suite checks guest redirect, account registration, HttpOnly/SameSite session cookies, content security policy, missing-CSRF rejection, stored HTML escaping, receipt replay, invitation acceptance, viewer 403 response, CSV export, cross-tenant denial, session logout and isolated sample seeding. It does not use a mock database or JavaScript replacement for the PHP implementation.

Production Secure cookies, Apache routing, the Docker build and Render health checks still require the primary agent's hosted validation. No Docker daemon is installed in the authoring environment. No hosted URL or screenshot is claimed here before deployment. Native HTTP tests do not prove production transport or load capacity.

The HTTP suite also runs in both hosting modes. It verifies that ephemeral notices appear before registration and within account workspaces only when the explicit deployment flag is true, and that ordinary self-hosted mode does not claim ephemeral storage. The flag leaves the same registration, receipt replay, tenant and session workflows operational.
