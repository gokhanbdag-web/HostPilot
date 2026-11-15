<?php
declare(strict_types=1);

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
$root = dirname(__DIR__);
$storage = $root . '/storage';
$lockFile = $storage . '/installed.lock';
$configFile = $storage . '/config.php';
$base = 'http://127.0.0.1:8080/';

function httpRequest(string $url, string $method = 'GET', string $cookie = '', array $data = []): array
{
    $headers = [];
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $options = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => 30,
        'follow_location' => 0,
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['header'] = implode("\r\n", $headers);
        $options['content'] = http_build_query($data);
    }
    $context = stream_context_create(['http' => $options]);
    $body = file_get_contents($url, false, $context);
    return [is_string($body) ? $body : '', $http_response_header ?? []];
}

function statusCode(array $headers): int
{
    return isset($headers[0]) && preg_match('/\s([0-9]{3})\s/', $headers[0], $m) ? (int)$m[1] : 0;
}

function cookieFromHeaders(array $headers): string
{
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/(PHPSESSID=[^;]+)/', $header, $match)) return $match[1];
    }
    return '';
}

function csrfFromHtml(string $html): string
{
    if (!preg_match('/name="_token" value="([^"]+)"/', $html, $match)) throw new RuntimeException('CSRF token was not found in browser page.');
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function installBrowserApp(PDO $admin, string $host, int $port, string $user, string $password, string $databaseName, string $url, string $username, string $email, string $expectedBasePath): array
{
    $admin->exec('CREATE DATABASE ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    [$page, $headers] = httpRequest($url);
    if (statusCode($headers) !== 200) throw new RuntimeException('Installer GET failed: HTTP ' . statusCode($headers));
    $cookie = cookieFromHeaders($headers);
    if ($cookie === '') throw new RuntimeException('Installer did not create a session cookie.');
    $form = [
        '_token' => csrfFromHtml($page),
        'business_model' => 'single',
        'db_host' => $host,
        'db_port' => (string)$port,
        'db_name' => $databaseName,
        'db_user' => $user,
        'db_pass' => $password,
        'admin_first_name' => 'CI',
        'admin_last_name' => 'Installer',
        'admin_username' => $username,
        'admin_email' => $email,
        'admin_pass' => 'Test-Installer-Password-2026!',
    ];
    [$result, $responseHeaders] = httpRequest($url, 'POST', $cookie, $form);
    if (statusCode($responseHeaders) !== 200 || !str_contains($result, 'Kurulum tamamlandı.')) {
        throw new RuntimeException('Browser installer failed. HTTP=' . statusCode($responseHeaders) . ' body=' . substr($result, 0, 1000));
    }
    $config = require dirname(__DIR__) . '/storage/config.php';
    if (($config['app']['base_path'] ?? null) !== $expectedBasePath) {
        throw new RuntimeException('Installer base path mismatch. Expected ' . $expectedBasePath . ', got ' . (string)($config['app']['base_path'] ?? 'null'));
    }
    $db = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $databaseName),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
    );
    $count = (int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
    if ($count !== 20) throw new RuntimeException('Fresh browser install did not apply all 20 migrations; found ' . $count . '.');
    $adminCheck = $db->prepare('SELECT role FROM users WHERE username=:username LIMIT 1');
    $adminCheck->execute([':username' => $username]);
    if ($adminCheck->fetchColumn() !== 'super_admin') throw new RuntimeException('Installer did not create the first super administrator.');
    $adminCheck->closeCursor();
    foreach (['product_groups','products','orders','services','invoices','payment_transactions','servers','email_outbox','domain_registrations','domain_renewals','password_reset_tokens'] as $table) {
        $check = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
        $check->execute([':table_name' => $table]);
        if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Fresh installer schema is missing table ' . $table . '.');
        $check->closeCursor();
    }
    return [$cookie, $db];
}

function cleanupInstallFiles(string $storage): void
{
    @unlink($storage . '/installed.lock');
    @unlink($storage . '/config.php');
    @unlink($storage . '/config.php.tmp');
    foreach (glob($storage . '/backups/*.sql.gz') ?: [] as $file) @unlink($file);
    @unlink($storage . '/backups/.htaccess');
    @rmdir($storage . '/backups');
    @unlink($storage . '/logs/migrations.log');
    @rmdir($storage . '/logs');
}

[$cookie, $db] = installBrowserApp($admin, $host, $port, $user, $password, $databaseName, $base, 'ci-installer', 'ci-installer@example.test', '');
echo 'Root browser installer passed: fresh schema, 20 migrations and first administrator.' . PHP_EOL;

// Simulate a pre-repair installation and verify the authenticated admin upgrade flow.
$db->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_invoice_service');
$db->exec("DELETE FROM migrations WHERE migration='20261115_0001_invoice_service_fk_repair'");
file_put_contents($lockFile, '{"status":"test"}', LOCK_EX);
[$maintenanceBody, $maintenanceHeaders] = httpRequest($base);
if (statusCode($maintenanceHeaders) !== 503) throw new RuntimeException('Maintenance mode did not block public storefront requests.');
[$upgradeRedirect, $upgradeHeaders] = httpRequest($base . 'admin/database-upgrade', 'GET', $cookie);
if (statusCode($upgradeHeaders) !== 302) throw new RuntimeException('Unauthenticated upgrade route did not redirect to admin login.');
@unlink($lockFile);

[$loginPage, $loginHeaders] = httpRequest($base . 'admin/login', 'GET', $cookie);
if (statusCode($loginHeaders) !== 200) throw new RuntimeException('Admin login page is unavailable.');
$loginToken = csrfFromHtml($loginPage);
[$loginResult, $loginResponseHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => $loginToken,
    'email' => 'ci-installer@example.test',
    'password' => 'Test-Installer-Password-2026!',
]);
if (statusCode($loginResponseHeaders) !== 302) throw new RuntimeException('Admin login failed during upgrade smoke test.');
$newCookie = cookieFromHeaders($loginResponseHeaders);
if ($newCookie !== '') $cookie = $newCookie;

