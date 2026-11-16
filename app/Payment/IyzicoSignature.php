<?php
declare(strict_types=1);
namespace HostPilot\Payment;

final class IyzicoSignature
{
    public static function normalizeMoney(mixed $value):string
    {
        $normalized=rtrim(rtrim(number_format((float)$value,8,'.',''),'0'),'.');
        return $normalized===''?'0':$normalized;
    }

    public static function signatureData(array $result,string $token):string
    {
        return implode(':',[
            (string)($result['paymentStatus']??''),
            (string)($result['paymentId']??''),
            (string)($result['currency']??''),
            (string)($result['basketId']??''),
            (string)($result['conversationId']??''),
            self::normalizeMoney($result['paidPrice']??''),
            self::normalizeMoney($result['price']??''),
            $token
        ]);
    }

    public static function sign(array $result,string $token,string $secret):string
    {
        return hash_hmac('sha256',self::signatureData($result,$token),$secret);
    }

    /**
     * Classify an authenticated Checkout Form retrieve response.
     * A claimed success with invalid signature or mismatched transaction data is
     * retained for manual review: it must never be silently recorded as a failed charge.
     *
     * @return array{decision:string,code:string,message:string}
     */
    public static function assess(array $result,array $transaction,string $token,string $secret):array
    {
        if(($result['status']??'')!=='success'){
            return ['decision'=>'failed','code'=>(string)($result['errorCode']??'retrieve_not_success'),'message'=>'iyzico ödeme sonucu başarısız.'];
        }
        $signature=(string)($result['signature']??'');
        if($signature===''||!hash_equals(self::sign($result,$token,$secret),$signature)){
            return ['decision'=>'review','code'=>'signature_review','message'=>'iyzico sonucu imza doğrulamasından geçmedi; sağlayıcı panelinde mutabakat gerekiyor.'];
        }
        $paymentStatus=(string)($result['paymentStatus']??'');
        if($paymentStatus==='FAILURE'){
            return ['decision'=>'failed','code'=>(string)($result['errorCode']??'payment_failed'),'message'=>'iyzico ödemeyi başarısız olarak bildirdi.'];
        }
        if($paymentStatus!=='SUCCESS'){
            return ['decision'=>'review','code'=>'payment_status_review','message'=>'iyzico ödeme durumu kesin değil; sağlayıcı panelinde mutabakat gerekiyor.'];
        }
        $matches=(string)($result['paymentId']??'')!==''
            &&(string)($result['currency']??'')===(string)$transaction['currency']
            &&abs((float)($result['paidPrice']??0)-(float)$transaction['amount'])<0.005
            &&abs((float)($result['price']??0)-(float)$transaction['amount'])<0.005
            &&(string)($result['basketId']??'')===(string)$transaction['invoice_number']
            &&(string)($result['conversationId']??'')===(string)$transaction['idempotency_key'];
        if(!$matches){
            return ['decision'=>'review','code'=>'response_mismatch_review','message'=>'iyzico başarılı ödeme bildirdi ancak işlem bilgileri beklenen değerlerle eşleşmiyor; sağlayıcı panelinde mutabakat gerekiyor.'];
        }
        $fraudStatus=(int)($result['fraudStatus']??-1);
        if($fraudStatus===1)return ['decision'=>'success','code'=>'success','message'=>'iyzico retrieve response and signature verified'];
        if($fraudStatus===0)return ['decision'=>'pending','code'=>'fraud_review','message'=>'iyzico risk incelemesi bekleniyor.'];
        return ['decision'=>'review','code'=>'fraud_status_review','message'=>'iyzico risk sonucu kesin değil; sağlayıcı panelinde mutabakat gerekiyor.'];
    }
}
