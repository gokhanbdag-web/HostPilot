<?php
declare(strict_types=1);
namespace HostPilot\Http;
use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use Throwable;

final class PrivacyController
{
    private const TABLES=['orders','services','invoices','payment_transactions','tickets','ticket_messages','domain_registrations','domain_renewals'];
    private const BLOCKED='/password|secret|token|hash|credential|api.?key|private.?key|_enc$|payload|raw.?response|session/i';
    public function __construct(private readonly PDO $db,private readonly string $basePath='',private readonly string $storagePath=''){}
    public static function isSafeColumn(string $name):bool{return preg_match(self::BLOCKED,$name)!==1;}
    public static function validReviewStatus(string $s):bool{return in_array($s,['under_review','approved','declined'],true);}
    public static function exportTables():array{return self::TABLES;}

    public function customer():void
    {
        $u=$this->customerUser();if(!$u)return;$error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            elseif(($_POST['confirm']??'')!=='HESABIMI SİL')$error='Onay metni hatalı.';
            else{
                $q=$this->db->prepare("SELECT password_hash FROM users WHERE id=:id AND role='customer' AND status='active'");
                $q->execute([':id'=>$u['id']]);$hash=(string)$q->fetchColumn();
                if(!password_verify((string)($_POST['password']??''),$hash))$error='Parola doğrulanamadı.';
                else try{
                    $this->db->beginTransaction();
                    $lock=$this->db->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');$lock->execute([':id'=>$u['id']]);
                    $pending=$this->db->prepare("SELECT id FROM privacy_requests WHERE user_id=:id AND request_type='erasure' AND status IN ('pending','under_review','approved') LIMIT 1");$pending->execute([':id'=>$u['id']]);
                    if($pending->fetchColumn()){$this->db->rollBack();$notice='Bekleyen bir silme talebiniz zaten bulunuyor.';}
                    else{
                        $ins=$this->db->prepare("INSERT INTO privacy_requests(user_id,request_type,status,request_details,created_at,updated_at) VALUES(:id,'erasure','pending','Customer requested account erasure; administrator review required.',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                        $ins->execute([':id'=>$u['id']]);$rid=(int)$this->db->lastInsertId();$this->db->commit();
                        SecurityAudit::record($this->db,'privacy.erasure_requested',(int)$u['id'],['request_id'=>$rid]);
                        $notice='Talebiniz alındı. Fatura, ödeme, aktif hizmet ve yasal saklama yükümlülükleri incelenecek; hesabınız bu aşamada silinmedi.';
                    }
                }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();error_log('[HostPilot] Privacy request failed: '.$e->getMessage());$error='Talep kaydedilemedi.';}
            }
        }
        $q=$this->db->prepare("SELECT id,status,created_at FROM privacy_requests WHERE user_id=:id AND request_type='erasure' ORDER BY id DESC LIMIT 10");$q->execute([':id'=>$u['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $this->start('Gizlilik ve verilerim');echo '<main><h1>Gizlilik ve kişisel verilerim</h1><p>Hesap profiliniz ve desteklenen müşteri kayıtlarınızın bir kopyasını indirebilir veya hesap silme talebi oluşturabilirsiniz.</p>';
        $this->msg($error,'error');$this->msg($notice,'notice');
        echo '<section><h2>Verilerimi dışa aktar</h2><p>Parola özetleri, oturum belirteçleri, API sırları ve şifrelenmiş erişim bilgileri dışa aktarılmaz.</p><a class="btn" href="'.$this->url('/client/privacy/export').'">JSON verilerimi indir</a></section>';
        echo '<section><h2>Hesabımın silinmesini talep et</h2><p>Talep anında silme yapmaz. Finansal kayıtlar, faturalar, aktif hizmetler ve yasal saklama yükümlülükleri yönetici tarafından değerlendirilir. Gerekli kayıtlar korunurken uygun kişisel veriler anonimleştirilebilir.</p><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><label>Mevcut parolanız<input type="password" name="password" required></label><label>Onay için HESABIMI SİL yazın<input name="confirm" required></label><button class="danger">Silme talebi oluştur</button></form></section><section><h2>Önceki taleplerim</h2>';
        if(!$rows)echo '<p>Henüz talebiniz yok.</p>';foreach($rows as $r)echo '<p>#'.(int)$r['id'].' · '.$this->e((string)$r['status']).' · '.$this->e((string)$r['created_at']).'</p>';
        echo '</section><a href="'.$this->url('/client').'">Müşteri paneline dön</a></main>';$this->end();
    }

    public function export():void
    {
        $u=$this->customerUser();if(!$u)return;
        try{
            $out=['format'=>'hostpilot-customer-export-v1','generated_at'=>gmdate('c'),'account'=>['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'username'=>$u['username'],'created_at'=>$u['created_at']],'records'=>[]];
            foreach(self::TABLES as $table){
                $exists=$this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND TABLE_TYPE='BASE TABLE'");$exists->execute([':t'=>$table]);if((int)$exists->fetchColumn()!==1)continue;
                $cols=$this->db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$safe=[];$owner=false;
                foreach($cols as $c){$n=(string)$c['Field'];if($n==='user_id')$owner=true;if(self::isSafeColumn($n))$safe[]=$n;}
                if(!$owner||!$safe)continue;
                $select=implode(',',array_map(static fn($c)=>'`'.$c.'`',$safe));
                $q=$this->db->prepare('SELECT '.$select.' FROM `'.$table.'` WHERE user_id=:uid ORDER BY id ASC LIMIT 10000');$q->execute([':uid'=>$u['id']]);$out['records'][$table]=$q->fetchAll(PDO::FETCH_ASSOC);
            }
            SecurityAudit::record($this->db,'privacy.exported',(int)$u['id'],['action'=>'export']);
            header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="hostpilot-data-'.(int)$u['id'].'.json"');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
            echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
        }catch(Throwable $e){error_log('[HostPilot] Privacy export failed: '.$e->getMessage());http_response_code(500);echo 'Veri dışa aktarılamadı.';}
    }

    public function admin():void
    {
        $a=Auth::user();if(!$a||!in_array($a['role']??'', ['admin','super_admin'],true)){http_response_code(403);echo 'Yönetici yetkisi gerekir.';return;}$error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            $id=(int)($_POST['request_id']??0);$action=(string)($_POST['action']??'review');$status=(string)($_POST['status']??'');
            if(!Auth::verifyCsrf($_POST['_token']??null)||$id<1)$error='Geçersiz işlem.';
            elseif($action==='complete'){
                try{$this->completeErasure($id,(int)$a['id']);$notice='Hesap anonimleştirildi. Yasal saklama kapsamındaki fatura ve ödeme kayıtları silinmeden korundu.';}
                catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Silme işlemi tamamlanamadı.';error_log('[HostPilot] Privacy erasure completion failed: '.$e->getMessage());}
            }elseif(!self::validReviewStatus($status))$error='Geçersiz inceleme durumu.';
            else{
                $q=$this->db->prepare("UPDATE privacy_requests SET status=:s,reviewed_by=:a,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND request_type='erasure' AND status IN ('pending','under_review')");
                $q->execute([':s'=>$status,':a'=>$a['id'],':id'=>$id]);
                if($q->rowCount()){SecurityAudit::record($this->db,'privacy.request_reviewed',(int)$a['id'],['request_id'=>$id,'action'=>$status]);$notice=$status==='approved'?'Talep onaylandı. Tamamlama öncesi aktif hizmetler ve bekleyen finansal işlemler yeniden kontrol edilecek.':($status==='declined'?'Talep reddedildi.':'Talep incelemeye alındı.');}
                else$error='Talep bulunamadı veya sonuçlandırılmış.';
            }
        }
        $rows=$this->db->query("SELECT r.id,r.status,r.created_at,u.name,u.email FROM privacy_requests r JOIN users u ON u.id=r.user_id WHERE r.request_type='erasure' ORDER BY r.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $this->start('KVKK talepleri');echo '<main><h1>KVKK / gizlilik talepleri</h1><p>Onaylanan talep, yalnızca aktif hizmetler, domain işlemleri, bekleyen tahsilatlar ve ödenmemiş faturalar kapatıldıktan sonra tamamlanabilir. Tamamlama hesap profilini anonimleştirir, destek içeriklerini ve eklerini siler; yasal saklama kapsamındaki finansal kayıtları korur.</p>';$this->msg($error,'error');$this->msg($notice,'notice');
        foreach($rows as $r){
            echo '<section><h2>Talep #'.(int)$r['id'].' · '.$this->e($r['status']).'</h2><p>'.$this->e($r['name']).' · '.$this->e($r['email']).' · '.$this->e($r['created_at']).'</p>';
            if(in_array($r['status'],['pending','under_review'],true))echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="request_id" value="'.(int)$r['id'].'"><button name="status" value="under_review">İncelemeye al</button> <button name="status" value="approved">Silme için onayla</button> <button class="danger" name="status" value="declined">Reddet</button></form>';
            elseif($r['status']==='approved')echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="request_id" value="'.(int)$r['id'].'"><input type="hidden" name="action" value="complete"><button class="danger">Hesabı anonimleştir ve talebi tamamla</button></form>';
            echo '</section>';
        }
        echo '<a href="'.$this->url('/admin').'">Yönetim paneli</a></main>';$this->end();
    }

    private function completeErasure(int $requestId,int $adminId):void
    {
        $this->db->beginTransaction();
        try{
            $request=$this->db->prepare("SELECT id,user_id,status FROM privacy_requests WHERE id=:id AND request_type='erasure' LIMIT 1 FOR UPDATE");
            $request->execute([':id'=>$requestId]);$privacy=$request->fetch(PDO::FETCH_ASSOC);
            if(!$privacy||$privacy['status']!=='approved')throw new RuntimeException('Talep bulunamadı veya önce yönetici tarafından onaylanmalı.');
            $userQuery=$this->db->prepare('SELECT id,email FROM users WHERE id=:id LIMIT 1 FOR UPDATE');
            $userQuery->execute([':id'=>$privacy['user_id']]);$user=$userQuery->fetch(PDO::FETCH_ASSOC);
            if(!$user)throw new RuntimeException('Talep sahibi hesabı bulunamadı.');
            $uid=(int)$user['id'];$oldEmail=(string)$user['email'];

            $blockers=[
                ["SELECT id FROM services WHERE user_id=:uid AND status NOT IN ('terminated','cancelled','deleted','failed') LIMIT 1 FOR UPDATE",'Aktif veya sonlandırılmamış hizmetler var. Önce hizmetleri güvenli biçimde sonlandırın.'],
                ["SELECT pj.id FROM provisioning_jobs pj JOIN services s ON s.id=pj.service_id WHERE s.user_id=:uid AND pj.status IN ('queued','processing','needs_review') LIMIT 1 FOR UPDATE",'Bekleyen sunucu işlemleri var. Önce otomasyon kuyruğunu sonuçlandırın.'],
                ["SELECT id FROM domain_registrations WHERE user_id=:uid AND status NOT IN ('failed','cancelled') LIMIT 1 FOR UPDATE",'Aktif veya sonuçlanmamış domain işlemleri var. Önce registrar işlemlerini sonuçlandırın.'],
                ["SELECT id FROM domain_renewals WHERE user_id=:uid AND status IN ('awaiting_payment','queued','processing','pending_remote','needs_review') LIMIT 1 FOR UPDATE",'Bekleyen domain yenileme işlemleri var. Önce yenileme işlemlerini sonuçlandırın.'],
                ["SELECT id FROM payment_transactions WHERE user_id=:uid AND status IN ('pending','review') LIMIT 1 FOR UPDATE",'Bekleyen veya incelemedeki ödeme işlemleri var. Önce ödeme mutabakatını tamamlayın.'],
                ["SELECT id FROM invoices WHERE user_id=:uid AND status IN ('unpaid','overdue','partially_paid') LIMIT 1 FOR UPDATE",'Ödenmemiş veya kısmen ödenmiş faturalar var. Önce finansal durumu sonuçlandırın.'],
                ["SELECT id FROM orders WHERE user_id=:uid AND status IN ('pending','processing') LIMIT 1 FOR UPDATE",'Bekleyen siparişler var. Önce siparişleri sonuçlandırın.'],
                ["SELECT pc.id FROM service_package_changes pc JOIN services s ON s.id=pc.service_id WHERE s.user_id=:uid AND pc.status IN ('pending_payment','queued','processing','needs_review') LIMIT 1 FOR UPDATE",'Bekleyen paket değişikliği var. Önce paket işlemini sonuçlandırın.'],
            ];
            foreach($blockers as [$sql,$message]){$check=$this->db->prepare($sql);$check->execute([':uid'=>$uid]);if($check->fetchColumn())throw new RuntimeException($message);}

            $attachments=$this->db->prepare('SELECT a.stored_name FROM ticket_attachments a JOIN tickets t ON t.id=a.ticket_id WHERE t.user_id=:uid FOR UPDATE');
            $attachments->execute([':uid'=>$uid]);$storedNames=$attachments->fetchAll(PDO::FETCH_COLUMN);
            $attachmentDir=rtrim($this->storagePath,'/\\\\').DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'ticket-attachments';
            foreach($storedNames as $storedName){
                $storedName=(string)$storedName;
                if(!preg_match('/^[a-f0-9]{48}\\.(pdf|jpg|png|txt)$/',$storedName))throw new RuntimeException('Destek eki güvenli dosya adı doğrulamasından geçemedi; manuel inceleme gerekiyor.');
                $file=$attachmentDir.DIRECTORY_SEPARATOR.$storedName;
                if(is_file($file)&&!@unlink($file))throw new RuntimeException('Destek eki silinemedi. Dosya izinlerini kontrol edip tekrar deneyin.');
            }
            $this->db->prepare("UPDATE ticket_messages SET message='[İçerik silindi - KVKK]',updated_at=COALESCE(updated_at,created_at) WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=:uid)")->execute([':uid'=>$uid]);
            $this->db->prepare("UPDATE tickets SET subject='[İçerik silindi - KVKK]',status='closed',updated_at=UTC_TIMESTAMP() WHERE user_id=:uid")->execute([':uid'=>$uid]);
            $this->db->prepare('DELETE FROM ticket_attachments WHERE ticket_id IN (SELECT id FROM tickets WHERE user_id=:uid)')->execute([':uid'=>$uid]);
            $this->db->prepare("UPDATE services SET domain=NULL,remote_domain=NULL,remote_identifier=NULL,remote_username=NULL,remote_password_enc=NULL,provisioning_error=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=:uid")->execute([':uid'=>$uid]);
            $this->db->prepare("UPDATE provisioning_logs pl JOIN services s ON s.id=pl.service_id SET pl.message='[İçerik silindi - KVKK]' WHERE s.user_id=:uid")->execute([':uid'=>$uid]);
            $this->db->prepare("UPDATE domain_registrations SET domain_name=CONCAT('erased-domain-',id,'.invalid'),registrar_customer_id=NULL,registrar_contact_id=NULL,registrar_payload_enc='',nameservers_enc=NULL,pending_nameservers_enc=NULL,nameserver_error=NULL,action_message=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=:uid")->execute([':uid'=>$uid]);
            $this->db->prepare('UPDATE domain_renewals SET last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE user_id=:uid')->execute([':uid'=>$uid]);
            $this->db->prepare("UPDATE orders SET notes='[İçerik silindi - KVKK]',updated_at=UTC_TIMESTAMP() WHERE user_id=:uid AND notes IS NOT NULL")->execute([':uid'=>$uid]);
            $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id=:uid')->execute([':uid'=>$uid]);
            $this->db->prepare('DELETE FROM email_verification_tokens WHERE user_id=:uid')->execute([':uid'=>$uid]);
            $this->db->prepare('DELETE FROM email_outbox WHERE recipient=:email')->execute([':email'=>$oldEmail]);
            $newEmail='deleted-user-'.$uid.'@privacy.invalid';$newUsername='deleted_user_'.$uid;$newName='Silinmiş müşteri #'.$uid;
            $this->db->prepare("UPDATE users SET name=:name,first_name=NULL,last_name=NULL,email=:email,username=:username,password_hash=:password,status='deleted',email_verified_at=NULL,last_login_at=NULL,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute([':name'=>$newName,':email'=>$newEmail,':username'=>$newUsername,':password'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),':id'=>$uid]);
            $this->db->prepare("UPDATE privacy_requests SET status='completed',request_details='Account anonymized. Statutory financial and payment records retained for applicable retention periods.',reviewed_by=:admin,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'")
                ->execute([':admin'=>$adminId,':id'=>$requestId]);
            $this->db->commit();
            SecurityAudit::record($this->db,'privacy.erasure_completed',$adminId,['request_id'=>$requestId,'action'=>'completed']);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function customerUser():?array{$a=Auth::user();if(!$a){$this->go('/client/login');return null;}if(($a['role']??'')!=='customer'){http_response_code(403);echo 'Müşteri hesabı gerekir.';return null;}$q=$this->db->prepare("SELECT id,name,email,username,created_at FROM users WHERE id=:id AND role='customer' AND status='active'");$q->execute([':id'=>$a['id']]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u){Auth::logout();$this->go('/client/login');return null;}return $u;}
    private function start(string $t):void{echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($t).'</title><style>body{background:#0a1020;color:#e8edf7;font:15px system-ui;margin:0}main{max-width:900px;margin:5vh auto;padding:20px}section{background:#111c2f;border:1px solid #293950;border-radius:12px;padding:22px;margin:16px 0}p{color:#9eacc2;line-height:1.6}label{display:block;margin:12px 0}input{display:block;width:100%;box-sizing:border-box;padding:12px;margin-top:6px;background:#0a1425;color:white;border:1px solid #394b65;border-radius:8px}button,.btn{border:0;border-radius:8px;padding:11px 15px;background:#5278ed;color:white;text-decoration:none;cursor:pointer}.danger{background:#8c3948}.error,.notice{padding:12px;background:#4b202b;border-radius:8px}.notice{background:#173d37}a{color:#a9bfff}</style></head><body>';}
    private function end():void{echo '</body></html>';}
    private function msg(string $s,string $c):void{if($s!=='')echo '<div class="'.$c.'">'.$this->e($s).'</div>';}
    private function url(string $p):string{return htmlspecialchars(rtrim($this->basePath,'/').$p,ENT_QUOTES,'UTF-8');}
    private function go(string $p):never{header('Location: '.$this->url($p));exit;}
    private function e(string $s):string{return \HostPilot\Core\Html::escape($s);}
}
