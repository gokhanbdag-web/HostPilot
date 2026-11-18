<?php
declare(strict_types=1);

namespace HostPilot\Http;

use HostPilot\Core\Auth;
use HostPilot\Core\SecurityAudit;
use PDO;
use PDOException;
use Throwable;

final class CmsMenuAdminController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath=''){}

    public function index(?int $menuId=null):void
    {
        $actor=Auth::user();
        if(!$actor||!in_array(($actor['role']??''),['admin','super_admin'],true)){http_response_code(403);echo 'Bu sayfaya yalnızca yöneticiler erişebilir.';return;}
        $error='';$notice='';$menu=null;$items=[];
        if($menuId!==null){
            $q=$this->db->prepare('SELECT id,name,location,status FROM cms_menus WHERE id=:id LIMIT 1');$q->execute([':id'=>$menuId]);$menu=$q->fetch(PDO::FETCH_ASSOC);
            if(!$menu){http_response_code(404);echo 'Menü bulunamadı.';return;}
        }
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
            if(!Auth::verifyCsrf($_POST['_token']??null))$error='Güvenlik doğrulaması başarısız.';
            else{
                try{
                    $action=(string)($_POST['action']??'');
                    if($menuId===null)throw new \RuntimeException('Menü seçimi geçersiz.');
                    if($action==='save_menu'){
                        $name=trim((string)($_POST['name']??''));$status=(string)($_POST['status']??'active');
                        if($name===''||$this->length($name)>100)throw new \RuntimeException('Menü adı 1–100 karakter olmalıdır.');
                        if(!in_array($status,['active','inactive'],true))throw new \RuntimeException('Menü durumu geçersiz.');
                        $this->db->prepare('UPDATE cms_menus SET name=:name,status=:status,updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute([':name'=>$name,':status'=>$status,':id'=>$menuId]);
                        SecurityAudit::record($this->db,'cms.menu_updated',(int)$actor['id'],['resource_id'=>$menuId]);
                    }elseif($action==='create_item'){
                        $item=$this->validatedItem($_POST);
                        $sort=(int)($_POST['sort_order']??0);
                        $q=$this->db->prepare('INSERT INTO cms_menu_items(menu_id,label,url,status,target_blank,sort_order,created_at,updated_at) VALUES(:menu,:label,:url,:status,:target,:sort,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                        $q->execute([':menu'=>$menuId,':label'=>$item['label'],':url'=>$item['url'],':status'=>$item['status'],':target'=>$item['target'],':sort'=>$sort]);
                        SecurityAudit::record($this->db,'cms.menu_item_created',(int)$actor['id'],['resource_id'=>(int)$this->db->lastInsertId(),'menu_id'=>$menuId]);
                    }elseif($action==='update_item'){
                        $itemId=(int)($_POST['item_id']??0);$item=$this->validatedItem($_POST);$sort=(int)($_POST['sort_order']??0);
                        $q=$this->db->prepare('UPDATE cms_menu_items SET label=:label,url=:url,status=:status,target_blank=:target,sort_order=:sort,updated_at=UTC_TIMESTAMP() WHERE id=:id AND menu_id=:menu');
                        $q->execute([':label'=>$item['label'],':url'=>$item['url'],':status'=>$item['status'],':target'=>$item['target'],':sort'=>$sort,':id'=>$itemId,':menu'=>$menuId]);
                        if($q->rowCount()===0){$check=$this->db->prepare('SELECT 1 FROM cms_menu_items WHERE id=:id AND menu_id=:menu');$check->execute([':id'=>$itemId,':menu'=>$menuId]);if(!$check->fetchColumn())throw new \RuntimeException('Menü öğesi bulunamadı.');}
                        SecurityAudit::record($this->db,'cms.menu_item_updated',(int)$actor['id'],['resource_id'=>$itemId,'menu_id'=>$menuId]);
                    }elseif($action==='delete_item'){
                        $itemId=(int)($_POST['item_id']??0);$q=$this->db->prepare('DELETE FROM cms_menu_items WHERE id=:id AND menu_id=:menu');$q->execute([':id'=>$itemId,':menu'=>$menuId]);
                        if($q->rowCount()!==1)throw new \RuntimeException('Menü öğesi bulunamadı.');
                        SecurityAudit::record($this->db,'cms.menu_item_deleted',(int)$actor['id'],['resource_id'=>$itemId,'menu_id'=>$menuId]);
                    }else throw new \RuntimeException('Geçersiz menü işlemi.');
                    $this->redirect('/admin/menus/'.$menuId.'?saved=1');
                }catch(Throwable $e){$error=$e instanceof PDOException?'Menü öğesi kaydedilemedi. Verileri kontrol edin.':$e->getMessage();}
            }
        }elseif(isset($_GET['saved']))$notice='Menü değişiklikleri kaydedildi.';
        if($menuId===null)$this->renderList($error,$notice);else{
            $q=$this->db->prepare('SELECT id,label,url,status,target_blank,sort_order FROM cms_menu_items WHERE menu_id=:id ORDER BY sort_order,id');$q->execute([':id'=>$menuId]);$items=$q->fetchAll(PDO::FETCH_ASSOC);
            $this->renderEditor($menu,$items,$error,$notice);
        }
    }

    private function validatedItem(array $input):array
    {
        $label=trim((string)($input['label']??''));$url=trim((string)($input['url']??''));$status=(string)($input['status']??'active');
        if($label===''||$this->length($label)>100)throw new \RuntimeException('Menü etiketi 1–100 karakter olmalıdır.');
        if($url===''||strlen($url)>500||preg_match('/[\x00-\x20\x7F]/',$url))throw new \RuntimeException('Menü bağlantısı geçersiz.');
        $relative=str_starts_with($url,'/')&&!str_starts_with($url,'//');
        $external=filter_var($url,FILTER_VALIDATE_URL)!==false&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https';
        if(!$relative&&!$external)throw new \RuntimeException('Yalnızca site içi bağlantılar veya HTTPS adresleri kullanılabilir.');
        if(!in_array($status,['active','inactive'],true))throw new \RuntimeException('Menü öğesi durumu geçersiz.');
        return ['label'=>$label,'url'=>$url,'status'=>$status,'target'=>isset($input['target_blank'])?1:0];
    }

    private function renderList(string $error,string $notice):void
    {
        $rows=$this->db->query('SELECT m.id,m.name,m.location,m.status,COUNT(i.id) AS item_count FROM cms_menus m LEFT JOIN cms_menu_items i ON i.menu_id=m.id GROUP BY m.id ORDER BY m.location')->fetchAll(PDO::FETCH_ASSOC);
        echo $this->head('Menü yönetimi').'<main class="wrap"><a href="'.$this->e($this->url('/admin')).'">← Yönetim paneli</a><header><div><h1>Menü yönetimi</h1><p>Site üst ve alt navigasyonlarını yönetin.</p></div></header>';$this->messages($error,$notice);
        echo '<section class="panel"><table><thead><tr><th>Menü</th><th>Konum</th><th>Durum</th><th>Öğe sayısı</th><th></th></tr></thead><tbody>';
        foreach($rows as $row)echo '<tr><td>'.$this->e((string)$row['name']).'</td><td>'.$this->e((string)$row['location']).'</td><td>'.$this->e((string)$row['status']).'</td><td>'.(int)$row['item_count'].'</td><td><a href="'.$this->e($this->url('/admin/menus/'.(int)$row['id'])).'">Düzenle →</a></td></tr>';
        if(!$rows)echo '<tr><td colspan="5">Henüz menü tanımlanmamış.</td></tr>';
        echo '</tbody></table></section></main></body></html>';
    }

    private function renderEditor(array $menu,array $items,string $error,string $notice):void
    {
        $id=(int)$menu['id'];echo $this->head('Menü düzenle').'<main class="wrap"><a href="'.$this->e($this->url('/admin/menus')).'">← Menüler</a><header><div><h1>'.$this->e((string)$menu['name']).'</h1><p>Konum: '.$this->e((string)$menu['location']).'</p></div></header>';$this->messages($error,$notice);
        echo '<section class="panel"><h2>Menü ayarları</h2><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="save_menu"><label>Menü adı<input name="name" maxlength="100" required value="'.$this->e((string)$menu['name']).'"></label><label>Durum<select name="status"><option value="active" '.($menu['status']==='active'?'selected':'').'>Aktif</option><option value="inactive" '.($menu['status']==='inactive'?'selected':'').'>Pasif</option></select></label><button class="primary">Menüyü kaydet</button></form></section>';
        echo '<section class="panel"><h2>Yeni menü öğesi</h2><form method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="action" value="create_item"><div class="grid"><label>Bağlantı metni<input name="label" maxlength="100" required></label><label>Bağlantı adresi<input name="url" maxlength="500" placeholder="/page/hakkimizda veya https://example.com" required></label><label>Durum<select name="status"><option value="active">Aktif</option><option value="inactive">Pasif</option></select></label><label>Sıralama<input type="number" name="sort_order" value="0"></label><label class="check"><input type="checkbox" name="target_blank" value="1"> Yeni sekmede aç</label></div><button class="primary">Menüye ekle</button></form></section>';
        echo '<section class="panel"><h2>Mevcut menü öğeleri</h2><div class="items">';
        foreach($items as $item){
            echo '<form class="item" method="post"><input type="hidden" name="_token" value="'.$this->e(Auth::csrfToken()).'"><input type="hidden" name="item_id" value="'.(int)$item['id'].'"><div class="grid"><label>Metin<input name="label" maxlength="100" required value="'.$this->e((string)$item['label']).'"></label><label>URL<input name="url" maxlength="500" required value="'.$this->e((string)$item['url']).'"></label><label>Durum<select name="status"><option value="active" '.($item['status']==='active'?'selected':'').'>Aktif</option><option value="inactive" '.($item['status']==='inactive'?'selected':'').'>Pasif</option></select></label><label>Sıralama<input type="number" name="sort_order" value="'.(int)$item['sort_order'].'"></label><label class="check"><input type="checkbox" name="target_blank" value="1" '.((int)$item['target_blank']===1?'checked':'').'> Yeni sekmede aç</label></div><div class="actions"><button class="primary" name="action" value="update_item">Kaydet</button><button class="danger-button" name="action" value="delete_item" formnovalidate onclick="return confirm(\'Menü öğesini silmek istiyor musunuz?\')">Sil</button></div></form>';
        }
        if(!$items)echo '<p>Bu menüde henüz bağlantı yok.</p>';
        echo '</div></section></main></body></html>';
    }

    private function head(string $title):string{return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:14px system-ui}.wrap{max-width:1120px;margin:32px auto;padding:20px}a{color:#9bb4ff;text-decoration:none}header{margin:24px 0}h1{font-size:27px;margin:0 0 8px}header p,.panel p{color:#9aabc2;margin:0}.panel{background:#111d31;border:1px solid #293850;border-radius:14px;padding:20px;margin:16px 0}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:13px;border-bottom:1px solid #293850}.primary{background:#5278ed;color:#fff;border:0;border-radius:8px;padding:11px 15px;font-weight:700;cursor:pointer}.danger-button{background:#8f394a;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}label{display:flex;flex-direction:column;gap:7px;color:#aebbd0;font-weight:600;margin:8px 0}input,select{padding:10px;background:#0b1425;color:#e8edf7;border:1px solid #34445e;border-radius:8px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.check{flex-direction:row;align-items:center}.item{padding:15px 0;border-bottom:1px solid #293850}.actions{display:flex;gap:10px;margin-top:10px}.notice{color:#9df0c8}.error{color:#ffc3ca}@media(max-width:700px){.wrap{padding:14px}.grid{grid-template-columns:1fr}}</style></head><body>';}
    private function messages(string $error,string $notice):void{if($notice!=='')echo '<p class="notice">'.$this->e($notice).'</p>';if($error!=='')echo '<p class="error">'.$this->e($error).'</p>';}
    private function length(string $value):int{return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);}
    private function redirect(string $path):never{header('Location: '.$this->url($path),true,303);exit;}
    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
