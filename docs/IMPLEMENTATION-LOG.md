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


## 2026-11-11 — PayTR and iyzico payment adapter foundation

### Added
- Administrator-only payment configuration page for PayTR and iyzico.
- Payment credentials encrypted at rest using AES-256-GCM and the installation application key; blank secret fields preserve existing credentials.
- PayTR iFrame token request with HMAC-SHA256 request token, merchant callback hash validation, and exact amount check.
- iyzico Checkout Form initialization and server-side retrieve flow using IYZWSv2 request signing.
- iyzico response signature, fraud approval, basket ID, conversation ID, currency, and amount checks before settlement.
- Customer invoice payment page and payment-method choice.
- Payment callbacks settle invoices idempotently under database row locks, mark linked orders paid, and create pending service records only after full payment.
- Service next-due dates derive from the selected billing cycle; one-time services have no renewal date.
- HTTPS required for checkout; HTTP transport supports cURL or verified TLS stream fallback.
- Provider callback routes and payment settings navigation.

### Remaining / not yet verified
- No merchant credentials were supplied, so sandbox or live transactions were not executed.
- No payment is marked paid by a browser return URL; provider callback/retrieve verification is required.
- Recurring/subscription billing, refunds, partial payments, tax calculation, reconciliation, email notifications, and formal accounting/e-invoice compliance remain pending.
- Payment settings require OpenSSL support; outbound HTTPS requires cURL or PHP HTTPS stream support.
- PHP lint workflow is present but its run result has not been confirmed in this turn.


### Payment verification tooling
- Added dependency-free PHP smoke tests for AES-256-GCM credential vault round trips, rejection under a different install key, iyzico IYZWSv2 request-signature composition, and PayTR callback signature validation/tamper rejection.
- GitHub Actions now runs PHP syntax checks and the payment cryptography smoke tests on pushes to any branch and pull requests.


### Payment operations visibility
- Added an administrator payment transaction history view with provider, invoice/customer, amount, status, provider reference, and safe response notes.
- Staff roles cannot access payment credential settings or payment transaction history.
- Cancelled/refunded invoices are blocked from online payment; failed checkout initialization is recorded as a failed transaction.
- iyzico fraud-review outcomes remain pending rather than being incorrectly marked as failed.

- iyzico fraud-review states are kept pending only after the retrieve response signature and invoice/conversation/amount data all match.
- iyzico buyer identity number is checksum-validated before checkout initialization.
