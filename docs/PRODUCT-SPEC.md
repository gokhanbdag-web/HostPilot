# HostPilot — Product Requirements Specification

**Status:** Baseline specification v0.1  
**Product class:** Self-hosted hosting billing, client management, provisioning, and storefront platform  
**Primary market:** Hosting providers, domain resellers, and web-service businesses

## 1. Product promise

HostPilot is a complete business platform, not just an invoice generator. Every installation includes a public sales website, a customer-facing Client Area, and a full-screen administration workspace. The product owner supplies their own provider accounts and credentials through the HostPilot admin UI; HostPilot ships the integration code.

The goal is broad functional parity with the core WHMCS use cases. Exact parity with every WHMCS add-on or every provider-specific capability is not assumed. Each feature must be implemented, documented, and tested.

## 2. Three connected interfaces

### 2.1 Public storefront

- Multiple selectable themes with distinct information architecture and page composition.
- Responsive desktop/tablet/mobile layouts.
- Hosting, reseller hosting, VPS, dedicated server, SSL, email, and domain product catalogs (availability depends on configured provider integrations).
- Product groups, configurable options, billing cycles, setup fees, promotions, coupon codes, taxes, currency display, and availability labels.
- Domain search, supported TLD suggestions, registration/transfer/renewal cart flows.
- Cart, checkout, order confirmation, payment handoff, and verified return/callback handling.
- CMS pages, menus, header/footer editor, blog/news, FAQ, knowledge base, contact page, legal pages, and announcements.
- Search-engine metadata, sitemap, canonical URLs, robots controls, and social sharing metadata.
- Visual page builder with reusable sections/widgets, theme presets, responsive preview, draft/publish, and revision history.
- All visible content and labels editable or translatable; no business copy hardcoded into templates.

### 2.2 Client Area

- Registration, login, email verification, password reset, optional MFA, profile and contact details.
- Customer dashboard with services, domains, invoices, balances/credits, tickets, announcements, and renewal alerts.
- Service details, status, renewal date, billing cycle, upgrade/downgrade request, cancellation request, and provider-specific control-panel shortcuts where safely supported.
- Domain portfolio, nameserver management, registrar lock, EPP/auth code, contact updates, and renewal options where registrar/TLD permits.
- Invoice list/detail, payment history, downloadable invoice documents, payment method management only where provider supports tokenization/recurring billing.
- Ticket creation, department selection, priority, attachments, replies, reopen/close, and full conversation history.
- FAQ and searchable knowledge base.
- Subaccounts/contact permissions, notification preferences, security/session management, API tokens if enabled.
- Clear status labels and actionable error messages.

### 2.3 Full-screen Admin Area

- Independent responsive application shell: collapsible sidebar, top bar, breadcrumbs, global search, notifications, profile menu, and full-width workspace.
- Dashboard cards and charts with date filters and drill-downs.
- Customer, staff, role, permission, product, pricing, configurable option, order, service, server, domain, registrar, invoice, transaction, tax, credit, coupon, ticket, FAQ, CMS, theme, email, automation, integration, and audit-log management.
- Server health and monitoring views.
- Payment reconciliation and transaction lookup.
- Manual actions with permission checks, confirmation, reason capture, and audit trail.
- Admin-configurable theme, branding, language, currency, tax, email, security, and system settings.

## 3. Dashboard and analytics

Use Chart.js as the primary charting library. All charts must be fed by server-side aggregates from persisted records; demo/sample data must be clearly labeled and never mixed with live business metrics.

### Financial
- Gross/net collected revenue, invoiced revenue, overdue balance, refunds, credits, taxes.
- Daily/weekly/monthly/yearly revenue trends.
- MRR and ARR with documented calculation rules and exclusions.
- New orders, paid orders, failed payments, cancellations, refunds.
- Revenue by product group, billing cycle, payment gateway, and currency.
- Customer lifetime value and average revenue per customer where data is sufficient.

### Customers and services
- Total/new/active/inactive customers.
- Active, pending, suspended, cancelled, and terminated services.
- New registrations, retention, churn with explicit cohort/time-window definition.
- Domain portfolio and upcoming renewals.
- Product/package popularity and conversion funnel where tracking consent/privacy requirements are met.

### Server monitoring
- Server availability, latency, last successful check, check history.
- CPU, memory, disk, network metrics only when the connected host exposes them through a supported and permitted mechanism.
- Service checks (e.g. HTTP, DNS, SMTP, database) as separately configured probes.
- Thresholds, alert state, incident history, and maintenance windows.
- Do not fabricate host CPU/RAM/disk telemetry from a basic control-panel API connection. If unavailable, display “not provided by this server/API” and offer an optional authenticated monitoring agent or external metrics endpoint in a later phase.

