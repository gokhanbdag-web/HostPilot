<?php
declare(strict_types=1);
$schema=file_get_contents(__DIR__.'/../app/Core/Schema.php');
$worker=file_get_contents(__DIR__.'/../app/Http/CronController.php');
$client=file_get_contents(__DIR__.'/../app/Http/ClientController.php');
$payment=file_get_contents(__DIR__.'/../app/Http/PaymentController.php');
$routes=file_get_contents(__DIR__.'/../index.php');
$checks=[
    'queue migration'=>str_contains($schema,'CREATE TABLE IF NOT EXISTS provisioning_jobs'),
    'unique create job per service'=>str_contains($schema,'uq_provision_job_service_type'),
    'customer service ownership lock'=>str_contains($client,'WHERE id=:id AND user_id=:user FOR UPDATE'),
    'customer domain submission enqueues job'=>str_contains($client,"INSERT INTO provisioning_jobs"),
    'paid services await domain input'=>str_contains($payment,"'waiting_input'"),
    'worker bearer authorization'=>str_contains($worker,'HTTP_AUTHORIZATION'),
    'worker avoids blind retry after remote call'=>str_contains($worker,"\$jobStatus=\$ambiguous?'needs_review'")&&str_contains($worker,"suspension_source='review'"),
    'worker supports all three hosting panels'=>str_contains($worker,'new CpanelWhmAdapter')&&str_contains($worker,'new DirectAdminAdapter')&&str_contains($worker,'new PleskXmlAdapter'),
    'worker route registered'=>str_contains($routes,"'/cron/provision'"),
    'customer domain route registered'=>str_contains($routes,"'/client/services/domain'"),
];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
