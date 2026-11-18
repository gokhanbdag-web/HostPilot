<?php
declare(strict_types=1);

$controller=file_get_contents(dirname(__DIR__).'/app/Http/StatementImportController.php');
$transport=file_get_contents(dirname(__DIR__).'/app/Payment/GatewayTransport.php');
$service=file_get_contents(dirname(__DIR__).'/app/Payment/StatementImport.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$checks=[
 'PayTR report fetch is an explicit admin action'=>str_contains($controller,'public function fetchPaytr')&&str_contains($controller,"['admin','super_admin']"),
 'PayTR report request uses the fixed official endpoint'=>str_contains($controller,'https://www.paytr.com/rapor/islem-dokumu'),
 'PayTR request token is HMAC-SHA256'=>str_contains($controller,'hash_hmac')&&str_contains($controller,'$merchantId.$start.$end.$merchantSalt'),
 'iyzico daily report fetch is an explicit admin action'=>str_contains($controller,'public function fetchIyzico'),
 'iyzico report retrieval is paginated'=>str_contains($controller,'totalPageCount')&&str_contains($controller,"'page'=>"),
 'iyzico report retrieval has a total page request cap'=>str_contains($controller,'$apiRequests > 50'),
 'iyzico API response fields are minimized before import'=>str_contains($controller,'array_intersect_key($transaction,$allowedFields)'),
 'PayTR range is capped at three days'=>str_contains($controller,'validatedReportRange(3)'),
 'iyzico range is capped at seven days'=>str_contains($controller,'validatedReportRange(7)'),
 'provider actions verify CSRF'=>substr_count($controller,'Auth::verifyCsrf')>=3,
 'provider API secrets are decrypted from the existing vault'=>str_contains($controller,'new SecretVault($applicationKey)')&&str_contains($controller,'$this->vault->decrypt'),
 'provider fetches use the existing statement importer'=>str_contains($controller,'StatementImport::import')&&str_contains($controller,"'paytr'")&&str_contains($controller,"'iyzico'"),
 'provider fetches are audited'=>str_contains($controller,'SecurityAudit::record')&&str_contains($controller,'payment.statement_imported'),
 'iyzico API JSON is normalized without buyer PII'=>str_contains($service,'private static function parseIyzicoJson')&&str_contains($service,"$transaction['paymentId']")&&!str_contains($service,"['buyerEmail']"),
 'API transport signs the fixed iyzico reporting path'=>str_contains($transport,'buildIyzicoAuthorization')&&str_contains($transport,'$path'),
 'API transport uses GET and keeps redirects disabled'=>str_contains($transport,'$options[CURLOPT_HTTPGET]=true')&&str_contains($transport,'CURLOPT_FOLLOWLOCATION=>false'),
 'report API endpoints remain host/path allowlisted'=>str_contains($transport,"'/rapor/islem-dokumu'")&&str_contains($transport,"'/v2/reporting/payment/transactions'"),
 'fetch routes are registered'=>str_contains($index,"'/admin/finance/import/paytr-fetch'")&&str_contains($index,"'/admin/finance/import/iyzico-fetch'"),
 'fetch routes require payment management permission'=>str_contains($auth,"'/admin/finance/import/paytr-fetch'")&&str_contains($auth,"'/admin/finance/import/iyzico-fetch'"),
 'review route uses the updated controller constructor'=>str_contains($index,'StatementImportController($pdo, $basePath, (string)($config'),
 'imports never change payment or invoice states'=>!str_contains($service,'UPDATE payment_transactions')&&!str_contains($service,'UPDATE invoices'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot on-demand provider report API policy tests passed.\n";
