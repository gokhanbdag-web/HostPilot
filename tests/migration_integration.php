<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Core/MigrationRunner.php';
use HostPilot\Core\MigrationRunner;

$host = getenv('HOSTPILOT_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('HOSTPILOT_DB_PORT') ?: 3306);
$name = getenv('HOSTPILOT_DB_NAME') ?: '';
$user = getenv('HOSTPILOT_DB_USER') ?: '';
$password = getenv('HOSTPILOT_DB_PASSWORD') ?: '';
if ($name !== 'hostpilot_ci' || $user === '') {
    throw new RuntimeException('Refusing to run migration integration tests outside the dedicated hostpilot_ci database.');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL, first_name VARCHAR(80) NULL, last_name VARCHAR(80) NULL,
    email VARCHAR(190) NOT NULL, username VARCHAR(80) NOT NULL, password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'customer', status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email), UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL, batch INT UNSIGNED NOT NULL, applied_at DATETIME NOT NULL,
    UNIQUE KEY uq_migrations_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$directory = dirname(__DIR__) . '/database/migrations';
$files = MigrationRunner::discover($directory);
if (count($files) !== 19) throw new RuntimeException('Expected 19 SQL migrations; discovered ' . count($files) . '.');

// Simulate interruption after the first DDL statement; the migration must safely retry.
$firstSql = file_get_contents($files[0]['path']);
if ($firstSql === false) throw new RuntimeException('Unable to read first migration.');
$firstStatements = MigrationRunner::splitStatements($firstSql);
if ($firstStatements === []) throw new RuntimeException('First migration is empty.');
$pdo->exec($firstStatements[0]);

try {
    MigrationRunner::runPending($pdo, $directory, false);
    throw new RuntimeException('Runner accepted execution without explicit confirmation.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'Explicit administrator confirmation')) throw $exception;
}
$applied = MigrationRunner::runPending($pdo, $directory, true);
if (count($applied) !== 19) throw new RuntimeException('Expected 19 applied migrations; got ' . count($applied) . '.');
$appliedCount = (int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
if ($appliedCount !== 19) throw new RuntimeException('Expected exactly 19 migration tracking rows.');
if (MigrationRunner::pending($pdo, $directory) !== []) throw new RuntimeException('Pending migrations remain after successful run.');
if (MigrationRunner::runPending($pdo, $directory, true) !== []) throw new RuntimeException('Second run was not a no-op.');

$requiredTables = ['product_groups','products','product_pricing','orders','order_items','services','invoices','invoice_items','payment_transactions','servers','provisioning_jobs','service_package_changes','email_outbox','domain_registrations','domain_renewals','password_reset_tokens'];
$check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
foreach ($requiredTables as $table) {
    $check->execute([':table_name' => $table]);
    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Expected table was not created: ' . $table);
}
echo 'MySQL/MariaDB migration integration passed: 19 migrations, partial-DDL retry, confirmation guard, tracking and idempotent second run.' . PHP_EOL;
