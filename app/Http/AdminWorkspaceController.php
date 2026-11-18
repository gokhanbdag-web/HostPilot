<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class AdminWorkspaceController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}

    public function integrations(): void
    {
        $user = Auth::user();
        if (!$user) { $this->redirect('/admin/login'); }
        if (!in_array((string)($user['role'] ?? ''), ['super_admin', 'admin'], true)) {
            http_response_code(403); echo 'Bu modüle erişim yetkiniz yok.'; return;
        }

        $payment = $this->settings('payment');
        $registrar = $this->settings('domain_registrar');
        $email = $this->settings('email');
        $serverCount = (int)$this->db->query("SELECT COUNT(*) FROM servers WHERE enabled=1")->fetchColumn();
        $items = [
            ['PayTR', 'Ödeme sağlayıcısı', ($payment['paytr_enabled'] ?? '0') === '1' && !empty($payment['paytr_merchant_id']) && !empty($payment['paytr_merchant_key']) && !empty($payment['paytr_merchant_salt']), '/admin/payments', 'PayTR mağaza bilgileri ve test/canlı ortam ayarları'],
            ['iyzico', 'Ödeme sağlayıcısı', ($payment['iyzico_enabled'] ?? '0') === '1' && !empty($payment['iyzico_api_key']) && !empty($payment['iyzico_secret_key']), '/admin/payments', 'iyzico API bilgileri ve Checkout Form ayarları'],
            ['ResellerClub', 'Domain kayıt kuruluşu', ($registrar['enabled'] ?? '0') === '1' && !empty($registrar['user_id']) && !empty($registrar['api_key_enc']), '/admin/domains/registrar', 'Domain müsaitlik, kayıt, nameserver ve yenileme işlemleri'],
            ['Hosting kontrol panelleri', 'Sunucu bağlantıları', $serverCount > 0, '/admin/servers', $serverCount . ' etkin sunucu bağlantısı'],
            ['SMTP e-posta', 'E-posta gönderimi', ($email['enabled'] ?? '0') === '1' && !empty($email['host']) && !empty($email['from_email']), '/admin/settings', 'Sistem bildirimleri ve müşteri e-postaları'],
        ];

        $this->start('Entegrasyon merkezi');
        echo '<div class="hero"><div><small>BAĞLANTILAR / DIŞ SERVİSLER</small><h1>Entegrasyon merkezi</h1><p>HostPilot bağlantılarını tek ekrandan takip edin. Gizli anahtarlar bu ekranda okunmaz.</p></div><span class="pill">' . count(array_filter($items, static fn($item) => $item[2])) . ' / ' . count($items) . ' etkin</span></div>';
        echo '<section class="panel"><div class="integration-grid">';
        foreach ($items as [$name, $type, $active, $url, $description]) {
            echo '<article class="integration-card"><div class="integration-head"><div><small>' . $this->e($type) . '</small><h2>' . $this->e($name) . '</h2></div><span class="pill ' . ($active ? 'green' : 'gray') . '">' . ($active ? 'Yapılandırılmış' : 'Kurulmadı / pasif') . '</span></div><p>' . $this->e($description) . '</p><a class="button" href="' . $this->url($url) . '">Ayarları aç →</a></article>';
        }
        echo '</div></section><section class="panel"><h2>Önemli not</h2><p>Bu ekran, kayıtlı yapılandırma durumunu gösterir; sağlayıcı hesabının canlı ortamda başarılı işlem yaptığını kanıtlamaz. Canlı satıştan önce her sağlayıcı için test/sandbox işlemi ve gerçek hesap doğrulaması yapılmalıdır.</p><p>Desteklenen bağlantıların kapsamı ve sağlayıcıya özgü gereksinimler ilgili ayar ekranlarında açıklanır.</p></section>';
        $this->end();
    }

    public function themes(): void
    {
        $user = Auth::user();
        if (!$user) { $this->redirect('/admin/login'); }
        if (!in_array((string)($user['role'] ?? ''), ['super_admin', 'admin'], true)) {
            http_response_code(403); echo 'Bu modüle erişim yetkiniz yok.'; return;
        }

        $counts = [];
        foreach ([
            'cms_pages' => 'İçerik sayfaları',
            'cms_menus' => 'Menüler',
            'cms_announcements' => 'Duyurular',
            'knowledgebase_articles' => 'Bilgi bankası makaleleri',
        ] as $table => $label) {
            $counts[$label] = $this->countTable($table);
        }

        $this->start('Tema ve sayfa çalışma alanı');
        echo '<div class="hero"><div><small>GÖRÜNÜM / İÇERİK YÖNETİMİ</small><h1>Tema ve sayfa çalışma alanı</h1><p>Mevcut CMS araçlarına buradan ulaşın. Görsel sürükle-bırak tema editörü henüz tamamlanmadı; bu ekran böyle bir özellik varmış gibi davranmaz.</p></div><span class="pill gray">Görsel editör geliştirme aşamasında</span></div>';
        echo '<section class="panel"><div class="integration-grid">';
        $links = [
            ['Site sayfaları', '/admin/pages', 'Sayfa oluşturma, düzenleme, SEO alanları ve yayın durumu'],
            ['Menü yönetimi', '/admin/menus', 'Üst ve alt menü bağlantılarını sıralama ve düzenleme'],
            ['Duyurular', '/admin/announcements', 'Müşteri panelinde yayınlanan duyurular'],
            ['Bilgi bankası', '/admin/knowledgebase', 'Yardım makaleleri, kategoriler ve yayın durumu'],
        ];
        foreach ($links as [$name, $url, $description]) {
            echo '<article class="integration-card"><h2>' . $this->e($name) . '</h2><p>' . $this->e($description) . '</p><a class="button" href="' . $this->url($url) . '">Yönet →</a></article>';
        }
        echo '</div></section><section class="panel"><h2>Mevcut içerik özeti</h2>';
        foreach ($counts as $label => $count) echo '<div class="status"><span>' . $this->e($label) . '</span><strong>' . ($count === null ? 'Tablo yok / güncelleme gerekli' : (string)$count) . '</strong></div>';
        echo '</section><section class="panel"><h2>Henüz tamamlanmayanlar</h2><ul><li>Birden fazla bağımsız storefront teması</li><li>Sürükle-bırak sayfa ve widget editörü</li><li>Mobil/tablet/masaüstü canlı önizleme</li><li>Tema revizyonu, yayınlama ve geri alma</li></ul></section>';
        $this->end();
    }

    private function settings(string $group): array
    {
        $q = $this->db->prepare('SELECT setting_key, setting_value, is_secret FROM settings WHERE setting_group=:group');
        $q->execute([':group' => $group]);
        $values = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Secret values are never decrypted or rendered; only their presence is exposed.
            $values[(string)$row['setting_key']] = (int)$row['is_secret'] === 1
                ? ((string)$row['setting_value'] !== '' ? '[configured]' : '')
                : (string)$row['setting_value'];
        }
        return $values;
    }

    private function countTable(string $table): ?int
    {
        if (!preg_match('/^[a-z_]+$/', $table)) return null;
        try {
            $q = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
            $q->execute([':table' => $table]);
            if ((int)$q->fetchColumn() !== 1) return null;
            return (int)$this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        } catch (Throwable) { return null; }
    }

    private function start(string $title): void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . ' · HostPilot</title><style>
        body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.nav-item.active{background:#1b2a46;border-radius:8px}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1300px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.topbar h1{font-size:21px}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p{color:#9aabc2;line-height:1.6}.hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:25px 0;border-bottom:1px solid #26354c}.hero small,.integration-card small{color:#8fa2bd}.hero h1{margin:7px 0;font-size:27px}.hero p{color:#9aabc2}.pill{display:inline-block;padding:7px 10px;border-radius:20px;background:#29344a;color:#c4d0e2;white-space:nowrap}.pill.green{background:#123b32;color:#9de5c8}.pill.gray{background:#29344a;color:#c4d0e2}.integration-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:14px}.integration-card{padding:18px;border:1px solid #2b3b55;border-radius:12px;background:#0d1729}.integration-card h2{font-size:17px;margin:7px 0}.integration-head{display:flex;justify-content:space-between;align-items:center;gap:10px}.integration-card p{min-height:42px}.button{display:inline-block;padding:10px 14px;background:#5278ed;color:white;border-radius:8px;text-decoration:none;font-weight:700}.status{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #26354c}.status:last-child{border:0}ul{color:#b8c5d8;line-height:2}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.sidebar .brand{font-size:0}.sidebar .brand span{font-size:12px}.nav-item{font-size:0}.main{padding:0 12px 20px}.hero{align-items:flex-start;flex-direction:column}}
        </style></head><body><div class="layout"><aside class="sidebar"><a class="brand" href="' . $this->url('/admin') . '">HOST<span>PILOT</span></a><a class="nav-item" href="' . $this->url('/admin') . '">▦ Genel Bakış</a><a class="nav-item" href="' . $this->url('/admin/integrations') . '">⌘ Entegrasyonlar</a><a class="nav-item" href="' . $this->url('/admin/themes') . '">✧ Tema & Sayfa Editörü</a><a class="nav-item" href="' . $this->url('/admin/pages') . '">▤ Site Sayfaları</a><a class="nav-item" href="' . $this->url('/admin/menus') . '">☷ Menü Yönetimi</a></aside><main class="main"><header class="topbar"><div><small>HOSTPILOT / YÖNETİM</small><h1>' . $this->e($title) . '</h1></div><a class="button" href="' . $this->url('/admin') . '">Kontrol merkezine dön</a></header>';
    }

    private function end(): void { echo '</main></div></body></html>'; }
    private function url(string $path): string { return htmlspecialchars(rtrim($this->basePath, '/') . $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function redirect(string $path): never { header('Location: ' . $this->url($path)); exit; }
}
