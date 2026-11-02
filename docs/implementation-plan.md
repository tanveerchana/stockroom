# Implementation plan

1. Establish a trusted native PHP runtime, verify current Laravel requirements and investigate Laravel, then use the authorized Core PHP fallback if dependency access is blocked, with a platform lockfile.
2. Write failing inventory business tests covering exact units, guarded reservations, release/fulfil semantics, immutable audit, tenant isolation and permissions.
3. Implement schema, tenant-scoped services, session authentication, authorization and controllers.
4. Build readable server-rendered PHP catalog, reservation, receipt, audit, low-stock and team screens, plus an isolated synthetic demo.
5. Verify business and HTTP tests, PHP syntax, production boot configuration and dependency audit. Add Docker/Render/CI setup and document actual constraints.
