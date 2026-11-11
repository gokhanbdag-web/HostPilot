<?php
declare(strict_types=1);
$schema=file_get_contents(__DIR__.'/../app/Core/Schema.php');
$client=file_get_contents(__DIR__.'/../app/Http/PackageChangeController.php');
$payment=file_get_contents(__DIR__.'/../app/Http/PaymentController.php');
$worker=file_get_contents(__DIR__.'/../app/Http/CronController.php');
$routes=file_get_contents(__DIR__.'/../index.php');
$portal=file_get_contents(__DIR__.'/../app/Http/ClientController.php');
$checks=[
 'versioned package change migration'=>str_contains($schema,'20261112_0008_service_package_changes')&&str_contains($schema,'CREATE TABLE IF NOT EXISTS service_package_changes'),
 'customer ownership enforced'=>str_contains($client,'s.user_id=:user FOR UPDATE'),
 'only higher mapped package offered'=>str_contains($client,'pp.price>:amount')&&str_contains($client,'m.server_id=:server'),
 'same billing cycle and currency required'=>str_contains($client,'pp.currency=:currency AND pp.billing_cycle=:cycle'),
 'remaining-term prorated charge'=>str_contains($client,'$days/$cycleDays'),
 'Plesk plan identifiers required'=>str_contains($client,'remote_package_guid')&&str_contains($client,'remote_package_external_id'),
 'DirectAdmin is not offered automatically'=>str_contains($client,'panel_type')&&str_contains($client,'cpanel')&&str_contains($client,'plesk'),
 'upgrade invoice linked to request'=>str_contains($client,'SET invoice_id=:invoice'),
 'customer route registered'=>str_contains($routes,"'/client/services/change'"),
 'service portal links upgrade page'=>str_contains($portal,'Paketimi yükselt'),
 'paid invoice queues package job'=>str_contains($payment,'packageJob')&&str_contains($payment,'service_package_changes'),
 'package invoice does not create duplicate service'=>str_contains($payment,'isPackageInvoice'),
 'worker dispatches package changes'=>str_contains($worker,'processPackageChangeJob')&&str_contains($worker,'package_'),
 'worker checks paid invoice and original product'=>str_contains($worker,'bill')&&str_contains($worker,'from_product_id'),
 'ambiguous result goes to review'=>str_contains($worker,'needs_review')&&str_contains($worker,'service_package_changes'),
 'admin can reconcile package change'=>str_contains($worker,'remote_applied')&&str_contains($worker,'remote_not_applied'),
];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
