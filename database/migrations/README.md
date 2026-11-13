# SQL database migrations

New schema changes belong in this directory as UTF-8 SQL files.

## Naming and ordering

Use a unique, monotonically increasing filename:

**YYYYMMDD_NNNN_short_description.sql**

Example: **20261114_0001_add_customer_locale.sql**.

Files are applied in lexicographic filename order. The filename without .sql is the migration key stored in the existing migrations table. Never rename or edit a migration after it has been applied to a customer database; create a new forward migration instead.

## Safety rules

- Prefer additive, backward-compatible changes.
- Use idempotent DDL (CREATE TABLE IF NOT EXISTS, guarded/index-aware operations where needed). MySQL DDL may implicitly commit, so a failed file can be retried only if its statements are safe to repeat.
- Do not put destructive data changes, credential changes, or irreversible operations in a routine migration.
- Test against the supported MySQL/MariaDB versions and a copy of representative data.
- Production upgrades must require an authenticated administrator, explicit confirmation, a verified database backup, and a maintenance/lock strategy.
- Never run migrations from ordinary storefront requests.

HostPilot\Core\MigrationRunner provides ordered discovery, pending-version lookup, SQL statement splitting, and an explicit runPending(..., true) execution method. It is intentionally not wired into the public front controller yet. The existing 17 legacy migrations in app/Core/Schema.php remain the active compatibility path until their SQL and conditional DDL have been audited and moved without changing their existing migration identifiers or behavior.

The installer and upgrade flow will be switched to this directory only after that compatibility conversion and backup/restore tests are complete.
