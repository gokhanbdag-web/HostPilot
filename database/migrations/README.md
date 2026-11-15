# SQL database migrations

New schema changes belong in this directory as UTF-8 SQL files.

## Naming and ordering

Use a unique, monotonically increasing filename:

**YYYYMMDD_NNNN_short_description.sql**

Example: **20261114_0001_add_customer_locale.sql**.

Files are applied in lexicographic filename order. The filename without .sql is the migration key stored in the existing migrations table. Never rename or edit a migration after it has been applied to a customer database; create a new forward migration instead.

## Legacy conversion status

The 17 historical migrations from `app/Core/Schema.php` now have corresponding SQL files in this directory. Their filenames preserve the exact identifiers already stored in customer databases, so the runner can skip already-applied versions.

The legacy `Schema::migrate()` path remains active for compatibility. The new SQL files are staged for review and are **not yet wired into the installer, front controller, or production upgrade flow**. Do not remove or bypass the legacy PHP migrations until clean-install and representative-upgrade equivalence tests have passed.

Two additional migrations remain outside the 17-entry `Schema.php` list:
- `20261113_0016_admin_password_resets` in `app/Core/PasswordResetMigration.php`
- `20261113_0017_admin_first_last_name` in `app/Core/AdminProfileMigration.php`

These must also be converted and reconciled before the installer and upgrade flow are switched to a single SQL migration source.

## Safety rules

- Prefer additive, backward-compatible changes.
- Use idempotent DDL (CREATE TABLE IF NOT EXISTS, guarded/index-aware operations where needed). MySQL DDL may implicitly commit, so a failed file can be retried only if its statements are safe to repeat.
- Conditional legacy column, index, and constraint additions use information_schema checks and prepared DDL statements; validate this strategy against the minimum supported MySQL and MariaDB versions.
- The historical domain-renewal tracking migration includes a data UPDATE. Its columns are added first and the UPDATE is intentionally scoped to rows still in the legacy `pending_remote` state.
- Do not put destructive data changes, credential changes, or irreversible operations in a routine migration.
- Test against supported MySQL/MariaDB versions and a copy of representative data.
- Production upgrades must require an authenticated administrator, explicit confirmation, a verified database backup, and a maintenance/lock strategy.
- Never run migrations from ordinary storefront requests.

HostPilot\\Core\\MigrationRunner provides ordered discovery, pending-version lookup, SQL statement splitting, advisory locking, and an explicit runPending(..., true) execution method. It is intentionally not wired into the public front controller yet.

The parser test also verifies that the 17 converted legacy SQL files are discovered in their historical order and can be split into statements. This is not a substitute for executing them against real MySQL/MariaDB databases.

## Remaining work before activation

1. Convert the two standalone PHP migrations listed above.
2. Compare every SQL file against the original PHP DDL and data changes.
3. Add clean-install, already-upgraded, partially-applied/retry, and repeated-run tests on supported MySQL/MariaDB versions.
4. Verify backup and restore, maintenance mode, authenticated admin confirmation, and failure recovery.
5. Only then switch new installs and controlled upgrades to the shared SQL migration directory.
