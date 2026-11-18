<?php
declare(strict_types=1);

/**
 * Static policy checks for Apache web-root boundaries and the first-run session bootstrap.
 */
$root = dirname(__DIR__);
$rules = file_get_contents($root . '/.htaccess');
if (!is_string($rules)) throw new RuntimeException('Unable to read root .htaccess.');

$requiredProtectedPaths = ['\\.git', '\\.github', 'app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tests', 'storage'];
foreach ($requiredProtectedPaths as $path) {
    if (!str_contains($rules, $path)) {
        throw new RuntimeException('Root .htaccess does not deny direct access to ' . $path . '.');
    }
}
foreach (['README\\.md', 'UPDATE_NOTES\\.md', 'VERSION'] as $file) {
    if (!str_contains($rules, $file)) {
        throw new RuntimeException('Root .htaccess does not deny repository file ' . $file . '.');
    }
}

$installer = file_get_contents($root . '/installer/index.php');
if (!is_string($installer)) throw new RuntimeException('Unable to read installer entry point.');
$sessionStart = strpos($installer, 'session_start();');
if ($sessionStart === false) throw new RuntimeException('Installer does not start a session.');
$sessionPolicies = [
    "ini_set('session.use_strict_mode', '1');",
    "ini_set('session.use_only_cookies', '1');",
    "ini_set('session.use_trans_sid', '0');",
    "'secure' => $isHttps",
    "'httponly' => true",
    "'samesite' => 'Lax'",
];
foreach ($sessionPolicies as $policy) {
    $position = strpos($installer, $policy);
    if ($position === false || $position > $sessionStart) {
        throw new RuntimeException('Installer session policy must be configured before session_start(): ' . $policy);
    }
}
$cookieParams = strpos($installer, 'session_set_cookie_params(');
if ($cookieParams === false || $cookieParams > $sessionStart) {
    throw new RuntimeException('Installer cookie parameters must be configured before session_start().');
}
echo 'PASS root web access restrictions and installer session bootstrap policy.' . PHP_EOL;
