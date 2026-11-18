<?php
declare(strict_types=1);

$controller=file_get_contents(dirname(__DIR__).'/app/Http/KnowledgebaseAdminController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$admin=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
foreach([$controller,$index,$auth,$admin] as $source)if(!is_string($source))throw new RuntimeException('Knowledgebase admin source missing.');
$checks=[
 'admin controller requires admin or super-admin role'=>str_contains($controller,"['admin','super_admin']"),
 'create and edit actions require CSRF validation'=>str_contains($controller,"Auth::verifyCsrf($_POST['_token']??null)"),
 'article writes use prepared statements'=>str_contains($controller,'INSERT INTO knowledgebase_articles')&&str_contains($controller,'UPDATE knowledgebase_articles'),
 'article deletion requires explicit confirmation'=>str_contains($controller,"$_POST['confirm_delete']")&&str_contains($controller,"'1'"),
 'article status is limited to draft or published'=>str_contains($controller,"['draft','published']"),
 'article slug is normalized and validated'=>str_contains($controller,'function slug(string $value)')&&str_contains($controller,'^[a-z0-9]+(?:-[a-z0-9]+)*$'),
 'article text limits are enforced'=>str_contains($controller,'50000')&&str_contains($controller,'2000'),
 'article create update and delete are audited'=>str_contains($controller,'knowledgebase.article_created')&&str_contains($controller,'knowledgebase.article_updated')&&str_contains($controller,'knowledgebase.article_deleted'),
 'new article route is registered'=>str_contains($index,"'/admin/knowledgebase/new'"),
 'article edit route only accepts numeric IDs'=>str_contains($index,"#^/admin/knowledgebase/([1-9][0-9]*)$#D"),
 'central authorization maps knowledgebase admin routes'=>str_contains($auth,'knowledgebase.manage'),
 'staff cannot manage articles through a staff permission'=>!str_contains($auth,"'knowledgebase.manage' =>"),
 'admin navigation links to knowledgebase management'=>str_contains($admin,"['Bilgi Bankası','/admin/knowledgebase','▧']"),
 'published article preview is linked from editor'=>str_contains($controller,"'/knowledgebase/'.(string)$article['slug']"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot admin knowledgebase management policy tests passed.".PHP_EOL;
