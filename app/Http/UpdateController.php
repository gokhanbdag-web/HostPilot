<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class UpdateController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '', private readonly string $baseUrl = '') {}

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
        $checks = $this->runtimeChecks();
        $healthy = !in_array(false, array_column($checks, 'ok'), true);
        $logFile = '/opt/hostpilot-deploy/deploy.log';
        $logReadable = is_file($logFile) && is_readable($logFile);
        $logLines = $logReadable ? $this->deploymentSummary($logFile) : [];
        $lastSuccess = null;
        foreach (array_reverse($logLines) as $line) {
            if (str_contains($line, 'BAŞARILI:')) { $lastSuccess = $line; break; }
        }

        $updateNotes = $this->readUpdateNotes();
        $deployment = $this->deploymentResult($logLines, $logReadable);
        $httpHealth = $this->httpHealthCheck();

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
        echo '<article class="panel roadmap"><div class="panel-head"><div><h3>Son dağıtım sonucu</h3><p>Dağıtım günlüğü ve canlı HTTP sağlık yanıtına göre doğrulanır.</p></div><strong style="color:' . ($deployment['ok'] === true ? '#7ce0b5' : ($deployment['ok'] === false ? '#ffb86b' : '#aab7cc')) . '">' . $this->e($deployment['label']) . '</strong></div>';
        echo '<div class="status"><span>Dağıtım durumu</span><strong>' . $this->e($deployment['detail']) . '</strong></div>';
        echo '<div class="status"><span>Tarih ve saat</span><strong>' . $this->e($deployment['timestamp']) . '</strong></div>';
        echo '<div class="status"><span>HTTP sağlık kontrolü</span><strong style="color:' . ($httpHealth['ok'] ? '#7ce0b5' : '#ffb86b') . '">' . $this->e($httpHealth['label']) . '</strong></div>';
        echo '<div class="status"><span>HTTP yanıtı</span><strong>' . $this->e($httpHealth['detail']) . '</strong></div>';
        echo '<div class="status"><span>Kontrol zamanı</span><strong>' . $this->e($httpHealth['checked_at']) . '</strong></div>';
        if ($deployment['record'] !== '') echo '<div class="notice">Dağıtım kaydı: ' . $this->e($deployment['record']) . '</div>';
        echo '</article>';
        echo '<article class="panel roadmap"><div class="panel-head"><div><h3>Güncelleme notları</h3><p>Yeni sürüm notları sayfa yenilenmeden otomatik kontrol edilir.</p></div><small id="update-notes-time">Canlı kontrol hazır</small></div><pre id="update-notes" style="white-space:pre-wrap;overflow-wrap:anywhere;color:#b8c5d8;font:12px/1.8 inherit;margin:12px 0 0">' . $this->e($updateNotes !== '' ? $updateNotes : 'Henüz güncelleme notu yayınlanmadı.') . '</pre></article>';
        echo '<article class="panel roadmap"><div class="panel-head"><div><h3>Canlı sistem sağlık kontrolü</h3><p>Bu sayfa her açıldığında sunucuda yeniden kontrol edilir</p></div><strong style="color:' . ($healthy ? '#7ce0b5' : '#ffb86b') . '">' . ($healthy ? 'SAĞLIKLI' : 'KONTROL GEREKLİ') . '</strong></div>';
        foreach ($checks as $check) echo '<div class="status"><span>' . $this->e($check['label']) . '</span><strong style="color:' . ($check['ok'] ? '#7ce0b5' : '#ffb86b') . '">' . $this->e($check['detail']) . '</strong></div>';
        echo '<div class="notice">Ayrıca dışarıdan çalışan GitHub Actions dağıtım kontrolü /health adresini tekrar deneyerek canlı yanıtı doğrular.</div></article>';
        echo '<article class="panel roadmap"><h3>Otomatik güncelleme akışı</h3><div class="status"><span>1. GitHub main dalına gönderim</span><strong>Kaynak kod</strong></div><div class="status"><span>2. GitHub Actions PHP ve güvenlik kontrolleri</span><strong>CI kontrolü</strong></div><div class="status"><span>3. Sunucu webhook dağıtımı</span><strong>Mevcut otomasyon</strong></div><div class="status"><span>4. Dağıtım sonrası sağlık kontrolü</span><strong>Otomatik doğrulama</strong></div></article>';
        echo '<article class="panel roadmap"><div class="panel-head"><div><h3>Dağıtım geçmişi</h3><p>Sunucudaki dağıtım günlüğünden güvenli özet</p></div><a class="logout" href="' . $this->url('/admin/updates') . '">Yenile</a></div>';
        if (!$logReadable) {
            echo '<div class="notice">Dağıtım günlüğü PHP web kullanıcısı tarafından okunamıyor. Günlük dosyası dışarı açılmadı; sunucu izinlerini değiştirmeden önce güvenli, salt-okunur bir raporlama kanalı kurulmalı.</div>';
        } elseif (!$logLines) {
            echo '<p>Henüz görüntülenecek dağıtım kaydı yok.</p>';
        } else {
            foreach ($logLines as $line) echo '<div class="status"><span style="overflow-wrap:anywhere">' . $this->e($line) . '</span></div>';
        }
        echo '</article>';
        echo '<article class="panel roadmap"><h3>Güvenli geri alma</h3><p>Geri alma düğmesi şimdilik kapalıdır. Mevcut yedek arşivleri PHP web isteğiyle doğrudan açmak; yol doğrulaması, arşiv bütünlüğü, kilitleme ve canlı kodu atomik değiştirme garantisi olmadan güvenli değildir. Bu sunucu tarafı korumalar doğrulanmadan geri alma işlemi başlatılmaz. Veritabanı ve müşteri dosyaları hiçbir kod geri almasında silinmez.</p></article>';
        echo '<script>
