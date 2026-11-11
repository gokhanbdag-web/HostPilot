<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Core/SecretVault.php';
require dirname(__DIR__).'/app/Payment/GatewayTransport.php';
require dirname(__DIR__).'/app/Payment/PaytrSignature.php';

function expect(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: ".$message.PHP_EOL;
}

$vault=new \HostPilot\Core\\SecretVault('hostpilot-test-installation-key-'.str_repeat('x',32));
$secret='merchant-secret-value-'.bin2hex(random_bytes(8));
$encrypted=$vault->encrypt($secret);
expect($encrypted!==$secret&&!str_contains($encrypted,$secret),'vault ciphertext does not expose plaintext');
expect($vault->decrypt($encrypted)===$secret,'AES-256-GCM vault round trip');
$wrongKeyRejected=false;
try{(new \HostPilot\Core\\SecretVault('a-different-installation-key'))->decrypt($encrypted);}catch(Throwable){$wrongKeyRejected=true;}
expect($wrongKeyRejected,'vault rejects ciphertext encrypted with another installation key');

$path='/payment/iyzipos/checkoutform/initialize/auth/ecom';
$body='{"locale":"tr","conversationId":"conv-123","price":"10.00"}';
$apiKey='sandbox-api-key';$apiSecret='sandbox-secret';$random='17200000001234567890';
$header=\HostPilot\Payment\\GatewayTransport::buildIyzicoAuthorization($path,$body,$apiKey,$apiSecret,$random);
$decoded=base64_decode(substr($header,strlen('IYZWSv2 ')),true);
$expectedSignature=hash_hmac('sha256',$random.$path.$body,$apiSecret);
expect(is_string($decoded)&&str_contains($decoded,'apiKey:'.$apiKey.'&randomKey:'.$random.'&signature:'.$expectedSignature),'iyzico IYZWSv2 request signature composition');

$merchantKey='paytr-test-key';$merchantSalt='paytr-test-salt';$merchantOid='HPTEST123';$status='success';$totalAmount='12500';
$callbackHash=\HostPilot\Payment\\PaytrSignature::callbackHash($merchantOid,$merchantSalt,$status,$totalAmount,$merchantKey);
expect(\HostPilot\Payment\\PaytrSignature::verifyCallback($merchantOid,$merchantSalt,$status,$totalAmount,$callbackHash,$merchantKey),'PayTR callback signature accepts a valid hash');
expect(!\HostPilot\Payment\\PaytrSignature::verifyCallback($merchantOid,$merchantSalt,$status,$totalAmount,$callbackHash.'tampered',$merchantKey),'PayTR callback signature rejects a tampered hash');

echo "Payment crypto checks completed.".PHP_EOL;
