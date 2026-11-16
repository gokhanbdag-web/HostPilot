# HostPilot Production Readiness and Release Gates

**Status:** Release checklist; not a production approval or certification.

This document separates automated repository verification from checks that require a real hosting environment, merchant credentials, or an operator's confirmation. A green GitHub Actions run does not prove that a webhook deployment completed or that a live payment/provider integration works.

## 1. Automated checks required for every release

- [ ] The pull request targets `main`; all required GitHub Actions jobs pass.
- [ ] PHP syntax and deployment-safety checks pass on PHP 8.2 and 8.3.
- [ ] SQL migration integration passes on MySQL 8.0 and MariaDB 10.11, on both supported PHP versions.
- [ ] Authenticated HTTP security integration tests pass.
- [ ] Browser installer smoke tests pass for domain-root and subdirectory installation, including interrupted-install recovery and authenticated upgrade.
- [ ] No Composer manifest/lockfile or tracked runtime configuration, installation lock, API secret, or customer data is introduced.
- [ ] The release version and `UPDATE_NOTES.md` describe the same shipped change set.

## 2. Deployment verification (after merge to main)

The existing deployment mechanism is the authenticated server-side GitHub webhook. The GitHub quality workflow includes a live health check for pushes to `main`; it polls `https://kodvadisi.com.tr/hostpilot/health` and requires HTTP 200 plus a JSON `status=healthy` response.

- [ ] Confirm the main-branch push workflow completed successfully, including **Live deployment health check**.
- [ ] Confirm the deployment log identifies the expected commit/release version.
- [ ] Open the public storefront and `/health` in a browser; verify the response is healthy and does not disclose configuration, secrets, database credentials, or filesystem paths.
- [ ] Open the admin login and customer login pages; verify there is no redirect loop, PHP warning, HTTP 500, or mixed-content error.
- [ ] Verify the installed application version shown by the application matches the released `VERSION` file.
- [ ] Confirm the live database and `storage/` contents were preserved by deployment. Do not replace installation-specific configuration or customer data with repository files.

If the live-health job is absent, skipped, or fails, the release is **not deployment-verified**. Do not infer deployment success from pull-request CI.

## 3. Existing-install upgrade acceptance

Perform these checks on a disposable copy or staging installation before approving a production upgrade:

- [ ] Take a database backup using the application backup workflow and verify its integrity.
- [ ] Confirm the application reports the exact pending migration list before approval.
- [ ] Run the upgrade through the authenticated browser-based admin upgrade page; do not require SSH, Composer, or CLI commands.
- [ ] Confirm maintenance mode is cleared after success and the migration history contains each migration exactly once.
- [ ] Reload the upgrade page and confirm there are no pending migrations.
- [ ] Confirm a failed/interrupted migration can be safely retried and that the pre-upgrade backup remains available.
- [ ] Re-test admin and customer login, session revocation, customer MFA (enabled and disabled accounts), and staff permission boundaries.
- [ ] Confirm customer invoices, payment history, support tickets, domains, and hosting services remain associated with the correct customer.

Never test migrations against the only production database. A backup is not considered verified until its restore has been rehearsed on a disposable database.

## 4. Commercial and provider acceptance (credentials required)

These checks cannot be proven by local CI fixtures alone:

- [ ] PayTR merchant sandbox: initialize checkout, complete a test payment, verify callback signature/amount/currency/reference, and deliver the same callback more than once; exactly one payment must be recorded.
- [ ] iyzico merchant sandbox: initialize Checkout Form, retrieve the result, verify provider signatures and invoice settlement; repeat retrieval/callback scenarios without duplicate settlement.
- [ ] SMTP: send a test message through the actual mail host and verify TLS, sender identity, queue delivery, retry, and failure visibility.
- [ ] Hosting panels: use disposable accounts and least-privilege API users to verify create/suspend/reactivate flows and ambiguous-timeout manual review on each supported panel.
- [ ] Registrar: use ResellerClub sandbox credentials and a funded test account; verify registration and nameserver stages, polling, and manual review of ambiguous outcomes.
- [ ] Verify webhook and cron scheduling from the hosting control panel. Tokens must be sent in the Authorization header, never in query strings.
- [ ] Confirm provider terms, merchant approval, tax/accounting requirements, privacy notices, retention obligations, and applicable legal e-invoice integration before commercial launch.

Do not enable live payment, registrar, or automated provisioning operations until the relevant provider's sandbox acceptance has passed for the actual merchant account and endpoint configuration.

## 5. Shared-hosting compatibility acceptance

Use representative customer hosting accounts, not only the GitHub Actions containers:

- [ ] Install from a clean subdirectory using the browser installer with database details and first administrator credentials.
- [ ] Confirm the host supplies PHP 8.2+ and the required PDO MySQL, OpenSSL, and zlib extensions; confirm cURL for integrations.
- [ ] Confirm HTTPS, secure session cookies, writable protected storage, and denied direct HTTP access to configuration, backups, logs, and private uploads.
- [ ] Confirm Apache/.htaccess or the hosting provider's equivalent forwards the Authorization header to PHP for cron workers.
- [ ] Confirm cron scheduling, mail delivery, database limits, request timeouts, and upload limits are adequate for the enabled modules.
- [ ] Confirm application installation and ordinary administration require no SSH, shell, Composer, or command-line setup.

## 6. Known release blockers and scope boundaries

The repository documentation and roadmap identify the following as unfinished or not production-proven. Do not market these as completed production capabilities until their acceptance tests exist:

- Live merchant sandbox acceptance for PayTR and iyzico; automated/partial refunds and provider refund API reconciliation.
- Real shared-hosting provider smoke-test matrix and release approval.
- Legal e-invoice/e-archive compliance, tax/accounting rules, and currency conversion.
- Full registrar framework/TLD pricing and additional registrar adapters; live registrar calls require merchant credentials and a funded test account.
- Complete storefront theme registry, CMS/page builder, and remaining customer self-service workflows.
- Financial/server telemetry, monitoring, alerts, and operational reporting.
- Application-wide centralized logging and the remaining roadmap work explicitly marked incomplete.

A release may be deployed to a controlled test installation while these items remain open, but that is not equivalent to a general commercial production-ready declaration.

## 7. Release record

For each release, record:

- Version and merged main commit:
- Pull request:
- GitHub Actions run URL and result:
- Live deployment health-check result:
- Staging host/PHP/database versions:
- Migration and restore rehearsal result:
- Provider sandbox acceptance references:
- Outstanding exceptions, owner, and target date:

Do not record a check as passed unless its result was observed. Mark unavailable external checks as **not verified**, not passed.
