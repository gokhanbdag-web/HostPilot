<?php
declare(strict_types=1);

$report=file_get_contents(dirname(__DIR__).'/app/Payment/FinanceReport.php');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/PaymentController.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$admin=file_get_contents(dirname(__DIR__).'/app/Http/AdminController.php');
$audit=file_get_contents(dirname(__DIR__).'/app/Core/SecurityAudit.php');
$checks=[
    'cash captures exclude uncollected manual refund decisions'=>str_contains($report,"COALESCE(response_code,'') <> 'manual_refund_confirmed'"),
    'captured states include full and partial refunds without double-counting refund rows'=>str_contains($report,"'success','partially_refunded','refunded'")&&!str_contains($report,'JOIN payment_refunds pr ON pr.payment_transaction_id=pt.id'),
    'refunds are grouped by their own movement date and currency'=>str_contains($report,'FROM payment_refunds')&&str_contains($report,'GROUP BY currency')&&str_contains($report,'created_at >= :refund_from'),
    'open invoice balances subtract already-paid amounts'=>str_contains($report,'GREATEST(total-amount_paid,0)'),
    'manual review amounts remain separate from captured cash'=>str_contains($report,"WHERE status='review'"),
    'movement report is date bounded and capped'=>str_contains($report,'max(1, min(5000, $limit))')&&str_contains($report,'max(1, min(500, $limit))'),
    'financial report page uses authenticated staff permission'=>str_contains($controller,'public function adminFinance():void')&&str_contains($controller,'if(!$this->isStaff())'),
    'CSV export protects spreadsheet formulas'=>str_contains($controller,'csvSafeValue')&&str_contains($controller,"preg_match('/^[=+@\\-\\t\\r]/'"),
    'CSV export is audited'=>str_contains($controller,"payment.finance_report_exported")&&str_contains($audit,"'payment.finance_report_exported'"),
    'financial report routes are registered'=>str_contains($index,"'/admin/finance'")&&str_contains($index,"'/admin/finance/export'"),
    'financial report is in admin navigation'=>str_contains($admin,"['Finans Raporları','/admin/finance','▥']"),
    'financial report uses the existing payments view permission'=>str_contains($auth,"'/admin/finance','/admin/finance/export'")&&str_contains($auth,"return 'payments.view'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot financial report policy tests passed.".PHP_EOL;
