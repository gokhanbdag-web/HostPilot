<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$admin=(string)file_get_contents($root.'/app/Http/AdminController.php');
$server=(string)file_get_contents($root.'/app/Http/ServerController.php');
$routes=(string)file_get_contents($root.'/index.php');
$auth=(string)file_get_contents($root.'/app/Core/Authorization.php');
$audit=(string)file_get_contents($root.'/app/Core/SecurityAudit.php');
$customerStart=strpos($admin,'public function viewCustomer');
$customerEnd=strpos($admin,'public function updateCustomerStatus',$customerStart===false?0:$customerStart);
$statusEnd=strpos($admin,'public function reports(): void',$customerEnd===false?0:$customerEnd);
$serviceStart=strpos($server,'public function viewService');
$serviceEnd=strpos($server,'public function provision():void',$serviceStart===false?0:$serviceStart);
if($customerStart===false||$customerEnd===false||$statusEnd===false||$serviceStart===false||$serviceEnd===false)throw new RuntimeException('Customer/service detail actions were not found.');
$customerView=substr($admin,$customerStart,$customerEnd-$customerStart);
$statusAction=substr($admin,$customerEnd,$statusEnd-$customerEnd);
$serviceView=substr($server,$serviceStart,$serviceEnd-$serviceStart);
$checks=[
    'Customer list links to customer detail pages'=>str_contains($admin,"/admin/customers/'.(int)$row['id']"),
    'Customer detail shows orders invoices services and tickets'=>str_contains($customerView,'FROM orders')&&str_contains($customerView,'FROM invoices')&&str_contains($customerView,'FROM services')&&str_contains($customerView,'FROM tickets'),
    'Customer detail excludes demo fixtures'=>str_contains($customerView,"NOT LIKE 'DEMO-%'")&&str_contains($customerView,"DEMO-TKT-%"),
    'Only administrators can change customer status'=>str_contains($statusAction,"['super_admin','admin']")&&str_contains($statusAction,'Auth::verifyCsrf'),
    'Customer status changes revoke sessions and are audited'=>str_contains($statusAction,'auth_version=auth_version+1')&&str_contains($statusAction,'customer.status_updated'),
    'Customer disable does not automatically terminate services'=>!str_contains($statusAction,'UPDATE services')&&!str_contains($statusAction,'DELETE FROM services'),
    'Service list links to service detail pages'=>str_contains($server,"/admin/services/'.(int)$row['id']"),
    'Service detail shows invoice and provisioning history'=>str_contains($serviceView,'FROM invoices')&&str_contains($serviceView,'FROM provisioning_logs'),
    'Service detail does not decrypt or render remote passwords'=>!str_contains($serviceView,'decrypt')&&!str_contains($serviceView,'remote_password_enc'),
    'Service detail offers only existing CSRF-protected lifecycle actions'=>str_contains($serviceView,'/admin/services/lifecycle')&&str_contains($serviceView,'/admin/services/terminate')&&str_contains($serviceView,'Auth::csrfToken()'),
    'Customer and service detail routes are registered'=>str_contains($routes,'viewCustomer((int)$customerMatch[1])')&&str_contains($routes,'viewService((int)$serviceMatch[1])'),
    'Detail routes and status mutation have explicit permissions'=>str_contains($auth,'customers.manage')&&str_contains($auth,'customers.view')&&str_contains($auth,'services.view'),
    'Customer status event is allowlisted'=>str_contains($audit,"'customer.status_updated'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot customer and service admin policy tests passed.".PHP_EOL;
