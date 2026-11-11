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
    private function accountAction(string $username,string $action):void
    {
        if(!preg_match('/^[a-z][a-z0-9]{3,7}$/',$username))throw new RuntimeException('DirectAdmin kullanıcı adı geçersiz.');
        $data=['select0'=>$username,'location'=>'CMD_SELECT_USERS'];
        if($action==='suspend')$data['dosuspend']=1;else $data['dounsuspend']=1;
        $r=HostingTransport::request($this->server['base_url'],'/CMD_API_SELECT_USERS','POST',['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],http_build_query($data),[$this->server['username'],$this->server['secret']]);
        if(stripos($r['body'],'error=1')!==false||stripos($r['body'],'error=')===0)throw new RuntimeException('DirectAdmin hesap durumunu değiştiremedi: '.substr(strip_tags($r['body']),0,250));
    }
    public function suspendAccount(string $username,string $domain,string $reason):void{$this->accountAction($username,'suspend');}
    public function unsuspendAccount(string $username,string $domain):void{$this->accountAction($username,'unsuspend');}
    public function changePackage(string $username,string $domain,string $package,string $packageGuid='',string $packageExternalId=''):void
    {
        // DirectAdmin exposes a per-server OpenAPI document. No stable, documented
        // package-switch operation is currently assumed by HostPilot.
        throw new RuntimeException('DirectAdmin paket değiştirme bu sunucunun OpenAPI sözleşmesi doğrulanana kadar desteklenmiyor. Hesap üzerinde değişiklik yapılmadı.');
    }
    public function terminateAccount(string $username,string $domain):void
    {
        if(!preg_match('/^[a-z][a-z0-9]{3,7}$/',$username))throw new RuntimeException('DirectAdmin kullanıcı adı geçersiz.');
        $data=['confirmed'=>'Confirm','delete'=>'yes','select0'=>$username,'json'=>'yes'];
        $r=HostingTransport::request($this->server['base_url'],'/CMD_API_SELECT_USERS?json=yes','POST',['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],http_build_query($data),[$this->server['username'],$this->server['secret']]);
        $j=$r['json'];
        if(is_array($j)){
            if((string)($j['error']??'0')!=='0')throw new RuntimeException((string)($j['text']??$j['details']??'DirectAdmin hesap silme hatası.'));
        }elseif(preg_match('/(?:^|&)error=1(?:&|$)/',(string)$r['body']))throw new RuntimeException('DirectAdmin hesap silme işlemini reddetti.');
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
