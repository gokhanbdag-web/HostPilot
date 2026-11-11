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
