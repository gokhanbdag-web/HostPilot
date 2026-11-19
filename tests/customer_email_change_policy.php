<?php
declare(strict_types=1);
$admin=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$client=file_get_contents(dirname(__DIR__).'/app/Http/ClientController.php');
$routes=file_get_contents(dirname(__DIR__).'/index.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261119_0001_customer_email_changes.sql');
$start=strpos($admin,'public function requestCustomerEmailChange(): void');
$end=strpos($admin,'public function updateCustomerStatus(): void',$start);
$request=substr($admin,$start,$end-$start);
$start=strpos($client,'public function confirmEmailChange(): void');
$end=strpos($client,'public function verifyEmail(): void',$start);
$confirm=substr($client,$start,$end-$start);
$checks=[
 'Routes present'=>str_contains($routes,'requestCustomerEmailChange()')&&str_contains($routes,'confirmEmailChange()'),
 'Admin role'=>str_contains($request,"['super_admin','admin']"),
 'CSRF protected'=>str_contains($request,'Auth::verifyCsrf(')&&str_contains($confirm,'Auth::verifyCsrf('),
 'Token hash'=>str_contains($request,"hash('sha256',$token)")&&str_contains($confirm,"hash('sha256',$token)"),
 'Expiration and single use'=>str_contains($confirm,'c.used_at IS NULL')&&str_contains($confirm,'c.expires_at>UTC_TIMESTAMP()'),
 'Unique address'=>str_contains($request,'email=:email AND id<>:id')&&str_contains($confirm,'email=:email AND id<>:id'),
 'Session revocation'=>str_contains($confirm,'auth_version=auth_version+1'),
 'Audited'=>str_contains($request,'customer.email_change_requested')&&str_contains($confirm,'customer.email_changed'),
 'Migration'=>str_contains($migration,'CREATE TABLE IF NOT EXISTS customer_email_changes'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