### Support and operations
- Open/awaiting/overdue tickets, first response time, resolution time, staff workload.
- Upcoming renewals, failed/queued automation jobs, webhook failures, and recent audit events.

### Reporting
- Date range and timezone filters.
- CSV export initially; PDF export after a tested server-side PDF strategy is selected.
- Role-aware report visibility and auditable exports.

## 4. Billing and commerce

- Product groups and recurring/one-time products.
- Monthly, quarterly, semiannual, annual, biennial, and configurable billing cycles.
- Setup fees, configurable options, prorations only after explicit calculation rules are implemented.
- Order lifecycle: draft/cart, pending payment, paid, provisioning, active, failed, cancelled, refunded.
- Invoice lifecycle: draft, unpaid, partially paid, paid, overdue, cancelled, refunded/credited as supported.
- Automatic invoice generation, reminders, overdue handling, suspension grace periods, and renewal automation.
- Multiple currencies with stored exchange-rate snapshots; do not rewrite historical invoice amounts after rate changes.
- Tax rules, tax-inclusive/exclusive pricing, customer tax identifiers, and configurable jurisdiction rules.
- Credit balance, refunds, partial payments, charge reconciliation, coupons, promotions, and configurable late fees.
- Immutable financial transaction records and append-only audit history for material changes.
- Invoice numbering and tax document compliance must be configurable per jurisdiction. Turkish e-Fatura/e-Arşiv issuance requires a separately verified GİB/authorized integrator route; a generated PDF alone is not an official e-document.

## 5. Provisioning and domains

- Provider-neutral product/service lifecycle and adapter contracts.
- cPanel/WHM, DirectAdmin, and Plesk adapters.
- Create, suspend, unsuspend, terminate, change package, retrieve account/service details, and test connection where API and account permissions support the operation.
- Explicit per-adapter capability discovery; unsupported operations are disabled, not simulated.
- Domain registrar adapters initially planned for ResellerClub, OpenSRS, and CentralNic Reseller, subject to current reseller account/API access.
- Domain availability, registration, transfer, renewal, nameservers, lock, auth/EPP code, contact details, DNS management where supported.
- TLD-specific restrictions, premium pricing, registry fees, grace/redemption periods, and transfer rules must be represented rather than assumed universal.

## 6. Support and knowledge

- Ticket departments, statuses, priorities, assignment, watchers, internal notes, canned responses, attachments, and response history.
- Configurable email notifications and inbound email-to-ticket only after a secure mail ingestion method is selected and tested.
- FAQ categories, searchable articles, public/private visibility, article feedback, related articles, and revision history.
- Knowledge-base search and category navigation available in storefront and Client Area.

## 7. Theme and page builder

- Themes are presentation packages, not separate business logic implementations.
- Shared, documented template contracts for product, cart, checkout, client area shell, and CMS pages.
- Multiple truly distinct storefront themes.
- Admin visual editor with section/widget library, multiple widget styles, reorder/visibility controls, responsive preview, draft/publish, and rollback.
- Theme and widget settings stored as validated structured data; never execute arbitrary PHP or untrusted JavaScript from editor fields.
- Client Area may have its own visual theme, but must preserve accessible, predictable workflows.

## 8. Extensibility and operations

- Versioned internal API with scoped API tokens and rate limits.
- Webhooks with signed payloads, delivery history, retries, and replay protection.
- Event hooks for internal modules.
- Module/adapter registry with explicit version and compatibility metadata.
- Email templates and notification channels.
- Background job queue, retry policy, dead-letter state, and admin job monitor.
- Installer, health checks, upgrade/migration runner, backup/restore guidance, diagnostics, and audit logs.

## 9. Localization and accessibility

- Turkish as the first complete locale.
- Translation files for all UI strings; locale-aware date, number, currency, and timezone formatting.
- Multi-currency support is distinct from localization.
- Keyboard-accessible controls, semantic forms, visible focus, sufficient contrast, and responsive layouts.

## 10. Acceptance principles

A module is not “complete” merely because a screen exists. It must have:
1. Database schema and migration.
2. Permission checks and server-side validation.
3. Successful and failed workflow handling.
4. Audit/logging where appropriate.
5. Responsive UI and empty/loading/error states.
6. Automated or repeatable tests.
7. Documentation and configuration instructions.
8. A clear statement of external prerequisites and provider limitations.