<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/PaytrSignature.php';
require_once dirname(__DIR__).'/app/Payment/GatewayTransport.php';

require_once dirname(__DIR__).'/app/Payment/IyzicoSignature.php';
use HostPilot\Payment\IyzicoSignature;

$iyzicoTransaction=['currency'=>'TRY','amount'=>125.00,'invoice_number'=>'HP-INV-2026-001','idempotency_key'=>'ci-idempotency-001'];
$iyzicoToken='ci-iyzico-token-123';
$iyzicoSecret='ci-iyzico-secret';
$iyzicoResponse=[
    'status'=>'success','paymentStatus'=>'SUCCESS','paymentId'=>'ci-payment-987',
    'currency'=>'TRY','basketId'=>'HP-INV-2026-001','conversationId'=>'ci-idempotency-001',
    'paidPrice'=>'125.00','price'=>'125.00','fraudStatus'=>1
];
$iyzicoResponse['signature']=IyzicoSignature::sign($iyzicoResponse,$iyzicoToken,$iyzicoSecret);
$assert($iyzicoResponse['signature']==='111837154ae024ff8da70c2df4f620661ed1fa1ee3d93b7e7689a442ac359584','iyzico retrieve response signature matches fixed HMAC-SHA256 vector');
$assert(IyzicoSignature::assess($iyzicoResponse,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['decision']==='success','iyzico verified SUCCESS with fraudStatus 1 is accepted');
$fraudPending=$iyzicoResponse;$fraudPending['fraudStatus']=0;$fraudPending['signature']=IyzicoSignature::sign($fraudPending,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($fraudPending,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['decision']==='pending','iyzico fraudStatus 0 remains pending for risk review');
$badSignature=$iyzicoResponse;$badSignature['signature']=str_repeat('0',64);
$badSignatureDecision=IyzicoSignature::assess($badSignature,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret);
$assert($badSignatureDecision['decision']==='review'&&$badSignatureDecision['code']==='signature_review','iyzico claimed success with invalid signature is retained for manual review');
$alteredAmount=$iyzicoResponse;$alteredAmount['paidPrice']='124.99';$alteredAmount['signature']=IyzicoSignature::sign($alteredAmount,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($alteredAmount,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['code']==='response_mismatch_review','iyzico signed success with amount mismatch is retained for review');
$alteredCurrency=$iyzicoResponse;$alteredCurrency['currency']='USD';$alteredCurrency['signature']=IyzicoSignature::sign($alteredCurrency,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($alteredCurrency,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['code']==='response_mismatch_review','iyzico signed success with currency mismatch is retained for review');
$alteredBasket=$iyzicoResponse;$alteredBasket['basketId']='OTHER-INVOICE';$alteredBasket['signature']=IyzicoSignature::sign($alteredBasket,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($alteredBasket,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['code']==='response_mismatch_review','iyzico signed success for another invoice is retained for review');
$alteredConversation=$iyzicoResponse;$alteredConversation['conversationId']='OTHER-REQUEST';$alteredConversation['signature']=IyzicoSignature::sign($alteredConversation,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($alteredConversation,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['code']==='response_mismatch_review','iyzico signed success for another request is retained for review');
$failedPayment=$iyzicoResponse;$failedPayment['paymentStatus']='FAILURE';$failedPayment['signature']=IyzicoSignature::sign($failedPayment,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($failedPayment,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['decision']==='failed','iyzico signed FAILURE is marked failed');
$unknownFraud=$iyzicoResponse;$unknownFraud['fraudStatus']=9;$unknownFraud['signature']=IyzicoSignature::sign($unknownFraud,$iyzicoToken,$iyzicoSecret);
$assert(IyzicoSignature::assess($unknownFraud,$iyzicoTransaction,$iyzicoToken,$iyzicoSecret)['decision']==='review','iyzico unknown fraud status is retained for manual review');


use HostPilot\Payment\GatewayTransport;
use HostPilot\Payment\PaytrSignature;

$assert=static function(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};

// Fixed synthetic protocol vectors; no merchant credentials are used.
$paytrHash=PaytrSignature::callbackHash('HP-CI-ORDER-0001','ci-salt','success','12500','ci-merchant-key');
$assert($paytrHash==='ZrxpFa67kjjV24867cTY1mtPD/xZZMiHBdonaI3mTto=','PayTR callback HMAC matches fixed SHA-256/Base64 vector');
$assert(PaytrSignature::verifyCallback('HP-CI-ORDER-0001','ci-salt','success','12500',$paytrHash,'ci-merchant-key'),'PayTR accepts a valid callback signature');
$assert(!PaytrSignature::verifyCallback('HP-CI-ORDER-0001','ci-salt','success','12501',$paytrHash,'ci-merchant-key'),'PayTR rejects altered callback amount');
$assert(!PaytrSignature::verifyCallback('HP-CI-ORDER-0001','ci-salt','failed','12500',$paytrHash,'ci-merchant-key'),'PayTR rejects altered callback status');
$assert(!PaytrSignature::verifyCallback('HP-CI-ORDER-0001','ci-salt','success','12500','','ci-merchant-key'),'PayTR rejects missing callback signature');

$path='/payment/iyzipos/checkoutform/initialize/auth/ecom';
$body='{"locale":"tr","conversationId":"ci-123","price":"125.00"}';
$authorization=GatewayTransport::buildIyzicoAuthorization($path,$body,'ci-api-key','ci-secret-key','1700000000000abcdef12345');
$expected='IYZWSv2 YXBpS2V5OmNpLWFwaS1rZXkmcmFuZG9tS2V5OjE3MDAwMDAwMDAwMDBhYmNkZWYxMjM0NSZzaWduYXR1cmU6YjIzYmNjNmUzYmJjNjU5M2Y4N2VmOWE1ODg0YWE2N2NmMzM3MGE1YjUwYTM4ODQzOTA4Njk1MDUxODA1ODA4Nw==';
$assert($authorization===$expected,'iyzico IYZWSv2 authorization matches fixed HMAC-SHA256 vector');
$decoded=base64_decode(substr($authorization,strlen('IYZWSv2 ')),true);
$assert(is_string($decoded)&&str_contains($decoded,'apiKey:ci-api-key&randomKey:1700000000000abcdef12345&signature:'),'iyzico authorization payload contains required fields');
$assert(GatewayTransport::buildIyzicoAuthorization($path,$body.' ','ci-api-key','ci-secret-key','1700000000000abcdef12345')!==$authorization,'iyzico body changes produce a different signature');
$assert(GatewayTransport::buildIyzicoAuthorization('/payment/other',$body,'ci-api-key','ci-secret-key','1700000000000abcdef12345')!==$authorization,'iyzico path changes produce a different signature');

echo "HostPilot payment gateway protocol contract tests passed.\n";
