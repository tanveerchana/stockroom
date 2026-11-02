# Stockroom design

Stockroom is a synthetic inventory and reservation workspace by Tanveer Singh. It demonstrates Core PHP backend engineering with SQLite, server-rendered escaped PHP templates and a readable operational interface. There is no real commerce, payment, shipping or external inventory integration.

Each account belongs to one tenant workspace. Owners can create catalog items and manage tenant members; operators can receive stock and reserve, release or fulfil it; viewers can inspect the catalog, low-stock list, reservations and append-only audit ledger. A guest demo creates a separate temporary tenant and never shares account state. Server session authentication, CSRF protection, password hashing, session regeneration and independent server authorization protect every mutation.

Quantities are whole integer units. Available stock equals on-hand minus reserved units. Receipts increase on-hand; reservations increase reserved; releases decrease reserved; fulfilments decrease both on-hand and reserved. Each operation runs inside a SQLite write transaction and uses guarded updates, so no sequence of concurrent requests can oversell. Terminal reservations cannot be repeated. Audit movements are immutable and written in the same transaction as balances. Tenant-scoped lookups prevent cross-workspace object access.

The interface uses white panels on #f6f8fa, slate #18212f text, blue #2563eb actions, 16px body text, 14px tables, metadata at least 12px and 44px controls. Navigation exposes Overview, Catalog, Reservations, Movements and Team. Data summaries derive from stored quantities. Forms have explicit validation and errors. All synthetic/demo labels remain visible.

Deployment uses a Docker PHP web runtime on Render. SQLite and file sessions on a free ephemeral instance reset when the service restarts; persistent storage requires a paid disk or migration to a durable database. This limit is disclosed in the UI and README. Dependencies are locked and CI runs business and HTTP tests.

Runtime decision: native PHP 8.3 and PDO SQLite were installed from the configured official Ubuntu snapshot packages. Laravel 13 requirements were verified in its official documentation, but Composer dependency retrieval from Packagist failed through the configured proxy. The authorized Core PHP fallback was selected, with no external PHP packages, rather than leaving untested framework code.
