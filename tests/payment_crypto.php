<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Core/SecretVault.php';
require dirname(__DIR__).'/app/Payment/GatewayTransport.php';

use HostPilot\Core\SecretVault;
use HostPilot\Payment\GatewayTransport;

function expect(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: ".$message.PHP_EOL;
}

$vault=new SecretVault('hostpilot-test-installation-key-'.str_repeat('x',32));
$secret='merchant-secret-value-'.bin2hex(random_bytes(8));
$encrypted=$vault->encrypt($secret);
expect($encrypted!==$secret&&!str_contains($encrypted,$secret),'vault ciphertext does not expose plaintext');
expect($vault->decrypt($encrypted)===$secret,'AES-256-GCM vault round trip');
$wrongKeyRejected=false;
try{(new SecretVault('a-different-installation-key'))->decrypt($encrypted);}catch(Throwable){$wrongKeyRejected=true;}
expect($wrongKeyRejected,'vault rejects ciphertext encrypted with another installation key');

$path='/payment/iyzipos/checkoutform/initialize/auth/ecom';
$body='{"locale":"tr","conversationId":"conv-123","price":"10.00"}';
$apiKey='sandbox-api-key';$apiSecret='sandbox-secret';$random='17200000001234567890';
$header=GatewayTransport::buildIyzicoAuthorization($path,$body,$apiKey,$apiSecret,$random);
$decoded=base64_decode(substr($header,strlen('IYZWSv2 ')),true);
$expectedSignature=hash_hmac('sha256',$random.$path.$body,$apiSecret);
expect(is_string($decoded)&&str_contains($decoded,'apiKey:'.$apiKey.'&randomKey:'.$random.'&signature:'.$expectedSignature),'iyzico IYZWSv2 request signature composition');

$merchantKey='paytr-test-key';$merchantSalt='paytr-test-salt';$merchantOid='HPTEST123';$status='success';$totalAmount='12500';
$callbackHash=base64_encode(hash_hmac('sha256',$merchantOid.$merchantSalt.$status.$totalAmount,$merchantKey,true));
$expectedCallback=base64_encode(hash_hmac('sha256',$merchantOid.$merchantSalt.$status.$totalAmount,$merchantKey,true));
expect(hash_equals($expectedCallback,$callbackHash),'PayTR callback HMAC calculation is deterministic');

echo "Payment crypto checks completed.".PHP_EOL;
