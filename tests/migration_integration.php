<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Core/MigrationRunner.php';
require dirname(__DIR__) . '/app/Core/DatabaseBackup.php';
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

$pdo->exec("CREATE TABLE tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(24) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(80) NOT NULL DEFAULT 'General Support',
    subject VARCHAR(190) NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    last_reply_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_tickets_number (ticket_number),
    KEY idx_tickets_user_status (user_id,status),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE ticket_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    message LONGTEXT NOT NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_ticket_messages_ticket (ticket_id,created_at),
    CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->prepare("INSERT INTO users (name,first_name,last_name,email,username,password_hash,role,status,created_at,updated_at) VALUES ('Ada Lovelace',NULL,NULL,:email,:username,'test-hash','admin','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    ->execute([':email' => 'ada@example.test', ':username' => 'ada-admin']);

$pdo->exec("INSERT INTO users (name,email,username,password_hash,role,status,created_at,updated_at) VALUES ('Grace Hopper','grace@example.test','grace-staff','test-hash','staff','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");

$directory = dirname(__DIR__) . '/database/migrations';
$files = MigrationRunner::discover($directory);
if (count($files) !== 27) throw new RuntimeException('Expected 27 SQL migrations; discovered ' . count($files) . '.');

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
if (count($applied) !== 27) throw new RuntimeException('Expected 27 applied migrations; got ' . count($applied) . '.');
$appliedCount = (int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
if ($appliedCount !== 27) throw new RuntimeException('Expected exactly 27 migration tracking rows.');
if (MigrationRunner::pending($pdo, $directory) !== []) throw new RuntimeException('Pending migrations remain after successful run.');
if (MigrationRunner::runPending($pdo, $directory, true) !== []) throw new RuntimeException('Second run was not a no-op.');

if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='email_verified_at'")->fetchColumn() !== 1) throw new RuntimeException('Email verification migration did not add email_verified_at to a legacy users table.');
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'")->fetchColumn() !== 1) throw new RuntimeException('Session security migration did not add auth_version to a legacy users table.');
$requiredTables = ['product_groups','products','product_pricing','orders','order_items','services','invoices','invoice_items','payment_transactions','servers','provisioning_jobs','service_package_changes','email_outbox','domain_registrations','domain_renewals','password_reset_tokens','staff_permissions','staff_departments','email_verification_tokens','security_audit_logs', 'admin_mfa', 'admin_mfa_recovery_codes','privacy_requests','ticket_attachments'];
$check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
foreach ($requiredTables as $table) {
    $check->execute([':table_name' => $table]);
    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Expected table was not created: ' . $table);
}
if ((int)$pdo->query("SELECT COUNT(*) FROM staff_permissions sp JOIN users u ON u.id=sp.user_id WHERE u.username='grace-staff'")->fetchColumn() !== 8) throw new RuntimeException('Existing staff permission defaults were not seeded.');
if ((int)$pdo->query("SELECT COUNT(*) FROM staff_departments sd JOIN users u ON u.id=sd.user_id WHERE u.username='grace-staff'")->fetchColumn() !== 4) throw new RuntimeException('Existing staff department defaults were not seeded.');

$pdo->exec("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES('Verification Test','verify@example.test','verify-test','test-hash','customer','pending_verification',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$verificationUserId=(int)$pdo->lastInsertId();
$verificationToken=bin2hex(random_bytes(32));
$verificationHash=hash('sha256',$verificationToken);
$pdo->prepare('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at,created_at) VALUES(:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP())')->execute([':user'=>$verificationUserId,':hash'=>$verificationHash]);
$verify=$pdo->prepare("SELECT user_id FROM email_verification_tokens WHERE token_hash=:hash AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()");
$verify->execute([':hash'=>$verificationHash]);
if ((int)$verify->fetchColumn() !== $verificationUserId) throw new RuntimeException('A fresh hashed email verification token was not accepted.');
$pdo->prepare("UPDATE users SET status='active',email_verified_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_verification'")->execute([':id'=>$verificationUserId]);
$pdo->prepare('UPDATE email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>$verificationUserId]);
$verify->execute([':hash'=>$verificationHash]);
if ($verify->fetchColumn() !== false) throw new RuntimeException('A consumed email verification token remained reusable.');
$verified=$pdo->prepare("SELECT status,email_verified_at FROM users WHERE id=:id");
$verified->execute([':id'=>$verificationUserId]);
$verifiedRow=$verified->fetch(PDO::FETCH_ASSOC);
if (($verifiedRow['status']??'')!=='active'||empty($verifiedRow['email_verified_at'])) throw new RuntimeException('Email verification did not activate the pending customer.');
echo 'MySQL/MariaDB migration integration passed: 27 migrations, staff defaults, partial-DDL retry, confirmation guard, tracking and idempotent second run.' . PHP_EOL;

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
$legacy->exec("CREATE TABLE tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(24) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    department VARCHAR(80) NOT NULL DEFAULT 'General Support',
    subject VARCHAR(190) NOT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    last_reply_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_tickets_number (ticket_number),
    KEY idx_tickets_user_status (user_id,status),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$legacy->exec("CREATE TABLE ticket_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    message LONGTEXT NOT NULL,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_ticket_messages_ticket (ticket_id,created_at),
    CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$seedUser = $legacy->prepare("INSERT INTO users (name,first_name,last_name,email,username,password_hash,role,status,created_at,updated_at) VALUES ('Ada Lovelace',NULL,NULL,:email,:username,'test-hash','admin','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$seedUser->execute([':email' => 'ada@example.test', ':username' => 'ada-admin']);

require_once dirname(__DIR__) . '/app/Core/Schema.php';
require_once dirname(__DIR__) . '/app/Core/PasswordResetMigration.php';
require_once dirname(__DIR__) . '/app/Core/AdminProfileMigration.php';
\HostPilot\Core\Schema::migrate($legacy);
\HostPilot\Core\PasswordResetMigration::migrate($legacy);
\HostPilot\Core\AdminProfileMigration::migrate($legacy);

$schemaSignature = static function (PDO $connection): array {
    $tables = $connection->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME NOT IN ('users','migrations','staff_permissions','staff_departments','email_verification_tokens','security_audit_logs','admin_mfa','admin_mfa_recovery_codes','privacy_requests','ticket_attachments') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
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
    $differences = [];
    foreach (array_unique(array_merge(array_keys($sqlSignature), array_keys($legacySignature))) as $table) {
        if (!isset($sqlSignature[$table]) || !isset($legacySignature[$table])) {
            $differences[$table] = ['sql_exists' => isset($sqlSignature[$table]), 'legacy_exists' => isset($legacySignature[$table])];
            continue;
        }
        foreach (['columns', 'indexes', 'foreign_keys'] as $kind) {
            if ($sqlSignature[$table][$kind] !== $legacySignature[$table][$kind]) {
                $differences[$table][$kind] = ['sql' => $sqlSignature[$table][$kind], 'legacy' => $legacySignature[$table][$kind]];
            }
        }
    }
    throw new RuntimeException('SQL/legacy schema parity failed: ' . json_encode($differences, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

foreach ([$pdo, $legacy] as $connection) {
    $userCheck = $connection->query("SELECT first_name,last_name FROM users WHERE username='ada-admin'");
    $profile = $userCheck->fetch(PDO::FETCH_ASSOC);
    $userCheck->closeCursor();
    if (($profile['first_name'] ?? null) !== 'Ada' || ($profile['last_name'] ?? null) !== 'Lovelace') {
        throw new RuntimeException('Admin profile migration did not preserve the legacy name backfill.');
    }
}
$legacyPending = MigrationRunner::pending($legacy, $directory);
if ($legacyPending !== ['20261115_0002_staff_permissions_departments', '20261115_0003_customer_email_verification', '20261115_0004_user_auth_version', '20261115_0005_security_audit_logs', '20261116_0001_admin_mfa', '20261116_0002_privacy_requests', '20261116_0003_ticket_attachments']) {
    throw new RuntimeException('SQL runner did not isolate the new staff permission migration on a legacy-upgraded database: ' . json_encode($legacyPending));
}
$legacyApplied = MigrationRunner::runPending($legacy, $directory, true);
if ($legacyApplied !== ['20261115_0002_staff_permissions_departments', '20261115_0003_customer_email_verification', '20261115_0004_user_auth_version', '20261115_0005_security_audit_logs', '20261116_0001_admin_mfa', '20261116_0002_privacy_requests', '20261116_0003_ticket_attachments']) {
    throw new RuntimeException('SQL runner did not apply only the pending Stage 2 security migrations to a legacy-upgraded database.');
}
if (MigrationRunner::pending($legacy, $directory) !== []) {
    throw new RuntimeException('Pending migrations remain after legacy database upgrade.');
}
echo 'Fresh SQL schema matches the legacy PHP schema; the pending Stage 2 security migrations upgrade legacy installs without replaying old migrations, and admin-name data backfill passed.' . PHP_EOL;

// Verify backup discovery, integrity checking, and the production restore engine on a fresh disposable database.
$backupStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hostpilot-backup-ci-' . bin2hex(random_bytes(4));
if (!mkdir($backupStorage, 0700, true)) throw new RuntimeException('Unable to create temporary backup test directory.');
$backup = \HostPilot\Core\DatabaseBackup::create($pdo, $backupStorage);
$compressedBackup = file_get_contents($backup['path']);
$backupSql = is_string($compressedBackup) ? gzdecode($compressedBackup) : false;
if (!is_string($backupSql) || $backup['size'] < 32 || !hash_equals($backup['sha256'], (string)hash_file('sha256', $backup['path']))) {
    throw new RuntimeException('Compressed database backup failed integrity verification.');
}
$listedBackups = \HostPilot\Core\DatabaseBackup::listBackups($backupStorage);
if (count($listedBackups) !== 1 || !$listedBackups[0]['valid'] || $listedBackups[0]['filename'] !== $backup['filename'] || !hash_equals($backup['sha256'], $listedBackups[0]['sha256'])) {
    throw new RuntimeException('Private backup listing did not validate the newly created backup.');
}
$restoreDatabase = 'hostpilot_restore_ci';
$pdo->exec('CREATE DATABASE ' . $restoreDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$restore = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $restoreDatabase),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
);
try {
    \HostPilot\Core\DatabaseBackup::restore($restore, $backupStorage, $backup['filename'], str_repeat('0', 64));
    throw new RuntimeException('Restore accepted a mismatched backup integrity hash.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'hash does not match')) throw $exception;
}
$restoreResult = \HostPilot\Core\DatabaseBackup::restore($restore, $backupStorage, $backup['filename'], $backup['sha256']);
if (($restoreResult['filename'] ?? '') !== $backup['filename'] || (int)($restoreResult['statements'] ?? 0) < 3) {
    throw new RuntimeException('Production restore engine did not execute the complete backup statement stream.');
}
if ($schemaSignature($restore) !== $sqlSignature) throw new RuntimeException('Restored backup schema does not match the source database.');
$restoredUser = $restore->query("SELECT first_name,last_name FROM users WHERE username='ada-admin'")->fetch(PDO::FETCH_ASSOC);
if (($restoredUser['first_name'] ?? null) !== 'Ada' || ($restoredUser['last_name'] ?? null) !== 'Lovelace') {
    throw new RuntimeException('Restored backup did not preserve customer/admin data.');
}
@unlink($backup['path']);
@unlink($backupStorage . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '.htaccess');
@rmdir($backupStorage . DIRECTORY_SEPARATOR . 'backups');
@rmdir($backupStorage);
echo 'Compressed database backup integrity, private inventory, hash validation, and production restore engine passed.' . PHP_EOL;
