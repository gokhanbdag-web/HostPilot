<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Payment/GatewayTransport.php';
use HostPilot\Payment\GatewayTransport;

$assert=static function(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
$rejects=static function(callable $fn):bool{try{$fn();return false;}catch(RuntimeException){return true;}};
$paytr='https://www.paytr.com/odeme/api/get-token';
$iyzi='https://sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom';
$detail='https://api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail';
$assert(GatewayTransport::validateEndpoint($paytr)['host']==='www.paytr.com','PayTR token endpoint is accepted');
$assert(GatewayTransport::validateEndpoint($iyzi)['host']==='sandbox-api.iyzipay.com','iyzico sandbox endpoint is accepted');
$assert(GatewayTransport::validateEndpoint($detail,'/payment/iyzipos/checkoutform/auth/ecom/detail')['path']==='/payment/iyzipos/checkoutform/auth/ecom/detail','iyzico retrieve path must match signed path');
foreach([
 'http://www.paytr.com/odeme/api/get-token',
 'https://evil.example/odeme/api/get-token',
 'https://www.paytr.com.evil.example/odeme/api/get-token',
 'https://user@www.paytr.com/odeme/api/get-token',
 'https://www.paytr.com:8443/odeme/api/get-token',
 'https://www.paytr.com/odeme/api/get-token?next=https://evil.example',
 'https://www.paytr.com/odeme/api/get-token#fragment',
 'https://127.0.0.1/odeme/api/get-token',
 'https://api.iyzipay.com/payment/iyzipos/checkoutform/initialize/auth/ecom/extra',
 'https://sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail'
] as $url)$assert($rejects(static fn()=>GatewayTransport::validateEndpoint($url)),'unsafe or unapproved provider URL is rejected: '.$url);
$assert($rejects(static fn()=>GatewayTransport::validateEndpoint($detail,'/payment/iyzipos/checkoutform/initialize/auth/ecom')),'iyzico authorization path cannot differ from requested URL path');
GatewayTransport::assertResponseSize(0);GatewayTransport::assertResponseSize(1048576);
$assert($rejects(static fn()=>GatewayTransport::assertResponseSize(1048577)),'oversized provider response is rejected');
$source=file_get_contents(dirname(__DIR__).'/app/Payment/GatewayTransport.php');
$assert(str_contains($source,'CURLOPT_FOLLOWLOCATION=>false')&&str_contains($source,'CURLOPT_MAXREDIRS=>0'),'cURL redirects are disabled');
$assert(str_contains($source,"'follow_location'=>0")&&str_contains($source,"'max_redirects'=>0"),'PHP stream redirects are disabled');
$assert(str_contains($source,'CURLOPT_SSL_VERIFYPEER=>true')&&str_contains($source,'CURLOPT_SSL_VERIFYHOST=>2'),'cURL certificate and hostname verification stay enabled');
$assert(str_contains($source,"'verify_peer'=>true")&&str_contains($source,"'verify_peer_name'=>true")&&str_contains($source,"'allow_self_signed'=>false"),'stream certificate and hostname verification stay enabled');
$assert(str_contains($source,'MAX_RESPONSE_BYTES=1048576'),'both transports use a one-megabyte response cap');
echo "HostPilot payment gateway transport security tests passed.\n";
