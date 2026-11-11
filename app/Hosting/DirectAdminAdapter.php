<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

use RuntimeException;

final class DirectAdminAdapter implements HostingAdapter
{
    public function __construct(private readonly array $server){}
    public function testConnection():string
    {
        $r=HostingTransport::request($this->server['base_url'],'/api/version','GET',['Accept: application/json'],null,[$this->server['username'],$this->server['secret']]);
        return 'DirectAdmin API bağlantısı başarılı · '.substr(trim($r['body']),0,80);
    }
    public function createAccount(array $a):array
    {
        $username=strtolower((string)$a['username']);
        if(!preg_match('/^[a-z][a-z0-9]{3,7}$/',$username))throw new RuntimeException('DirectAdmin kullanıcı adı bu entegrasyon için 4–8 karakter olmalı; harfle başlamalıdır.');
        $data=['action'=>'create','add'=>'yes','username'=>$username,'email'=>$a['email'],'passwd'=>$a['password'],'passwd2'=>$a['password'],'domain'=>$a['domain'],'package'=>$a['package'],'notify'=>'no','json'=>'yes'];
        $r=HostingTransport::request($this->server['base_url'],'/CMD_ACCOUNT_USER?json=yes','POST',['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],http_build_query($data),[$this->server['username'],$this->server['secret']]);
        $j=$r['json'];
        if(is_array($j)){
            if((string)($j['error']??'0')!=='0')throw new RuntimeException((string)($j['text']??$j['details']??'DirectAdmin hesap oluşturma hatası.'));
        }elseif(stripos($r['body'],'error=1')!==false)throw new RuntimeException('DirectAdmin hesap oluşturma işlemini reddetti.');
        return ['identifier'=>$username,'username'=>$username,'domain'=>(string)$a['domain']];
    }
}
