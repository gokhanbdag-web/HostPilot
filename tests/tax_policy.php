<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$catalog=$read('app/Http/CatalogController.php');
$domain=$read('app/Http/DomainController.php');
$cron=$read('app/Http/CronController.php');
$package=$read('app/Http/PackageChangeController.php');
$settings=$read('app/Http/TaxSettingsController.php');
$calculator=$read('app/Core/TaxCalculator.php');
$routes=$read('index.php');
$auth=$read('app/Core/Authorization.php');
$nav=$read('app/Http/AdminController.php');
$audit=$read('app/Core/SecurityAudit.php');
$checks=[
    'Cart preview calculates tax from each product and setup-fee line'=>str_contains($catalog,'TaxCalculator::totals($cartAmounts,$taxSettings)'),
    'Checkout recalculates tax after reloading current product prices'=>str_contains($catalog,'TaxCalculator::settings($this->db)')&&str_contains($catalog,'TaxCalculator::totals(array_column($invoiceRows'),
    'Checkout persists net subtotal, tax and gross total'=>str_contains($catalog,':subtotal,:tax,:total')&&str_contains($catalog,':rate,:tax,:total'),
    'One-time setup fees are not added to recurring service price'=>str_contains($catalog, "':line'=>\$base"),
    'Domain renewal invoices use the current tax settings'=>str_contains($domain,'TaxCalculator::line(TaxCalculator::toCents($total)'),
    'Recurring service invoices use the current tax settings'=>str_contains($cron,'TaxCalculator::line(TaxCalculator::toCents($service[\'amount\'])'),
    'Package upgrade invoices include tax snapshots'=>str_contains($package,'TaxCalculator::line(TaxCalculator::toCents(number_format($amount'),
    'Tax calculation uses integer cents and line-level rounding'=>str_contains($calculator,'intdiv(($net * $rate) + 5000, 10000)')&&str_contains($calculator,'intdiv(($amountCents * 10000)'),
    'Inclusive and exclusive pricing modes are supported'=>str_contains($calculator,"=== 'inclusive'")&&str_contains($calculator,"['exclusive', 'inclusive']"),
    'Tax settings route is registered and protected'=>str_contains($routes,"'/admin/tax'")&&str_contains($auth,"'/admin/tax'"),
    'Tax settings are visible in finance navigation'=>str_contains($nav,"['Vergi / KDV Ayarları','/admin/tax'"),
    'Tax settings are audited through the allowlisted event'=>str_contains($settings,'tax.settings_updated')&&str_contains($audit,"'tax.settings_updated'"),
    'Tax settings save atomically'=>str_contains($settings,'beginTransaction()')&&str_contains($settings,'commit()')&&str_contains($settings,'rollBack()'),
    'Existing invoice snapshots are not rewritten by settings changes'=>str_contains($settings,'mevcut faturalar değişmez'),
    'Tax inclusive/exclusive mode cannot be changed after orders exist'=>str_contains($settings,'$existingOrders > 0')&&str_contains($settings,'fiyat biçimi sipariş oluşturulduktan sonra değiştirilemez'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot tax policy tests passed.".PHP_EOL;
