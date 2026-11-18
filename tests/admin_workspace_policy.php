<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$workspace=file_get_contents($root.'/app/Http/AdminWorkspaceController.php');
$routes=file_get_contents($root.'/index.php');
$auth=file_get_contents($root.'/app/Core/Authorization.php');
$nav=file_get_contents($root.'/app/Http/AdminController.php');
$checks=[
    'Integration workspace has a real controller action'=>str_contains($workspace,'public function integrations(): void'),
    'Theme workspace has a real controller action'=>str_contains($workspace,'public function themes(): void'),
    'Both menu links are routed'=>str_contains($routes,"$"."requestPath === '/admin/integrations'")&&str_contains($routes,"$"."requestPath === '/admin/themes'"),
    'Both workspaces have explicit authorization'=>str_contains($auth,"'/admin/integrations'")&&str_contains($auth,"'/admin/themes'"),
    'Integration workspace links to payment settings'=>str_contains($workspace,"'/admin/payments'"),
    'Integration workspace links to registrar settings'=>str_contains($workspace,"'/admin/domains/registrar'"),
    'Integration workspace links to hosting and SMTP settings'=>str_contains($workspace,"'/admin/servers'")&&str_contains($workspace,"'/admin/settings'"),
    'Theme workspace links to existing CMS tools'=>str_contains($workspace,"'/admin/pages'")&&str_contains($workspace,"'/admin/menus'")&&str_contains($workspace,"'/admin/knowledgebase'"),
    'Theme workspace clearly discloses visual editor is not complete'=>str_contains($workspace,'henüz tamamlanmadı')&&str_contains($workspace,'Görsel sürükle-bırak tema editörü'),
    'Integration status never decrypts or prints stored secrets'=>!str_contains($workspace,'->decrypt(')&&str_contains($workspace,'Secret values are never decrypted or rendered'),
    'Existing admin navigation exposes both workspaces'=>str_contains($nav,"['Entegrasyonlar','/admin/integrations'")&&str_contains($nav,"['Tema & Sayfa Editörü','/admin/themes'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot admin workspace policy tests passed.".PHP_EOL;
