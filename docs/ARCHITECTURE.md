# HostPilot — Technical Architecture

**Status:** Baseline architecture v0.1

## 1. Deployment constraints

- Native PHP; no Composer autoloader or dependency installation.
- No SSH, shell, or CLI required for install or routine use.
- MySQL 8+ and a tested compatible MariaDB target.
- PDO with prepared statements.
- Browser-based installer; root and subdirectory deployment.
- HTTPS required for production payment, login, API credentials, and callbacks.
- PHP 8.3+ initial baseline, with compatibility tests for newer supported branches as the hosting ecosystem permits.
- The installer must check PHP version, required extensions, database connectivity, writable paths, HTTPS state, and URL/subdirectory detection.

## 2. Architectural style

Use a modular monolith with clear boundaries. A modular monolith is preferable to microservices for ordinary shared hosting and single-server installs. Modules communicate through application services and domain events, not direct cross-module SQL coupling.

High-level layers:

1. **HTTP entry and routing** — front controller, route definitions, request normalization.
2. **Middleware** — session, CSRF, authentication, authorization, locale, rate limits.
3. **Controllers** — thin HTTP adapters.
4. **Application services** — use cases and transaction orchestration.
5. **Domain layer** — billing rules, order lifecycle, service lifecycle, invoice calculations.
6. **Infrastructure** — PDO repositories, mail, filesystem, HTTP client, provider adapters, queue, cache.
7. **Presentation** — storefront themes, client area, admin theme, reusable components.

## 3. Proposed repository layout

```text
/
  index.php
  .htaccess
  installer/
    index.php
    assets/
  app/
    Core/
    Http/
    Middleware/
    Modules/
      Auth/
      Customers/
      Catalog/
      Orders/
      Billing/
      Payments/
      Services/
      Servers/
      Domains/
      Support/
      KnowledgeBase/
      Cms/
      Themes/
      Monitoring/
      Reports/
      Automation/
      Integrations/
      Staff/
      Api/
    Providers/
      Hosting/
      Payments/
      Registrars/
    Themes/
      Admin/
      Client/
      Storefront/
  bootstrap/
  config/
  database/
    migrations/
    seeds/
  public/
    assets/
  resources/
    lang/
      tr/
    views/
  storage/
    cache/
    logs/
    sessions/
    uploads/
    private/
  routes/
  docs/
  tests/
```

The actual tree may be refined before implementation. Sensitive files and storage must be denied direct web access through server rules, with PHP-level safeguards as a fallback.

## 4. Request lifecycle

- Root `index.php` resolves the installation base path and loads a minimal bootstrap.
- If installation is absent, route to the browser installer.
- If installed, load configuration from a protected storage location.
- Resolve request path relative to the detected base URL; do not assume the application is at the domain root.
- Dispatch through router and middleware.
- Controller invokes an application service.
- Service validates permissions and domain rules, executes a database transaction, and calls adapters/jobs as appropriate.
- Render the selected theme or JSON response.

## 5. Subdirectory-safe URL strategy

- Store the detected installation base path after installation.
- Generate all internal URLs through a central URL helper.
- Avoid root-relative hardcoded links such as `/admin` where they would escape a subdirectory installation.
- Rewrite rules must preserve existing files/directories and route only application paths.
- Installer and runtime must work at both `https://example.com/` and `https://example.com/billing/`.
- Include test cases for nested subdirectory installation and reverse-proxy HTTPS headers.

## 6. Database and migrations

- Use InnoDB, utf8mb4, explicit foreign keys where practical, indexes for query paths, and UTC timestamps.
- Use migration files with ordered IDs and a migration ledger.
- Installer executes migrations in a transaction where supported; DDL transactional behavior varies by MySQL/MariaDB operation, so use safe idempotent migration steps and record partial failures.
- Never rely on editing a single giant schema dump for upgrades.
- Store money as integer minor units or fixed-precision DECIMAL with currency metadata; never use binary floating point for financial arithmetic.
- Store currency, tax, and exchange-rate snapshots on financial documents.

## 7. Provider adapter contracts

Each provider integration implements a shared interface plus provider-specific capability metadata. Suggested operations:

- `testConnection()`
- `capabilities()`
- `createService()`
- `suspendService()`
- `unsuspendService()`
- `terminateService()`
- `changePackage()`
- `getServiceDetails()`

Not every provider supports every operation. Return typed success/failure results with provider request IDs, sanitized messages, and retryability metadata. Do not expose secrets or raw sensitive responses in logs.

Registrar adapters and payment gateway adapters use their own domain-specific contracts; do not force them into the hosting interface.

## 8. Automation and queue

- Persistent database-backed job queue; no in-memory-only queue.
- Every job has unique ID, type, payload, status, attempts, availability time, lock/lease, and sanitized error.
- Jobs must be idempotent or use idempotency keys before external side effects.
- Retry transient errors with bounded exponential backoff and jitter.
- Permanent errors move to failed/dead-letter state for admin review.
- Cron runner invoked from cPanel cron using a PHP URL or protected endpoint compatible with shared hosting.
- A secret-protected web-trigger fallback may run bounded work when cron is unavailable, but is not equivalent to reliable cron and must include overlap protection, rate limits, and execution caps.
- Do not run long provisioning tasks inside the user's checkout HTTP request; queue them and show progress.

## 9. Dashboard and monitoring

- Chart.js in admin dashboard; aggregate data server-side and return JSON from authenticated endpoints.
- Apply role and tenant scope to every aggregate.
- Cache expensive aggregates with explicit invalidation/short TTL; don't cache sensitive per-customer data across permissions.
- Separate business metrics (billing/service records) from infrastructure telemetry.
- Monitoring collectors store check results and metric samples. Host-level CPU/RAM/disk metrics require an authorized host agent, provider telemetry API, or configured monitoring endpoint; control panel API access alone must not be assumed to provide all host metrics.
- Retention policies and sampling intervals must be configurable.

## 10. Theme and widget system

- Storefront themes implement documented template contracts.
- Widget registry defines widget type, schema, defaults, validation, allowed contexts, and renderer.
- Page layouts stored as JSON validated against a schema/version.
- Render only registered widgets; never eval/execute editor content.
- Separate theme assets and settings from business data.
- Preview uses draft layout; publish changes atomically and preserve revisions.

## 11. Security and secrets

- Encrypt provider secrets at rest using a key outside the public web root where possible; installation generates a random application key.
- Use password_hash/password_verify, secure session cookies, session ID rotation, CSRF protection, output escaping, prepared statements, upload validation, and strict authorization.
- Verify payment callbacks cryptographically/server-side according to each provider's exact protocol.
- Restrict outbound HTTP targets to prevent SSRF; validate hostnames, block private/reserved IP ranges unless explicitly configured for an internal server integration, and revalidate DNS resolution.
- Log actions without credentials, tokens, card data, or unnecessary personal information.
- See SECURITY.md.

## 12. Testing strategy

- Unit tests for money, tax, proration, billing cycles, and state transitions.
- Integration tests using local mock HTTP servers for provider adapters.
- Contract fixtures based on official provider documentation; do not use production credentials in tests.
- Installer tests for root/subdirectory, permission failures, wrong credentials, and interrupted migrations.
- End-to-end tests for cart → payment → verified callback → invoice reconciliation → provisioning job → client area service.
- Regression tests for retries, duplicate callbacks, duplicate cron execution, and concurrent state transitions.
