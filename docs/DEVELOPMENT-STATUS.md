# HostPilot — Development Status

## Repository initialization

- Private repository: `gokhanbdag-web/HostPilot`
- Default branch: `main`
- Product requirements, architecture, integration research, security baseline, and roadmap committed.
- Initial PHP front controller and first-run installer scaffold committed.

## Implemented in this milestone

- Apache front-controller rewrite and internal directory deny rules.
- Root/subdirectory-aware initial request handling.
- Browser installer collecting database connection details and initial administrator identity.
- PDO connection foundation with native PHP.
- Initial database tables: users, settings, migrations.
- Random application key generation and protected storage config/installation lock.
- Basic JSON health endpoint after installation.

## Not implemented yet

- Authentication/login and complete admin shell.
- Client Area and storefront themes.
- Product/catalog/order/invoice/billing modules.
- PayTR/iyzico production adapters.
- cPanel/WHM, DirectAdmin, Plesk, registrar adapters.
- Ticketing, FAQ, CMS, page builder, Chart.js dashboard, monitoring collector.
- Migration runner, automated test suite, upgrade and backup system.

## Verification warning

The source has been committed to GitHub but has not yet been installed on a live shared-hosting account or tested against real provider credentials. The installer is an early scaffold and must pass PHP lint, MySQL integration, root/subdirectory, duplicate-install, permissions, and failure-recovery tests before being treated as release-ready.
