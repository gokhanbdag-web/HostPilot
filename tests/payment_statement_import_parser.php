<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/StatementImport.php';

use HostPilot\Payment\StatementImport;

$valid="date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX-100,Test capture,125.50,TRY,capture\n2026-11-17 11:00:00,RF-100,Partial refund,25,TRY,refund\n";
$rows=StatementImport::parseCsv("\xEF\xBB\xBF".$valid);
if(count($rows)!==2||$rows[0]['reference']!=='TX-100'||$rows[0]['amount']!=='125.50'||$rows[1]['currency']!=='TRY')throw new RuntimeException('Valid UTF-8/BOM CSV parsing failed.');
$invalid=[
 "reference,date,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,TRY,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17,TX,Test,1,TRY,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,TRY,chargeback\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,1,US,capture\n",
 "date,reference,description,amount,currency,type\n2026-11-17 10:30:00,TX,Test,-1,TRY,capture\n",
];
foreach($invalid as $csv){$failed=false;try{StatementImport::parseCsv($csv);}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Invalid CSV input was accepted.');}
$failed=false;try{StatementImport::parseCsv(str_repeat('x',StatementImport::MAX_FILE_BYTES+1));}catch(RuntimeException){$failed=true;}if(!$failed)throw new RuntimeException('Oversized CSV was accepted.');
echo "HostPilot statement import parser tests passed.\n";
