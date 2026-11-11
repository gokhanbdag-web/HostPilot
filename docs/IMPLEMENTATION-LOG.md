# HostPilot implementation log

## 2026-11-11 — Foundation milestone

### Added
- Root front controller with root/subdirectory path normalization.
- Browser installer using PDO; collects database details, admin name, username, email and password.
- Installer choice for single-business or multi-vendor mode.
- Initial users/settings/migrations database tables.
- Generated installation key and protected storage directory rules.
- Admin authentication using password_hash/password_verify, session ID rotation and CSRF tokens.
- Responsive full-screen admin workspace with navigation.
- Initial Chart.js customer-registration trend based on actual user records.
- Operational summary cards that distinguish absent modules from zero counts.
- GitHub Actions PHP syntax-lint workflow targeting PHP 8.3.

### Not yet complete
- This is not a release candidate and has not been installed on a real shared-hosting account.
- Authentication still needs rate limiting, password reset, MFA options, detailed RBAC and security tests.
- Installer needs full environment preflight, robust rollback, schema migration runner, and root/nested-subdirectory test coverage.
- Dashboard financial and infrastructure charts await billing and monitoring modules.
- Sidebar module links are navigation placeholders until their corresponding modules are implemented.
- Chart.js currently loads from a pinned CDN URL; an offline/self-hosted asset strategy remains to be finalized.

### Validation
- PHP lint workflow has been committed; its GitHub Actions result must be checked after GitHub runs it.
- No live database, payment, registrar or hosting-panel integration test has been claimed.


## 2026-11-11 — Customer portal and support foundation

### Added
- Customer self-registration and login, with customer-role isolation.
- Client dashboard and responsive client-side navigation.
- Customer support ticket creation and customer-owned ticket listing.
- Ticket thread view and customer replies.
- Staff ticket inbox, staff replies, and close/reopen controls.
- Public knowledge base / FAQ listing scaffold.
- Installer now creates tickets, ticket_messages, and knowledgebase_articles tables.
- Front-controller routes for client area, ticket threads, knowledge base, and staff ticket inbox.

### Hardening notes
- Ticket ownership is enforced in customer queries; customers cannot view another account's ticket by changing its numeric ID.
- Form actions use the shared CSRF token.
- Ticket creation and first message are wrapped in a database transaction.
- Email notifications, file attachments, staff assignment, SLA, canned replies, and article administration are not implemented yet.
- Existing installations will need a schema migration mechanism before these new tables can be applied; the browser installer creates them for fresh installs only.


## 2026-11-11 — Catalogue and order foundation

### Added
- Product groups and product catalogue schema, including product type, visibility, and sort order.
- Currency-aware price rows with supported billing-cycle identifiers and setup-fee field.
- Order and order-item records with DECIMAL monetary fields and product-name snapshots.
- Customer-facing storefront catalogue and individual product detail pages.
- Customer checkout action creates a pending order only after server-side product and active-price revalidation.
- Customer order history restricted to the signed-in customer.
- Staff product/group creation and staff order overview.
- Idempotent browser-request migration for already-installed instances, plus matching fresh-installer schema.
- GitHub Actions PHP syntax lint workflow.

### Not yet implemented
- Multiple prices per product editing, cart/multi-item checkout, product custom fields, coupons, tax rules, invoices, payment collection, service activation, order review actions, and provisioning adapters.
- Migration execution currently runs at application bootstrap and needs concurrency locking plus an explicit upgrade UI before production release.
- GitHub Actions result is pending; source has not been deployed to a live PHP/MySQL host in this turn.


## 2026-11-11 — Invoice foundation

### Added
- Invoice header, invoice-line snapshot, and payment-transaction ledger tables.
- New idempotent migration for existing installations and matching fresh-installer DDL.
- Every newly placed pending order receives one unpaid invoice within the same database transaction.
- Invoice number, issue time, seven-day initial due date, currency, totals, and item description are snapshotted.
- Customer invoice list and staff invoice overview.
- Customer dashboard shortcut to invoices.

### Important boundary
- These are internal billing records, not a claim of statutory e-invoice/e-archive compliance. Tax configuration, official document numbering requirements, legal invoice output, payment capture, refunds, and reconciliation remain to be implemented.
- No payment is marked successful and no hosting service is activated at this stage.
