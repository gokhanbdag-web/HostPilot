<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/StatementImport.php';

use HostPilot\Payment\StatementImport;

$canonical="date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX-100,Test capture,125.50,TRY,capture\n2026-11-17 11:00:00,RF-100,Partial refund,25,TRY,refund\n";
$rows=StatementImport::parseCsv("\xEF\xBB\xBF".$canonical);
if(count($rows)!==2||$rows[0]['reference']!=='TX-100'||$rows[0]['amount']!=='125.50'||$rows[1]['currency']!=='TRY')throw new RuntimeException('Canonical UTF-8/BOM CSV parsing failed.');
if(count(StatementImport::parseFile($canonical,'bank'))!==2)throw new RuntimeException('Bank canonical CSV dispatch failed.');

$iyzico="transactionDate,transactionType,paymentTxId,paymentId,conversationId,paidPrice,transactionCurrency,buyerEmail,settlementReferenceCode\n"
 ."2026-11-17 13:30:00,AUTH,tx-100,pay-100,conv-100,125.50,TRY,private@example.test,settle-1\n"
 ."2026-11-17 14:00:00,REFUND,tx-ref-1,pay-100,conv-ref-1,25.00,TRY,private@example.test,settle-1\n"
 ."2026-11-17 14:30:00,PREAUTH,tx-pre-1,pay-pre-1,conv-pre-1,99.00,TRY,private@example.test,settle-1\n";
$iyzRows=StatementImport::parseFile($iyzico,'iyzico');
if(count($iyzRows)!==3||$iyzRows[0]['reference']!=='pay-100'||$iyzRows[0]['type']!=='capture'||$iyzRows[1]['reference']!=='tx-ref-1'||$iyzRows[1]['type']!=='refund'||$iyzRows[2]['type']!=='unknown')throw new RuntimeException('iyzico SFTP transaction report mapping failed.');
if($iyzRows[0]['date']!=='2026-11-17 10:30:00')throw new RuntimeException('iyzico Europe/Istanbul to UTC date conversion failed.');
$semicolon="transactionDate;transactionType;paymentTxId;paymentId;conversationId;paidPrice;transactionCurrency\n2026-11-17 13:30:00;AUTH;tx-200;pay-200;conv-200;10.00;USD\n";
if(StatementImport::parseFile($semicolon,'iyzico')[0]['currency']!=='USD')throw new RuntimeException('iyzico semicolon CSV parsing failed.');
$withSummary=$iyzico."\ntransactionCurrency,TRY\ntotalPayments,2\n";
if(count(StatementImport::parseFile($withSummary,'iyzico'))!==3)throw new RuntimeException('iyzico settlement summary rows were not safely ignored.');

$paytr=json_encode(['status'=>'success','data'=>[
 ['islem_tipi'=>'S','net_tutar'=>'9.76','kesinti_tutari'=>'0.24','islem_tutari'=>'10.00','odeme_tutari'=>'10.00','islem_tarihi'=>'17.11.2026','para_birimi'=>'TL','siparis_no'=>'merchant-oid-1'],
 ['islem_tipi'=>'I','net_tutar'=>'4.88','kesinti_tutari'=>'0.12','islem_tutari'=>'5.00','odeme_tutari'=>'5.00','islem_tarihi'=>'17.11.2026','para_birimi'=>'USD','siparis_no'=>'merchant-oid-2'],
]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$payRows=StatementImport::parseFile($paytr,'paytr');
if(count($payRows)!==2||$payRows[0]['reference']!=='merchant-oid-1'||$payRows[0]['type']!=='capture'||$payRows[0]['currency']!=='TRY'||$payRows[1]['type']!=='refund'||$payRows[1]['amount']!=='5.00')throw new RuntimeException('PayTR JSON report mapping failed.');
if($payRows[0]['date']!=='2026-11-16 21:00:00')throw new RuntimeException('PayTR local date-only UTC normalization failed.');


$iyzicoApi=json_encode(['status'=>'success','transactions'=>[
 ['transactionType'=>'PAYMENT','transactionDate'=>'2026-11-17 13:30:00','transactionId'=>9001,'paymentTxId'=>88001,'paymentId'=>77001,'paidPrice'=>'150.00','price'=>'125.00','transactionCurrency'=>'TRY','transactionStatus'=>2,'buyerEmail'=>'private@example.test'],
 ['transactionType'=>'REFUND','transactionDate'=>'2026-11-17 14:00:00','transactionId'=>9002,'paymentTxId'=>88001,'paymentId'=>77001,'paidPrice'=>'150.00','price'=>'25.00','transactionCurrency'=>'TRY','buyerEmail'=>'private@example.test'],
 ['transactionType'=>'PAYMENT','transactionDate'=>'2026-11-17 14:30:00','transactionId'=>9003,'paymentTxId'=>88002,'paymentId'=>77002,'paidPrice'=>'50.00','price'=>'50.00','transactionCurrency'=>'TRY','transactionStatus'=>1],
]],JSON_THROW_ON_ERROR);
$apiRows=StatementImport::parseFile($iyzicoApi,'iyzico');
if(count($apiRows)!==3||$apiRows[0]['type']!=='capture'||$apiRows[0]['reference']!=='77001'||$apiRows[0]['amount']!=='150.00'||$apiRows[1]['type']!=='refund'||$apiRows[1]['reference']!=='9002'||$apiRows[1]['amount']!=='25.00'||$apiRows[2]['type']!=='unknown')throw new RuntimeException('iyzico daily reporting API JSON mapping failed.');
if($apiRows[0]['date']!=='2026-11-17 10:30:00'||str_contains(json_encode($apiRows,JSON_THROW_ON_ERROR),'private@example.test'))throw new RuntimeException('iyzico API local time or PII filtering failed.');

$invalid=[
 "reference,date,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,TRY,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17,TX,Test,1,TRY,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,TRY,chargeback\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,US,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,-1,TRY,capture\n",
];
foreach($invalid as $csv){$failed=false;try{StatementImport::parseCsv($csv);}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Invalid canonical CSV input was accepted.');}
foreach([
 ['source'=>'paytr','contents'=>'{"status":"failed"}'],
 ['source'=>'paytr','contents'=>'{"status":"success","data":[{"islem_tipi":"X"}]}'],
 ['source'=>'iyzico','contents'=>"transactionDate,transactionType,paymentId,paidPrice,transactionCurrency\n2026-11-17,AUTH,p1,1,US\n"],
] as $case){$failed=false;try{StatementImport::parseFile($case['contents'],$case['source']);}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Invalid provider report was accepted.');}
$failed=false;try{StatementImport::parseCsv(str_repeat('x',StatementImport::MAX_FILE_BYTES+1));}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Oversized CSV was accepted.');
echo "HostPilot statement import parser tests passed.\n";
