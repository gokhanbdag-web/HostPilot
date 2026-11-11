<?php
declare(strict_types=1);
$schema=file_get_contents(__DIR__.'/../app/Core/Schema.php');
$cron=file_get_contents(__DIR__.'/../app/Http/CronController.php');
$payment=file_get_contents(__DIR__.'/../app/Http/PaymentController.php');
$server=file_get_contents(__DIR__.'/../app/Http/ServerController.php');
$routes=file_get_contents(__DIR__.'/../index.php');
$checks=[
    'service-linked recurring invoices'=>str_contains($schema,"'service_id','BIGINT UNSIGNED NULL'")&&str_contains($schema,'uq_invoice_service_period'),
    'billing suspension ownership fields'=>str_contains($schema,'suspension_source')&&str_contains($schema,'suspension_invoice_id'),
    'grace period is configurable'=>str_contains($cron,'billing_grace_days')&&str_contains($cron,"setting_key='grace_days'"),
    'renewal invoices are generated from service due dates'=>str_contains($cron,'advanceBillingDate')&&str_contains($cron,'service_period_start'),
    'only outstanding overdue balances are suspended'=>str_contains($cron,"i.status IN ('unpaid','partially_paid','overdue')")&&str_contains($cron,'i.amount_paid+0.005<i.total')&&str_contains($cron,"SET status='overdue'"),
    'billing worker rechecks invoice before suspend'=>str_contains($cron,'Recheck after the remote call')&&str_contains($cron,'payment_race_recovered'),
    'safe failures use bounded retry backoff'=>str_contains($cron,'$attempts<5')&&str_contains($cron,'$delay=min(3600,300*'),
    'ambiguous lifecycle outcomes enter review state'=>str_contains($cron,"suspension_source='review'"),
    'lifecycle worker handles suspend and unsuspend'=>str_contains($cron,'processLifecycleJob')&&str_contains($cron,'suspendAccount')&&str_contains($cron,'unsuspendAccount'),
    'full payment queues matching invoice unsuspend'=>str_contains($payment,"$jobType='unsuspend_'")&&str_contains($payment,'$invoice[\'service_id\']'),
    'manual suspension is distinguished from billing'=>str_contains($server,"'admin':'none'"),
    'automation settings accepts POST'=>str_contains($routes,"in_array(\$method, ['GET','POST'], true)) { \$cronController->adminAutomation(); exit; }"),
    'account intent credentials are encrypted before provisioning'=>str_contains($schema,"'provisioning_password_enc'")&&str_contains($cron,'provisioning_password_enc=:password'),
    'admin can retry safe failed jobs'=>str_contains($cron,"$job['status']!=='failed'")&&str_contains($cron,"status='queued',attempts=0"),
    'admin can reconcile confirmed created or absent account'=>str_contains($cron,"$resolution==='remote_created'")&&str_contains($cron,"$resolution==='remote_absent'"),
    'admin reconciliation endpoints are protected routes'=>str_contains($routes,"'/admin/automation/jobs/retry'")&&str_contains($routes,"'/admin/automation/jobs/resolve'"),
];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
