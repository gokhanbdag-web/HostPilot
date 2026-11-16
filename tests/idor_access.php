<?php
declare(strict_types=1);

/**
 * Source-level authorization regression tests. These assert that resource lookups
 * keep the authenticated owner in the same SQL predicate and that staff ticket
 * detail/download paths retain department checks.
 */
$root = dirname(__DIR__);
$checks = [
    ['customer service list is owner-scoped', 'app/Http/ClientController.php', 'WHERE s.user_id=:user'],
    ['customer service update locks only owned service', 'app/Http/ClientController.php', 'WHERE id=:id AND user_id=:user FOR UPDATE'],
    ['customer ticket list is owner-scoped', 'app/Http/ClientController.php', 'FROM tickets WHERE user_id=:id'],
    ['customer ticket detail is owner-scoped', 'app/Http/ClientController.php', 'WHERE id=:id AND user_id=:uid LIMIT 1'],
    ['customer domain list is owner-scoped', 'app/Http/DomainController.php', 'WHERE d.user_id=:user'],
    ['customer nameserver request is owner-scoped', 'app/Http/DomainController.php', 'WHERE id=:id AND user_id=:user FOR UPDATE'],
    ['customer domain sync is owner-scoped', 'app/Http/DomainController.php', 'WHERE id=:id AND user_id=:user LIMIT 1'],
    ['customer domain renewal is owner-scoped', 'app/Http/DomainController.php', 'WHERE d.id=:id AND d.user_id=:user'],
    ['package change reads only owned service', 'app/Http/PackageChangeController.php', 'WHERE s.id=:id AND s.user_id=:user LIMIT 1'],
    ['package change create locks only owned service', 'app/Http/PackageChangeController.php', 'WHERE s.id=:id AND s.user_id=:user FOR UPDATE'],
    ['package change cancellation is owner-scoped', 'app/Http/PackageChangeController.php', 'WHERE c.id=:id AND s.user_id=:user FOR UPDATE'],
    ['invoice payment is owner-scoped', 'app/Http/PaymentController.php', 'WHERE id=:id AND user_id=:uid LIMIT 1'],
    ['privacy export filters every exported table by owner', 'app/Http/PrivacyController.php', 'WHERE user_id=:uid ORDER BY id ASC LIMIT 10000'],
    ['staff ticket detail checks assigned department', 'app/Http/AdminController.php', "in_array((string)$ticket['department'], $this->staffDepartments((int)$user['id']), true)"],
    ['staff attachment download checks assigned department', 'app/Http/TicketAttachmentController.php', 'staff_departments WHERE user_id=:u AND department=:d'],
    ['customer attachment download joins ticket owner', 'app/Http/TicketAttachmentController.php', 't.user_id=:uid'],
];
foreach ($checks as [$label, $path, $needle]) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || !str_contains($source, $needle)) {
        throw new RuntimeException('IDOR regression: missing owner/department boundary: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
