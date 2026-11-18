<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use HostPilot\Core\TaxCalculator;
use PDO;
use Throwable;

final class TaxSettingsController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}

    public function index(): void
    {
        $user = Auth::user();
        if (!$user) { $this->redirect('/admin/login'); }
        if (!in_array((string)($user['role'] ?? ''), ['super_admin', 'admin'], true)) {
            http_response_code(403); echo 'Vergi ayarlarını değiştirme yetkiniz yok.'; return;
        }
        $notice = '';
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
                $error = 'Güvenlik doğrulaması başarısız.';
            } else {
                try {
                    $enabled = isset($_POST['enabled']) ? '1' : '0';
                    $rateInput = trim((string)($_POST['rate'] ?? '0'));
                    $rate = TaxCalculator::rateBasisPoints($rateInput);
                    $mode = (string)($_POST['mode'] ?? 'exclusive');
                    $label = trim((string)($_POST['label'] ?? 'KDV'));
                    if (!in_array($mode, ['exclusive', 'inclusive'], true)) throw new \RuntimeException('Fiyat vergilendirme biçimi geçersiz.');
                    if ($label === '' || strlen($label) > 40 || preg_match('/[<>]/', $label)) throw new \RuntimeException('Vergi adı 1–40 karakter olmalı.');
                    $current = TaxCalculator::settings($this->db);
                    if ($current['mode'] !== $mode) {
                        $existingOrders = (int)$this->db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
                        if ($existingOrders > 0) throw new \RuntimeException('Vergi dahil/hariç fiyat biçimi sipariş oluşturulduktan sonra değiştirilemez; mevcut hizmet yenilemelerinin fiyat temeli korunmalıdır.');
                    }
                    $rateValue = number_format($rate / 100, 2, '.', '');
                    $fields = ['enabled' => $enabled, 'rate' => $rateValue, 'mode' => $mode, 'label' => $label];
                    foreach ($fields as $key => $value) {
                        $q = $this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('tax',:key,:value,0,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0,updated_at=VALUES(updated_at)");
                        $q->execute([':key' => $key, ':value' => $value, ':updated' => gmdate('Y-m-d H:i:s')]);
                    }
                    SecurityAudit::record($this->db, 'tax.settings_updated', isset($user['id']) ? (int)$user['id'] : null, ['action' => 'updated']);
                    $notice = 'Vergi ayarları kaydedildi. Yeni oluşturulacak sipariş ve faturalar bu ayarları kullanır; mevcut faturalar değişmez.';
                } catch (Throwable $e) {
                    $error = $e instanceof \RuntimeException ? $e->getMessage() : 'Vergi ayarları kaydedilemedi.';
                }
            }
        }
        $settings = TaxCalculator::settings($this->db);
        $this->start();
        echo '<div class="hero"><div><small>FİNANS / VERGİ</small><h1>Vergi ve KDV ayarları</h1><p>Yeni sipariş ve faturalar için varsayılan vergi oranını ve katalog fiyatlarının vergi dahil olup olmadığını belirleyin.</p></div></div>';
        if ($notice !== '') echo '<div class="notice">' . $this->e($notice) . '</div>';
        if ($error !== '') echo '<div class="alert">' . $this->e($error) . '</div>';
        echo '<section class="panel"><form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><label><input type="checkbox" name="enabled" value="1" ' . ($settings['enabled'] ? 'checked' : '') . '> Vergi hesaplamasını etkinleştir</label><label>Vergi adı<input name="label" maxlength="40" value="' . $this->e($settings['label']) . '" required></label><label>Varsayılan vergi oranı (%)<input name="rate" type="number" min="0" max="100" step="0.01" value="' . $this->e(number_format((int)$settings['rate_basis_points'] / 100, 2, '.', '')) . '" required></label><label>Ürün fiyatlarının vergi durumu<select name="mode"><option value="exclusive" ' . ($settings['mode'] === 'exclusive' ? 'selected' : '') . '>Vergi hariç fiyat — vergi fatura toplamına eklenir</option><option value="inclusive" ' . ($settings['mode'] === 'inclusive' ? 'selected' : '') . '>Vergi dahil fiyat — vergi tutardan ayrıştırılır</option></select></label><button class="button">Vergi ayarlarını kaydet</button></form></section>';
        echo '<section class="panel"><h2>Hesaplama davranışı</h2><ul><li>Vergi oranı her fatura satırında sabitlenir; oran daha sonra değiştirilse bile eski faturalar değişmez.</li><li>Vergi hariç modunda vergi, net satır tutarına eklenir.</li><li>Vergi dahil modunda vergi, katalogda görünen fiyattan ayrıştırılır.</li><li>Hesaplamalar kuruş hassasiyetinde satır bazında yuvarlanır.</li><li>Vergi dahil/hariç fiyat biçimi ilk siparişten sonra değiştirilemez; mevcut hizmetlerin fiyat temeli korunur. Vergi oranı değiştirilebilir ve yeni faturalara uygulanır.</li><li>Bu genel oran başlangıç aşamasıdır; ürün bazlı oranlar, ülke/istisna kuralları ve yasal e-belge entegrasyonu ayrı geliştirme adımlarıdır.</li></ul></section>';
        $this->end();
    }

    private function start(): void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vergi ayarları · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1200px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p,.panel li{color:#9aabc2;line-height:1.7}.panel label{display:block;margin:16px 0;color:#c4d0e2}.panel input:not([type=checkbox]),.panel select{display:block;width:100%;max-width:520px;margin-top:7px;padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.panel button,.button{padding:12px 17px;background:#5278ed;color:white;border:0;border-radius:8px;font-weight:700;cursor:pointer}.hero{padding:25px 0;border-bottom:1px solid #26354c}.hero small{color:#8fa2bd}.hero h1{font-size:27px;margin:7px 0}.hero p{color:#9aabc2}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0;background:#172f35;color:#a9f0d0}.alert{background:#4b202b;color:#ffc3ca}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.main{padding:0 12px 20px}}</style></head><body><div class="layout"><aside class="sidebar"><a class="brand" href="' . $this->url('/admin') . '">HOST<span>PILOT</span></a><a class="nav-item" href="' . $this->url('/admin') . '">← Kontrol merkezi</a><a class="nav-item" href="' . $this->url('/admin/finance') . '">Finans raporları</a><a class="nav-item" href="' . $this->url('/admin/payments') . '">Ödeme yöntemleri</a></aside><main class="main"><header class="topbar"><h1>Vergi ve KDV</h1><a class="button" href="' . $this->url('/admin') . '">Kontrol merkezi</a></header>';
    }

    private function end(): void { echo '</main></div></body></html>'; }
    private function url(string $path): string { return htmlspecialchars(rtrim($this->basePath, '/') . $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function redirect(string $path): never { header('Location: ' . $this->url($path)); exit; }
}
