<?php
declare(strict_types=1);

/**
 * HostPilot front controller.
 * Supports root and subdirectory installations; no Composer is required.
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
    exit('HostPilot configuration is invalid. Check the protected storage configuration.');
}

require HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Database.php';

try {
    $database = new HostPilot\Core\Database($config['database']);
    $database->connection();
} catch (Throwable $exception) {
    error_log('[HostPilot] Database connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('HostPilot cannot connect to its database. Please check the database configuration.');
}

$basePath = (string)($config['app']['base_path'] ?? '');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$requestPath = '/' . ltrim($requestPath, '/');

if ($requestPath === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'application' => 'HostPilot',
        'status' => 'core_initialized',
        'database' => 'connected',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HostPilot</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;background:#0b1220;color:#e8eef8;display:grid;place-items:center;padding:24px}
        main{width:min(720px,100%);padding:36px;border:1px solid #26344b;border-radius:20px;background:#111c2e;box-shadow:0 24px 80px #0005}
        .mark{display:inline-flex;padding:8px 12px;border-radius:9px;background:#193b37;color:#8ce4c0;font-size:13px;font-weight:700;letter-spacing:.08em}
        h1{font-size:clamp(30px,5vw,46px);margin:22px 0 10px}p{color:#aab8cc;line-height:1.7}
        code{color:#9bd4ff}a{color:#9bd4ff}
    </style>
</head>
<body>
<main>
    <span class="mark">HOSTPILOT · CORE</span>
    <h1>Kurulum tamamlandı.</h1>
    <p>HostPilot çekirdeği veritabanına bağlandı. Bu ilk geliştirme aşamasında kurulum ve altyapı temeli hazırlanmıştır; yönetim paneli, müşteri alanı, satış temaları ve iş modülleri henüz bu sürümde tamamlanmış değildir.</p>
    <p>Sağlık kontrolü: <a href="<?= htmlspecialchars(($basePath ?: '') . '/health', ENT_QUOTES, 'UTF-8') ?>">/health</a></p>
</main>
</body>
</html>
