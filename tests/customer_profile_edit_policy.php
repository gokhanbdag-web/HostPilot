<?php
declare(strict_types=1);
$src=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$routes=file_get_contents(dirname(__DIR__).'/index.php');
$start=strpos($src,'public function updateCustomerProfile(): void');
$end=strpos($src,'public function updateCustomerStatus(): void',$start);
if($start===false||$end===false)throw new RuntimeException('Customer profile handler missing');
$part=substr($src,$start,$end-$start);
$checks=[
 'POST route'=>str_contains($routes,"'/admin/customers/profile'")&&str_contains($routes,'updateCustomerProfile()'),
 'Administrator permission'=>str_contains($part,"['super_admin','admin']"),
 'CSRF protection'=>str_contains($part,'Auth::verifyCsrf('),
 'Customer ownership scope'=>str_contains($part,"role='customer'"),
 'Unique username guard'=>str_contains($part,'username=:username')&&str_contains($part,'id<>:id'),
 'Username validation'=>str_contains($part,'preg_match('),
 'Parameterized update'=>str_contains($part,'name=:name')&&str_contains($part,'$update->execute('),
 'Audited change'=>str_contains($part,'customer.profile_updated'),
 'Transaction rollback'=>str_contains($part,'rollBack()'),
 'Escaped form'=>str_contains($src,"\$this->e((string)\$customer['name'])"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
