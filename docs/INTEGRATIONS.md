# HostPilot — Provider Integration Research

**Status:** Initial research register v0.1  
**Rule:** HostPilot ships the adapter code. Each HostPilot operator supplies their own provider account, API credentials, server access, and any required reseller/merchant permissions.

## 1. Hosting control panels

### cPanel / WHM

- Primary API: WHM API 1 over HTTPS/JSON.
- Official docs: https://api.docs.cpanel.net/whm/introduction
- Account creation reference: https://api.docs.cpanel.net/specifications/whm.openapi/account-creation
- Suspend reference: https://api.docs.cpanel.net/specifications/whm.openapi/operation/suspendacct/
- Verified documented use cases include WHM server administration, account management, service management, account creation, and suspension.
- Implemented: WHM API 1 token authentication, HTTPS connection/version probe, and `createacct` account creation. Account credentials are sent in a POST body. TLS verification is mandatory.
- Implemented: suspend and unsuspend via WHM API `suspendacct` / `unsuspendacct`.
- Pending: termination/package changes, account details, capability discovery, and live server acceptance tests.
- Constraints: reseller ACLs, server profile, WHM version, and token permissions may limit available operations. Test using the actual configured reseller/root scope; never assume root-level access.

### DirectAdmin

- Official docs: https://docs.directadmin.dev/developer/api/
- Official docs describe two modes: newer JSON API under `/api/...` and legacy API under `/CMD_API_...`.
- The current server exposes its supported OpenAPI/Swagger description at `/static/swagger.json`.
- Implemented: HTTPS `/api/version` connection probe and legacy `CMD_ACCOUNT_USER?json=yes` account creation using Basic authentication. Use a dedicated restricted DirectAdmin Login Key rather than the main administrator password.
- Implemented: suspend/unsuspend via `CMD_API_SELECT_USERS` account-selection actions.
- Pending: capability discovery from `/static/swagger.json`, terminate/package changes, and live server acceptance tests.
- Constraints: DirectAdmin API surface evolves; endpoint availability and permission scope depend on installed version/configuration.

### Plesk

- Official REST docs: https://docs.plesk.com/en-US/obsidian/api-rpc/about-rest-api.79359
- Official API introduction: https://docs.plesk.com/en-US/obsidian/api-rpc/introduction.79358/
- Plesk documents both REST and XML APIs. REST uses HTTPS and OpenAPI; API keys are recommended by Plesk. Current REST access starts with Obsidian 18.0.79 for admin/reseller/customer roles when permission is granted.
- Plesk explicitly notes REST coverage is still limited compared with XML API. New integrations should prefer REST; use XML only for required operations not yet available in REST.
- Implemented: XML API connection probe and `webspace/add` subscription creation with a mapped Plesk service plan and configured shared IP. Authentication supports `HTTP_AUTH_LOGIN`/`HTTP_AUTH_PASSWD` or the `KEY` header; TLS verification is mandatory.
- Implemented: XML `<webspace><set>` subscription status changes for suspend/reactivate.
- Pending: REST adapter, termination/package changes, role/capability checks, and live server acceptance tests.
- Constraints: role permissions, Plesk version, platform (Linux/Windows), and endpoint coverage. Do not assume a reseller has administrator capabilities.

### Shared hosting adapter security

- Server API credentials are encrypted at rest using the installation application key (AES-256-GCM).
- Outbound calls require HTTPS, verify peer certificates and hostnames, do not follow redirects, and use bounded connection/response timeouts.
- The shared transport requires PHP cURL; credential encryption requires PHP OpenSSL.
- Product IDs must be mapped to exact remote package/service-plan names before account creation. Provider success is not assumed from an HTTP status alone; adapters inspect each panel's response.
- Current account creation is administrator-triggered. Payment-to-queue automation, scheduled workers, retry/reconciliation, and lifecycle actions are still pending.

## 2. Payment gateways

### PayTR

- Official iFrame docs: https://dev.paytr.com/iframe-api
- Official docs describe a server-side token request followed by rendering the hosted iFrame and a separate callback/notification URL receiving payment results.
- Credentials (merchant ID/key/salt) are obtained by the merchant from the PayTR merchant panel.
- Implementation scope: iFrame checkout, callback endpoint, hash/signature verification exactly per current PayTR docs, duplicate notification protection, payment transaction record, sandbox/live configuration if provided by PayTR, reconciliation logs.
- Security rule: browser redirect/query parameters are never sufficient proof of payment. Only a verified server notification or a separate authenticated verification flow may transition an invoice/order to paid.
- Open verification item: confirm current PayTR Direct API and recurring/automatic collection availability, eligibility, and contractual prerequisites before promising those features. Do not equate stored card support with a generic recurring billing permission.

### iyzico

- Official developer docs: https://docs.iyzico.com/
- Integration candidates: Checkout Form, API/3D Secure flows, refund/cancel operations, and Subscription API where enabled.
- Credentials are supplied by each HostPilot operator's own merchant account.
- Implementation scope: modular gateway adapter, sandbox/live mode, callback/token verification per exact flow, transaction mapping, refunds where permitted, duplicate callback protection, reconciliation, structured logs.
- Open verification item: Subscription product availability and merchant enablement must be checked. Recurring billing is not automatically available to every merchant or every product; do not promise it without account eligibility and current docs validation.
- Security rule: never trust a client-side success page; verify server-side state.

