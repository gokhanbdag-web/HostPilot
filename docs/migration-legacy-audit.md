# Legacy schema migration conversion audit

The shared SQL migration directory now contains 20 ordered migration files: the 17 identifiers historically run by app/Core/Schema.php, two standalone PHP migrations, and one forward repair migration for a legacy invoice/service foreign-key omission.

## Identifier mapping

The filenames preserve the exact identifiers already stored in customer databases:

1. 20261111_0001_catalog_orders_services
2. 20261111_0002_invoices_payments
3. 20261111_0003_hosting_servers
4. 20261111_0004_provisioning_queue
5. 20261111_0005_service_billing_lifecycle
6. 20261111_0006_provisioning_intent
7. 20261111_0007_remote_package_identifiers
8. 20261112_0008_service_package_changes
9. 20261112_0009_service_package_change_type
10. 20261112_0010_email_outbox
11. 20261112_0011_domain_registration_queue
12. 20261112_0012_domain_nameserver_queue
13. 20261112_0013_domain_expiry_sync
14. 20261112_0014_domain_renewal_queue
15. 20261112_0015_domain_renewal_action_tracking
16. 20261112_0016_domain_expiry_reminder_index
17. 20261112_0017_domain_expiry_sync_queue
18. 20261113_0016_admin_password_resets
19. 20261113_0017_admin_first_last_name
20. 20261115_0001_invoice_service_fk_repair

## Conversion-sensitive operations

- Migration 3 conditionally adds server and provisioning columns to services, then adds its foreign key only if absent.
- Migration 5 conditionally adds invoice/service lifecycle columns and indexes. The legacy PHP implementation's FK existence statement was not executed; migration 20 repairs the missing invoice/service foreign key on already-upgraded databases.
- Migrations 6, 7, 9, 12, 13, 15, 16 and 17 contain guarded column/index/constraint operations.
- Migration 15 adds registrar action tracking columns before its data update. The update is scoped to legacy rows still in pending_remote with no action_eaq_id.
- The password reset migration creates a token table with unique token hashes and user relationship.
- The admin profile migration conditionally adds first/last name fields and performs the legacy name backfill.
- Conditional no-op branches use DO 0 rather than SELECT 1 so PDO does not leave an unconsumed result set while subsequent DDL is executed.

## Verification status

- [x] All 20 migration identifiers have matching SQL files.
- [x] Parser/discovery test asserts all 20 identifiers in order.
- [x] MySQL 8.0 and MariaDB 10.11 integration tests execute the SQL migration set.
- [x] Partial-DDL retry, explicit confirmation, tracking and idempotent repeated-run checks.
- [x] Fresh SQL schema compared with legacy PHP schema for tables, columns, indexes and foreign keys.
- [x] Legacy admin-name data backfill compared.
- [x] Already-upgraded legacy database recognized with no pending migrations.
- [x] Compressed database backup integrity and restore rehearsal in isolated CI database.
- [x] Browser installer uses the shared SQL migration directory.
- [x] Authenticated admin upgrade page, CSRF confirmation, maintenance mode before backup, failure retry and guarded maintenance-clear safeguards.
- [x] HTTP smoke tests cover root and subdirectory installs, invalid database connection, partial-install retry, admin authentication and upgrade flow.
- [x] Installer preflight checks PHP/PDO/PDO MySQL/OpenSSL/zlib/storage write permissions.
- [ ] Final review and production deployment approval.

No production database migration is run by CI. The public front controller does not automatically execute pending schema changes.
