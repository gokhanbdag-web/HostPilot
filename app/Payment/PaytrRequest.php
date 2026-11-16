<?php
declare(strict_types=1);
namespace HostPilot\Payment;

final class PaytrRequest
{
    /**
     * Build the PayTR iFrame token request without making a network call.
     * Input values are prepared by the authenticated payment controller.
     */
    public static function buildPayload(array $input,string $merchantKey,string $merchantSalt):array
    {
        foreach(['merchant_id','user_ip','merchant_oid','email','payment_amount','basket','test_mode','user_name','user_address','user_phone','ok_url','fail_url'] as $required){
            if(!array_key_exists($required,$input))throw new \InvalidArgumentException('Missing PayTR request field: '.$required);
        }
        $merchantId=(string)$input['merchant_id'];
        $ip=(string)$input['user_ip'];
        $merchantOid=(string)$input['merchant_oid'];
        $email=(string)$input['email'];
        $paymentAmount=(string)$input['payment_amount'];
        $test=(string)$input['test_mode']==='1'?'1':'0';
        if($merchantKey===''||$merchantSalt===''||$merchantId===''||$merchantOid===''||$email===''||!preg_match('/^[0-9]+$/',$paymentAmount)|| (int)$paymentAmount<1){
            throw new \InvalidArgumentException('Invalid PayTR merchant or payment details.');
        }
        if(!is_array($input['basket'])||$input['basket']===[])throw new \InvalidArgumentException('PayTR basket cannot be empty.');
        $basketEncoded=base64_encode(json_encode($input['basket'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $noInstallment='0';$maxInstallment='0';$currency='TL';
        $hash=$merchantId.$ip.$merchantOid.$email.$paymentAmount.$basketEncoded.$noInstallment.$maxInstallment.$currency.$test;
        $token=base64_encode(hash_hmac('sha256',$hash.$merchantSalt,$merchantKey,true));
        return [
            'merchant_id'=>$merchantId,'user_ip'=>$ip,'merchant_oid'=>$merchantOid,'email'=>$email,
            'payment_amount'=>$paymentAmount,'paytr_token'=>$token,'user_basket'=>$basketEncoded,
            'debug_on'=>$test,'no_installment'=>$noInstallment,'max_installment'=>$maxInstallment,
            'user_name'=>substr((string)$input['user_name'],0,60),'user_address'=>substr(trim((string)$input['user_address']),0,400),
            'user_phone'=>substr(trim((string)$input['user_phone']),0,20),
            'merchant_ok_url'=>(string)$input['ok_url'],'merchant_fail_url'=>(string)$input['fail_url'],
            'timeout_limit'=>'30','currency'=>$currency,'test_mode'=>$test,'lang'=>'tr','iframe_v2'=>'1'
        ];
    }
}
