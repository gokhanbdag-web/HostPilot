<?php
declare(strict_types=1);
namespace HostPilot\\Http;

use HostPilot\\Core\\Auth;
use PDO;

final class EmailQueueController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath){}
    public function index():void
    {
        $user=Auth::user();
        if(!$user){$this->go('/admin/login');return;}
        if(!in_array($user['role']??'', ['super_admin','admin'],true)){http_response_code(403);echo 'Yetkisiz erişim.';return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$this->go('/admin/email-queue','error='.rawurlencode('Güvenlik doğrulaması başarısız.'));
            $id=(int)($_POST['id']??0);
            $q=$this->db->prepare("UPDATE email_outbox SET status='pending',attempts=0,available_at=:now,locked_at=NULL,last_error=NULL,updated_at=:now WHERE id=:id AND status IN ('failed','needs_review')");
            $q->execute([':now'=>gmdate('Y-m-d H:i:s'),':id'=>$id]);
            $this->go('/admin/email-queue',($q->rowCount()?'notice=E-posta+yeniden+kuyruğa+alındı':'error=Kayıt+yeniden+kuyruğa+alınamadı'));
        }
        $counts=$this->db->query("SELECT status,COUNT(*) AS total FROM email_outbox GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows=$this->db->query("SELECT id,event_type,recipient,subject,status,attempts,available_at,last_error,created_at,sent_at FROM email_outbox ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $notice=(string)($_GET['notice']??'');$error=(string)($_GET['error']??'');
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>E-posta kuyruğu · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}main{max-width:1200px;margin:auto;padding:28px}a{color:#9bb4ff}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}.card,article{background:#111c2f;border:1px solid #26354c;border-radius:12px;padding:15px;margin:12px 0}.muted{color:#9aabc2}.ok{color:#8ce0bb}.bad{color:#ffb2bd}button{padding:8px 12px;background:#5278ed;color:white;border:0;border-radius:7px;font-weight:700}article{overflow-wrap:anywhere}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}</style></head><body><main><div class="top"><div><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><h1>E-posta gönderim kuyruğu</h1><p class="muted">Gönderimler SMTP worker tarafından yürütülür. Belirsiz zaman aşımı kayıtları otomatik tekrarlanmaz; çift e-posta riskine karşı incelemeye alınır.</p></div><a href="'.$this->e($this->url('/admin/settings')).'">SMTP ayarları →</a></div>';
        if($notice!=='')echo '<p class="ok">'.$this->e($notice).'</p>';if($error!=='')echo '<p class="bad">'.$this->e($error).'</p>';
        echo '<section class="cards">';foreach(['pending'=>'Bekleyen','sending'=>'Gönderiliyor','sent'=>'Gönderildi','failed'=>'Başarısız','needs_review'=>'İnceleme gerekli'] as $key=>$label)echo '<div class="card"><b>'.$label.'</b><h2>'.(int)($counts[$key]??0).'</h2></div>';echo '</section>';
        if(!$rows)echo '<p class="muted">Henüz e-posta kaydı yok.</p>';
        foreach($rows as $r){echo '<article><div class="top"><b>#'.(int)$r['id'].' · '.$this->e($r['subject']).'</b><b>'.$this->e($r['status']).'</b></div><p>'.$this->e($r['recipient']).' · '.$this->e($r['event_type']).' · Deneme: '.(int)$r['attempts'].'</p><p class="muted">Oluşturulma: '.$this->e($r['created_at']).' · Sonraki deneme: '.$this->e($r['available_at']).($r['sent_at']?' · Gönderim: '.$this->e($r['sent_at']):'').'</p>';
            if($r['last_error'])echo '<p class="bad">'.$this->e($r['last_error']).'</p>';
            if(in_array($r['status'],['failed','needs_review'],true))echo '<form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button>Manuel yeniden kuyruğa al</button></form>';
            echo '</article>';}
        echo '</main></body></html>';
    }
    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function go(string $path,string $query=''):never{$url=rtrim($this->basePath,'/').$path;if($query!=='')$url.='?'.$query;header('Location: '.$url);exit;}
}
