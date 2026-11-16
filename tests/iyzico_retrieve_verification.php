<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Payment/IyzicoSignature.php';
use HostPilot\Payment\IyzicoSignature;

$assert=static function(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
$transaction=['currency'=>'TRY','amount'=>125.00,'invoice_number'=>'HP-INV-2026-001','idempotency_key'=>'ci-idempotency-001'];
$token='ci-iyzico-token-123';$secret='ci-iyzico-secret';
$response=['status'=>'success','paymentStatus'=>'SUCCESS','paymentId'=>'ci-payment-987','currency'=>'TRY','basketId'=>'HP-INV-2026-001','conversationId'=>'ci-idempotency-001','paidPrice'=>'125.00','price'=>'125.00','fraudStatus'=>1];
$response['signature']=IyzicoSignature::sign($response,$token,$secret);
$assert($response['signature']==='0800a9d83a6f3ba857b65524699ddda757f1ed25e29f4171f3d75acd2b02f1d5','iyzico retrieve signature fixed vector');
$assert(IyzicoSignature::assess($response,$transaction,$token,$secret)['decision']==='success','verified iyzico payment settles');
foreach([
 ['fraudStatus'=>0,'decision'=>'pending','label'=>'fraud review stays pending'],
 ['fraudStatus'=>9,'decision'=>'review','label'=>'unknown fraud status requires review']
] as $case){$candidate=$response;$candidate['fraudStatus']=$case['fraudStatus'];$candidate['signature']=IyzicoSignature::sign($candidate,$token,$secret);$assert(IyzicoSignature::assess($candidate,$transaction,$token,$secret)['decision']===$case['decision'],'iyzico '.$case['label']);}
$bad=$response;$bad['signature']=str_repeat('0',64);$a=IyzicoSignature::assess($bad,$transaction,$token,$secret);$assert($a['decision']==='review'&&$a['code']==='signature_review','invalid signature cannot be recorded as a failed payment');
foreach([
 ['paidPrice'=>'124.99','label'=>'amount'],
 ['currency'=>'USD','label'=>'currency'],
 ['basketId'=>'OTHER-INVOICE','label'=>'invoice'],
 ['conversationId'=>'OTHER-REQUEST','label'=>'conversation']
] as $case){$candidate=$response;foreach($case as $key=>$value)if($key!=='label')$candidate[$key]=$value;$candidate['signature']=IyzicoSignature::sign($candidate,$token,$secret);$a=IyzicoSignature::assess($candidate,$transaction,$token,$secret);$assert($a['decision']==='review'&&$a['code']==='response_mismatch_review','signed success with mismatched '.$case['label'].' requires review');}
$failed=$response;$failed['paymentStatus']='FAILURE';$failed['signature']=IyzicoSignature::sign($failed,$token,$secret);$assert(IyzicoSignature::assess($failed,$transaction,$token,$secret)['decision']==='failed','signed iyzico FAILURE is rejected');
echo "HostPilot iyzico retrieve verification policy tests passed.\n";
