<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Payment/PaytrRequest.php';
use HostPilot\Payment\PaytrRequest;

$assert=static function(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
$input=[
 'merchant_id'=>'ci-merchant-001','user_ip'=>'203.0.113.10','merchant_oid'=>'HP-CI-PAYTR-0001',
 'email'=>'ci@example.test','payment_amount'=>'12500','basket'=>[['HostPilot Starter','125.00',1]],
 'test_mode'=>'1','user_name'=>'CI Customer','user_address'=>'Test Street 1','user_phone'=>'+905551112233',
 'ok_url'=>'https://hostpilot.example.test/payment/return','fail_url'=>'https://hostpilot.example.test/payment/return'
];
$payload=PaytrRequest::buildPayload($input,'ci-merchant-key','ci-salt');
$assert($payload['paytr_token']==='LtIPsksyrb1OqeMcaIcbCf2Pt27PnkKsInNcnxqNmPw=','PayTR iframe initialization token matches fixed HMAC vector');
$assert($payload['user_basket']==='W1siSG9zdFBpbG90IFN0YXJ0ZXIiLCIxMjUuMDAiLDFdXQ==','PayTR basket is encoded with the expected JSON contract');
$assert($payload['payment_amount']==='12500'&&$payload['currency']==='TL','PayTR amount is sent in kuruş and TRY uses TL');
$assert($payload['test_mode']==='1'&&$payload['debug_on']==='1'&&$payload['lang']==='tr'&&$payload['iframe_v2']==='1','PayTR sandbox and iframe request flags are preserved');
$assert($payload['merchant_ok_url']===$input['ok_url']&&$payload['merchant_fail_url']===$input['fail_url'],'PayTR return URLs are passed through unchanged');
$changed=$input;$changed['payment_amount']='12501';
$assert(PaytrRequest::buildPayload($changed,'ci-merchant-key','ci-salt')['paytr_token']!==$payload['paytr_token'],'PayTR token changes when payment amount changes');
$changed=$input;$changed['user_ip']='203.0.113.11';
$assert(PaytrRequest::buildPayload($changed,'ci-merchant-key','ci-salt')['paytr_token']!==$payload['paytr_token'],'PayTR token changes when client IP changes');
$changed=$input;$changed['test_mode']='0';
$assert(PaytrRequest::buildPayload($changed,'ci-merchant-key','ci-salt')['paytr_token']!==$payload['paytr_token'],'PayTR token changes when live/test mode changes');
$invalid=$input;$invalid['payment_amount']='0';
$rejected=false;try{PaytrRequest::buildPayload($invalid,'ci-merchant-key','ci-salt');}catch(InvalidArgumentException){$rejected=true;}
$assert($rejected,'PayTR rejects zero-value payment initialization');
$invalid=$input;$invalid['basket']=[];
$rejected=false;try{PaytrRequest::buildPayload($invalid,'ci-merchant-key','ci-salt');}catch(InvalidArgumentException){$rejected=true;}
$assert($rejected,'PayTR rejects an empty basket');
echo "HostPilot PayTR initialization contract tests passed.\n";
