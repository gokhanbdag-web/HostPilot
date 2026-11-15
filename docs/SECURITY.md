# HostPilot — Security Baseline

**Status:** Initial baseline v0.1

## 1. Security objectives

HostPilot stores customer personal data, billing records, server credentials, registrar credentials, and payment integration secrets. Security controls are core product functionality, not optional polish.

Use OWASP ASVS as a verification framework (target Level 2 for the application), plus current provider-specific integration requirements.

Reference: https://owasp.org/www-project-application-security-verification-standard/

## 2. Authentication and sessions

- Use `password_hash()` and `password_verify()`; rehash when needed.
- Enforce secure, HttpOnly, SameSite cookies; Secure only under HTTPS.
- Regenerate session IDs after authentication and privilege changes.
- Idle and absolute session expiry.
- CSRF tokens for state-changing browser requests.
- Rate-limit login, password reset, verification, ticket attachment, and sensitive actions.
- Generic authentication errors to reduce account enumeration.
- Optional MFA with recovery codes and auditable enrollment/reset.
- Staff accounts must support least privilege and role-based permissions.

## 3. Authorization

- Deny by default.
- Check permissions server-side for every admin action and every customer-owned resource.
- Prevent insecure direct object references by scoping reads and writes to the authenticated customer/tenant.
- Separate staff roles, support departments, and sensitive billing/server permissions.
- Require re-authentication or additional confirmation for destructive or high-risk actions.

## 4. Database and input handling

- PDO prepared statements for values; allow-list dynamic identifiers.
- Validate at boundaries; normalize domain names, emails, currency codes, and URLs.
- Context-aware output escaping.
- Transactions for financial state transitions and order/payment workflows.
- No secrets or payment card data stored in application logs.
- Avoid storing raw card PAN/CVV; use gateway-hosted checkout/tokenization according to provider rules.

## 5. Secrets and integrations

- Encrypt provider credentials at rest using authenticated encryption (e.g. sodium when available) with an application key stored outside public web root.
- Installer must generate a strong random application key and explain secure backup/rotation.
- Mask secrets in UI; write-only reveal/update semantics where practical.
- Restrict outbound requests to approved provider hosts and configured server hosts.
- Defend against SSRF: reject localhost/private/reserved IPs by default, validate DNS resolution and redirects, and require explicit admin approval for private network targets used for a hosting control panel.
- Enforce TLS verification, connect/read timeouts, response-size limits, and safe redirect policy.
- Redact authorization headers, tokens, passwords, signatures, and personal data from logs.

## 6. Payment callbacks and financial integrity

- Verify callback signature/hash exactly according to current provider docs.
- Validate amount, currency, merchant/order reference, and expected invoice/order state.
- Make callbacks idempotent; duplicate notifications must not duplicate payment or provisioning.
- Do not mark an invoice paid from a browser return URL alone.
- Store immutable gateway transaction references and reconciliation history.
- Refunds and manual payment adjustments require permission and audit reason.

## 7. File uploads

- Allow-list extensions and MIME types; do not trust client filename or Content-Type.
- Randomize stored names; store outside public web root where possible.
- Enforce file size/count limits and scan where hosting permits.
- Prevent executable uploads and path traversal.
- Serve private ticket attachments through an authorization-checked controller.

## 8. Installer and filesystem

- Installer is available only when installation lock/config is absent.
- Validate database connection and filesystem permissions before writing.
- Generate secrets using a cryptographically secure random source.
- Write config atomically; restrict config, storage, logs, backups, and private uploads from direct web access.
- Create a lock marker after successful install and disable installer routes.
- Provide a diagnostic page that does not reveal secrets or server environment details.
- Support subdirectory installs without weakening access controls.

## 9. Logging and audit

- Separate application errors, security events, integration calls, payment events, and admin audit events.
- Include correlation IDs and timestamps.
- Never log passwords, API keys, payment card data, full authorization headers, or unredacted secrets.
- Audit staff login, permission changes, customer data exports, server actions, invoice adjustments, refunds, and integration credential changes.
- Protect logs against direct web access and define retention controls.

## 10. Operational security

- Keep dependencies minimal; no Composer dependency at runtime.
- Pin and review any vendored third-party JS/CSS libraries; record version and license.
- Use Content Security Policy where practical, secure headers, and HTTPS.
- Provide backup/restore and application-key recovery guidance.
- Keep admin notifications for failed jobs, suspicious logins, and repeated webhook verification failures.
- Security testing is required before production release; this baseline is not a certification.


## 11. Implemented authentication/session baseline (Stage 2)

- Login attempts are throttled atomically by a hashed client-IP bucket (30 attempts per 15-minute window). The raw IP and login identifier are not written to rate-limit files.
- Rate-limit state is stored under the protected storage directory with file locking for shared-host concurrency. If the directory cannot be written, login fails closed.
- Authenticated sessions expire after 30 minutes of inactivity or 12 hours absolute lifetime. Existing sessions receive timestamps on their first request after rollout.
- Session IDs are regenerated after successful login; the CSRF token is rotated. Logout clears session data and expires the session cookie.
- These controls are an initial security slice, not a substitute for centralized RBAC, MFA, audit trails, or full session revocation.


## 12. Central admin route authorization (Stage 2)

- Admin requests pass through a centralized, deny-by-default permission map before controller dispatch.
- `super_admin` and `admin` retain the full administrative capability set; `staff` receives an explicit least-privilege allow-list for dashboard, customer/report viewing, support, catalogue, order/invoice viewing, and logout.
- Unknown admin routes and unsupported HTTP methods are denied. Existing controller-level checks and CSRF validation remain in place as defense in depth.
- Permission grants are currently code-defined; editable per-staff permissions and support-department scoping remain future Stage 2 work.
