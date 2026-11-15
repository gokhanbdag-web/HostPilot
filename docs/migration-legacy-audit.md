# Legacy schema migration conversion audit

Audit source: `app/Core/Schema.php` at feature commit `b945774d4bf6ce57ae9d80a309461cadb80a51c5`.

This document is a conversion checklist and status record. The legacy migration code remains active and must not be removed until each SQL file has been reviewed and tested against both a clean install and a representative upgraded database.

## Existing migration identifiers

The identifiers below are the exact values currently stored in the `migrations.migration` column. SQL filenames use these identifiers unchanged so already-upgraded customer databases can skip the corresponding files.

| Order | Existing migration | Conversion notes |
|---:|---|---|
| 1 | `20261111_0001_catalog_orders_services` | Creates product groups, products, pricing, orders, order items and services. |
| 2 | `20261111_0002_invoices_payments` | Creates invoices, invoice items and payment transactions. |
| 3 | `20261111_0003_hosting_servers` | Creates server/provisioning tables and conditionally adds columns and a foreign key to services. |
| 4 | `20261111_0004_provisioning_queue` | Creates provisioning jobs. |
| 5 | `20261111_0005_service_billing_lifecycle` | Conditionally adds invoice/service columns, indexes and a foreign key. |
| 6 | `20261111_0006_provisioning_intent` | Conditionally adds provisioning credential-intent columns to services. |
| 7 | `20261111_0007_remote_package_identifiers` | Conditionally adds server-product mapping columns. |
| 8 | `20261112_0008_service_package_changes` | Creates service package change records. |
| 9 | `20261112_0009_service_package_change_type` | Conditionally adds the change_type column. |
| 10 | `20261112_0010_email_outbox` | Creates the email outbox table. |
| 11 | `20261112_0011_domain_registration_queue` | Creates domain registration records/queue. |
| 12 | `20261112_0012_domain_nameserver_queue` | Conditionally adds nameserver and pending-nameserver fields. |
| 13 | `20261112_0013_domain_expiry_sync` | Conditionally adds expiry date and registrar sync fields. |
| 14 | `20261112_0014_domain_renewal_queue` | Creates domain renewal records and queue indexes/constraints. |
| 15 | `20261112_0015_domain_renewal_action_tracking` | Adds registrar action tracking columns and updates legacy pending_remote rows. The UPDATE is placed after the guarded column additions and remains scoped to rows with no action_eaq_id. |
| 16 | `20261112_0016_domain_expiry_reminder_index` | Conditionally creates an index after checking information_schema.STATISTICS. |
| 17 | `20261112_0017_domain_expiry_sync_queue` | Conditionally adds sync attempt/error columns and a queue index. |

## Conversion rules applied

1. Keep each migration key exactly as listed; do not generate new keys for these historical schema changes.
2. Each migration has a corresponding `database/migrations/<identifier>.sql` file in the same order.
3. CREATE TABLE definitions are copied from the legacy source, preserving their column definitions, defaults, indexes, foreign keys, engine, character set and collation.
4. Conditional column, index and foreign-key operations use information_schema checks with prepared DDL statements rather than unconditional ALTER statements.
5. Migration 15 preserves the original UPDATE predicate and values. The two required columns are added before the UPDATE; the UPDATE changes only rows that remain in `pending_remote` with `action_eaq_id IS NULL`.
6. The runner records a migration only after all statements in its SQL file complete. Existing installations with a matching migration row should skip that file.
7. No installer, front-controller, or production-upgrade cutover has been made.

## Additional migrations outside Schema.php

The audit found two more PHP migrations which are not part of the 17 entries above and must not be overlooked during the eventual single-source cutover:

- `20261113_0016_admin_password_resets` in `app/Core/PasswordResetMigration.php`.
- `20261113_0017_admin_first_last_name` in `app/Core/AdminProfileMigration.php`.

Their conversion and compatibility checks remain outstanding.

## Current status

- [x] Inventory all 17 legacy Schema.php migration identifiers.
- [x] Add 17 corresponding ordered SQL files with the original migration keys.
- [x] Add parser/discovery coverage for the 17 files.
- [x] Correct migration 15 statement order so its columns exist before its data UPDATE.
- [ ] Execute every migration on clean MySQL and MariaDB databases.
- [ ] Compare final schemas from the legacy and SQL paths.
- [ ] Test already-upgraded databases, partial failure/retry, and repeated execution.
- [ ] Convert and reconcile the two standalone PHP migrations.
- [ ] Implement authenticated admin upgrade with verified backup, maintenance mode, explicit confirmation, logs, and recovery procedure.
- [ ] Switch the installer and upgrade flow only after all checks pass.

MySQL DDL can implicitly commit. A failed migration therefore cannot be assumed to roll back transactionally. Before production execution, require authenticated admin confirmation, a verified backup, maintenance/locking safeguards, and a tested recovery plan. No customer database, installed lock, runtime configuration, or live server was modified during this conversion work.