(() => {
 const notesNode=document.getElementById("update-notes");
 const timeNode=document.getElementById("update-notes-time");
 const endpoint=' . json_encode($this->url('/admin/updates/status')) . ';
 let lastNotes=notesNode?notesNode.textContent:"";
 let busy=false;
 async function refreshNotes(){
  if(busy||document.visibilityState==="hidden")return;
  busy=true;
  try{
   const response=await fetch(endpoint+"?t="+Date.now(),{credentials:"same-origin",cache:"no-store",headers:{"Accept":"application/json"}});
   if(!response.ok)throw new Error("HTTP "+response.status);
   const data=await response.json();
   const notes=typeof data.notes==="string"&&data.notes.trim()!==""?data.notes:"Henüz güncelleme notu yayınlanmadı.";
   if(notesNode&&notes!==lastNotes){notesNode.textContent=notes;lastNotes=notes;}
   if(timeNode)timeNode.textContent="Kontrol edildi: "+new Date(data.checked_at).toLocaleTimeString("tr-TR")+" · Otomatik yenileme açık";
  }catch(error){if(timeNode)timeNode.textContent="Canlı notlar alınamadı; sonraki kontrolde tekrar denenecek.";}
  finally{busy=false;}
 }
 refreshNotes();
 window.setInterval(refreshNotes,5000);
})();
</script>';
        echo '</main></div>';
        $this->endPage();
    }

    public function status(): void
    {
        if (!Auth::check()) { http_response_code(401); echo 'Oturum gerekli.'; return; }
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['super_admin', 'admin'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }
        $versionFile = defined('HOSTPILOT_ROOT') ? HOSTPILOT_ROOT . DIRECTORY_SEPARATOR . 'VERSION' : '';
        $version = $versionFile !== '' && is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'Bilinmiyor';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['version' => $version, 'notes' => $this->readUpdateNotes(), 'checked_at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function deploymentResult(array $lines, bool $logReadable): array
    {
        if (!$logReadable) return ['ok' => null, 'label' => 'DOĞRULANAMADI', 'detail' => 'Dağıtım günlüğü okunamıyor', 'timestamp' => 'Kayıt erişilebilir değil', 'record' => ''];
        $lastStart = -1;
        $lastSuccess = -1;
        $lastFailure = -1;
        $lastStartRecord = '';
        foreach ($lines as $index => $line) {
            if (preg_match('/^=== DEPLOY /iu', $line)) { $lastStart = $index; $lastStartRecord = $line; }
            if (str_contains($line, 'BAŞARILI:')) $lastSuccess = $index;
            if (preg_match('/^(?:ERROR:|HATA:|fatal:)/iu', $line)) $lastFailure = $index;
        }
        if ($lastSuccess >= 0 && $lastSuccess > $lastStart && $lastSuccess > $lastFailure) {
            $record = $lines[$lastSuccess];
            $timestamp = 'Günlükte ayrıca tarih bulunmuyor';
            if (preg_match('/\b(20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z)?)\b/u', $record, $match)) {
                $timestamp = $match[1];
            } elseif (preg_match('/\[(20\d{2}-\d{2}-\d{2}[^\]]+)\]/u', $record, $match)) {
                $timestamp = $match[1];
            } elseif (preg_match('/\b(20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z)?)\b/u', $lastStartRecord, $match)) {
                $timestamp = $match[1];
            }
            return ['ok' => true, 'label' => 'DAĞITIM BAŞARILI', 'detail' => 'Başarılı', 'timestamp' => $timestamp, 'record' => substr($record, 0, 220)];
        }
        if ($lastFailure >= 0 && $lastFailure > $lastStart) {
            return ['ok' => false, 'label' => 'DAĞITIM BAŞARISIZ', 'detail' => 'Son dağıtım hatayla sonuçlandı', 'timestamp' => 'Hata kaydı mevcut; tarih günlükte', 'record' => substr($lines[$lastFailure], 0, 220)];
        }
        return ['ok' => null, 'label' => 'KAYIT BULUNAMADI', 'detail' => 'Son dağıtım sonucu doğrulanamadı', 'timestamp' => 'Dağıtım tarihi doğrulanamadı', 'record' => ''];
    }

    private function httpHealthCheck(): array
    {
        $checkedAt = date('d.m.Y H:i:s');
        if ($this->baseUrl === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'label' => 'DOĞRULANAMADI', 'detail' => 'HTTP sağlık kontrolü yapılamadı', 'checked_at' => $checkedAt];
        }
        $url = rtrim($this->baseUrl, '/') . '/health';
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'label' => 'BAŞARISIZ', 'detail' => 'HTTP/1.1 yanıtı alınamadı', 'checked_at' => $checkedAt];
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'],
        ]);
        $body = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $payload = is_string($body) ? json_decode($body, true) : null;
        $healthy = $code === 200 && is_array($payload) && ($payload['status'] ?? '') === 'healthy';
        $reason = $code > 0 ? 'HTTP/1.1 ' . $code . ' ' . $this->httpReason($code) : 'HTTP bağlantısı kurulamadı';
        if ($error !== '' && $code === 0) $reason .= ' · ' . $error;
        return [
            'ok' => $healthy,
            'label' => $healthy ? 'BAŞARILI' : 'BAŞARISIZ',
            'detail' => $reason . ($healthy ? ' · Uygulama sağlıklı' : ' · Uygulama sağlık yanıtı doğrulanamadı'),
            'checked_at' => $checkedAt,
        ];
    }

    private function httpReason(int $code): string
    {
        return match ($code) {
            200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently',
            302 => 'Found', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
            502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            default => 'HTTP Response',
        };
    }

    private function readUpdateNotes(): string
    {
        $root = defined('HOSTPILOT_ROOT') ? HOSTPILOT_ROOT : '';
        if ($root === '') return '';
        $path = $root . DIRECTORY_SEPARATOR . 'UPDATE_NOTES.md';
        if (!is_file($path) || !is_readable($path)) return '';
        $size = filesize($path);
        if ($size === false || $size > 16384) return '';
        $contents = file_get_contents($path);
        return is_string($contents) ? trim($contents) : '';
    }

    private function runtimeChecks(): array
    {
        $root = defined('HOSTPILOT_ROOT') ? HOSTPILOT_ROOT : '';
        $storage = defined('HOSTPILOT_STORAGE') ? HOSTPILOT_STORAGE : '';
        $checks = [];
        $checks[] = ['label' => 'PHP sürümü', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION];
        $checks[] = ['label' => 'Veritabanı sorgusu', 'ok' => true, 'detail' => 'Bağlantı ve SELECT 1 başarılı'];
        try { $dbOk = (int)$this->db->query('SELECT 1')->fetchColumn() === 1;
            $checks[count($checks)-1]['ok'] = $dbOk;
            $checks[count($checks)-1]['detail'] = $dbOk ? 'SELECT 1 başarılı' : 'Sorgu beklenmeyen sonuç döndürdü'; } catch (Throwable) { $checks[count($checks)-1]['ok'] = false; $checks[count($checks)-1]['detail'] = 'Sorgu başarısız'; }
        $checks[] = ['label' => 'Kurulum kilidi', 'ok' => $storage !== '' && is_file($storage . DIRECTORY_SEPARATOR . 'installed.lock'), 'detail' => 'Kurulum kilidi mevcut'];
        $checks[] = ['label' => 'Özel yapılandırma', 'ok' => $storage !== '' && is_file($storage . DIRECTORY_SEPARATOR . 'config.php') && is_readable($storage . DIRECTORY_SEPARATOR . 'config.php'), 'detail' => 'storage/config.php mevcut ve okunabilir'];
        $checks[] = ['label' => 'Storage erişimi', 'ok' => $storage !== '' && is_dir($storage) && is_writable($storage), 'detail' => 'Storage dizini yazılabilir'];
        $checks[] = ['label' => 'Sürüm dosyası', 'ok' => $root !== '' && is_file($root . DIRECTORY_SEPARATOR . 'VERSION'), 'detail' => 'VERSION dosyası mevcut'];
        return $checks;
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
        $lines = preg_split('/\r?\n/', $chunk) ?: [];
        $summary = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^(=== DEPLOY |BAŞARILI:|PHP dosyaları kontrol edildi|Canlı kod yedeklendi|Yeni sürüm aktarıldı|Canlı PHP kontrolü|Dosya sahipliği|fatal:|ERROR:|HATA:)/iu', $line)) {
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
