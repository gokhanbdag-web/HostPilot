<?php
declare(strict_types=1);
namespace HostPilot\Core;

final class SecretVault
{
    public function __construct(private readonly string $applicationKey) {}

    private function key():string
    {
        return hash('sha256','HostPilot settings vault|'.$this->applicationKey,true);
    }

    public function encrypt(string $plain):string
    {
        if($plain==='')return '';
        if(!function_exists('openssl_encrypt'))throw new \RuntimeException('Gizli anahtar saklamak için OpenSSL desteği gerekli.');
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$iv,$tag,'hostpilot-settings',16);
        if($cipher===false)throw new \RuntimeException('Gizli ayar şifrelenemedi.');
        return 'v1:'.base64_encode($iv.$tag.$cipher);
    }

    public function decrypt(string $stored):string
    {
        if($stored==='')return '';
        if(!str_starts_with($stored,'v1:'))throw new \RuntimeException('Gizli ayar biçimi tanınmıyor.');
        $raw=base64_decode(substr($stored,3),true);
        if($raw===false||strlen($raw)<29)throw new \RuntimeException('Gizli ayar verisi bozuk.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'hostpilot-settings');
        if($plain===false)throw new \RuntimeException('Gizli ayar çözümlenemedi. Kurulum anahtarı değişmiş olabilir.');
        return $plain;
    }
}
