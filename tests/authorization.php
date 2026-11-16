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
    ['staff permission settings are admin-only', '/admin/staff', 'GET', $staff, false],
    ['staff save endpoint is admin-only', '/admin/staff/save', 'POST', $staff, false],
    ['customer cannot access admin dashboard', '/admin', 'GET', $customer, false],
    ['anonymous cannot access admin dashboard', '/admin', 'GET', null, false],
    ['unknown admin route is denied by default', '/admin/unmapped-action', 'POST', $admin, false],
    ['wrong HTTP method is denied', '/admin/payments', 'DELETE', $admin, false],
    ['login route remains public', '/admin/login', 'GET', null, true],
    ['customer cannot access admin MFA management', '/admin/security/mfa', 'GET', $customer, false],
    ['staff can manage own MFA without an unrelated permission', '/admin/security/mfa', 'GET', $staff, true],
    ['customer cannot post to admin MFA management', '/admin/security/mfa', 'POST', $customer, false],
];
foreach ($cases as [$name, $path, $method, $user, $expected]) {
    $actual = \HostPilot\Core\Authorization::allowsAdminRequest($path, $method, $user);
    if ($actual !== $expected) throw new RuntimeException('Authorization test failed: ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
