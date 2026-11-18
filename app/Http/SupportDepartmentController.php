<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use Throwable;

final class SupportDepartmentController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath='') {}

    public function index(): void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){
            http_response_code(403);echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';return;
        }
        $error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $action=(string)($_POST['action']??'');
                try{
                    if($action==='create'){
                        $name=trim((string)($_POST['name']??''));
                        if(strlen($name)<2||strlen($name)>100||preg_match('/[\x00-\x1F\x7F]/',$name))throw new \RuntimeException('Departman adı 2–100 karakter olmalıdır.');
                        $this->db->beginTransaction();
                        $next=(int)$this->db->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM support_departments')->fetchColumn();
                        $q=$this->db->prepare("INSERT INTO support_departments(name,status,sort_order,created_at,updated_at) VALUES(:name,'active',:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                        $q->execute([':name'=>$name,':sort'=>$next]);
                        SecurityAudit::record($this->db,'support.department_created',(int)$actor['id'],['action'=>'created']);
                        $this->db->commit();$notice='Yeni destek departmanı eklendi.';
                    }elseif($action==='toggle'){
                        $id=(int)($_POST['department_id']??0);
                        $this->db->beginTransaction();
                        $q=$this->db->prepare('SELECT id,status FROM support_departments WHERE id=:id FOR UPDATE');
                        $q->execute([':id'=>$id]);$department=$q->fetch(PDO::FETCH_ASSOC);
                        if(!$department)throw new \RuntimeException('Departman bulunamadı.');
                        $next=$department['status']==='active'?'inactive':'active';
                        if($next==='inactive'&&(int)$this->db->query("SELECT COUNT(*) FROM support_departments WHERE status='active'")->fetchColumn()<=1)throw new \RuntimeException('En az bir aktif destek departmanı bulunmalıdır.');
                        $this->db->prepare('UPDATE support_departments SET status=:status,updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute([':status'=>$next,':id'=>$id]);
                        SecurityAudit::record($this->db,'support.department_status_changed',(int)$actor['id'],['resource_id'=>$id,'action'=>$next]);
                        $this->db->commit();$notice=$next==='active'?'Departman yeniden etkinleştirildi.':'Departman yeni taleplere kapatıldı.';
                    }else throw new \RuntimeException('Geçersiz departman işlemi.');
                }catch(Throwable $e){
                    if($this->db->inTransaction())$this->db->rollBack();
                    $error=$e instanceof \PDOException?'Bu departman adı zaten kullanılıyor.':$e->getMessage();
                }
            }
        }
        $rows=$this->db->query('SELECT d.id,d.name,d.status,d.sort_order,(SELECT COUNT(*) FROM tickets t WHERE t.department=d.name) AS ticket_count,(SELECT COUNT(*) FROM staff_departments sd WHERE sd.department=d.name) AS staff_count FROM support_departments d ORDER BY d.sort_order,d.name')->fetchAll(PDO::FETCH_ASSOC);
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Destek departmanları · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}.wrap{max-width:1000px;margin:36px auto;padding:20px}.panel{background:#111d31;border:1px solid #293850;border-radius:14px;padding:22px;margin:18px 0}a{color:#8ab4ff}input{padding:11px;background:#0b1425;color:#fff;border:1px solid #34445e;border-radius:8px}button{padding:10px 15px;border:0;border-radius:8px;background:#5278ed;color:#fff;font-weight:700;cursor:pointer}.muted{color:#9aabc2}.notice{color:#9df0c8}.error{color:#ffc3ca}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #293850}</style></head><body><main class="wrap"><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><h1>Destek departmanları</h1><p class="muted">Yeni destek taleplerinde kullanılacak departmanları yönetin. Pasif departmanlar eski taleplerde ve personel geçmişinde korunur.</p>';
        if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';
        if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';
        echo '<section class="panel"><h2>Yeni departman</h2><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input name="name" maxlength="100" minlength="2" placeholder="Örn. Teknik Destek" required><button name="action" value="create">Departman ekle</button></form></section>';
        echo '<section class="panel"><h2>Mevcut departmanlar</h2><table><thead><tr><th>Departman</th><th>Durum</th><th>Personel</th><th>Destek talebi</th><th>İşlem</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td>'.$this->e((string)$row['name']).'</td><td>'.$this->e((string)$row['status']).'</td><td>'.(int)$row['staff_count'].'</td><td>'.(int)$row['ticket_count'].'</td><td><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="department_id" value="'.(int)$row['id'].'"><button name="action" value="toggle">'.($row['status']==='active'?'Pasife al':'Yeniden etkinleştir').'</button></form></td></tr>';
        }
        if(!$rows)echo '<tr><td colspan="5">Departman bulunamadı.</td></tr>';
        echo '</tbody></table></section></main></body></html>';
    }

    private function url(string $path):string{return $this->basePath.$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
