# Legacy schema migration conversion audit

The SQL migration directory contains the 17 identifiers historically run by app/Core/Schema.php, plus the two standalone migrations for password reset tokens and admin first/last names. The original PHP migration implementations remain in place and are still the active application path.

## Identifier mapping

The SQL filenames preserve the exact migration keys already stored in customer databases:

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

## Conversion-sensitive operations

- Migration 3 conditionally adds server and provisioning columns to services, then adds its foreign key only if absent.
- Migration 5 conditionally adds invoice/service lifecycle columns, indexes and a foreign key.
- Migrations 6, 7, 9, 12, 13, 15, 16 and 17 contain guarded column/index/constraint operations.
- Migration 15 adds the registrar action tracking columns before its data update. The update is scoped to legacy rows still in pending_remote with no action_eaq_id.
- The password reset migration creates a token table with unique token hashes and user relationship.
- The admin profile migration conditionally adds first/last name fields and performs the legacy name backfill.

## Verification status

- [x] All 19 migration identifiers have matching SQL files.
- [x] Parser/discovery test asserts all 19 identifiers in order.
- [x] SQL splitter checks semicolons in comments and quoted values.
- [x] Runner refuses execution without explicit confirmation and uses a MySQL advisory lock.
- [x] Dedicated MySQL 8.0 and MariaDB 10.11 integration tests added to CI, including partial-DDL retry and repeated-run checks.
- [ ] CI integration runs must pass on both database engines.
- [ ] Compare complete resulting schemas from the legacy PHP path and SQL path on fresh-install and representative-upgrade fixtures.
- [ ] Verify backup and restore, maintenance mode, authenticated admin confirmation, and failure recovery.
- [ ] Only then activate the SQL runner in the installer and controlled admin upgrade flow.

MySQL DDL can implicitly commit. A failed migration can leave earlier statements applied. Production execution must require authenticated admin authorization, CSRF protection, a verified backup, maintenance/locking safeguards, an audit trail, and a rehearsed recovery procedure. CI uses only ephemeral service databases and never contacts the live HostPilot database.
