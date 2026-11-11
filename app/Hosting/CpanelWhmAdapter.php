<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

use RuntimeException;

final class CpanelWhmAdapter implements HostingAdapter
{
    public function __construct(private readonly array $server){}
    private function call(string $path,array $params=[],string $method='GET'):array
    {
        $headers=['Accept: application/json','Authorization: whm '.$this->server['username'].':'.$this->server['secret']];
        $query=http_build_query(['api.version'=>1]+$params);
        if($method==='POST'){$headers[]='Content-Type: application/x-www-form-urlencoded';$r=HostingTransport::request($this->server['base_url'],'/json-api/'.$path.'?api.version=1','POST',$headers,http_build_query($params));}
        else $r=HostingTransport::request($this->server['base_url'],'/json-api/'.$path.'?'.$query,'GET',$headers);
        $j=$r['json'];
        if(!is_array($j))throw new RuntimeException('WHM API geçerli JSON döndürmedi.');
        $meta=$j['metadata']??[];if((int)($meta['result']??0)!==1)throw new RuntimeException((string)($meta['reason']??'WHM işlemi başarısız.'));
        return $j;
    }
    public function testConnection():string
    {$j=$this->call('version');return 'WHM API bağlantısı başarılı · '.(string)($j['data']['version']??'sürüm bilgisi alınamadı');}
    public function suspendAccount(string $username,string $domain,string $reason):void
    {$this->call('suspendacct',['user'=>$username,'reason'=>$reason,'disallowun'=>1]);}
    public function unsuspendAccount(string $username,string $domain):void
    {$this->call('unsuspendacct',['user'=>$username]);}
    public function terminateAccount(string $username,string $domain):void
    {
        if(!preg_match('/^[a-z][a-z0-9]{0,15}$/',$username))throw new RuntimeException('cPanel kullanıcı adı geçersiz.');
        $this->call('removeacct',['user'=>$username],'POST');
    }
    public function createAccount(array $a):array
    {
        $username=strtolower((string)$a['username']);
        if(!preg_match('/^[a-z][a-z0-9]{0,15}$/',$username))throw new RuntimeException('cPanel kullanıcı adı 1–16 karakter olmalı; harfle başlamalı ve yalnızca küçük harf/rakam içermelidir.');
        $params=['username'=>$username,'domain'=>$a['domain'],'password'=>$a['password'],'contactemail'=>$a['email'],'plan'=>$a['package']];
        $j=$this->call('createacct',$params,'POST');
        return ['identifier'=>(string)($j['data']['user']??$username),'username'=>$username,'domain'=>(string)$a['domain']];
    }
}
