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
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/domains/registrar').'">Domain registrar</a><a class="nav-item" href="'.$this->url('/admin/domains/orders').'">Domain siparişleri</a><a class="nav-item" href="'.$this->url('/admin/servers').'">Sunucular</a></aside><main class="main"><header class="topbar"><div><small>ENTEGRASYONLAR / DOMAIN</small><h1>ResellerClub bağlantısı</h1></div><a href="'.$this->url('/admin').'">Kontrol merkezi</a></header><article class="panel"><p>ResellerClub REST API v2 bağlantısı. İlk kurulumda sandbox kullanın. Canlı ortamı yalnızca kendi reseller hesabınızın API anahtarıyla etkinleştirin. API anahtarı kurulum anahtarıyla şifrelenir ve ekranda tekrar gösterilmez.</p>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label><input type="checkbox" name="enabled" value="1" '.(($cfg['enabled']??'0')==='1'?'checked':'').'> Domain registrar entegrasyonunu etkinleştir (sorgulama ve kayıt)</label><label>API ortamı<select name="environment"><option value="sandbox" '.(($cfg['environment']??'sandbox')==='sandbox'?'selected':'').'>Sandbox (test)</option><option value="live" '.(($cfg['environment']??'')==='live'?'selected':'').'>Canlı</option></select></label><label>Reseller User ID<input name="user_id" required value="'.$this->e((string)($cfg['user_id']??'')).'"></label><label>API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="'.(!empty($cfg['api_key_enc'])?'Kayıtlı anahtar korunuyor; değiştirmek için yazın':'ResellerClub API Key').'"></label><button>Ayarları kaydet</button></form><hr><h3>Müşteri domain sorgulama</h3><p>Bağlantıyı kaydettikten sonra müşteri alanından gerçek müsaitlik kontrolü yapabilirsiniz. Satışa açtığınız domain ürünlerinde müşteri ödemesi doğrulanınca kayıt kuyruğu çalışır. Registrar kaydı güvenli varsayılan olarak <b>OnlyAdd</b> seçeneğiyle gönderilir; ResellerClub tarafındaki faturayı ayrıca ödemeniz gerekebilir. Bu seçenek reseller bakiyesinden sessizce otomatik tahsilat yapmaz.</p><p>Domain kayıt işçisi: <code>/cron/domains?token=...</code>. Token, kurulum anahtarıyla HMAC-SHA256 kullanılarak üretilir. İşlem durumlarını bu ekrandaki Domain siparişleri bölümünden izleyin.</p><a class="button" href="'.$this->url('/admin/domains/orders').'">Domain siparişlerini yönet →</a><a class="button" href="'.$this->url('/domains/search').'">Domain sorgulama sayfasını aç →</a></article></main></div>';$this->end();
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

    public function requestNameservers():void
    {
        $user=Auth::user();if(!$user){$this->go('/client/login');return;}if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['domain_id']??0);$servers=preg_split('/[\\r\\n,;]+/',trim((string)($_POST['nameservers']??'')))?:[];$servers=array_values(array_unique(array_map(static fn($v)=>strtolower(trim((string)$v)),$servers)));
        try{
            if(count($servers)<2||count($servers)>5)throw new \RuntimeException('İki ile beş arasında nameserver girin.');
            foreach($servers as $server)if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',$server))throw new \RuntimeException('Nameserver biçimi geçersiz: '.$server);
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT * FROM domain_registrations WHERE id=:id AND user_id=:user FOR UPDATE");$q->execute([':id'=>$id,':user'=>$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='active'||empty($row['registrar_order_id']))throw new \RuntimeException('Yalnızca aktif ve registrar orderId bilgisi bulunan domainlerde nameserver değiştirilebilir.');
            if(in_array($row['nameserver_status']??'', ['queued','processing','needs_review'],true))throw new \RuntimeException('Önceki nameserver işlemi tamamlanmalı veya destek ekibince incelenmeli.');
            $encrypted=$this->vault->encrypt(json_encode($servers,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            $this->db->prepare("UPDATE domain_registrations SET pending_nameservers_enc=:payload,nameserver_status='queued',nameserver_error=NULL,nameserver_updated_at=:now,updated_at=:now WHERE id=:id")->execute([':payload'=>$encrypted,':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->db->commit();$this->go('/client/domains','notice='.rawurlencode('Nameserver değişikliği güvenli işlem kuyruğuna alındı.'));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/client/domains','error='.rawurlencode($e->getMessage()));}
    }

    public function syncDomainDetails():void
    {
        $user=Auth::user();if(!$user){$this->go('/client/login');return;}if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['domain_id']??0);
        try{
            $q=$this->db->prepare("SELECT id,registrar_order_id,status FROM domain_registrations WHERE id=:id AND user_id=:user LIMIT 1");
            $q->execute([':id'=>$id,':user'=>$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='active'||empty($row['registrar_order_id']))throw new \RuntimeException('Yalnızca aktif ve registrar orderId bilgisi bulunan domainler eşitlenebilir.');
            $cfg=$this->load();if(($cfg['enabled']??'0')!=='1'||empty($cfg['api_key_enc'])||empty($cfg['user_id']))throw new \RuntimeException('Domain registrar bağlantısı etkin değil veya eksik.');
            $api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)($cfg['environment']??'sandbox'));
            $details=$api->domainDetails((string)$row['registrar_order_id']);
            $expiryRaw=$details['expiryDate']??$details['endTime']??$details['domainEndTime']??null;
            $expiry=$this->parseRegistrarExpiry($expiryRaw);
            if($expiry===null)throw new \RuntimeException('Registrar yanıtında doğrulanabilir son kullanma tarihi bulunamadı.');
            $autoRenew=array_key_exists('isAutoRenew',$details)?(filter_var($details['isAutoRenew'],FILTER_VALIDATE_BOOLEAN)?1:0):null;
            $now=gmdate('Y-m-d H:i:s');
            $this->db->prepare('UPDATE domain_registrations SET expiry_date=:expiry,registrar_auto_renew=:auto_renew,registrar_synced_at=:synced,registrar_sync_attempted_at=:synced,registrar_sync_error=NULL,updated_at=:updated WHERE id=:id')
                ->execute([':expiry'=>$expiry,':auto_renew'=>$autoRenew,':synced'=>$now,':updated'=>$now,':id'=>$id]);
            $this->go('/client/domains','notice='.rawurlencode('Domain bitiş tarihi ve otomatik yenileme bilgisi registrar üzerinden güncellendi.'));
        }catch(Throwable $e){$this->go('/client/domains','error='.rawurlencode($e->getMessage()));}
    }

    public function requestRenewal():void
    {
        $user=Auth::user();if(!$user){$this->go('/client/login');return;}if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['domain_id']??0);$years=(int)($_POST['years']??1);
        $cycles=[1=>'annually',2=>'biennially',3=>'triennially'];
        try{
            if(!isset($cycles[$years]))throw new \RuntimeException('Yenileme süresi 1, 2 veya 3 yıl olmalı.');
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT d.*,oi.product_id FROM domain_registrations d JOIN order_items oi ON oi.order_id=d.order_id JOIN products p ON p.id=oi.product_id AND p.product_type='domain' WHERE d.id=:id AND d.user_id=:user AND d.status='active' LIMIT 1 FOR UPDATE");
            $q->execute([':id'=>$id,':user'=>$user['id']]);$domain=$q->fetch(PDO::FETCH_ASSOC);
            if(!$domain||empty($domain['registrar_order_id'])||empty($domain['expiry_date'])||empty($domain['registrar_synced_at']))throw new \RuntimeException('Yenileme için önce aktif domainin registrar bitiş bilgilerini eşitleyin.');
            if(strtotime((string)$domain['expiry_date'])<=time())throw new \RuntimeException('Süresi dolmuş domain otomatik yenileme talebine uygun değil; destek ekibine başvurun.');
            if(str_ends_with(strtolower((string)$domain['domain_name']),'.ai')&&$years!==2)throw new \RuntimeException('ResellerClub .AI domainleri yalnızca 2 yıllık dönemle yenilenebilir.');
            $pending=$this->db->prepare("SELECT COUNT(*) FROM domain_renewals WHERE domain_registration_id=:id AND status IN ('awaiting_payment','queued','processing','pending_remote','needs_review')");
            $pending->execute([':id'=>$id]);if((int)$pending->fetchColumn()>0)throw new \RuntimeException('Bu domain için zaten bekleyen bir yenileme işlemi var.');
            $price=$this->db->prepare("SELECT p.name,pp.price,pp.currency,pp.billing_cycle FROM products p JOIN product_pricing pp ON pp.product_id=p.id WHERE p.id=:product AND p.status='active' AND pp.billing_cycle=:cycle AND pp.is_active=1 LIMIT 1");
            $price->execute([':product'=>$domain['product_id'],':cycle'=>$cycles[$years]]);$pricing=$price->fetch(PDO::FETCH_ASSOC);
            if(!$pricing)throw new \RuntimeException('Bu domain ürünü için seçilen süreye ait aktif yenileme fiyatı tanımlı değil.');
            $now=gmdate('Y-m-d H:i:s');$total=(string)$pricing['price'];$orderNo='HPR'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));
            $o=$this->db->prepare("INSERT INTO orders(order_number,user_id,status,currency,subtotal,tax_total,total,created_at,updated_at) VALUES(:number,:user,'pending',:currency,:total,0,:total,:now,:now)");
            $o->execute([':number'=>$orderNo,':user'=>$user['id'],':currency'=>$pricing['currency'],':total'=>$total,':now'=>$now]);$orderId=(int)$this->db->lastInsertId();
            $oi=$this->db->prepare('INSERT INTO order_items(order_id,product_id,product_name,billing_cycle,quantity,unit_price,line_total,created_at) VALUES(:order,:product,:name,:cycle,1,:price,:price,:now)');
            $oi->execute([':order'=>$orderId,':product'=>$domain['product_id'],':name'=>$pricing['name'].' · Domain yenileme '.$years.' yıl',':cycle'=>$cycles[$years],':price'=>$total,':now'=>$now]);
            $invoiceNo='HPRF'.gmdate('ymd').strtoupper(bin2hex(random_bytes(4)));$due=gmdate('Y-m-d H:i:s',time()+604800);
            $inv=$this->db->prepare("INSERT INTO invoices(invoice_number,order_id,user_id,status,currency,subtotal,tax_total,total,amount_paid,issued_at,due_at,created_at,updated_at) VALUES(:number,:order,:user,'unpaid',:currency,:total,0,:total,0,:now,:due,:now,:now)");
            $inv->execute([':number'=>$invoiceNo,':order'=>$orderId,':user'=>$user['id'],':currency'=>$pricing['currency'],':total'=>$total,':now'=>$now,':due'=>$due]);$invoiceId=(int)$this->db->lastInsertId();
            $line=$this->db->prepare('INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,tax_rate,tax_amount,line_total,created_at) VALUES(:invoice,:description,1,:price,0,0,:price,:now)');
            $line->execute([':invoice'=>$invoiceId,':description'=>$domain['domain_name'].' · '.$years.' yıllık domain yenileme',':price'=>$total,':now'=>$now]);
            $renew=$this->db->prepare("INSERT INTO domain_renewals(domain_registration_id,user_id,order_id,invoice_id,years,registrar_order_id,expiry_before,status,idempotency_key,available_at,created_at,updated_at) VALUES(:domain,:user,:order,:invoice,:years,:registrar,:expiry,'awaiting_payment',:key,:now,:now,:now)");
            $renew->execute([':domain'=>$id,':user'=>$user['id'],':order'=>$orderId,':invoice'=>$invoiceId,':years'=>$years,':registrar'=>$domain['registrar_order_id'],':expiry'=>$domain['expiry_date'],':key'=>hash('sha256','domain-renewal:'.$id.':'.$orderId),':now'=>$now]);
            $this->db->commit();$this->go('/client/invoices','notice='.rawurlencode('Domain yenileme faturası oluşturuldu. Ödeme doğrulanınca işlem kuyruğa alınacak.'));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/client/domains','error='.rawurlencode($e->getMessage()));}
    }

    public function myDomains():void
    {
        $user=Auth::user();if(!$user){$this->go('/client/login');return;}if(($user['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekli.';return;}
        $q=$this->db->prepare('SELECT d.*,i.invoice_number,i.status AS invoice_status FROM domain_registrations d JOIN invoices i ON i.id=d.invoice_id WHERE d.user_id=:user ORDER BY d.created_at DESC');
        $q->execute([':user'=>$user['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $labels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kayıt kuyruğunda','processing'=>'İşleniyor','customer_created'=>'Registrar müşteri kaydı açıldı','contact_created'=>'Alan adı iletişim kaydı açıldı','pending_remote'=>'Registrar kayıt işlemi sürüyor','active'=>'Aktif','failed'=>'Başarısız','needs_review'=>'Yönetici incelemesi gerekli','cancelled'=>'İptal edildi'];
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Domainlerim · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}main{max-width:1000px;margin:30px auto;padding:20px}.panel{background:#111c2f;border:1px solid #26354c;border-radius:14px;padding:22px;margin-top:20px}.muted,small{color:#9aabc2}.item{padding:16px 0;border-bottom:1px solid #26354c}.item:last-child{border:0}a{color:#9eb7fa}</style></head><body><main><a href="'.$this->url('/client').'">← Müşteri paneli</a><section class="panel"><h1>Domainlerim</h1><p class="muted">Satın aldığınız alan adlarının ödeme ve registrar kayıt durumları.</p>';if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="error">'.$this->e($error).'</div>';
        if(!$rows)echo '<p>Henüz domain siparişiniz bulunmuyor. <a href="'.$this->url('/domains/search').'">Alan adı sorgulayın.</a></p>';
        foreach($rows as $row){$status=$labels[$row['status']]??(string)$row['status'];echo '<article class="item"><h2>'.$this->e((string)$row['domain_name']).'</h2><b>'.$this->e($status).'</b><p class="muted">'.$this->e((string)$row['years']).' yıl · Fatura #'.$this->e((string)$row['invoice_number']).' · Fatura: '.$this->e((string)$row['invoice_status']).'</p>';if(!empty($row['expiry_date']))echo '<p>Registrar bitiş tarihi: <b>'.date('d.m.Y H:i',strtotime((string)$row['expiry_date'])).' UTC</b> · Otomatik yenileme: '.($row['registrar_auto_renew']===null?'Bilinmiyor':((int)$row['registrar_auto_renew']===1?'Açık':'Kapalı')).'</p>';if($row['status']==='active')echo '<form method="post" action="'.$this->url('/client/domains/sync').'" style="margin:10px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="domain_id" value="'.(int)$row['id'].'"><button>Registrar bilgilerini güncelle</button></form>';if($row['status']==='active'&&!empty($row['expiry_date'])){$renewalYears=str_ends_with(strtolower((string)$row['domain_name']),'.ai')?[2]:[1,2,3];$renewalOptions='';foreach($renewalYears as $renewalYear)$renewalOptions.='<option value="'.$renewalYear.'">'.$renewalYear.' yıl</option>';echo '<form method="post" action="'.$this->url('/client/domains/renew').'" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="domain_id" value="'.(int)$row['id'].'"><label>Yenileme süresi <select name="years">'.$renewalOptions.'</select></label><button>Yenileme faturası oluştur</button></form>';}if(!empty($row['action_message']))echo '<p>'.$this->e((string)$row['action_message']).'</p>';if($row['status']==='needs_review'||$row['status']==='failed')echo '<p>İşlem destek ekibinin kontrolüne alındı. Yinelenen kayıt oluşturmamak için otomatik yeniden gönderim yapılmayacak.</p>';
            $renewals=$this->db->prepare('SELECT years,status,renewed_expiry,last_error,invoice_id FROM domain_renewals WHERE domain_registration_id=:id ORDER BY created_at DESC LIMIT 5');$renewals->execute([':id'=>$row['id']]);foreach($renewals->fetchAll(PDO::FETCH_ASSOC) as $renewal){$renewalLabels=['awaiting_payment'=>'Yenileme faturası bekleniyor','queued'=>'Yenileme kuyruğunda','processing'=>'Yenileme gönderiliyor','pending_remote'=>'Registrar sonucu bekleniyor','completed'=>'Yenileme tamamlandı','failed'=>'Yenileme başarısız','needs_review'=>'Yenileme yönetici incelemesinde'];echo '<p>Yenileme · '.$this->e((string)$renewal['years']).' yıl: <b>'.$this->e($renewalLabels[$renewal['status']]??$renewal['status']).'</b>'.(!empty($renewal['renewed_expiry'])?' · Yeni bitiş: '.$this->e((string)$renewal['renewed_expiry']):'').' · <a href="'.$this->url('/client/invoices').'">Faturalar</a>'.(!empty($renewal['last_error'])?' · '.$this->e((string)$renewal['last_error']):'').'</p>';}$nsStatus=(string)($row['nameserver_status']??'');$nsLabels=['queued'=>'Değişiklik kuyruğa alındı','processing'=>'Nameserver güncelleniyor','completed'=>'Nameserver güncellendi','failed'=>'Nameserver işlemi başarısız','needs_review'=>'Nameserver işlemi yönetici incelemesinde'];
            if($nsStatus!=='')echo '<p>Nameserver durumu: '.$this->e($nsLabels[$nsStatus]??$nsStatus).(!empty($row['nameserver_error'])?' · '.$this->e((string)$row['nameserver_error']):'').'</p>';
            if($row['status']==='active'&&!in_array($nsStatus,['queued','processing','needs_review'],true)){echo '<form method="post" action="'.$this->url('/client/domains/nameservers').'" style="margin-top:14px"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="domain_id" value="'.(int)$row['id'].'"><label>Nameserver adresleri (her satıra bir tane)<textarea name="nameservers" rows="3" placeholder="ns1.example.com&#10;ns2.example.com" required></textarea></label><button>Nameserver değişikliğini sıraya al</button></form>';}
            echo '</article>';}
        echo '</section></main></body></html>';
    }

    public function adminOrders():void
    {
        if(!$this->admin())return;
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        $search=trim((string)($_GET['q']??''));$status=(string)($_GET['status']??'');$expiry=(string)($_GET['expiry']??'');$sort=(string)($_GET['sort']??'newest');
        $allowedStatuses=['awaiting_payment','queued','processing','customer_created','contact_created','pending_remote','active','failed','needs_review','cancelled'];
        if(!in_array($status,$allowedStatuses,true))$status='';
        if(!in_array($expiry,['expired','7','30','90','no_date'],true))$expiry='';
        if(!in_array($sort,['newest','expiry_asc','expiry_desc'],true))$sort='newest';
        $page=max(1,(int)($_GET['page']??1));$perPage=25;$where=[];$params=[];
        if($search!==''){$where[]='(d.domain_name LIKE :q_domain OR u.name LIKE :q_name OR u.email LIKE :q_email)';$params[':q_domain']='%'.$search.'%';$params[':q_name']='%'.$search.'%';$params[':q_email']='%'.$search.'%';}
        if($status!==''){$where[]='d.status=:status';$params[':status']=$status;}
        if($expiry==='expired')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date<CURDATE()";
        elseif($expiry==='7')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY)";
        elseif($expiry==='30')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)";
        elseif($expiry==='90')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 90 DAY)";
        elseif($expiry==='no_date')$where[]='d.expiry_date IS NULL';
        $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
        $summary=$this->db->query("SELECT COUNT(*) AS active_total,SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date<CURDATE() THEN 1 ELSE 0 END) AS expired,SUM(CASE WHEN expiry_date>=CURDATE() AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_7,SUM(CASE WHEN expiry_date>DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS due_30,SUM(CASE WHEN expiry_date>DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND expiry_date<=DATE_ADD(CURDATE(),INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS due_90,SUM(CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END) AS no_date FROM domain_registrations WHERE status='active'")->fetch(PDO::FETCH_ASSOC)?:[];
        $count=$this->db->prepare('SELECT COUNT(*) FROM domain_registrations d JOIN users u ON u.id=d.user_id'.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;
        $order=$sort==='expiry_asc'?'d.expiry_date IS NULL ASC,d.expiry_date ASC':($sort==='expiry_desc'?'d.expiry_date IS NULL ASC,d.expiry_date DESC':"CASE WHEN d.status IN ('needs_review','failed') THEN 0 ELSE 1 END,d.created_at DESC");
        $stmt=$this->db->prepare('SELECT d.*,u.name,u.email,i.invoice_number,i.status AS invoice_status FROM domain_registrations d JOIN users u ON u.id=d.user_id JOIN invoices i ON i.id=d.invoice_id'.$whereSql.' ORDER BY '.$order.' LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value)$stmt->bindValue($key,$value);$stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);$stmt->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $labels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kuyrukta','processing'=>'İşleniyor','customer_created'=>'Müşteri kaydı','contact_created'=>'İletişim kaydı','pending_remote'=>'Registrar bekleniyor','active'=>'Aktif','failed'=>'Başarısız','needs_review'=>'İnceleme gerekli','cancelled'=>'İptal'];
        $this->page('Domain siparişleri');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin/domains/registrar').'">Registrar ayarları</a><a class="nav-item active" href="'.$this->url('/admin/domains/orders').'">Domain siparişleri</a><a class="nav-item" href="'.$this->url('/admin/domains/renewals').'">Domain yenilemeleri</a></aside><main class="main"><header class="topbar"><div><small>DOMAIN / SİPARİŞLER</small><h1>Domain kayıt kuyruğu</h1></div><a href="'.$this->url('/admin/domains/registrar').'">Registrar ayarları</a></header><article class="panel"><p>Belirsiz registrar yanıtları otomatik tekrar gönderilmez; manuel doğrulama gerektirir. Aşağıdaki uzlaştırma işlemleri yalnızca registrar panelinde kontrol yaptıktan sonra kullanılmalıdır.</p>';if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:18px 0">';$cards=[['Aktif domain',(int)($summary['active_total']??0),''],['Süresi dolmuş',(int)($summary['expired']??0),'expired'],['7 gün içinde',(int)($summary['due_7']??0),'7'],['8–30 gün',(int)($summary['due_30']??0),'30'],['31–90 gün',(int)($summary['due_90']??0),'90'],['Bitiş tarihi yok',(int)($summary['no_date']??0),'no_date']];foreach($cards as [$caption,$number,$filter]){$href=$this->url('/admin/domains/orders').($filter!==''?'?'.http_build_query(['expiry'=>$filter]):'');echo '<a href="'.$this->e($href).'" style="display:block;padding:14px;background:#0b1425;border:1px solid #26354c;border-radius:10px;text-decoration:none"><small>'.$this->e($caption).'</small><div style="font-size:25px;font-weight:800;margin-top:5px">'.$number.'</div></a>';}echo '</div>';
        echo '<form method="get" action="'.$this->url('/admin/domains/orders').'" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:18px 0"><label>Domain / müşteri / e-posta<input name="q" value="'.$this->e($search).'" placeholder="ornek.com"></label><label>Durum<select name="status"><option value="">Tüm durumlar</option>';foreach($labels as $key=>$label)echo '<option value="'.$this->e($key).'"'.($status===$key?' selected':'').'>'.$this->e($label).'</option>';echo '</select></label><label>Bitiş tarihi<select name="expiry"><option value="">Tüm tarihler</option><option value="expired"'.($expiry==='expired'?' selected':'').'>Süresi dolmuş</option><option value="7"'.($expiry==='7'?' selected':'').'>7 gün içinde</option><option value="30"'.($expiry==='30'?' selected':'').'>30 gün içinde</option><option value="90"'.($expiry==='90'?' selected':'').'>90 gün içinde</option><option value="no_date"'.($expiry==='no_date'?' selected':'').'>Tarih bilinmiyor</option></select></label><label>Sıralama<select name="sort"><option value="newest"'.($sort==='newest'?' selected':'').'>En yeni kayıt</option><option value="expiry_asc"'.($sort==='expiry_asc'?' selected':'').'>Bitiş tarihi yakın önce</option><option value="expiry_desc"'.($sort==='expiry_desc'?' selected':'').'>Bitiş tarihi uzak önce</option></select></label><button type="submit">Filtrele</button><a href="'.$this->url('/admin/domains/orders').'">Temizle</a></form><p><a class="button" href="'.$this->e($this->url('/admin/domains/export').($search!==''||$status!==''||$expiry!==''||$sort!=='newest'?'?'.http_build_query(array_filter(['q'=>$search,'status'=>$status,'expiry'=>$expiry,'sort'=>$sort],static fn($v)=>$v!=='')):'' )).'">Filtrelenmiş CSV raporunu indir ↓</a></p><p>Toplam '.$total.' domain · Sayfa '.$page.' / '.$pages.'</p>';
        if(!$rows)echo '<p>Henüz domain siparişi yok.</p>';
        foreach($rows as $row){
            echo '<div style="padding:14px 0;border-bottom:1px solid #26354c"><b>'.$this->e((string)$row['domain_name']).'</b> · '.$this->e($labels[$row['status']]??(string)$row['status']).'<p>'.$this->e((string)$row['name']).' · '.$this->e((string)$row['email']).' · #'.$this->e((string)$row['invoice_number']).' · '.$this->e((string)$row['invoice_status']).'</p><small>Registrar order: '.$this->e((string)($row['registrar_order_id']??'—')).' · Action: '.$this->e((string)($row['action_status']??'—')).'</small>'.(!empty($row['last_error'])?'<p class="alert">'.$this->e((string)$row['last_error']).'</p>':'').'</div>';
            if($row['status']==='active'&&!empty($row['registrar_order_id'])){
                $attempt=$row['registrar_sync_attempted_at']??null;$success=$row['registrar_synced_at']??null;$syncError=$row['registrar_sync_error']??null;
                $next='İlk senkronizasyon bekleniyor';if($attempt){$interval=$syncError?3600:86400;$nextAt=strtotime((string)$attempt)+$interval;$next=gmdate('Y-m-d H:i:s',$nextAt).' UTC';if($nextAt<=time())$next.=' · zamanı geldi';}
                echo '<section style="margin:12px 0;padding:14px;background:#0b1425;border:1px solid #26354c;border-radius:10px"><b>Registrar bitiş tarihi senkronizasyonu</b><p>Son başarılı eşitleme: '.$this->e((string)($success?:'Henüz yok')).'<br>Son deneme: '.$this->e((string)($attempt?:'Henüz denenmedi')).'<br>Sonraki otomatik deneme: '.$this->e($next).'<br>Registrar otomatik yenileme: '.$this->e(isset($row['registrar_auto_renew'])&&$row['registrar_auto_renew']!==null?((int)$row['registrar_auto_renew']===1?'Açık':'Kapalı'):'Bilinmiyor').'</p>';
                if($syncError)echo '<p class="alert">Son senkronizasyon hatası: '.$this->e((string)$syncError).'</p>';
                $nsBusy=in_array($row['nameserver_status']??'', ['queued','processing'],true);
                if(empty($row['locked_at'])&&!$nsBusy)echo '<form method="post" action="'.$this->url('/admin/domains/expiry-sync/queue').'" onsubmit="return confirm(\'Bu domain günlük senkronizasyon kuyruğuna alınsın mı?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><button>Senkronizasyonu kuyruğa al</button></form>';
                else echo '<small>Domain başka bir işlem tarafından kilitli; manuel kuyruğa alma şu an kapalı.</small>';
                echo '</section>';
            }
            if($row['status']==='active'&&in_array($row['nameserver_status']??'', ['needs_review','failed'],true)){echo '<p>Nameserver işlemi: '.$this->e((string)$row['nameserver_status']).(!empty($row['nameserver_error'])?' · '.$this->e((string)$row['nameserver_error']):'').'</p><form method="post" action="'.$this->url('/admin/domains/nameservers/reconcile').'" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><button name="decision" value="remote_applied">Uzakta uygulandı — eşitle</button><button name="decision" value="remote_not_applied">Uygulanmadı — tekrar sıraya al</button><button name="decision" value="mark_failed">İşlemi kapat</button></form>';}
            if(in_array($row['status'],['needs_review','failed'],true)){echo '<form method="post" action="'.$this->url('/admin/domains/reconcile').'" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0 25px"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><label>Doğrulanmış customerId<input name="customer_id" inputmode="numeric"></label><label>Doğrulanmış contactId<input name="contact_id" inputmode="numeric"></label><label>Doğrulanmış registrar orderId<input name="registrar_order_id" inputmode="numeric"></label><label>Başarısızlık açıklaması<input name="reason" maxlength="1000"></label><button name="decision" value="resume_customer">Müşteri kaydı var → devam et</button><button name="decision" value="resume_contact">İletişim kaydı var → devam et</button><button name="decision" value="resume_action">Domain kayıt emri var → durumunu sorgula</button><button name="decision" value="mark_active" onclick="return confirm(\'Registrar panelinde domainin aktif olduğunu doğruladınız mı?\')">Aktif olarak işaretle</button><button name="decision" value="mark_failed">Başarısız olarak kapat</button></form>';}
        }
        if($pages>1){$base=['q'=>$search,'status'=>$status,'expiry'=>$expiry,'sort'=>$sort];echo '<nav style="display:flex;gap:8px;flex-wrap:wrap;margin:20px 0">';for($n=max(1,$page-3);$n<=min($pages,$page+3);$n++){$link=$this->url('/admin/domains/orders').'?'.http_build_query($base+['page'=>$n]);echo '<a href="'.$this->e($link).'"'.($n===$page?' aria-current="page"':'').'>'.$n.'</a>';}echo '</nav>';}
        echo '</article></main></div>';$this->end();
    }

    public function exportOrdersCsv():void
    {
        if(!$this->admin())return;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo 'Method not allowed';return;}
        $search=trim((string)($_GET['q']??''));$status=(string)($_GET['status']??'');$expiry=(string)($_GET['expiry']??'');$sort=(string)($_GET['sort']??'newest');
        $allowedStatuses=['awaiting_payment','queued','processing','customer_created','contact_created','pending_remote','active','failed','needs_review','cancelled'];
        if(!in_array($status,$allowedStatuses,true))$status='';
        if(!in_array($expiry,['expired','7','30','90','no_date'],true))$expiry='';
        if(!in_array($sort,['newest','expiry_asc','expiry_desc'],true))$sort='newest';
        $where=[];$params=[];
        if($search!==''){$where[]='(d.domain_name LIKE :q_domain OR u.name LIKE :q_name OR u.email LIKE :q_email)';$params[':q_domain']='%'.$search.'%';$params[':q_name']='%'.$search.'%';$params[':q_email']='%'.$search.'%';}
        if($status!==''){$where[]='d.status=:status';$params[':status']=$status;}
        if($expiry==='expired')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date<CURDATE()";
        elseif($expiry==='7')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY)";
        elseif($expiry==='30')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)";
        elseif($expiry==='90')$where[]="d.expiry_date IS NOT NULL AND d.expiry_date>=CURDATE() AND d.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 90 DAY)";
        elseif($expiry==='no_date')$where[]='d.expiry_date IS NULL';
        $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
        $order=$sort==='expiry_asc'?'d.expiry_date IS NULL ASC,d.expiry_date ASC':($sort==='expiry_desc'?'d.expiry_date IS NULL ASC,d.expiry_date DESC':"CASE WHEN d.status IN ('needs_review','failed') THEN 0 ELSE 1 END,d.created_at DESC");
        $stmt=$this->db->prepare('SELECT d.domain_name,d.status,d.expiry_date,d.registrar_auto_renew,d.registrar_synced_at,d.registrar_sync_error,u.name AS customer_name,u.email,i.invoice_number,i.status AS invoice_status FROM domain_registrations d JOIN users u ON u.id=d.user_id JOIN invoices i ON i.id=d.invoice_id'.$whereSql.' ORDER BY '.$order);
        $stmt->execute($params);
        $filename='hostpilot-domain-raporu-'.gmdate('Y-m-d').'.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','wb');
        if($out===false){http_response_code(500);return;}
        fwrite($out,"\xEF\xBB\xBF");
        $safe=static function($value):string{$value=(string)($value??'');$probe=ltrim($value," \t\n\r\0\x0B\xC2\xA0\xEF\xBB\xBF");return $probe!==''&&str_contains('=+-@',$probe[0])?"'".$value:$value;};
        fputcsv($out,['Domain','Müşteri','E-posta','Kayıt durumu','Fatura no','Fatura durumu','Bitiş tarihi','Otomatik yenileme','Son registrar eşitlemesi','Son eşitleme hatası']);
        $statusLabels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kuyrukta','processing'=>'İşleniyor','customer_created'=>'Müşteri kaydı','contact_created'=>'İletişim kaydı','pending_remote'=>'Registrar bekleniyor','active'=>'Aktif','failed'=>'Başarısız','needs_review'=>'İnceleme gerekli','cancelled'=>'İptal'];
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
            $auto=$row['registrar_auto_renew']===null?'Bilinmiyor':((int)$row['registrar_auto_renew']===1?'Açık':'Kapalı');
            fputcsv($out,[
                $safe($row['domain_name']),$safe($row['customer_name']),$safe($row['email']),
                $safe($statusLabels[$row['status']]??$row['status']),$safe($row['invoice_number']),
                $safe($row['invoice_status']),$safe($row['expiry_date']),$safe($auto),
                $safe($row['registrar_synced_at']),$safe($row['registrar_sync_error'])
            ]);
        }
        fclose($out);exit;
    }

    public function queueExpirySync():void
    {
        if(!$this->admin())return;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['id']??0);
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare('SELECT id,status,registrar_order_id,locked_at,nameserver_status FROM domain_registrations WHERE id=:id FOR UPDATE');
            $q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='active'||empty($row['registrar_order_id']))throw new \RuntimeException('Yalnızca aktif ve registrar orderId bilgisi bulunan domainler kuyruğa alınabilir.');
            if(!empty($row['locked_at'])||in_array($row['nameserver_status']??'', ['queued','processing'],true))throw new \RuntimeException('Domain başka bir işlem tarafından kullanılıyor; senkronizasyon kuyruğa alınmadı.');
            $this->db->prepare('UPDATE domain_registrations SET registrar_sync_attempted_at=NULL,registrar_sync_error=NULL,updated_at=:now WHERE id=:id')->execute([':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->db->commit();
            $this->go('/admin/domains/orders','notice='.rawurlencode('Domain bir sonraki cron çalışmasında registrar senkronizasyon kuyruğundan işlenecek.'));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/admin/domains/orders','error='.rawurlencode($e->getMessage()));}
    }

    public function adminRenewals():void
    {
        if(!$this->admin())return;
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        $rows=$this->db->query("SELECT r.*,d.domain_name,d.expiry_date AS current_expiry,u.name,u.email,i.invoice_number,i.status AS invoice_status FROM domain_renewals r JOIN domain_registrations d ON d.id=r.domain_registration_id JOIN users u ON u.id=r.user_id JOIN invoices i ON i.id=r.invoice_id ORDER BY CASE WHEN r.status='needs_review' THEN 0 WHEN r.status='pending_remote' THEN 1 WHEN r.status='failed' THEN 2 ELSE 3 END,r.created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $labels=['awaiting_payment'=>'Ödeme bekleniyor','queued'=>'Kuyrukta','processing'=>'İşleniyor','pending_remote'=>'Registrar sonucu bekleniyor','completed'=>'Tamamlandı','failed'=>'Başarısız','needs_review'=>'Yönetici incelemesi gerekli'];
        $this->page('Domain yenilemeleri');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin/domains/registrar').'">Registrar ayarları</a><a class="nav-item" href="'.$this->url('/admin/domains/orders').'">Domain siparişleri</a><a class="nav-item active" href="'.$this->url('/admin/domains/renewals').'">Domain yenilemeleri</a></aside><main class="main"><header class="topbar"><div><small>DOMAIN / YENİLEME</small><h1>Domain yenileme işlemleri</h1></div><a href="'.$this->url('/admin/domains/orders').'">Domain siparişleri</a></header><article class="panel"><p>Belirsiz registrar işlemlerinde otomatik tekrar yapılmaz. Registrar panelinde yenileme emrinin sonucunu ve yeni bitiş tarihini doğruladıktan sonra aşağıdaki uzlaştırma işlemini kullanın.</p>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        if(!$rows)echo '<p>Henüz domain yenileme talebi yok.</p>';
        foreach($rows as $row){
            echo '<section style="padding:16px 0;border-bottom:1px solid #26354c"><b>'.$this->e((string)$row['domain_name']).'</b> · '.$this->e($labels[$row['status']]??(string)$row['status']).'<p>'.$this->e((string)$row['name']).' · '.$this->e((string)$row['email']).' · Fatura: '.$this->e((string)$row['invoice_number']).' ('.$this->e((string)$row['invoice_status']).')</p><small>'.$this->e((string)$row['years']).' yıl · Önceki bitiş: '.$this->e((string)$row['expiry_before']).' · Güncel yerel bitiş: '.$this->e((string)($row['current_expiry']??'—')).' · Registrar order: '.$this->e((string)$row['registrar_order_id']).' · EAQ: '.$this->e((string)($row['action_eaq_id']??'—')).' · Action: '.$this->e((string)($row['action_status']??'—')).'</small>';
            if(!empty($row['last_error']))echo '<p class="alert">'.$this->e((string)$row['last_error']).'</p>';
            if($row['status']==='needs_review'){
                echo '<p>Registrar panelinde doğruladığınız sonuca göre işlem yapın. “Uygulandı” seçeneğinde registrar panelinde görünen yeni bitiş tarihini girmeniz gerekir.</p><form method="post" action="'.$this->url('/admin/domains/renewals/reconcile').'" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><label>Registrar üzerinde görünen yeni bitiş tarihi<input type="date" name="renewed_expiry"></label><label>Yönetici notu<input name="reason" maxlength="1000" placeholder="Doğrulama notu"></label><button name="decision" value="remote_applied">Yenileme uygulandı — eşitle</button><button name="decision" value="remote_not_applied">Uygulanmadı — tekrar kuyruğa al</button><button name="decision" value="mark_failed">Yenilemeyi başarısız kapat</button></form>';
            }
            echo '</section>';
        }
        echo '</article></main></div>';$this->end();
    }

    public function reconcileRenewal():void
    {
        if(!$this->admin())return;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');$reason=trim((string)($_POST['reason']??''));$now=gmdate('Y-m-d H:i:s');
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT r.*,d.expiry_date AS current_expiry,i.status AS invoice_status FROM domain_renewals r JOIN domain_registrations d ON d.id=r.domain_registration_id JOIN invoices i ON i.id=r.invoice_id WHERE r.id=:id FOR UPDATE");
            $q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='needs_review')throw new \RuntimeException('Yalnızca yönetici incelemesindeki yenilemeler uzlaştırılabilir.');
            if($row['invoice_status']!=='paid')throw new \RuntimeException('Yenileme faturası tamamen ödenmiş değil.');
            if($row['current_expiry']===null||strtotime((string)$row['current_expiry'])!==strtotime((string)$row['expiry_before']))throw new \RuntimeException('Domain bitiş tarihi değişmiş; bu yenileme güvenli biçimde uzlaştırılamaz.');
            if($decision==='remote_applied'){
                $date=trim((string)($_POST['renewed_expiry']??''));$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
                $dateErrors=\DateTimeImmutable::getLastErrors();
                if(!$parsed||($dateErrors!==false&&($dateErrors['warning_count']>0||$dateErrors['error_count']>0))||$parsed->format('Y-m-d')!==$date)throw new \RuntimeException('Registrar panelinde doğruladığınız yeni bitiş tarihini YYYY-AA-GG biçiminde girin.');
                $expiry=$parsed->format('Y-m-d').' 23:59:59';
                if(strtotime($expiry)<=strtotime((string)$row['expiry_before']))throw new \RuntimeException('Yeni bitiş tarihi önceki bitiş tarihinden ileri olmalı.');
                $this->db->prepare("UPDATE domain_renewals SET status='completed',action_status='Admin verified completed',renewed_expiry=:expiry,last_error=NULL,locked_at=NULL,updated_at=:now WHERE id=:id")->execute([':expiry'=>$expiry,':now'=>$now,':id'=>$id]);
                $this->db->prepare("UPDATE domain_registrations SET expiry_date=:expiry,registrar_synced_at=:now,updated_at=:now WHERE id=:domain")->execute([':expiry'=>$expiry,':now'=>$now,':domain'=>$row['domain_registration_id']]);
                $message='Registrar panelinde tamamlandığı doğrulanan yenileme HostPilot ile eşitlendi.';
            }elseif($decision==='remote_not_applied'){
                $this->db->prepare("UPDATE domain_renewals SET status='queued',action_eaq_id=NULL,action_type=NULL,action_status='Admin verified not applied',last_error=NULL,locked_at=NULL,available_at=:now,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$id]);
                $message='Registrar panelinde uygulanmadığı doğrulanan yenileme yeniden kuyruğa alındı.';
            }elseif($decision==='mark_failed'){
                if($reason==='')$reason='Yönetici registrar panelinde yenilemenin başarısız olduğunu doğruladı.';
                $this->db->prepare("UPDATE domain_renewals SET status='failed',action_status='Admin verified failed',last_error=:reason,locked_at=NULL,updated_at=:now WHERE id=:id")->execute([':reason'=>substr($reason,0,1000),':now'=>$now,':id'=>$id]);
                $message='Domain yenilemesi yönetici tarafından başarısız olarak kapatıldı.';
            }else throw new \RuntimeException('Geçersiz yenileme uzlaştırma kararı.');
            $this->db->commit();$this->go('/admin/domains/renewals','notice='.rawurlencode($message));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/admin/domains/renewals','error='.rawurlencode($e->getMessage()));}
    }

    public function reconcileNameservers():void
    {
        if(!$this->admin())return;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');$now=gmdate('Y-m-d H:i:s');
        try{
            $this->db->beginTransaction();$q=$this->db->prepare("SELECT * FROM domain_registrations WHERE id=:id FOR UPDATE");$q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='active'||!in_array($row['nameserver_status']??'', ['needs_review','failed'],true))throw new \RuntimeException('Yalnızca incelemedeki aktif domain nameserver işlemleri uzlaştırılabilir.');
            if($decision==='remote_applied'){
                if(empty($row['pending_nameservers_enc']))throw new \RuntimeException('Bekleyen nameserver verisi bulunamadı.');
                $this->db->prepare("UPDATE domain_registrations SET nameservers_enc=pending_nameservers_enc,pending_nameservers_enc=NULL,nameserver_status='completed',nameserver_error=NULL,nameserver_updated_at=:now,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$id]);
            }elseif($decision==='remote_not_applied'){
                if(empty($row['pending_nameservers_enc']))throw new \RuntimeException('Bekleyen nameserver verisi bulunamadı.');
                $this->db->prepare("UPDATE domain_registrations SET nameserver_status='queued',nameserver_error=NULL,nameserver_updated_at=:now,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$id]);
            }elseif($decision==='mark_failed'){
                $this->db->prepare("UPDATE domain_registrations SET pending_nameservers_enc=NULL,nameserver_status='failed',nameserver_error='Yönetici nameserver işleminin uygulanmadığını ve kapatıldığını doğruladı.',nameserver_updated_at=:now,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$id]);
            }else throw new \RuntimeException('Geçersiz nameserver uzlaştırma kararı.');
            $this->db->commit();$this->go('/admin/domains/orders','notice='.rawurlencode('Nameserver işlemi uzlaştırıldı.'));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/admin/domains/orders','error='.rawurlencode($e->getMessage()));}
    }

    public function reconcileOrder():void
    {
        if(!$this->admin())return;
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo 'Method not allowed';return;}
        if(!Auth::verifyCsrf($_POST['_token']??null)){http_response_code(419);echo 'Güvenlik doğrulaması başarısız.';return;}
        $id=(int)($_POST['id']??0);$decision=(string)($_POST['decision']??'');$customerId=trim((string)($_POST['customer_id']??''));$contactId=trim((string)($_POST['contact_id']??''));$orderId=trim((string)($_POST['registrar_order_id']??''));$now=gmdate('Y-m-d H:i:s');
        if($id<1){http_response_code(422);echo 'Geçersiz domain siparişi.';return;}
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT * FROM domain_registrations WHERE id=:id FOR UPDATE");$q->execute([':id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||!in_array($row['status'],['needs_review','failed'],true))throw new \RuntimeException('Yalnızca inceleme veya başarısız durumundaki kayıtlar uzlaştırılabilir.');
            if($decision==='resume_customer'){
                if(!ctype_digit($customerId))throw new \RuntimeException('Registrar panelinden doğruladığınız customerId sayısal olmalı.');
                $this->db->prepare("UPDATE domain_registrations SET registrar_customer_id=:customer,status='customer_created',attempts=0,locked_at=NULL,available_at=:now,last_error=NULL,action_message='Yönetici registrar müşteri kaydını doğruladı.',updated_at=:now WHERE id=:id")->execute([':customer'=>$customerId,':now'=>$now,':id'=>$id]);
            }elseif($decision==='resume_contact'){
                if(!ctype_digit($customerId)||!ctype_digit($contactId))throw new \RuntimeException('Doğrulanmış customerId ve contactId gerekli.');
                $this->db->prepare("UPDATE domain_registrations SET registrar_customer_id=:customer,registrar_contact_id=:contact,status='contact_created',attempts=0,locked_at=NULL,available_at=:now,last_error=NULL,action_message='Yönetici registrar iletişim kaydını doğruladı.',updated_at=:now WHERE id=:id")->execute([':customer'=>$customerId,':contact'=>$contactId,':now'=>$now,':id'=>$id]);
            }elseif($decision==='resume_action'){
                if(!ctype_digit($orderId))throw new \RuntimeException('Registrar panelinden doğruladığınız orderId sayısal olmalı.');
                $this->db->prepare("UPDATE domain_registrations SET registrar_order_id=:order_id,status='pending_remote',attempts=0,locked_at=NULL,available_at=:now,last_error=NULL,action_message='Yönetici registrar kayıt emrini doğruladı.',updated_at=:now WHERE id=:id")->execute([':order_id'=>$orderId,':now'=>$now,':id'=>$id]);
            }elseif($decision==='mark_active'){
                if($orderId!==''&&!ctype_digit($orderId))throw new \RuntimeException('Registrar orderId sayısal olmalı.');
                $this->db->prepare("UPDATE domain_registrations SET registrar_order_id=COALESCE(NULLIF(:order_id,''),registrar_order_id),status='active',registered_at=COALESCE(registered_at,:now),attempts=0,locked_at=NULL,last_error=NULL,action_status='Completed',action_message='Yönetici registrar panelinden tamamlandığını doğruladı.',updated_at=:now WHERE id=:id")->execute([':order_id'=>$orderId,':now'=>$now,':id'=>$id]);
            }elseif($decision==='mark_failed'){
                $reason=trim((string)($_POST['reason']??'Registrar panelinde başarısız olduğu yönetici tarafından doğrulandı.'));
                $this->db->prepare("UPDATE domain_registrations SET status='failed',locked_at=NULL,last_error=:reason,action_status='Failed',action_message=:reason,updated_at=:now WHERE id=:id")->execute([':reason'=>substr($reason,0,1000),':now'=>$now,':id'=>$id]);
            }else throw new \RuntimeException('Geçersiz uzlaştırma kararı.');
            $this->db->commit();
            $this->go('/admin/domains/orders','notice='.rawurlencode('Domain siparişi uzlaştırıldı. Verdiğiniz karar kaydedildi.'));
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->go('/admin/domains/orders','error='.rawurlencode($e->getMessage()));}
    }

    public function runRegistrationQueue():void
    {
        $provided='';
        if(preg_match('/Bearer\\s+(.+)/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$m))$provided=trim($m[1]);
        if($provided===''&&isset($_GET['token']))$provided=(string)$_GET['token'];
        $expected=hash_hmac('sha256','HostPilot domain cron v1',$this->applicationKey);
        if($this->applicationKey===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo 'Forbidden';return;}
        header('Content-Type: application/json; charset=utf-8');
        $row=null;$stage='';$remoteCallStarted=false;$nameserverOperation=false;
        try{
            $now=gmdate('Y-m-d H:i:s');
            $this->db->beginTransaction();
            $cutoff=gmdate('Y-m-d H:i:s',time()-900);
            $this->db->prepare("UPDATE domain_registrations SET status='needs_review',locked_at=NULL,last_error='Worker yanıtı zaman aşımına uğradı; registrar tarafındaki son durum doğrulanmalı.',updated_at=:now WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff")
                ->execute([':now'=>$now,':cutoff'=>$cutoff]);
            $this->db->prepare("UPDATE domain_registrations SET nameserver_status='needs_review',locked_at=NULL,nameserver_error='Nameserver worker zaman aşımına uğradı; registrar tarafı kontrol edilmeli.',updated_at=:now WHERE nameserver_status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff")
                ->execute([':now'=>$now,':cutoff'=>$cutoff]);
            $pick=$this->db->prepare("SELECT * FROM domain_registrations WHERE ((status IN ('queued','customer_created','contact_created','pending_remote') AND available_at<=:now) OR (nameserver_status='queued' AND status='active')) AND (locked_at IS NULL OR locked_at<:stale) ORDER BY CASE WHEN nameserver_status='queued' THEN 0 ELSE 1 END,available_at,id LIMIT 1 FOR UPDATE");
            $pick->execute([':now'=>$now,':stale'=>gmdate('Y-m-d H:i:s',time()-900)]);
            $row=$pick->fetch(PDO::FETCH_ASSOC);
            if(!$row){$this->db->commit();echo json_encode(['ok'=>true,'processed'=>0,'message'=>'Bekleyen domain kaydı yok.']);return;}
            $stage=(string)$row['status'];$nameserverOperation=($row['nameserver_status']??'')==='queued';
            if($nameserverOperation)$this->db->prepare("UPDATE domain_registrations SET nameserver_status='processing',locked_at=:locked,updated_at=:updated WHERE id=:id")->execute([':locked'=>$now,':updated'=>$now,':id'=>$row['id']]);
            else $this->db->prepare("UPDATE domain_registrations SET status='processing',locked_at=:locked,attempts=attempts+1,updated_at=:updated WHERE id=:id")->execute([':locked'=>$now,':updated'=>$now,':id'=>$row['id']]);
            $this->db->commit();

            $cfg=$this->load();
            if(($cfg['enabled']??'0')!=='1'||empty($cfg['api_key_enc'])||empty($cfg['user_id']))throw new \RuntimeException('Domain registrar bağlantısı etkin değil veya eksik.');
            $api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)($cfg['environment']??'sandbox'));
            if($nameserverOperation){
                if($stage!=='active'||empty($row['registrar_order_id'])||empty($row['pending_nameservers_enc']))throw new \RuntimeException('Nameserver kuyruğu için aktif domain veya şifreli değişiklik bilgisi eksik.');
                $servers=json_decode($this->vault->decrypt((string)$row['pending_nameservers_enc']),true,16,JSON_THROW_ON_ERROR);
                if(!is_array($servers))throw new \RuntimeException('Nameserver değişiklik bilgileri okunamadı.');
                $remoteCallStarted=true;$result=$api->updateNameservers((string)$row['registrar_order_id'],$servers);
                $saved=$this->vault->encrypt(json_encode($servers,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                $this->db->prepare("UPDATE domain_registrations SET nameservers_enc=:saved,pending_nameservers_enc=NULL,nameserver_status='completed',nameserver_error=NULL,nameserver_updated_at=:now,locked_at=NULL,updated_at=:now WHERE id=:id")
                    ->execute([':saved'=>$saved,':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'nameservers'=>'completed','registrar_message'=>(string)($result['message']??'Nameserver değişikliği kabul edildi.')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
            }
            if(in_array($stage,['queued','customer_created','contact_created'],true)){
                $payload=json_decode($this->vault->decrypt((string)$row['registrar_payload_enc']),true,32,JSON_THROW_ON_ERROR);
                if(!is_array($payload)||($stage!=='contact_created'&&(empty($payload['customer'])||empty($payload['contact']))))throw new \RuntimeException('Şifreli domain kayıt bilgileri okunamadı.');
                $customerId=(string)($row['registrar_customer_id']??'');
                $contactId=(string)($row['registrar_contact_id']??'');
                if($stage==='queued'){
                    $remoteCallStarted=true;
                    $availability=$api->checkAvailability((string)$row['domain_name']);
                    if(empty($availability['available']))throw new \RuntimeException('Ödeme tamamlandı ancak alan adı son kontrol sırasında artık uygun değil. Yönetici incelemesi gerekli.');
                    $remoteCallStarted=true;
                    $customerId=$api->createCustomer((array)$payload['customer']);
                    unset($payload['customer']['password'],$payload['customer']['username']);
                    $payloadEnc=$this->vault->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
                    $this->db->prepare("UPDATE domain_registrations SET registrar_customer_id=:customer,registrar_payload_enc=:payload,status='customer_created',locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':customer'=>$customerId,':payload'=>$payloadEnc,':available'=>gmdate('Y-m-d H:i:s'),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    $stage='customer_created';
                }
                if($stage==='customer_created'){
                    if($customerId===''||!ctype_digit($customerId))throw new \RuntimeException('Registrar customerId kaydı eksik; manuel kontrol gerekli.');
                    $payload['contact']['customerId']=$customerId;
                    $remoteCallStarted=true;
                    $contactId=$api->createContact((array)$payload['contact']);
                    $emptyPayload=$this->vault->encrypt('{}');
                    $this->db->prepare("UPDATE domain_registrations SET registrar_contact_id=:contact,registrar_payload_enc=:payload,status='contact_created',locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':contact'=>$contactId,':payload'=>$emptyPayload,':available'=>gmdate('Y-m-d H:i:s'),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    $stage='contact_created';
                }
                if($stage==='contact_created'){
                    if($customerId===''||$contactId===''||!ctype_digit($customerId)||!ctype_digit($contactId))throw new \RuntimeException('Registrar müşteri/iletişim kimliği eksik; manuel kontrol gerekli.');
                    $remoteCallStarted=true;
                    $result=$api->registerDomain((string)$row['domain_name'],(int)$row['years'],$customerId,$contactId,(array)($payload['nameservers']??[]),(string)($payload['invoiceOption']??'OnlyAdd'),(bool)($payload['autoRenew']??false));
                    $this->db->prepare("UPDATE domain_registrations SET registrar_order_id=:order_id,status='pending_remote',action_status=:action,action_message=:message,locked_at=NULL,available_at=:available,updated_at=:updated WHERE id=:id")
                        ->execute([':order_id'=>(string)$result['orderId'],':action'=>(string)($result['actionStatus']??'Added'),':message'=>substr((string)($result['message']??'Kayıt talebi registrar tarafından alındı.'),0,500),':available'=>gmdate('Y-m-d H:i:s',time()+45),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    echo json_encode(['ok'=>true,'processed'=>1,'domain'=>$row['domain_name'],'status'=>'pending_remote'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
                }
            }
            if($stage==='pending_remote'){
                $orderId=(string)($row['registrar_order_id']??'');
                if($orderId==='')throw new \RuntimeException('Registrar orderId eksik; otomatik tekrar güvenli değil.');
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
                if($nameserverOperation){
                    $nsNext=$remoteCallStarted?'needs_review':'failed';
                    $this->db->prepare("UPDATE domain_registrations SET nameserver_status=:status,locked_at=NULL,nameserver_error=:error,nameserver_updated_at=:updated,updated_at=:updated WHERE id=:id")
                        ->execute([':status'=>$nsNext,':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                }else{
                    $next=($remoteCallStarted||in_array($stage,['customer_created','contact_created','pending_remote'],true))?'needs_review':'failed';
                    $this->db->prepare("UPDATE domain_registrations SET status=:status,locked_at=NULL,last_error=:error,updated_at=:updated WHERE id=:id")
                        ->execute([':status'=>$next,':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                }
            }
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Domain kayıt işlemi tamamlanamadı; tekrarın güvenli olmadığı durumlarda manuel incelemeye alındı.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }

    public function runRenewalQueue():void
    {
        $provided='';if(preg_match('/Bearer\\s+(.+)/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$m))$provided=trim($m[1]);if($provided===''&&isset($_GET['token']))$provided=(string)$_GET['token'];
        $expected=hash_hmac('sha256','HostPilot domain cron v1',$this->applicationKey);
        if($this->applicationKey===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo 'Forbidden';return;}
        header('Content-Type: application/json; charset=utf-8');$row=null;$remoteCallStarted=false;$stage='';
        try{
            $now=gmdate('Y-m-d H:i:s');$this->db->beginTransaction();
            $this->db->prepare("UPDATE domain_renewals SET status='needs_review',locked_at=NULL,last_error='Yenileme worker zaman aşımına uğradı; registrar tarafı doğrulanmalı.',updated_at=:now WHERE status='processing' AND locked_at IS NOT NULL AND locked_at<:cutoff")->execute([':now'=>$now,':cutoff'=>gmdate('Y-m-d H:i:s',time()-900)]);
            $q=$this->db->prepare("SELECT r.*,i.status AS invoice_status,d.expiry_date AS current_expiry FROM domain_renewals r JOIN invoices i ON i.id=r.invoice_id JOIN domain_registrations d ON d.id=r.domain_registration_id WHERE r.status IN ('queued','pending_remote') AND r.available_at<=:now AND (r.locked_at IS NULL OR r.locked_at<:stale) ORDER BY r.id LIMIT 1 FOR UPDATE");
            $q->execute([':now'=>$now,':stale'=>gmdate('Y-m-d H:i:s',time()-900)]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){$this->db->commit();echo json_encode(['ok'=>true,'processed'=>0]);return;}
            $stage=(string)$row['status'];
            if($row['invoice_status']!=='paid')throw new \RuntimeException('Domain yenileme faturası tamamen ödenmemiş.');
            if($row['current_expiry']===null||strtotime((string)$row['current_expiry'])!==strtotime((string)$row['expiry_before']))throw new \RuntimeException('Domain bitiş tarihi yenileme talebinden sonra değişmiş; mükerrer yenileme riski nedeniyle inceleme gerekli.');
            $this->db->prepare("UPDATE domain_renewals SET status='processing',locked_at=:now,updated_at=:now WHERE id=:id")->execute([':now'=>$now,':id'=>$row['id']]);$this->db->commit();
            $cfg=$this->load();if(($cfg['enabled']??'0')!=='1'||empty($cfg['api_key_enc'])||empty($cfg['user_id']))throw new \RuntimeException('Domain registrar bağlantısı etkin değil veya eksik.');
            $api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)($cfg['environment']??'sandbox'));
            if($stage==='queued'){
                $expiryTimestamp=strtotime((string)$row['expiry_before'].' UTC');if(!$expiryTimestamp||$expiryTimestamp<=time())throw new \RuntimeException('Domain bitiş tarihi geçmiş veya geçersiz; otomatik yenileme yapılamaz.');
                $remoteCallStarted=true;$result=$api->renewDomain((string)$row['registrar_order_id'],(int)$row['years'],$expiryTimestamp,false);
                $eaqId=(string)($result['eaqId']??'');
                if(!ctype_digit($eaqId))throw new \RuntimeException('Registrar yenileme isteği alındı ancak takip için geçerli EAQ işlem kimliği dönmedi; manuel doğrulama gerekli.');
                $this->db->prepare("UPDATE domain_renewals SET status='pending_remote',action_eaq_id=:eaq,action_type=:type,action_status=:action,last_error=NULL,locked_at=NULL,available_at=:next,updated_at=:now WHERE id=:id")
                    ->execute([':eaq'=>$eaqId,':type'=>(string)($result['actionType']??''),':action'=>(string)($result['actionStatus']??$result['status']??'Submitted'),':next'=>gmdate('Y-m-d H:i:s',time()+120),':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                echo json_encode(['ok'=>true,'processed'=>1,'renewal'=>'submitted','message'=>'Registrar yenileme isteği gönderildi; işlem kimliği kaydedildi ve sonuç takip edilecek.']);return;
            }
            $eaqId=(string)($row['action_eaq_id']??'');
            if(!ctype_digit($eaqId))throw new \RuntimeException('Registrar yenileme işlem kimliği bulunamadı; otomatik sorgu durduruldu ve yönetici incelemesi gerekli.');
            $remoteCallStarted=true;$statusData=$api->actionStatus((string)$row['registrar_order_id'],$eaqId);$actions=$statusData['actions']??[];$action=null;
            if(is_array($actions)){foreach($actions as $candidate){if(is_array($candidate)&&(string)($candidate['eaqId']??'')===$eaqId){$action=$candidate;break;}}}
            $actionStatus=(string)($action['actionStatus']??'');$message=substr((string)($action['actionStatusDesc']??$action['message']??''),0,500);
            if(strcasecmp($actionStatus,'Completed')===0){
                $details=$api->domainDetails((string)$row['registrar_order_id']);$raw=$details['expiryDate']??$details['endTime']??$details['domainEndTime']??null;
                if($raw===null||$raw==='')throw new \RuntimeException('Registrar yenileme tamamlandı ancak yeni bitiş tarihi alanı dönmedi.');
                try{
                    if(is_numeric($raw)){
                        $timestamp=(int)$raw;if($timestamp>9999999999)$timestamp=(int)floor($timestamp/1000);
                        if($timestamp<1)throw new \RuntimeException('Geçersiz registrar bitiş zaman damgası.');
                        $expiryDate=(new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
                    }else{$expiryDate=new \DateTimeImmutable((string)$raw,new \DateTimeZone('UTC'));}
                }catch(Throwable $dateError){throw new \RuntimeException('Registrar yeni bitiş tarihi tanınan bir tarih biçiminde değil.');}
                $expiry=$expiryDate->format('Y-m-d H:i:s');
                if(strtotime($expiry)<=strtotime((string)$row['expiry_before']))throw new \RuntimeException('Registrar yenileme tamamlandı ancak yeni bitiş tarihi önceki tarihten ileri değil.');
                $now=gmdate('Y-m-d H:i:s');
                $this->db->beginTransaction();
                $this->db->prepare("UPDATE domain_renewals SET status='completed',action_status=:action,renewed_expiry=:expiry,last_error=NULL,locked_at=NULL,updated_at=:now WHERE id=:id")->execute([':action'=>$actionStatus,':expiry'=>$expiry,':now'=>$now,':id'=>$row['id']]);
                $this->db->prepare("UPDATE domain_registrations SET expiry_date=:expiry,registrar_synced_at=:now,updated_at=:now WHERE id=:id")->execute([':expiry'=>$expiry,':now'=>$now,':id'=>$row['domain_registration_id']]);
                $this->db->commit();echo json_encode(['ok'=>true,'processed'=>1,'renewal'=>'completed','expiry'=>$expiry]);return;
            }
            if(strcasecmp($actionStatus,'Failed')===0){$this->db->prepare("UPDATE domain_renewals SET status='failed',action_status=:action,last_error=:error,locked_at=NULL,updated_at=:now WHERE id=:id")->execute([':action'=>$actionStatus,':error'=>$message?:'Registrar yenileme işlemini başarısız bildirdi.',':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);echo json_encode(['ok'=>true,'processed'=>1,'renewal'=>'failed']);return;}
            $pendingStatus=$actionStatus?:'Not found yet';
            $this->db->prepare("UPDATE domain_renewals SET status='pending_remote',action_status=:action,last_error=:error,locked_at=NULL,available_at=:next,updated_at=:now WHERE id=:id")->execute([':action'=>$pendingStatus,':error'=>$message?:null,':next'=>gmdate('Y-m-d H:i:s',time()+180),':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
            echo json_encode(['ok'=>true,'processed'=>1,'renewal'=>'pending_remote','action_status'=>$pendingStatus]);
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();error_log('[HostPilot] Domain renewal worker: '.$e->getMessage());
            if($row){$status=$remoteCallStarted?'needs_review':'failed';$this->db->prepare("UPDATE domain_renewals SET status=:status,last_error=:error,locked_at=NULL,updated_at=:now WHERE id=:id")->execute([':status'=>$status,':error'=>substr($e->getMessage(),0,1000),':now'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);}
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Domain yenileme işlemi tamamlanamadı; belirsiz registrar yanıtı otomatik tekrarlanmayacak.']);
        }
    }

    /**
     * Daily, read-only registrar synchronization. Each invocation is bounded to ten domains.
     * GET requests are safe to retry; failures are recorded and retried after one hour.
     */
    public function runExpirySyncQueue():void
    {
        $provided='';
        if(preg_match('/Bearer\\s+(.+)/i',(string)($_SERVER['HTTP_AUTHORIZATION']??''),$m))$provided=trim($m[1]);
        if($provided===''&&isset($_GET['token']))$provided=(string)$_GET['token'];
        $expected=hash_hmac('sha256','HostPilot domain sync cron v1',$this->applicationKey);
        if($this->applicationKey===''||$provided===''||!hash_equals($expected,$provided)){http_response_code(403);echo 'Forbidden';return;}
        header('Content-Type: application/json; charset=utf-8');
        $stats=['synced'=>0,'failed'=>0,'skipped'=>0];
        try{
            $cfg=$this->load();
            if(($cfg['enabled']??'0')!=='1'||empty($cfg['api_key_enc'])||empty($cfg['user_id']))throw new \RuntimeException('Domain registrar bağlantısı etkin değil veya eksik.');
            $api=new ResellerClubClient((string)$cfg['user_id'],$this->vault->decrypt((string)$cfg['api_key_enc']),(string)($cfg['environment']??'sandbox'));
            for($n=0;$n<10;$n++){
                $row=null;$now=gmdate('Y-m-d H:i:s');
                $this->db->beginTransaction();
                $this->db->prepare("UPDATE domain_registrations SET locked_at=NULL WHERE status='active' AND (nameserver_status IS NULL OR nameserver_status<>'processing') AND locked_at IS NOT NULL AND locked_at<:stale")->execute([':stale'=>gmdate('Y-m-d H:i:s',time()-900)]);
                $pick=$this->db->prepare("SELECT id,domain_name,registrar_order_id FROM domain_registrations
                    WHERE status='active' AND registrar_order_id IS NOT NULL AND registrar_order_id<>''
                    AND (locked_at IS NULL)
                    AND (nameserver_status IS NULL OR nameserver_status NOT IN ('queued','processing'))
                    AND (registrar_sync_attempted_at IS NULL
                      OR (registrar_sync_error IS NULL AND registrar_sync_attempted_at<=:daily)
                      OR (registrar_sync_error IS NOT NULL AND registrar_sync_attempted_at<=:retry))
                    ORDER BY COALESCE(registrar_sync_attempted_at,'1970-01-01 00:00:00'),id
                    LIMIT 1 FOR UPDATE");
                $pick->execute([':daily'=>gmdate('Y-m-d H:i:s',time()-86400),':retry'=>gmdate('Y-m-d H:i:s',time()-3600)]);
                $row=$pick->fetch(PDO::FETCH_ASSOC);
                if(!$row){$this->db->commit();break;}
                $this->db->prepare('UPDATE domain_registrations SET locked_at=:now,registrar_sync_attempted_at=:attempted,updated_at=:updated WHERE id=:id')
                    ->execute([':now'=>$now,':attempted'=>$now,':updated'=>$now,':id'=>$row['id']]);
                $this->db->commit();
                try{
                    $details=$api->domainDetails((string)$row['registrar_order_id']);
                    $expiryRaw=$details['expiryDate']??$details['endTime']??$details['domainEndTime']??null;
                    $expiry=$this->parseRegistrarExpiry($expiryRaw);
                    if($expiry===null)throw new \RuntimeException('Registrar yanıtında doğrulanabilir son kullanma tarihi bulunamadı.');
                    $autoRenew=array_key_exists('isAutoRenew',$details)?(filter_var($details['isAutoRenew'],FILTER_VALIDATE_BOOLEAN)?1:0):null;
                    $synced=gmdate('Y-m-d H:i:s');
                    $this->db->prepare('UPDATE domain_registrations SET expiry_date=:expiry,registrar_auto_renew=:auto_renew,registrar_synced_at=:synced,registrar_sync_error=NULL,locked_at=NULL,updated_at=:updated WHERE id=:id')
                        ->execute([':expiry'=>$expiry,':auto_renew'=>$autoRenew,':synced'=>$synced,':updated'=>$synced,':id'=>$row['id']]);
                    $stats['synced']++;
                }catch(Throwable $e){
                    $this->db->prepare('UPDATE domain_registrations SET registrar_sync_error=:error,locked_at=NULL,updated_at=:updated WHERE id=:id')
                        ->execute([':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$row['id']]);
                    $stats['failed']++;
                    error_log('[HostPilot] Domain expiry sync failed for registration '.$row['id'].': '.$e->getMessage());
                }
            }
            echo json_encode(['ok'=>true,'results'=>$stats,'limit'=>10],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }catch(Throwable $e){
            error_log('[HostPilot] Domain expiry sync worker failed: '.$e->getMessage());
            http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Domain expiry synchronization failed.'],JSON_UNESCAPED_SLASHES);
        }
    }

    private function parseRegistrarExpiry(mixed $value):?string
    {
        if(!is_scalar($value)||$value==='')return null;
        if(is_numeric($value)){
            $timestamp=(int)$value;
            if($timestamp>9999999999)$timestamp=(int)floor($timestamp/1000);
            if($timestamp<=0)return null;
        }else{
            $timestamp=strtotime((string)$value);
            if($timestamp===false)return null;
        }
        return gmdate('Y-m-d H:i:s',$timestamp);
    }

    private function load():array{$q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='domain_registrar'");$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['setting_key']]=$r['setting_value'];return $out;}
    private function admin():bool{$u=Auth::user();if(!$u){$this->go('/admin/login');return false;}if(!in_array($u['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return false;}return true;}
    private function page(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.nav-item.active{background:#1b2a46;border-radius:8px}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1300px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.topbar h1{font-size:21px}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p{color:#9aabc2;line-height:1.6}.panel label{display:block;margin:14px 0;color:#c4d0e2}.panel input:not([type=checkbox]),.panel select{display:block;width:100%;margin-top:7px;padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.panel button,.button{padding:12px 17px;background:#5278ed;color:white;border:0;border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none}.panel hr{border:0;border-top:1px solid #26354c;margin:28px 0}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0;background:#172f35;color:#a9f0d0}.alert{background:#4b202b;color:#ffc3ca}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.sidebar .brand{font-size:0}.sidebar .brand span{font-size:12px}.nav-item{font-size:0}.main{padding:0 12px 20px}}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$url=rtrim($this->basePath,'/').$p;if($query!=='')$url.='?'.$query;header('Location: '.$url);exit;}
}