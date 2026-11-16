<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/MigrationRunner.php';

use HostPilot\Core\MigrationRunner;

function expectSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$statements = MigrationRunner::splitStatements(<<<'SQL'
-- A comment with a semicolon; it must not split.
CREATE TABLE sample (id INT NOT NULL, label VARCHAR(40) DEFAULT 'a;b');
INSERT INTO sample (label) VALUES ("x;y"), ('it''s ok'); # trailing comment;
SQL);

expectSame([
    "CREATE TABLE sample (id INT NOT NULL, label VARCHAR(40) DEFAULT 'a;b')",
    "INSERT INTO sample (label) VALUES (\"x;y\"), ('it''s ok')",
], $statements, 'SQL statement splitting failed.');

try {
    MigrationRunner::splitStatements("SELECT 'unterminated;");
    fwrite(STDERR, "Unterminated SQL string was accepted." . PHP_EOL);
    exit(1);
} catch (RuntimeException) {
    // Expected.
}


$legacyMigrations = MigrationRunner::discover(dirname(__DIR__) . '/database/migrations');
$expectedLegacy = ['20261111_0001_catalog_orders_services', '20261111_0002_invoices_payments', '20261111_0003_hosting_servers', '20261111_0004_provisioning_queue', '20261111_0005_service_billing_lifecycle', '20261111_0006_provisioning_intent', '20261111_0007_remote_package_identifiers', '20261112_0008_service_package_changes', '20261112_0009_service_package_change_type', '20261112_0010_email_outbox', '20261112_0011_domain_registration_queue', '20261112_0012_domain_nameserver_queue', '20261112_0013_domain_expiry_sync', '20261112_0014_domain_renewal_queue', '20261112_0015_domain_renewal_action_tracking', '20261112_0016_domain_expiry_reminder_index', '20261112_0017_domain_expiry_sync_queue', '20261113_0016_admin_password_resets', '20261113_0017_admin_first_last_name', '20261115_0001_invoice_service_fk_repair', '20261115_0002_staff_permissions_departments', '20261115_0003_customer_email_verification', '20261115_0004_user_auth_version', '20261115_0005_security_audit_logs', '20261116_0001_admin_mfa', '20261116_0002_privacy_requests', '20261116_0003_ticket_attachments', '20261116_0004_payment_reconciliations', '20261116_0005_customer_mfa'];
$actualLegacy = array_column($legacyMigrations, 'name');
expectSame($expectedLegacy, $actualLegacy, 'SQL migration discovery/order failed.');
foreach ($legacyMigrations as $migration) {
    $sql = file_get_contents($migration['path']);
    if ($sql === false || MigrationRunner::splitStatements($sql) === []) {
        fwrite(STDERR, 'SQL migration is empty or invalid: ' . $migration['name'] . PHP_EOL);
        exit(1);
    }
}

echo "MigrationRunner parser and migration discovery tests passed." . PHP_EOL;
