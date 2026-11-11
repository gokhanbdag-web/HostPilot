<?php
declare(strict_types=1);
namespace HostPilot\Payment;

final class PaytrSignature
{
    public static function callbackHash(string $merchantOid,string $merchantSalt,string $status,string $totalAmount,string $merchantKey):string
    {
        return base64_encode(hash_hmac('sha256',$merchantOid.$merchantSalt.$status.$totalAmount,$merchantKey,true));
    }

    public static function verifyCallback(string $merchantOid,string $merchantSalt,string $status,string $totalAmount,string $providedHash,string $merchantKey):bool
    {
        if($providedHash==='')return false;
        return hash_equals(self::callbackHash($merchantOid,$merchantSalt,$status,$totalAmount,$merchantKey),$providedHash);
    }
}
