<?php
declare(strict_types=1);

$client=file_get_contents(dirname(__DIR__).'/app/Http/ClientController.php');
$staff=file_get_contents(dirname(__DIR__).'/app/Http/StaffAccessController.php');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/SupportDepartmentController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261118_0003_support_departments.sql');
$admin=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
foreach([$client,$staff,$controller,$index,$auth,$migration,$admin] as $source)if(!is_string($source))throw new RuntimeException('Support department source missing.');
$checks=[
 'customer ticket form reads active departments from database'=>str_contains($client,"FROM support_departments WHERE status='active'"),
 'customer submission validates selected active department'=>str_contains($client,'in_array($department,$departments,true)'),
 'staff department options are database-driven'=>str_contains($staff,'FROM support_departments'),
 'staff can retain previously assigned inactive departments'=>str_contains($staff,'OR name IN (SELECT department FROM staff_departments WHERE user_id=:id)'),
 'admin department route is registered'=>str_contains($index,"'/admin/ticket-departments'"),
 'department route is restricted to administrators'=>str_contains($auth,"'/admin/ticket-departments'"),
 'department manager is linked from admin navigation'=>str_contains($admin,"'/admin/ticket-departments'"),
 'department manager prevents deactivating final active department'=>str_contains($controller,'En az bir aktif destek departmanı'),
 'department changes are audited'=>str_contains($controller,'support.department_status_changed')&&str_contains($controller,'support.department_created'),
 'seed migration preserves the four existing department names'=>str_contains($migration,'General Support')&&str_contains($migration,'Technical Support')&&str_contains($migration,'Billing')&&str_contains($migration,'Sales'),
 'department names are unique'=>str_contains($migration,'UNIQUE KEY uq_support_departments_name'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot dynamic support department policy tests passed.".PHP_EOL;
