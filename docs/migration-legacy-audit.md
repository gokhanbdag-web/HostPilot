# Legacy schema migration conversion audit

Audit source: `app/Core/Schema.php` on `main` at the time this document was prepared.

This document is a conversion checklist, not an executable migration. The legacy migration code remains active and must not be removed until each SQL file has been reviewed and tested against both a clean install and a representative upgraded database.

## Existing migration identifiers

The identifiers below are the exact values currently stored in the `migrations.migration` column. SQL filenames must use these identifiers unchanged so already-upgraded customer databases skip the corresponding files.

| Order | Existing migration | Conversion notes |
|---:|---|---|
| 1 | `20261111_0001_catalog_orders_services` | Creates product groups, products, pricing, orders, order items and services. Mostly repeat-safe CREATE TABLE statements. |
| 2 | `20261111_0002_invoices_payments` | Creates invoices, invoice items and payment transactions. Mostly repeat-safe CREATE TABLE statements. |
| 3 | `20261111_0003_hosting_servers` | Creates server/provisioning tables and conditionally adds columns to services. Conditional column additions need portable guards. |
| 4 | `20261111_0004_provisioning_queue` | Creates provisioning jobs. Preserve the existing table definition exactly. |
| 5 | `20261111_0005_service_billing_lifecycle` | Adds multiple invoice columns conditionally and includes additional DDL. Must preserve the information_schema checks and exact defaults. |
| 6 | `20261111_0006_provisioning_intent` | Conditionally adds a services column. |
| 7 | `20261111_0007_remote_package_identifiers` | Conditionally adds a server-product mapping column. |
| 8 | `20261112_0008_service_package_changes` | Creates service package change records. |
| 9 | `20261112_0009_service_package_change_type` | Conditionally adds the change_type column. |
| 10 | `20261112_0010_email_outbox` | Creates the email outbox table. |
| 11 | `20261112_0011_domain_registration_queue` | Creates domain registration records/queue. |
| 12 | `20261112_0012_domain_nameserver_queue` | Conditionally adds nameserver and pending-nameserver fields. |
| 13 | `20261112_0013_domain_expiry_sync` | Conditionally adds expiry date and registrar sync fields. |
| 14 | `20261112_0014_domain_renewal_queue` | Creates domain renewal records and queue indexes/constraints. |
| 15 | `20261112_0015_domain_renewal_action_tracking` | Conditionally adds registrar action tracking columns and performs a data UPDATE for legacy pending_remote rows. This data change needs explicit review and an idempotency test. |
| 16 | `20261112_0016_domain_expiry_reminder_index` | Conditionally creates an index after checking information_schema.STATISTICS. |
| 17 | `20261112_0017_domain_expiry_sync_queue` | Conditionally adds sync attempt/error columns and a queue index. |

## Required conversion rules

1. Keep each migration key exactly as listed; do not generate new keys for these historical schema changes.
2. Convert each migration to one `database/migrations/<identifier>.sql` file in the same order.
3. Preserve column types, nullability, defaults, indexes, foreign keys, character set and collation verbatim.
4. The current code uses information_schema guards for conditional column/index changes. Do not replace those guards with unconditional ALTER statements. MySQL/MariaDB support for `IF NOT EXISTS` differs by server version; choose a compatibility mechanism and test on the minimum supported versions.
5. Migration 15 includes a data UPDATE. Preserve its exact WHERE predicate and resulting values, then prove it is safe to retry after partial DDL failure.
6. Existing installations with a matching row in `migrations` must skip that SQL file. A clean installation must apply the same SQL files in order.
7. Do not switch `Schema::migrate()`, the installer, or production upgrade flow until all 17 files have been compared against the original definitions and tested.
8. MySQL DDL can implicitly commit. Before production execution, require authenticated admin confirmation, a verified backup, maintenance/locking safeguards, and a recovery plan. A migration log row must be written only after the complete migration file succeeds.
9. Do not run migration execution on ordinary frontend requests.

## Current status

- The explicit SQL runner is a foundation only.
- The legacy Schema.php path remains unchanged.
- No customer database, installed lock, or runtime configuration is modified by this audit.
- Next implementation step: extract and review the exact SQL for migrations 1–17, then add clean-install and upgrade-compatibility tests before switching either installer or upgrader.
