# SQL database migrations

New schema changes belong in this directory as UTF-8 SQL files.

## Naming and ordering

Use a unique, monotonically increasing filename in YYYYMMDD_NNNN_short_description.sql format. Files are applied in lexicographic filename order. The filename without .sql is the migration key stored in the existing migrations table. Never rename or edit a migration after it has been applied to a customer database; create a new forward migration instead.

## Current inventory

The 17 historical migrations from app/Core/Schema.php and two standalone PHP migrations have corresponding SQL files: 19 migration files total. The filenames preserve the exact identifiers already stored in customer databases.

The legacy Schema::migrate(), PasswordResetMigration::migrate(), and AdminProfileMigration::migrate() paths remain active for compatibility. The SQL runner is deliberately not wired into the public front controller or production update flow yet. This avoids changing customer databases before real MySQL/MariaDB integration and schema-parity checks pass.

## Runner behavior

HostPilot\Core\MigrationRunner provides ordered discovery, pending-version lookup, SQL statement splitting, MySQL advisory locking, and explicit runPending(..., true) execution. It records a migration only after every statement in its file succeeds.

MySQL DDL may implicitly commit. A partially executed migration therefore cannot be assumed to roll back. Every migration must be safe to retry after partial DDL. The runner refuses execution unless its caller supplies explicit confirmation; that boolean is a guardrail, not an authorization system. Production callers must independently enforce authenticated administrator permissions, CSRF protection, a verified backup, maintenance mode, and audit logging.

## Test coverage

- Parser/discovery tests verify statement splitting and the exact ordered list of all 19 migration keys.
- tests/migration_integration.php executes the complete SQL migration set against a dedicated disposable database. It checks a partial-DDL retry, the confirmation guard, tracking records, expected tables, and a second run as a no-op.
- GitHub Actions runs the integration test on both MySQL 8.0 and MariaDB 10.11 using isolated service containers. The test refuses to run unless the database name is exactly hostpilot_ci.
- These tests validate SQL execution and retry safety; they do not yet prove structural equivalence with every legacy PHP migration path.

## Production activation gate

Do not connect the SQL runner to normal storefront requests. Before production activation:

1. Compare every SQL file with its original PHP DDL and data changes.
2. Test a fresh install and representative legacy-upgrade snapshots, then compare resulting table/column/index/foreign-key definitions.
3. Verify database and file backup creation and perform a restore rehearsal.
4. Implement a browser-based, authenticated admin upgrade screen with CSRF, explicit confirmation, maintenance/lock mode, progress and failure logs.
5. Verify partial-failure recovery and retry against both supported database engines.
6. Only then switch new installs and controlled upgrades to the shared SQL migration directory.

No production database migration is run by the CI workflow.