## 3. Domain registrars

### ResellerClub

- Official developer portal: https://www.resellerclub.com/developer-portal
- Planned operations: availability, registration, transfer, renewal, nameservers, contact details, lock, auth/EPP code, DNS where supported, TLD price sync.
- Verify reseller account, API credentials, test environment, supported TLD operation matrix, premium domain handling, and billing/credit requirements.

### OpenSRS

- Official docs: https://opensrs.com/resources/documentation
- API overview: https://opensrs.com/api/overview
- Current product overview describes a Domains + TLS/SSL API and a separate Email API; the domain API is XML-based and includes registration, renewals, transfers, WHOIS/contact updates, and domain management.
- Some Storefront API functions require an active Storefront with API access enabled and client credentials; do not assume every OpenSRS account has every API product.
- Implementation: separate XML Domains API adapter and optional Storefront API adapter only if the account's product/access matches.

### CentralNic Reseller

- Official platform: https://www.centralnicreseller.com/
- Prior branding and product naming have changed over time; confirm the current official API documentation and account portal before implementation.
- Planned adapter must be based on current official API documentation supplied by the provider account. Do not infer exact endpoint formats from older Hexonet examples without validation.

## 4. Internal integration architecture

- One independent adapter per provider.
- Provider credentials stored encrypted, masked in UI, and never returned in normal API responses.
- “Test connection” validates authentication and a harmless read-only operation.
- Capability discovery controls which UI actions are enabled.
- Each external operation writes an integration log with correlation ID, sanitized request metadata, provider reference, duration, and retryability.
- External side effects use idempotency keys where provider supports them; otherwise maintain an operation ledger and reconcile before retrying.
- All provider HTTP calls use TLS certificate verification, timeouts, response-size limits, strict host validation, and redaction.
- Webhook endpoints are provider-specific and verify each provider's documented signature/secret scheme.
- Sandbox and production credentials are separate settings.

## 5. Explicit prerequisites

The HostPilot operator must obtain:
- A licensed/authorized cPanel/WHM, DirectAdmin, or Plesk server with API access and sufficient permissions.
- PayTR and/or iyzico merchant accounts approved for the intended payment flow.
- Reseller accounts and API access for each domain registrar.
- HTTPS certificate and a supported PHP/MySQL hosting environment.
- Cron access for reliable scheduled automation, preferably cPanel Cron Jobs. The worker is configured under Admin → Automation and should run once per minute. Each request processes at most one external hosting-account action. It creates renewal invoices from each active service's next due date, uses the configured 1–30 day grace period, queues suspension only for an outstanding overdue balance, and queues reactivation after full payment when the suspension source is billing. Manual administrator suspensions are never automatically reversed. Safe pre-call failures use bounded exponential retries; ambiguous remote outcomes are held for manual review instead of being blindly replayed. The Admin → Automation queue exposes safe retry for failures known to occur before the remote call and account-creation reconciliation that requires an administrator to confirm the remote account's existence or absence. Ambiguous invoice-driven suspend/reactivate jobs now have explicit remote-applied and remote-not-applied resolutions; the applied path revalidates invoice ownership, payment balance, due date, and billing suspension ownership before changing the HostPilot service state. The intended account username and password are encrypted and retained until account-creation reconciliation completes. Admins can queue permanent account termination from Services only after typing the exact remote domain; the cron worker performs the destructive API call outside the browser request. cPanel uses WHM API 1 `removeacct`, DirectAdmin uses the documented `CMD_API_SELECT_USERS` deletion form (`confirmed=Confirm`, `delete=yes`, `select0=username`), and Plesk uses XML API `webspace/del` filtered by subscription domain. If the remote response is ambiguous, the job pauses for an administrator to verify the panel and confirm deleted/present before reconciling or retrying. Termination is irreversible and is never triggered automatically by billing.


## Hosting package changes

- cPanel/WHM uses the documented WHM API 1 `changepackage` operation with the remote account username and package name.
- Plesk uses XML API `webspace/switch-subscription`. The request requires both `plan-guid` and `plan-external-id`; the server-product mapping screen stores these separately from the package display name.
- DirectAdmin is deliberately fail-closed for package changes. DirectAdmin publishes the actual OpenAPI/Swagger contract at `/static/swagger.json` per server, but HostPilot does not guess an endpoint or mutate user configuration files. A server-specific documented operation must be verified before enabling it.
- This adapter capability layer does not yet expose a customer upgrade/downgrade workflow or billing. Those must be completed before package changes can be offered to customers.

## 6. Research sources

- WHMCS developer feature map (themes, provisioning, addon modules, payment gateways, registrars, hooks, API): https://developers.whmcs.com/
- WHMCS product documentation: https://docs.whmcs.com/
- cPanel WHM API 1: https://api.docs.cpanel.net/whm/introduction
- DirectAdmin API: https://docs.directadmin.dev/developer/api/
- Plesk REST API: https://docs.plesk.com/en-US/obsidian/api-rpc/about-rest-api.79359
- PayTR iFrame API: https://dev.paytr.com/iframe-api
- iyzico: https://docs.iyzico.com/
- OpenSRS docs: https://opensrs.com/resources/documentation
- OpenSRS API overview: https://opensrs.com/api/overview

This document is a research baseline, not a claim that adapters have already been implemented or certified. Each adapter must pass sandbox and live-account acceptance tests before being marked production-ready.
