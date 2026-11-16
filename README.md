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

The repository now contains the initial browser-installer flow, PDO connection layer, admin authentication, and a responsive full-screen dashboard with a Chart.js customer-registration chart. The customer portal now includes registration/login, a client dashboard, customer-owned support tickets with threaded replies, and a public knowledge base listing. Staff can review, answer, close, and reopen tickets from the admin area. The initial product catalogue, product groups, recurring price-cycle records, storefront product pages, pending order creation, customer order history, and staff order list are now scaffolded. Order-linked unpaid invoice records, invoice line snapshots, customer invoice listing, and staff invoice overview are also scaffolded. PayTR iFrame and iyzico Checkout Form initialization/retrieval handlers, administrator credential settings, encrypted credential storage, server-side callback verification, and an administrator payment transaction history view have been scaffolded. The hosting foundation now includes encrypted server registry, HTTPS connection tests, product-to-server-package mapping, and admin-triggered account creation adapters for cPanel/WHM, DirectAdmin, and Plesk XML API. Administrators can also suspend and reactivate provisioned accounts through each panel adapter. Customer service credentials are encrypted at rest and shown only to the owning customer. Customer-triggered domain capture and queued hosting account creation through a cPanel Cron Jobs worker are now implemented as an initial workflow; ambiguous remote outcomes are held for manual review. Recurring service-linked invoice generation, configurable grace periods, overdue-balance suspension, and payment-triggered billing reactivation are now implemented as an initial cron-driven lifecycle. Manual administrator suspensions remain separate and are not automatically reversed. Safe pre-call failures have bounded retries; ambiguous remote outcomes require review. The admin automation screen now lists queue jobs, allows safe retry of pre-call failures, and lets an administrator confirm whether an uncertain account creation exists before finalizing or retrying with the same encrypted credentials. Ambiguous suspend/reactivate operations require manual panel-state verification, then the Admin → Automation screen can reconcile the HostPilot record or safely requeue the operation after rechecking the invoice and billing-suspension ownership. Admins can now queue permanent hosting account termination after typing the exact domain; cPanel/WHM, DirectAdmin, and Plesk XML adapters use their documented account-removal APIs, with manual review for ambiguous responses. Termination is never automatic. Customer package upgrades for cPanel/WHM and Plesk now use prorated invoices, payment-gated queued changes, and manual reconciliation for ambiguous results. Customers can schedule a lower mapped package for the next renewal date without an immediate charge, or cancel it before the renewal queue begins. Native SMTP settings provide encrypted credentials and package-change lifecycle notifications. General invoice-created/paid/overdue, payment-failed, hosting-provisioned/suspended/reactivated, and support ticket notifications are also queued. The versioned email outbox uses idempotency keys, bounded worker batches, exponential retry backoff, stale-send manual review, and an admin queue screen; a separate authenticated cron endpoint processes deliveries. DirectAdmin package changes remain disabled pending server-specific API verification. Provider sandbox tests, payment acceptance tests, tax calculation, legal e-invoice compliance, recurring collection, refunds, reconciliation, registrar automation, storefront themes, visual builder, and infrastructure monitoring remain under development and are not production-ready.

## Documentation

- [Product specification](docs/PRODUCT-SPEC.md)
- [Technical architecture](docs/ARCHITECTURE.md)
- [Integration research and contracts](docs/INTEGRATIONS.md)
- [Security baseline](docs/SECURITY.md)
- [Implementation roadmap](docs/ROADMAP.md)

## Target deployment

