<?php
declare(strict_types=1);

$host = getenv('HOSTPILOT_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('HOSTPILOT_DB_PORT') ?: 3306);
$user = getenv('HOSTPILOT_DB_USER') ?: '';
$password = getenv('HOSTPILOT_DB_PASSWORD') ?: '';
$databaseName = getenv('HOSTPILOT_INSTALL_DB_NAME') ?: '';
require_once dirname(__DIR__) . '/app/Core/MigrationRunner.php';
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
$maintenanceFile = $storage . '/maintenance.lock';
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
    $admin->exec('CREATE DATABASE IF NOT EXISTS ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
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
    if ($count !== 25) throw new RuntimeException('Fresh browser install did not apply all 25 migrations; found ' . $count . '.');
    $adminCheck = $db->prepare('SELECT role FROM users WHERE username=:username LIMIT 1');
    $adminCheck->execute([':username' => $username]);
    if ($adminCheck->fetchColumn() !== 'super_admin') throw new RuntimeException('Installer did not create the first super administrator.');
    $adminCheck->closeCursor();
    foreach (['product_groups','products','orders','services','invoices','payment_transactions','servers','email_outbox','domain_registrations','domain_renewals','password_reset_tokens','admin_mfa','admin_mfa_recovery_codes'] as $table) {
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
echo 'Root browser installer passed: fresh schema, 25 migrations and first administrator.' . PHP_EOL;

// Simulate a pre-repair installation and verify the authenticated admin upgrade flow.
$db->exec('ALTER TABLE invoices DROP FOREIGN KEY fk_invoice_service');
$db->exec("DELETE FROM migrations WHERE migration='20261115_0001_invoice_service_fk_repair'");
file_put_contents($maintenanceFile, '{"status":"test"}', LOCK_EX);
[$maintenanceBody, $maintenanceHeaders] = httpRequest($base);
if (statusCode($maintenanceHeaders) !== 503) throw new RuntimeException('Maintenance mode did not block public storefront requests.');
[$upgradeRedirect, $upgradeHeaders] = httpRequest($base . 'admin/database-upgrade', 'GET', $cookie);
if (statusCode($upgradeHeaders) !== 302) throw new RuntimeException('Unauthenticated upgrade route did not redirect to admin login.');
@unlink($maintenanceFile);

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
if (is_file($maintenanceFile)) throw new RuntimeException('Successful upgrade did not clear maintenance mode.');
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
echo 'Authenticated admin upgrade passed: CSRF confirmation, pre-upgrade backup, maintenance mode and migration recovery.' . PHP_EOL;

// Exercise MFA enrollment, one-time recovery codes, TOTP login and replay protection.
require_once dirname(__DIR__) . '/app/Core/Mfa.php';
[$mfaPage, $mfaHeaders] = httpRequest($base . 'admin/security/mfa', 'GET', $cookie);
if (statusCode($mfaHeaders) !== 200 || !str_contains($mfaPage, 'Durum: Kapalı')) throw new RuntimeException('MFA settings page is unavailable after migration.');
[$startPage, $startHeaders] = httpRequest($base . 'admin/security/mfa', 'POST', $cookie, [
    '_token' => csrfFromHtml($mfaPage),
    'action' => 'start',
    'password' => 'Test-Installer-Password-2026!',
]);
if (statusCode($startHeaders) !== 200 || !preg_match('/<div class="secret">([^<]+)<\/div>/', $startPage, $secretMatch)) {
    throw new RuntimeException('MFA enrollment did not issue a manual authenticator secret.');
}
$totpSecret = html_entity_decode($secretMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$enrollmentCode = \HostPilot\Core\Mfa::codeAt($totpSecret, time());
if (!is_string($enrollmentCode)) throw new RuntimeException('Unable to generate the enrollment TOTP fixture.');
[$enabledPage, $enabledHeaders] = httpRequest($base . 'admin/security/mfa', 'POST', $cookie, [
    '_token' => csrfFromHtml($startPage),
    'action' => 'enable',
    'password' => 'Test-Installer-Password-2026!',
    'code' => $enrollmentCode,
]);
if (statusCode($enabledHeaders) !== 200 || !str_contains($enabledPage, 'Kurtarma kodlarınız') || !preg_match('/<pre class="codes">([^<]+)<\/pre>/', $enabledPage, $codesMatch)) {
    throw new RuntimeException('MFA enrollment did not verify the authenticator and display recovery codes.');
}
$recoveryCodes = preg_split('/\\s+/', trim(html_entity_decode($codesMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [];
if (count($recoveryCodes) !== 10) throw new RuntimeException('MFA enrollment did not create ten recovery codes.');
$mfaState = $db->query('SELECT secret_enc,last_used_step,enabled_at FROM admin_mfa LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!is_array($mfaState) || $mfaState['enabled_at'] === null || $mfaState['secret_enc'] === $totpSecret) throw new RuntimeException('MFA secret was not encrypted at rest.');
if ((int)$db->query('SELECT COUNT(*) FROM admin_mfa_recovery_codes WHERE used_at IS NULL')->fetchColumn() !== 10) throw new RuntimeException('MFA recovery code hashes were not stored.');
$recoveryHash = \HostPilot\Core\Mfa::recoveryCodeHash($recoveryCodes[0]);
$storedRecovery = $db->prepare('SELECT code_hash FROM admin_mfa_recovery_codes WHERE code_hash=:hash LIMIT 1');
$storedRecovery->execute([':hash' => $recoveryHash]);
if ($storedRecovery->fetchColumn() !== $recoveryHash) throw new RuntimeException('Recovery code hash was not persisted.');

[$mfaSettings, $mfaSettingsHeaders] = httpRequest($base . 'admin/security/mfa', 'GET', $cookie);
[$logoutBody, $logoutHeaders] = httpRequest($base . 'admin/logout', 'POST', $cookie, ['_token' => csrfFromHtml($mfaSettings)]);
if (statusCode($logoutHeaders) !== 302) throw new RuntimeException('Could not log out before MFA login verification.');
$cookie = '';
[$mfaLogin, $mfaLoginHeaders] = httpRequest($base . 'admin/login', 'GET', $cookie);
$cookie = cookieFromHeaders($mfaLoginHeaders);
[$challengePage, $challengeHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($mfaLogin),
    'email' => 'ci-installer@example.test',
    'password' => 'Test-Installer-Password-2026!',
]);
if (statusCode($challengeHeaders) !== 200 || !str_contains($challengePage, 'authenticator')) throw new RuntimeException('Password login bypassed the required MFA challenge.');
$newCookie = cookieFromHeaders($challengeHeaders);
if ($newCookie !== '') $cookie = $newCookie;
[$recoveryLogin, $recoveryLoginHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($challengePage),
    'code' => $recoveryCodes[0],
]);
if (statusCode($recoveryLoginHeaders) !== 302) throw new RuntimeException('A valid one-time recovery code did not complete login.');
$newCookie = cookieFromHeaders($recoveryLoginHeaders);
if ($newCookie !== '') $cookie = $newCookie;
if ((int)$db->query('SELECT COUNT(*) FROM admin_mfa_recovery_codes WHERE used_at IS NOT NULL')->fetchColumn() !== 1) throw new RuntimeException('Recovery code was not consumed after login.');

[$mfaSettings, $mfaSettingsHeaders] = httpRequest($base . 'admin/security/mfa', 'GET', $cookie);
[$logoutBody, $logoutHeaders] = httpRequest($base . 'admin/logout', 'POST', $cookie, ['_token' => csrfFromHtml($mfaSettings)]);
if (statusCode($logoutHeaders) !== 302) throw new RuntimeException('Could not log out before recovery replay test.');
$cookie = '';
[$mfaLogin, $mfaLoginHeaders] = httpRequest($base . 'admin/login', 'GET', $cookie);
$cookie = cookieFromHeaders($mfaLoginHeaders);
[$challengePage, $challengeHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($mfaLogin),
    'email' => 'ci-installer@example.test',
    'password' => 'Test-Installer-Password-2026!',
]);
$newCookie = cookieFromHeaders($challengeHeaders);
if ($newCookie !== '') $cookie = $newCookie;
[$replayedRecovery, $replayedRecoveryHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($challengePage),
    'code' => $recoveryCodes[0],
]);
if (statusCode($replayedRecoveryHeaders) !== 200 || !str_contains($replayedRecovery, 'daha önce kullanılmış')) throw new RuntimeException('A consumed recovery code was accepted a second time.');
$testStep = max(intdiv(time(), 30), (int)$mfaState['last_used_step'] + 1);
$nextStepCode = \HostPilot\Core\Mfa::codeAt($totpSecret, $testStep * 30);
[$totpLogin, $totpLoginHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($replayedRecovery),
    'code' => (string)$nextStepCode,
]);
if (statusCode($totpLoginHeaders) !== 302) {
    $usedStep = (int)$db->query('SELECT last_used_step FROM admin_mfa LIMIT 1')->fetchColumn();
    throw new RuntimeException('A valid TOTP challenge did not complete login. HTTP=' . statusCode($totpLoginHeaders) . '; expected_step=' . $testStep . '; stored_step=' . $usedStep . '; response=' . substr(strip_tags($totpLogin), 0, 240));
}

[$mfaSettings, $mfaSettingsHeaders] = httpRequest($base . 'admin/security/mfa', 'GET', cookieFromHeaders($totpLoginHeaders) ?: $cookie);
$totpCookie = cookieFromHeaders($totpLoginHeaders) ?: $cookie;
[$logoutBody, $logoutHeaders] = httpRequest($base . 'admin/logout', 'POST', $totpCookie, ['_token' => csrfFromHtml($mfaSettings)]);
if (statusCode($logoutHeaders) !== 302) throw new RuntimeException('Could not log out before TOTP replay test.');
$cookie = '';
[$mfaLogin, $mfaLoginHeaders] = httpRequest($base . 'admin/login', 'GET', $cookie);
$cookie = cookieFromHeaders($mfaLoginHeaders);
[$challengePage, $challengeHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($mfaLogin),
    'email' => 'ci-installer@example.test',
    'password' => 'Test-Installer-Password-2026!',
]);
$newCookie = cookieFromHeaders($challengeHeaders);
if ($newCookie !== '') $cookie = $newCookie;
[$replayedTotp, $replayedTotpHeaders] = httpRequest($base . 'admin/login', 'POST', $cookie, [
    '_token' => csrfFromHtml($challengePage),
    'code' => (string)$nextStepCode,
]);
if (statusCode($replayedTotpHeaders) !== 200 || !str_contains($replayedTotp, 'daha önce kullanılmış')) throw new RuntimeException('A previously accepted TOTP time step was replayed.');
echo 'MFA browser flow passed: encrypted enrollment, ten hashed recovery codes, mandatory second factor, one-time recovery, TOTP login and replay rejection.' . PHP_EOL;

// Clean up the test-only MFA fixture before continuing installer failure/retry cases.
$db->exec('DELETE FROM admin_mfa WHERE user_id = (SELECT id FROM users WHERE username = "ci-installer")');
cleanupInstallFiles($storage);


// Invalid database credentials must fail closed without leaving an install lock or config.
[$badPage, $badHeaders] = httpRequest($base);
$badCookie = cookieFromHeaders($badHeaders);
[$badResult, $badResponseHeaders] = httpRequest($base, 'POST', $badCookie, [
    '_token' => csrfFromHtml($badPage),
    'business_model' => 'single',
    'db_host' => $host,
    'db_port' => (string)$port,
    'db_name' => 'hostpilot_missing_db_ci',
    'db_user' => $user,
    'db_pass' => $password,
    'admin_first_name' => 'CI',
    'admin_last_name' => 'Failure',
    'admin_username' => 'ci-failure',
    'admin_email' => 'ci-failure@example.test',
    'admin_pass' => 'Test-Installer-Password-2026!',
]);
if (statusCode($badResponseHeaders) !== 200 || !str_contains($badResult, 'Kurulum tamamlanamadı.')) {
    throw new RuntimeException('Invalid database connection did not produce a safe installer error.');
}
if (is_file($lockFile) || is_file($configFile)) throw new RuntimeException('Failed installer attempt left an active config or installation lock.');
echo 'Invalid database connection failure path passed: no config or install lock left behind.' . PHP_EOL;

// Simulate a partially-created schema, then verify the browser installer can retry idempotently.
$partialDatabase = 'hostpilot_installer_partial_ci';
$admin->exec('CREATE DATABASE IF NOT EXISTS ' . $partialDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partial = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $partialDatabase), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
]);
$firstMigration = file_get_contents(dirname(__DIR__) . '/database/migrations/20261111_0001_catalog_orders_services.sql');
if (!is_string($firstMigration)) throw new RuntimeException('Unable to read first migration for partial-install fixture.');
$firstStatements = \HostPilot\Core\MigrationRunner::splitStatements($firstMigration);
$partial->exec($firstStatements[0]);
cleanupInstallFiles($storage);
[$partialCookie, $partialDb] = installBrowserApp($admin, $host, $port, $user, $password, $partialDatabase, $base, 'ci-partial', 'ci-partial@example.test', '');
if ((int)$partialDb->query('SELECT COUNT(*) FROM migrations')->fetchColumn() !== 24) {
    throw new RuntimeException('Installer did not recover from a partially-created schema.');
}
cleanupInstallFiles($storage);
echo 'Partial-install retry passed: installer completed all 24 migrations after an interrupted first DDL.' . PHP_EOL;

// Verify root/subdirectory-aware browser installation independently.
$subdirectory = $root . '/ci-subdir';
if (!mkdir($subdirectory, 0750, true)) throw new RuntimeException('Unable to create temporary subdirectory test fixture.');
file_put_contents($subdirectory . '/index.php', "<?php require dirname(__DIR__) . '/index.php';\n");
$subDatabase = 'hostpilot_installer_subdir_ci';
[$subCookie, $subDb] = installBrowserApp($admin, $host, $port, $user, $password, $subDatabase, 'http://127.0.0.1:8080/ci-subdir/', 'ci-subdir', 'ci-subdir@example.test', '/ci-subdir');
if ((int)$subDb->query('SELECT COUNT(*) FROM migrations')->fetchColumn() !== 24) throw new RuntimeException('Subdirectory installer did not apply all SQL migrations.');
cleanupInstallFiles($storage);
@unlink($subdirectory . '/index.php');
@rmdir($subdirectory);
echo 'Subdirectory browser installer passed: base-path configuration and 25 SQL migrations.' . PHP_EOL;
