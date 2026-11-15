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
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
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

// Compare the SQL migration path with the retained legacy PHP migration path
// on an independent clean schema. This remains an ephemeral CI-only database.
$legacyDatabase = 'hostpilot_legacy_ci';
$pdo->exec('CREATE DATABASE ' . $legacyDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$legacy = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $legacyDatabase),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]
);
$legacy->exec("CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL, first_name VARCHAR(80) NULL, last_name VARCHAR(80) NULL,
    email VARCHAR(190) NOT NULL, username VARCHAR(80) NOT NULL, password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'customer', status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_email (email), UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$legacy->exec("CREATE TABLE migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL, batch INT UNSIGNED NOT NULL, applied_at DATETIME NOT NULL,
    UNIQUE KEY uq_migrations_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$seedUser = $legacy->prepare("INSERT INTO users (name,first_name,last_name,email,username,password_hash,role,status,created_at,updated_at) VALUES ('Ada Lovelace',NULL,NULL,:email,:username,'test-hash','admin','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$seedUser->execute([':email' => 'ada@example.test', ':username' => 'ada-admin']);
$pdo->prepare("INSERT INTO users (name,first_name,last_name,email,username,password_hash,role,status,created_at,updated_at) VALUES ('Ada Lovelace',NULL,NULL,:email,:username,'test-hash','admin','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':email' => 'ada@example.test', ':username' => 'ada-admin']);

require_once dirname(__DIR__) . '/app/Core/Schema.php';
require_once dirname(__DIR__) . '/app/Core/PasswordResetMigration.php';
require_once dirname(__DIR__) . '/app/Core/AdminProfileMigration.php';
\HostPilot\Core\Schema::migrate($legacy);
\HostPilot\Core\PasswordResetMigration::migrate($legacy);
\HostPilot\Core\AdminProfileMigration::migrate($legacy);

$schemaSignature = static function (PDO $connection): array {
    $tables = $connection->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT IN ('users','migrations') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $signature = [];
    foreach ($tables as $table) {
        $columns = $connection->prepare("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLUMN_KEY,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name ORDER BY ORDINAL_POSITION");
        $columns->execute([':table_name' => $table]);
        $columnRows = $columns->fetchAll(PDO::FETCH_ASSOC);
        $columns->closeCursor();

        $indexes = $connection->prepare("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name ORDER BY INDEX_NAME,SEQ_IN_INDEX");
        $indexes->execute([':table_name' => $table]);
        $indexRows = $indexes->fetchAll(PDO::FETCH_ASSOC);
        $indexes->closeCursor();

        $foreignKeys = $connection->prepare("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=:table_name AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION");
        $foreignKeys->execute([':table_name' => $table]);
        $foreignKeyRows = $foreignKeys->fetchAll(PDO::FETCH_ASSOC);
        $foreignKeys->closeCursor();

        $signature[$table] = ['columns' => $columnRows, 'indexes' => $indexRows, 'foreign_keys' => $foreignKeyRows];
    }
    return $signature;
};

$sqlSignature = $schemaSignature($pdo);
$legacySignature = $schemaSignature($legacy);
if ($sqlSignature !== $legacySignature) {
    $sqlTables = array_keys($sqlSignature);
    $legacyTables = array_keys($legacySignature);
    $missingInSql = array_values(array_diff($legacyTables, $sqlTables));
    $missingInLegacy = array_values(array_diff($sqlTables, $legacyTables));
    throw new RuntimeException('SQL/legacy schema parity failed. Missing in SQL: ' . implode(',', $missingInSql) . '; missing in legacy: ' . implode(',', $missingInLegacy) . '. Inspect table signatures in CI logs.');
}

foreach ([$pdo, $legacy] as $connection) {
    $userCheck = $connection->query("SELECT first_name,last_name FROM users WHERE username='ada-admin'");
    $profile = $userCheck->fetch(PDO::FETCH_ASSOC);
    $userCheck->closeCursor();
    if (($profile['first_name'] ?? null) !== 'Ada' || ($profile['last_name'] ?? null) !== 'Lovelace') {
        throw new RuntimeException('Admin profile migration did not preserve the legacy name backfill.');
    }
}
if (MigrationRunner::pending($legacy, $directory) !== []) {
    throw new RuntimeException('SQL runner did not recognize all migration keys from a legacy-upgraded database.');
}
if (MigrationRunner::runPending($legacy, $directory, true) !== []) {
    throw new RuntimeException('SQL runner attempted to reapply migrations on an already-upgraded legacy database.');
}
echo 'Fresh SQL schema matches the legacy PHP schema; existing-upgrade detection and admin-name data backfill passed.' . PHP_EOL;
