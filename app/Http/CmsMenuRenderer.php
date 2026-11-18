<?php
declare(strict_types=1);

namespace HostPilot\Http;

use PDO;

final class CmsMenuRenderer
{
    public static function links(PDO $db,string $basePath,string $location):string
    {
        $menu=$db->prepare("SELECT id FROM cms_menus WHERE location=:location AND status='active' LIMIT 1");
        $menu->execute([':location'=>$location]);$menuId=(int)$menu->fetchColumn();
        if($menuId<1)return '';
        $q=$db->prepare("SELECT label,url,target_blank FROM cms_menu_items WHERE menu_id=:id AND status='active' ORDER BY sort_order,id LIMIT 50");
        $q->execute([':id'=>$menuId]);$html='';
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $item){
            $url=(string)$item['url'];
            $relative=str_starts_with($url,'/')&&!str_starts_with($url,'//');
            $external=filter_var($url,FILTER_VALIDATE_URL)!==false&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https';
            if(!$relative&&!$external)continue;
            if($relative)$url=rtrim($basePath,'/').$url;
            $target=(int)$item['target_blank']===1?' target="_blank" rel="noopener noreferrer"':'';
            $html.='<a class="menu-link" href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"'.$target.'>'.htmlspecialchars((string)$item['label'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a>';
        }
        return $html;
    }
}
