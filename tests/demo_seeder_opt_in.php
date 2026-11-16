<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../index.php');
$installer = file_get_contents(__DIR__ . '/../installer/index.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(is_string($index) && str_contains($index, "!empty(\$config['app']['demo_data_enabled'])"),
    'runtime demo seeding requires explicit installation opt-in');
$assert(!str_contains($index, "if (!is_file(\$maintenanceFile)) {\n    try {\n        \\HostPilot\\Core\\DemoSeeder::seed"),
    'runtime does not seed demo records unconditionally');
$assert(is_string($installer) && str_contains($installer, "'demo_data_enabled' => \$demoDataEnabled"),
    'installer persists the explicit demo data choice');
$assert(str_contains($installer, "\$demoDataEnabled = isset(\$_POST['demo_data']) && (string)\$_POST['demo_data'] === '1';"),
    'unchecked demo option resolves to disabled');
$assert(str_contains($installer, 'name="demo_data" value="1"'),
    'installer exposes an opt-in checkbox');
$assert(str_contains($installer, 'Varsayılan olarak kapalıdır'),
    'installer clearly explains the safe default');

echo "HostPilot demo seeder opt-in policy tests passed.\n";
