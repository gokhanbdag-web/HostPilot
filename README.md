# HostPilot

**HostPilot** is a standalone, self-hosted hosting billing, automation, domain-reseller, and customer-management platform designed as a commercial WHMCS alternative.

It is intended to provide three integrated experiences:

1. **Public storefront** — configurable themes, hosting/domain catalog, pricing, cart, checkout, knowledge base, FAQ, and sales pages.
2. **Client Area** — customer services, domains, invoices, payments, renewals, profile/security, and support tickets.
3. **Full-screen Admin Area** — business dashboard, billing, provisioning, server monitoring, support operations, CMS/theme builder, integrations, staff roles, reporting, and automation.

## Non-negotiable product requirements

- Native PHP + MySQL/MariaDB; PDO for database access.
- No Composer, SSH, shell, or CLI requirement for installation or normal operation.
- Browser-based first-run installer; supports both domain-root and subdirectory installations.
- Turkish language included first; translation-ready architecture.
- Multiple genuinely distinct storefront themes, not merely color variations.
- Full-screen responsive administration and a separate customer portal.
- Chart.js-powered analytics and reporting based on real persisted data.
- Built-in, customer-configurable integrations for cPanel/WHM, DirectAdmin, Plesk, PayTR, iyzico, and supported domain registrars.
- Provider credentials belong to each HostPilot installation owner; end customers of that installation do not need to write integration code.
- Safe automation: verified callbacks, idempotent jobs, audit trails, retries, and explicit capability checks.
- No claim of provider capability until verified against the provider's current API and account requirements.

## Current status

The repository now contains the initial browser-installer flow, PDO connection layer, admin authentication, and a responsive full-screen dashboard with a Chart.js customer-registration chart. The customer portal now includes registration/login, a client dashboard, customer-owned support tickets with threaded replies, and a public knowledge base listing. Staff can review, answer, close, and reopen tickets from the admin area. The initial product catalogue, product groups, recurring price-cycle records, storefront product pages, pending order creation, customer order history, and staff order list are now scaffolded. Payment capture, tax/invoice generation, service activation, provisioning, registrar automation, storefront themes, visual builder, and infrastructure monitoring remain under development and are not production-ready.

## Documentation

- [Product specification](docs/PRODUCT-SPEC.md)
- [Technical architecture](docs/ARCHITECTURE.md)
- [Integration research and contracts](docs/INTEGRATIONS.md)
- [Security baseline](docs/SECURITY.md)
- [Implementation roadmap](docs/ROADMAP.md)

## Target deployment

Shared hosting / cPanel-compatible deployment, with PHP 8.3+ as the initial baseline (subject to the hosting environment's available extensions), MySQL 8.0+ or a tested compatible MariaDB release, HTTPS, and a browser-accessible installer. Scheduled automation will use a documented cPanel cron entry where available; a constrained web-trigger fallback will not be represented as equivalent to a real scheduler.

## License

License and commercial distribution terms are to be decided before the first public release.