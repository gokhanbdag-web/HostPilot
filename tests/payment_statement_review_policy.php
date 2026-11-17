<?php
declare(strict_types=1);

$service=file_get_contents(dirname(__DIR__).'/app/Payment/StatementImport.php');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/StatementImportController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$audit=file_get_contents(dirname(__DIR__).'/app/Core/SecurityAudit.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261117_0003_payment_statement_review.sql');
$checks=[
 'only exact suggestions can be confirmed'=>str_contains($service, "match_status'] !== 'matched"),
 'review is single-use and locked'=>str_contains($service,'FOR UPDATE')&&str_contains($service,"['review_status'] !== 'pending'"),
 'review does not mutate payment or invoice state'=>!str_contains($service,'UPDATE payment_transactions')&&!str_contains($service,'UPDATE invoices')&&!str_contains($service,'UPDATE payment_refunds'),
 'review requires a reason for rejection or manual resolution'=>str_contains($service,"note === ''"),
 'review metadata migration exists'=>str_contains($migration,'review_status')&&str_contains($migration,'reviewed_by')&&str_contains($migration,'review_note'),
 'review action requires CSRF'=>str_contains($controller,'Auth::verifyCsrf'),
 'review action is audited'=>str_contains($controller,'payment.statement_movement_reviewed')&&str_contains($audit,"'payment.statement_movement_reviewed'"),
 'review route is registered'=>str_contains($index,"'/admin/finance/import/movements/review'"),
 'review route requires payment management permission'=>str_contains($auth,"'/admin/finance/import/movements/review'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot statement review policy tests passed.\n";
