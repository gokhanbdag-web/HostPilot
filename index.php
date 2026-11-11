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
    \HostPilot\Core\Schema::migrate($pdo);
} catch (Throwable $exception) {
    error_log('[HostPilot] Database bootstrap or migration failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('HostPilot veritabanına bağlanamıyor veya veritabanı güncellemesini tamamlayamıyor. Yapılandırmayı ve hata kayıtlarını kontrol edin.');
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/'))) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '//') $requestPath = '/';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$client = new \HostPilot\Http\ClientController($pdo, $basePath);
if ($requestPath === '/client/login' && in_array($method, ['GET', 'POST'], true)) { $client->login(); exit; }
if ($requestPath === '/client/register' && in_array($method, ['GET', 'POST'], true)) { $client->register(); exit; }
if ($requestPath === '/client/logout' && $method === 'POST') { $client->logout(); exit; }
if ($requestPath === '/client' || $requestPath === '/client/') { $client->dashboard(); exit; }
if ($requestPath === '/client/tickets' && $method === 'GET') { $client->tickets(); exit; }
if ($requestPath === '/client/tickets/new' && in_array($method, ['GET', 'POST'], true)) { $client->newTicket(); exit; }
if (preg_match('#^/client/tickets/([0-9]+)$#', $requestPath, $ticketMatch) && in_array($method, ['GET', 'POST'], true)) { $client->viewTicket((int)$ticketMatch[1]); exit; }
if ($requestPath === '/knowledgebase' && $method === 'GET') { $client->knowledgebase(); exit; }

$controller = new \HostPilot\Http\AdminController($pdo, $basePath);
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
$catalog = new \HostPilot\Http\CatalogController($pdo, $basePath);
if ($requestPath === '/admin/products' && in_array($method, ['GET','POST'], true)) { $catalog->adminProducts(); exit; }
if ($requestPath === '/admin/orders' && $method === 'GET') { $catalog->adminOrders(); exit; }
if ($requestPath === '/client/orders' && $method === 'GET') { $catalog->clientOrders(); exit; }
if ($requestPath === '/client/invoices' && $method === 'GET') { $catalog->clientInvoices(); exit; }
if ($requestPath === '/admin/invoices' && $method === 'GET') { $catalog->adminInvoices(); exit; }
if ($requestPath === '/order/create' && $method === 'POST') { $catalog->createOrder(); exit; }
if (preg_match('#^/product/([a-z0-9-]+)$#', $requestPath, $productMatch) && $method === 'GET') { $catalog->product($productMatch[1]); exit; }

if ($requestPath === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['application' => 'HostPilot', 'status' => 'core_initialized', 'database' => 'connected'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($requestPath === '/') {
    $catalog->storefront();
    exit;
}

http_response_code(404);
echo '404 - Sayfa bulunamadı';
