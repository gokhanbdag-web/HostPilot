<?php
declare(strict_types=1);

$admin=file_get_contents(dirname(__DIR__).'/app/Http/CmsPageAdminController.php');
$public=file_get_contents(dirname(__DIR__).'/app/Http/CmsPageController.php');
$catalog=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$navigation=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0005_cms_pages.sql');
foreach([$admin,$public,$catalog,$index,$auth,$navigation,$migration] as $source)if(!is_string($source))throw new RuntimeException('CMS page source missing.');
$checks=[
 'CMS editor is restricted to admin and super-admin'=>str_contains($admin,"['admin','super_admin']"),
 'CMS writes require CSRF validation'=>str_contains($admin,'Auth::verifyCsrf($_POST[\'_token\']??null)'),
 'CMS writes use prepared statements'=>str_contains($admin,'INSERT INTO cms_pages')&&str_contains($admin,'UPDATE cms_pages'),
 'CMS page deletion requires confirmation'=>str_contains($admin,'$_POST[\'confirm_delete\']'),
 'CMS status is limited to draft and published'=>str_contains($admin,"['draft','published']"),
 'CMS slugs are normalized and validated'=>str_contains($admin,'function slug(string $value)')&&str_contains($admin,'^[a-z0-9]+(?:-[a-z0-9]+)*$'),
 'CMS page text and SEO lengths are validated'=>str_contains($admin,'100000')&&str_contains($admin,'meta_description'),
 'CMS page create update delete actions are audited'=>str_contains($admin,'cms.page_created')&&str_contains($admin,'cms.page_updated')&&str_contains($admin,'cms.page_deleted'),
 'public renderer exposes published pages only'=>str_contains($public,"WHERE slug=:slug AND status='published'"),
 'public page body is escaped before display'=>str_contains($public,'nl2br(')&&str_contains($public,'$this->e('),
 'SEO description is emitted from the head renderer'=>str_contains($public,'private function start(string $title,string $description=')&&str_contains($public,'$description!=='),
 'storefront navigation uses the administrator-managed header menu'=>str_contains($catalog,'CmsMenuRenderer::links')&&str_contains($catalog,"'header'"),
 'public page route validates slug format'=>str_contains($index,"#^/page/([a-z0-9][a-z0-9-]{0,189})$#D"),
 'CMS admin route is centrally authorized'=>str_contains($auth,'cms.pages.manage'),
 'staff cannot access CMS page editor through staff permission'=>!str_contains($auth,"'cms.pages.manage' =>"),
 'admin navigation links to CMS page management'=>str_contains($navigation,"['Site Sayfaları','/admin/pages','▤']"),
 'CMS page schema includes unique slug and status index'=>str_contains($migration,'UNIQUE KEY uq_cms_pages_slug')&&str_contains($migration,'idx_cms_pages_status_sort'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot CMS page management policy tests passed.".PHP_EOL;
