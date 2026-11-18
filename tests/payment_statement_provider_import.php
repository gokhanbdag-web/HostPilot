<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/StatementImport.php';

use HostPilot\Payment\StatementImport;

$paytr = [
    'status'=>'success',
    'transactions'=>[
        ['islem_tipi'=>'S','net_tutar'=>'97.65','islem_tutari'=>'100.00','islem_tarihi'=>'13.01.2026','para_birimi'=>'TL','siparis_no'=>'HP-PAYTR-100','odeme_tipi'=>'KART'],
        ['islem_tipi'=>'I','net_tutar'=>'24.40','islem_tutari'=>'25.00','islem_tarihi'=>'14.01.2026','para_birimi'=>'USD','siparis_no'=>'HP-PAYTR-101','odeme_tipi'=>'KART'],
    ],
];
$paytrRows = StatementImport::parsePaytrJson(json_encode($paytr, JSON_THROW_ON_ERROR));
if (count($paytrRows)!==2 || $paytrRows[0]['type']!=='capture' || $paytrRows[0]['currency']!=='TRY' || $paytrRows[0]['amount']!=='100.00' || $paytrRows[0]['reference']!=='HP-PAYTR-100' || $paytrRows[1]['type']!=='refund') {
    throw new RuntimeException('PayTR native JSON parsing or normalization failed.');
}

$iyzicoCsv = "transactionDate,transactionType,paymentTxId,paymentId,conversationId,paidPrice,transactionCurrency,refundConversationId,buyerName,buyerEmail\n"
    ."2026-01-13 10:30:00,AUTH,88001,77001,order-77001,150.00,TRY,,, \n"
    ."2026-01-13 11:30:00,REFUND,88002,77001,order-77001,25.00,TRY,refund-order-1,Private Buyer,private@example.test\n"
    ."2026-01-13 12:30:00,PREAUTH,88003,77002,order-77002,50.00,TRY,,,\n"
    ."2026-01-13 13:30:00,CANCEL,88004,77003,order-77003,75.00,TRY,,,\n";
$iyzicoRows = StatementImport::parseIyzicoCsv($iyzicoCsv);
if (count($iyzicoRows)!==4 || $iyzicoRows[0]['type']!=='capture' || $iyzicoRows[0]['reference']!=='77001' || $iyzicoRows[1]['type']!=='refund' || $iyzicoRows[1]['reference']!=='refund-order-1' || $iyzicoRows[2]['type']!=='unknown' || $iyzicoRows[3]['type']!=='unknown') {
    throw new RuntimeException('iyzico SFTP CSV mapping failed.');
}
if (str_contains(json_encode($iyzicoRows, JSON_THROW_ON_ERROR), 'private@example.test') || str_contains(json_encode($iyzicoRows, JSON_THROW_ON_ERROR), 'Private Buyer')) {
    throw new RuntimeException('iyzico buyer personal data leaked into canonical movements.');
}
$semicolon = "transactionDate;transactionType;paymentTxId;paymentId;paidPrice;transactionCurrency\n2026-01-13 10:30:00;POSTAUTH;88005;77005;10.50;EUR\n";
if (StatementImport::parseIyzicoCsv($semicolon)[0]['currency'] !== 'EUR') throw new RuntimeException('iyzico semicolon-delimited report was not parsed.');

foreach ([
    ['paytr', '{"status":"failure","transactions":[]}'],
    ['paytr', '{"status":"success","transactions":[{"islem_tipi":"S","siparis_no":"x","islem_tarihi":"not-a-date","islem_tutari":"1","para_birimi":"TL"}]}'],
    ['iyzico', "transactionDate,transactionType,paymentId,transactionCurrency,paidPrice\n2026-01-13 10:30:00,AUTH,1,TRY,-1\n"],
] as [$source,$contents]) {
    $failed=false;
    try { StatementImport::parseUpload($source,$contents); } catch (Throwable) { $failed=true; }
    if (!$failed) throw new RuntimeException('Invalid provider report was accepted.');
}
echo "HostPilot native PayTR JSON and iyzico SFTP CSV tests passed.\n";
