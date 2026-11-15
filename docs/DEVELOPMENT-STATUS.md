# HostPilot — Development Status

Last reconciled: 2026-11-15

## Production baseline

- Default branch: main.
- Production deployment: kodvadisi.com.tr/hostpilot.
- Latest production baseline recorded before Stage 1 work: commit 42368aa447d8fee7c9c50e87bf07e55ebc567557, application version 2026.11.13.2.
- The deployment workflow reported HTTP 200 / Healthy and verified database connection, installation lock, config, storage write permission, PHP compatibility and version file.
- That deployment did not execute SQL migrations against the live database.

## Implemented application foundations

- Native PHP and MySQL/PDO application with no Composer runtime requirement.
- Browser-based installer for database settings and first administrator; root and subdirectory URL handling.
- Customer authentication and client area foundations.
- Admin authentication, dashboard, customer, product/catalog, order, invoice, payment, server, hosting-service, domain, ticket, integration, theme/page-editor and update-center foundations.
- Payment adapter foundations for PayTR and iyzico; provider sandbox/live transactions still require merchant credentials and end-to-end validation.
- Hosting adapter foundations for cPanel, Plesk and DirectAdmin; live server API state and provider-specific compatibility still require validation.
- ResellerClub domain workflow foundations; sandbox/live registrar transaction verification remains outstanding.
- SMTP settings, queued email outbox, retry worker, and selected business-event notifications.
- Deployment webhook with signed request verification, code backup/sync and post-deploy health checking.

The detailed product backlog and stage order are tracked in docs/ROADMAP.md and the current project handoff.

## Stage 1 — migration, installer and controlled update

Implementation is being developed on feature/stage-1-migration-integration and PR #6. It has not been merged into main or deployed to production.

The branch currently contains:
- 20 ordered SQL migration files shared by fresh installation and existing-install upgrades.
- A browser installer that creates foundational tables, then applies the shared SQL migration directory before creating the first administrator.
- A migration runner with ordered discovery, tracking, advisory locking, explicit confirmation and retry-safe migration execution.
- An authenticated /admin/database-upgrade page with CSRF confirmation, a verified compressed SQL backup, maintenance mode, failure logging and retry/recovery controls.
- A front-controller maintenance response that keeps the admin login and upgrade route available while public traffic receives HTTP 503.
- A forward repair migration for a missing invoice/service foreign key on databases where the old PHP migration's existence check did not execute.
- CI integration tests against MySQL 8.0 and MariaDB 10.11, including partial-DDL retry, schema parity against the legacy PHP migration path, existing-upgrade detection, backup integrity and restore rehearsal.

The legacy PHP migration classes remain as a comparison baseline; the public front controller no longer applies schema changes automatically on every request. Production databases are not touched by CI.

## Current verification boundary

- PHP syntax and migration parser checks run in GitHub Actions.
- MySQL/MariaDB integration runs use disposable service databases.
- The live health-check job is restricted to pushes to main; pull requests and manual workflow runs do not contact production.
- A green CI result is necessary but not sufficient for release. Before production activation, the PR must be reviewed, the browser installer and upgrade flow must be smoke-tested on a shared-hosting test account, and the production backup/deployment plan must be approved.
- No production database migration has been run as part of Stage 1 work.

## Not yet release-certified

- Full browser smoke-test matrix for fresh install, subdirectory install, failed connection, partial install and permission failures on real shared-hosting providers.
- Production-account backup retention and a separately rehearsed restore procedure.
- Full authorization/RBAC and security testing across all admin/customer actions.
- End-to-end provider sandbox/live verification for payment, hosting and registrar integrations.
- Complete billing/accounting compliance, full CMS/page builder, analytics, API/webhook framework, monitoring and release acceptance.

A feature is not marked production-ready solely because its PHP files pass syntax checks or because its workflow foundation exists. It must pass its relevant integration, failure-path and operational tests.
