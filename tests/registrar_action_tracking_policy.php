<?php
declare(strict_types=1);

$api=file_get_contents(dirname(__DIR__).'/app/Core/ResellerClubClient.php');
$domain=file_get_contents(dirname(__DIR__).'/app/Http/DomainController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0008_domain_registration_action_id.sql');
$integration=file_get_contents(dirname(__DIR__).'/tests/migration_integration.php');
foreach([$api,$domain,$migration,$integration] as $source)if(!is_string($source))throw new RuntimeException('Registrar action tracking source missing.');
$checks=[
 'action polling accepts an optional EAQ identifier'=>str_contains($api,'actionStatus(string $orderId,string $eaqId=\'\')'),
 'order ID is always included in action search'=>str_contains($api,'$filters=[\'orderId\'=>$orderId,\'size\'=>10,\'page\'=>1]'),
 'EAQ filter is added only when provided'=>str_contains($api,'if($eaqId!==\'\')$filters[\'eaqId\']=$eaqId'),
 'registration response EAQ ID is validated when present'=>str_contains($api,'$data[\'eaqId\']??\'\'')&&str_contains($api,'Registrar kayıt yanıtındaki EAQ action ID geçersiz.'),
 'registration queue persists returned EAQ ID'=>str_contains($domain,'registrar_action_id=:action_id')&&str_contains($domain,'$result[\'eaqId\']'),
 'registration polling uses stored EAQ ID with order ID fallback'=>str_contains($domain,'$api->actionStatus($orderId,$eaqId)')&&str_contains($domain,'$row[\'registrar_action_id\']??\'\''),
 'migration adds nullable registrar action identifier'=>str_contains($migration,'ADD COLUMN registrar_action_id VARCHAR(40) NULL'),
 'database integration asserts registrar action column'=>str_contains($integration,'COLUMN_NAME=\'registrar_action_id\''),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot registrar registration action tracking policy tests passed.".PHP_EOL;
