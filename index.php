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
} catch (Throwable $exception) {
    error_log('[HostPilot] Database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('HostPilot veritabanına bağlanamıyor. Yapılandırmayı kontrol edin.');
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
if ($requestPath === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['application' => 'HostPilot', 'status' => 'core_initialized', 'database' => 'connected'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($requestPath === '/') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>HostPilot</title><body><main><h1>HostPilot</h1><p>Hosting ve domain hizmetlerinizi keşfedin.</p><a href="' . htmlspecialchars($basePath . '/client', ENT_QUOTES, 'UTF-8') . '">Müşteri paneli</a> · <a href="' . htmlspecialchars($basePath . '/knowledgebase', ENT_QUOTES, 'UTF-8') . '">Yardım merkezi</a> · <a href="' . htmlspecialchars($basePath . '/admin', ENT_QUOTES, 'UTF-8') . '">Yönetim paneli</a></main></body></html>';
    exit;
}

http_response_code(404);
echo '404 - Sayfa bulunamadı';
