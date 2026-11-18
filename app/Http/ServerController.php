<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\Authorization;
use HostPilot\Core\SecurityAudit;
use HostPilot\Core\SecretVault;
use HostPilot\Hosting\CpanelWhmAdapter;
use HostPilot\Hosting\DirectAdminAdapter;
use HostPilot\Hosting\PleskXmlAdapter;
use HostPilot\Hosting\HostingTransport;
use PDO;
use Throwable;

final class ServerController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,private readonly string $basePath,string $appKey)
    {$this->vault=new SecretVault($appKey);}

    public function servers():void
    {
        if(!$this->allowed())return;
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        $servers=$this->db->query("SELECT * FROM servers ORDER BY id DESC")->fetchAll();
        $products=$this->db->query("SELECT id,name FROM products ORDER BY name")->fetchAll();
        $this->start('Sunucu Yönetimi','servers');
        echo '<div class="hero"><div><small>ALTYAPI / KONTROL PANELİ BAĞLANTILARI</small><h1>Sunucu yönetimi</h1><p>cPanel/WHM, DirectAdmin ve Plesk sunucularınızı tek merkezden bağlayın.</p></div><span class="pill">'.count($servers).' sunucu</span></div>';
        if($notice!=='')echo '<div class="notice ok">'.$this->e($notice).'</div>';
        if($error!=='')echo '<div class="notice err">'.$this->e($error).'</div>';
        echo '<section class="panel"><h2>Yeni sunucu bağlantısı</h2><form method="post" action="'.$this->url('/admin/servers/save').'" class="form" id="server-connection-form"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Sunucu adı<input name="name" required maxlength="120" placeholder="Ana Hosting Sunucusu"></label><label>Kontrol paneli<select name="panel_type" id="panel_type"><option value="cpanel">cPanel / WHM</option><option value="directadmin">DirectAdmin</option><option value="plesk">Plesk</option></select></label><div class="full" id="panel-help" style="padding:12px;background:#101d31;border-radius:9px;color:#aebbd0">cPanel / WHM: WHM API token ile bağlantı kurulur. Varsayılan port 2087.</div><label>Sunucu adresi (HTTPS)<input name="base_url" id="server_base_url" type="url" required placeholder="https://server.example.com:2087"></label><label id="server-username-wrap">API kullanıcı adı<input name="username" id="server_username" required autocomplete="off" placeholder="root veya WHM yetkili kullanıcı"></label><div id="auth-mode-wrap" style="display:none"><label>Plesk kimlik doğrulama<select name="auth_mode" id="auth_mode"><option value="key">API KEY</option><option value="password">Kullanıcı adı + parola</option></select></label></div><label><span id="server-secret-label">WHM API token</span><input name="secret" id="server_secret" type="password" required autocomplete="new-password" placeholder="WHM API token"></label><label id="shared-ip-wrap" style="display:none">Plesk paylaşımlı IP adresi<input name="shared_ip" id="shared_ip" placeholder="203.0.113.10"></label><div class="full"><button>Sunucuyu kaydet</button><small>API sırları veritabanında şifreli tutulur. Bağlantılar HTTPS üzerinden yapılır ve TLS sertifika kontrolü kapatılamaz.</small></div></form><script>(function(){const panel=document.getElementById("panel_type"),url=document.getElementById("server_base_url"),userWrap=document.getElementById("server-username-wrap"),user=document.getElementById("server_username"),modeWrap=document.getElementById("auth-mode-wrap"),mode=document.getElementById("auth_mode"),secretLabel=document.getElementById("server-secret-label"),secret=document.getElementById("server_secret"),ipWrap=document.getElementById("shared-ip-wrap"),ip=document.getElementById("shared_ip"),help=document.getElementById("panel-help");function sync(){const p=panel.value,plesk=p==="plesk",key=plesk&&mode.value==="key";if(p==="cpanel"){url.placeholder="https://server.example.com:2087";user.placeholder="root veya WHM yetkili kullanıcı";secretLabel.textContent="WHM API token";secret.placeholder="WHM API token";help.textContent="cPanel / WHM: WHM API token kullanın. Genellikle 2087 portu kullanılır.";}else if(p==="directadmin"){url.placeholder="https://server.example.com:2222";user.placeholder="DirectAdmin kullanıcı adı (admin/reseller)";secretLabel.textContent="DirectAdmin Login Key";secret.placeholder="Login Key parolası";help.textContent="DirectAdmin: API erişimi olan sınırlı yetkili bir Login Key kullanın. Genellikle 2222 portu kullanılır.";}else{url.placeholder="https://server.example.com:8443";user.placeholder="Plesk yöneticisi veya API kullanıcısı";secretLabel.textContent=key?"Plesk API KEY":"Plesk API parolası";secret.placeholder=key?"Plesk API KEY":"Plesk API parolası";help.textContent="Plesk XML API: API KEY veya kullanıcı adı/parola doğrulamasını seçin. Genellikle 8443 portu kullanılır. Abonelik oluşturma için paylaşımlı IP gereklidir.";}modeWrap.style.display=plesk?"block":"none";userWrap.style.display=key?"none":"block";user.required=!key;ipWrap.style.display=plesk?"block":"none";ip.required=plesk;user.disabled=key;ip.disabled=!plesk;mode.disabled=!plesk;}panel.addEventListener("change",sync);mode.addEventListener("change",sync);sync();})();</script></section>';
        foreach($servers as $server){
            $maps=$this->db->prepare("SELECT m.product_id,m.remote_package,m.remote_package_guid,m.remote_package_external_id,p.name FROM server_product_map m JOIN products p ON p.id=m.product_id WHERE m.server_id=:id ORDER BY p.name");$maps->execute([':id'=>$server['id']]);
            echo '<section class="panel server"><div class="serverhead"><div><h2>'.$this->e($server['name']).'</h2><p>'.$this->e(strtoupper($server['panel_type'])).' · '.$this->e($server['base_url']).'</p><small>Son bağlantı: '.$this->e((string)($server['last_test_at']??'Henüz test edilmedi')).' · '.$this->e((string)($server['last_test_message']??'')).'</small></div><span class="pill '.($server['enabled']?'green':'gray').'">'.($server['enabled']?'Etkin':'Pasif').'</span></div><div class="actions"><form method="post" action="'.$this->url('/admin/servers/test').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="server_id" value="'.(int)$server['id'].'"><button class="secondary">Bağlantıyı test et</button></form></div><h3>Ürün / uzak paket eşleştirmeleri</h3>';
            foreach($maps->fetchAll() as $map){
                echo '<div class="maprow"><span>'.$this->e($map['name']).'</span><code>'.$this->e($map['remote_package']).'</code>';
                if($server['panel_type']==='plesk')echo '<small>Plan GUID: '.$this->e((string)($map['remote_package_guid']??'—')).' · Harici kimlik: '.$this->e((string)($map['remote_package_external_id']??'—')).'</small>';
                echo '</div>';
            }
            echo '<form method="post" action="'.$this->url('/admin/servers/map').'" class="mapform"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="server_id" value="'.(int)$server['id'].'"><select name="product_id" required><option value="">Ürün seçin</option>';
            foreach($products as $product)echo '<option value="'.(int)$product['id'].'">'.$this->e($product['name']).'</option>';
            echo '</select><input name="remote_package" required placeholder="Kontrol panelindeki paket adı">';
            if($server['panel_type']==='plesk')echo '<input name="remote_package_guid" maxlength="190" placeholder="Plesk plan GUID"><input name="remote_package_external_id" maxlength="190" placeholder="Plesk plan external ID">';
            echo '<button class="secondary">Eşleştir</button></form></section>';
        }
        if(!$servers)echo '<section class="panel empty">Henüz sunucu tanımlanmamış. İlk bağlantınızı yukarıdan ekleyin.</section>';
        echo '<section class="panel"><h2>Önemli güvenlik notu</h2><p>WHM için API token, DirectAdmin için sınırlı yetkili Login Key, Plesk için IP kısıtlı XML API KEY kullanılması önerilir. API sırları veritabanında şifreli tutulur.</p></section>';
        $this->end();
    }

    public function saveServer():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf()){$this->go('/admin/servers','error=Güvenlik doğrulaması başarısız.');}
        try{
            $name=trim((string)($_POST['name']??''));$panel=(string)($_POST['panel_type']??'');$base=HostingTransport::validateBaseUrl((string)($_POST['base_url']??''));$user=trim((string)($_POST['username']??''));$secret=(string)($_POST['secret']??'');$mode=(string)($_POST['auth_mode']??'key');$ip=trim((string)($_POST['shared_ip']??''));
            if($name===''||!in_array($panel,['cpanel','directadmin','plesk'],true)||$secret==='')throw new \RuntimeException('Sunucu adı, panel türü, adres ve API sırrı zorunludur.');
            if($panel==='plesk'&&!in_array($mode,['key','password'],true))throw new \RuntimeException('Plesk kimlik doğrulama yöntemi geçersiz.');
            if($panel==='plesk'&&$mode==='key')$user='';
            elseif($user==='')throw new \RuntimeException('Seçilen bağlantı yöntemi için API kullanıcı adı zorunludur.');
            if($panel!=='plesk')$mode='password';
            if($panel==='plesk'&&$ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP))throw new \RuntimeException('Plesk paylaşımlı IP adresi geçersiz.');
            if($panel==='plesk'&&$ip==='')throw new \RuntimeException('Plesk üzerinde hosting hesabı oluşturmak için paylaşımlı IP adresi gereklidir.');
            $q=$this->db->prepare("INSERT INTO servers(name,panel_type,base_url,username,secret_enc,auth_mode,shared_ip,enabled,created_at,updated_at) VALUES(:name,:panel,:base,:username,:secret,:mode,:ip,1,:created,:updated)");
            $now=gmdate('Y-m-d H:i:s');$q->execute([':name'=>$name,':panel'=>$panel,':base'=>$base,':username'=>$user,':secret'=>$this->vault->encrypt($secret),':mode'=>$mode,':ip'=>$ip!==''?$ip:null,':created'=>$now,':updated'=>$now]);
            $actor=Auth::user();SecurityAudit::record($this->db,'server.created',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>(int)$this->db->lastInsertId(),'action'=>'created']);
            $this->go('/admin/servers','notice=Sunucu bağlantısı kaydedildi. Şimdi bağlantı testini çalıştırın.');
        }catch(Throwable $e){$this->go('/admin/servers','error='.rawurlencode($e->getMessage()));}
    }

    public function testServer():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/servers','error=Güvenlik doğrulaması başarısız.');
        $id=(int)($_POST['server_id']??0);
        try{
            $server=$this->server($id);$adapter=$this->adapter($server);$message=$adapter->testConnection();
            $q=$this->db->prepare("UPDATE servers SET last_test_at=:at,last_test_status='success',last_test_message=:message,updated_at=:at2 WHERE id=:id");
            $q->execute([':at'=>gmdate('Y-m-d H:i:s'),':message'=>substr($message,0,500),':at2'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $actor=Auth::user();SecurityAudit::record($this->db,'server.connection_tested',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'success']);
            $this->go('/admin/servers','notice='.$message);
        }catch(Throwable $e){
            $q=$this->db->prepare("UPDATE servers SET last_test_at=:at,last_test_status='failed',last_test_message=:message,updated_at=:at2 WHERE id=:id");
            $q->execute([':at'=>gmdate('Y-m-d H:i:s'),':message'=>substr($e->getMessage(),0,500),':at2'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $actor=Auth::user();SecurityAudit::record($this->db,'server.connection_tested',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'failed']);
            $this->go('/admin/servers','error='.$e->getMessage());
        }
    }

    public function saveMapping():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/servers','error=Güvenlik doğrulaması başarısız.');
        $server=(int)($_POST['server_id']??0);$product=(int)($_POST['product_id']??0);$package=trim((string)($_POST['remote_package']??''));
        $guid=trim((string)($_POST['remote_package_guid']??''));$externalId=trim((string)($_POST['remote_package_external_id']??''));
        if($server<1||$product<1||$package===''||strlen($package)>190||strlen($guid)>190||strlen($externalId)>190||preg_match('/[\\r\\n\\x00]/',$package.$guid.$externalId))$this->go('/admin/servers','error=Ürün veya uzak paket bilgileri geçersiz.');
        $panelQuery=$this->db->prepare('SELECT panel_type FROM servers WHERE id=:id AND enabled=1 LIMIT 1');$panelQuery->execute([':id'=>$server]);$panel=(string)$panelQuery->fetchColumn();
        if($panel==='')$this->go('/admin/servers','error=Etkin sunucu bulunamadı.');
        if($panel==='plesk'&&(($guid==='')!==($externalId==='')))$this->go('/admin/servers','error=Plesk için GUID ve harici plan kimliği birlikte girilmelidir.');
        $q=$this->db->prepare("INSERT INTO server_product_map(server_id,product_id,remote_package,remote_package_guid,remote_package_external_id,created_at,updated_at) VALUES(:server,:product,:package,:guid,:external_id,:created,:updated) ON DUPLICATE KEY UPDATE remote_package=VALUES(remote_package),remote_package_guid=VALUES(remote_package_guid),remote_package_external_id=VALUES(remote_package_external_id),updated_at=VALUES(updated_at)");
        $now=gmdate('Y-m-d H:i:s');$q->execute([':server'=>$server,':product'=>$product,':package'=>$package,':guid'=>$guid!==''?$guid:null,':external_id'=>$externalId!==''?$externalId:null,':created'=>$now,':updated'=>$now]);
        $actor=Auth::user();SecurityAudit::record($this->db,'server.product_mapping_updated',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$server,'action'=>'updated']);
        $this->go('/admin/servers','notice=Ürün ve sunucu paketi eşleştirildi.');
    }

    public function services():void
    {
        if(!$this->allowed())return;
        $rows=$this->db->query("SELECT s.*,u.name AS customer_name,u.email,p.name AS product_name,sv.name AS server_name,EXISTS(SELECT 1 FROM provisioning_jobs pj WHERE pj.service_id=s.id AND LEFT(pj.job_type,10)='terminate_' AND pj.status IN ('queued','processing','needs_review')) AS termination_pending FROM services s JOIN users u ON u.id=s.user_id JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id ORDER BY s.id DESC LIMIT 300")->fetchAll();
        $servers=$this->db->query("SELECT id,name FROM servers WHERE enabled=1 ORDER BY name")->fetchAll();
        $this->start('Hizmetler','services');
        echo '<div class="hero"><div><small>OPERASYON / HOSTING HİZMETLERİ</small><h1>Hizmet yönetimi</h1><p>Ödenmiş siparişlerden oluşan bekleyen hizmetleri sunuculara atayın ve hesapları otomatik oluşturun.</p></div><span class="pill">'.count($rows).' hizmet</span></div><section class="panel"><div class="tablewrap"><table><thead><tr><th>Müşteri / Ürün</th><th>Domain</th><th>Durum</th><th>Sunucu</th><th>İşlem</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td><b>'.$this->e($row['customer_name']).'</b><small>'.$this->e($row['email']).'</small><small>'.$this->e($row['product_name']).'</small><small><a href="'.$this->url('/admin/services/'.(int)$row['id']).'">Hizmet detayını aç →</a></small></td><td>'.$this->e((string)($row['domain']??'—')).'</td><td><b>'.$this->e((string)$row['status']).'</b><small>'.$this->e((string)($row['provisioning_status']??'not_started')).'</small>'.(!empty($row['provisioning_error'])?'<small class="error">'.$this->e($row['provisioning_error']).'</small>':'').'</td><td>'.$this->e((string)($row['server_name']??'Atanmadı')).'</td><td>';
            if(empty($row['provisioned_at'])&&$row['status']==='pending'&&($row['provisioning_status']??'')!=='needs_review'){
                echo '<form method="post" action="'.$this->url('/admin/services/provision').'" class="provision"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.(int)$row['id'].'"><select name="server_id" required><option value="">Sunucu seçin</option>';
                foreach($servers as $sv)echo '<option value="'.(int)$sv['id'].'">'. $this->e($sv['name']).'</option>';
                echo '</select><input name="domain" required placeholder="example.com" value="'.$this->e((string)($row['domain']??'')).'"><button>Hesabı oluştur</button></form>';
            }elseif(!empty($row['provisioned_at'])&&in_array($row['status'],['active','suspended'],true)){
                $op=$row['status']==='active'?'suspend':'unsuspend';$label=$op==='suspend'?'Hizmeti askıya al':'Yeniden etkinleştir';
                echo '<form method="post" action="'.$this->url('/admin/services/lifecycle').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.(int)$row['id'].'"><input type="hidden" name="operation" value="'.$op.'"><button class="secondary">'.$label.'</button></form>';
                if(!(int)$row['termination_pending']){
                    echo '<details class="provision"><summary style="color:#ff9da8;cursor:pointer">Hesabı kalıcı olarak sonlandır</summary><form method="post" action="'.$this->url('/admin/services/terminate').'" onsubmit="return confirm(\'Bu işlem uzak hosting hesabını ve içeriğini kalıcı olarak silecek. Devam edilsin mi?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.(int)$row['id'].'"><label>Onay için domain adını aynen yazın<input name="confirm_domain" required autocomplete="off" placeholder="'.$this->e((string)$row['remote_domain']).'"></label><button style="background:#a93248">Kalıcı sonlandırma kuyruğuna al</button></form></details>';
                }else echo '<span class="muted">Sonlandırma işi kuyrukta / incelemede</span>';
            }elseif($row['status']==='terminated')echo '<span class="muted">Sonlandırıldı</span>';
            else echo '<span class="muted">İşlem bekliyor</span>';
            echo '</td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="5">Henüz hizmet bulunmuyor.</td></tr>';
        echo '</tbody></table></div></section><section class="panel"><h2>Otomatik aktivasyon</h2><p>Müşteri alan adını müşteri panelinden kaydeder. Eşleşen sunucu ve paket seçilerek hesap, cPanel Cron Jobs üzerinden sıraya alınmış işçi tarafından oluşturulur.</p><p><a href="'.$this->url('/admin/automation').'">Cron ve otomasyon ayarlarını görüntüle →</a></p><p>Uzak sunucu isteğinin sonucu belirsiz kalırsa yinelenen hesap açılmasını önlemek için hizmet yönetici incelemesine alınır.</p></section>';
        $this->end();
    }

    public function viewService(int $id):void
    {
        if(!$this->allowed())return;
        $q=$this->db->prepare("SELECT s.*,u.name AS customer_name,u.email,p.name AS product_name,sv.name AS server_name,o.currency AS currency,EXISTS(SELECT 1 FROM provisioning_jobs pj WHERE pj.service_id=s.id AND LEFT(pj.job_type,10)='terminate_' AND pj.status IN ('queued','processing','needs_review')) AS termination_pending FROM services s JOIN users u ON u.id=s.user_id JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id LEFT JOIN orders o ON o.id=s.order_id WHERE s.id=:id LIMIT 1");
        $q->execute([':id'=>$id]);$service=$q->fetch(PDO::FETCH_ASSOC);
        if(!$service){http_response_code(404);echo 'Hizmet bulunamadı.';return;}
        $invoices=$this->db->prepare("SELECT id,invoice_number,status,currency,total,amount_paid,due_at,issued_at FROM invoices WHERE (service_id=:service OR order_id=:order) AND invoice_number NOT LIKE 'DEMO-%' ORDER BY id DESC LIMIT 50");
        $invoices->execute([':service'=>$id,':order'=>(int)$service['order_id']]);$invoiceRows=$invoices->fetchAll(PDO::FETCH_ASSOC);
        $logs=$this->db->prepare('SELECT action,status,message,created_at FROM provisioning_logs WHERE service_id=:id ORDER BY id DESC LIMIT 50');
        $logs->execute([':id'=>$id]);$logRows=$logs->fetchAll(PDO::FETCH_ASSOC);
        $servers=$this->db->query('SELECT id,name FROM servers WHERE enabled=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $currency=(string)($service['currency']??'TRY');
        $money=static fn(mixed $amount,string $code):string=>number_format((float)$amount,2,',','.').' '.htmlspecialchars($code,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

        $this->start('Hizmet detayı','services');
        echo '<div class="hero"><div><small>OPERASYON / HİZMETLER / DETAY</small><h1>'.$this->e((string)$service['product_name']).'</h1><p>'.$this->e((string)($service['domain']??'Domain atanmamış')).' · '.$this->e((string)$service['customer_name']).'</p></div><span class="pill">'.$this->e((string)$service['status']).'</span></div>';
        echo '<section class="panel"><h2>Hizmet bilgileri</h2><div class="status"><span>Müşteri</span><strong><a href="'.$this->url('/admin/customers/'.(int)$service['user_id']).'">'.$this->e((string)$service['customer_name']).' · '.$this->e((string)$service['email']).'</a></strong></div><div class="status"><span>Ürün</span><strong>'.$this->e((string)$service['product_name']).'</strong></div><div class="status"><span>Domain</span><strong>'.$this->e((string)($service['domain']??'—')).'</strong></div><div class="status"><span>Hizmet durumu</span><strong>'.$this->e((string)$service['status']).'</strong></div><div class="status"><span>Provisioning</span><strong>'.$this->e((string)($service['provisioning_status']??'not_started')).'</strong></div><div class="status"><span>Sunucu</span><strong>'.$this->e((string)($service['server_name']??'Atanmadı')).'</strong></div><div class="status"><span>Fiyat / dönem</span><strong>'.$money($service['amount'],$currency).' · '.$this->e((string)$service['billing_cycle']).'</strong></div><div class="status"><span>Sonraki yenileme</span><strong>'.$this->e((string)($service['next_due_date']??'—')).'</strong></div><div class="status"><span>Askıya alma kaynağı</span><strong>'.$this->e((string)($service['suspension_source']??'none')).'</strong></div><div class="status"><span>Uzak hesap kullanıcı adı</span><strong>'.$this->e((string)($service['remote_username']??'—')).'</strong></div><div class="status"><span>Uzak domain / kimlik</span><strong>'.$this->e((string)($service['remote_domain']??'—')).' · '.$this->e((string)($service['remote_identifier']??'—')).'</strong></div>';
        if(!empty($service['provisioning_error']))echo '<p class="error">Son işlem hatası: '.$this->e((string)$service['provisioning_error']).'</p>';
        echo '<p class="muted">Uzak hesap parolası bu ekranda gösterilmez. Şifrelenmiş müşteri erişim bilgileri gizli tutulur.</p><div class="provision">';
        if(empty($service['provisioned_at'])&&$service['status']==='pending'&&($service['provisioning_status']??'')!=='needs_review'){
            echo '<form method="post" action="'.$this->url('/admin/services/provision').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><label>Sunucu<select name="server_id" required><option value="">Sunucu seçin</option>';
            foreach($servers as $server)echo '<option value="'.(int)$server['id'].'">'.$this->e((string)$server['name']).'</option>';
            echo '</select></label><label>Domain<input name="domain" required value="'.$this->e((string)($service['domain']??'')).'" placeholder="example.com"></label><button>Hosting hesabını oluştur</button></form>';
        }elseif(!empty($service['provisioned_at'])&&in_array($service['status'],['active','suspended'],true)){
            $operation=$service['status']==='active'?'suspend':'unsuspend';$label=$operation==='suspend'?'Hizmeti askıya al':'Yeniden etkinleştir';
            echo '<form method="post" action="'.$this->url('/admin/services/lifecycle').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><input type="hidden" name="operation" value="'.$operation.'"><button class="secondary">'.$label.'</button></form>';
            if(empty($service['termination_pending']))echo '<details><summary style="color:#ff9da8;cursor:pointer">Kalıcı sonlandırma kuyruğuna al</summary><form method="post" action="'.$this->url('/admin/services/terminate').'" onsubmit="return confirm(\'Uzak hosting hesabı kalıcı olarak silinecek. Devam edilsin mi?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.$id.'"><label>Onay için uzak domaini yazın<input name="confirm_domain" required autocomplete="off" placeholder="'.$this->e((string)$service['remote_domain']).'"></label><button style="background:#a93248">Kalıcı sonlandır</button></form></details>';
        }elseif($service['status']==='terminated')echo '<span class="muted">Hizmet sonlandırılmış.</span>';
        else echo '<span class="muted">Bu hizmet mevcut durumunda doğrudan işlem için uygun değil.</span>';
        echo '</div></section><section class="panel"><h2>Fatura geçmişi</h2>';
        if(!$invoiceRows)echo '<p>Bu hizmete bağlı gerçek fatura bulunmuyor.</p>';
        foreach($invoiceRows as $invoice)echo '<div class="status"><span>'.$this->e((string)$invoice['invoice_number']).'<small>'.$this->e((string)$invoice['status']).' · Vade: '.$this->e((string)($invoice['due_at']??'—')).'</small></span><strong>'.$money($invoice['amount_paid'],(string)$invoice['currency']).' / '.$money($invoice['total'],(string)$invoice['currency']).'</strong></div>';
        echo '</section><section class="panel"><h2>İşlem geçmişi</h2>';
        if(!$logRows)echo '<p>Henüz provisioning işlem kaydı yok.</p>';
        foreach($logRows as $log)echo '<div class="status"><span>'.$this->e((string)$log['action']).' · '.$this->e((string)$log['status']).'<small>'.$this->e((string)$log['created_at']).'</small></span><strong>'.$this->e((string)$log['message']).'</strong></div>';
        echo '</section>';
        $this->end();
    }

    public function provision():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/services','error=Güvenlik doğrulaması başarısız.');
        $id=(int)($_POST['service_id']??0);$serverId=(int)($_POST['server_id']??0);$domain=strtolower(trim((string)($_POST['domain']??'')));
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',$domain))$this->go('/admin/services','error=Geçerli bir alan adı girin.');
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT s.*,u.email FROM services s JOIN users u ON u.id=s.user_id WHERE s.id=:id FOR UPDATE");$q->execute([':id'=>$id]);$service=$q->fetch();
            if(!$service)throw new \RuntimeException('Hizmet bulunamadı.');
            if($service['status']!=='pending'||!empty($service['provisioned_at'])||in_array($service['provisioning_status'],['in_progress','completed'],true))throw new \RuntimeException('Bu hizmet şu anda otomatik hesap oluşturmaya uygun değil.');
            $server=$this->server($serverId);
            $map=$this->db->prepare("SELECT remote_package FROM server_product_map WHERE server_id=:server AND product_id=:product LIMIT 1");$map->execute([':server'=>$serverId,':product'=>$service['product_id']]);$package=(string)$map->fetchColumn();
            if($package==='')throw new \RuntimeException('Bu ürün için seçilen sunucuda uzak paket eşleştirmesi yapılmamış.');
            $this->db->prepare("UPDATE services SET provisioning_status='in_progress',provisioning_error=NULL,server_id=:server,domain=:domain,remote_domain=:domain2,updated_at=:updated WHERE id=:id")->execute([':server'=>$serverId,':domain'=>$domain,':domain2'=>$domain,':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->db->commit();
            $username='hp'.substr(bin2hex(random_bytes(6)),0,6);$password=rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
            $result=$this->adapter($server)->createAccount(['username'=>$username,'password'=>$password,'domain'=>$domain,'email'=>(string)$service['email'],'package'=>$package]);
            $now=gmdate('Y-m-d H:i:s');
            $up=$this->db->prepare("UPDATE services SET status='active',provisioning_status='completed',remote_identifier=:remote,remote_username=:username,remote_password_enc=:password,provisioned_at=:at,provisioning_error=NULL,updated_at=:updated WHERE id=:id AND provisioning_status='in_progress'");
            $up->execute([':remote'=>$result['identifier'],':username'=>$result['username'],':password'=>$this->vault->encrypt($password),':at'=>$now,':updated'=>$now,':id'=>$id]);
            $this->log($id,$serverId,'create','success','Hosting hesabı oluşturuldu: '.$result['identifier']);
            $actor=Auth::user();SecurityAudit::record($this->db,'service.provision_completed',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'completed']);
            $this->go('/admin/services','notice=Hosting hesabı başarıyla oluşturuldu.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->db->prepare("UPDATE services SET provisioning_status='failed',provisioning_error=:error,updated_at=:updated WHERE id=:id")->execute([':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->log($id,$serverId,'create','failed',substr($e->getMessage(),0,1000));
            $actor=Auth::user();SecurityAudit::record($this->db,'service.provision_failed',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'failed']);
            $this->go('/admin/services','error='.$e->getMessage());
        }
    }

    public function lifecycle():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/services','error=Güvenlik doğrulaması başarısız.');
        $id=(int)($_POST['service_id']??0);$operation=(string)($_POST['operation']??'');
        if(!in_array($operation,['suspend','unsuspend'],true))$this->go('/admin/services','error=Geçersiz hizmet işlemi.');
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT * FROM services WHERE id=:id FOR UPDATE");$q->execute([':id'=>$id]);$service=$q->fetch();
            if(!$service||empty($service['provisioned_at'])||empty($service['remote_username'])||empty($service['remote_domain'])||empty($service['server_id']))throw new \RuntimeException('Sunucuda oluşturulmuş hizmet kaydı bulunamadı.');
            if($operation==='suspend'&&$service['status']!=='active')throw new \RuntimeException('Yalnızca aktif hizmetler askıya alınabilir.');
            if($operation==='unsuspend'&&$service['status']!=='suspended')throw new \RuntimeException('Yalnızca askıdaki hizmetler yeniden etkinleştirilebilir.');
            $server=$this->server((int)$service['server_id']);
            $this->db->commit();
            $adapter=$this->adapter($server);
            if($operation==='suspend')$adapter->suspendAccount((string)$service['remote_username'],(string)$service['remote_domain'],'HostPilot billing / administrator action');
            else $adapter->unsuspendAccount((string)$service['remote_username'],(string)$service['remote_domain']);
            $newStatus=$operation==='suspend'?'suspended':'active';$now=gmdate('Y-m-d H:i:s');
            $u=$this->db->prepare("UPDATE services SET status=:status,suspension_source=:source,suspension_invoice_id=NULL,suspended_at=:suspended,updated_at=:updated WHERE id=:id AND status=:old");
            $u->execute([':status'=>$newStatus,':source'=>$operation==='suspend'?'admin':'none',':suspended'=>$operation==='suspend'?$now:null,':updated'=>$now,':id'=>$id,':old'=>$service['status']]);
            if($u->rowCount()!==1)throw new \RuntimeException('Hizmet durumu eşzamanlı değişti; işlem tekrar kontrol edilmeli.');
            $this->log($id,(int)$service['server_id'],$operation,'success','Kontrol paneli hizmet durumu: '.$newStatus);
            $actor=Auth::user();SecurityAudit::record($this->db,'service.lifecycle_completed',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>$operation]);
            $this->go('/admin/services','notice=Sunucu ve HostPilot hizmet durumu güncellendi.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->log($id,0,$operation,'failed',substr($e->getMessage(),0,900));
            $actor=Auth::user();SecurityAudit::record($this->db,'service.lifecycle_failed',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'failed']);
            $this->go('/admin/services','error='.$e->getMessage());
        }
    }

    public function terminate():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/services','error=Güvenlik doğrulaması başarısız.');
        $id=(int)($_POST['service_id']??0);$confirmation=trim((string)($_POST['confirm_domain']??''));
        try{
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT * FROM services WHERE id=:id FOR UPDATE");$q->execute([':id'=>$id]);$service=$q->fetch();
            if(!$service||empty($service['provisioned_at'])||empty($service['remote_username'])||empty($service['remote_domain'])||empty($service['server_id']))throw new \RuntimeException('Sonlandırılabilir uzak hosting hesabı bulunamadı.');
            if(!in_array($service['status'],['active','suspended'],true))throw new \RuntimeException('Hizmet mevcut durumunda sonlandırılamaz.');
            if(!hash_equals((string)$service['remote_domain'],$confirmation))throw new \RuntimeException('Güvenlik için alan adı birebir aynı yazılmalıdır.');
            $pending=$this->db->prepare("SELECT id FROM provisioning_jobs WHERE service_id=:service AND LEFT(job_type,10)='terminate_' AND status IN ('queued','processing','needs_review') LIMIT 1 FOR UPDATE");$pending->execute([':service'=>$id]);
            if($pending->fetchColumn())throw new \RuntimeException('Bu hizmet için zaten bekleyen bir sonlandırma işi var.');
            $now=gmdate('Y-m-d H:i:s');$type='terminate_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
            $insert=$this->db->prepare("INSERT INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at) VALUES(:service,:type,'queued',0,:available,:created,:updated)");
            $insert->execute([':service'=>$id,':type'=>$type,':available'=>$now,':created'=>$now,':updated'=>$now]);
            $this->db->commit();
            $this->log($id,(int)$service['server_id'],'terminate_queued','success','Yönetici domain doğrulamasıyla kalıcı sonlandırma kuyruğa alındı.');
            $actor=Auth::user();SecurityAudit::record($this->db,'service.termination_requested',isset($actor['id'])?(int)$actor['id']:null,['resource_id'=>$id,'action'=>'queued']);
            $this->go('/admin/services','notice=Sonlandırma işi kuyruğa alındı. Cron worker uzak işlemi gerçekleştirecek.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->go('/admin/services','error='.$e->getMessage());
        }
    }

    private function server(int $id):array
    {
        $q=$this->db->prepare("SELECT * FROM servers WHERE id=:id AND enabled=1 LIMIT 1");$q->execute([':id'=>$id]);$s=$q->fetch();
        if(!$s)throw new \RuntimeException('Sunucu bulunamadı veya devre dışı.');
        $s['secret']=$this->vault->decrypt((string)$s['secret_enc']);return $s;
    }
    private function adapter(array $s):object
    {
        $config=['base_url'=>$s['base_url'],'username'=>$s['username'],'secret'=>$s['secret'],'auth_mode'=>$s['auth_mode'],'shared_ip'=>$s['shared_ip']];
        return match($s['panel_type']){'cpanel'=>new CpanelWhmAdapter($config),'directadmin'=>new DirectAdminAdapter($config),'plesk'=>new PleskXmlAdapter($config),default=>throw new \RuntimeException('Desteklenmeyen kontrol paneli.')};
    }
    private function log(int $service,int $server,string $action,string $status,string $message):void
    {try{$q=$this->db->prepare("INSERT INTO provisioning_logs(service_id,server_id,action,status,message,created_at) VALUES(:service,:server,:action,:status,:message,:created)");$q->execute([':service'=>$service,':server'=>$server?:null,':action'=>$action,':status'=>$status,':message'=>$message,':created'=>gmdate('Y-m-d H:i:s')]);}catch(Throwable $e){error_log('[HostPilot] Provisioning audit log failed: '.$e->getMessage());}}
    private function allowed():bool
    {
        $u=Auth::user();if(!$u){$this->go('/admin/login');return false;}
        if(!Authorization::allowsCurrentRequest($u,$this->db,$this->basePath)){http_response_code(403);echo 'Bu işlem için yetkiniz yok.';return false;}
        return true;
    }
    private function csrf():bool{return Auth::verifyCsrf($_POST['_token']??null);}
    private function start(string $title,string $active):void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>
        *{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e5edf9;font:14px Inter,system-ui,Arial}
        .shell{display:flex;min-height:100vh}.side{width:230px;flex:0 0 230px;background:#111c2f;padding:25px 16px;border-right:1px solid #26364e}
        .brand{font-weight:900;letter-spacing:2px;font-size:18px;color:#fff;text-decoration:none;display:block;margin-bottom:35px}.brand span{color:#7595ff}
        .side a.item{display:block;padding:12px;color:#aebdd2;text-decoration:none;border-radius:8px;margin:4px 0}.side a.active,.side a.item:hover{background:#20314c;color:#fff}
        .main{flex:1;min-width:0;padding:30px;max-width:1500px}.hero{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}
        .hero small{color:#8fa8cc;letter-spacing:1.5px}.hero h1{font-size:30px;margin:8px 0}.hero p,.panel p{color:#9aacc5;line-height:1.65}
        .pill{border:1px solid #334967;border-radius:30px;padding:9px 13px;color:#cbd8eb}.panel{background:#121e31;border:1px solid #263851;border-radius:14px;padding:22px;margin:16px 0}
        .panel h2{margin:0 0 18px;font-size:17px}.form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
        .form label{display:block;color:#b8c8dd;font-size:12px}.form input,.form select,.mapform input,.mapform select,.provision input,.provision select{display:block;width:100%;margin-top:7px;background:#0b1424;color:#eef4ff;border:1px solid #344760;border-radius:8px;padding:11px}
        .form .full{grid-column:1/-1}.form button,button{background:#5579ef;color:white;border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer}
        .mapform{display:grid;grid-template-columns:1fr 1fr auto;gap:9px;margin-top:12px}.notice{padding:13px 16px;border-radius:9px;margin:14px 0}.ok{background:#123a30;color:#8ce0bb}.err{background:#49222b;color:#ffc0c8}
        .tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #2a3b53;vertical-align:top}
        th{color:#92a8c7;font-size:11px;text-transform:uppercase}td{font-size:12px;color:#d7e2f2}td small{display:block;color:#8296b2;margin-top:5px}
        .provision{display:grid;gap:7px;min-width:190px}.provision input,.provision select{margin:0}.provision button{padding:9px}.error{color:#ff9da8!important}.muted{color:#8192aa}
        @media(max-width:800px){.side{width:68px;flex-basis:68px;padding:18px 7px}.brand{font-size:0}.brand span{font-size:12px}.side a.item{font-size:0;text-align:center;padding:13px 3px}.main{padding:18px 12px}.hero h1{font-size:23px}.form,.mapform{grid-template-columns:1fr}.form .full{grid-column:auto}.hero{align-items:flex-start;flex-direction:column}}
        </style></head><body><div class="shell"><aside class="side">';
        echo '<a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a>';
        echo '<a class="item" href="'.$this->url('/admin').'">▦ Genel Bakış</a>';
        echo '<a class="item '.($active==='servers'?'active':'').'" href="'.$this->url('/admin/servers').'">▣ Sunucular</a>';
        echo '<a class="item '.($active==='services'?'active':'').'" href="'.$this->url('/admin/services').'">▤ Hizmetler</a>';
        echo '<a class="item" href="'.$this->url('/admin/products').'">Ürünler</a>';
        echo '<a class="item" href="'.$this->url('/admin/orders').'">Siparişler</a>';
        echo '<a class="item" href="'.$this->url('/admin/invoices').'">Faturalar</a>';
        echo '<a class="item" href="'.$this->url('/admin/automation').'">Otomasyon</a>';
        echo '</aside><main class="main">';
    }
    private function end():void{echo '</main></div></html>';}
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8');}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $path,string $query=''):never{header('Location: '.$this->url($path).($query!==''?'?'.$query:''));exit;}
}
