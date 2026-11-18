# SQL database migrations

All new schema changes belong in this directory as UTF-8 SQL files. The same ordered SQL files are the schema source for fresh installs and authenticated upgrades.

## Naming and ordering

Use a unique, monotonically increasing filename in YYYYMMDD_NNNN_short_description.sql format. Files are applied in lexicographic filename order. The filename without .sql is the migration key stored in the existing migrations table. Never rename or edit a migration after it has been applied to a customer database; create a new forward migration instead.

## Current inventory

There are 37 SQL migration files:
- 17 historical migrations converted from app/Core/Schema.php.
- Two standalone migrations for password reset tokens and admin first/last names.
- One forward repair migration for a legacy invoice/service foreign-key omission.
- Seventeen additional forward migrations covering staff permissions, customer verification and MFA, audit/privacy, ticket attachments, payment reconciliation/refunds, statement imports/review, and private support-ticket notes, department-scoped ticket assignments, configurable support departments, knowledge-base feedback, and dynamic CMS pages.

The original PHP migration implementations remain in app/Core/Schema.php and the two standalone migration classes as compatibility references and as the independent schema-parity test baseline. The public front controller no longer executes schema migrations automatically on every request.

## Fresh installation

The browser installer creates only the foundational tables needed for installation (users, settings, migrations and support knowledge-base tables), then runs MigrationRunner against this directory before creating the first administrator and installation lock. It does not require Composer or SSH. A failed installation can be retried: migration files are designed to tolerate a partially completed DDL run.

## Existing installation upgrade

Super administrators and administrators can open /admin/database-upgrade. The page:
1. Lists pending migration versions.
2. Requires an authenticated admin session, CSRF token and explicit confirmation.
3. Creates and verifies a compressed SQL backup under storage/backups.
4. Enables storage/maintenance.lock so public requests receive HTTP 503 while the update runs.
5. Applies pending SQL files in order and records each successful version in the migrations table.
6. Removes maintenance mode after success. If a migration fails, maintenance mode remains active and the page stays available for a safe retry.

The backup folder contains an access-deny rule and is under the private storage tree. Migration events are recorded in storage/logs/migrations.log. If all migrations have completed but the maintenance lock could not be removed, the admin page offers a separate, CSRF-protected maintenance-clear action after verifying that no migrations remain.

## Runner behavior and safety

HostPilot\Core\MigrationRunner provides ordered discovery, pending-version lookup, SQL statement splitting, MySQL advisory locking and explicit runPending(..., true) execution. It records a migration only after every statement in its file succeeds.

MySQL DDL may implicitly commit. A partially executed migration therefore cannot be assumed to roll back. Every migration must be safe to retry after partial DDL. The runner's confirmation argument is a guardrail, not an authorization system; production authorization is enforced by the admin controller and installer flow.

## Test coverage

GitHub Actions uses isolated disposable databases and never contacts the live HostPilot database:
- Parser/discovery tests verify statement splitting and all 36 migration identifiers in order.
- MySQL 8.0 and MariaDB 10.11 execute all SQL migrations.
- The actual browser installer is smoke-tested over HTTP against disposable databases at the root and in a nested subdirectory; the test verifies all 36 migrations, the first super-administrator and the installation lock.
- The HTTP test also exercises authenticated admin upgrade, CSRF confirmation, maintenance-mode 503, pre-upgrade backup, invalid database connection failure and retry after a partially-created first migration.
- A deliberately interrupted first migration verifies partial-DDL retry.
- The test verifies explicit confirmation, tracking records, expected tables and a second run as a no-op.
- A separate clean schema runs the legacy PHP migration path; table/column/index/foreign-key definitions and admin-name data backfill are compared with the SQL path.
- A legacy-upgraded schema is checked for zero pending SQL migrations.
- A compressed database backup is restored into a separate disposable database and its schema/data are verified.

These tests validate the migration and backup mechanisms. They are not a substitute for a production backup retention policy or a separately rehearsed recovery procedure for a specific hosting account.

## Production activation rules

- Do not add DDL to normal storefront requests.
- Do not edit an applied SQL file; add a new forward migration.
- Do not run SQL migrations on production outside the authenticated admin upgrade screen or initial browser installer.
- Verify backups and check free storage before large customer upgrades.
- Never deploy a migration to production until the CI matrix is green and the deployment is explicitly approved.


- `20261115_0003_customer_email_verification.sql`: stores hashed, expiring, single-use customer email verification tokens. New customer registrations remain pending until the address is verified; existing accounts are not modified.

- `20261118_0001_ticket_internal_notes.sql`: stores private staff-only notes separately from customer-visible ticket messages; notes are audited and never queued as customer replies.

- `20261118_0002_ticket_assignments.sql`: stores one current staff assignment per ticket, with foreign keys to the ticket, assignee and assigning operator.

- `20261118_0003_support_departments.sql`: creates the active/inactive support department registry and seeds the four original department names for existing installations.

- `20261118_0004_knowledgebase_feedback.sql`: stores one changeable helpful/not-helpful vote per browser session and article, without storing visitor IP addresses.

- `20261118_0005_cms_pages.sql`: creates the dynamic public CMS page registry with unique SEO slugs, draft/published status and metadata fields.
