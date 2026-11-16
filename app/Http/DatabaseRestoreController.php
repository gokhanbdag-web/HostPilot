<?php
declare(strict_types=1);
namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\DatabaseBackup;
use HostPilot\Core\SecurityAudit;
use PDO;
use Throwable;

final class DatabaseRestoreController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath,private readonly string $storageDirectory){}

    public function index():void
    {
        if(!$this->authorize())return;
        $error='';$notice=(string)($_SESSION['hostpilot_restore_notice']??'');
        unset($_SESSION['hostpilot_restore_notice']);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
            else $error=$this->restoreSelected();
        }
        $backups=DatabaseBackup::listBackups($this->storageDirectory);
        $maintenance=$this->maintenanceInfo();
        $retryAllowed=in_array((string)($maintenance['status']??''),['restore_failed','restored'],true);
        $this->page('Veritabanı yedeğini geri yükle');
        echo '<div class="wrap"><header><a href="'.$this->url('/admin').'">HOSTPILOT</a><h1>Veritabanı yedeğini geri yükle</h1><p>Bu işlem mevcut veritabanını seçilen yedeğin içeriğiyle değiştirir.</p></header>';
        if($error!=='')echo '<div class="error" role="alert">'.$this->e($error).'</div>';
        if($notice!=='')echo '<div class="success" role="status">'.$this->e($notice).'</div>';
        if($maintenance!==null){
            echo '<div class="warning"><b>Bakım modu etkin.</b> Durum: '.$this->e((string)($maintenance['status']??'unknown')).'. ';
            if(!empty($maintenance['emergency_backup']))echo 'Acil durum yedeği: <code>'.$this->e((string)$maintenance['emergency_backup']).'</code>. ';
            echo '<a href="'.$this->url('/admin/database-upgrade').'">Veritabanı güncelleme / bakım modunu kontrol et</a></div>';
            if(!$retryAllowed)echo '<p>Başka bir veritabanı işlemi devam ediyor veya güvenli şekilde tamamlanmamış. Bu sayfadan yeni geri yükleme başlatılamaz.</p>';
        }
        echo '<section class="panel"><h2>Mevcut yedekler</h2><p>Geri yükleme öncesinde sistem mevcut veritabanının ayrıca acil durum yedeğini alır. İşlem sırasında bakım modu açık tutulur. Yedek dosyasının adı, SHA-256 özeti ve yönetici parolası tekrar doğrulanmalıdır.</p>';
        if($backups===[])echo '<p>Henüz geri yüklenebilir yedek bulunamadı. Önce veritabanı güncelleme merkezinden veya güncelleme işleminden bir yedek oluşturun.</p>';
        foreach($backups as $backup){
            $valid=(bool)$backup['valid'];
            echo '<article class="backup"><div class="meta"><b>'.$this->e($backup['filename']).'</b><span>'.$this->e(number_format((int)$backup['size']/1048576,2,',','.')).' MB · '.$this->e($backup['created_at']).'</span></div>';
            echo '<p class="hash"><b>SHA-256:</b> <code>'.$this->e($backup['sha256']).'</code></p>';
            if(!$valid){echo '<p class="error">Bu dosya okunabilir veya doğrulanabilir bir gzip yedeği değil; geri yükleme devre dışı.</p>';}
            elseif($maintenance!==null&&!$retryAllowed){echo '<p>Bakım modu nedeniyle işlem başlatılamıyor.</p>';}
            else{
                echo '<form method="post" autocomplete="off"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="restore"><input type="hidden" name="backup_filename" value="'.$this->e($backup['filename']).'"><input type="hidden" name="backup_sha256" value="'.$this->e($backup['sha256']).'">';
                echo '<label>Onay için dosya adını aynen yazın<input name="confirm_filename" required autocomplete="off" placeholder="'.$this->e($backup['filename']).'"></label>';
                echo '<label>SHA-256 özetini aynen yazın<input name="confirm_sha256" required minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off"></label>';
                echo '<label>Super admin parolası<input type="password" name="password" required autocomplete="current-password" maxlength="4096"></label>';
                echo '<button type="submit" onclick="return confirm(\'Seçilen yedek mevcut veritabanının üzerine yazılacak. Devam etmek istiyor musunuz?\')">Yedeği geri yükle</button></form>';
            }
            echo '</article>';
        }
        echo '</section><p class="foot">Geri yükleme tamamlandığında bakım modu otomatik kapatılmaz. Önce <a href="'.$this->url('/admin/database-upgrade').'">veritabanı güncelleme merkezinde</a> bekleyen migration olup olmadığını kontrol edin; gerekirse güncelleyin ve yalnızca veritabanı güncelse bakım modunu kapatın.</p></div>';
        $this->endPage();
    }

    private function restoreSelected():string
    {
        $actor=Auth::user();
        if(!$actor||($actor['role']??'')!=='super_admin')return 'Geri yükleme yalnızca super admin tarafından yapılabilir.';
        if((string)($_POST['action']??'')!=='restore')return 'Geçersiz işlem.';
        $filename=trim((string)($_POST['backup_filename']??''));$hash=strtolower(trim((string)($_POST['backup_sha256']??'')));
        if($filename===''||!hash_equals($filename,(string)($_POST['confirm_filename']??''))||!hash_equals($hash,strtolower(trim((string)($_POST['confirm_sha256']??'')))))return 'Dosya adı veya SHA-256 onayı eşleşmiyor.';
        $backups=DatabaseBackup::listBackups($this->storageDirectory);$selected=null;
        foreach($backups as $backup)if($backup['filename']===$filename&&$backup['valid']&&hash_equals($backup['sha256'],$hash)){$selected=$backup;break;}
        if($selected===null)return 'Seçilen yedek bulunamadı veya bütünlük kontrolünden geçemedi.';
        $verified=Auth::verifyCredentials($this->db,(string)($actor['email']??''),(string)($_POST['password']??''));
        if(!is_array($verified)||(int)($verified['id']??0)!==(int)$actor['id']||($verified['role']??'')!=='super_admin')return 'Super admin parolası doğrulanamadı.';

        $lockPath=$this->storageDirectory.DIRECTORY_SEPARATOR.'maintenance.lock';
        $existing=$this->maintenanceInfo();
        $retryAllowed=in_array((string)($existing['status']??''),['restore_failed','restored'],true);
        if($existing!==null&&!$retryAllowed)return 'Başka bir veritabanı işlemi nedeniyle bakım modu etkin. Önce o işlemi güvenli şekilde tamamlayın.';
        $payload=['started_at'=>gmdate(DATE_ATOM),'started_by'=>(int)$actor['id'],'status'=>'restore_preparing','target_backup'=>$filename,'emergency_backup'=>null];
        if(!$this->writeMaintenance($payload))return 'Bakım modu güvenli şekilde etkinleştirilemedi; geri yükleme başlatılmadı.';
        $emergency=null;
        try{
            $emergency=DatabaseBackup::create($this->db,$this->storageDirectory);
            $payload['status']='restore_running';$payload['emergency_backup']=$emergency['filename'];$payload['emergency_sha256']=$emergency['sha256'];
            if(!$this->writeMaintenance($payload))throw new \RuntimeException('Unable to persist emergency backup information.');
            $result=DatabaseBackup::restore($this->db,$this->storageDirectory,$filename,$hash);
            $payload['status']='restored';$payload['restored_at']=gmdate(DATE_ATOM);$payload['statements']=$result['statements'];
            if(!$this->writeMaintenance($payload))throw new \RuntimeException('Restore completed but maintenance status could not be updated.');
            SecurityAudit::record($this->db,'database.restore_completed',(int)$actor['id'],['action'=>'completed']);
            $this->log('RESTORE_COMPLETED','actor='.(int)$actor['id'].' target='.$filename.' emergency='.$emergency['filename']);
            $_SESSION['hostpilot_restore_notice']='Yedek geri yüklendi. Acil durum yedeği: '.$emergency['filename'].'. Güvenlik için bakım modu açık bırakıldı; veritabanı güncelleme merkezinde migration durumunu kontrol edin.';
            header('Location: '.$this->url('/admin/database-restore'));exit;
        }catch(Throwable $exception){
            $payload['status']=$emergency===null?'restore_preparation_failed':'restore_failed';$payload['failed_at']=gmdate(DATE_ATOM);
            if($emergency!==null){$payload['emergency_backup']=$emergency['filename'];$payload['emergency_sha256']=$emergency['sha256'];}
            $this->writeMaintenance($payload);
            SecurityAudit::record($this->db,'database.restore_failed',(int)$actor['id'],['action'=>'failed']);
            $this->log('RESTORE_FAILED','actor='.(int)$actor['id'].' target='.$filename.' error='.$exception->getMessage());
            if($emergency===null){@unlink($lockPath);return 'Acil durum yedeği alınamadığı için geri yükleme başlatılmadı. Sunucu günlüklerini kontrol edin.';}
            return 'Geri yükleme tamamlanamadı. Güvenlik için bakım modu açık bırakıldı. Acil durum yedeği korunuyor: '.$emergency['filename'].'. Sunucu günlüklerini inceleyin; gerekirse acil durum yedeğini seçerek geri yükleyin.';
        }
    }

    private function authorize():bool
    {
        if(!Auth::check()){header('Location: '.$this->url('/admin/login'));return false;}
        if((Auth::user()['role']??'')!=='super_admin'){http_response_code(403);echo 'Bu işlem yalnızca super admin tarafından yapılabilir.';return false;}
        return true;
    }

    private function maintenanceInfo():?array
    {
        $path=$this->storageDirectory.DIRECTORY_SEPARATOR.'maintenance.lock';
        if(!is_file($path))return null;
        $data=json_decode((string)@file_get_contents($path),true);
        return is_array($data)?$data:['status'=>'unknown'];
    }

    private function writeMaintenance(array $payload):bool
    {
        $path=$this->storageDirectory.DIRECTORY_SEPARATOR.'maintenance.lock';$tmp=$path.'.restore.tmp';
        $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($json)||file_put_contents($tmp,$json,LOCK_EX)===false)return false;
        @chmod($tmp,0640);
        if(!rename($tmp,$path)){@unlink($tmp);return false;}
        @chmod($path,0640);return true;
    }

    private function log(string $event,string $message):void
    {
        $directory=$this->storageDirectory.DIRECTORY_SEPARATOR.'logs';
        if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))return;
        $line='['.gmdate('Y-m-d H:i:s').' UTC] '.$event.' '.str_replace(["\r","\n"],' ',$message).PHP_EOL;
        @file_put_contents($directory.DIRECTORY_SEPARATOR.'database-restore.log',$line,FILE_APPEND|LOCK_EX);
        @chmod($directory.DIRECTORY_SEPARATOR.'database-restore.log',0640);
    }

    private function page(string $title):void
    {
        echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).'</title><style>
        *{box-sizing:border-box}body{margin:0;background:#0a1020;color:#e8edf7;font:14px Inter,system-ui,sans-serif}.wrap{max-width:1000px;margin:0 auto;padding:32px 18px}header{border-bottom:1px solid #293a54;padding-bottom:20px;margin-bottom:22px}header a{color:#91aaff;text-decoration:none;font-weight:800;letter-spacing:.12em}h1{font-size:27px;margin:12px 0}p{color:#aebbd0;line-height:1.7}.panel,.backup{background:#111c2f;border:1px solid #293a54;border-radius:12px;padding:20px;margin:14px 0}.meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}.meta span,.hash{color:#9fb0c8}.hash code,code{overflow-wrap:anywhere;color:#b7c8ff}.backup form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}.backup label{display:grid;gap:6px;color:#cbd5e1}.backup label:nth-of-type(3){grid-column:1/-1}.backup input{width:100%;padding:11px;border:1px solid #40516d;border-radius:7px;background:#0b1425;color:#fff}.backup button{grid-column:1/-1;border:0;border-radius:8px;background:#4169e1;color:white;padding:13px;font-weight:750;cursor:pointer}.success,.error,.warning{padding:14px;border-radius:9px;margin:12px 0;line-height:1.6}.success{background:#123b32;color:#b7f7df}.error{background:#481f2b;color:#ffd0da}.warning{background:#463719;color:#ffe6a6}.foot{font-size:12px;margin-top:20px}@media(max-width:650px){.backup form{grid-template-columns:1fr}.backup label:nth-of-type(3){grid-column:auto}.backup button{grid-column:auto}.meta{display:block}}
        </style></head><body>';
    }

    private function endPage():void{echo '</body></html>';}
    private function url(string $path):string{return htmlspecialchars(rtrim($this->basePath,'/').$path,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
