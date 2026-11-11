# HostPilot — Implementation Roadmap

**Status:** Initial roadmap v0.1  
**Approach:** Complete vertical slices with acceptance criteria; no fake UI-only completion.

## Phase 0 — Product and technical foundation
- [x] Confirm private GitHub repository and product direction.
- [x] Record core product requirements.
- [x] Record initial architecture and deployment constraints.
- [x] Record provider integration research baseline.
- [x] Record security baseline.
- [ ] Complete detailed data model and state-transition diagrams.
- [ ] Confirm licensing/distribution model and third-party asset licenses.

**Exit criteria:** Reviewed requirements, database model, route/module boundaries, and test plan.

## Phase 1 — Core framework and installer
- [x] Root front controller and subdirectory-aware route dispatch (initial routes only).
- [ ] Configuration loader, environment checks, logging, error handling.
- [x] Browser installer with DB credentials, admin identity, app URL, locale, generated encryption key, and single/multi-vendor selection (initial implementation; hardening remains).
- [ ] Full migration/seed manager and upgrade UI (initial idempotent catalogue migration runner added).
- [ ] Installation lock and protected storage.
- [ ] Root and nested-subdirectory installation test matrix.

**Exit criteria:** Fresh install on supported shared hosting without Composer, SSH, or CLI.

## Phase 2 — Identity and administration shell
- [ ] Customer and staff authentication.
- [ ] Role/permission system.
- [x] Initial full-screen responsive admin shell and navigation (module routes remain pending).
- [ ] Client Area shell.
- [ ] Locale and settings framework.
- [ ] Audit log.

**Exit criteria:** Secure login and role-scoped navigation/actions for admin, staff, and customers.

## Phase 3 — Catalog, storefront, and order lifecycle
- [x] Product groups, products, initial pricing and billing-cycle records (multiple price editing and configurable options remain pending).
- [x] Initial catalogue storefront and product detail pages (theme registry and multiple themes remain pending).
- [ ] CMS pages, menus, FAQ, knowledge base.
- [x] Single-product pending order creation, customer order history, and staff order listing (cart, payment checkout, coupons remain pending).
- [ ] Theme registry and widget/page builder foundations.

**Exit criteria:** User can browse products and place a pending order end-to-end.

## Phase 4 — Billing and payment
- [ ] Invoice engine and immutable line-item snapshots.
- [ ] Tax, currency, credit, partial payments, refunds.
- [ ] PayTR adapter and verified callback.
- [ ] iyzico adapter and verified callback.
- [ ] Reconciliation, payment logs, duplicate notification tests.
- [ ] Invoice email and customer invoice portal.

**Exit criteria:** Sandbox payment can settle an invoice exactly once; failures and retries are handled.

## Phase 5 — Hosting provisioning
- [ ] Server registry and encrypted credentials.
- [ ] cPanel/WHM adapter.
- [ ] DirectAdmin adapter.
- [ ] Plesk REST adapter plus XML fallback for documented gaps.
- [ ] Connection/capability tests.
- [ ] Async create/suspend/unsuspend/terminate/package-change workflows.
- [ ] Reconciliation and manual retry interface.

**Exit criteria:** Sandbox/test server lifecycle operations work with least-privilege credentials and full audit history.

## Phase 6 — Domains and registrar adapters
- [ ] Registrar framework and TLD/pricing matrix.
- [ ] ResellerClub adapter.
- [ ] OpenSRS adapter.
- [ ] CentralNic Reseller adapter after current API contract verification.
- [ ] Availability, registration, transfer, renewal, DNS/nameserver, lock, EPP flows as supported.
- [ ] Domain renewal notifications and retry-safe automation.

**Exit criteria:** Test registrar transactions and domain state reconciliation pass.

## Phase 7 — Support and customer self-service
- [ ] Ticket departments, priorities, assignment, internal notes, attachments.
- [ ] Customer ticket portal and email notifications.
- [ ] FAQ and knowledge base search/feedback.
- [ ] Announcements and service notifications.
- [ ] Client Area service/domain/invoice management.

**Exit criteria:** Complete customer support workflow from ticket creation through closure.

## Phase 8 — Monitoring, analytics, and reports
- [x] Initial Chart.js customer-growth dashboard chart; financial and server telemetry charts remain pending.
- [ ] Financial, customer, service, and support aggregates.
- [ ] Server availability/latency checks.
- [ ] Optional host metrics agent/endpoint design and security review.
- [ ] Alerts, incidents, retention, and export.

**Exit criteria:** Dashboard uses real stored records; monitoring clearly distinguishes available and unavailable telemetry.

## Phase 9 — Themes and visual editor
- [ ] At least three genuinely distinct storefront themes.
- [ ] Separate Client Area presentation.
- [ ] Widget library with multiple style variants.
- [ ] Drag/drop page editor, responsive preview, revisions, publish/rollback.
- [ ] Theme validation and safe rendering.

**Exit criteria:** Admin can create and publish a storefront page without editing code.

## Phase 10 — Hardening and release
- [ ] API and webhook framework.
- [ ] Email piping/inbound mail strategy.
- [ ] Backup/restore and upgrade runner.
- [ ] Load, security, accessibility, and regression tests.
- [ ] Shared-hosting compatibility matrix.
- [ ] Installation and operator documentation.
- [ ] Production readiness review.

**Exit criteria:** Release candidate passes defined acceptance suite; unsupported features and provider prerequisites are documented.

## Definition of done

A feature is complete only when UI, data model, permissions, validation, failure handling, logs, tests, documentation, and upgrade behavior are present. Mock screens and simulated provider success do not count as production functionality.