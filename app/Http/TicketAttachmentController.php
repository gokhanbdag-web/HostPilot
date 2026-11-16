<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\ClamAvScanner;
use HostPilot\Core\SecurityAudit;
use PDO;
use RuntimeException;
use Throwable;

final class TicketAttachmentController
{
    private const MAX_BYTES=5242880;
    private const MAX_FILES=3;
    private const TYPES=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','text/plain'=>'txt'];

    public function __construct(private readonly PDO $db,private readonly string $basePath,private readonly string $storagePath){}

    public function storeUploads(int $ticketId,int $messageId,int $userId):array
    {
        $f=$_FILES['attachments']??null;
        if(!is_array($f)||!isset($f['name'])||!is_array($f['name']))return [];
        $count=count($f['name']);if($count>self::MAX_FILES)throw new RuntimeException('En fazla 3 dosya ekleyebilirsiniz.');
        $saved=[];$dir=rtrim($this->storagePath,'/\\\\').'/private/ticket-attachments';
        $scannerConfig=$this->scannerConfig();$scanEnabled=$scannerConfig['enabled'];$scanner=new ClamAvScanner();
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Güvenli dosya alanı oluşturulamadı.');
        @chmod($dir,0700);
        try{
            for($i=0;$i<$count;$i++){
                $name=(string)($f['name'][$i]??'');$tmp=(string)($f['tmp_name'][$i]??'');$error=(int)($f['error'][$i]??UPLOAD_ERR_NO_FILE);$size=(int)($f['size'][$i]??0);
                if($error===UPLOAD_ERR_NO_FILE)continue;
                if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Dosya yüklenemedi. PHP yükleme hatası: '.$error);
                if($size<1||$size>self::MAX_BYTES||!is_uploaded_file($tmp))throw new RuntimeException('Dosya boş, geçersiz veya 5 MB sınırını aşıyor.');
                $finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);
                if(!isset(self::TYPES[$mime]))throw new RuntimeException('Yalnızca PDF, JPG, PNG ve TXT dosyaları kabul edilir.');
                if($mime==='text/plain'&&preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', (string)file_get_contents($tmp,false,null,0,4096)))throw new RuntimeException('Metin dosyası geçerli düz metin değil.');
                $scanResult=null;
                if($scanEnabled){
                    $scanResult=$scanner->scanFile($tmp,$scannerConfig['endpoint']);
                    if(($scanResult['status']??'')!=='clean'){
                        $event=($scanResult['status']??'')==='infected'?'attachment.scan_infected':'attachment.scan_unavailable';
                        SecurityAudit::record($this->db,$event,$userId,['action'=>($scanResult['status']??'')==='infected'?'failed':'unavailable']);
                        throw new RuntimeException(($scanResult['status']??'')==='infected'?'Dosyada zararlı içerik tespit edildi; ek kabul edilmedi.':'Virüs taraması yapılamıyor; güvenlik için dosya kabul edilmedi.');
                    }
                }
                $ext=self::TYPES[$mime];$stored=bin2hex(random_bytes(24)).'.'.$ext;$path=$dir.'/'.$stored;
                if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Dosya güvenli alana taşınamadı.');
                @chmod($path,0600);$saved[]=$path;
                $original=trim(str_replace(["\\r","\\n","\\0","/","\\\\"],'',basename($name)));
                if($original===''||strlen($original)>190)$original='ek-'.$i.'.'.$ext;
                $q=$this->db->prepare('INSERT INTO ticket_attachments(ticket_id,message_id,user_id,original_name,stored_name,mime_type,file_size,sha256,created_at) VALUES(:ticket,:message,:user,:original,:stored,:mime,:size,:hash,UTC_TIMESTAMP())');
                $q->execute([':ticket'=>$ticketId,':message'=>$messageId,':user'=>$userId,':original'=>$original,':stored'=>$stored,':mime'=>$mime,':size'=>$size,':hash'=>hash_file('sha256',$path)]);
                $attachmentId=(int)$this->db->lastInsertId();
                SecurityAudit::record($this->db,$scanEnabled?'attachment.scanned_clean':'attachment.uploaded_unscanned',$userId,['resource_id'=>$attachmentId,'action'=>$scanEnabled?'success':'unscanned']);
            }
            return $saved;
        }catch(Throwable $e){foreach($saved as $path)if(is_file($path))@unlink($path);throw $e;}
    }

