<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Authorization.php';

$admin = ['id' => 1, 'role' => 'admin'];
$super = ['id' => 2, 'role' => 'super_admin'];
$staff = ['id' => 3, 'role' => 'staff'];
$customer = ['id' => 4, 'role' => 'customer'];
$cases = [
    ['admin may manage payments', '/admin/payments', 'POST', $admin, true],
    ['super admin may upgrade database', '/admin/database-upgrade', 'POST', $super, true],
    ['staff may view dashboard', '/admin', 'GET', $staff, true],
    ['staff may reply to support', '/admin/tickets/12', 'POST', $staff, true],
    ['staff may manage catalogue as before', '/admin/products', 'POST', $staff, true],
    ['staff cannot manage payment settings', '/admin/payments', 'POST', $staff, false],
    ['staff cannot access server credentials', '/admin/servers', 'GET', $staff, false],
    ['customer cannot access admin dashboard', '/admin', 'GET', $customer, false],
    ['anonymous cannot access admin dashboard', '/admin', 'GET', null, false],
    ['unknown admin route is denied by default', '/admin/unmapped-action', 'POST', $admin, false],
    ['wrong HTTP method is denied', '/admin/payments', 'DELETE', $admin, false],
    ['login route remains public', '/admin/login', 'GET', null, true],
];
foreach ($cases as [$name, $path, $method, $user, $expected]) {
    $actual = \HostPilot\Core\Authorization::allowsAdminRequest($path, $method, $user);
    if ($actual !== $expected) throw new RuntimeException('Authorization test failed: ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
