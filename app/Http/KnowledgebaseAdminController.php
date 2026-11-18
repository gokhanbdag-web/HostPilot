<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use PDOException;
use Throwable;

final class KnowledgebaseAdminController
{
    public function __construct(private readonly PDO $db, private readonly string $basePath='') {}

    public function index(?int $articleId=null): void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){
            http_response_code(403);echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';return;
        }
        $error='';$notice='';
        $article=['id'=>0,'title'=>'','slug'=>'','excerpt'=>'','content'=>'','category'=>'Genel','status'=>'draft','sort_order'=>0];
        if($articleId!==null){
            $q=$this->db->prepare('SELECT id,title,slug,excerpt,content,category,status,sort_order FROM knowledgebase_articles WHERE id=:id LIMIT 1');
            $q->execute([':id'=>$articleId]);$found=$q->fetch(PDO::FETCH_ASSOC);
            if(!$found){http_response_code(404);echo 'Bilgi bankası makalesi bulunamadı.';return;}
            $article=$found;
        }
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                $action=(string)($_POST['action']??'save');
                try{
                    if($action==='delete'){
                        if($articleId===null||(string)($_POST['confirm_delete']??'')!=='1')throw new \RuntimeException('Silme işlemini onaylamanız gerekiyor.');
                        $this->db->beginTransaction();
                        $delete=$this->db->prepare('DELETE FROM knowledgebase_articles WHERE id=:id');
                        $delete->execute([':id'=>$articleId]);
                        if($delete->rowCount()!==1)throw new \RuntimeException('Makale bulunamadı veya daha önce silinmiş.');
                        SecurityAudit::record($this->db,'knowledgebase.article_deleted',(int)$actor['id'],['resource_id'=>$articleId]);
                        $this->db->commit();$this->redirect('/admin/knowledgebase?deleted=1');
                    }
                    if($action!=='save')throw new \RuntimeException('Geçersiz makale işlemi.');
                    $title=trim((string)($_POST['title']??''));$slug=$this->slug((string)($_POST['slug']??''));$category=trim((string)($_POST['category']??'Genel'));
                    $excerpt=trim((string)($_POST['excerpt']??''));$content=trim((string)($_POST['content']??''));$status=(string)($_POST['status']??'draft');$sort=(int)($_POST['sort_order']??0);
                    if($title===''||mb_strlen($title)>190)throw new \RuntimeException('Makale başlığı 1–190 karakter olmalıdır.');
                    if($slug===''||strlen($slug)>190||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug))throw new \RuntimeException('SEO bağlantısı geçerli bir kısa ad olmalıdır.');
                    if($category===''||mb_strlen($category)>100)throw new \RuntimeException('Kategori 1–100 karakter olmalıdır.');
                    if(mb_strlen($excerpt)>2000||$content===''||mb_strlen($content)>50000)throw new \RuntimeException('Açıklama en fazla 2.000, makale içeriği 1–50.000 karakter olmalıdır.');
                    if(!in_array($status,['draft','published'],true))throw new \RuntimeException('Yayın durumu geçersiz.');
                    $this->db->beginTransaction();
                    if($articleId===null){
                        $save=$this->db->prepare('INSERT INTO knowledgebase_articles(title,slug,excerpt,content,category,status,sort_order,created_at,updated_at) VALUES(:title,:slug,:excerpt,:content,:category,:status,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                        $save->execute([':title'=>$title,':slug'=>$slug,':excerpt'=>$excerpt,':content'=>$content,':category'=>$category,':status'=>$status,':sort'=>$sort]);
                        $savedId=(int)$this->db->lastInsertId();$event='knowledgebase.article_created';
                    }else{
                        $save=$this->db->prepare('UPDATE knowledgebase_articles SET title=:title,slug=:slug,excerpt=:excerpt,content=:content,category=:category,status=:status,sort_order=:sort,updated_at=UTC_TIMESTAMP() WHERE id=:id');
                        $save->execute([':title'=>$title,':slug'=>$slug,':excerpt'=>$excerpt,':content'=>$content,':category'=>$category,':status'=>$status,':sort'=>$sort,':id'=>$articleId]);
                        $savedId=$articleId;$event='knowledgebase.article_updated';
                    }
                    SecurityAudit::record($this->db,$event,(int)$actor['id'],['resource_id'=>$savedId,'status'=>$status]);
                    $this->db->commit();$this->redirect('/admin/knowledgebase/'.$savedId.'?saved=1');
                }catch(Throwable $e){
                    if($this->db->inTransaction())$this->db->rollBack();
                    $error=$e instanceof PDOException?'Bu SEO bağlantısı başka bir makale tarafından kullanılıyor.':$e->getMessage();
                    $article=array_merge($article,['title'=>(string)($_POST['title']??$article['title']),'slug'=>(string)($_POST['slug']??$article['slug']),'excerpt'=>(string)($_POST['excerpt']??$article['excerpt']),'content'=>(string)($_POST['content']??$article['content']),'category'=>(string)($_POST['category']??$article['category']),'status'=>(string)($_POST['status']??$article['status']),'sort_order'=>(int)($_POST['sort_order']??$article['sort_order'])]);
                }
            }
        }elseif(isset($_GET['saved']))$notice='Makale kaydedildi.';
        elseif(isset($_GET['deleted']))$notice='Makale silindi.';
        if($articleId===null)$this->renderList($error,$notice);else $this->renderEditor($article,$error,$notice);
    }

    private function renderList(string $error,string $notice):void
    {
        $rows=$this->db->query("SELECT a.id,a.title,a.slug,a.category,a.status,a.updated_at,(SELECT COUNT(*) FROM knowledgebase_feedback f WHERE f.article_id=a.id) AS votes FROM knowledgebase_articles a ORDER BY a.updated_at DESC,a.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        echo $this->head('Bilgi bankası yönetimi').'<main class="wrap"><nav><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a></nav><header><div><h1>Bilgi bankası</h1><p>Müşteri yardım merkezi makalelerini oluşturun, düzenleyin ve yayınlayın.</p></div><a class="primary" href="'.$this->e($this->url('/admin/knowledgebase/new')).'">+ Yeni makale</a></header>';
        $this->messages($error,$notice);
        echo '<section class="panel"><div class="table-wrap"><table><thead><tr><th>Makale</th><th>Kategori</th><th>Durum</th><th>Oy</th><th>Son güncelleme</th><th></th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td><strong>'.$this->e((string)$row['title']).'</strong><small>'.$this->e((string)$row['slug']).'</small></td><td>'.$this->e((string)$row['category']).'</td><td><span class="status '.($row['status']==='published'?'published':'draft').'">'.$this->e((string)$row['status']).'</span></td><td>'.(int)$row['votes'].'</td><td>'.$this->e((string)$row['updated_at']).'</td><td><a href="'.$this->e($this->url('/admin/knowledgebase/'.(int)$row['id'])).'">Düzenle →</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="6">Henüz makale yok. İlk yardım makalenizi oluşturun.</td></tr>';
        echo '</tbody></table></div></section></main></body></html>';
    }

    private function renderEditor(array $article,string $error,string $notice):void
    {
        $id=(int)$article['id'];$editing=$id>0;$action=$editing?$this->url('/admin/knowledgebase/'.$id):$this->url('/admin/knowledgebase/new');
        echo $this->head($editing?'Makale düzenle':'Yeni makale').'<main class="wrap"><nav><a href="'.$this->e($this->url('/admin/knowledgebase')).'">← Bilgi bankası</a></nav><header><div><h1>'.($editing?'Makale düzenle':'Yeni makale').'</h1><p>İçerik düz metin olarak saklanır ve güvenli biçimde gösterilir.</p></div></header>';
        $this->messages($error,$notice);
        echo '<section class="panel"><form method="post" action="'.$this->e($action).'"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="save"><div class="grid"><label>Makale başlığı<input name="title" maxlength="190" required value="'.$this->e((string)$article['title']).'"></label><label>SEO bağlantısı<input name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" required value="'.$this->e((string)$article['slug']).'" placeholder="ornek-makale"></label><label>Kategori<input name="category" maxlength="100" required value="'.$this->e((string)$article['category']).'"></label><label>Yayın durumu<select name="status"><option value="draft" '.($article['status']==='draft'?'selected':'').'>Taslak</option><option value="published" '.($article['status']==='published'?'selected':'').'>Yayında</option></select></label><label class="wide">Kısa açıklama<textarea name="excerpt" rows="3" maxlength="2000">'.$this->e((string)$article['excerpt']).'</textarea></label><label class="wide">Makale içeriği<textarea name="content" rows="16" maxlength="50000" required>'.$this->e((string)$article['content']).'</textarea></label><label>Sıralama<input type="number" name="sort_order" value="'.(int)$article['sort_order'].'"></label></div><div class="actions"><button class="primary" type="submit">Kaydet</button>';
        if($editing&&$article['status']==='published')echo '<a href="'.$this->e($this->url('/knowledgebase/'.(string)$article['slug'])).'" target="_blank" rel="noopener">Makaleyi görüntüle ↗</a>';
        echo '</div></form></section>';
        if($editing)echo '<section class="panel danger"><h2>Makale silme</h2><p>Silinen makale ve ona ait geri bildirim oyları kalıcı olarak silinir.</p><form method="post" action="'.$this->e($action).'" onsubmit="return confirm(\'Bu makaleyi ve oylarını kalıcı olarak silmek istediğinize emin misiniz?\')"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm_delete" value="1"><button class="danger-button" type="submit">Makaleyi sil</button></form></section>';
        echo '</main></body></html>';
    }

    private function head(string $title):string
    {
        return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}.wrap{max-width:1120px;margin:32px auto;padding:20px}a{color:#9bb4ff;text-decoration:none}nav{margin-bottom:24px}header{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:22px}h1{font-size:27px;margin:0 0 8px}header p,.panel p{color:#9aabc2;margin:0}.panel{background:#111d31;border:1px solid #293850;border-radius:14px;padding:20px;margin:16px 0}.primary{display:inline-block;background:#5278ed;color:#fff;border:0;border-radius:8px;padding:11px 15px;font-weight:700;cursor:pointer}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:left;padding:13px;border-bottom:1px solid #293850}td small{display:block;color:#8fa0b9;margin-top:5px}.status{font-size:11px;padding:5px 8px;border-radius:99px;background:#27364e}.status.published{background:#173d37;color:#9df0c8}.status.draft{color:#e9cb8c}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.grid label{display:flex;flex-direction:column;gap:7px;color:#aebbd0;font-weight:600}.grid input,.grid select,.grid textarea{width:100%;padding:11px;border:1px solid #34445e;border-radius:8px;background:#0b1425;color:#e8edf7;font:inherit}.grid .wide{grid-column:1/-1}.actions{display:flex;gap:18px;align-items:center;margin-top:18px}.notice{color:#9df0c8}.error{color:#ffc3ca}.danger{border-color:#633b4b}.danger-button{background:#8f394a;color:#fff;border:0;border-radius:8px;padding:11px 15px;cursor:pointer}@media(max-width:700px){.wrap{padding:14px}header{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:1fr}.grid .wide{grid-column:auto}}</style></head><body>';
    }

    private function messages(string $error,string $notice):void
    {
        if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';
        if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';
    }

    private function slug(string $value):string
    {
        $value=trim($value);
        if($value==='')return '';
        $ascii=function_exists('iconv')?iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value):$value;
        $ascii=is_string($ascii)?$ascii:$value;
        $slug=strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-', $ascii),'-'));
        return $slug;
    }

    private function redirect(string $path):never
    {
        header('Location: '.$this->url($path),true,303);exit;
    }

    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