    public function adminScannerSettings():void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['super_admin','admin'],true)){http_response_code(403);echo 'Yönetici yetkisi gerekir.';return;}
        $config=$this->scannerConfig();$enabled=$config['enabled'];$endpoint=$config['endpoint'];$error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $endpoint=trim((string)($_POST['endpoint']??$endpoint));$enabled=isset($_POST['enabled'])&&$_POST['enabled']==='1';
                if(!ClamAvScanner::isAllowedEndpoint($endpoint))$error='Yalnızca yerel ClamAV unix socket veya localhost TCP adresi kullanılabilir.';
                elseif(($_POST['action']??'')==='test'){
                    $notice=(new ClamAvScanner())->ping($endpoint)?'ClamAV bağlantısı başarılı.':'ClamAV bağlantısı kurulamadı. Ayarları ve clamd hizmetini kontrol edin.';
                    if(!str_contains($notice,'başarılı'))$error=$notice;
                }else{
                    $save=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('security','attachment_scan_enabled',:enabled,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");
                    $save->execute([':enabled'=>$enabled?'1':'0']);
                    $save=$this->db->prepare("INSERT INTO settings(setting_group,setting_key,setting_value,is_secret,updated_at) VALUES('security','attachment_scan_endpoint',:endpoint,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=UTC_TIMESTAMP()");
                    $save->execute([':endpoint'=>$endpoint]);
                    SecurityAudit::record($this->db,'security.attachment_scanner_settings_updated',(int)$actor['id'],['action'=>'updated']);
                    $notice=$enabled?'ClamAV taraması etkinleştirildi. Tarama hizmeti yanıt vermezse ek yüklemeleri güvenlik için reddedilir.':'ClamAV taraması kapatıldı. Dosyalar MIME ve içerik türü kontrolleriyle kabul edilmeye devam eder.';
                }
            }
        }
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ek dosya güvenliği</title><body><main style="max-width:760px;margin:40px auto;padding:20px;font:16px sans-serif"><h1>Destek eki virüs taraması</h1>';
        if($error!=='')echo '<p role="alert">'.self::escape($error).'</p>';
        if($notice!=='')echo '<p role="status">'.self::escape($notice).'</p>';
        echo '<p>ClamAV etkinleştirildiğinde her dosya yüklenmeden önce yerel clamd servisine gönderilir. Servis erişilemiyorsa yükleme reddedilir. Bu ayar varsayılan olarak kapalıdır; paylaşımlı hosting hesabınızda ClamAV socket hizmeti bulunmuyorsa kapalı bırakın.</p>';
        echo '<form method="post" action="'.self::escape($this->basePath.'/admin/security/attachments').'"><input type="hidden" name="_token" value="'.self::escape(Auth::csrfToken()).'"><label><input type="checkbox" name="enabled" value="1"'.($enabled?' checked':'').'> ClamAV taramasını zorunlu etkinleştir</label><p><label>ClamAV endpoint <input name="endpoint" style="width:100%" value="'.self::escape($endpoint).'"></label></p><button name="action" value="save">Kaydet</button> <button name="action" value="test">Bağlantıyı test et</button></form>';
        echo '<p><a href="'.self::escape($this->basePath.'/admin').'">Yönetim paneli</a></p></main></body></html>';
    }

    private function scannerConfig():array
    {
        $values=[];
        $q=$this->db->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='security' AND setting_key IN ('attachment_scan_enabled','attachment_scan_endpoint')");
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$values[(string)$row['setting_key']]=(string)$row['setting_value'];
        $endpoint=$values['attachment_scan_endpoint']??'unix:///var/run/clamav/clamd.ctl';
        if(!ClamAvScanner::isAllowedEndpoint($endpoint))$endpoint='unix:///var/run/clamav/clamd.ctl';
        return ['enabled'=>($values['attachment_scan_enabled']??'0')==='1','endpoint'=>$endpoint];
    }

    private static function escape(string $value):string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    public function forMessage(int $messageId):array
    {
        $q=$this->db->prepare('SELECT id,original_name,mime_type,file_size FROM ticket_attachments WHERE message_id=:id ORDER BY id');$q->execute([':id'=>$messageId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function download(int $attachmentId,bool $admin):void
    {
        $actor=Auth::user();if(!$actor){http_response_code(401);return;}
        if($admin){
            if(!in_array(($actor['role']??''),['super_admin','admin','staff'],true)||!\HostPilot\Core\Authorization::allowsAdminRequest('/admin/tickets/1','GET',$actor,$this->db)){http_response_code(403);return;}
            $q=$this->db->prepare('SELECT a.*,t.department FROM ticket_attachments a JOIN tickets t ON t.id=a.ticket_id WHERE a.id=:id LIMIT 1');$q->execute([':id'=>$attachmentId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){http_response_code(404);return;}
            if(($actor['role']??'')==='staff'){
                $d=$this->db->prepare('SELECT 1 FROM staff_departments WHERE user_id=:u AND department=:d LIMIT 1');$d->execute([':u'=>$actor['id'],':d'=>$row['department']]);if(!$d->fetchColumn()){http_response_code(404);return;}
            }
        }else{
            if(!self::isCustomerRole((string)($actor['role']??''))){http_response_code(403);return;}
            $q=$this->db->prepare('SELECT a.* FROM ticket_attachments a JOIN tickets t ON t.id=a.ticket_id WHERE a.id=:id AND t.user_id=:uid LIMIT 1');$q->execute([':id'=>$attachmentId,':uid'=>$actor['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){http_response_code(404);return;}
        }
        $file=rtrim($this->storagePath,'/\\\\').'/private/ticket-attachments/'.basename((string)$row['stored_name']);
        if(!is_file($file)||!hash_equals((string)$row['sha256'],(string)hash_file('sha256',$file))){http_response_code(404);return;}
        $mime=(string)$row['mime_type'];if(!in_array($mime,array_keys(self::TYPES),true)){http_response_code(404);return;}
        $name=str_replace(["\\r","\\n","\""],'',(string)$row['original_name']);
        header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($file));header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($name));
        header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('Content-Security-Policy: sandbox');
        readfile($file);
    }

    public static function isCustomerRole(string $role):bool{return in_array($role,['customer','client'],true);}
    public static function cleanupStored(array $paths):void{foreach($paths as $path)if(is_string($path)&&is_file($path))@unlink($path);}
    public static function allowedMime(string $mime):bool{return isset(self::TYPES[$mime]);}
    public static function maxBytes():int{return self::MAX_BYTES;}
}
