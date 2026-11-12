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
                if(!in_array($environment,['sandbox','live'],true)||$userId===''||strlen($userId)>120||strlen($apiKey)>1000)$error='ResellerClub bağlantı bilgilerini kontrol edin.';
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

    private function load():array{$q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='domain_registrar'");$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['setting_key']]=$r['setting_value'];return $out;}
    private function admin():bool{$u=Auth::user();if(!$u){$this->go('/admin/login');return false;}if(!in_array($u['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return false;}return true;}
    private function page(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.nav-item.active{background:#1b2a46;border-radius:8px}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1300px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.topbar h1{font-size:21px}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p{color:#9aabc2;line-height:1.6}.panel label{display:block;margin:14px 0;color:#c4d0e2}.panel input:not([type=checkbox]),.panel select{display:block;width:100%;margin-top:7px;padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.panel button,.button{padding:12px 17px;background:#5278ed;color:white;border:0;border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none}.panel hr{border:0;border-top:1px solid #26354c;margin:28px 0}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0;background:#172f35;color:#a9f0d0}.alert{background:#4b202b;color:#ffc3ca}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.sidebar .brand{font-size:0}.sidebar .brand span{font-size:12px}.nav-item{font-size:0}.main{padding:0 12px 20px}}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$url=rtrim($this->basePath,'/').$p;if($query!=='')$url.='?'.$query;header('Location: '.$url);exit;}
}