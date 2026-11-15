<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Auth.php';

$now = 1_800_000_000;
$cases = [
    ['fresh session remains valid', $now - 60, $now - 30, $now, false],
    ['idle timeout expires at 31 minutes', $now - 3600, $now - 1861, $now, true],
    ['absolute timeout expires after 12 hours', $now - 43201, $now - 10, $now, true],
    ['absolute timeout expires even with recent activity', $now - 43201, $now - 1, $now, true],
    ['invalid timestamps fail closed', 0, $now, $now, true],
];
foreach ($cases as [$name, $created, $last, $clock, $expected]) {
    $actual = \HostPilot\Core\Auth::sessionExpired($created, $last, $clock);
    if ($actual !== $expected) throw new RuntimeException('Session policy test failed: ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}
