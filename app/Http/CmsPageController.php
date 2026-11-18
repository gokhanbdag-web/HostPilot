<?php
declare(strict_types=1);

namespace HostPilot\Http;

use PDO;

final class CmsPageController
{
    public function __construct(private readonly PDO $db,private readonly string $basePath=''){}

    public function show(string $slug):void
    {
        $q=$this->db->prepare("SELECT title,slug,excerpt,content,meta_title,meta_description FROM cms_pages WHERE slug=:slug AND status='published' LIMIT 1");
        $q->execute([':slug'=>$slug]);$page=$q->fetch(PDO::FETCH_ASSOC);
        if(!$page){http_response_code(404);$this->start('Sayfa bulunamadı');echo '<main class="cms"><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><h1>Sayfa bulunamadı</h1><a href="'.$this->url('/').'">Ana sayfaya dön</a></main>';$this->end();return;}
        $this->start((string)($page['meta_title']?:$page['title']));
        if(trim((string)$page['meta_description'])!=='')echo '<meta name="description" content="'.$this->e((string)$page['meta_description']).'">';
        echo '<main class="cms"><header><a class="brand" href="'.$this->url('/').'">HOST<span>PILOT</span></a><nav><a href="'.$this->url('/').'">Ana sayfa</a><a href="'.$this->url('/knowledgebase').'">Yardım merkezi</a><a href="'.$this->url('/client').'">Müşteri paneli</a></nav></header><article><h1>'.$this->e((string)$page['title']).'</h1>';
        if(trim((string)$page['excerpt'])!=='')echo '<p class="excerpt">'.$this->e((string)$page['excerpt']).'</p>';
        echo '<div class="content">'.nl2br($this->e((string)$page['content'])).'</div></article></main>';$this->end();
    }

    private function start(string $title,string $description=''):void
    {
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · HostPilot</title><style>body{margin:0;background:#0a1020;color:#e8edf7;font:15px system-ui}.cms{max-width:1080px;margin:auto;padding:24px}.cms header{display:flex;justify-content:space-between;align-items:center;gap:18px;border-bottom:1px solid #26354c;padding-bottom:18px}.brand{font-size:19px;font-weight:900;letter-spacing:.12em;color:#fff;text-decoration:none}.brand span{color:#7898ff}nav{display:flex;gap:18px;flex-wrap:wrap}nav a,.cms a{color:#9bb4ff;text-decoration:none}.cms article{max-width:850px;margin:55px auto;background:#111c2f;border:1px solid #26354c;border-radius:16px;padding:34px}.cms h1{font-size:32px;margin:0 0 16px}.excerpt{color:#9aabc2;font-size:17px;line-height:1.6}.content{white-space:normal;line-height:1.9;color:#d5dfed;overflow-wrap:anywhere}@media(max-width:650px){.cms{padding:16px}.cms header{align-items:flex-start;flex-direction:column}.cms article{margin:28px auto;padding:22px}.cms h1{font-size:25px}}</style></head><body>';
    }
    private function end():void{echo '</body></html>';}
    private function url(string $path):string{return rtrim($this->basePath,'/').$path;}
    private function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
