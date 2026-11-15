<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\DatabaseBackup;
use HostPilot\Core\MigrationRunner;
use PDO;
use Throwable;

final class DatabaseUpgradeController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath, private readonly string $storageDirectory, private readonly string $migrationDirectory) {}

    public function index(): void
    {
        if (!$this->authorize()) return;
        $error = '';
        $success = (string)($_SESSION['hostpilot_migration_success'] ?? '');
        unset($_SESSION['hostpilot_migration_success']);
        $backupInfo = $_SESSION['hostpilot_migration_backup'] ?? null;
        unset($_SESSION['hostpilot_migration_backup']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) $error = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
            elseif ((string)($_POST['action'] ?? '') === 'clear_maintenance' && (string)($_POST['confirm_clear'] ?? '') === '1') {
                try {
                    if (MigrationRunner::pending($this->db, $this->migrationDirectory) !== []) throw new \RuntimeException('Pending migrations remain.');
                    $lockPath = $this->storageDirectory . DIRECTORY_SEPARATOR . 'maintenance.lock';
                    if (is_file($lockPath) && !@unlink($lockPath)) throw new \RuntimeException('Unable to remove maintenance lock.');
                    $this->log('MAINTENANCE_CLEARED', 'user=' . (int)(Auth::user()['id'] ?? 0));
                    $_SESSION['hostpilot_migration_success'] = 'Bekleyen migration bulunmadığı doğrulandı ve bakım modu kapatıldı.';
                    header('Location: ' . $this->url('/admin/database-upgrade')); exit;
                } catch (Throwable $exception) {
                    $this->log('MAINTENANCE_CLEAR_FAILED', $exception->getMessage());
                    $error = 'Bakım modu kapatılamadı. Önce bekleyen migration olmadığını ve dosya izinlerini kontrol edin.';
                }
            } elseif ((string)($_POST['confirm_upgrade'] ?? '') !== '1') $error = 'Veritabanı güncellemesini onaylamanız gerekiyor.';
            else {
                $error = $this->runUpgrade($backupInfo);
                if ($error === '') { header('Location: ' . $this->url('/admin/database-upgrade')); exit; }
            }
        }
        try { $pending = MigrationRunner::pending($this->db, $this->migrationDirectory); }
        catch (Throwable $exception) { $pending = []; $error = $error !== '' ? $error : 'Migration listesi okunamadı. Veritabanı bağlantısını ve migration tablosunu kontrol edin.'; $this->log('DISCOVERY_FAILED', $exception->getMessage()); }
        $maintenance = is_file($this->storageDirectory . DIRECTORY_SEPARATOR . 'maintenance.lock');
        $this->page('Veritabanı güncellemeleri');
        echo '<div class="layout"><aside><a class="brand" href="' . $this->url('/admin') . '">HOST<span>PILOT</span></a><a href="' . $this->url('/admin') . '">Kontrol merkezi</a><a class="active" href="' . $this->url('/admin/database-upgrade') . '">Veritabanı güncellemeleri</a></aside><main><header><small>YÖNETİM / SİSTEM</small><h1>Veritabanı güncellemeleri</h1><p>Yeni sürümün bekleyen SQL migration dosyalarını güvenli şekilde uygulayın.</p></header>';
        if ($success !== '') echo '<div class="success">' . $this->e($success) . '</div>';
        if ($error !== '') echo '<div class="error">' . $this->e($error) . '</div>';
        if ($maintenance) echo '<div class="warning"><b>Bakım modu etkin.</b> Normal ziyaretçiler geçici olarak engelleniyor. Güncelleme başarılı olunca bakım modu otomatik kapanır; hata durumunda bu sayfadan güvenli tekrar deneyebilirsiniz.</div>';
        echo '<section class="panel"><div class="stat"><span>Bekleyen migration</span><strong>' . count($pending) . '</strong></div>';
        if ($pending === []) {
            echo '<h2>Veritabanı güncel</h2><p>Bekleyen SQL migration bulunmuyor.</p>';
            if ($maintenance) echo '<form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><input type="hidden" name="action" value="clear_maintenance"><label class="confirm"><input type="checkbox" name="confirm_clear" value="1" required> Bekleyen migration olmadığını kontrol ettim; bakım modunu kapat.</label><button type="submit">Bakım modunu kapat</button></form>';
        } else {
            echo '<h2>Uygulanacak sürümler</h2><ol class="migrations">';
            foreach ($pending as $migration) echo '<li><code>' . $this->e($migration) . '</code></li>';
            echo '</ol><p>İşlem başlamadan önce veritabanının sıkıştırılmış SQL yedeği alınır ve doğrulanır. Ardından bakım modu açılır. SQL migration dosyaları sırasıyla uygulanır.</p>';
            echo '<form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><label class="confirm"><input type="checkbox" name="confirm_upgrade" value="1" required> Yedekleme tamamlandıktan sonra veritabanı güncellemesini başlatmayı onaylıyorum.</label><button type="submit">Yedekle ve güncellemeyi başlat</button></form>';
        }
        if (is_array($backupInfo) && isset($backupInfo['filename'])) echo '<p class="backup">Son yedek: <code>' . $this->e((string)$backupInfo['filename']) . '</code></p>';
        echo '</section><p class="foot">Yedekler storage/backups altında tutulur ve doğrudan web erişimine kapalıdır. Migration hata verirse bakım modu açık kalır; idempotent dosyalar bu ekrandan tekrar denenebilir. Güncelleme kayıtları storage/logs/migrations.log dosyasına yazılır.</p></main></div>';
        $this->endPage();
    }

    private function runUpgrade(?array &$backupInfo): string
    {
        try {
            $pending = MigrationRunner::pending($this->db, $this->migrationDirectory);
            if ($pending === []) { $_SESSION['hostpilot_migration_success'] = 'Veritabanı zaten güncel; uygulanacak migration bulunamadı.'; return ''; }
            $backup = DatabaseBackup::create($this->db, $this->storageDirectory);
            if (!is_file($backup['path']) || !is_readable($backup['path']) || filesize($backup['path']) !== $backup['size'] || hash_file('sha256', $backup['path']) !== $backup['sha256']) throw new \RuntimeException('Database backup verification failed.');
            $backupInfo = ['filename' => $backup['filename'], 'sha256' => $backup['sha256'], 'size' => $backup['size']];
            $_SESSION['hostpilot_migration_backup'] = $backupInfo;
            $lockPath = $this->storageDirectory . DIRECTORY_SEPARATOR . 'maintenance.lock';
            $lockPayload = json_encode(['started_at' => gmdate(DATE_ATOM), 'started_by' => (int)(Auth::user()['id'] ?? 0), 'backup' => $backup['filename'], 'status' => 'running'], JSON_UNESCAPED_SLASHES);
            $temporaryLock = $lockPath . '.tmp';
            if (!is_string($lockPayload) || file_put_contents($temporaryLock, $lockPayload, LOCK_EX) === false || !rename($temporaryLock, $lockPath)) { @unlink($temporaryLock); throw new \RuntimeException('Unable to activate maintenance mode.'); }
            @chmod($lockPath, 0640);
            $this->log('STARTED', 'user=' . (int)(Auth::user()['id'] ?? 0) . ' backup=' . $backup['filename'] . ' pending=' . implode(',', $pending));
            $applied = MigrationRunner::runPending($this->db, $this->migrationDirectory, true);
            if (!@unlink($lockPath) && is_file($lockPath)) throw new \RuntimeException('Migrations applied, but maintenance lock could not be removed.');
            $this->log('SUCCESS', 'applied=' . implode(',', $applied) . ' backup=' . $backup['filename']);
            $_SESSION['hostpilot_migration_success'] = count($applied) . ' migration başarıyla uygulandı. Yedek: ' . $backup['filename'];
            return '';
        } catch (Throwable $exception) {
            $lockPath = $this->storageDirectory . DIRECTORY_SEPARATOR . 'maintenance.lock';
            if (is_file($lockPath)) {
                $payload = json_encode(['failed_at' => gmdate(DATE_ATOM), 'status' => 'failed', 'backup' => $backupInfo['filename'] ?? null], JSON_UNESCAPED_SLASHES);
                if (is_string($payload)) @file_put_contents($lockPath, $payload, LOCK_EX);
            }
            $this->log('FAILED', $exception->getMessage());
            return 'Güncelleme tamamlanamadı. Bakım modu güvenlik amacıyla açık bırakıldı. Hata ayrıntısı sunucu günlüklerine yazıldı; düzeltme sonrası bu sayfadan tekrar deneyebilirsiniz.';
        }
    }

    private function authorize(): bool
    {
        if (!Auth::check()) { header('Location: ' . $this->url('/admin/login')); return false; }
        if (!in_array(Auth::user()['role'] ?? '', ['super_admin', 'admin'], true)) { http_response_code(403); echo 'Bu alana erişim yetkiniz yok.'; return false; }
        return true;
    }

    private function log(string $event, string $message): void
    {
        $directory = $this->storageDirectory . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return;
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $event . ' ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
        @file_put_contents($directory . DIRECTORY_SEPARATOR . 'migrations.log', $line, FILE_APPEND | LOCK_EX);
        @chmod($directory . DIRECTORY_SEPARATOR . 'migrations.log', 0640);
    }

    private function page(string $title): void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . '</title><style>
        *{box-sizing:border-box}body{margin:0;background:#0a1020;color:#e8edf7;font:14px Inter,system-ui,sans-serif}.layout{display:flex;min-height:100vh}aside{width:235px;background:#101a2d;padding:26px 18px;border-right:1px solid #26354c}aside a{display:block;color:#aebbd0;text-decoration:none;padding:12px;border-radius:8px;margin:5px 0}.brand{font-weight:900;letter-spacing:.1em;color:#fff;padding-bottom:28px}.brand span{color:#7898ff}aside a.active{background:#1b2a46;color:#fff}main{max-width:1050px;width:100%;padding:34px}header{border-bottom:1px solid #26354c;padding-bottom:22px;margin-bottom:24px}header small{color:#8fa2bf;font-size:10px;letter-spacing:.15em}h1{font-size:27px;margin:8px 0}header p,.foot{color:#8fa2bf;line-height:1.7}.panel{background:#111c2f;border:1px solid #293a54;border-radius:14px;padding:24px}.stat{display:flex;align-items:center;justify-content:space-between;color:#aebbd0}.stat strong{font-size:28px;color:#fff}h2{font-size:17px;margin-top:24px}.migrations{padding-left:24px;line-height:2.2}code{color:#b7c8ff;overflow-wrap:anywhere}.confirm{display:flex;gap:10px;align-items:flex-start;margin:22px 0;color:#cbd5e1;line-height:1.6}.confirm input{margin-top:4px}button{border:0;background:#4169e1;color:white;border-radius:9px;padding:13px 18px;font-weight:750;cursor:pointer}.success,.error,.warning{padding:14px;border-radius:10px;margin:0 0 16px;line-height:1.6}.success{background:#123b32;color:#b7f7df}.error{background:#481f2b;color:#ffd0da}.warning{background:#463719;color:#ffe6a6}.backup{margin-top:20px;color:#8fa2bf}.foot{font-size:12px;margin-top:18px}@media(max-width:720px){aside{width:66px;padding:20px 5px}aside a{font-size:0;padding:12px 3px}.brand{font-size:0}main{padding:22px 14px}}
        </style></head><body>';
    }
    private function endPage(): void { echo '</body></html>'; }
    private function url(string $path): string { return htmlspecialchars(rtrim($this->basePath, '/') . $path, ENT_QUOTES, 'UTF-8'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
