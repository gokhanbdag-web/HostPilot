<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Payment/PaytrSignature.php';
require_once dirname(__DIR__).'/app/Payment/GatewayTransport.php';

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
$body=json_encode(["locale"=>"tr","conversationId"=>"ci-123","price"=>"125.00"],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$authorization=GatewayTransport::buildIyzicoAuthorization($path,$body,'ci-api-key','ci-secret-key','1700000000000abcdef12345');
$expected='IYZWSv2 YXBpS2V5OmNpLWFwaS1rZXkmcmFuZG9tS2V5OjE3MDAwMDAwMDAwMDBhYmNkZWYxMjM0NSZzaWduYXR1cmU6YjIzYmNjNmUzYmJjNjU5M2Y4N2VmOWE1ODg0YWE2N2NmMzM3MGE1YjUwYTM4ODQzOTA4Njk1MDUxODA1ODA4Nw==';
echo 'DEBUG IYZICO AUTH='.bin2hex($authorization).PHP_EOL;
$assert($authorization===$expected,'iyzico IYZWSv2 authorization matches fixed HMAC-SHA256 vector');
$decoded=base64_decode(substr($authorization,strlen('IYZWSv2 ')),true);
$assert(is_string($decoded)&&str_contains($decoded,'apiKey:ci-api-key&randomKey:1700000000000abcdef12345&signature:'),'iyzico authorization payload contains required fields');
$assert(GatewayTransport::buildIyzicoAuthorization($path,$body.' ','ci-api-key','ci-secret-key','1700000000000abcdef12345')!==$authorization,'iyzico body changes produce a different signature');
$assert(GatewayTransport::buildIyzicoAuthorization('/payment/other',$body,'ci-api-key','ci-secret-key','1700000000000abcdef12345')!==$authorization,'iyzico path changes produce a different signature');

echo "HostPilot payment gateway protocol contract tests passed.\n";
