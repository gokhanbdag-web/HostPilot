<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\EmailService;
use HostPilot\Core\SecretVault;
use PDO;
use Throwable;

final class ClientController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db, private readonly string $basePath = '',private readonly string $applicationKey='',private readonly string $baseUrl='') {$this->vault=new SecretVault($this->applicationKey);}

    public function login(): void
    {
        if(Auth::check() && (Auth::user()['role']??'')==='customer')$this->go('/client');$error='';
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
        if(Auth::check())$this->go('/client');$error='';$notice='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif($name===''||strlen($name)>80||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/',$username)||strlen($password)<12||strlen($password)>4096)$error='Bilgileri kontrol edin. Parola en az 12 karakter olmalıdır.';
            else try{
                $now=gmdate('Y-m-d H:i:s');
                $s=$this->db->prepare("INSERT INTO users(name,email,username,password_hash,role,status,created_at,updated_at) VALUES(:n,:e,:u,:p,'customer','pending_verification',:c,:u2)");
                $s->execute([':n'=>$name,':e'=>$email,':u'=>$username,':p'=>password_hash($password,PASSWORD_DEFAULT),':c'=>$now,':u2'=>$now]);
                $userId=(int)$this->db->lastInsertId();
                $queued=$this->queueVerificationEmail($userId,$name,$email);
                $notice='Hesabınız oluşturuldu. Giriş yapmadan önce e-posta adresinize gönderilen doğrulama bağlantısını açın.';
                if(!$queued)$notice.=' E-posta gönderimi henüz kuyruğa eklenemedi; doğrulama e-postasını yeniden istemeyi deneyin.';
            }catch(Throwable){$error='Bu e-posta veya kullanıcı adı kullanılıyor olabilir.';}
        }
        $this->start('Müşteri kaydı');echo '<main class="auth"><div class="brand">HOST<span>PILOT</span></div><h1>Hesap oluşturun</h1>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        $this->err($error);
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Ad soyad<input name="name" maxlength="80" required></label><label>Kullanıcı adı<input name="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9_.-]+" required></label><label>E-posta<input type="email" name="email" maxlength="190" required></label><label>Parola (en az 12 karakter)<input type="password" name="password" minlength="12" maxlength="4096" required></label><button>Hesap oluştur</button></form><a href="'.$this->url('/client/resend-verification').'">Doğrulama e-postasını yeniden gönder</a><br><a href="'.$this->url('/client/login').'">Zaten hesabım var</a></main>';$this->end();
    }

    public function verifyEmail(): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        $token=trim((string)($method==='POST'?($_POST['token']??''):($_GET['token']??'')));
        $message='Doğrulama bağlantısı geçersiz veya süresi dolmuş.';
        $success=false;$valid=false;
        if(preg_match('/^[a-f0-9]{64}$/D',$token)===1){
            if($method==='POST'&&!Auth::verifyCsrf($_POST['_token']??null)){
                $message='Güvenlik doğrulaması başarısız. Sayfayı yenileyin.';
            }else{
                try{
                    if($method==='GET'){
                        $q=$this->db->prepare("SELECT t.id FROM email_verification_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=:hash AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.role='customer' AND u.status='pending_verification' LIMIT 1");
                        $q->execute([':hash'=>hash('sha256',$token)]);$valid=(bool)$q->fetchColumn();
                    }elseif($method==='POST'){
                        $this->db->beginTransaction();
                        $q=$this->db->prepare("SELECT t.user_id FROM email_verification_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=:hash AND t.used_at IS NULL AND t.expires_at>UTC_TIMESTAMP() AND u.role='customer' AND u.status='pending_verification' LIMIT 1 FOR UPDATE");
                        $q->execute([':hash'=>hash('sha256',$token)]);$row=$q->fetch(PDO::FETCH_ASSOC);
                        if($row){
                            $userId=(int)$row['user_id'];
                            $this->db->prepare("UPDATE users SET status='active',email_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND role='customer' AND status='pending_verification'")->execute([':id'=>$userId]);
                            $this->db->prepare('UPDATE email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>$userId]);
                            $this->db->commit();$success=true;
                        }else $this->db->rollBack();
                    }
                }catch(Throwable $exception){
                    if($this->db->inTransaction())$this->db->rollBack();
                    error_log('[HostPilot] Email verification failed: '.$exception->getMessage());
                }
            }
        }
        $this->start('E-posta doğrulaması');
        echo '<main class="auth"><div class="brand">HOST<span>PILOT</span></div><h1>E-posta doğrulaması</h1>';
        if($success)echo '<div class="notice">E-posta adresiniz doğrulandı. Artık müşteri hesabınıza giriş yapabilirsiniz.</div><a href="'.$this->url('/client/login').'">Giriş yap</a>';
        elseif($valid&&$method==='GET')echo '<p>E-posta adresinizi doğrulamak ve hesabınızı etkinleştirmek için aşağıdaki düğmeye basın.</p><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="token" value="'.$this->e($token).'"><button>E-posta adresini doğrula</button></form>';
        else echo '<div class="error">'.$this->e($message).'</div><a href="'.$this->url('/client/resend-verification').'">Yeni doğrulama bağlantısı iste</a>';
        echo '</main>';$this->end();
    }

    public function resendVerification(): void
    {
        $notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$notice='Güvenlik doğrulaması başarısız. Sayfayı yenileyin.';
            else{
                $email=strtolower(trim((string)($_POST['email']??'')));
                if(filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($email)<=190){
                    try{
                        $q=$this->db->prepare("SELECT id,name,email FROM users WHERE email=:email AND role='customer' AND status='pending_verification' LIMIT 1");
                        $q->execute([':email'=>$email]);$user=$q->fetch(PDO::FETCH_ASSOC);
                        if($user)$this->queueVerificationEmail((int)$user['id'],(string)$user['name'],(string)$user['email']);
                    }catch(Throwable $exception){error_log('[HostPilot] Verification resend failed: '.$exception->getMessage());}
                }
                $notice='Adres kayıtlı ve doğrulama bekleyen bir hesaba aitse yeni bağlantı e-posta kuyruğuna eklenecektir.';
            }
        }
        $this->start('Doğrulama e-postasını yeniden gönder');
        echo '<main class="auth"><div class="brand">HOST<span>PILOT</span></div><h1>Doğrulama bağlantısı</h1><p>Kayıt sırasında kullandığınız e-posta adresini girin.</p>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>E-posta<input type="email" name="email" maxlength="190" autocomplete="email" required></label><button>Bağlantıyı gönder</button></form><a href="'.$this->url('/client/login').'">Giriş sayfasına dön</a></main>';$this->end();
    }

    private function queueVerificationEmail(int $userId,string $name,string $email): bool
    {
        if($userId<1||$this->applicationKey===''||$this->baseUrl===''||!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
        $cooldown=$this->db->prepare('SELECT id FROM email_verification_tokens WHERE user_id=:id AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND) LIMIT 1');
        $cooldown->execute([':id'=>$userId]);
        if($cooldown->fetchColumn())return false;
        $this->db->prepare('UPDATE email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND used_at IS NULL')->execute([':id'=>$userId]);
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=gmdate('Y-m-d H:i:s');
        $this->db->prepare('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at,created_at) VALUES(:id,:hash,:expires,:created)')
            ->execute([':id'=>$userId,':hash'=>$hash,':expires'=>gmdate('Y-m-d H:i:s',time()+86400),':created'=>$now]);
        $link=rtrim($this->baseUrl,'/').rtrim($this->basePath,'/').'/client/verify-email?token='.rawurlencode($token);
        $body="Merhaba ".$name.",\n\nHostPilot müşteri hesabınızı etkinleştirmek için 24 saat içinde aşağıdaki bağlantıyı açın:\n\n".$link."\n\nBu kaydı siz yapmadıysanız bu e-postayı dikkate almayın.";
        return (new EmailService($this->db,$this->applicationKey))->queue($email,'HostPilot e-posta adresinizi doğrulayın',$body,'email-verification:'.$userId.':'.$hash,'customer_email_verification');
    }

    public function dashboard():void
    {
        $u=$this->customer();if(!$u)return;$s=$this->db->prepare('SELECT COUNT(*) FROM tickets WHERE user_id=:id');$s->execute([':id'=>$u['id']]);$n=(int)$s->fetchColumn();
        $this->shell($u,'Müşteri paneli');echo '<section class="hero"><span>HOSTPILOT CLIENT AREA</span><h1>Merhaba, '.$this->e($u['name']).'</h1><p>Hesabınızı ve destek işlemlerinizi yönetin.</p></section><div class="stats"><article><small>Destek taleplerim</small><strong>'.$n.'</strong></article><article><small>Hesap durumu</small><strong>Aktif</strong></article><article><small>Üyelik tarihi</small><strong>'.date('d.m.Y',strtotime($u['created_at'])).'</strong></article></div><section class="panel"><h2>Destek merkezi</h2><p>Bir sorunuz varsa destek ekibimize ulaşın.</p><a class="button" href="'.$this->url('/client/tickets/new').'">Yeni destek talebi</a><a class="quick" href="'.$this->url('/client/orders').'">Siparişlerim →</a><a class="quick" href="'.$this->url('/client/services').'">Hosting hizmetlerim →</a><a class="quick" href="'.$this->url('/client/invoices').'">Faturalarım →</a><a class="quick" href="'.$this->url('/client/tickets').'">Destek taleplerim →</a><a class="quick" href="'.$this->url('/domains/search').'">Domain sorgula →</a><a class="quick" href="'.$this->url('/client/domains').'">Domainlerim →</a><a class="quick" href="'.$this->url('/knowledgebase').'">Sıkça sorulan sorular / Bilgi bankası →</a></section>';$this->shellEnd();
    }

    public function services():void
    {
        $u=$this->customer();if(!$u)return;
        $q=$this->db->prepare("SELECT s.*,p.name AS product_name,sv.name AS server_name,sv.panel_type,sv.base_url FROM services s JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id WHERE s.user_id=:user ORDER BY s.id DESC");
        $q->execute([':user'=>$u['id']]);$rows=$q->fetchAll();
        $this->shell($u,'Hizmetlerim');
        echo '<section class="panel"><div class="panelhead"><div><h1>Hosting hizmetlerim</h1><p>Aktif hizmetleriniz ve kontrol paneli erişim bilgileriniz</p></div><a class="back" href="'.$this->url('/client').'">← Müşteri paneli</a></div>';
        if(!empty($_GET['error']))echo '<div class="error">'.$this->e((string)$_GET['error']).'</div>';
        if(!empty($_GET['notice']))echo '<div class="notice">'.$this->e((string)$_GET['notice']).'</div>';
        if(!$rows)echo '<div class="empty">Henüz hesabınıza bağlı bir hosting hizmeti bulunmuyor.</div>';
        foreach($rows as $row){
            echo '<article class="kb"><small>'.$this->e(strtoupper((string)($row['panel_type']??'BEKLEMEDE'))).' · '.$this->e((string)$row['status']).'</small><h2>'.$this->e($row['product_name']).'</h2><p>Domain: <b>'.$this->e((string)($row['remote_domain']??$row['domain']??'Henüz tanımlanmadı')).'</b><br>Sunucu: '.$this->e((string)($row['server_name']??'Henüz atanmadı')).'<br>Hesap durumu: '.$this->e((string)($row['provisioning_status']??'not_started')).'</p>';
            if($row['status']==='pending'&&empty($row['provisioned_at'])&&!in_array(($row['provisioning_status']??''),['in_progress','completed','needs_review'],true)){
                echo '<form method="post" action="'.$this->url('/client/services/domain').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.(int)$row['id'].'"><label>Hosting hesabınız için alan adı<input type="text" name="domain" value="'.$this->e((string)($row['domain']??'')).'" placeholder="ornek.com" required></label><button class="button">Alan adını kaydet ve otomatik kurulumu sıraya al</button></form>';
            }elseif(($row['provisioning_status']??'')==='needs_review'){
                echo '<p>Otomatik işlem sonucu kesinleştirilemedi. Yinelenen hesap açılmasını önlemek için yönetici kontrolü bekleniyor.</p>';
            }
            $changeQuery=$this->db->prepare("SELECT c.id,c.status,c.effective_at,c.invoice_id,tp.name AS target_name FROM service_package_changes c JOIN products tp ON tp.id=c.to_product_id WHERE c.service_id=:service AND c.status IN ('pending_payment','scheduled','queued','processing','needs_review','failed') ORDER BY c.id DESC LIMIT 1");
            $changeQuery->execute([':service'=>$row['id']]);$change=$changeQuery->fetch();
            if($change){
                if($change['status']==='scheduled')echo '<p>Planlanan paket düşürme: <b>'.$this->e($change['target_name']).'</b><br>Uygulanma tarihi: '.$this->e(date('d.m.Y',strtotime($change['effective_at']))).'. Mevcut döneminiz sonuna kadar şimdiki paketiniz geçerlidir.</p><form method="post" action="'.$this->url('/client/services/change/cancel').'" onsubmit="return confirm(\'Planlanmış paket düşürme talebini iptal etmek istiyor musunuz?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="change_id" value="'.(int)$change['id'].'"><button class="button" style="background:#8a3946">Paket düşürme talebini iptal et</button></form>';
                elseif($change['status']==='pending_payment')echo '<p>Yükseltme talebiniz ödeme bekliyor.</p>';
                elseif($change['status']==='needs_review')echo '<p>Paket değişikliği kontrol paneliyle eşleştiriliyor; yönetici incelemesi bekleniyor.</p>';
                elseif($change['status']==='failed')echo '<p>Paket değişikliği tamamlanamadı; destek/yönetici incelemesi bekleniyor.</p>';
                else echo '<p>Paket değişikliği işlemi: '.$this->e((string)$change['status']).'</p>';
            }
            if(($row['status']??'')==='active'&&($row['provisioning_status']??'')==='completed'&&in_array(($row['panel_type']??''),['cpanel','plesk'],true)&&!$change){
                echo '<p><a class="quick" href="'.$this->url('/client/services/change?service_id='.(int)$row['id']).'">Paketimi değiştir veya düşür →</a></p>';
            }
            if(($row['provisioning_status']??'')==='completed'&&$row['remote_username']){
                $password='';try{$password=$this->vault->decrypt((string)$row['remote_password_enc']);}catch(Throwable){}
                echo '<details><summary>Hosting giriş bilgilerimi göster</summary><p>Kullanıcı adı: <b>'.$this->e((string)$row['remote_username']).'</b><br>Parola: <b>'.$this->e($password!==''?$password:'Parola bilgisi bulunamadı').'</b><br>Kontrol paneli adresi: '.$this->e($this->panelUrl($row)).'</p><small>Bu bilgiler yalnızca kendi hesabınızda görüntülenir. Güvenli bir yerde saklayın.</small></details>';
            }
            echo '</article>';
        }
        echo '</section>';$this->shellEnd();
    }

    public function saveServiceDomain():void
    {
        $u=$this->customer();if(!$u)return;
        if(!Auth::verifyCsrf($_POST['_token']??null)){$this->go('/client/services');}
        $id=(int)($_POST['service_id']??0);
        $domain=strtolower(trim((string)($_POST['domain']??'')));
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$domain)){$this->go('/client/services');}
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT * FROM services WHERE id=:id AND user_id=:user FOR UPDATE");
            $q->execute([':id'=>$id,':user'=>$u['id']]);$service=$q->fetch();
            if(!$service||$service['status']!=='pending'||!empty($service['provisioned_at'])||in_array($service['provisioning_status'],['in_progress','completed','needs_review'],true))throw new \RuntimeException('Bu hizmet için alan adı değiştirilemiyor.');
            $dupe=$this->db->prepare("SELECT COUNT(*) FROM services WHERE domain=:domain AND id<>:id AND status<>'cancelled'");
            $dupe->execute([':domain'=>$domain,':id'=>$id]);
            if((int)$dupe->fetchColumn()>0)throw new \RuntimeException('Bu alan adı başka bir hizmette kullanılıyor.');
            $now=gmdate('Y-m-d H:i:s');
            $this->db->prepare("UPDATE services SET domain=:domain,remote_domain=:domain2,provisioning_status='queued',provisioning_error=NULL,updated_at=:updated WHERE id=:id")
                ->execute([':domain'=>$domain,':domain2'=>$domain,':updated'=>$now,':id'=>$id]);
            $job=$this->db->prepare("INSERT INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,'create','queued',0,:available,:created,:updated) ON DUPLICATE KEY UPDATE status=IF(status IN ('failed','queued'),'queued',status),attempts=IF(status='failed',0,attempts),available_at=IF(status='failed',VALUES(available_at),available_at),last_error=IF(status='failed',NULL,last_error),updated_at=VALUES(updated_at)");
            $job->execute([':service'=>$id,':available'=>$now,':created'=>$now,':updated'=>$now]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();}
        $this->go('/client/services');
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
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));$department=(string)($_POST['department']??'General Support');
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif($subject===''||strlen($subject)>190||strlen($message)<10||strlen($message)>12000)$error='Konu ve mesaj alanlarını kontrol edin.';
            else{
                if(!in_array($department,['General Support','Billing','Technical Support','Sales'],true))$department='General Support';
                $storedFiles=[];
                try{
                    $this->db->beginTransaction();$now=gmdate('Y-m-d H:i:s');$no='HP'.gmdate('ymd').strtoupper(bin2hex(random_bytes(3)));
                    $s=$this->db->prepare("INSERT INTO tickets(ticket_number,user_id,department,subject,priority,status,last_reply_at,created_at,updated_at) VALUES(:no,:uid,:d,:s,'normal','open',:reply,:created,:updated)");
                    $s->execute([':no'=>$no,':uid'=>$u['id'],':d'=>$department,':s'=>$subject,':reply'=>$now,':created'=>$now,':updated'=>$now]);$id=(int)$this->db->lastInsertId();
                    $m=$this->db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:t,:u,:m,0,:n)');$m->execute([':t'=>$id,':u'=>$u['id'],':m'=>$message,':n'=>$now]);$messageId=(int)$this->db->lastInsertId();
                    $storedFiles=(new TicketAttachmentController($this->db,$this->basePath,HOSTPILOT_STORAGE))->storeUploads($id,$messageId,(int)$u['id']);
                    $this->db->commit();$this->notifyTicketStaff($id,$no,$subject,'created',$messageId);$this->go('/client/tickets/'.$id);
                }catch(Throwable $exception){if($this->db->inTransaction())$this->db->rollBack();TicketAttachmentController::cleanupStored($storedFiles);error_log('[HostPilot] Ticket create failed: '.$exception->getMessage());$error='Talep veya dosya ekleri kaydedilemedi. Dosya türü ve boyutunu kontrol edin.';}
            }
        }
        $this->shell($u,'Yeni destek talebi');$this->err($error);
        echo '<section class="panel formpanel"><h1>Yeni destek talebi</h1><form method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Konu<input name="subject" maxlength="190" required></label><label>Departman<select name="department"><option value="General Support">Genel destek</option><option value="Technical Support">Teknik destek</option><option value="Billing">Faturalandırma</option><option value="Sales">Satış</option></select></label><label>Mesaj<textarea name="message" rows="7" minlength="10" maxlength="12000" required></textarea></label><label>Dosya ekleri (en fazla 3 dosya, her biri 5 MB; PDF, JPG, PNG, TXT)<input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.txt,application/pdf,image/jpeg,image/png,text/plain" multiple></label><button class="button">Talebi gönder</button></form></section>';$this->shellEnd();
    }

    public function viewTicket(int $id):void
    {
        $u=$this->customer();if(!$u)return;$s=$this->db->prepare('SELECT * FROM tickets WHERE id=:id AND user_id=:uid LIMIT 1');$s->execute([':id'=>$id,':uid'=>$u['id']]);$t=$s->fetch();
        if(!$t){http_response_code(404);echo 'Destek talebi bulunamadı.';return;}$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $msg=trim((string)($_POST['message']??''));
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif($msg===''||strlen($msg)>12000)$error='Mesaj alanını kontrol edin.';
            elseif(in_array($t['status'],['closed','cancelled'],true))$error='Kapatılmış talebe yanıt gönderilemez.';
            else{
                $storedFiles=[];
                try{
                    $this->db->beginTransaction();$now=gmdate('Y-m-d H:i:s');
                    $m=$this->db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_staff,created_at) VALUES(:t,:u,:m,0,:n)');$m->execute([':t'=>$id,':u'=>$u['id'],':m'=>$msg,':n'=>$now]);$messageId=(int)$this->db->lastInsertId();
                    $storedFiles=(new TicketAttachmentController($this->db,$this->basePath,HOSTPILOT_STORAGE))->storeUploads($id,$messageId,(int)$u['id']);
                    $this->db->prepare("UPDATE tickets SET status='customer_reply',last_reply_at=:reply,updated_at=:updated WHERE id=:id")->execute([':reply'=>$now,':updated'=>$now,':id'=>$id]);
                    $this->db->commit();$this->notifyTicketStaff($id,(string)$t['ticket_number'],(string)$t['subject'],'customer_reply',$messageId);$this->go('/client/tickets/'.$id);
                }catch(Throwable $exception){if($this->db->inTransaction())$this->db->rollBack();TicketAttachmentController::cleanupStored($storedFiles);error_log('[HostPilot] Ticket reply failed: '.$exception->getMessage());$error='Yanıt veya dosya ekleri kaydedilemedi. Dosya türü ve boyutunu kontrol edin.';}
            }
        }
        $m=$this->db->prepare('SELECT m.*,u.name FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=:id ORDER BY m.created_at,m.id');$m->execute([':id'=>$id]);
        $attachmentService=new TicketAttachmentController($this->db,$this->basePath,HOSTPILOT_STORAGE);
        $this->shell($u,'Destek talebi');echo '<section class="panel"><a class="back" href="'.$this->url('/client/tickets').'">← Taleplere dön</a><h1>#'.$this->e($t['ticket_number']).' · '.$this->e($t['subject']).'</h1><p>'.$this->e($this->status($t['status'])).' · '.$this->e($t['department']).'</p>';
        foreach($m->fetchAll() as $x){
            echo '<article class="message"><b>'.$this->e($x['name']).'</b> <small>'.($x['is_staff']?'Destek ekibi':'Müşteri').' · '.date('d.m.Y H:i',strtotime($x['created_at'])).'</small><p>'.nl2br($this->e($x['message'])).'</p>';
            foreach($attachmentService->forMessage((int)$x['id']) as $file)echo '<p>📎 <a href="'.$this->url('/client/tickets/attachment/'.(int)$file['id']).'">'.$this->e($file['original_name']).' · '.number_format((int)$file['file_size']/1024,0).' KB</a></p>';
            echo '</article>';
        }
        $this->err($error);
        if(!in_array($t['status'],['closed','cancelled'],true))echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Yanıtınız<textarea name="message" rows="5" maxlength="12000" required></textarea></label><label>Dosya ekleri (en fazla 3 dosya, her biri 5 MB; PDF, JPG, PNG, TXT)<input type="file" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png,.txt,application/pdf,image/jpeg,image/png,text/plain" multiple></label><button class="button">Yanıt gönder</button></form>';
        echo '</section>';$this->shellEnd();
    }

    private function notifyTicketStaff(int $ticketId,string $ticketNumber,string $subject,string $event,int $messageId):void
    {
        if($this->applicationKey==='')return;
        try{
            $q=$this->db->query("SELECT id,email FROM users WHERE role IN ('super_admin','admin','staff') AND status='active' AND email<>''");
            $mail=new EmailService($this->db,$this->applicationKey);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $staff)$mail->queueTicketNotice((string)$staff['email'],$ticketNumber,$subject,$event,'ticket:'.$ticketId.':'.$event.':'.$messageId.':'.$staff['id']);
        }catch(Throwable $e){error_log('[HostPilot] Ticket email queue: '.$e->getMessage());}
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
    private function shell(array $u,string $title):void{$this->start($title);echo '<div class="client"><aside class="clientside"><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><small>MÜŞTERİ ALANI</small><a href="'.$this->url('/client').'">Genel bakış</a><a href="'.$this->url('/client/orders').'">Siparişlerim</a><a href="'.$this->url('/client/invoices').'">Faturalarım</a><a href="'.$this->url('/client/tickets').'">Destek talepleri</a><a href="'.$this->url('/client/privacy').'">Gizlilik ve verilerim</a><a href="'.$this->url('/knowledgebase').'">Bilgi bankası</a><form method="post" action="'.$this->url('/client/logout').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><button class="logout">Güvenli çıkış</button></form></aside><main class="clientmain"><header><span>'.$this->e($title).'</span><b>'.$this->e($u['name']).'</b></header>';}
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
