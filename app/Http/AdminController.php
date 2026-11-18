<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\Authorization;
use HostPilot\Core\EmailService;
use PDO;
use Throwable;

final class AdminController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath = '',private readonly string $applicationKey='') {}

    public function login(): void
    {
        if (Auth::check() && in_array((Auth::user()['role'] ?? ''), ['super_admin','admin','staff'], true)) $this->redirect('/admin');
        $challenge = $_SESSION['hostpilot_mfa_challenge'] ?? null;
        if (!is_array($challenge) || (int)($challenge['expires_at'] ?? 0) < time()) {
            unset($_SESSION['hostpilot_mfa_challenge']);
            $challenge = null;
        }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::verifyCsrf($_POST['_token'] ?? null)) {
                $error = 'Oturum doğrulaması başarısız.';
            } elseif (is_array($challenge)) {
                $userId = (int)($challenge['user_id'] ?? 0);
                $challengeAuthVersion = (int)($challenge['auth_version'] ?? 0);
                $code = trim((string)($_POST['code'] ?? ''));
                if ($userId > 0 && !Auth::consumeMfaAttempt()) {
                    $method = \HostPilot\Core\Mfa::verifyCode($this->db, $userId, $code, $this->applicationKey);
                    if ($method !== null) {
                        $query = $this->db->prepare("SELECT id,name,email,role,status,auth_version FROM users WHERE id=:id AND auth_version=:auth_version AND status='active' AND role IN ('super_admin','admin','staff') LIMIT 1");
                        $query->execute([':id' => $userId, ':auth_version' => $challengeAuthVersion]);
                        $user = $query->fetch(PDO::FETCH_ASSOC);
                        if (is_array($user)) {
                            if ($method === 'recovery') \HostPilot\Core\SecurityAudit::record($this->db, 'auth.mfa_recovery_used', $userId);
                            \HostPilot\Core\SecurityAudit::record($this->db, 'auth.mfa_challenge_succeeded', $userId);
                            Auth::completeLogin($this->db, $user);
                            $this->redirect('/admin');
                        }
                    }
                }
                \HostPilot\Core\SecurityAudit::record($this->db, 'auth.mfa_challenge_failed', $userId > 0 ? $userId : null);
                $error = 'Doğrulama kodu geçersiz, süresi dolmuş veya daha önce kullanılmış.';
            } else {
                $user = Auth::verifyCredentials($this->db, trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''));
                if (is_array($user) && in_array((string)$user['role'], ['super_admin','admin','staff'], true)) {
                    if (\HostPilot\Core\Mfa::isEnabled($this->db, (int)$user['id'])) {
                        session_regenerate_id(true);
                        unset($_SESSION['hostpilot_csrf']);
                        $_SESSION['hostpilot_mfa_challenge'] = ['user_id' => (int)$user['id'], 'auth_version' => (int)($user['auth_version'] ?? 1), 'expires_at' => time() + 300];
                        $challenge = $_SESSION['hostpilot_mfa_challenge'];
                    } else {
                        Auth::completeLogin($this->db, $user);
                        $this->redirect('/admin');
                    }
                } else {
                    $error = 'E-posta, kullanıcı adı veya parola hatalı ya da hesap etkin değil.';
                }
            }
        }
        $this->startPage('Yönetici girişi');
        echo '<section class="login"><div class="eyebrow">HOSTPILOT CONTROL CENTER</div><h1>Yönetim paneline giriş</h1><p>İşletmenizi tek merkezden yönetin.</p>';
        if ($error !== '') echo '<div class="alert">' . $this->e($error) . '</div>';
        if (is_array($challenge)) {
            echo '<p>Parolanız doğrulandı. Devam etmek için authenticator uygulamanızdaki 6 haneli kodu veya tek kullanımlık kurtarma kodlarından birini girin. Bu adım 5 dakika içinde tamamlanmalıdır.</p><form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><label>Doğrulama kodu<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" required autofocus></label><button>Giriş yap</button></form>';
        } else {
            echo '<form method="post"><input type="hidden" name="_token" value="' . $this->e(Auth::csrfToken()) . '"><label>E-posta veya kullanıcı adı<input type="text" name="email" autocomplete="username" required></label><label>Parola<input type="password" name="password" autocomplete="current-password" required></label><button>Giriş yap</button></form><p><a href="' . $this->url('/admin/forgot-password') . '">Parolamı unuttum</a></p>';
        }
        echo '</section>';
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
        $items=[['Genel Bakış','/admin','▦'],['Müşteri Listesi','/admin/customers','♙'],['Ürün & Paketler','/admin/products','▤'],['Siparişler','/admin/orders','⇄'],['Faturalar','/admin/invoices','▧'],['Ödeme Yöntemleri','/admin/payments','₺'],['Ödeme İşlemleri','/admin/payment-transactions','↗'],['Finans Raporları','/admin/finance','▥'],['Vergi / KDV Ayarları','/admin/tax','％'],['Sunucular','/admin/servers','▣'],['Hizmetler','/admin/services','▤'],['Domainler','/admin/domains','◎'],['Domain Siparişleri','/admin/domains/orders','◎'],['Domain Yenilemeleri','/admin/domains/renewals','↻'],['Destek Talepleri','/admin/tickets','✉'],['Destek Departmanları','/admin/ticket-departments','▤'],['Bilgi Bankası','/admin/knowledgebase','▧'],['Site Sayfaları','/admin/pages','▤'],['Menü Yönetimi','/admin/menus','☷'],['Duyurular','/admin/announcements','◉'],['İşletme Raporları','/admin/reports','▥'],['Entegrasyonlar','/admin/integrations','⌘'],['Tema & Sayfa Editörü','/admin/themes','✧'],['E-posta / SMTP Ayarları','/admin/settings','⚙'],['Güncelleme Merkezi','/admin/updates','↻'],['Veritabanı Güncellemeleri','/admin/database-upgrade','⇧']
        ];
        if (in_array(($user['role'] ?? ''), ['admin','super_admin'], true)) { $items[]=['Personel Yetkileri','/admin/staff','♙']; $items[]=['Oturum Güvenliği','/admin/security/sessions','⌑']; }
        $items[]=['İki Aşamalı Doğrulama','/admin/security/mfa','◈'];
        if (in_array(($user['role'] ?? ''), ['admin','super_admin'], true)) $items[]=['Ek Dosya Güvenliği','/admin/security/attachments','⛨'];
        if (($user['role'] ?? '') === 'super_admin') $items[]=['Veritabanı Yedekleri','/admin/database-restore','⟲'];
        if(($user['role']??'')==='staff') $items=array_values(array_filter($items,fn($item)=>Authorization::allowsAdminRequest($item[1],'GET',$user,$this->db)));
        foreach($items as $item) echo '<a class="nav-item'.($item[1]==='/admin'?' active':'').'" href="'.$this->url($item[1]).'"><span>'.$item[2].'</span>'.$this->e($item[0]).'</a>';
        echo '</aside><main class="main"><header class="topbar"><div><small>YÖNETİM / GENEL BAKIŞ</small><h1>Kontrol merkezi</h1></div><div class="user">'.$this->e((string)$user['name']).'<form method="post" action="'.$this->url('/admin/logout').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button class="logout">Çıkış</button></form></div></header>';
        echo '<section class="welcome"><div><div class="eyebrow">İŞLETME ÖZETİ</div><h2>Hoş geldiniz, '.$this->e((string)$user['name']).'</h2><p>İşletmenizin temel göstergeleri tek ekranda.</p></div><span class="date">'.date('d.m.Y').'</span></section>';
        $demoCheck=$this->db->prepare("SELECT setting_value FROM settings WHERE setting_group='demo' AND setting_key='seed_version' LIMIT 1");$demoCheck->execute();
        if((string)$demoCheck->fetchColumn()==='1')echo '<div class="notice"><strong>DEMO VERİLERİ:</strong> DEMO- ile başlayan sipariş, fatura ve destek kayıtları örnek veridir. Gerçek müşteri, tahsilat veya sunucu işlemi değildir.</div>';
        echo '<section class="cards">';
        $this->metric('Müşteriler',$users,'Kayıtlı kullanıcı hesabı'); $this->metric('Siparişler',$orders,$orders===null?'Modül henüz kurulmadı':'Toplam sipariş');
        $this->metric('Faturalar',$invoices,$invoices===null?'Modül henüz kurulmadı':'Toplam fatura'); $this->metric('Hizmetler',$services,$services===null?'Modül henüz kurulmadı':'Toplam hizmet');
        echo '</section><section class="columns"><article class="panel"><div class="panel-head"><div><h3>Müşteri kazanımı</h3><p>Son 30 gün · gerçek kayıt verisi</p></div><span class="live">Canlı veritabanı</span></div><div class="chart"><canvas id="growthChart"></canvas></div></article>';
        echo '<article class="panel"><div class="panel-head"><div><h3>Operasyon durumu</h3><p>Kurulu modüllerden alınan sayımlar</p></div></div>';
        $this->status('Destek talepleri',$tickets); $this->status('Tanımlı sunucular',$servers); $this->status('Müşteri hesapları',$users);
        echo '<div class="notice">CPU, RAM, disk ve ağ metrikleri yalnızca bağlı sunucu API’si veya yetkili izleme ajanı veri sağladığında gösterilir. Sahte sunucu verisi üretilmez.</div></article></section>';
        echo '<article class="panel roadmap"><h3>Modül geliştirme durumu</h3><p>Henüz bağlanmamış modüller</p><div class="chips"><span>Billing Engine</span><span>Ödeme sağlayıcıları</span><span>Provisioning</span><span>Domain Registrars</span><span>Ticketing</span><span>Theme Builder</span><span>Monitoring Engine</span></div></article><footer>HostPilot · Yönetim çalışma alanı</footer></main></div>';
        $this->endPage($growth);
    }

    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST' || !Auth::verifyCsrf($_POST['_token']??null)) { http_response_code(400); echo 'Geçersiz istek.'; return; }
        Auth::logout(); $this->redirect('/admin/login');
    }

    public function customers(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user=Auth::user();
        if (!in_array($user['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }
        $term=trim((string)($_GET['q']??''));
        $page=max(1,(int)($_GET['page']??1));$limit=25;$offset=($page-1)*$limit;
        $where="role='customer'";$params=[];
        if($term!==''){$where.=" AND (name LIKE :q1 OR email LIKE :q2 OR username LIKE :q3)";$like='%'.$term.'%';$params=[':q1'=>$like,':q2'=>$like,':q3'=>$like];}
        $count=$this->db->prepare("SELECT COUNT(*) FROM users WHERE ".$where);$count->execute($params);$total=(int)$count->fetchColumn();
        $list=$this->db->prepare("SELECT id,name,email,username,status,created_at FROM users WHERE ".$where." ORDER BY created_at DESC,id DESC LIMIT ".$limit." OFFSET ".$offset);$list->execute($params);$rows=$list->fetchAll();
        $this->startPage('Müşteriler');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/customers').'">♙ Müşteriler</a><a class="nav-item" href="'.$this->url('/admin/orders').'">⇄ Siparişler</a></aside><main class="main"><header class="topbar"><div><small>YÖNETİM / MÜŞTERİLER</small><h1>Müşteri yönetimi</h1></div><a class="logout" href="'.$this->url('/admin').'">Kontrol merkezi</a></header><article class="panel"><div class="panel-head"><div><h3>Kayıtlı müşteriler</h3><p>'.$total.' müşteri hesabı</p></div><form method="get" action="'.$this->url('/admin/customers').'"><input name="q" value="'.$this->e($term).'" placeholder="Ad, e-posta veya kullanıcı adı" style="padding:10px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px"><button style="padding:10px 14px">Ara</button></form></div>';
        if(!$rows)echo '<p>Aramanızla eşleşen müşteri bulunamadı.</p>';
        foreach($rows as $row){
            $created=date('d.m.Y',strtotime((string)$row['created_at']));
            echo '<a class="ticket-admin" href="'.$this->url('/admin/customers/'.(int)$row['id']).'"><span><b>'.$this->e((string)$row['name']).'</b><small>'.$this->e((string)$row['email']).' · @'.$this->e((string)$row['username']).' · Kayıt: '.$created.'</small></span><em>'.$this->e((string)$row['status']).' · Detay →</em></a>';
        }
        $pages=max(1,(int)ceil($total/$limit));
        if($pages>1){echo '<nav style="display:flex;gap:12px;padding-top:18px">';for($n=1;$n<=$pages;$n++){if($n===$page)echo '<strong>'.$n.'</strong>';else echo '<a href="'.$this->url('/admin/customers').'?q='.rawurlencode($term).'&page='.$n.'">'.$n.'</a>';}echo '</nav>';}
        echo '</article></main></div>';$this->endPage();
    }

    public function viewCustomer(int $id): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $actor=Auth::user();
        if (!in_array($actor['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }
        $q=$this->db->prepare("SELECT id,name,email,username,status,created_at FROM users WHERE id=:id AND role='customer' LIMIT 1");
        $q->execute([':id'=>$id]);$customer=$q->fetch(PDO::FETCH_ASSOC);
        if(!$customer){http_response_code(404);echo 'Müşteri bulunamadı.';return;}

        $orders=$this->db->prepare("SELECT id,order_number,status,currency,total,created_at FROM orders WHERE user_id=:id AND order_number NOT LIKE 'DEMO-%' ORDER BY id DESC LIMIT 50");
        $orders->execute([':id'=>$id]);$orderRows=$orders->fetchAll(PDO::FETCH_ASSOC);
        $invoices=$this->db->prepare("SELECT id,invoice_number,status,currency,total,amount_paid,due_at,issued_at FROM invoices WHERE user_id=:id AND invoice_number NOT LIKE 'DEMO-%' ORDER BY id DESC LIMIT 50");
        $invoices->execute([':id'=>$id]);$invoiceRows=$invoices->fetchAll(PDO::FETCH_ASSOC);
        $services=$this->db->prepare("SELECT s.id,s.domain,s.status,s.billing_cycle,s.amount,s.next_due_date,p.name AS product_name FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN orders o ON o.id=s.order_id WHERE s.user_id=:id AND (o.id IS NULL OR o.order_number NOT LIKE 'DEMO-%') ORDER BY s.id DESC LIMIT 50");
        $services->execute([':id'=>$id]);$serviceRows=$services->fetchAll(PDO::FETCH_ASSOC);
        $tickets=$this->db->prepare("SELECT ticket_number,subject,status,department,updated_at FROM tickets WHERE user_id=:id AND ticket_number NOT LIKE 'DEMO-TKT-%' ORDER BY id DESC LIMIT 30");
        $tickets->execute([':id'=>$id]);$ticketRows=$tickets->fetchAll(PDO::FETCH_ASSOC);

        $money=static fn(mixed $amount,string $currency):string=>number_format((float)$amount,2,',','.').' '.htmlspecialchars($currency,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $this->startPage('Müşteri detayları');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/customers').'">♙ Müşteri Listesi</a><a class="nav-item" href="'.$this->url('/admin/services').'">▤ Hizmetler</a></aside><main class="main"><header class="topbar"><div><small>YÖNETİM / MÜŞTERİLER / DETAY</small><h1>'.$this->e((string)$customer['name']).'</h1></div><a class="logout" href="'.$this->url('/admin/customers').'">Müşteri listesine dön</a></header>';
        if(isset($_GET['notice']))echo '<div class="notice">'. $this->e((string)$_GET['notice']).'</div>';
        if(isset($_GET['error']))echo '<div class="notice err">'.$this->e((string)$_GET['error']).'</div>';
        echo '<section class="cards"><article class="metric"><span>Sipariş</span><strong>'.count($orderRows).'</strong><small>Son 50 gerçek sipariş</small></article><article class="metric"><span>Fatura</span><strong>'.count($invoiceRows).'</strong><small>Son 50 gerçek fatura</small></article><article class="metric"><span>Hizmet</span><strong>'.count($serviceRows).'</strong><small>Demo hizmetler hariç</small></article><article class="metric"><span>Destek</span><strong>'.count($ticketRows).'</strong><small>Gerçek destek talepleri</small></article></section>';
        echo '<section class="panel"><h3>Hesap bilgileri</h3><div class="status"><span>Ad soyad</span><strong>'.$this->e((string)$customer['name']).'</strong></div><div class="status"><span>E-posta</span><strong>'.$this->e((string)$customer['email']).'</strong></div><div class="status"><span>Kullanıcı adı</span><strong>'.$this->e((string)$customer['username']).'</strong></div><div class="status"><span>Durum</span><strong>'.$this->e((string)$customer['status']).'</strong></div><div class="status"><span>Kayıt tarihi</span><strong>'.$this->e((string)$customer['created_at']).'</strong></div>';
        if(in_array($actor['role'],['super_admin','admin'],true)){
            $nextStatus=$customer['status']==='active'?'disabled':'active';$label=$nextStatus==='disabled'?'Müşteri hesabını devre dışı bırak':'Müşteri hesabını yeniden etkinleştir';
            echo '<form method="post" action="'.$this->url('/admin/customers/status').'" onsubmit="return confirm(\'Hesap durumu değiştirilsin mi? Mevcut oturumlar kapatılacaktır.\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="customer_id" value="'.(int)$customer['id'].'"><input type="hidden" name="status" value="'.$nextStatus.'"><button class="secondary">'.$label.'</button></form><p>Hesabı devre dışı bırakmak yeni girişleri engeller ve mevcut oturumları geçersiz kılar; hizmetleri veya domainleri otomatik olarak sonlandırmaz.</p>';
        }else echo '<p>Hesap durumunu değiştirmek için yönetici yetkisi gerekir.</p>';
        echo '</section><section class="panel"><h3>Sipariş geçmişi</h3>';
        if(!$orderRows)echo '<p>Gerçek sipariş kaydı yok.</p>';
        foreach($orderRows as $row)echo '<div class="status"><span>'.$this->e((string)$row['order_number']).'<small>'.$this->e((string)$row['status']).' · '.$this->e((string)$row['created_at']).'</small></span><strong>'.$money($row['total'],(string)$row['currency']).'</strong></div>';
        echo '</section><section class="panel"><h3>Fatura geçmişi</h3>';
        if(!$invoiceRows)echo '<p>Gerçek fatura kaydı yok.</p>';
        foreach($invoiceRows as $row)echo '<div class="status"><span>'.$this->e((string)$row['invoice_number']).'<small>'.$this->e((string)$row['status']).' · Vade: '.$this->e((string)($row['due_at']??'—')).'</small></span><strong>'.$money($row['amount_paid'],(string)$row['currency']).' / '.$money($row['total'],(string)$row['currency']).'</strong></div>';
        echo '</section><section class="panel"><h3>Hizmetleri</h3>';
        if(!$serviceRows)echo '<p>Bağlı hizmet bulunmuyor.</p>';
        foreach($serviceRows as $row)echo '<a class="ticket-admin" href="'.$this->url('/admin/services/'.(int)$row['id']).'"><span><b>'.$this->e((string)$row['product_name']).'</b><small>'.$this->e((string)($row['domain']??'Domain atanmadı')).' · '.$this->e((string)$row['billing_cycle']).' · Yenileme: '.$this->e((string)($row['next_due_date']??'—')).'</small></span><em>'.$this->e((string)$row['status']).' · '.$money($row['amount'],'TRY').' · Detay →</em></a>';
        echo '</section><section class="panel"><h3>Destek geçmişi</h3>';
        if(!$ticketRows)echo '<p>Gerçek destek talebi yok.</p>';
        foreach($ticketRows as $row)echo '<div class="status"><span>#'.$this->e((string)$row['ticket_number']).' · '.$this->e((string)$row['subject']).'<small>'.$this->e((string)$row['department']).' · '.$this->e((string)$row['updated_at']).'</small></span><strong>'.$this->e((string)$row['status']).'</strong></div>';
        echo '</section></main></div>';$this->endPage();
    }

    public function updateCustomerStatus(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $actor=Auth::user();
        if (!in_array($actor['role'], ['super_admin','admin'], true)) { http_response_code(403); echo 'Müşteri hesabı durumunu değiştirme yetkiniz yok.'; return; }
        $id=(int)($_POST['customer_id']??0);$status=(string)($_POST['status']??'');
        if(!Auth::verifyCsrf($_POST['_token']??null)||$id<1||!in_array($status,['active','disabled'],true))$this->redirect('/admin/customers/'.$id.'?error='.rawurlencode('Güvenlik veya doğrulama hatası.'));
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT id,status FROM users WHERE id=:id AND role='customer' FOR UPDATE");$q->execute([':id'=>$id]);$target=$q->fetch(PDO::FETCH_ASSOC);
            if(!$target)throw new \RuntimeException('Müşteri bulunamadı.');
            $column=$this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version'");
            $column->execute();if((int)$column->fetchColumn()!==1)throw new \RuntimeException('Oturum iptali için veritabanı güncellemelerini uygulayın.');
            $this->db->prepare('UPDATE users SET status=:status,auth_version=auth_version+1,updated_at=:updated WHERE id=:id')->execute([':status'=>$status,':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            \HostPilot\Core\SecurityAudit::record($this->db,'customer.status_updated',(int)$actor['id'],['resource_id'=>$id,'action'=>'updated']);
            $this->db->commit();
            $this->redirect('/admin/customers/'.$id.'?notice='.rawurlencode('Müşteri hesabı durumu güncellendi; mevcut oturumlar geçersiz kılındı.'));
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Customer status update failed: '.$e->getMessage());
            $this->redirect('/admin/customers/'.$id.'?error='.rawurlencode($e instanceof \RuntimeException?$e->getMessage():'Müşteri hesabı güncellenemedi.'));
        }
    }

    public function reports(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user=Auth::user();
        if (!in_array($user['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }

        // Exclude opt-in demo fixtures and keep every monetary aggregate separated by currency.
        $customers=(int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='customer' AND email NOT LIKE 'demo.musteri%@example.invalid'")->fetchColumn();
        $orders=(int)$this->db->query("SELECT COUNT(*) FROM orders WHERE order_number NOT LIKE 'DEMO-%'")->fetchColumn();
        $services=(int)$this->db->query("SELECT COUNT(*) FROM services s LEFT JOIN orders o ON o.id=s.order_id WHERE o.id IS NULL OR o.order_number NOT LIKE 'DEMO-%'")->fetchColumn();
        $openTickets=(int)$this->db->query("SELECT COUNT(*) FROM tickets WHERE status<>'closed' AND ticket_number NOT LIKE 'DEMO-TKT-%'")->fetchColumn();

        $ordersByCurrency=$this->db->query("SELECT currency,COUNT(*) AS order_count,COALESCE(SUM(subtotal),0) AS subtotal,COALESCE(SUM(tax_total),0) AS tax_total,COALESCE(SUM(total),0) AS total FROM orders WHERE order_number NOT LIKE 'DEMO-%' GROUP BY currency ORDER BY currency")->fetchAll(PDO::FETCH_ASSOC);
        $financeByCurrency=$this->db->query("SELECT currency,COUNT(*) AS invoice_count,COALESCE(SUM(total),0) AS billed,COALESCE(SUM(amount_paid),0) AS collected,COALESCE(SUM(GREATEST(total-amount_paid,0)),0) AS outstanding FROM invoices WHERE invoice_number NOT LIKE 'DEMO-%' GROUP BY currency ORDER BY currency")->fetchAll(PDO::FETCH_ASSOC);
        $invoiceStatuses=$this->db->query("SELECT currency,status,COUNT(*) AS item_count,COALESCE(SUM(total),0) AS total,COALESCE(SUM(amount_paid),0) AS paid FROM invoices WHERE invoice_number NOT LIKE 'DEMO-%' GROUP BY currency,status ORDER BY currency,status")->fetchAll(PDO::FETCH_ASSOC);
        $serviceStatuses=$this->db->query("SELECT s.status,COUNT(*) AS item_count FROM services s LEFT JOIN orders o ON o.id=s.order_id WHERE o.id IS NULL OR o.order_number NOT LIKE 'DEMO-%' GROUP BY s.status ORDER BY s.status")->fetchAll(PDO::FETCH_ASSOC);
        $ticketStatuses=$this->db->query("SELECT status,COUNT(*) AS item_count FROM tickets WHERE ticket_number NOT LIKE 'DEMO-TKT-%' GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
        $topProducts=$this->db->query("SELECT o.currency,oi.product_name,SUM(oi.quantity) AS quantity,COALESCE(SUM(oi.line_total),0) AS amount FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.order_number NOT LIKE 'DEMO-%' GROUP BY o.currency,oi.product_id,oi.product_name ORDER BY o.currency,amount DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        $customerGrowth=$this->db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,COUNT(*) AS total FROM users WHERE role='customer' AND email NOT LIKE 'demo.musteri%@example.invalid' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at,'%Y-%m') ORDER BY month")->fetchAll(PDO::FETCH_ASSOC);

        $money=static fn(mixed $amount,string $currency):string=>number_format((float)$amount,2,',','.').' '.htmlspecialchars($currency,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $this->startPage('İşletme raporları');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/reports').'">▥ İşletme Raporları</a><a class="nav-item" href="'.$this->url('/admin/finance').'">₺ Finans hareketleri</a></aside><main class="main"><header class="topbar"><div><small>YÖNETİM / RAPORLAR</small><h1>İşletme raporları</h1><p>Gerçek sipariş, fatura, hizmet ve destek kayıtlarından hesaplanır.</p></div><a class="logout" href="'.$this->url('/admin').'">Kontrol merkezi</a></header><div class="notice">DEMO- ile başlayan örnek sipariş ve faturalar bu raporlara dahil edilmez. Farklı para birimleri birbirine çevrilmeden ayrı gösterilir.</div><section class="cards"><article class="metric"><span>Müşteriler</span><strong>'.number_format($customers,0,',','.').'</strong><small>Kayıtlı müşteri hesabı</small></article><article class="metric"><span>Gerçek siparişler</span><strong>'.number_format($orders,0,',','.').'</strong><small>Demo siparişler hariç</small></article><article class="metric"><span>Hizmet kayıtları</span><strong>'.number_format($services,0,',','.').'</strong><small>Demo siparişlere bağlı hizmetler hariç</small></article><article class="metric"><span>Açık destek talepleri</span><strong>'.number_format($openTickets,0,',','.').'</strong><small>Kapatılmamış talepler</small></article></section>';
        echo '<section class="panel"><h3>Fatura ve tahsilat özeti · Para birimine göre</h3><div class="tablewrap"><table><thead><tr><th>Para birimi</th><th>Fatura</th><th>Faturalanan</th><th>Tahsil edilen</th><th>Açık bakiye</th></tr></thead><tbody>';
        if(!$financeByCurrency)echo '<tr><td colspan="5">Henüz gerçek fatura kaydı yok.</td></tr>';
        foreach($financeByCurrency as $row)echo '<tr><td>'.$this->e((string)$row['currency']).'</td><td>'.(int)$row['invoice_count'].'</td><td>'.$money($row['billed'],(string)$row['currency']).'</td><td>'.$money($row['collected'],(string)$row['currency']).'</td><td>'.$money($row['outstanding'],(string)$row['currency']).'</td></tr>';
        echo '</tbody></table></div></section><section class="columns"><article class="panel"><h3>Sipariş özeti · Para birimine göre</h3>';
        if(!$ordersByCurrency)echo '<p>Henüz gerçek sipariş kaydı yok.</p>';
        foreach($ordersByCurrency as $row)echo '<div class="status"><span>'.$this->e((string)$row['currency']).' · '.(int)$row['order_count'].' sipariş</span><strong>'.$money($row['total'],(string)$row['currency']).'</strong></div>';
        echo '</article><article class="panel"><h3>Fatura durumları</h3>';
        if(!$invoiceStatuses)echo '<p>Henüz fatura kaydı yok.</p>';
        foreach($invoiceStatuses as $row)echo '<div class="status"><span>'.$this->e((string)$row['currency']).' · '.$this->e((string)$row['status']).'</span><strong>'.(int)$row['item_count'].' · '.$money($row['total'],(string)$row['currency']).'</strong></div>';
        echo '</article></section><section class="columns"><article class="panel"><h3>Hizmet durumları</h3>';
        if(!$serviceStatuses)echo '<p>Henüz hizmet kaydı yok.</p>';
        foreach($serviceStatuses as $row)echo '<div class="status"><span>'.$this->e((string)$row['status']).'</span><strong>'.(int)$row['item_count'].'</strong></div>';
        echo '</article><article class="panel"><h3>Destek talepleri</h3>';
        if(!$ticketStatuses)echo '<p>Henüz destek talebi yok.</p>';
        foreach($ticketStatuses as $row)echo '<div class="status"><span>'.$this->e((string)$row['status']).'</span><strong>'.(int)$row['item_count'].'</strong></div>';
        echo '</article></section><section class="columns"><article class="panel"><h3>Yeni müşteri kayıtları · Son 6 ay</h3>';
        if(!$customerGrowth)echo '<p>Bu dönemde yeni müşteri kaydı yok.</p>';
        foreach($customerGrowth as $row)echo '<div class="status"><span>'.$this->e((string)$row['month']).'</span><strong>'.(int)$row['total'].'</strong></div>';
        echo '</article><article class="panel"><h3>En çok sipariş edilen ürünler · Para birimine göre</h3>';
        if(!$topProducts)echo '<p>Henüz ürün siparişi yok.</p>';
        foreach($topProducts as $row)echo '<div class="status"><span>'.$this->e((string)$row['product_name']).'<small>'.$this->e((string)$row['currency']).' · '.(int)$row['quantity'].' adet</small></span><strong>'.$money($row['amount'],(string)$row['currency']).'</strong></div>';
        echo '</article></section></main></div>';$this->endPage();
    }

    public function tickets(): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user=Auth::user();
        if (!in_array($user['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }
        if (($user['role'] ?? '') === 'staff') {
            $departments = $this->staffDepartments((int)$user['id']);
            if (!$departments) $rows = [];
            else {
                $placeholders = implode(',', array_fill(0, count($departments), '?'));
                $statement = $this->db->prepare("SELECT t.id,t.ticket_number,t.subject,t.department,t.priority,t.status,t.updated_at,u.name,u.email,assigned.name AS assigned_name FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN ticket_assignments ta ON ta.ticket_id=t.id LEFT JOIN users assigned ON assigned.id=ta.assigned_to_user_id WHERE t.department IN ($placeholders) ORDER BY FIELD(t.status,'open','customer_reply','in_progress','staff_reply','closed'),t.updated_at DESC");
                $statement->execute($departments);
                $rows = $statement->fetchAll();
            }
        } else {
            $rows=$this->db->query("SELECT t.id,t.ticket_number,t.subject,t.department,t.priority,t.status,t.updated_at,u.name,u.email,assigned.name AS assigned_name FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN ticket_assignments ta ON ta.ticket_id=t.id LEFT JOIN users assigned ON assigned.id=ta.assigned_to_user_id ORDER BY FIELD(t.status,'open','customer_reply','in_progress','staff_reply','closed'),t.updated_at DESC")->fetchAll();
        }
        $this->startPage('Destek talepleri');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/tickets').'">✉ Destek Talepleri</a></aside><main class="main"><header class="topbar"><div><small>YÖNETİM / DESTEK</small><h1>Destek talepleri</h1></div><a class="logout" href="'.$this->url('/admin').'">Kontrol merkezi</a></header><article class="panel"><h3>Gelen talepler</h3>';
        if(!$rows) echo '<p>Henüz destek talebi yok.</p>';
        foreach($rows as $t) echo '<a class="ticket-admin" href="'.$this->url('/admin/tickets/'.$t['id']).'"><span><b>#'.$this->e($t['ticket_number']).' · '.$this->e($t['subject']).'</b><small>'.$this->e($t['name']).' · '.$this->e($t['email']).' · '.$this->e($t['department']).' · Öncelik: '.$this->e($t['priority']).' · Atanan: '.$this->e((string)($t['assigned_name']??'Atanmamış')).'</small></span><em>'.$this->e($t['status']).'</em></a>';
        echo '</article></main></div>';
        $this->endPage();
    }

    public function viewTicket(int $id): void
    {
        if (!Auth::check()) $this->redirect('/admin/login');
        $user=Auth::user();
        if (!in_array($user['role'], ['super_admin','admin','staff'], true)) { http_response_code(403); echo 'Yetkisiz erişim.'; return; }
        $q=$this->db->prepare('SELECT t.*,u.name,u.email,assigned.name AS assigned_name,ta.assigned_to_user_id FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN ticket_assignments ta ON ta.ticket_id=t.id LEFT JOIN users assigned ON assigned.id=ta.assigned_to_user_id WHERE t.id=:id');
        $q->execute([':id'=>$id]);$ticket=$q->fetch();
        if(!$ticket){http_response_code(404);echo 'Talep bulunamadı.';return;}
        if (($user['role'] ?? '') === 'staff' && !in_array((string)$ticket['department'], $this->staffDepartments((int)$user['id']), true)) { http_response_code(404); echo 'Destek talebi bulunamadı.'; return; }
        $error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $action=(string)($_POST['action']??'reply');$now=gmdate('Y-m-d H:i:s');
                if($action==='internal_note'){
                    $note=trim((string)($_POST['message']??''));
                    if($note===''||strlen($note)>12000)$error='İç not 1–12.000 karakter arasında olmalıdır.';
                    else{
                        try{
                            $this->db->beginTransaction();
                            $n=$this->db->prepare('INSERT INTO ticket_internal_notes(ticket_id,user_id,note,created_at) VALUES(:ticket,:user,:note,:created)');
                            $n->execute([':ticket'=>$id,':user'=>(int)$user['id'],':note'=>$note,':created'=>$now]);
                            $this->db->prepare('UPDATE tickets SET updated_at=:updated WHERE id=:id')->execute([':updated'=>$now,':id'=>$id]);
                            \HostPilot\Core\SecurityAudit::record($this->db,'support.internal_note_added',(int)$user['id'],['resource_id'=>$id,'action'=>'created']);
                            $this->db->commit();
                            $this->redirect('/admin/tickets/'.$id);
                        }catch(Throwable $exception){
                            if($this->db->inTransaction())$this->db->rollBack();
                            error_log('[HostPilot] Internal ticket note failed: '.$exception->getMessage());
                            $error='İç not kaydedilemedi.';
                        }
                    }
                }elseif($action==='assign'){
                    $target=(int)($_POST['assigned_to_user_id']??0);
                    if(($user['role']??'')==='staff'&&$target!==(int)$user['id'])$error='Personel yalnızca kendi üzerine atama yapabilir.';
                    elseif($target===0&&!in_array(($user['role']??''),['admin','super_admin'],true))$error='Atamayı kaldırmak için yönetici yetkisi gerekir.';
                    else{
                        try{
                            $this->db->beginTransaction();
                            $lock=$this->db->prepare('SELECT department FROM tickets WHERE id=:id FOR UPDATE');
                            $lock->execute([':id'=>$id]);$department=(string)$lock->fetchColumn();
                            if($department==='')throw new \RuntimeException('Destek talebi bulunamadı.');
                            if($target>0){
                                $valid=$this->db->prepare("SELECT u.id FROM users u JOIN staff_departments sd ON sd.user_id=u.id JOIN staff_permissions sp ON sp.user_id=u.id AND sp.permission='support.reply' WHERE u.id=:id AND u.role='staff' AND u.status='active' AND sd.department=:department LIMIT 1");
                                $valid->execute([':id'=>$target,':department'=>$department]);
                                if(!(int)$valid->fetchColumn())throw new \RuntimeException('Seçilen personel bu departmanda aktif destek yanıtı yetkisine sahip değil.');
                                $save=$this->db->prepare('INSERT INTO ticket_assignments(ticket_id,assigned_to_user_id,assigned_by_user_id,created_at,updated_at) VALUES(:ticket,:assignee,:actor,:created,:updated) ON DUPLICATE KEY UPDATE assigned_to_user_id=VALUES(assigned_to_user_id),assigned_by_user_id=VALUES(assigned_by_user_id),updated_at=VALUES(updated_at)');
                                $save->execute([':ticket'=>$id,':assignee'=>$target,':actor'=>(int)$user['id'],':created'=>$now,':updated'=>$now]);
                            }else{
                                $this->db->prepare('DELETE FROM ticket_assignments WHERE ticket_id=:ticket')->execute([':ticket'=>$id]);
                            }
                            \HostPilot\Core\SecurityAudit::record($this->db,'support.ticket_assignment_changed',(int)$user['id'],['resource_id'=>$id,'action'=>$target>0?'assigned':'unassigned']);
                            $this->db->prepare('UPDATE tickets SET updated_at=:updated WHERE id=:id')->execute([':updated'=>$now,':id'=>$id]);
                            $this->db->commit();
                            $this->redirect('/admin/tickets/'.$id);
                        }catch(Throwable $exception){
                            if($this->db->inTransaction())$this->db->rollBack();
                            $error=$exception->getMessage();
                        }
                    }
                }elseif($action==='priority'){
                    $priority=(string)($_POST['priority']??'');
                    if(!in_array(($user['role']??''),['admin','super_admin'],true))$error='Önceliği yalnızca yönetici değiştirebilir.';
                    elseif(!in_array($priority,['low','normal','high','urgent'],true))$error='Destek önceliği geçersiz.';
                    else{
                        $this->db->prepare('UPDATE tickets SET priority=:priority,updated_at=:updated WHERE id=:id')->execute([':priority'=>$priority,':updated'=>$now,':id'=>$id]);
                        \HostPilot\Core\SecurityAudit::record($this->db,'support.ticket_priority_changed',(int)$user['id'],['resource_id'=>$id,'action'=>'updated']);
                        $this->redirect('/admin/tickets/'.$id);
                    }
                }elseif($action==='close'||$action==='reopen'){
                    $status=$action==='close'?'closed':'in_progress';
                    $this->db->prepare('UPDATE tickets SET status=:status,updated_at=:now WHERE id=:id')->execute([':status'=>$status,':now'=>$now,':id'=>$id]);
                    $this->redirect('/admin/tickets/'.$id);
                }else{
                    $message=trim((string)($_POST['message']??''));
                    if($message===''||strlen($message)>12000)$error='Yanıt mesajını kontrol edin.';
                    else{
                        $storedFiles=[];
                        try{
                            $this->db->beginTransaction();
                            $m=$this->db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:ticket,:uid,:message,1,:created)');
                            $m->execute([':ticket'=>$id,':uid'=>$user['id'],':message'=>$message,':created'=>$now]);$messageId=(int)$this->db->lastInsertId();
                            $storedFiles=(new TicketAttachmentController($this->db,$this->basePath,HOSTPILOT_STORAGE))->storeUploads($id,$messageId,(int)$user['id']);
                            $this->db->prepare("UPDATE tickets SET status='staff_reply',last_reply_at=:reply,updated_at=:updated WHERE id=:id")->execute([':reply'=>$now,':updated'=>$now,':id'=>$id]);
                            $this->db->commit();
                            if($this->applicationKey!=='')(new EmailService($this->db,$this->applicationKey))->queueTicketNotice((string)$ticket['email'],(string)$ticket['ticket_number'],(string)$ticket['subject'],'staff_reply','ticket:'.$id.':staff_reply:'.$messageId);
                            $this->redirect('/admin/tickets/'.$id);
                        }catch(Throwable $exception){
                            if($this->db->inTransaction())$this->db->rollBack();TicketAttachmentController::cleanupStored($storedFiles);
                            error_log('[HostPilot] Staff ticket reply failed: '.$exception->getMessage());$error='Yanıt veya dosya ekleri kaydedilemedi. Dosya türü ve boyutunu kontrol edin.';
                        }
                    }
                }
            }
        }
        $m=$this->db->prepare('SELECT m.*,u.name FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:id ORDER BY m.created_at,m.id');
        $notes=$this->db->prepare('SELECT n.note,n.created_at,u.name FROM ticket_internal_notes n JOIN users u ON u.id=n.user_id WHERE n.ticket_id=:id ORDER BY n.created_at,n.id');
        $notes->execute([':id'=>$id]);
        $m->execute([':id'=>$id]);$attachmentService=new TicketAttachmentController($this->db,$this->basePath,HOSTPILOT_STORAGE);
        $this->startPage('Talep #'.$ticket['ticket_number']);
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin/tickets').'">← Destek listesi</a></aside><main class="main"><header class="topbar"><div><small>DESTEK / TALEP DETAYI</small><h1>#'.$this->e($ticket['ticket_number']).'</h1></div></header><article class="panel"><h3>'.$this->e($ticket['subject']).'</h3><p>'.$this->e($ticket['name']).' · '.$this->e($ticket['email']).' · '.$this->e($ticket['department']).' · Öncelik: '.$this->e($ticket['priority']).' · Atanan: '.$this->e((string)($ticket['assigned_name']??'Atanmamış')).'</p>';
        foreach($m->fetchAll() as $msg){
            echo '<div class="ticket-admin"><span><b>'.$this->e($msg['name']).($msg['is_staff']?' · Destek':' · Müşteri').'</b><small>'.date('d.m.Y H:i',strtotime($msg['created_at'])).'</small><p>'.nl2br($this->e($msg['message'])).'</p>';
            foreach($attachmentService->forMessage((int)$msg['id']) as $file)echo '<p>📎 <a href="'.$this->url('/admin/tickets/attachment/'.(int)$file['id']).'">'.$this->e($file['original_name']).' · '.number_format((int)$file['file_size']/1024,0).' KB</a></p>';
            echo '</span></div>';
        }
        $internalNotes=$notes->fetchAll();
        if($internalNotes){
            echo '<section class="panel" style="margin-top:16px;border-color:#8b6b2c"><h3>Ekip iç notları · yalnızca personel görür</h3>';
            foreach($internalNotes as $note)echo '<div class="ticket-admin" style="border-left:3px solid #d6a84b"><span><b>'.$this->e($note['name']).' · İç not</b><small>'.date('d.m.Y H:i',strtotime($note['created_at'])).'</small><p>'.nl2br($this->e($note['note'])).'</p></span></div>';
            echo '</section>';
        }
        if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        $assignees=$this->db->prepare("SELECT u.id,u.name FROM users u JOIN staff_departments sd ON sd.user_id=u.id JOIN staff_permissions sp ON sp.user_id=u.id AND sp.permission='support.reply' WHERE u.role='staff' AND u.status='active' AND sd.department=:department ORDER BY u.name");
        $assignees->execute([':department'=>(string)$ticket['department']]);
        echo '<section class="panel"><h3>Atama ve öncelik</h3><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Sorumlu personel<select name="assigned_to_user_id">';
        if(in_array(($user['role']??''),['admin','super_admin'],true))echo '<option value="0">Atanmamış</option>';
        foreach($assignees->fetchAll(PDO::FETCH_ASSOC) as $assignee)echo '<option value="'.(int)$assignee['id'].'" '.((int)($ticket['assigned_to_user_id']??((($user['role']??'')==='staff')?(int)$user['id']:0))===(int)$assignee['id']?'selected':'').'>'.$this->e($assignee['name']).'</option>';
        echo '</select></label><button name="action" value="assign">Atamayı kaydet</button></form>';
        if(in_array(($user['role']??''),['admin','super_admin'],true)){
            echo '<form method="post" style="margin-top:12px"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Öncelik<select name="priority">';
            foreach(['low'=>'Düşük','normal'=>'Normal','high'=>'Yüksek','urgent'=>'Acil'] as $key=>$label)echo '<option value="'.$key.'" '.($ticket['priority']===$key?'selected':'').'>'.$label.'</option>';
            echo '</select></label><button name="action" value="priority">Önceliği güncelle</button></form>';
        }
        echo '</section>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Ekip iç notu <small>(Müşteriye gönderilmez)</small><textarea name="message" rows="3" maxlength="12000" required></textarea></label><button name="action" value="internal_note">İç not ekle</button></form>';
        echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Yanıtınız<textarea name="message" rows="6" maxlength="12000" style="width:100%;padding:12px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px"></textarea></label><label>Dosya ekleri (en fazla 3 dosya, her biri 5 MB; PDF, JPG, PNG, TXT)<input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.txt,application/pdf,image/jpeg,image/png,text/plain" multiple></label><button>Yanıt gönder</button></form><form method="post" style="margin-top:12px"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button name="action" value="'.($ticket['status']==='closed'?'reopen':'close').'">'.($ticket['status']==='closed'?'Talebi yeniden aç':'Talebi kapat').'</button></form></article></main></div>';
        $this->endPage();
    }

    private function staffDepartments(int $userId): array
    {
        try {
            $statement = $this->db->prepare('SELECT department FROM staff_departments WHERE user_id = :user_id ORDER BY department');
            $statement->execute([':user_id' => $userId]);
            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $exception) {
            error_log('[HostPilot] Staff department lookup failed: ' . $exception->getMessage());
            return [];
        }
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
        return ':root{font-family:Inter,system-ui,sans-serif;color:#e8edf7;background:#0a1020}*{box-sizing:border-box}body{margin:0}.layout{min-height:100vh;display:flex}.sidebar{width:248px;flex:0 0 248px;background:#0e1729;border-right:1px solid #202d43;padding:25px 15px}.brand{display:block;color:#f5f7ff;text-decoration:none;font-weight:900;letter-spacing:.12em;font-size:19px;padding:0 12px 34px}.brand span{color:#7898ff}.nav-label,.eyebrow{font-size:10px;letter-spacing:.16em;font-weight:800;color:#7d8da8}.nav-label{padding:0 12px 12px}.nav-item{display:flex;gap:12px;align-items:center;padding:12px;border-radius:9px;color:#aab7cc;text-decoration:none;font-size:13px;margin:3px 0}.nav-item span{width:18px;text-align:center;color:#8fa6d8}.nav-item:hover,.nav-item.active{background:#1b2a46;color:#fff}.main{min-width:0;flex:1;padding:0 32px 28px;max-width:1800px;margin:0 auto}.topbar{height:82px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #202d43}.topbar small{font-size:10px;color:#8493aa}.topbar h1{font-size:20px;margin:5px 0 0}.user{display:flex;align-items:center;gap:14px;color:#c4d0e2;font-size:12px}.user form{margin:0}.logout{background:transparent;border:1px solid #33425a;color:#d7e1f1;border-radius:8px;padding:8px 12px;cursor:pointer}.welcome{display:flex;justify-content:space-between;align-items:center;padding:27px 0 21px}.welcome h2{font-size:24px;margin:8px 0}.welcome p,.panel-head p,.roadmap p{color:#8b9bb3;font-size:12px;margin:0}.date,.live{font-size:11px;padding:8px 11px;border:1px solid #2a3952;border-radius:20px;color:#b9c7dc}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.metric,.panel{background:#111c2f;border:1px solid #26354c;border-radius:13px}.metric{padding:18px}.metric>span{display:block;color:#a7b5ca;font-size:12px}.metric strong{display:block;font-size:30px;margin:15px 0 6px}.metric small{color:#8191aa;font-size:11px}.columns{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr);gap:14px;margin-top:16px}.panel{padding:20px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-head h3,.roadmap h3{font-size:14px;margin:0 0 6px}.live{color:#7ce0b5;border-color:#285c50}.chart{height:260px;margin-top:18px}.status{display:flex;justify-content:space-between;padding:14px 0;border-bottom:1px solid #26354c;font-size:12px;color:#b8c5d8}.status strong{color:#f1f5fb}.ticket-admin{display:flex;justify-content:space-between;gap:14px;padding:15px 0;border-bottom:1px solid #26354c;color:#dce5f3;text-decoration:none;font-size:12px}.ticket-admin small{display:block;color:#8c9db6;margin-top:6px}.ticket-admin p{white-space:normal;line-height:1.65;color:#c4d0e2}.notice{margin-top:18px;padding:12px;background:#17253c;border-radius:9px;color:#91a6c4;font-size:11px;line-height:1.6}.roadmap{margin-top:14px}.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.chips span{font-size:11px;padding:8px 10px;background:#1a2942;border:1px solid #2b3b55;border-radius:7px;color:#b9c8dd}footer{color:#64748b;font-size:11px;padding:24px 2px}.login{width:min(440px,calc(100% - 28px));margin:9vh auto;background:#111c2f;border:1px solid #26354c;border-radius:16px;padding:30px}.login h1{font-size:24px;margin:12px 0}.login p{color:#8b9bb3;font-size:13px}.login label{display:block;color:#c4d0e2;font-size:12px;margin-top:18px}.login input{display:block;width:100%;margin-top:8px;padding:12px;background:#0b1425;border:1px solid #34445e;border-radius:8px;color:#fff}.login button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:8px;background:#5278ed;color:#fff;font-weight:750;cursor:pointer}.alert{margin-top:16px;padding:12px;border-radius:8px;background:#4b202b;color:#ffc3ca;font-size:12px}@media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}.main{padding:0 20px 24px}}@media(max-width:700px){.sidebar{width:66px;flex-basis:66px;padding:20px 7px}.brand{font-size:0;padding:0 8px 28px}.brand span{font-size:13px}.nav-label{font-size:0;padding:0}.nav-item{font-size:0;justify-content:center;padding:13px 5px}.nav-item span{font-size:16px}.main{padding:0 12px 20px}.topbar{height:70px}.topbar h1{font-size:17px}.user{font-size:0}.logout{font-size:11px}.welcome h2{font-size:19px}.date{white-space:nowrap}.cards{gap:9px}.metric{padding:13px}.metric strong{font-size:23px}.metric small{font-size:10px}.panel{padding:15px}.chart{height:220px}}';
    }
    private function url(string $path):string { return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8'); }
    private function redirect(string $path):never { header('Location: '.$this->url($path)); exit; }
    private function e(string $value):string { return \HostPilot\Core\Html::escape($value); }
}
