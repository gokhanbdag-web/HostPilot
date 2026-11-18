<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use PDOException;
use Throwable;

final class CmsPageAdminController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath='') {}

    public function index(?int $pageId=null):void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';return;}
        $page=['id'=>0,'title'=>'','slug'=>'','excerpt'=>'','content'=>'','meta_title'=>'','meta_description'=>'','status'=>'draft','sort_order'=>0];
        if($pageId!==null&&$pageId>0){
            $q=$this->db->prepare('SELECT id,title,slug,excerpt,content,meta_title,meta_description,status,sort_order FROM cms_pages WHERE id=:id LIMIT 1');$q->execute([':id'=>$pageId]);$found=$q->fetch(PDO::FETCH_ASSOC);
            if(!$found){http_response_code(404);echo 'Sayfa bulunamadı.';return;}$page=$found;
        }
        $error='';$notice='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                try{
                    $action=(string)($_POST['action']??'save');
                    if($action==='delete'){
                        if(!$pageId||(string)($_POST['confirm_delete']??'')!=='1')throw new \RuntimeException('Silme işlemini onaylamanız gerekiyor.');
                        $this->db->beginTransaction();$q=$this->db->prepare('DELETE FROM cms_pages WHERE id=:id');$q->execute([':id'=>$pageId]);
                        if($q->rowCount()!==1)throw new \RuntimeException('Sayfa bulunamadı.');
                        SecurityAudit::record($this->db,'cms.page_deleted',(int)$actor['id'],['resource_id'=>$pageId]);$this->db->commit();$this->redirect('/admin/pages?deleted=1');
                    }
                    if($action!=='save')throw new \RuntimeException('Geçersiz sayfa işlemi.');
                    $title=trim((string)($_POST['title']??''));$slug=$this->slug((string)($_POST['slug']??''));if($slug==='')$slug=$this->slug($title);
                    $excerpt=trim((string)($_POST['excerpt']??''));$content=trim((string)($_POST['content']??''));$metaTitle=trim((string)($_POST['meta_title']??''));$metaDescription=trim((string)($_POST['meta_description']??''));$status=(string)($_POST['status']??'draft');$sort=(int)($_POST['sort_order']??0);
                    if($title===''||$this->length($title)>190)throw new \RuntimeException('Sayfa başlığı 1–190 karakter olmalıdır.');
                    if($slug===''||strlen($slug)>190||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug))throw new \RuntimeException('SEO bağlantısı geçerli olmalıdır.');
                    if($this->length($excerpt)>2000||$content===''||$this->length($content)>100000)throw new \RuntimeException('Özet en fazla 2.000, içerik 1–100.000 karakter olmalıdır.');
                    if($this->length($metaTitle)>190||$this->length($metaDescription)>320)throw new \RuntimeException('SEO başlığı veya açıklaması çok uzun.');
                    if(!in_array($status,['draft','published'],true))throw new \RuntimeException('Yayın durumu geçersiz.');
                    $this->db->beginTransaction();
                    if(!$pageId){
                        $q=$this->db->prepare('INSERT INTO cms_pages(title,slug,excerpt,content,meta_title,meta_description,status,sort_order,created_at,updated_at) VALUES(:title,:slug,:excerpt,:content,:meta_title,:meta_description,:status,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                        $q->execute([':title'=>$title,':slug'=>$slug,':excerpt'=>$excerpt,':content'=>$content,':meta_title'=>$metaTitle,':meta_description'=>$metaDescription,':status'=>$status,':sort'=>$sort]);$savedId=(int)$this->db->lastInsertId();$event='cms.page_created';
                    }else{
                        $q=$this->db->prepare('UPDATE cms_pages SET title=:title,slug=:slug,excerpt=:excerpt,content=:content,meta_title=:meta_title,meta_description=:meta_description,status=:status,sort_order=:sort,updated_at=UTC_TIMESTAMP() WHERE id=:id');
                        $q->execute([':title'=>$title,':slug'=>$slug,':excerpt'=>$excerpt,':content'=>$content,':meta_title'=>$metaTitle,':meta_description'=>$metaDescription,':status'=>$status,':sort'=>$sort,':id'=>$pageId]);$savedId=$pageId;$event='cms.page_updated';
                    }
                    SecurityAudit::record($this->db,$event,(int)$actor['id'],['resource_id'=>$savedId,'status'=>$status]);$this->db->commit();$this->redirect('/admin/pages/'.$savedId.'?saved=1');
                }catch(Throwable $e){
                    if($this->db->inTransaction())$this->db->rollBack();$error=$e instanceof PDOException?'Bu SEO bağlantısı başka bir sayfa tarafından kullanılıyor.':$e->getMessage();
                    $page=array_merge($page,['title'=>(string)($_POST['title']??$page['title']),'slug'=>(string)($_POST['slug']??$page['slug']),'excerpt'=>(string)($_POST['excerpt']??$page['excerpt']),'content'=>(string)($_POST['content']??$page['content']),'meta_title'=>(string)($_POST['meta_title']??$page['meta_title']),'meta_description'=>(string)($_POST['meta_description']??$page['meta_description']),'status'=>(string)($_POST['status']??$page['status']),'sort_order'=>(int)($_POST['sort_order']??$page['sort_order'])]);
                }
            }
        }elseif(isset($_GET['saved']))$notice='Sayfa kaydedildi.';elseif(isset($_GET['deleted']))$notice='Sayfa silindi.';
        if($pageId===null)$this->list($error,$notice);else $this->editor($page,$error,$notice);
    }

    private function list(string $error,string $notice):void
    {
        $rows=$this->db->query('SELECT id,title,slug,status,updated_at FROM cms_pages ORDER BY sort_order,title LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
        echo $this->head('CMS sayfaları').'<main class="wrap"><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><header><div><h1>Site sayfaları</h1><p>Kurumsal sayfaları ve içerikleri yönetin.</p></div><a class="primary" href="'.$this->e($this->url('/admin/pages/new')).'">+ Yeni sayfa</a></header>';
        $this->messages($error,$notice);echo '<section class="panel"><div class="table-wrap"><table><thead><tr><th>Sayfa</th><th>Durum</th><th>Son güncelleme</th><th></th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td><strong>'.$this->e((string)$row['title']).'</strong><small>/page/'.$this->e((string)$row['slug']).'</small></td><td>'.$this->e((string)$row['status']).'</td><td>'.$this->e((string)$row['updated_at']).'</td><td><a href="'.$this->e($this->url('/admin/pages/'.(int)$row['id'])).'">Düzenle →</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="4">Henüz site sayfası yok.</td></tr>';echo '</tbody></table></div></section></main></body></html>';
    }

    private function editor(array $page,string $error,string $notice):void
    {
        $id=(int)$page['id'];$edit=$id>0;$action=$edit?$this->url('/admin/pages/'.$id):$this->url('/admin/pages/new');
        echo $this->head($edit?'Sayfa düzenle':'Yeni site sayfası').'<main class="wrap"><a href="'.$this->e($this->url('/admin/pages')).'">← Site sayfaları</a><header><div><h1>'.($edit?'Sayfa düzenle':'Yeni site sayfası').'</h1><p>İçerik güvenli düz metin olarak saklanır. SEO alanları isteğe bağlıdır.</p></div></header>';
        $this->messages($error,$notice);echo '<section class="panel"><form method="post" action="'.$this->e($action).'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="save"><div class="grid"><label>Sayfa başlığı<input name="title" maxlength="190" required value="'.$this->e((string)$page['title']).'"></label><label>SEO bağlantısı<input name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="'.$this->e((string)$page['slug']).'" placeholder="hakkimizda"></label><label>Yayın durumu<select name="status"><option value="draft" '.($page['status']==='draft'?'selected':'').'>Taslak</option><option value="published" '.($page['status']==='published'?'selected':'').'>Yayında</option></select></label><label>Sıralama<input type="number" name="sort_order" value="'.(int)$page['sort_order'].'"></label><label class="wide">Kısa açıklama<textarea name="excerpt" rows="2" maxlength="2000">'.$this->e((string)$page['excerpt']).'</textarea></label><label>SEO başlığı<input name="meta_title" maxlength="190" value="'.$this->e((string)$page['meta_title']).'"></label><label>SEO açıklaması<textarea name="meta_description" rows="2" maxlength="320">'.$this->e((string)$page['meta_description']).'</textarea></label><label class="wide">Sayfa içeriği<textarea name="content" rows="18" maxlength="100000" required>'.$this->e((string)$page['content']).'</textarea></label></div><p class="actions"><button class="primary" type="submit">Kaydet</button>';
        if($edit&&$page['status']==='published')echo '<a href="'.$this->e($this->url('/page/'.(string)$page['slug'])).'" target="_blank" rel="noopener">Sayfayı görüntüle ↗</a>';
        echo '</p></form></section>';
        if($edit)echo '<section class="panel danger"><h2>Sayfa silme</h2><form method="post" action="'.$this->e($action).'" onsubmit="return confirm(\'Sayfayı kalıcı olarak silmek istiyor musunuz?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm_delete" value="1"><button class="danger-button" type="submit">Sayfayı sil</button></form></section>';
        echo '</main></body></html>';
    }

    private function head(string $title):string{return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}.wrap{max-width:1120px;margin:32px auto;padding:20px}a{color:#9bb4ff;text-decoration:none}header{display:flex;justify-content:space-between;align-items:center;gap:18px;margin:24px 0}h1{font-size:27px;margin:0 0 8px}header p,.panel p{color:#9aabc2;margin:0}.panel{background:#111d31;border:1px solid #293850;border-radius:14px;padding:20px;margin:16px 0}.primary{display:inline-block;background:#5278ed;color:#fff;border:0;border-radius:8px;padding:11px 15px;font-weight:700;cursor:pointer}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:650px}th,td{text-align:left;padding:13px;border-bottom:1px solid #293850}td small{display:block;color:#8fa0b9;margin-top:5px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.grid label{display:flex;flex-direction:column;gap:7px;color:#aebbd0;font-weight:600}.grid input,.grid select,.grid textarea{width:100%;padding:11px;border:1px solid #34445e;border-radius:8px;background:#0b1425;color:#e8edf7;font:inherit}.grid .wide{grid-column:1/-1}.actions{display:flex;gap:18px;align-items:center}.notice{color:#9df0c8}.error{color:#ffc3ca}.danger{border-color:#633b4b}.danger-button{background:#8f394a;color:#fff;border:0;border-radius:8px;padding:11px 15px;cursor:pointer}@media(max-width:700px){.wrap{padding:14px}header{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.grid .wide{grid-column:auto}}</style></head><body>';
    }

    private function messages(string $error,string $notice):void{if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';}
    private function slug(string $value):string{$value=trim($value);$ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value):$value;$ascii=is_string($ascii)?$ascii:$value;return strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-',$ascii),'-'));}
    private function length(string $value):int{return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);}
    private function redirect(string $path):never{header('Location: '.$this->url($path),true,303);exit;}
    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
