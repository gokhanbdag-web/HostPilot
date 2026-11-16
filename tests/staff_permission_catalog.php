<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Authorization.php';

use HostPilot\Core\Authorization;

$expected=[
    'dashboard.view','customers.view','reports.view','support.view','support.reply',
    'catalog.manage','orders.view','invoices.view','payments.view',
    'domains.orders.view','domains.operations.view','servers.view','services.view',
];
$catalog=Authorization::staffPermissionCatalog();
if(array_keys($catalog)!==$expected)throw new RuntimeException('Staff permission catalog does not match the supported least-privilege modules.');
$adminOnly=[
    'staff.permissions.manage','security.sessions.manage','security.mfa.manage','admin.logout',
    'email.settings.manage','email.queue.manage','system.update','domains.registrar.manage',
    'domains.export','domains.operations.manage','servers.manage','services.manage',
    'automation.manage','payments.manage','database.upgrade','privacy.requests.manage',
];
foreach($adminOnly as $permission)if(isset($catalog[$permission]))throw new RuntimeException('Admin-only permission is assignable to staff: '.$permission);
$routes=[
    ['dashboard.view','/admin','GET'],
    ['customers.view','/admin/customers','GET'],
    ['reports.view','/admin/reports','GET'],
    ['support.view','/admin/tickets','GET'],
    ['support.reply','/admin/tickets/10','POST'],
    ['catalog.manage','/admin/products','POST'],
    ['orders.view','/admin/orders','GET'],
    ['invoices.view','/admin/invoices','GET'],
    ['payments.view','/admin/payment-transactions','GET'],
    ['domains.orders.view','/admin/domains/orders','GET'],
    ['domains.operations.view','/admin/domains/renewals','GET'],
    ['servers.view','/admin/servers','GET'],
    ['services.view','/admin/services','GET'],
];
foreach($routes as [$permission,$path,$method]){
    if(Authorization::permissionFor($path,$method)!==$permission)throw new RuntimeException('Route permission mismatch: '.$path);
}
if(!Authorization::allowsAdminRequest('/admin/logout','POST',['id'=>10,'role'=>'staff']))throw new RuntimeException('Staff logout must remain available without an extra permission.');
if(!Authorization::allowsAdminRequest('/admin/security/mfa','GET',['id'=>10,'role'=>'staff']))throw new RuntimeException('Staff MFA self-service must remain available.');
if(Authorization::allowsAdminRequest('/admin/payments','GET',['id'=>10,'role'=>'staff']))throw new RuntimeException('Unassigned sensitive payment settings must remain denied.');
echo "HostPilot staff permission catalog tests passed.\n";
