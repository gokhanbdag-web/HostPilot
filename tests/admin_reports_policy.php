<?php
declare(strict_types=1);

$source=(string)file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$start=strpos($source,'public function reports(): void');
$end=strpos('public function tickets(): void',$source,$start===false?0:$start);
if($start===false||$end===false)throw new RuntimeException('Admin reports method was not found.');
$method=substr($source,$start,$end-$start);
$checks=[
    'Reports are based on persisted business records'=>str_contains($method,'FROM orders')&&str_contains($method,'FROM invoices')&&str_contains($method,'FROM services')&&str_contains($method,'FROM tickets'),
    'Demo orders and invoices are excluded from financial reports'=>substr_count($method,"NOT LIKE 'DEMO-%'")>=4,
    'Demo customer and support fixtures are excluded'=>str_contains($method,"demo.musteri%@example.invalid")&&str_contains($method,"DEMO-TKT-%"),
    'Financial totals are grouped by currency'=>str_contains($method,'GROUP BY currency')&&str_contains($method,'SUM(amount_paid)')&&str_contains($method,'outstanding'),
    'Order totals are grouped by currency'=>str_contains($method,'GROUP BY currency ORDER BY currency'),
    'Top products are separated by currency'=>str_contains($method,'GROUP BY o.currency,oi.product_id,oi.product_name'),
    'Customer growth uses real registration timestamps'=>str_contains($method,'DATE_FORMAT(created_at')&&str_contains($method,'INTERVAL 6 MONTH'),
    'Support and service statuses use real records'=>str_contains($method,'FROM services s')&&str_contains($method,'FROM tickets GROUP BY status'),
    'Report view does not expose customer email or personal identifiers'=>!str_contains($method,'u.email')&&!str_contains($method,'u.name'),
    'Report page is no longer labelled as demo-only'=>!str_contains($method,'Demo işletme raporları')&&str_contains($method,'İşletme raporları'),
    'Currency totals are not hard-coded to TRY'=>!str_contains($method,'₺'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot real business reports policy tests passed.".PHP_EOL;
