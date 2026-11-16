<?php
declare(strict_types=1);
namespace HostPilot\\Http;
use HostPilot\\Core\\Auth;
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
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Güvenli dosya alanı oluşturulamadı.');
        @chmod($dir,0700);
        try{
            for($i=0;$i<$count;$i++){
                $name=(string)($f['name'][$i]??'');$tmp=(string)($f['tmp_name'][$i]??'');$error=(int)($f['error'][$i]??UPLOAD_ERR_NO_FILE);$size=(int)($f['size'][$i]??0);
                if($error===UPLOAD_ERR_NO_FILE)continue;
                if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Dosya yüklenemedi. PHP yükleme hatası: '.$error);
                if($size<1||$size>self::MAX_BYTES||!is_uploaded_file($tmp))throw new RuntimeException('Dosya boş, geçersiz veya 5 MB sınırını aşıyor.');
                $finfo=new \\finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);
                if(!isset(self::TYPES[$mime]))throw new RuntimeException('Yalnızca PDF, JPG, PNG ve TXT dosyaları kabul edilir.');
                if($mime==='text/plain'&&preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/', (string)file_get_contents($tmp,false,null,0,4096)))throw new RuntimeException('Metin dosyası geçerli düz metin değil.');
                $ext=self::TYPES[$mime];$stored=bin2hex(random_bytes(24)).'.'.$ext;$path=$dir.'/'.$stored;
                if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Dosya güvenli alana taşınamadı.');
                @chmod($path,0600);$saved[]=$path;
                $original=trim(str_replace(["\\r","\\n","\\0","/","\\\\"],'',basename($name)));
                if($original===''||strlen($original)>190)$original='ek-'.$i.'.'.$ext;
                $q=$this->db->prepare('INSERT INTO ticket_attachments(ticket_id,message_id,user_id,original_name,stored_name,mime_type,file_size,sha256,created_at) VALUES(:ticket,:message,:user,:original,:stored,:mime,:size,:hash,UTC_TIMESTAMP())');
                $q->execute([':ticket'=>$ticketId,':message'=>$messageId,':user'=>$userId,':original'=>$original,':stored'=>$stored,':mime'=>$mime,':size'=>$size,':hash'=>hash_file('sha256',$path)]);
            }
            return $saved;
        }catch(Throwable $e){foreach($saved as $path)if(is_file($path))@unlink($path);throw $e;}
    }

    public function forMessage(int $messageId):array
    {
        $q=$this->db->prepare('SELECT id,original_name,mime_type,file_size FROM ticket_attachments WHERE message_id=:id ORDER BY id');$q->execute([':id'=>$messageId]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function download(int $attachmentId,bool $admin):void
    {
        $actor=Auth::user();if(!$actor){http_response_code(401);return;}
        if($admin){
            if(!in_array(($actor['role']??''),['super_admin','admin','staff'],true)||!\\HostPilot\\Core\\Authorization::allowsAdminRequest('/admin/tickets/1','GET',$actor,$this->db)){http_response_code(403);return;}
            $q=$this->db->prepare('SELECT a.*,t.department FROM ticket_attachments a JOIN tickets t ON t.id=a.ticket_id WHERE a.id=:id LIMIT 1');$q->execute([':id'=>$attachmentId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row){http_response_code(404);return;}
            if(($actor['role']??'')==='staff'){
                $d=$this->db->prepare('SELECT 1 FROM staff_departments WHERE user_id=:u AND department=:d LIMIT 1');$d->execute([':u'=>$actor['id'],':d'=>$row['department']]);if(!$d->fetchColumn()){http_response_code(404);return;}
            }
        }else{
            if(($actor['role']??'')!=='customer'){http_response_code(403);return;}
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

    public static function cleanupStored(array $paths):void{foreach($paths as $path)if(is_string($path)&&is_file($path))@unlink($path);}
    public static function allowedMime(string $mime):bool{return isset(self::TYPES[$mime]);}
    public static function maxBytes():int{return self::MAX_BYTES;}
}
