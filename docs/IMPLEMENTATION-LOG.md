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
