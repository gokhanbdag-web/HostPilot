<?php
declare(strict_types=1);

use PDO;
use RuntimeException;

$host = getenv('HOSTPILOT_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('HOSTPILOT_DB_PORT') ?: 3306);
$user = getenv('HOSTPILOT_DB_USER') ?: '';
$password = getenv('HOSTPILOT_DB_PASSWORD') ?: '';
$databaseName = getenv('HOSTPILOT_INSTALL_DB_NAME') ?: '';
if ($databaseName !== 'hostpilot_installer_ci' || $user === '') {
    throw new RuntimeException('Refusing installer smoke test outside hostpilot_installer_ci.');
}

$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
$admin->exec('CREATE DATABASE ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$base = 'http://127.0.0.1:8080/';
$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
$page = file_get_contents($base, false, $context);
$headers = $http_response_header ?? [];
if (!is_string($page)) throw new RuntimeException('Installer GET request failed.');
$cookie = '';
foreach ($headers as $header) {
    if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/PHPSESSID=[^;]+/', $header, $match)) { $cookie = $match[0]; break; }
}
if ($cookie === '') throw new RuntimeException('Installer did not create a session cookie.');
if (!preg_match('/name="_token" value="([^"]+)"/', $page, $tokenMatch)) throw new RuntimeException('Installer CSRF token was not found.');

$form = http_build_query([
    '_token' => html_entity_decode($tokenMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    'business_model' => 'single',
    'db_host' => $host,
    'db_port' => (string)$port,
    'db_name' => $databaseName,
    'db_user' => $user,
    'db_pass' => $password,
    'admin_first_name' => 'CI',
    'admin_last_name' => 'Installer',
    'admin_username' => 'ci-installer',
    'admin_email' => 'ci-installer@example.test',
    'admin_pass' => 'Test-Installer-Password-2026!',
]);
$postContext = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . $cookie . "\r\nConnection: close\r\n",
    'content' => $form,
    'ignore_errors' => true,
    'timeout' => 30,
    'follow_location' => 0,
]]);
$result = file_get_contents($base, false, $postContext);
$responseHeaders = $http_response_header ?? [];
$statusLine = $responseHeaders[0] ?? '';
if (!is_string($result) || !str_contains($statusLine, '200') || !str_contains($result, 'Kurulum tamamlandı.')) {
    throw new RuntimeException('Browser installer did not complete successfully. HTTP=' . $statusLine . ' body=' . substr((string)$result, 0, 1200));
}

$root = dirname(__DIR__);
$lockFile = $root . '/storage/installed.lock';
$configFile = $root . '/storage/config.php';
if (!is_file($lockFile) || !is_file($configFile)) throw new RuntimeException('Installer did not create protected config and installation lock.');
$config = require $configFile;
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $databaseName),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
);
$migrationCount = (int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
if ($migrationCount !== 20) throw new RuntimeException('Fresh browser install did not apply all 20 shared SQL migrations; found ' . $migrationCount . '.');
$adminCheck = $db->prepare("SELECT role FROM users WHERE username='ci-installer' LIMIT 1");
$adminCheck->execute();
$role = $adminCheck->fetchColumn();
$adminCheck->closeCursor();
if ($role !== 'super_admin') throw new RuntimeException('Installer did not create the first super administrator.');
foreach (['product_groups','products','orders','services','invoices','payment_transactions','servers','email_outbox','domain_registrations','domain_renewals','password_reset_tokens'] as $table) {
    $check = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
    $check->execute([':table_name' => $table]);
    if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Fresh installer schema is missing table ' . $table . '.');
    $check->closeCursor();
}

@unlink($lockFile);
@unlink($configFile);
@unlink($configFile . '.tmp');
echo 'Browser installer smoke test passed: fresh schema, 20 SQL migrations, admin account and installation lock.' . PHP_EOL;
