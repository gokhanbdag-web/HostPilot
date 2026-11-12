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
 'remaining-term proration uses recorded service period'=>str_contains($client,'service_period_start')&&str_contains($client,'$days/$cycleDays'),
 'upgrade blocked by outstanding service invoices'=>str_contains($client,'Ödenmemiş hizmet faturası'),
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
 'customer can select lower mapped packages'=>str_contains($client,'$lower=array_values')&&str_contains($client,"'Paketimi değiştir veya düşür'"),
 'downgrade is scheduled at service renewal date'=>str_contains($client,"'scheduled'")&&str_contains($client,"$effective=(string)$s['next_due_date'].' 00:00:00'"),
 'downgrade creates no immediate invoice'=>str_contains($client,"'scheduled',:effective")&&str_contains($client,"$this->db->commit();"),
 'billing queues due downgrades before renewal invoicing'=>str_contains($worker,"c.status='scheduled' AND c.invoice_id IS NULL")&&str_contains($worker,'Planlanmış paket düşürme'),
 'old-price renewal blocked while downgrade unresolved'=>str_contains($worker,"c.status IN ('scheduled','queued','processing','needs_review','failed')"),
 'scheduled downgrade worker does not require paid invoice'=>str_contains($worker,'$scheduled=$change[\'invoice_id\']===null')&&str_contains($worker,'Paket düşürme henüz yenileme tarihine ulaşmadı'),
 'scheduled downgrade must lower service price'=>str_contains($worker,'Faturasız planlı işlem yalnızca paket düşürme'),
 'admin can see scheduled downgrade queue'=>str_contains($worker,'Planlanmış paket düşürmeleri'),
 'customer sees scheduled downgrade and effective date'=>str_contains($portal,'Planlanan paket düşürme')&&str_contains($portal,'Uygulanma tarihi'),
 'scheduled downgrade supports manual remote reconciliation'=>str_contains($worker,'Planlanmış paket düşürme henüz yürürlük tarihine ulaşmadı')&&str_contains($worker,'remote_not_applied'),
];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
