<?php
declare(strict_types=1);

namespace HostPilot\\Http;

use HostPilot\\Core\\Auth;
use PDO;
use Throwable;

final class UpdateController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}

    public function index(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['super_admin', 'admin'], true)) {
            http_response_code(403);
            echo 'Bu alana erişim yetkiniz yok.';
            return;
        }

        $versionFile = defined('HOSTPILOT_ROOT') ? HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'VERSION' : '';
        $version = $versionFile !== '' && is_file($versionFile)
            ? trim((string)file_get_contents($versionFile))
            : 'Bilinmiyor';
        $logFile = '/opt/hostpilot-deploy/deploy.log';
        $logReadable = is_file($logFile) && is_readable($logFile);
        $logLines = $logReadable ? $this->deploymentSummary($logFile) : [];
        $lastSuccess = null;
        foreach (array_reverse($logLines) as $line) {
            if (str_contains($line, 'BAŞARILI:')) { $lastSuccess = $line; break; }
        }

        $this->page('Güncelleme Merkezi');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="' . $this->url('/admin') . '">HOST<span>PILOT</span></a>';
        echo '<a class="nav-item" href="' . $this->url('/admin') . '">▦ Genel Bakış</a>';
        echo '<a class="nav-item active" href="' . $this->url('/admin/updates') . '">↻ Güncelleme Merkezi</a>';
        echo '<a class="nav-item" href="' . $this->url('/admin/automation') . '">⚙ Otomasyon</a></aside><main class="main">';
        echo '<header class="topbar"><div><small>YÖNETİM / SİSTEM</small><h1>Güncelleme Merkezi</h1></div><a class="logout" href="' . $this->url('/admin') . '">Kontrol merkezi</a></header>';
        echo '<section class="cards">';
        echo '<article class="metric"><span>Kurulu sürüm</span><strong style="font-size:22px">' . $this->e($version) . '</strong><small>Uygulama sürüm dosyası</small></article>';
        echo '<article class="metric"><span>Veritabanı</span><strong style="font-size:22px">Bağlı</strong><small>Bu sayfa açıldığı için uygulama DB bağlantısı kuruldu</small></article>';
        echo '<article class="metric"><span>Dağıtım günlüğü</span><strong style="font-size:22px">' . ($logReadable ? 'Erişilebilir' : 'Erişilemiyor') . '</strong><small>' . ($logReadable ? 'Sunucu dağıtım kaydı okunabildi' : 'Web kullanıcısının sunucu günlüğüne erişimi yok') . '</small></article>';
        echo '<article class="metric"><span>Son başarılı dağıtım</span><strong style="font-size:16px">' . ($lastSuccess ? $this->e(substr($lastSuccess, 0, 100)) : 'Kayıt yok') . '</strong><small>Dağıtım günlüğünden doğrulanır</small></article>';
        echo '</section>';
        echo '<article class="panel roadmap"><h3>Otomatik güncelleme akışı</h3><div class="status"><span>1. GitHub main dalına gönderim</span><strong>Kaynak kod</strong></div><div class="status"><span>2. GitHub Actions PHP ve güvenlik kontrolleri</span><strong>CI kontrolü</strong></div><div class="status"><span>3. Sunucu webhook dağıtımı</span><strong>Mevcut otomasyon</strong></div><div class="status"><span>4. Dağıtım sonrası sağlık kontrolü</span><strong>Harici doğrulama gerekli</strong></div><div class="notice">GitHub Actions kontrolünün başarılı olması, canlı sunucuya dağıtımın tamamlandığını tek başına kanıtlamaz. Canlı dağıtım sonucu sunucu günlüğü ve /health yanıtı ile doğrulanmalıdır.</div></article>';
        echo '<article class="panel roadmap"><div class="panel-head"><div><h3>Dağıtım geçmişi</h3><p>Sunucudaki dağıtım günlüğünden güvenli özet</p></div><a class="logout" href="' . $this->url('/admin/updates') . '">Yenile</a></div>';
        if (!$logReadable) {
            echo '<div class="notice">Dağıtım günlüğü PHP web kullanıcısı tarafından okunamıyor. Günlük dosyası dışarı açılmadı; sunucu izinlerini değiştirmeden önce güvenli, salt-okunur bir raporlama kanalı kurulmalı.</div>';
        } elseif (!$logLines) {
            echo '<p>Henüz görüntülenecek dağıtım kaydı yok.</p>';
        } else {
            foreach ($logLines as $line) echo '<div class="status"><span style="overflow-wrap:anywhere">' . $this->e($line) . '</span></div>';
        }
        echo '</article>';
        echo '<article class="panel roadmap"><h3>Güvenli işlem sınırları</h3><p>Bu ilk sürüm yalnızca görüntüleme yapar. Veritabanı, storage/config.php, kurulum kilidi ve müşteri dosyaları güncelleme sırasında korunur. Geri alma özelliği, yedek doğrulaması ve yetkili sunucu işlemi için ayrı güvenlik katmanı tamamlanmadan etkinleştirilmeyecektir.</p></article>';
        echo '</main></div>';
        $this->endPage();
    }

    private function deploymentSummary(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) return [];
        $size = filesize($path);
        if ($size === false) { fclose($handle); return []; }
        fseek($handle, max(0, $size - 16384));
        $chunk = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($chunk)) return [];
        $lines = preg_split('/\\r?\\n/', $chunk) ?: [];
        $summary = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^(=== DEPLOY |BAŞARILI:|PHP dosyaları kontrol ediliyor|Canlı kod yedekleniyor|Yeni sürüm aktarılıyor|Canlı PHP kontrolü|Dosya sahipliği|fatal:|ERROR:|HATA:)/iu', $line)) {
                $summary[] = substr($line, 0, 240);
            }
        }
        return array_slice($summary, -30);
    }

    private function page(string $title): void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . ' · HostPilot</title><style>' . $this->css() . '</style></head><body>';
    }

    private function endPage(): void { echo '</body></html>'; }
    private function css(): string
    {
        return ':root{font-family:Inter,system-ui,sans-serif;color:#e8edf7;background:#0a1020}*{box-sizing:border-box}body{margin:0}.layout{min-height:100vh;display:flex}.sidebar{width:248px;flex:0 0 248px;background:#0e1729;border-right:1px solid #202d43;padding:25px 15px}.brand{display:block;color:#f5f7ff;text-decoration:none;font-weight:900;letter-spacing:.12em;font-size:19px;padding:0 12px 34px}.brand span{color:#7898ff}.nav-item{display:flex;gap:12px;align-items:center;padding:12px;border-radius:9px;color:#aab7cc;text-decoration:none;font-size:13px;margin:3px 0}.nav-item:hover,.nav-item.active{background:#1b2a46;color:#fff}.main{min-width:0;flex:1;padding:0 32px 28px;max-width:1800px;margin:0 auto}.topbar{height:82px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #202d43}.topbar small{font-size:10px;color:#8493aa}.topbar h1{font-size:20px;margin:5px 0 0}.logout{background:transparent;border:1px solid #33425a;color:#d7e1f1;border-radius:8px;padding:8px 12px;text-decoration:none;font-size:12px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:22px 0}.metric,.panel{background:#111c2f;border:1px solid #26354c;border-radius:13px}.metric{padding:18px}.metric>span{display:block;color:#a7b5ca;font-size:12px}.metric strong{display:block;font-size:30px;margin:15px 0 6px;overflow-wrap:anywhere}.metric small{color:#8191aa;font-size:11px}.panel{padding:20px;margin-top:14px}.panel h3{font-size:14px;margin:0 0 12px}.panel p{color:#8b9bb3;font-size:12px;line-height:1.7}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.panel-head p{margin:0}.status{display:flex;justify-content:space-between;gap:14px;padding:13px 0;border-bottom:1px solid #26354c;font-size:12px;color:#b8c5d8}.status strong{color:#f1f5fb;white-space:nowrap}.notice{margin-top:16px;padding:12px;background:#17253c;border-radius:9px;color:#91a6c4;font-size:11px;line-height:1.6}@media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.main{padding:0 18px 24px}}@media(max-width:700px){.sidebar{width:66px;flex-basis:66px;padding:20px 7px}.brand{font-size:0;padding:0 8px 28px}.brand span{font-size:13px}.nav-item{font-size:0;justify-content:center;padding:13px 5px}.main{padding:0 12px 20px}.cards{gap:9px}.metric{padding:12px}.metric strong{font-size:20px}}';
    }
    private function url(string $path): string { return htmlspecialchars(rtrim($this->basePath, '/') . $path, ENT_QUOTES, 'UTF-8'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function redirect(string $path): never { header('Location: ' . $this->url($path)); exit; }
}
