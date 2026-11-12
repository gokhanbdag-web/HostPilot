<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\ResellerClubClient;
use HostPilot\Core\SecretVault;
use PDO;
use Throwable;

final class DomainController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,private readonly string $basePath,private readonly string $applicationKey)
    {$this->vault=new SecretVault($applicationKey);}

    public function settings():void
    {
        if(!$this->admin())return;
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $environment=(string)($_POST['environment']??'sandbox');$userId=trim((string)($_POST['user_id']??''));$apiKey=(string)($_POST['api_key']??'');$enabled=isset($_POST['enabled'])?'1':'0';
                if(!in_array($environment,['sandbox','live'],true)||!preg_match('/^[0-9]{1,20}$/',$userId)||strlen($apiKey)>1000)$error='ResellerClub bağlantı bilgilerini kontrol edin.';
                else try{
                    $old=$this->load();$secret=$apiKey!==''?$this->vault->encrypt($apiKey):(string)($old['api_key_enc']??'');
                    if($secret==='')throw new \RuntimeException('API anahtarı gerekli. İlk kayıt sırasında anahtarı girin.');
                    $values=['enabled'=>$enabled,'environment'=>$environment,'user_id'=>$userId,'api_key_enc'=>$secret];
                    foreach($values as $key=>$value){$q=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('domain_registrar',:key,:value,:secret,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret),updated_at=VALUES(updated_at)");$q->execute([':key'=>$key,':value'=>$value,':secret'=>$key==='api_key_enc'?1:0,':updated'=>gmdate('Y-m-d H:i:s')]);}
                    $notice='ResellerClub ayarları kaydedildi.';
                }catch(Throwable $e){$error='Ayarlar kaydedilemedi: '.$e->getMessage();}
            }
            if($error!=='')$this->go('/admin/domains/registrar','error='.rawurlencode($error));
            $this->go('/admin/domains/registrar','notice='.rawurlencode($notice));
        }
        $cfg=$this->load();$this->page('Domain registrar ayarları');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/domains/registrar').'">Domain registrar</a><a class="nav-item" href="'.$this->url('/admin/servers').'">Sunucular</a></aside><main class="main"><header class="topbar"><div><small>ENTEGRASYONLAR / DOMAIN</small><h1>ResellerClub bağlantısı</h1></div><a href="'.$this->url('/admin').'">Kontrol merkezi</a></header><article class="panel"><p>ResellerClub REST API v2 bağlantısı. İlk kurulumda sandbox kullanın. Canlı ortamı yalnızca kendi reseller hesabınızın API anahtarıyla etkinleştirin. API anahtarı kurulum anahtarıyla şifrelenir ve ekranda tekrar gösterilmez.</p>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label><input type="checkbox" name="enabled" value="1" '.(($cfg['enabled']??'0')==='1'?'checked':'').'> Domain sorgulama entegrasyonunu etkinleştir</label><label>API ortamı<select name="environment"><option value="sandbox" '.(($cfg['environment']??'sandbox')==='sandbox'?'selected':'').'>Sandbox (test)</option><option value="live" '.(($cfg['environment']??'')==='live'?'selected':'').'>Canlı</option></select></label><label>Reseller User ID<input name="user_id" required value="'.$this->e((string)($cfg['user_id']??'')).'"></label><label>API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="'.(!empty($cfg['api_key_enc'])?'Kayıtlı anahtar korunuyor; değiştirmek için yazın':'ResellerClub API Key').'"></label><button>Ayarları kaydet</button></form><hr><h3>Müşteri domain sorgulama</h3><p>Bağlantıyı kaydettikten sonra müşteri alanından gerçek müsaitlik kontrolü yapabilirsiniz. Bu adım domain satın alımı veya kayıt işlemi başlatmaz.</p><a class="button" href="'.$this->url('/domains/search').'">Domain sorgulama sayfasını aç →</a></article></main></div>';$this->end();
    }

    public function search():void
    {
        $u=Auth::user();if(!$u){$this->go('/client/login');return;}if(($u['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $domain='';$result=null;$error='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $domain=strtolower(trim((string)($_POST['domain']??'')));
                try{$cfg=$this->load();if(($cfg['enabled']??'0')!=='1')throw new \RuntimeException('Domain sorgulama şu anda etkin değil.');if(empty($cfg['api_key_enc']))throw new \RuntimeException('Registrar API anahtarı tanımlanmamış.');$api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)$cfg['environment']);$result=$api->checkAvailability($domain);}
                catch(Throwable $e){$error=$e->getMessage();}
            }
        }
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Domain sorgula · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}main{max-width:850px;margin:40px auto;padding:24px}.brand{color:white;text-decoration:none;font-weight:900}.brand span{color:#7898ff}.panel{margin-top:24px;padding:26px;background:#111c2f;border:1px solid #26354c;border-radius:16px}input{width:100%;box-sizing:border-box;padding:14px;background:#0b1425;color:white;border:1px solid #34445e;border-radius:9px;margin:8px 0 16px}button,.button{display:inline-block;padding:12px 18px;background:#5278ed;color:white;border:0;border-radius:8px;text-decoration:none;font-weight:700}.notice,.error{padding:14px;border-radius:8px;margin-top:18px}.notice{background:#123b31;color:#9df0c8}.error{background:#4b202b;color:#ffc3ca}.muted{color:#9aabc2}</style></head><body><main><a class="brand" href="'.$this->url('/client').'">HOST<span>PILOT</span></a><p><a href="'.$this->url('/client').'">← Müşteri paneli</a></p><section class="panel"><h1>Alan adı sorgula</h1><p class="muted">İstediğiniz alan adının registrar üzerinden anlık müsaitlik durumunu kontrol edin.</p><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Alan adı</label><input name="domain" value="'.$this->e($domain).'" placeholder="ornek.com" maxlength="253" required><button>Uygunluğu kontrol et</button></form>';
        if($error!=='')echo '<div class="error">'.$this->e($error).'</div>';
        if($result!==null){if($result['available'])echo '<div class="notice"><b>'.$this->e($result['domain']).'</b> kayıt için uygun görünüyor.'.($result['price']!==null?' Registrar fiyat bilgisi: '.$this->e($result['price']):'').' <p>Bu yalnızca uygunluk sonucudur; henüz sipariş veya kayıt oluşturulmadı.</p></div>';else echo '<div class="error"><b>'.$this->e($result['domain']).'</b> şu anda kayıt için uygun değil.</div>';}
        echo '</section></main></body></html>';
    }

    public function myDomains():void
    {
        $user=Auth::user();if(!$user){$this->go('/client/login');return;}if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $q=$this->db->prepare('SELECT d.*,i.invoice_number,i.status AS invoice_status FROM domain_registrations d JOIN invoices i ON i.id=d.invoice_id WHERE d.user_id=:user ORDER BY d.created_at DESC');
        $q->execute([':user'=>$user['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $labels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kayıt kuyruğunda','processing'=>'İşleniyor','customer_created'=>'Registrar müşteri kaydı açıldı','contact_created'=>'Alan adı iletişim kaydı açıldı','pending_remote'=>'Registrar kayıt işlemi sürüyor','active'=>'Aktif','failed'=>'Başarısız','needs_review'=>'Yönetici incelemesi gerekli','cancelled'=>'İptal edildi'];
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Domainlerim · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}main{max-width:1000px;margin:30px auto;padding:20px}.panel{background:#111c2f;border:1px solid #26354c;border-radius:14px;padding:22px;margin-top:20px}.muted,small{color:#9aabc2}.item{padding:16px 0;border-bottom:1px solid #26354c}.item:last-child{border:0}a{color:#9eb7fa}</style></head><body><main><a href="'.$this->url('/client').'">← Müşteri paneli</a><section class="panel"><h1>Domainlerim</h1><p class="muted">Satın aldığınız alan adlarının ödeme ve registrar kayıt durumları.</p>';
        if(!$rows)echo '<p>Henüz domain siparişiniz bulunmuyor. <a href="'.$this->url('/domains/search').'">Alan adı sorgulayın.</a></p>';
        foreach($rows as $row){$status=$labels[$row['status']]??(string)$row['status'];echo '<article class="item"><h2>'.$this->e((string)$row['domain_name']).'</h2><b>'.$this->e($status).'</b><p class="muted">'.$this->e((string)$row['years']).' yıl · Fatura #'.$this->e((string)$row['invoice_number']).' · Fatura: '.$this->e((string)$row['invoice_status']).'</p>';if(!empty($row['action_message']))echo '<p>'.$this->e((string)$row['action_message']).'</p>';if($row['status']==='needs_review'||$row['status']==='failed')echo '<p>İşlem destek ekibinin kontrolüne alındı. Yinelenen kayıt oluşturmamak için otomatik yeniden gönderim yapılmayacak.</p>';echo '</article>';}
        echo '</section></main></body></html>';
    }

    public function adminOrders():void
    {
        if(!$this->admin())return;
        $rows=$this->db->query('SELECT d.*,u.name,u.email,i.invoice_number,i.status AS invoice_status FROM domain_registrations d JOIN users u ON u.id=d.user_id JOIN invoices i ON i.id=d.invoice_id ORDER BY d.created_at DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
        $labels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kuyrukta','processing'=>'İşleniyor','customer_created'=>'Müşteri kaydı','contact_created'=>'İletişim kaydı','pending_remote'=>'Registrar bekleniyor','active'=>'Aktif','failed'=>'Başarısız','needs_review'=>'İnceleme gerekli','cancelled'=>'İptal'];
        $this->page('Domain siparişleri');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin/domains/registrar').'">Registrar ayarları</a><a class="nav-item active" href="'.$this->url('/admin/domains/orders').'">Domain siparişleri</a></aside><main class="main"><header class="topbar"><div><small>DOMAIN / SİPARİŞLER</small><h1>Domain kayıt kuyruğu</h1></div><a href="'.$this->url('/admin/domains/registrar').'">Registrar ayarları</a></header><article class="panel"><p>Belirsiz registrar yanıtları otomatik tekrar gönderilmez; manuel doğrulama gerektirir.</p>';
        if(!$rows)echo '<p>Henüz domain siparişi yok.</p>';
        foreach($rows as $row)echo '<div style="padding:14px 0;border-bottom:1px solid #26354c"><b>'.$this->e((string)$row['domain_name']).'</b> · '.$this->e($labels[$row['status']]??(string)$row['status']).'<p>'.$this->e((string)$row['name']).' · '.$this->e((string)$row['email']).' · #'.$this->e((string)$row['invoice_number']).' · '.$this->e((string)$row['invoice_status']).'</p><small>Registrar order: '.$this->e((string)($row['registrar_order_id']??'—')).' · Action: '.$this->e((string)($row['action_status']??'—')).'</small>'.(!empty($row['last_error'])?'<p class="alert">'.$this->e((string)$row['last_error']).'</p>':'').'</div>';
        echo '</article></main></div>';$this->end();
    }

    public function runRegistrationQueue():void
    {
        $provided='';
        if(preg_match('/Bearer\\s+(.+)/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$m))$provided=trim($m[1]);
        if($provided===''&&isset($_GET['token']))$provided=(string)$_GET['token'];
        $expected=hash_hmac('sha256','HostPilot domain cron v1',$this->applicationKey);
        if($this->applicationKey===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo 'Forbidden';return;}
        header('Content-Type: application/json; charset=utf-8');
        $row=null;$stage='';$remoteCallStarted=false;
        try{
            $now=gmdate('Y-m-d H:i:s');
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE domain_registrations SET status='needs_review',locked_at=NULL,last_error='Worker yanıtı zaman aşımına uğradı; registrar tarafındaki son durum doğrulanmalı.',updated_at=:now WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff")
                ->execute([':now'=>$now,':cutoff'=>gmdate('Y-m-d H:i:s',time()-900)]);
            $pick=$this->db->prepare("SELECT * FROM domain_registrations WHERE status IN ('queued','customer_created','contact_created','pending_remote') AND available_at<=:now AND (locked_at IS NULL OR locked_at<:stale) ORDER BY available_at,id LIMIT 1 FOR UPDATE");
            $pick->execute([':now'=>$now,':stale'=>gmdate('Y-m-d H:i:s',time()-900)]);
            $row=$pick->fetch(PDO::FETCH_ASSOC);
            if(!$row){$this->db->commit();echo json_encode(['ok'=>true,'processed'=>0,'message'=>'Bekleyen domain kaydı yok.']);return;}
            $stage=(string)$row['status'];
            $this->db->prepare("UPDATE domain_registrations SET status='processing',locked_at=:locked,attempts=attempts+1,updated_at=:updated WHERE id=:id")
                ->execute([':locked'=>$now,':updated'=>$now,':id'=>$row['id']]);
            $this->db->commit();

            $cfg=$this->load();
            if(($cfg['enabled']??'0')!=='1'||empty($cfg['api_key_enc'])||empty($cfg['user_id']))throw new \\RuntimeException('Domain registrar bağlantısı etkin değil veya eksik.');
            $api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)($cfg['environment']??'sandbox'));
            if(in_array($stage,['queued','customer_created','contact_created'],true)){
                $payload=json_decode($this->vault->decrypt((string)$row['registrar_payload_enc']),true,32,JSON_THROW_ON_ERROR);
                if(!is_array($payload)||($stage!=='contact_created'&&(empty($payload['customer'])||empty($payload['contact']))))throw new \\RuntimeException('Şifreli domain kayıt bilgileri okunamadı.');
                $customerId=(string)($row['registrar_customer_id']??'');
                $contactId=(string)($row['registrar_contact_id']??'');
                if($stage==='queued'){
                    $remoteCallStarted=true;
                    $availability=$api->checkAvailability((string)$row['domain_name']);
                    if(empty($availability['available']))throw new \\RuntimeException('Ödeme tamamlandı ancak alan adı son kontrol sırasında artık uygun değil. Yönetici incelemesi gerekli.');
                    $remoteCallStarted=true;
                    $customerId=$api->createCustomer((array)$payload['customer']);
                    unset($payload['customer']['password'],$payload['customer']['username']);
                    $payloadEnc=$this->vault->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                    $this->db->prepare("UPDATE domain_registrations SET registrar_customer_id=:customer,registrar_payload_enc=:payload,status='customer_created',locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':customer'=>$customerId,':payload'=>$payloadEnc,':available'=>gmdate('Y-m-d H:i:s'),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    $stage='customer_created';
                }
                if($stage==='customer_created'){
                    if($customerId===''||!ctype_digit($customerId))throw new \\RuntimeException('Registrar customerId kaydı eksik; manuel kontrol gerekli.');
                    $payload['contact']['customerId']=$customerId;
                    $remoteCallStarted=true;
                    $contactId=$api->createContact((array)$payload['contact']);
                    $emptyPayload=$this->vault->encrypt('{}');
                    $this->db->prepare("UPDATE domain_registrations SET registrar_contact_id=:contact,registrar_payload_enc=:payload,status='contact_created',locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':contact'=>$contactId,':payload'=>$emptyPayload,':available'=>gmdate('Y-m-d H:i:s'),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    $stage='contact_created';
                }
                if($stage==='contact_created'){
                    if($customerId===''||$contactId===''||!ctype_digit($customerId)||!ctype_digit($contactId))throw new \\RuntimeException('Registrar müşteri/iletişim kimliği eksik; manuel kontrol gerekli.');
                    $remoteCallStarted=true;
                    $result=$api->registerDomain((string)$row['domain_name'],(int)$row['years'],$customerId,$contactId,(array)($payload['nameservers']??[]),(string)($payload['invoiceOption']??'OnlyAdd'),(bool)($payload['autoRenew']??false));
                    $this->db->prepare("UPDATE domain_registrations SET registrar_order_id=:order_id,status='pending_remote',action_status=:action,action_message=:message,locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':order_id'=>(string)$result['orderId'],':action'=>(string)($result['actionStatus']??'Added'),':message'=>substr((string)($result['message']??'Kayıt talebi registrar tarafından alındı.'),0,500),':available'=>gmdate('Y-m-d H:i:s',time()+45),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'status'=>'pending_remote'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
                }
            }
            if($stage==='pending_remote'){
                $orderId=(string)($row['registrar_order_id']??'');
                if($orderId==='')throw new \\RuntimeException('Registrar orderId eksik; otomatik tekrar güvenli değil.');
                $remoteCallStarted=true;$statusData=$api->actionStatus($orderId);
                $actions=$statusData['actions']??[];$action=is_array($actions)&&isset($actions[0])&&is_array($actions[0])?$actions[0]:null;
                $actionStatus=(string)($action['actionStatus']??'');
                $message=substr((string)($action['actionStatusDesc']??$action['message']??''),0,500);
                if(strcasecmp($actionStatus,'Completed')===0){
                    $this->db->prepare("UPDATE domain_registrations SET status='active',action_status=:action,action_message=:message,registered_at=:registered,locked_at=NULL,last_error=NULL,updated_at=:updated WHERE id=:id")
                        ->execute([':action'=>$actionStatus,':message'=>$message,':registered'=>gmdate('Y-m-d H:i:s'),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'status'=>'active'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
                }
                if(strcasecmp($actionStatus,'Failed')===0){
                    $this->db->prepare("UPDATE domain_registrations SET status='failed',action_status=:action,action_message=:message,last_error=:error,locked_at=NULL,updated_at=:updated WHERE id=:id")
                        ->execute([':action'=>$actionStatus,':message'=>$message,':error'=>$message!==''?$message:'Registrar domain kayıt işlemini başarısız olarak bildirdi.',':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'status'=>'failed'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
                }
                $attempts=(int)$row['attempts']+1;$nextStatus=$attempts>=40?'needs_review':'pending_remote';
                $this->db->prepare("UPDATE domain_registrations SET status=:status,action_status=:action,action_message=:message,locked_at=NULL,available_at=:available,last_error=:error,updated_at=:updated WHERE id=:id")
                    ->execute([':status'=>$nextStatus,':action'=>$actionStatus?:'Pending',':message'=>$message,':available'=>gmdate('Y-m-d H:i:s',time()+120),':error'=>$nextStatus==='needs_review'?'Registrar işlemi beklenen sürede tamamlanmadı; manuel kontrol gerekli.':null,':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'status'=>$nextStatus,'action_status'=>$actionStatus],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
            }
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            error_log('[HostPilot] Domain registration worker: '.$e->getMessage());
            if($row){
                $next=($remoteCallStarted||in_array($stage,['customer_created','contact_created','pending_remote'],true))?'needs_review':'failed';
                $this->db->prepare("UPDATE domain_registrations SET status=:status,locked_at=NULL,last_error=:error,updated_at=:updated WHERE id=:id")
                    ->execute([':status'=>$next,':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
            }
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Domain kayıt işlemi tamamlanamadı; tekrarın güvenli olmadığı durumlarda manuel incelemeye alındı.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    private function load():array{$q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='domain_registrar'");$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['setting_key']]=$r['setting_value'];return $out;}
    private function admin():bool{$u=Auth::user();if(!$u){$this->go('/admin/login');return false;}if(!in_array($u['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return false;}return true;}
    private function page(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.nav-item.active{background:#1b2a46;border-radius:8px}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1300px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.topbar h1{font-size:21px}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p{color:#9aabc2;line-height:1.6}.panel label{display:block;margin:14px 0;color:#c4d0e2}.panel input:not([type=checkbox]),.panel select{display:block;width:100%;margin-top:7px;padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.panel button,.button{padding:12px 17px;background:#5278ed;color:white;border:0;border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none}.panel hr{border:0;border-top:1px solid #26354c;margin:28px 0}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0;background:#172f35;color:#a9f0d0}.alert{background:#4b202b;color:#ffc3ca}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.sidebar .brand{font-size:0}.sidebar .brand span{font-size:12px}.nav-item{font-size:0}.main{padding:0 12px 20px}}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$url=rtrim($this->basePath,'/').$p;if($query!=='')$url.='?'.$query;header('Location: '.$url);exit;}
}