[$upgradePage, $upgradePageHeaders] = httpRequest($base . 'admin/database-upgrade', 'GET', $cookie);
if (statusCode($upgradePageHeaders) !== 200 || !str_contains($upgradePage, '20261115_0001_invoice_service_fk_repair')) {
    throw new RuntimeException('Authenticated upgrade page did not show the pending migration.');
}
$upgradeToken = csrfFromHtml($upgradePage);
[$upgradeResult, $upgradeResponseHeaders] = httpRequest($base . 'admin/database-upgrade', 'POST', $cookie, [
    '_token' => $upgradeToken,
    'confirm_upgrade' => '1',
]);
if (statusCode($upgradeResponseHeaders) !== 302) throw new RuntimeException('Authenticated migration upgrade did not complete: ' . substr($upgradeResult, 0, 800));
if (is_file($lockFile)) throw new RuntimeException('Successful upgrade did not clear maintenance mode.');
$applied = $db->prepare("SELECT COUNT(*) FROM migrations WHERE migration='20261115_0001_invoice_service_fk_repair'");
$applied->execute();
if ((int)$applied->fetchColumn() !== 1) throw new RuntimeException('Admin upgrade did not record the pending migration.');
$applied->closeCursor();
$fk = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND CONSTRAINT_NAME='fk_invoice_service'");
$fk->execute();
if ((int)$fk->fetchColumn() !== 1) throw new RuntimeException('Admin upgrade did not restore the invoice/service foreign key.');
$fk->closeCursor();
if (count(glob($storage . '/backups/*.sql.gz') ?: []) < 1) throw new RuntimeException('Admin upgrade did not create a compressed pre-upgrade backup.');
[$currentPage, $currentHeaders] = httpRequest($base . 'admin/database-upgrade', 'GET', $cookie);
if (statusCode($currentHeaders) !== 200 || !str_contains($currentPage, 'Veritabanı güncel')) throw new RuntimeException('Upgrade page did not report a current database after success.');
cleanupInstallFiles($storage);
echo 'Authenticated admin upgrade passed: CSRF confirmation, pre-upgrade backup, maintenance mode and migration recovery.' . PHP_EOL;

// Verify root/subdirectory-aware browser installation independently.
$subdirectory = $root . '/ci-subdir';
if (!mkdir($subdirectory, 0750, true)) throw new RuntimeException('Unable to create temporary subdirectory test fixture.');
file_put_contents($subdirectory . '/index.php', "<?php require dirname(__DIR__) . '/index.php';\n");
$subDatabase = 'hostpilot_installer_subdir_ci';
[$subCookie, $subDb] = installBrowserApp($admin, $host, $port, $user, $password, $subDatabase, 'http://127.0.0.1:8080/ci-subdir/', 'ci-subdir', 'ci-subdir@example.test', '/ci-subdir');
if ((int)$subDb->query('SELECT COUNT(*) FROM migrations')->fetchColumn() !== 20) throw new RuntimeException('Subdirectory installer did not apply all SQL migrations.');
cleanupInstallFiles($storage);
@unlink($subdirectory . '/index.php');
@rmdir($subdirectory);
echo 'Subdirectory browser installer passed: base-path configuration and 20 SQL migrations.' . PHP_EOL;
