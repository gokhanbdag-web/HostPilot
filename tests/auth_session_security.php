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
$root = sys_get_temp_dir() . '/hostpilot-reset-rate-test-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true)) throw new RuntimeException('Unable to create isolated reset-rate test directory.');
define('HOSTPILOT_STORAGE', $root);
$_SERVER['REMOTE_ADDR'] = '198.51.100.22';
try {
    for ($i = 1; $i <= 5; $i++) {
        if (\HostPilot\Core\Auth::consumePasswordResetAttempt()) throw new RuntimeException('Password reset request ' . $i . ' should be allowed.');
    }
    if (!\HostPilot\Core\Auth::consumePasswordResetAttempt()) throw new RuntimeException('The sixth password reset request must be blocked.');
    $files = glob($root . '/auth-rate-limits/*.json') ?: [];
    if (count($files) !== 1) throw new RuntimeException('Password reset limiter should create one bucket.');
    $raw = (string)file_get_contents($files[0]);
    if (str_contains($raw, '198.51.100.22')) throw new RuntimeException('Raw IP must not be persisted.');
    echo 'PASS password reset IP throttling and hashed storage.' . PHP_EOL;
} finally {
    foreach (glob($root . '/auth-rate-limits/*') ?: [] as $file) @unlink($file);
    @rmdir($root . '/auth-rate-limits');
    @rmdir($root);
}
