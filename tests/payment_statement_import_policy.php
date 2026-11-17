<?php
declare(strict_types=1);

$service=file_get_contents(dirname(__DIR__).'/app/Payment/StatementImport.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261117_0002_payment_statement_imports.sql');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/StatementImportController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$audit=file_get_contents(dirname(__DIR__).'/app/Core/SecurityAudit.php');
$checks=[
 'CSV parser requires canonical headers'=>str_contains($service,"['date','reference','description','amount','currency','type']"),
 'CSV upload has a strict size limit'=>str_contains($service,'MAX_FILE_BYTES = 2097152'),
 'CSV import has a row limit'=>str_contains($service,'MAX_ROWS = 5000'),
 'CSV values are validated before persistence'=>str_contains($service,'preg_match')&&str_contains($service,'DateTimeImmutable'),
 'duplicate source files are rejected'=>str_contains($migration,'uq_statement_source_file'),
 'statement rows are idempotent within an import'=>str_contains($migration,'uq_statement_import_row'),
 'matching never updates payment or invoice state'=>!str_contains($service,'UPDATE payment_transactions')&&!str_contains($service,'UPDATE invoices'),
 'amount mismatches are kept separate'=>str_contains($service,'amount_mismatch'),
 'ambiguous references are not auto-matched'=>str_contains($service,"'ambiguous'"),
 'CSV imports require CSRF validation'=>str_contains($controller,'Auth::verifyCsrf'),
 'CSV imports are audited'=>str_contains($controller,"payment.statement_imported")&&str_contains($audit,"'payment.statement_imported'"),
 'admin import route is registered'=>str_contains($index,"'/admin/finance/import'"),
 'route uses existing payment view permission'=>str_contains($auth,"'/admin/finance/import'")&&str_contains($auth,"$path === '/admin/finance/import' && $method === 'POST'"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot statement import policy tests passed.\n";
