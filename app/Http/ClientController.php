<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\SecretVault;
use PDO;
use Throwable;

final class ClientController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db, private readonly string $basePath = '',string $appKey='') {$this->vault=new SecretVault($appKey);}

    public function login(): void
    {
        if(Auth::check())$this->go('/client');$error='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif(Auth::attempt($this->db,trim((string)($_POST['email']??'')),(string)($_POST['password']??''))){
                if((Auth::user()['role']??'')==='customer')$this->go('/client');Auth::logout();$error='Bu giriş müşteri hesapları içindir.';
            }else $error='Giriş bilgileri hatalı veya hesap etkin değil.';
        }
        $this->start('Müşteri girişi');echo '<main class="auth"><div class="brand">HOST<span>PILOT</span></div><h1>Müşteri hesabınıza giriş yapın</h1><p>Hizmetlerinizi, faturalarınızı ve destek taleplerinizi yönetin.</p>';$this->err($error);
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>E-posta<input type="email" name="email" required></label><label>Parola<input type="password" name="password" required></label><button>Giriş yap</button></form><a href="'.$this->url('/client/register').'">Yeni müşteri hesabı oluştur</a></main>';$this->end();
    }

    public function register(): void
    {
        if(Auth::check())$this->go('/client');$error='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif($name===''||strlen($name)>80||!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/',$username)||strlen($password)<12)$error='Bilgileri kontrol edin. Parola en az 12 karakter olmalıdır.';
            else try{$now=gmdate('Y-m-d H:i:s');$s=$this->db->prepare("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:n,:e,:u,:p,'customer','active',:c,:u2)");$s->execute([':n'=>$name,':e'=>$email,':u'=>$username,':p'=>password_hash($password,PASSWORD_DEFAULT),':c'=>$now,':u2'=>$now]);Auth::attempt($this->db,$email,$password);$this->go('/client');}catch(Throwable){$error='Bu e-posta veya kullanıcı adı kullanılıyor olabilir.';}
        }
        $this->start('Müşteri kaydı');echo '<main class="auth"><div class="brand">HOST<span>PILOT</span></div><h1>Hesap oluşturun</h1>';$this->err($error);
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Ad soyad<input name="name" maxlength="80" required></label><label>Kullanıcı adı<input name="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]+" required></label><label>E-posta<input type="email" name="email" required></label><label>Parola (en az 12 karakter)<input type="password" name="password" minlength="12" required></label><button>Hesap oluştur</button></form><a href="'.$this->url('/client/login').'">Zaten hesabım var</a></main>';$this->end();
    }

    public function dashboard():void
    {
        $u=$this->customer();if(!$u)return;$s=$this->db->prepare('SELECT COUNT(*) FROM tickets WHERE user_id=:id');$s->execute([':id'=>$u['id']]);$n=(int)$s->fetchColumn();
        $this->shell($u,'Müşteri paneli');echo '<section class="hero"><span>HOSTPILOT CLIENT AREA</span><h1>Merhaba, '.$this->e($u['name']).'</h1><p>Hesabınızı ve destek işlemlerinizi yönetin.</p></section><div class="stats"><article><small>Destek taleplerim</small><strong>'.$n.'</strong></article><article><small>Hesap durumu</small><strong>Aktif</strong></article><article><small>Üyelik tarihi</small><strong>'.date('d.m.Y',strtotime($u['created_at'])).'</strong></article></div><section class="panel"><h2>Destek merkezi</h2><p>Bir sorunuz varsa destek ekibimize ulaşın.</p><a class="button" href="'.$this->url('/client/tickets/new').'">Yeni destek talebi</a><a class="quick" href="'.$this->url('/client/orders').'">Siparişlerim →</a><a class="quick" href="'.$this->url('/client/services').'">Hosting hizmetlerim →</a><a class="quick" href="'.$this->url('/client/invoices').'">Faturalarım →</a><a class="quick" href="'.$this->url('/client/tickets').'">Destek taleplerim →</a><a class="quick" href="'.$this->url('/knowledgebase').'">Sıkça sorulan sorular / Bilgi bankası →</a></section>';$this->shellEnd();
    }

    public function services():void
    {
        $u=$this->customer();if(!$u)return;
        $q=$this->db->prepare("SELECT s.*,p.name AS product_name,sv.name AS server_name,sv.panel_type,sv.base_url FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id WHERE s.user_id=:user ORDER BY s.id DESC");
        $q->execute([':user'=>$u['id']]);$rows=$q->fetchAll();
        $this->shell($u,'Hizmetlerim');
        echo '<section class="panel"><div class="panelhead"><div><h1>Hosting hizmetlerim</h1><p>Aktif hizmetleriniz ve kontrol paneli erişim bilgileriniz</p></div><a class="back" href="'.$this->url('/client').'">← Müşteri paneli</a></div>';
        if(!$rows)echo '<div class="empty">Henüz hesabınıza bağlı bir hosting hizmeti bulunmuyor.</div>';
        foreach($rows as $row){
            echo '<article class="kb"><small>'.$this->e(strtoupper((string)($row['panel_type']??'BEKLEMEDE'))).' · '.$this->e((string)$row['status']).'</small><h2>'.$this->e($row['product_name']).'</h2><p>Domain: <b>'.$this->e((string)($row['remote_domain']??$row['domain']??'Henüz tanımlanmadı')).'</b><br>Sunucu: '.$this->e((string)($row['server_name']??'Henüz atanmadı')).'<br>Hesap durumu: '.$this->e((string)($row['provisioning_status']??'not_started')).'</p>';
            if(($row['provisioning_status']??'')==='completed'&&$row['remote_username']){
                $password='';try{$password=$this->vault->decrypt((string)$row['remote_password_enc']);}catch(Throwable){}
                echo '<details><summary>Hosting giriş bilgilerimi göster</summary><p>Kullanıcı adı: <b>'.$this->e((string)$row['remote_username']).'</b><br>Parola: <b>'.$this->e($password!==''?$password:'Parola bilgisi bulunamadı').'</b><br>Kontrol paneli adresi: '.$this->e($this->panelUrl($row)).'</p><small>Bu bilgiler yalnızca kendi hesabınızda görüntülenir. Güvenli bir yerde saklayın.</small></details>';
            }
            echo '</article>';
        }
        echo '</section>';$this->shellEnd();
    }

    public function tickets():void
    {
        $u=$this->customer();if(!$u)return;$s=$this->db->prepare('SELECT id,ticket_number,subject,department,status,updated_at FROM tickets WHERE user_id=:id ORDER BY updated_at DESC');$s->execute([':id'=>$u['id']]);$rows=$s->fetchAll();
        $this->shell($u,'Destek taleplerim');echo '<section class="panel"><div class="panelhead"><h1>Destek taleplerim</h1><a class="button" href="'.$this->url('/client/tickets/new').'">Yeni talep</a></div>';
        if(!$rows)echo '<div class="empty">Henüz destek talebiniz bulunmuyor.</div>';
        foreach($rows as $t)echo '<a class="ticketrow" href="'.$this->url('/client/tickets/'.$t['id']).'"><span><b>#'.$this->e($t['ticket_number']).' · '.$this->e($t['subject']).'</b><small>'.$this->e($t['department']).' · '.date('d.m.Y H:i',strtotime($t['updated_at'])).'</small></span><em>'.$this->e($this->status($t['status'])).'</em></a>';
        echo '</section>';$this->shellEnd();
    }

    public function newTicket():void
    {
        $u=$this->customer();if(!$u)return;$error='';
        if($_SERVER['REQUEST_METHOD']==='POST'){$subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));$department=(string)($_POST['department']??'General Support');
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif($subject===''||strlen($subject)>570||strlen($message)<10||strlen($message)>36000)$error='Konu ve mesaj alanlarını kontrol edin.';
            else{if(!in_array($department,['General Support','Billing','Technical Support','Sales'],true))$department='General Support';try{$this->db->beginTransaction();$now=gmdate('Y-m-d H:i:s');$no='HP'.gmdate('ymd').strtoupper(bin2hex(random_bytes(3)));$s=$this->db->prepare("INSERT INTO tickets(ticket_number,user_id,department,subject,priority,status,last_reply_at,created_at,updated_at) VALUES(:no,:uid,:d,:s,'normal','open',:reply,:created,:updated)");$s->execute([':no'=>$no,':uid'=>$u['id'],':d'=>$department,':s'=>$subject,':reply'=>$now,':created'=>$now,':updated'=>$now]);$id=(int)$this->db->lastInsertId();$m=$this->db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:t,:u,:m,0,:n)');$m->execute([':t'=>$id,':u'=>$u['id'],':m'=>$message,':n'=>$now]);$this->db->commit();$this->go('/client/tickets/'.$id);}catch(Throwable){if($this->db->inTransaction())$this->db->rollBack();$error='Talep kaydedilemedi.';}}
        }
        $this->shell($u,'Yeni destek talebi');$this->err($error);echo '<section class="panel formpanel"><h1>Yeni destek talebi</h1><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Konu<input name="subject" maxlength="190" required></label><label>Departman<select name="department"><option value="General Support">Genel destek</option><option value="Technical Support">Teknik destek</option><option value="Billing">Faturalandırma</option><option value="Sales">Satış</option></select></label><label>Mesaj<textarea name="message" rows="7" minlength="10" maxlength="12000" required></textarea></label><button class="button">Talebi gönder</button></form></section>';$this->shellEnd();
    }

    public function viewTicket(int $id):void
    {
        $u=$this->customer();if(!$u)return;$s=$this->db->prepare('SELECT * FROM tickets WHERE id=:id AND user_id=:uid LIMIT 1');$s->execute([':id'=>$id,':uid'=>$u['id']]);$t=$s->fetch();if(!$t){http_response_code(404);echo 'Destek talebi bulunamadı.';return;}$error='';
        if($_SERVER['REQUEST_METHOD']==='POST'){$msg=trim((string)($_POST['message']??''));if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';elseif($msg===''||strlen($msg)>36000)$error='Mesaj alanını kontrol edin.';elseif(in_array($t['status'],['closed','cancelled'],true))$error='Kapatılmış talebe yanıt gönderilemez.';else{$now=gmdate('Y-m-d H:i:s');$m=$this->db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:t,:u,:m,0,:n)');$m->execute([':t'=>$id,':u'=>$u['id'],':m'=>$msg,':n'=>$now]);$this->db->prepare("UPDATE tickets SET status='customer_reply',last_reply_at=:reply,updated_at=:updated WHERE id=:id")->execute([':reply'=>$now,':updated'=>$now,':id'=>$id]);$this->go('/client/tickets/'.$id);}}
        $m=$this->db->prepare('SELECT m.*,u.name FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:id ORDER BY m.created_at');$m->execute([':id'=>$id]);$this->shell($u,'Destek talebi');echo '<section class="panel"><a class="back" href="'.$this->url('/client/tickets').'">← Taleplere dön</a><h1>#'.$this->e($t['ticket_number']).' · '.$this->e($t['subject']).'</h1><p>'.$this->e($this->status($t['status'])).' · '.$this->e($t['department']).'</p>';
        foreach($m->fetchAll() as $x)echo '<article class="message"><b>'.$this->e($x['name']).'</b> <small>'.($x['is_staff']?'Destek ekibi':'Müşteri').' · '.date('d.m.Y H:i',strtotime($x['created_at'])).'</small><p>'.nl2br($this->e($x['message'])).'</p></article>';$this->err($error);
        if(!in_array($t['status'],['closed','cancelled'],true))echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Yanıtınız<textarea name="message" rows="5" maxlength="12000" required></textarea></label><button class="button">Yanıt gönder</button></form>';
        echo '</section>';$this->shellEnd();
    }

    public function knowledgebase():void
    {
        $rows=$this->db->query("SELECT title,excerpt,content,category FROM knowledgebase_articles WHERE status='published' ORDER BY sort_order,title")->fetchAll();$this->start('Bilgi bankası');echo '<main class="public"><div class="brand">HOST<span>PILOT</span></div><h1>Yardım merkezi</h1><p>Sıkça sorulan sorular ve destek makaleleri.</p>';
        if(!$rows)echo '<article class="kb"><h2>Bilgi bankası hazırlanıyor</h2><p>Yardım içerikleri yayınlandıkça burada görünecek.</p></article>';
        foreach($rows as $a)echo '<article class="kb"><small>'.$this->e($a['category']).'</small><h2>'.$this->e($a['title']).'</h2><p>'.nl2br($this->e($a['excerpt']?:substr(strip_tags($a['content']),0,540))).'</p></article>';
        echo '<a href="'.$this->url('/client/login').'">Müşteri paneline giriş →</a></main>';$this->end();
    }

    public function logout():void{if($_SERVER['REQUEST_METHOD']!=='POST'||!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(400);echo 'Geçersiz istek.';return;}Auth::logout();$this->go('/client/login');}
    private function customer():?array{$a=Auth::user();if(!$a){$this->go('/client/login');return null;}if(($a['role']??'')!=='customer'){http_response_code(403);echo 'Bu alan müşteri hesaplarına özeldir.';return null;}$s=$this->db->prepare("SELECT id,name,email,username,created_at FROM users WHERE id=:id AND status='active'");$s->execute([':id'=>$a['id']]);$u=$s->fetch();if(!$u){Auth::logout();$this->go('/client/login');return null;}return $u;}
    private function shell(array $u,string $title):void{$this->start($title);echo '<div class="client"><aside class="clientside"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><small>MÜŞTERİ ALANI</small><a href="'.$this->url('/client').'">Genel bakış</a><a href="'.$this->url('/client/orders').'">Siparişlerim</a><a href="'.$this->url('/client/invoices').'">Faturalarım</a><a href="'.$this->url('/client/tickets').'">Destek talepleri</a><a href="'.$this->url('/knowledgebase').'">Bilgi bankası</a><form method="post" action="'.$this->url('/client/logout').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button class="logout">Güvenli çıkış</button></form></aside><main class="clientmain"><header><span>'.$this->e($title).'</span><b>'.$this->e($u['name']).'</b></header>';}
    private function shellEnd():void{echo '<footer>HostPilot müşteri alanı · <a href="'.$this->url('/knowledgebase').'">Bilgi bankası</a></footer></main></div>';$this->end();}
    private function start(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>'.$this->css().'</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function err(string $e):void{if($e!=='')echo '<div class="error">'.$this->e($e).'</div>';}
    private function status(string $s):string{return ['open'=>'Açık','customer_reply'=>'Müşteri yanıtladı','staff_reply'=>'Destek yanıtladı','in_progress'=>'İşlemde','closed'=>'Kapalı','cancelled'=>'İptal'][$s]??$s;}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function go(string $p):never{header('Location: '.$this->url($p));exit;}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function css():string{return ':root{font-family:Inter,system-ui,sans-serif;color:#e8edf7;background:#0a1020}*{box-sizing:border-box}body{margin:0}.client{min-height:100vh;display:flex}.clientside{width:245px;flex:0 0 245px;background:#0e1729;border-right:1px solid #26354c;padding:25px 18px}.brand{font-size:19px;font-weight:900;letter-spacing:.12em;color:#fff;text-decoration:none;display:block;margin-bottom:36px}.brand span{color:#7898ff}.clientside small{display:block;color:#71829e;font-size:10px;letter-spacing:.14em;margin-bottom:12px}.clientside>a:not(.brand){display:block;padding:12px;border-radius:8px;text-decoration:none;color:#afbdd1;font-size:13px}.clientside>a:hover{background:#1b2a46;color:#fff}.clientside form{margin-top:25px}.logout{border:1px solid #35445b;background:transparent;color:#d5deed;padding:10px;border-radius:8px;width:100%}.clientmain{min-width:0;flex:1;padding:0 32px 30px;max-width:1500px;margin:auto}.clientmain>header{height:76px;border-bottom:1px solid #26354c;display:flex;justify-content:space-between;align-items:center;color:#a9b8ce;font-size:13px}.hero{padding:36px 0 24px}.hero span{font-size:10px;letter-spacing:.16em;color:#89a2e8;font-weight:800}.hero h1{font-size:28px;margin:9px 0}.hero p,.panel>p{color:#91a1b9;font-size:13px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.stats article,.panel{background:#111c2f;border:1px solid #26354c;border-radius:13px;padding:20px}.stats small{display:block;color:#9aabc2}.stats strong{display:block;font-size:25px;margin-top:10px}.panelhead{display:flex;justify-content:space-between;align-items:center;gap:15px}.panel h1,.panel h2{font-size:19px}.button{display:inline-block;background:#5278ed;color:white;border:0;border-radius:8px;padding:11px 15px;text-decoration:none;font-weight:700;cursor:pointer}.quick{display:block;padding:15px 0;border-top:1px solid #26354c;color:#c8d6eb;text-decoration:none;font-size:13px}.quick:first-of-type{margin-top:15px}.ticketrow{display:flex;justify-content:space-between;gap:15px;padding:16px 0;border-top:1px solid #26354c;color:#e7edf8;text-decoration:none}.ticketrow small{display:block;color:#8c9db6;margin-top:6px}.ticketrow em{font-size:11px;color:#9fbaef;font-style:normal}.empty{padding:25px;color:#8e9eb5}.formpanel{max-width:850px}.formpanel label,.panel label,.auth label{display:block;margin:18px 0 7px;color:#c5d1e3;font-size:12px}.formpanel input,.formpanel select,.formpanel textarea,.panel textarea,.auth input{display:block;width:100%;margin-top:8px;padding:12px;border-radius:8px;border:1px solid #34445e;background:#0b1425;color:#fff;font:inherit}.message{padding:17px 0;border-bottom:1px solid #26354c}.message small{color:#91a1b9;font-size:11px}.message p{color:#d0d9e8;font-size:13px;line-height:1.7}.back{color:#9eb7fa;text-decoration:none;font-size:12px}.error{padding:12px;border-radius:8px;background:#4b202b;color:#ffc3ca;font-size:12px;margin:14px 0}.clientmain footer{padding:25px 0;color:#74859f;font-size:11px}.clientmain footer a,.public>a,.auth>a{color:#9eb7fa}.auth,.public{width:min(650px,calc(100% - 28px));margin:8vh auto;background:#111c2f;border:1px solid #26354c;border-radius:16px;padding:30px}.auth h1,.public h1{font-size:25px}.auth p,.public>p{color:#91a1b9;font-size:13px}.auth input{margin-top:8px}.auth button{width:100%;margin:22px 0;background:#5278ed;color:#fff;border:0;border-radius:8px;padding:13px;font-weight:750}.kb{border:1px solid #26354c;border-radius:10px;padding:17px;margin:12px 0}.kb small{color:#8da7eb}.kb h2{font-size:16px}.kb p{font-size:13px;line-height:1.6;color:#aab9ce}@media(max-width:760px){.clientside{width:65px;flex-basis:65px;padding:20px 7px}.clientside .brand{font-size:0}.clientside .brand span{font-size:12px}.clientside small{font-size:0}.clientside>a:not(.brand){font-size:0;padding:13px 4px}.clientside>a:not(.brand):before{content:"•";font-size:20px}.clientside form button{font-size:0}.clientside form button:after{content:"↪";font-size:16px}.clientmain{padding:0 13px 20px}.stats{grid-template-columns:1fr}.panel{padding:15px}.panelhead{align-items:flex-start;flex-direction:column}.ticketrow{font-size:12px}.hero h1{font-size:22px}}';}
}
