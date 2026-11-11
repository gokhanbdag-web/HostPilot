<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
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
        echo '<section class="panel"><h2>Yeni sunucu bağlantısı</h2><form method="post" action="'.$this->url('/admin/servers/save').'" class="form"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Sunucu adı<input name="name" required maxlength="120" placeholder="Ana Hosting Sunucusu"></label><label>Kontrol paneli<select name="panel_type"><option value="cpanel">cPanel / WHM</option><option value="directadmin">DirectAdmin</option><option value="plesk">Plesk XML API</option></select></label><label>HTTPS API adresi<input name="base_url" type="url" required placeholder="https://server.example.com:2087"></label><label>API kullanıcı adı<input name="username" required autocomplete="off"></label><label>Kimlik doğrulama yöntemi<select name="auth_mode"><option value="password">Kullanıcı adı + API token/parola</option><option value="key">Plesk KEY token</option></select></label><label>API token / parola<input name="secret" type="password" required autocomplete="new-password"></label><label>Paylaşımlı IP (Plesk için gerekli)<input name="shared_ip" placeholder="203.0.113.10"></label><div class="full"><button>Sunucuyu kaydet</button><small>Bağlantılar yalnızca HTTPS üzerinden yapılır ve TLS sertifika kontrolü kapatılamaz.</small></div></form></section>';
        foreach($servers as $server){
            $maps=$this->db->prepare("SELECT m.product_id,m.remote_package,p.name FROM server_product_map m JOIN products p ON p.id=m.product_id WHERE m.server_id=:id ORDER BY p.name");$maps->execute([':id'=>$server['id']]);
            echo '<section class="panel server"><div class="serverhead"><div><h2>'.$this->e($server['name']).'</h2><p>'.$this->e(strtoupper($server['panel_type'])).' · '.$this->e($server['base_url']).'</p><small>Son bağlantı: '.$this->e((string)($server['last_test_at']??'Henüz test edilmedi')).' · '.$this->e((string)($server['last_test_message']??'')).'</small></div><span class="pill '.($server['enabled']?'green':'gray').'">'.($server['enabled']?'Etkin':'Pasif').'</span></div><div class="actions"><form method="post" action="'.$this->url('/admin/servers/test').'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="server_id" value="'.(int)$server['id'].'"><button class="secondary">Bağlantıyı test et</button></form></div><h3>Ürün / uzak paket eşleştirmeleri</h3>';
            foreach($maps->fetchAll() as $map)echo '<div class="maprow"><span>'.$this->e($map['name']).'</span><code>'.$this->e($map['remote_package']).'</code></div>';
            echo '<form method="post" action="'.$this->url('/admin/servers/map').'" class="mapform"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="server_id" value="'.(int)$server['id'].'"><select name="product_id" required><option value="">Ürün seçin</option>';
            foreach($products as $product)echo '<option value="'.(int)$product['id'].'">'.$this->e($product['name']).'</option>';
            echo '</select><input name="remote_package" required placeholder="Kontrol panelindeki paket adı"><button class="secondary">Eşleştir</button></form></section>';
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
            $name=trim((string)($_POST['name']??''));$panel=(string)($_POST['panel_type']??'');$base=HostingTransport::validateBaseUrl((string)($_POST['base_url']??''));$user=trim((string)($_POST['username']??''));$secret=(string)($_POST['secret']??'');$mode=(string)($_POST['auth_mode']??'password');$ip=trim((string)($_POST['shared_ip']??''));
            if($name===''||!in_array($panel,['cpanel','directadmin','plesk'],true)||$user===''||$secret==='')throw new \RuntimeException('Zorunlu sunucu alanlarını kontrol edin.');
            if($panel==='plesk'&&$mode==='key'&&$secret==='')throw new \RuntimeException('Plesk KEY token zorunludur.');
            if($panel!=='plesk')$mode='password';
            if($ip!==''&&!filter_var($ip,FILTER_VALIDATE_IP))throw new \RuntimeException('Paylaşımlı IP adresi geçersiz.');
            $q=$this->db->prepare("INSERT INTO servers(name,panel_type,base_url,username,secret_enc,auth_mode,shared_ip,enabled,created_at,updated_at) VALUES(:name,:panel,:base,:username,:secret,:mode,:ip,1,:created,:updated)");
            $now=gmdate('Y-m-d H:i:s');$q->execute([':name'=>$name,':panel'=>$panel,':base'=>$base,':username'=>$user,':secret'=>$this->vault->encrypt($secret),':mode'=>$mode,':ip'=>$ip!==''?$ip:null,':created'=>$now,':updated'=>$now]);
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
            $this->go('/admin/servers','notice='.$message);
        }catch(Throwable $e){
            $q=$this->db->prepare("UPDATE servers SET last_test_at=:at,last_test_status='failed',last_test_message=:message,updated_at=:at2 WHERE id=:id");
            $q->execute([':at'=>gmdate('Y-m-d H:i:s'),':message'=>substr($e->getMessage(),0,500),':at2'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->go('/admin/servers','error='.$e->getMessage());
        }
    }

    public function saveMapping():void
    {
        if(!$this->allowed())return;
        if(!$this->csrf())$this->go('/admin/servers','error=Güvenlik doğrulaması başarısız.');
        $server=(int)($_POST['server_id']??0);$product=(int)($_POST['product_id']??0);$package=trim((string)($_POST['remote_package']??''));
        if($server<1||$product<1||$package==='')$this->go('/admin/servers','error=Ürün ve uzak paket adı zorunludur.');
        $q=$this->db->prepare("INSERT INTO server_product_map(server_id,product_id,remote_package,created_at,updated_at) VALUES(:server,:product,:package,:created,:updated) ON DUPLICATE KEY UPDATE remote_package=VALUES(remote_package),updated_at=VALUES(updated_at)");
        $now=gmdate('Y-m-d H:i:s');$q->execute([':server'=>$server,':product'=>$product,':package'=>$package,':created'=>$now,':updated'=>$now]);
        $this->go('/admin/servers','notice=Ürün ve sunucu paketi eşleştirildi.');
    }

    public function services():void
    {
        if(!$this->allowed())return;
        $rows=$this->db->query("SELECT s.*,u.name AS customer_name,u.email,p.name AS product_name,sv.name AS server_name FROM services s JOIN users u ON u.id=s.user_id JOIN products p ON p.id=s.product_id LEFT JOIN servers sv ON sv.id=s.server_id ORDER BY s.id DESC LIMIT 300")->fetchAll();
        $servers=$this->db->query("SELECT id,name FROM servers WHERE enabled=1 ORDER BY name")->fetchAll();
        $this->start('Hizmetler','services');
        echo '<div class="hero"><div><small>OPERASYON / HOSTING HİZMETLERİ</small><h1>Hizmet yönetimi</h1><p>Ödenmiş siparişlerden oluşan bekleyen hizmetleri sunuculara atayın ve hesapları otomatik oluşturun.</p></div><span class="pill">'.count($rows).' hizmet</span></div><section class="panel"><div class="tablewrap"><table><thead><tr><th>Müşteri / Ürün</th><th>Domain</th><th>Durum</th><th>Sunucu</th><th>İşlem</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td><b>'.$this->e($row['customer_name']).'</b><small>'.$this->e($row['email']).'</small><small>'.$this->e($row['product_name']).'</small></td><td>'.$this->e((string)($row['domain']??'—')).'</td><td><b>'.$this->e((string)$row['status']).'</b><small>'.$this->e((string)($row['provisioning_status']??'not_started')).'</small>'.(!empty($row['provisioning_error'])?'<small class="error">'.$this->e($row['provisioning_error']).'</small>':'').'</td><td>'.$this->e((string)($row['server_name']??'Atanmadı')).'</td><td>';
            if(empty($row['provisioned_at'])&&$row['status']==='pending'){
                echo '<form method="post" action="'.$this->url('/admin/services/provision').'" class="provision"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="service_id" value="'.(int)$row['id'].'"><select name="server_id" required><option value="">Sunucu seçin</option>';
                foreach($servers as $sv)echo '<option value="'.(int)$sv['id'].'">'. $this->e($sv['name']).'</option>';
                echo '</select><input name="domain" required placeholder="example.com" value="'.$this->e((string)($row['domain']??'')).'"><button>Hesabı oluştur</button></form>';
            }else echo '<span class="muted">İşlem tamamlandı</span>';
            echo '</td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="5">Henüz hizmet bulunmuyor.</td></tr>';
        echo '</tbody></table></div></section><section class="panel"><h2>Otomatik aktivasyon güvenliği</h2><p>Bu ilk sürümde hizmet hesabı, yönetici tarafından sunucu ve domain doğrulandıktan sonra oluşturulur. Ödeme callback’i tek başına kontrol panelinde hesap açmaz. Ürün–uzak paket eşleştirmesi zorunludur.</p></section>';
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
            $this->go('/admin/services','notice=Hosting hesabı başarıyla oluşturuldu.');
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->db->prepare("UPDATE services SET provisioning_status='failed',provisioning_error=:error,updated_at=:updated WHERE id=:id")->execute([':error'=>substr($e->getMessage(),0,1000),':updated'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->log($id,$serverId,'create','failed',substr($e->getMessage(),0,1000));
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
    {$u=Auth::user();if(!$u){$this->go('/admin/login');return false;}if(!in_array($u['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Bu işlem için yönetici yetkisi gerekir.';return false;}return true;}
    private function csrf():bool{return Auth::verifyCsrf($_POST['_token']??null);}
    private function start(string $title,string $active):void
    {echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e5edf9;font:14px Inter,system-ui,Arial}.shell{display:flex;min-height:100vh}.side{width:230px;background:#111c2f;padding:25px 16px;border-right:1px solid #26364e}.brand{font-weight:900;letter-spacing:2px;font-size:18px;color:#fff;text-decoration:none;display:block;margin-bottom:35px}.brand span{color:#7595ff}.side a.item{display:block;padding:12px;color:#aebdd2;text-decoration:none;border-radius:8px;margin:4px 0}.side a.active,.side a.item:hover{background:#20314c;color:#fff}.main{flex:1;min-width:0;padding:30px;max-width:1500px}.hero{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}.hero small{color:#8fa8cc;letter-spacing:1.5px}.hero h1{font-size:30px;margin:8px 0}.hero p,.panel p{color:#9aacc5;line-height:1.65}.pill{border:1px solid #334967;border-radius:30px;padding:9px 13px;color:#cbd8eb}.green{color:#7fe0b0}.gray{color:#aab5c5}.panel{background:#121e31;border:1px solid #263851;border-radius:14px;padding:22px;margin:16px 0}.panel h2{margin:0 0 18px;font-size:17px}.panel h3{font-size:14px;margin-top:25px}.form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.form label{display:block;color:#b8c8dd;font-size:12px}.form input,.form select,.mapform input,.mapform select,.provision input,.provision select{display:block;width:100%;margin-top:7px;background:#0b1424;color:#eef4ff;border:1px solid #344760;border-radius:8px;padding:11px}.form .full{grid-column:1/-1}.form button,button{background:#5579ef;color:white;border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer}.form small{display:block;color:#8296b2;margin-top:10px}.secondary{background:#263954}.serverhead{display:flex;justify-content:space-between;gap:18px}.serverhead h2{margin:0}.serverhead p,.serverhead small{color:#8fa2bc;display:block;margin:7px 0}.actions{margin:15px 0}.maprow{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #293a53;color:#b9c9df}.maprow code{color:#a6baff}.mapform{display:grid;grid-template-columns:1fr 1fr auto;gap:9px;margin-top:12px}.notice{padding:13px 16px;border-radius:9px;margin:14px 0}.ok{background:#123a30;color:#8ce0bb}.err{background:#49222b;color:#ffc0c8}.empty{color:#91a3bc}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:850px}th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #2a3b53;vertical-align:top}th{color:#92a8c7;font-size:11px;text-transform:uppercase}td{font-size:12px;color:#d7e2f2}td small{display:block;color:#8296b2;margin-top:5px}.provision{display:grid;gap:7px;min-width:190px}.provision input,.provision select{margin:0}.provision button{padding:9px}.error{color:#ff9da8!important}.muted{color:#8192aa}@media(max-width:800px){.side{width:68px;padding:18px 7px}.brand{font-size:0}.brand span{font-size:12px}.side a.item{font-size:0;text-align:center;padding:13px 3px}.main{padding:18px 12px}.hero h1{font-size:23px}.form{grid-template-columns:1fr}.form .full{grid-column:auto}.mapform{grid-template-columns:1fr}.serverhead{display:block}.hero{align-items:flex-start;flex-direction:column}}</style><div class="shell"><aside class="side"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="item '.($active==='servers'?'active':'').'" href="'.$this->url('/admin/servers').'">▣ Sunucular</a><a class="item '.($active==='services'?'active':'').'" href="'.$this->url('/admin/services').'">▤ Hizmetler</a><a class="item" href="'.$this->url('/admin/products').'">Ürünler</a><a class="item" href="'.$this->url('/admin/orders').'">Siparişler</a><a class="item" href="'.$this->url('/admin/invoices').'">Faturalar</a></aside><main class="main">');}
    private function end():void{echo '</main></div></html>';}
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES,'UTF-8');}
    private function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $path,string $query=''):never{header('Location: '.$this->url($path).($query!==''?'?'.$query:''));exit;}
}
