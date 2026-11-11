<?php
declare(strict_types=1);

/**
 * First-run browser installer. No Composer, SSH, or CLI is required.
 * The front controller includes this file before HostPilot is installed.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$root = dirname(__DIR__);
$storage = $root . DIRECTORY_SEPARATOR . 'storage';
$lockFile = $storage . DIRECTORY_SEPARATOR . 'installed.lock';
$configFile = $storage . DIRECTORY_SEPARATOR . 'config.php';

if (is_file($lockFile) && is_file($configFile)) {
    http_response_code(403);
    exit('HostPilot is already installed.');
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$basePath = dirname($scriptName);
if (str_ends_with($basePath, '/installer')) {
    $basePath = dirname($basePath);
}
$basePath = rtrim($basePath, '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) === '443');
$scheme = $isHttps ? 'https' : 'http';
$host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseUrl = $scheme . '://' . ($host !== '' ? $host : 'localhost') . $basePath;

if (empty($_SESSION['hostpilot_install_csrf'])) {
    $_SESSION['hostpilot_install_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['_token'] ?? '');
    if (!hash_equals((string)$_SESSION['hostpilot_install_csrf'], $token)) {
        $errors[] = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    }

    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $adminUsername = trim((string)($_POST['admin_username'] ?? ''));
    $businessModel = (string)($_POST['business_model'] ?? '');
    $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');

    if ($dbHost === '' || !preg_match('/^[a-zA-Z0-9.\\-:\\[\\]]{1,255}$/', $dbHost)
        || $dbName === '' || $dbUser === '' || strlen($dbUser) > 128
        || $dbPort < 1 || $dbPort > 65535) {
        $errors[] = 'Veritabanı bağlantı bilgilerini kontrol edin.';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
        $errors[] = 'Veritabanı adı yalnızca harf, rakam ve alt çizgi içerebilir.';
    }
    if ($adminName === '' || strlen($adminName) > 80) {
        $errors[] = 'Yönetici adı zorunludur ve 80 karakteri aşamaz.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $adminUsername)) {
        $errors[] = 'Yönetici kullanıcı adı 3-80 karakter olmalı; harf, rakam, nokta, tire ve alt çizgi içermelidir.';
    }
    if (!in_array($businessModel, ['single', 'multi'], true)) {
        $errors[] = 'Lütfen tek işletme veya çoklu satıcı modelini seçin.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Geçerli bir yönetici e-posta adresi girin.';
    }
    if (strlen($adminPass) < 12) {
        $errors[] = 'Yönetici parolası en az 12 karakter olmalıdır.';
    }

    if (!$errors) {
        try {
            if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
                throw new RuntimeException('storage dizini oluşturulamadı.');
            }

            // Defense in depth: deny direct HTTP access to secrets, logs, sessions, and private files.
            $storageHtaccess = $storage . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($storageHtaccess)) {
                $denyRules = "<IfModule mod_authz_core.c>\\nRequire all denied\\n</IfModule>\\n<IfModule !mod_authz_core.c>\\nDeny from all\\n</IfModule>\\n";
                if (file_put_contents($storageHtaccess, $denyRules, LOCK_EX) === false) {
                    throw new RuntimeException('storage erişim koruması yazılamadı.');
                }
                @chmod($storageHtaccess, 0640);
            }

            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                email VARCHAR(190) NOT NULL,
                username VARCHAR(80) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'customer',
                status VARCHAR(24) NOT NULL DEFAULT 'active',
                email_verified_at DATETIME NULL,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_users_email (email),
                UNIQUE KEY uq_users_username (username),
                KEY idx_users_role_status (role, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                setting_group VARCHAR(80) NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value LONGTEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_settings_group_key (setting_group, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY uq_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
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
                KEY idx_tickets_user_status (user_id, status),
                CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                message LONGTEXT NOT NULL,
                is_staff TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_ticket_messages_ticket (ticket_id, created_at),
                CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                CONSTRAINT fk_ticket_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS knowledgebase_articles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                excerpt TEXT NULL,
                content LONGTEXT NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'Genel',
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_kb_slug (slug),
                KEY idx_kb_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS product_groups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(150) NOT NULL,
                description TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_product_groups_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS products (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_id BIGINT UNSIGNED NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                description LONGTEXT NULL,
                product_type VARCHAR(40) NOT NULL DEFAULT 'hosting',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_products_slug (slug),
                KEY idx_products_status_type (status, product_type),
                CONSTRAINT fk_products_group FOREIGN KEY (group_id) REFERENCES product_groups(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS product_pricing (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'TRY',
                billing_cycle VARCHAR(24) NOT NULL DEFAULT 'monthly',
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                setup_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_product_price_cycle (product_id,currency,billing_cycle),
                CONSTRAINT fk_product_pricing_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(30) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                currency CHAR(3) NOT NULL DEFAULT 'TRY',
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_orders_number (order_number),
                KEY idx_orders_user_status (user_id,status),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                product_name VARCHAR(190) NOT NULL,
                billing_cycle VARCHAR(24) NOT NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) NOT NULL,
                line_total DECIMAL(12,2) NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                KEY idx_order_items_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS services (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                order_id BIGINT UNSIGNED NULL,
                domain VARCHAR(190) NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                billing_cycle VARCHAR(24) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                next_due_date DATE NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_services_user_status (user_id,status),
                CONSTRAINT fk_services_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_services_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                CONSTRAINT fk_services_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->beginTransaction();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO users
                (name, email, username, password_hash, role, status, created_at, updated_at)
                VALUES (:name, :email, :username, :password_hash, 'super_admin', 'active', :created_at, :updated_at)");
            $stmt->execute([
                ':name' => $adminName,
                ':email' => $adminEmail,
                ':username' => $adminUsername,
                ':password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $appKey = bin2hex(random_bytes(32));
            $config = [
                'app' => [
                    'name' => 'HostPilot',
                    'base_url' => $baseUrl,
                    'base_path' => $basePath,
                    'timezone' => 'Europe/Istanbul',
                    'locale' => 'tr',
                    'business_model' => $businessModel,
                    'key' => $appKey,
                ],
                'database' => [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'database' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPass,
                    'charset' => 'utf8mb4',
                ],
            ];

            $configPhp = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
            $temporaryConfig = $configFile . '.tmp';
            if (file_put_contents($temporaryConfig, $configPhp, LOCK_EX) === false) {
                throw new RuntimeException('Yapılandırma dosyası yazılamadı.');
            }
            @chmod($temporaryConfig, 0640);
            if (!rename($temporaryConfig, $configFile)) {
                @unlink($temporaryConfig);
                throw new RuntimeException('Yapılandırma dosyası etkinleştirilemedi.');
            }

            if (file_put_contents($lockFile, 'installed=' . gmdate(DATE_ATOM) . PHP_EOL, LOCK_EX) === false) {
                @unlink($configFile);
                throw new RuntimeException('Kurulum kilidi oluşturulamadı.');
            }
            @chmod($lockFile, 0640);
            $pdo->commit();
            $success = true;
            unset($_SESSION['hostpilot_install_csrf']);
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$success) {
                @unlink($lockFile);
                @unlink($configFile);
                @unlink($configFile . '.tmp');
            }
            error_log('[HostPilot Installer] ' . $exception->getMessage());
            $errors[] = 'Kurulum tamamlanamadı. Veritabanı bilgilerini ve storage dizininin yazma izinlerini kontrol edin.';
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HostPilot · Kurulum</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#172033;background:#f3f6fb}
*{box-sizing:border-box}body{margin:0;padding:32px 16px}.wrap{max-width:760px;margin:0 auto}
header{margin:0 0 24px}header span{font-size:12px;letter-spacing:.14em;font-weight:800;color:#2563eb}
h1{margin:8px 0;font-size:32px}header p,.hint{color:#64748b;line-height:1.6}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:26px;box-shadow:0 12px 40px #0f172a0b;margin-bottom:18px}
h2{font-size:17px;margin:0 0 18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
label{display:block;font-size:13px;font-weight:650;margin-bottom:7px}
input{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:12px;font:inherit;background:#fff}
input:focus{outline:3px solid #dbeafe;border-color:#3b82f6}.field{margin-bottom:14px}
button{border:0;border-radius:10px;padding:14px 20px;background:#1d4ed8;color:#fff;font-weight:750;font-size:15px;cursor:pointer;width:100%}
.error{padding:13px;border-radius:10px;background:#fff1f2;color:#9f1239;margin-bottom:14px}
.ok{padding:16px;border-radius:12px;background:#ecfdf5;color:#065f46;line-height:1.7}
@media(max-width:600px){.grid{grid-template-columns:1fr}.panel{padding:20px}body{padding:20px 12px}}
</style>
</head>
<body>
<div class="wrap">
<header><span>HOSTPILOT</span><h1>Kurulum sihirbazı</h1><p>Veritabanı bağlantısını ve ilk yönetici hesabını oluşturun. Kurulum SSH veya Composer gerektirmez.</p></header>

<?php if ($success): ?>
<div class="panel"><div class="ok"><strong>Kurulum tamamlandı.</strong><br>HostPilot çekirdek tabloları, destek talebi altyapısı, bilgi bankası ve ilk yönetici kaydı oluşturuldu. Güvenlik için kurulum kilitlendi. Yönetim paneline giriş yapabilirsiniz.</div></div>
<?php else: ?>
<?php foreach ($errors as $error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<form method="post" class="panel" autocomplete="off">
<input type="hidden" name="_token" value="<?= htmlspecialchars((string)$_SESSION['hostpilot_install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<h2>1. İşletme modeli</h2>
<div class="field"><label for="business_model">HostPilot kullanım modeli</label><select id="business_model" name="business_model" required style="width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:12px;font:inherit"><option value="single">Tek işletme — kendi hizmetlerimi satacağım</option><option value="multi">Çoklu satıcı — bayi / hosting pazaryeri</option></select></div>
<h2 style="margin-top:26px">2. Veritabanı bilgileri</h2>
<div class="grid">
<div class="field"><label for="db_host">Veritabanı sunucusu</label><input id="db_host" name="db_host" value="localhost" required></div>
<div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" value="3306" min="1" max="65535" required></div>
<div class="field"><label for="db_name">Veritabanı adı</label><input id="db_name" name="db_name" required></div>
<div class="field"><label for="db_user">Veritabanı kullanıcı adı</label><input id="db_user" name="db_user" required></div>
</div>
<div class="field"><label for="db_pass">Veritabanı parolası</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password"></div>
<h2 style="margin-top:26px">3. İlk yönetici hesabı</h2>
<div class="grid">
<div class="field"><label for="admin_name">Yönetici adı / kullanıcı adı</label><input id="admin_name" name="admin_name" required maxlength="80"></div>
<div class="field"><label for="admin_username">Yönetici kullanıcı adı</label><input id="admin_username" name="admin_username" required minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]+"></div>
<div class="field"><label for="admin_email">Yönetici e-posta adresi</label><input id="admin_email" name="admin_email" type="email" required></div>
</div>
<div class="field"><label for="admin_pass">Yönetici parolası (en az 12 karakter)</label><input id="admin_pass" name="admin_pass" type="password" minlength="12" autocomplete="new-password" required></div>
<p class="hint">Kurulumdan önce hosting hesabınızda boş bir MySQL veritabanı ve kullanıcı oluşturun. Veritabanı kullanıcısının bu veritabanında tablo oluşturma yetkisi olmalıdır.</p>
<button type="submit">HostPilot'u kur</button>
</form>
<?php endif; ?>
</div>
</body>
</html>
