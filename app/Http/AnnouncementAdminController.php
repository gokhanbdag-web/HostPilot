<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use PDOException;
use Throwable;

final class AnnouncementAdminController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath=''){}

    public function index(?int $announcementId=null):void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';return;}
        $item=['id'=>0,'title'=>'','body'=>'','severity'=>'info','status'=>'draft','starts_at'=>'','ends_at'=>'','published_at'=>null];
        if($announcementId!==null&&$announcementId>0){
            $q=$this->db->prepare('SELECT id,title,body,severity,status,starts_at,ends_at,published_at FROM cms_announcements WHERE id=:id LIMIT 1');$q->execute([':id'=>$announcementId]);$found=$q->fetch(PDO::FETCH_ASSOC);
            if(!$found){http_response_code(404);echo 'Duyuru bulunamadı.';return;}
            $item=$found;$item['starts_at']=$this->toInputDate($item['starts_at']);$item['ends_at']=$this->toInputDate($item['ends_at']);
        }
        $error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                try{
                    $action=(string)($_POST['action']??'save');
                    if($action==='delete'){
                        if(!$announcementId||(string)($_POST['confirm_delete']??'')!=='1')throw new \RuntimeException('Silme işlemini onaylamanız gerekiyor.');
                        $this->db->beginTransaction();$q=$this->db->prepare('DELETE FROM cms_announcements WHERE id=:id');$q->execute([':id'=>$announcementId]);
                        if($q->rowCount()!==1)throw new \RuntimeException('Duyuru bulunamadı.');
                        SecurityAudit::record($this->db,'announcement.deleted',(int)$actor['id'],['resource_id'=>$announcementId]);$this->db->commit();$this->redirect('/admin/announcements?deleted=1');
                    }
                    if($action!=='save')throw new \RuntimeException('Geçersiz duyuru işlemi.');
                    $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$severity=(string)($_POST['severity']??'info');$status=(string)($_POST['status']??'draft');
                    $start=$this->parseDate((string)($_POST['starts_at']??''));$end=$this->parseDate((string)($_POST['ends_at']??''));
                    if($title===''||$this->length($title)>190)throw new \RuntimeException('Duyuru başlığı 1–190 karakter olmalıdır.');
                    if($body===''||$this->length($body)>4000)throw new \RuntimeException('Duyuru metni 1–4.000 karakter olmalıdır.');
                    if(!in_array($severity,['info','success','warning','critical'],true))throw new \RuntimeException('Duyuru türü geçersiz.');
                    if(!in_array($status,['draft','published'],true))throw new \RuntimeException('Yayın durumu geçersiz.');
                    if($start!==null&&$end!==null&&$start>=$end)throw new \RuntimeException('Bitiş tarihi başlangıç tarihinden sonra olmalıdır.');
                    $this->db->beginTransaction();
                    if(!$announcementId){
                        $q=$this->db->prepare('INSERT INTO cms_announcements(title,body,severity,status,starts_at,ends_at,published_at,created_by,created_at,updated_at) VALUES(:title,:body,:severity,:status,:starts,:ends,:published,:creator,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                        $q->execute([':title'=>$title,':body'=>$body,':severity'=>$severity,':status'=>$status,':starts'=>$start,':ends'=>$end,':published'=>$status==='published'?gmdate('Y-m-d H:i:s'):null,':creator'=>$actor['id']]);$savedId=(int)$this->db->lastInsertId();$event='announcement.created';
                    }else{
                        $published=$status==='published'?((string)($item['published_at']??'')?:gmdate('Y-m-d H:i:s')):null;
                        $q=$this->db->prepare('UPDATE cms_announcements SET title=:title,body=:body,severity=:severity,status=:status,starts_at=:starts,ends_at=:ends,published_at=:published,updated_at=UTC_TIMESTAMP() WHERE id=:id');
                        $q->execute([':title'=>$title,':body'=>$body,':severity'=>$severity,':status'=>$status,':starts'=>$start,':ends'=>$end,':published'=>$published,':id'=>$announcementId]);$savedId=$announcementId;$event='announcement.updated';
                    }
                    SecurityAudit::record($this->db,$event,(int)$actor['id'],['resource_id'=>$savedId,'status'=>$status]);$this->db->commit();$this->redirect('/admin/announcements/'.$savedId.'?saved=1');
                }catch(Throwable $e){
                    if($this->db->inTransaction())$this->db->rollBack();$error=$e instanceof PDOException?'Duyuru kaydedilemedi.':$e->getMessage();
                    $item=array_merge($item,['title'=>(string)($_POST['title']??$item['title']),'body'=>(string)($_POST['body']??$item['body']),'severity'=>(string)($_POST['severity']??$item['severity']),'status'=>(string)($_POST['status']??$item['status']),'starts_at'=>(string)($_POST['starts_at']??$item['starts_at']),'ends_at'=>(string)($_POST['ends_at']??$item['ends_at'])]);
                }
            }
        }elseif(isset($_GET['saved']))$notice='Duyuru kaydedildi.';elseif(isset($_GET['deleted']))$notice='Duyuru silindi.';
        if($announcementId===null)$this->renderList($error,$notice);else $this->renderEditor($item,$error,$notice);
    }

    private function renderList(string $error,string $notice):void
    {
        $rows=$this->db->query('SELECT id,title,severity,status,starts_at,ends_at,published_at FROM cms_announcements ORDER BY created_at DESC,id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
        echo $this->head('Duyuru yönetimi').'<main class="wrap"><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><header><div><h1>Müşteri duyuruları</h1><p>Müşteri panelinde gösterilecek sistem ve hizmet duyurularını yönetin.</p></div><a class="primary" href="'.$this->e($this->url('/admin/announcements/new')).'">+ Yeni duyuru</a></header>';$this->messages($error,$notice);
        echo '<section class="panel"><table><thead><tr><th>Duyuru</th><th>Tür</th><th>Durum</th><th>Başlangıç</th><th>Bitiş</th><th></th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.$this->e((string)$row['title']).'</td><td>'.$this->e((string)$row['severity']).'</td><td>'.$this->e((string)$row['status']).'</td><td>'.$this->e((string)($row['starts_at']??'—')).'</td><td>'.$this->e((string)($row['ends_at']??'—')).'</td><td><a href="'.$this->e($this->url('/admin/announcements/'.(int)$row['id'])).'">Düzenle →</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="6">Henüz duyuru yok.</td></tr>';echo '</tbody></table></section></main></body></html>';
    }

    private function renderEditor(array $item,string $error,string $notice):void
    {
        $id=(int)$item['id'];$edit=$id>0;$action=$edit?$this->url('/admin/announcements/'.$id):$this->url('/admin/announcements/new');
        echo $this->head($edit?'Duyuru düzenle':'Yeni duyuru').'<main class="wrap"><a href="'.$this->e($this->url('/admin/announcements')).'">← Duyurular</a><header><div><h1>'.($edit?'Duyuru düzenle':'Yeni duyuru').'</h1><p>Yayınlanan duyurular tarih aralığında müşteri panelinde görünür.</p></div></header>';$this->messages($error,$notice);
        echo '<section class="panel"><form method="post" action="'.$this->e($action).'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="save"><div class="grid"><label>Başlık<input name="title" maxlength="190" required value="'.$this->e((string)$item['title']).'"></label><label>Duyuru türü<select name="severity">';
        foreach(['info'=>'Bilgi','success'=>'Başarılı','warning'=>'Uyarı','critical'=>'Kritik'] as $key=>$label)echo '<option value="'.$key.'" '.($item['severity']===$key?'selected':'').'>'.$label.'</option>';
        echo '</select></label><label>Yayın durumu<select name="status"><option value="draft" '.($item['status']==='draft'?'selected':'').'>Taslak</option><option value="published" '.($item['status']==='published'?'selected':'').'>Yayında</option></select></label><label>Başlangıç tarihi<input type="datetime-local" name="starts_at" value="'.$this->e((string)$item['starts_at']).'"></label><label>Bitiş tarihi<input type="datetime-local" name="ends_at" value="'.$this->e((string)$item['ends_at']).'"></label><label class="wide">Duyuru metni<textarea name="body" rows="8" maxlength="4000" required>'.$this->e((string)$item['body']).'</textarea></label></div><button class="primary">Kaydet</button></form></section>';
        if($edit)echo '<section class="panel danger"><h2>Duyuru silme</h2><form method="post" action="'.$this->e($action).'" onsubmit="return confirm(\'Duyuruyu kalıcı olarak silmek istiyor musunuz?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm_delete" value="1"><button class="danger-button">Duyuruyu sil</button></form></section>';
        echo '</main></body></html>';
    }

    private function parseDate(string $value):?string
    {
        $value=trim($value);if($value==='')return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('Europe/Istanbul'));
        $errors=\DateTimeImmutable::getLastErrors();
        if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0)))throw new \RuntimeException('Tarih biçimi geçersiz.');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    private function toInputDate(?string $value):string{return $value?(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Istanbul'))->format('Y-m-d\TH:i'):'';}
    private function head(string $title):string{return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}.wrap{max-width:1120px;margin:32px auto;padding:20px}a{color:#9bb4ff;text-decoration:none}header{margin:24px 0}h1{font-size:27px;margin:0 0 8px}header p,.panel p{color:#9aabc2;margin:0}.panel{background:#111d31;border:1px solid #293850;border-radius:14px;padding:20px;margin:16px 0}.primary{display:inline-block;background:#5278ed;color:#fff;border:0;border-radius:8px;padding:11px 15px;font-weight:700;cursor:pointer}.danger-button{background:#8f394a;color:#fff;border:0;border-radius:8px;padding:11px 15px;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:13px;border-bottom:1px solid #293850}label{display:flex;flex-direction:column;gap:7px;color:#aebbd0;font-weight:600}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.grid .wide{grid-column:1/-1}input,select,textarea{padding:11px;background:#0b1425;color:#e8edf7;border:1px solid #34445e;border-radius:8px;font:inherit}.notice{color:#9df0c8}.error{color:#ffc3ca}.danger{border-color:#633b4b}@media(max-width:700px){.wrap{padding:14px}.grid{grid-template-columns:1fr}.grid .wide{grid-column:auto}}</style></head><body>';}
    private function messages(string $error,string $notice):void{if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';}
    private function length(string $value):int{return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);}
    private function redirect(string $path):never{header('Location: '.$this->url($path),true,303);exit;}
    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
