<?php
declare(strict_types=1);

/**
 * HostPilot front controller.
 * Root and subdirectory deployment are supported without Composer.
 */
define('HOSTPILOT_ROOT', __DIR__);
define('HOSTPILOT_STORAGE', HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'storage');

$lockFile = HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'installed.lock';
$configFile = HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'config.php';

if (!is_file($lockFile) || !is_file($configFile)) {
    require HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'index.php';
    exit;
}

$config = require $configFile;
if (!is_array($config) || empty($config['database'])) {
    http_response_code(500);
    exit('HostPilot configuration is invalid.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'HostPilot\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = HOSTPILOT_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

$basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) === '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => ($basePath !== '' ? $basePath : '/') . (str_ends_with($basePath, '/') ? '' : ''),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

try {
    $database = new \HostPilot\Core\Database($config['database']);
    $pdo = $database->connection();
} catch (Throwable $exception) {
    error_log('[HostPilot] Database bootstrap or migration failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('HostPilot veritabanına bağlanamıyor. Yapılandırmayı ve veritabanı erişim bilgilerini kontrol edin.');
}

\HostPilot\Core\Auth::validateSessionVersion($pdo);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '//') $requestPath = '/';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$maintenanceFile = HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'maintenance.lock';
$maintenanceAllowedPaths = ['/admin/database-upgrade', '/admin/login', '/admin/forgot-password', '/admin/reset-password', '/admin/logout'];
if (is_file($maintenanceFile) && !in_array($requestPath, $maintenanceAllowedPaths, true)) {
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bakım çalışması</title><body style="font:16px system-ui;background:#0a1020;color:#e8edf7;display:grid;place-items:center;min-height:100vh;margin:0"><main style="max-width:560px;padding:32px;text-align:center"><h1>Planlı bakım çalışması</h1><p>Veritabanı güncellemesi devam ediyor. Lütfen birkaç dakika sonra tekrar deneyin.</p></main></body></html>';
    exit;
}

if (\HostPilot\Core\Authorization::isAdminPath($requestPath)
    && !\HostPilot\Core\Authorization::isPublicRoute($requestPath)) {
    $adminUser = \HostPilot\Core\Auth::user();
    if ($adminUser === null) {
        header('Location: ' . $basePath . '/admin/login');
        exit;
    }
    if (!\HostPilot\Core\Authorization::allowsAdminRequest($requestPath, $method, $adminUser, $pdo)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bu yönetim işlemi için yetkiniz yok.';
        exit;
    }
}

if ($requestPath === '/admin/security/sessions' && in_array($method, ['GET','POST'], true)) { (new \HostPilot\Http\SessionSecurityController($pdo, $basePath))->index(); exit; }
if ($requestPath === '/admin/security/mfa' && in_array($method, ['GET','POST'], true)) { (new \HostPilot\Http\MfaController($pdo, $basePath, (string)($config['app']['key'] ?? '')))->index(); exit; }

if ($requestPath === '/admin/database-upgrade' && in_array($method, ['GET', 'POST'], true)) {
    (new \HostPilot\Http\DatabaseUpgradeController(
        $pdo,
        $basePath,
        HOSTPILOT_STORAGE,
        HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations'
    ))->index();
    exit;
}

// Demo data is optional and non-blocking. Never seed while maintenance mode is active.
if (!is_file($maintenanceFile)) {
    try {
        \HostPilot\Core\DemoSeeder::seed($pdo);
    } catch (Throwable $exception) {
        error_log('[HostPilot] Demo seed skipped: ' . $exception->getMessage());
    }
}

$client = new \HostPilot\Http\ClientController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
$passwordReset = new \HostPilot\Http\PasswordResetController($pdo, $basePath, (string)($config['app']['base_url'] ?? ''), (string)($config['app']['key'] ?? ''));
if ($requestPath === '/admin/forgot-password' && in_array($method, ['GET','POST'], true)) { $passwordReset->request(); exit; }
if ($requestPath === '/admin/reset-password' && in_array($method, ['GET','POST'], true)) { $passwordReset->reset(); exit; }
if ($requestPath === '/client/login' && in_array($method, ['GET', 'POST'], true)) { $client->login(); exit; }
if ($requestPath === '/client/register' && in_array($method, ['GET', 'POST'], true)) { $client->register(); exit; }
if ($requestPath === '/client/verify-email' && in_array($method, ['GET','POST'], true)) { $client->verifyEmail(); exit; }
if ($requestPath === '/client/resend-verification' && in_array($method, ['GET','POST'], true)) { $client->resendVerification(); exit; }
if ($requestPath === '/client/logout' && $method === 'POST') { $client->logout(); exit; }
$privacy = new \HostPilot\Http\PrivacyController($pdo, $basePath);
if ($requestPath === '/client/privacy' && in_array($method, ['GET','POST'], true)) { $privacy->customer(); exit; }
if ($requestPath === '/client/privacy/export' && $method === 'GET') { $privacy->export(); exit; }
if ($requestPath === '/admin/privacy' && in_array($method, ['GET','POST'], true)) { $privacy->admin(); exit; }
if ($requestPath === '/client' || $requestPath === '/client/') { $client->dashboard(); exit; }
if ($requestPath === '/client/services' && $method === 'GET') { $client->services(); exit; }
if ($requestPath === '/client/services/domain' && $method === 'POST') { $client->saveServiceDomain(); exit; }
$packageChanges = new \HostPilot\Http\PackageChangeController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
if ($requestPath === '/client/services/change' && $method === 'GET') { $packageChanges->index(); exit; }
if ($requestPath === '/client/services/change' && $method === 'POST') { $packageChanges->create(); exit; }
if ($requestPath === '/client/services/change/cancel' && $method === 'POST') { $packageChanges->cancelScheduled(); exit; }
if ($requestPath === '/client/tickets/attachment' || preg_match('#^/client/tickets/attachment/([0-9]+)$#D', $requestPath, $attachmentMatch)) { if ($method === 'GET' && isset($attachmentMatch[1])) { (new \\HostPilot\\Http\\TicketAttachmentController($pdo,$basePath,HOSTPILOT_STORAGE))->download((int)$attachmentMatch[1],false); exit; } http_response_code(404); exit; }
if (preg_match('#^/admin/tickets/attachment/([0-9]+)$#D', $requestPath, $attachmentMatch) && $method === 'GET') { (new \\HostPilot\\Http\\TicketAttachmentController($pdo,$basePath,HOSTPILOT_STORAGE))->download((int)$attachmentMatch[1],true); exit; }
if ($requestPath === '/client/tickets' && $method === 'GET') { $client->tickets(); exit; }
if ($requestPath === '/client/tickets/new' && in_array($method, ['GET', 'POST'], true)) { $client->newTicket(); exit; }
if (preg_match('#^/client/tickets/([0-9]+)$#', $requestPath, $ticketMatch) && in_array($method, ['GET', 'POST'], true)) { $client->viewTicket((int)$ticketMatch[1]); exit; }
if ($requestPath === '/knowledgebase' && $method === 'GET') { $client->knowledgebase(); exit; }

if ($requestPath === '/admin/settings' && in_array($method, ['GET','POST'], true)) { (new \HostPilot\Http\EmailSettingsController($pdo, $basePath, (string)($config['app']['key'] ?? '')))->index(); exit; }
if ($requestPath === '/admin/email-queue' && in_array($method, ['GET','POST'], true)) { (new \HostPilot\Http\EmailQueueController($pdo, $basePath))->index(); exit; }

if ($requestPath === '/admin/updates' && $method === 'GET') { (new \HostPilot\Http\UpdateController($pdo, $basePath, (string)($config['app']['base_url'] ?? '')))->index(); exit; }
if ($requestPath === '/admin/updates/status' && $method === 'GET') { (new \HostPilot\Http\UpdateController($pdo, $basePath, (string)($config['app']['base_url'] ?? '')))->status(); exit; }
$controller = new \HostPilot\Http\AdminController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
if ($requestPath === '/admin/staff' && $method === 'GET') { (new \HostPilot\Http\StaffAccessController($pdo, $basePath))->index(); exit; }
if ($requestPath === '/admin/staff/save' && $method === 'POST') { (new \HostPilot\Http\StaffAccessController($pdo, $basePath))->save(); exit; }
if ($requestPath === '/admin/customers' && $method === 'GET') { $controller->customers(); exit; }
if ($requestPath === '/admin/reports' && $method === 'GET') { $controller->reports(); exit; }
if ($requestPath === '/admin/tickets' && $method === 'GET') { $controller->tickets(); exit; }
if (preg_match('#^/admin/tickets/([0-9]+)$#', $requestPath, $adminTicketMatch) && in_array($method, ['GET','POST'], true)) { $controller->viewTicket((int)$adminTicketMatch[1]); exit; }

if ($requestPath === '/admin/login' && in_array($method, ['GET', 'POST'], true)) {
    $controller->login();
    exit;
}
if ($requestPath === '/admin/logout' && $method === 'POST') {
    $controller->logout();
    exit;
}
if ($requestPath === '/admin' || $requestPath === '/admin/') {
    $controller->dashboard();
    exit;
}
$catalog = new \HostPilot\Http\CatalogController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
$domains = new \HostPilot\Http\DomainController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
if (in_array($requestPath, ['/admin/domains','/admin/domains/registrar'], true) && in_array($method, ['GET','POST'], true)) { $domains->settings(); exit; }
if ($requestPath === '/domains/search' && in_array($method, ['GET','POST'], true)) { $domains->search(); exit; }
if ($requestPath === '/client/domains' && $method === 'GET') { $domains->myDomains(); exit; }
if ($requestPath === '/client/domains/nameservers' && $method === 'POST') { $domains->requestNameservers(); exit; }
if ($requestPath === '/client/domains/sync' && $method === 'POST') { $domains->syncDomainDetails(); exit; }
if ($requestPath === '/client/domains/renew' && $method === 'POST') { $domains->requestRenewal(); exit; }
if ($requestPath === '/admin/domains/export' && $method === 'GET') { $domains->exportOrdersCsv(); exit; }
if ($requestPath === '/admin/domains/orders' && $method === 'GET') { $domains->adminOrders(); exit; }
if ($requestPath === '/admin/domains/expiry-sync/queue' && $method === 'POST') { $domains->queueExpirySync(); exit; }
if ($requestPath === '/admin/domains/expiry-sync/bulk-queue' && $method === 'POST') { $domains->queueBulkExpirySync(); exit; }
if ($requestPath === '/admin/domains/renewals' && $method === 'GET') { $domains->adminRenewals(); exit; }
if ($requestPath === '/admin/domains/renewals/reconcile' && $method === 'POST') { $domains->reconcileRenewal(); exit; }
if ($requestPath === '/admin/domains/reconcile' && $method === 'POST') { $domains->reconcileOrder(); exit; }
if ($requestPath === '/admin/domains/nameservers/reconcile' && $method === 'POST') { $domains->reconcileNameservers(); exit; }
$payment = new \HostPilot\Http\PaymentController($pdo, $basePath, (string)($config['app']['base_url'] ?? ''), (string)($config['app']['key'] ?? ''));
$serverController = new \HostPilot\Http\ServerController($pdo, $basePath, (string)($config['app']['key'] ?? ''));
$cronController = new \HostPilot\Http\CronController($pdo, $basePath, (string)($config['app']['key'] ?? ''), (string)($config['app']['base_url'] ?? ''));
if ($requestPath === '/admin/servers' && $method === 'GET') { $serverController->servers(); exit; }
if ($requestPath === '/admin/servers/save' && $method === 'POST') { $serverController->saveServer(); exit; }
if ($requestPath === '/admin/servers/test' && $method === 'POST') { $serverController->testServer(); exit; }
if ($requestPath === '/admin/servers/map' && $method === 'POST') { $serverController->saveMapping(); exit; }
if ($requestPath === '/admin/services' && $method === 'GET') { $serverController->services(); exit; }
if ($requestPath === '/admin/automation' && in_array($method, ['GET','POST'], true)) { $cronController->adminAutomation(); exit; }
if ($requestPath === '/admin/automation/jobs/retry' && $method === 'POST') { $cronController->retryProvisioningJob(); exit; }
if ($requestPath === '/admin/automation/jobs/resolve' && $method === 'POST') { $cronController->resolveProvisioningJob(); exit; }
if ($requestPath === '/cron/provision' && $method === 'GET') { $cronController->runProvisioning(); exit; }
if ($requestPath === '/cron/email' && $method === 'GET') { $cronController->runEmailQueue(); exit; }
if ($requestPath === '/cron/domains' && $method === 'GET') { $domains->runRegistrationQueue(); exit; }
if ($requestPath === '/cron/domain-renewals' && $method === 'GET') { $domains->runRenewalQueue(); exit; }
if ($requestPath === '/cron/domain-expiry-sync' && $method === 'GET') { $domains->runExpirySyncQueue(); exit; }
if ($requestPath === '/admin/services/provision' && $method === 'POST') { $serverController->provision(); exit; }
if ($requestPath === '/admin/services/lifecycle' && $method === 'POST') { $serverController->lifecycle(); exit; }
if ($requestPath === '/admin/services/terminate' && $method === 'POST') { $serverController->terminate(); exit; }
if ($requestPath === '/admin/payments' && in_array($method, ['GET','POST'], true)) { $payment->adminSettings(); exit; }
if ($requestPath === '/admin/payment-transactions' && $method === 'GET') { $payment->adminTransactions(); exit; }
if (preg_match('#^/client/invoices/([0-9]+)/pay$#', $requestPath, $payInvoiceMatch) && in_array($method, ['GET','POST'], true)) { $payment->payInvoice((int)$payInvoiceMatch[1]); exit; }
if ($requestPath === '/payment/paytr/callback' && $method === 'POST') { $payment->paytrCallback(); exit; }
if ($requestPath === '/payment/iyzico/callback' && $method === 'POST') { $payment->iyzicoCallback(); exit; }
if ($requestPath === '/payment/return' && $method === 'GET') { $payment->returnPage(); exit; }

if ($requestPath === '/admin/products' && in_array($method, ['GET','POST'], true)) { $catalog->adminProducts(); exit; }
if ($requestPath === '/admin/orders' && $method === 'GET') { $catalog->adminOrders(); exit; }
if ($requestPath === '/client/orders' && $method === 'GET') { $catalog->clientOrders(); exit; }
if ($requestPath === '/client/invoices' && $method === 'GET') { $catalog->clientInvoices(); exit; }
if ($requestPath === '/admin/invoices' && $method === 'GET') { $catalog->adminInvoices(); exit; }
if ($requestPath === '/order/create' && $method === 'POST') { $catalog->createOrder(); exit; }
if (preg_match('#^/product/([a-z0-9-]+)$#', $requestPath, $productMatch) && $method === 'GET') { $catalog->product($productMatch[1]); exit; }

if ($requestPath === '/health' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $healthChecks = [
        'database' => false,
        'installer_lock' => is_file(HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'installed.lock'),
        'config_file' => is_file(HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'config.php') && is_readable(HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'config.php'),
        'storage_writable' => is_dir(HOSTPILOT_STORAGE) && is_writable(HOSTPILOT_STORAGE),
        'version_file' => is_file(HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'VERSION'),
        'php_supported' => PHP_VERSION_ID >= 80200,
    ];
    try { $healthChecks['database'] = (int)$pdo->query('SELECT 1')->fetchColumn() === 1; } catch (Throwable) { $healthChecks['database'] = false; }
    $healthy = !in_array(false, $healthChecks, true);
    http_response_code($healthy ? 200 : 503);
    echo json_encode([
        'application' => 'HostPilot',
        'status' => $healthy ? 'healthy' : 'degraded',
        'version' => is_file(HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'VERSION') ? trim((string)file_get_contents(HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'VERSION')) : null,
        'checks' => $healthChecks,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($requestPath === '/') {
    $catalog->storefront();
    exit;
}

http_response_code(404);
echo '404 - Sayfa bulunamadı';
