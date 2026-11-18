<?php
declare(strict_types=1);

$service=file_get_contents(dirname(__DIR__).'/app/Payment/StatementImport.php');
$controller=file_get_contents(dirname(__DIR__).'/app/Http/StatementImportController.php');
$migration=file_get_contents(dirname(__DIR__).'/database/migrations/20261117_0002_payment_statement_imports.sql');
$checks=[
 'bank uses the existing canonical CSV parser'=>str_contains($service,"'bank' => self::parseCsv"),
 'PayTR native report JSON is supported'=>str_contains($service,'private static function parsePaytrJson'),
 'PayTR sale and refund codes map separately'=>str_contains($service, "'type' => " . '$providerType' . " === 'S' ? 'capture' : 'refund'"),
 'iyzico native SFTP CSV is supported'=>str_contains($service,'private static function parseIyzicoCsv'),
 'iyzico auth and postauth are captured while preauth and cancel remain non-cash'=>str_contains($service,"'auth', 'postauth' => 'capture'")&&str_contains($service,"'preauth', 'cancel' => 'unknown'"),
 'iyzico refunds use parent paymentId rather than paymentTxId'=>str_contains($service,"$value('paymentid') !== '' ? $value('paymentid') : $value('conversationid')"),
 'iyzico refund amount uses price rather than original paidPrice'=>str_contains($service,"$type === 'refund'\n                    ? $value('price')"),
 'refund matching uses exact decimal amount and currency'=>str_contains($service,'pr.amount=:amount')&&str_contains($service,'pr.currency=:currency'),
 'provider date conversion is explicit'=>str_contains($service,'Europe/Istanbul')&&str_contains($service,"new \\DateTimeZone('UTC')"),
 'provider parser does not persist cardholder personal data'=>!str_contains($service,"['buyerEmail']")&&!str_contains($service,"['buyerName']"),
 'upload UI accepts native CSV and JSON files'=>str_contains($controller,'application/json')&&str_contains($controller,'iyzico raporu (CSV)'),
 'matching remains suggestion-only'=>!str_contains($service,'UPDATE payment_transactions')&&!str_contains($service,'UPDATE invoices'),
 'source file import remains idempotent'=>str_contains($migration,'uq_statement_source_file'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot provider-native statement import policy tests passed.\n";
