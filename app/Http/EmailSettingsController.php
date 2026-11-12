<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\EmailService;
use HostPilot\Core\SecretVault;
use PDO;
use Throwable;

final class EmailSettingsController
{
    private SecretVault $vault;
    public function __construct(private readonly PDO $db,private readonly string $basePath,string $appKey)
    {
        $this->vault=new SecretVault($appKey);
    }
    public function index():void
    {
        $user=Auth::user();
        if(!$user){$this->go('/admin/login');return;}
        if(!in_array($user['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $action=(string)($_POST['action']??'save');
                $host=trim((string)($_POST['host']??''));$port=(int)($_POST['port']??587);
                $from=trim((string)($_POST['from_email']??''));$fromName=trim((string)($_POST['from_name']??'HostPilot'));
                $username=trim((string)($_POST['username']??''));$password=(string)($_POST['password']??'');
                $encryption=(string)($_POST['encryption']??'tls');$enabled=isset($_POST['enabled'])?'1':'0';
                if($host===''||strlen($host)>253||preg_match('/[\\r\\n\\s]/',$host)||$port<1||$port>65535||!filter_var($from,FILTER_VALIDATE_EMAIL)||$fromName===''||strlen($fromName)>100||!in_array($encryption,['tls','ssl','none'],true)||strlen($username)>190||strlen($password)>1000)$error='SMTP ayarlarını kontrol edin.';
                else try{
                    $existing=$this->load();
                    $secret=$password!==''?$this->vault->encrypt($password):(string)($existing['password_enc']??'');
                    $fields=['enabled'=>$enabled,'host'=>$host,'port'=>(string)$port,'encryption'=>$encryption,'username'=>$username,'password_enc'=>$secret,'from_email'=>$from,'from_name'=>$fromName];
                    foreach($fields as $key=>$value){
                        $q=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('email',:key,:value,:secret,:updated) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=VALUES(is_secret),updated_at=VALUES(updated_at)");
                        $q->execute([':key'=>$key,':value'=>$value,':secret'=>$key==='password_enc'?1:0,':updated'=>gmdate('Y-m-d H:i:s')]);
                    }
                    if($action==='test'){
                        $to=trim((string)($_POST['test_email']??''));
                        if(!filter_var($to,FILTER_VALIDATE_EMAIL))$error='Test e-posta adresi geçerli değil.';
                        else{$sent=(new EmailService($this->db,$this->appKey))->send($to,'HostPilot SMTP test e-postası','SMTP bağlantı ve gönderim testi başarıyla tamamlandı.');if($sent)$notice='Test e-postası gönderildi.';else$error='Test e-postası gönderilemedi. SMTP bilgilerini ve sunucu hata kayıtlarını kontrol edin.';}
                    }else $notice='E-posta ayarları kaydedildi.';
                }catch(Throwable $e){$error='Ayarlar kaydedilemedi: '.$e->getMessage();}
            }
            if($error!=='')$this->go('/admin/settings','error='.rawurlencode($error));
            $this->go('/admin/settings','notice='.rawurlencode($notice));
        }
        $cfg=$this->load();
        $this->page('E-posta ayarları');
        echo '<div class="layout"><aside class="sidebar"><a class="brand" href="'.$this->url('/admin').'">HOST<span>PILOT</span></a><a class="nav-item" href="'.$this->url('/admin').'">▦ Genel Bakış</a><a class="nav-item active" href="'.$this->url('/admin/settings').'">⚙ E-posta ayarları</a><a class="nav-item" href="'.$this->url('/admin/automation').'">◷ Otomasyon</a></aside><main class="main"><header class="topbar"><div><small>YÖNETİM / AYARLAR</small><h1>E-posta ve SMTP</h1></div><a class="logout" href="'.$this->url('/admin').'">Kontrol merkezi</a></header><article class="panel"><p>HostPilot bildirimleri için SMTP sunucunuzu tanımlayın. Parola şifrelenerek saklanır; kayıtlı parola hiçbir zaman ekranda gösterilmez.</p>';
        if($notice!=='')echo '<div class="notice">'.$this->e($notice).'</div>';
        if($error!=='')echo '<div class="alert">'.$this->e($error).'</div>';
        echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="save"><label><input type="checkbox" name="enabled" value="1" '.(($cfg['enabled']??'0')==='1'?'checked':'').'> E-posta bildirimlerini etkinleştir</label><div class="fields"><label>SMTP sunucusu<input name="host" value="'.$this->e((string)($cfg['host']??'')).'" placeholder="mail.ornek.com" required></label><label>Port<input type="number" name="port" min="1" max="65535" value="'.$this->e((string)($cfg['port']??'587')).'" required></label><label>Şifreleme<select name="encryption"><option value="tls" '.(($cfg['encryption']??'tls')==='tls'?'selected':'').'>STARTTLS</option><option value="ssl" '.(($cfg['encryption']??'')==='ssl'?'selected':'').'>SSL/TLS</option><option value="none" '.(($cfg['encryption']??'')==='none'?'selected':'').'>Şifreleme yok (önerilmez)</option></select></label><label>SMTP kullanıcı adı<input name="username" value="'.$this->e((string)($cfg['username']??'')).'"></label><label>SMTP parolası<input type="password" name="password" autocomplete="new-password" placeholder="'.(!empty($cfg['password_enc'])?'Kayıtlı parola korunuyor; değiştirmek için yazın':'SMTP parolası').'"></label><label>Gönderen e-posta<input type="email" name="from_email" value="'.$this->e((string)($cfg['from_email']??'')).'" required></label><label>Gönderen adı<input name="from_name" maxlength="100" value="'.$this->e((string)($cfg['from_name']??'HostPilot')).'" required></label></div><button>SMTP ayarlarını kaydet</button></form><hr><h3>Bağlantı testi</h3><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="test"><input type="hidden" name="enabled" value="1"><input type="hidden" name="host" value="'.$this->e((string)($cfg['host']??'')).'"><input type="hidden" name="port" value="'.$this->e((string)($cfg['port']??'587')).'"><input type="hidden" name="encryption" value="'.$this->e((string)($cfg['encryption']??'tls')).'"><input type="hidden" name="username" value="'.$this->e((string)($cfg['username']??'')).'"><input type="hidden" name="from_email" value="'.$this->e((string)($cfg['from_email']??'')).'"><input type="hidden" name="from_name" value="'.$this->e((string)($cfg['from_name']??'HostPilot')).'"><label>Test alıcısı<input type="email" name="test_email" placeholder="test@ornek.com" required></label><p>Test gönderimi için önce SMTP ayarlarını kaydedin. Kayıtlı parola korunur.</p><button>Test e-postası gönder</button></form></article></main></div>';
        $this->end();
    }
    private function load():array{$q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='email'");$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['setting_key']]=$r['setting_value'];return $out;}
    private function page(string $title):void{echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}*{box-sizing:border-box}.layout{display:flex;min-height:100vh}.sidebar{width:235px;padding:24px 15px;background:#0e1729;border-right:1px solid #26354c}.brand{display:block;color:white;font-weight:900;text-decoration:none;padding:10px}.brand span{color:#7898ff}.nav-item{display:block;color:#aab7cc;padding:12px;text-decoration:none}.nav-item.active{background:#1b2a46;border-radius:8px}.main{flex:1;min-width:0;padding:0 28px 30px;max-width:1300px;margin:auto}.topbar{height:80px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #26354c}.topbar small{color:#8493aa}.topbar h1{font-size:21px}.panel{margin-top:22px;padding:24px;background:#111c2f;border:1px solid #26354c;border-radius:14px}.panel p{color:#9aabc2;line-height:1.6}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.panel label{display:block;margin:14px 0;color:#c4d0e2}.panel input:not([type=checkbox]),.panel select{display:block;width:100%;margin-top:7px;padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}.panel button{padding:12px 17px;background:#5278ed;color:white;border:0;border-radius:8px;font-weight:700;cursor:pointer}.panel hr{border:0;border-top:1px solid #26354c;margin:28px 0}.notice,.alert{padding:12px;border-radius:8px;margin:12px 0;background:#172f35;color:#a9f0d0}.alert{background:#4b202b;color:#ffc3ca}@media(max-width:700px){.sidebar{width:64px;flex:0 0 64px}.sidebar .brand{font-size:0}.sidebar .brand span{font-size:12px}.nav-item{font-size:0}.main{padding:0 12px 20px}.fields{grid-template-columns:1fr}}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $p,string $query=''):never{$url=rtrim($this->basePath,'/').$p;if($query!=='')$url.='?'.$query;header('Location: '.$url);exit;}
}
