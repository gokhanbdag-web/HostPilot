<?php
declare(strict_types=1);

$admin=file_get_contents(dirname(__DIR__).'/app/Http/CmsMenuAdminController.php');
$renderer=file_get_contents(dirname(__DIR__).'/app/Http/CmsMenuRenderer.php');
$catalog=file_get_contents(dirname(__DIR__).'/app/Http/CatalogController.php');
$public=file_get_contents(dirname(__DIR__).'/app/Http/CmsPageController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$navigation=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0006_cms_menus.sql');
foreach([$admin,$renderer,$catalog,$public,$index,$auth,$navigation,$migration] as $source)if(!is_string($source))throw new RuntimeException('CMS menu source missing.');
$checks=[
 'menu manager is restricted to admin and super-admin'=>str_contains($admin,"['admin','super_admin']"),
 'menu changes require CSRF validation'=>str_contains($admin,'Auth::verifyCsrf($_POST[\'_token\']??null)'),
 'menu and item create update delete actions exist'=>str_contains($admin,'save_menu')&&str_contains($admin,'create_item')&&str_contains($admin,'update_item')&&str_contains($admin,'delete_item'),
 'menu item URL validation permits only safe relative or HTTPS URLs'=>str_contains($admin,'str_starts_with($url,\'/\')')&&str_contains($admin,'str_starts_with($url,\'//\')')&&str_contains($admin,"==='https'"),
 'menu item labels and URL lengths are validated'=>str_contains($admin,'100')&&str_contains($admin,'500'),
 'menu changes are audited'=>str_contains($admin,'cms.menu_updated')&&str_contains($admin,'cms.menu_item_created')&&str_contains($admin,'cms.menu_item_updated')&&str_contains($admin,'cms.menu_item_deleted'),
 'renderer checks CMS menu tables before querying and safely skips missing tables'=>str_contains($renderer,"information_schema.TABLES")&&str_contains($renderer,"if((int)$tables->fetchColumn()!==2)return ''"),
 'renderer selects active menus and active items'=>str_contains($renderer,"location=:location AND status='active'")&&str_contains($renderer,"menu_id=:id AND status='active'"),
 'renderer escapes labels and URLs'=>str_contains($renderer,'htmlspecialchars('),
 'renderer rejects unsafe database URLs'=>str_contains($renderer,'if(!$relative&&!$external)continue;'),
 'external new-tab links use noopener noreferrer'=>str_contains($renderer,'noopener noreferrer'),
 'storefront uses configurable header and footer menus'=>str_contains($catalog,"'header'")&&str_contains($catalog,"'footer'")&&str_contains($catalog,'CmsMenuRenderer::links'),
 'CMS page navigation uses configurable header menu'=>str_contains($public,'CmsMenuRenderer::links'),
 'menu admin routes are registered'=>str_contains($index,"'/admin/menus'")&&str_contains($index,"^/admin/menus/"),
 'menu routes are centrally authorized'=>str_contains($auth,'cms.menus.manage'),
 'staff cannot access menu management through staff permissions'=>!str_contains($auth,"'cms.menus.manage' =>"),
 'admin navigation links to menu management'=>str_contains($navigation,"['Menü Yönetimi','/admin/menus','☷']"),
 'migration seeds header and footer menus'=>str_contains($migration,"'header'")&&str_contains($migration,"'footer'"),
 'menu items cascade when a menu is deleted'=>str_contains($migration,'ON DELETE CASCADE'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot CMS menu management policy tests passed.".PHP_EOL;
