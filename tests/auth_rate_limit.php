<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Auth.php';

$root = sys_get_temp_dir() . '/hostpilot-auth-rate-test-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true)) throw new RuntimeException('Unable to create isolated rate-limit test directory.');
define('HOSTPILOT_STORAGE', $root);

$method = new ReflectionMethod(\HostPilot\Core\Auth::class, 'consumeAttempt');
$method->setAccessible(true);
$clear = new ReflectionMethod(\HostPilot\Core\Auth::class, 'clearRateLimitBucket');
$clear->setAccessible(true);

try {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
    for ($attempt = 1; $attempt <= 30; $attempt++) {
        if ($method->invoke(null) !== false) throw new RuntimeException('Attempt ' . $attempt . ' should be allowed.');
    }
    if ($method->invoke(null) !== true) throw new RuntimeException('The 31st attempt from one IP must be blocked.');

    $files = glob($root . '/auth-rate-limits/*.json') ?: [];
    if (count($files) !== 1) throw new RuntimeException('Rate limiter should create one bounded bucket per IP.');
    $stored = (string)file_get_contents($files[0]);
    if (str_contains($stored, '203.0.113.41')) throw new RuntimeException('Raw client IP must not be persisted.');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    if ($method->invoke(null) !== false) throw new RuntimeException('A different IP must have an independent bucket.');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
    $clear->invoke(null);
    if ($method->invoke(null) !== false) throw new RuntimeException('Successful authentication must clear the IP bucket.');

    echo 'PASS atomic IP rate limit, hashed storage, independent clients and successful-login reset.' . PHP_EOL;
} finally {
    foreach (glob($root . '/auth-rate-limits/*') ?: [] as $file) @unlink($file);
    @rmdir($root . '/auth-rate-limits');
    @rmdir($root);
}