Shared hosting / cPanel-compatible deployment, with PHP 8.3+ as the initial baseline (subject to the hosting environment's available extensions), PDO MySQL, OpenSSL, cURL, MySQL 8.0+ or a tested compatible MariaDB release, HTTPS, and a browser-accessible installer. Outbound hosting-panel integrations require cURL and valid remote TLS certificates. No Composer, SSH, or CLI is required for the installer. Scheduled automation will use a documented cPanel cron entry where available; a constrained web-trigger fallback will not be represented as equivalent to a real scheduler.

## License

License and commercial distribution terms are to be decided before the first public release.

**Package-change integration status:** cPanel/WHM uses WHM API 1 `changepackage`; Plesk uses `webspace/switch-subscription` with both plan GUID and external ID. DirectAdmin package switching is disabled until the connected server's own OpenAPI contract is verified. Customer-facing upgrade and scheduled downgrade workflows are implemented for cPanel/WHM and Plesk; DirectAdmin remains disabled pending API verification.


### Domain registrar (ResellerClub REST API v2)

The ResellerClub REST API v2 integration now supports customer availability lookup, domain-product checkout, payment-gated registration queueing, customer/contact creation, domain registration submission, and asynchronous action polling. Configure the numeric ResellerClub User ID and API key at `/admin/domains/registrar`, choose sandbox or live, and enable the connection. The API key is encrypted with the installation key. Customer availability lookup is `/domains/search`; domain products collect the domain and registrant address/phone details at checkout. Registrar payloads are encrypted at rest; the temporary registrar customer password is removed after customer creation and contact details are erased from the payload after contact creation. Verified full invoice payment queues registration; domain products are excluded from hosting-service provisioning. `/cron/domains?token=...` is an authenticated browser cron worker; generate its token as `hash_hmac('sha256', 'HostPilot domain cron v1', APP_KEY)`. The worker rechecks availability, saves each external stage before proceeding, submits registrations with the conservative `OnlyAdd` invoice option (so it does not silently spend the reseller wallet), and polls registrar actions. A timeout or uncertain remote side effect is moved to manual review rather than blindly retried. Customers can track `/client/domains` and request nameserver changes for active domains; nameserver requests are encrypted, queued, and applied by the domain worker using the documented `PUT /domains/{orderId}/nameservers` endpoint. Ambiguous nameserver results require administrator reconciliation. Staff can inspect `/admin/domains/orders` and reconcile uncertain registration or nameserver operations. A funded reseller account / payment of the registrar-side invoice may still be required before the registrar completes an `OnlyAdd` order. Use sandbox credentials and a funded test account before enabling live sales. Live registrar calls have not been tested without merchant credentials.


## Developer automation (SSH-free daily workflow)

Every push to `main` and every pull request targeting `main` runs the GitHub Actions workflow at `.github/workflows/quality.yml`. It checks the syntax of every tracked PHP file with PHP 8.3, enforces the no-Composer requirement, and rejects tracked installation configuration or lock files.

The production server continues to deploy through its existing authenticated server-side GitHub webhook and deployment script. Developers do not need to open SSH or run shell commands for ordinary code changes. The workflow is an automated quality check; it does not replace or claim to gate the existing server-side deployment hook. Confirm production deployment using the deployment log and live health endpoint.

Deployment safeguards:
- Keep `storage/` out of Git-based code synchronization; it contains installation-specific configuration and data.
- Never commit `storage/config.php`, `storage/installed.lock`, environment files, API keys, or customer data.
- Keep the live `storage/` directory and database untouched during code deployments.
- Verify the deployment result and `/health` after each release; a green CI check alone is not proof that production deployment succeeded.
- Keep server credentials and webhook secrets in server-side secret storage, never in repository files.

### Customer privacy erasure workflow

Customer account-erasure requests require password confirmation and an administrator approval. An approved request can be completed from `/admin/privacy`; the completion action rechecks service, provisioning, domain, renewal, payment and invoice blockers under a database transaction. Active services, unresolved registrar operations, pending/review payment attempts, unpaid or partially paid invoices, pending orders, and pending package changes prevent completion.

When eligible, HostPilot anonymizes the customer profile, replaces the login identifiers, rotates the password hash, increments `auth_version` to revoke existing sessions, removes password-reset/email-verification tokens and queued email for the old address, redacts support tickets/messages and deletes private ticket attachment files. Terminal hosting/domain records have stored credentials and contact payloads cleared. Paid invoices and successful payment evidence are deliberately retained for applicable statutory/accounting retention; the account row remains as an anonymized foreign-key anchor. This workflow is an application data-minimization control, not a substitute for a jurisdiction-specific legal retention schedule or registrar-side contact update.
