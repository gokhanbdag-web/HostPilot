<?php
declare(strict_types=1);
$schema=file_get_contents(__DIR__.'/../app/Core/Schema.php');
$worker=file_get_contents(__DIR__.'/../app/Http/CronController.php');
$client=file_get_contents(__DIR__.'/../app/Http/ClientController.php');
$payment=file_get_contents(__DIR__.'/../app/Http/PaymentController.php');
$routes=file_get_contents(__DIR__.'/../index.php');
$server=file_get_contents(__DIR__.'/../app/Http/ServerController.php');
$checks=[
    'queue migration'=>str_contains($schema,'CREATE TABLE IF NOT EXISTS provisioning_jobs'),
    'unique create job per service'=>str_contains($schema,'uq_provision_job_service_type'),
    'customer service ownership lock'=>str_contains($client,'WHERE id=:id AND user_id=:user FOR UPDATE'),
    'customer domain submission enqueues job'=>str_contains($client,"INSERT INTO provisioning_jobs"),
    'paid services await domain input'=>str_contains($payment,"'waiting_input'"),
    'worker bearer authorization'=>str_contains($worker,'CronAuthenticator::isAuthorized')&&!str_contains($worker,"\\$_GET['token']"),
    'worker avoids blind retry after remote call'=>str_contains($worker,"\$jobStatus=\$ambiguous?'needs_review'")&&str_contains($worker,"suspension_source='review'"),
    'worker supports all three hosting panels'=>str_contains($worker,'new CpanelWhmAdapter')&&str_contains($worker,'new DirectAdminAdapter')&&str_contains($worker,'new PleskXmlAdapter'),
    'worker route registered'=>str_contains($routes,"'/cron/provision'"),
    'customer domain route registered'=>str_contains($routes,"'/client/services/domain'"),
    'admin termination route registered'=>str_contains($routes,"'/admin/services/terminate'"),
    'termination requires exact domain confirmation'=>str_contains($server,"hash_equals((string)\$service['remote_domain'],\$confirmation)"),
    'termination is queued instead of deleting in web request'=>str_contains($server,"INSERT INTO provisioning_jobs(service_id,job_type,status,attempts,available_at,created_at,updated_at)"),
    'worker dispatches termination jobs'=>str_contains($worker,'processTerminationJob')&&str_contains($worker,"terminate_[0-9]{14}_[a-f0-9]{6}"),
    'ambiguous termination requires manual reconciliation'=>str_contains($worker,"resolution\" value=\"remote_deleted\"")&&str_contains($worker,"resolution\" value=\"remote_present\""),
    'termination clears stored hosting password after confirmed deletion'=>str_contains($worker,"provisioning_status='terminated',provisioning_error=NULL,remote_password_enc=NULL"),

];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
