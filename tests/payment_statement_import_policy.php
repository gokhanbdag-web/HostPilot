<?php
declare(strict_types=1);

$service=file_get_contents(dirname(__DIR__).'/app/Payment/StatementImport.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261117_0002_payment_statement_imports.sql');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/StatementImportController.php');
$index=file_get_contents(dirname(__DIR__).'/index.php');
$auth=file_get_contents(dirname(__DIR__).'/app/Core/Authorization.php');
$audit=file_get_contents(dirname(__DIR__).'/app/Core/SecurityAudit.php');
$checks=[
 'bank CSV parser requires canonical headers'=>str_contains($service,"['date','reference','description','amount','currency','type']"),
 'all report uploads have a strict size limit'=>str_contains($service,'MAX_FILE_BYTES = 2097152'),
 'all report imports have a row limit'=>str_contains($service,'MAX_ROWS = 5000'),
 'report values are validated before persistence'=>str_contains($service,'preg_match')&&str_contains($service,'DateTimeImmutable'),
 'PayTR report JSON parser is available'=>str_contains($service,'parsePaytrJson')&&str_contains($service,"'S' => 'capture'")&&str_contains($service,"'I' => 'refund'"),
 'iyzico SFTP report parser is available'=>str_contains($service,'parseIyzicoCsv')&&str_contains($service,"'auth','postauth' => 'capture'")&&str_contains($service,"'refund' => 'refund'"),
 'provider parsers exclude buyer personal data'=>!str_contains($service,"$get('buyeremail')")&&!str_contains($service,"$get('buyername')"),
 'source-specific parser is selected'=>str_contains($service,'self::parseUpload($source, $contents)'),
 'duplicate source files are rejected'=>str_contains($migration,'uq_statement_source_file'),
 'statement rows are idempotent within an import'=>str_contains($migration,'uq_statement_import_row'),
 'matching never updates payment or invoice state'=>!str_contains($service,'UPDATE payment_transactions')&&!str_contains($service,'UPDATE invoices'),
 'amount mismatches are kept separate'=>str_contains($service,'amount_mismatch'),
 'ambiguous references are not auto-matched'=>str_contains($service,"'ambiguous'"),
 'report imports require CSRF validation'=>str_contains($controller,'Auth::verifyCsrf'),
 'report imports are audited'=>str_contains($controller,"payment.statement_imported")&&str_contains($audit,"'payment.statement_imported'"),
 'provider upload choices are documented in admin UI'=>str_contains($controller,'PayTR işlem dökümü (JSON)')&&str_contains($controller,'iyzico SFTP raporu (CSV)'),
 'admin import route is registered'=>str_contains($index,"'/admin/finance/import'"),
 'route uses existing payment view permission'=>str_contains($auth,"'/admin/finance/import'")&&str_contains($auth, "if (\$path === '/admin/finance/import' && \$method === 'POST')"),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot statement import policy tests passed.\n";
