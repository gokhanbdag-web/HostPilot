<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use PDO;
use Throwable;

final class AdminController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '') {}

    public function login(): void
    {
        if (Auth::check()) $this->redirect('/admin');
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) $error = 'Oturum doğrulaması başarısız.';
            elseif (Auth::attempt($this->db, trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''))) $this->redirect('/admin');
            else $error = 'E-posta veya parola hatalı ya da hesap etkin değil.';
        }
        $this->startPage('Yönetici girişi');
        echo '<section class="login"><div class="eyebrow">HOSTPILOT CONTROL CENTER</div><h1>Yönetim paneline giriş</h1><p>İşletmenizi tek merkezden yönetin.</p>';
        if ($error !== '') echo '<div class="alert">' . $this->e($error) . '</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><label>E-posta<input type="email" name="email" autocomplete="username" required></label><label>Parola<input type="password" name="password" autocomplete="current-password" required></label><button>Giriş yap</button></form></section>';
        $this->endPage();
    }

    public function dashboard(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user = Auth::user();
        if (!in_array($user['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Bu alana erişim yetkiniz yok.'; return; }
        $users=$this->countTable('users'); $orders=$this->countTable('orders'); $invoices=$this->countTable('invoices');
        $services=$this->countTable('services'); $tickets=$this->countTable('tickets'); $servers=$this->countTable('servers');
        $growth=$this->customerGrowth();
        $this->startPage('Genel Bakış');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><div class="nav-label">YÖNETİM</div>';
        $items=[['Genel Bakış','/admin','▦'],['Müşteriler','/admin/customers','♙'],['Ürün & Paketler','/admin/products','▤'],['Siparişler','/admin/orders','⇄'],['Faturalar','/admin/invoices','▧'],['Sunucular','/admin/servers','▣'],['Domainler','/admin/domains','◎'],['Destek Talepleri','/admin/tickets','✉'],['Raporlar','/admin/reports','▥'],['Entegrasyonlar','/admin/integrations','⌘'],['Tema & Sayfa Editörü','/admin/themes','✧'],['Ayarlar','/admin/settings','⚙']];
        foreach($items as $item) echo '<a class="nav-item'.($item[1]==='/admin'?' active':'').'" href="'.$this->url($item[1]).'"><span>'.$item[2].'</span>'.$this->e($item[0]).'</a>';
        echo '</aside><main class="main"><header class="topbar"><div><small>YÖNETİM / GENEL BAKIŞ</small><h1>Kontrol merkezi</h1></div><div class="user">'.$this->e((string)$user['name']).'<form method="post" action="'.$this->url('/admin/logout').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button class="logout">Çıkış</button></form></div></header>';
        echo '<section class="welcome"><div><div class="eyebrow">İŞLETME ÖZETİ</div><h2>Hoş geldiniz, '.$this->e((string)$user['name']).'</h2><p>İşletmenizin temel göstergeleri tek ekranda.</p></div><span class="date">'.date('d.m.Y').'</span></section><section class="cards">';
        $this->metric('Müşteriler',$users,'Kayıtlı kullanıcı hesabı'); $this->metric('Siparişler',$orders,$orders===null?'Modül henüz kurulmadı':'Toplam sipariş');
        $this->metric('Faturalar',$invoices,$invoices===null?'Modül henüz kurulmadı':'Toplam fatura'); $this->metric('Hizmetler',$services,$services===null?'Modül henüz kurulmadı':'Toplam hizmet');
        echo '</section><section class="columns"><article class="panel"><div class="panel-head"><div><h3>Müşteri kazanımı</h3><p>Son 30 gün · gerçek kayıt verisi</p></div><span class="live">Canlı veritabanı</span></div><div class="chart"><canvas id="growthChart"></canvas></div></article>';
        echo '<article class="panel"><div class="panel-head"><div><h3>Operasyon durumu</h3><p>Kurulu modüllerden alınan sayımlar</p></div></div>';
        $this->status('Destek talepleri',$tickets); $this->status('Tanımlı sunucular',$servers); $this->status('Müşteri hesapları',$users);
        echo '<div class="notice">CPU, RAM, disk ve ağ metrikleri yalnızca bağlı sunucu API’si veya yetkili izleme ajanı veri sağladığında gösterilir. Sahte sunucu verisi üretilmez.</div></article></section>';
        echo '<article class="panel roadmap"><h3>Modül geliştirme durumu</h3><p>Henüz bağlanmamış modüller</p><div class="chips"><span>Billing Engine</span><span>PayTR / iyzico</span><span>Provisioning</span><span>Domain Registrars</span><span>Ticketing</span><span>Theme Builder</span><span>Monitoring Engine</span></div></article><footer>HostPilot · Yönetim çalışma alanı</footer></main></div>';
        $this->endPage($growth);
    }

    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST' || !Auth::verifyCsrf($_POST['_token']??null)) { http_response_code(400); echo 'Geçersiz istek.'; return; }
        Auth::logout(); $this->redirect('/admin/login');
    }

    private function countTable(string $table): ?int
    {
        $allowed=['users','orders','invoices','services','tickets','servers'];
        if(!in_array($table,$allowed,true)) return null;
        try {
            $check=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
            $check->execute([':table'=>$table]);
            if((int)$check->fetchColumn()===0) return null;
            return (int)$this->db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        } catch(Throwable) { return null; }
    }

    private function customerGrowth(): array
    {
        $start=new \DateTimeImmutable('today -29 days',new \DateTimeZone('UTC')); $values=[];
        for($i=0;$i<30;$i++) $values[$start->modify('+'.$i.' days')->format('Y-m-d')]=0;
        try {
            $stmt=$this->db->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS total FROM users WHERE created_at>=:start GROUP BY DATE(created_at) ORDER BY day');
            $stmt->execute([':start'=>$start->format('Y-m-d 00:00:00')]);
            foreach($stmt->fetchAll() as $row) if(isset($values[$row['day']])) $values[$row['day']]=(int)$row['total'];
        } catch(Throwable) {}
        return ['labels'=>array_keys($values),'values'=>array_values($values)];
    }

    private function metric(string $label,?int $value,string $hint):void
    { echo '<article class="metric"><span>'.$this->e($label).'</span><strong>'.($value===null?'—':number_format($value,0,',','.')).'</strong><small>'.$this->e($hint).'</small></article>'; }
    private function status(string $label,?int $value):void
    { echo '<div class="status"><span>'.$this->e($label).'</span><strong>'.($value===null?'Modül bekleniyor':number_format($value,0,',','.')).'</strong></div>'; }
    private function startPage(string $title):void
    { echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>'.$this->css().'</style></head><body>'; }
    private function endPage(array $growth=[]):void
    {
        if($growth!==[]) echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script><script>if(window.Chart){new Chart(document.getElementById("growthChart"),{type:"line",data:{labels:'.json_encode($growth['labels']).',datasets:[{label:"Yeni müşteri",data:'.json_encode($growth['values']).',borderColor:"#7695ff",backgroundColor:"rgba(118,149,255,.13)",fill:true,tension:.35,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:7}},y:{beginAtZero:true,ticks:{precision:0}}}}});}</script>';
        echo '</body></html>';
    }
    private function css():string
    {
        return ':root{font-family:Inter,system-ui,sans-serif;color:#e8edf7;background:#0a1020}*{box-sizing:border-box}body{margin:0}.layout{min-height:100vh;display:flex}.sidebar{width:248px;flex:0 0 248px;background:#0e1729;border-right:1px solid #202d43;padding:25px 15px}.brand{display:block;color:#f5f7ff;text-decoration:none;font-weight:900;letter-spacing:.12em;font-size:19px;padding:0 12px 34px}.brand span{color:#7898ff}.nav-label,.eyebrow{font-size:10px;letter-spacing:.16em;font-weight:800;color:#7d8da8}.nav-label{padding:0 12px 12px}.nav-item{display:flex;gap:12px;align-items:center;padding:12px;border-radius:9px;color:#aab7cc;text-decoration:none;font-size:13px;margin:3px 0}.nav-item span{width:18px;text-align:center;color:#8fa6d8}.nav-item:hover,.nav-item.active{background:#1b2a46;color:#fff}.main{min-width:0;flex:1;padding:0 32px 28px;max-width:1800px;margin:0 auto}.topbar{height:82px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #202d43}.topbar small{font-size:10px;color:#8493aa}.topbar h1{font-size:20px;margin:5px 0 0}.user{display:flex;align-items:center;gap:14px;color:#c4d0e2;font-size:12px}.user form{margin:0}.logout{background:transparent;border:1px solid #33425a;color:#d7e1f1;border-radius:8px;padding:8px 12px;cursor:pointer}.welcome{display:flex;justify-content:space-between;align-items:center;padding:27px 0 21px}.welcome h2{font-size:24px;margin:8px 0}.welcome p,.panel-head p,.roadmap p{color:#8b9bb3;font-size:12px;margin:0}.date,.live{font-size:11px;padding:8px 11px;border:1px solid #2a3952;border-radius:20px;color:#b9c7dc}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.metric,.panel{background:#111c2f;border:1px solid #26354c;border-radius:13px}.metric{padding:18px}.metric>span{display:block;color:#a7b5ca;font-size:12px}.metric strong{display:block;font-size:30px;margin:15px 0 6px}.metric small{color:#8191aa;font-size:11px}.columns{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:14px;margin-top:16px}.panel{padding:20px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-head h3,.roadmap h3{font-size:14px;margin:0 0 6px}.live{color:#7ce0b5;border-color:#285c50}.chart{height:260px;margin-top:18px}.status{display:flex;justify-content:space-between;padding:14px 0;border-bottom:1px solid #26354c;font-size:12px;color:#b8c5d8}.status strong{color:#f1f5fb}.notice{margin-top:18px;padding:12px;background:#17253c;border-radius:9px;color:#91a6c4;font-size:11px;line-height:1.6}.roadmap{margin-top:14px}.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.chips span{font-size:11px;padding:8px 10px;background:#1a2942;border:1px solid #2b3b55;border-radius:7px;color:#b9c8dd}footer{color:#64748b;font-size:11px;padding:24px 2px}.login{width:min(440px,calc(100% - 28px));margin:9vh auto;background:#111c2f;border:1px solid #26354c;border-radius:16px;padding:30px}.login h1{font-size:24px;margin:12px 0}.login p{color:#8b9bb3;font-size:13px}.login label{display:block;color:#c4d0e2;font-size:12px;margin-top:18px}.login input{display:block;width:100%;margin-top:8px;padding:12px;background:#0b1425;border:1px solid #34445e;border-radius:8px;color:#fff}.login button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:8px;background:#5278ed;color:#fff;font-weight:750;cursor:pointer}.alert{margin-top:16px;padding:12px;border-radius:8px;background:#4b202b;color:#ffc3ca;font-size:12px}@media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}.main{padding:0 20px 24px}}@media(max-width:700px){.sidebar{width:66px;flex-basis:66px;padding:20px 7px}.brand{font-size:0;padding:0 8px 28px}.brand span{font-size:13px}.nav-label{font-size:0;padding:0}.nav-item{font-size:0;justify-content:center;padding:13px 5px}.nav-item span{font-size:16px}.main{padding:0 12px 20px}.topbar{height:70px}.topbar h1{font-size:17px}.user{font-size:0}.logout{font-size:11px}.welcome h2{font-size:19px}.date{white-space:nowrap}.cards{gap:9px}.metric{padding:13px}.metric strong{font-size:23px}.metric small{font-size:10px}.panel{padding:15px}.chart{height:220px}}';
    }
    private function url(string $path):string { return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8'); }
    private function redirect(string $path):never { header('Location: '.$this->url($path)); exit; }
    private function e(string $value):string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
