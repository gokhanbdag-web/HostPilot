<?php
declare(strict_types=1);
namespace HostPilot\Core;

use RuntimeException;

final class ResellerClubClient
{
    private string $baseUrl;
    public function __construct(private readonly string $userId,private readonly string $apiKey,string $environment='sandbox')
    {
        if(trim($userId)===''||trim($apiKey)==='')throw new RuntimeException('ResellerClub API kullanıcı kimliği ve anahtarı gerekli.');
        if(!in_array($environment,['sandbox','live'],true))throw new RuntimeException('Geçersiz ResellerClub ortamı.');
        $this->baseUrl=$environment==='live'?'https://api.resellerclub.com/v2':'https://api.sandbox.resellerclub.com/v2';
    }
    public function checkAvailability(string $domain):array
    {
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',$domain))throw new RuntimeException('Geçerli bir alan adı girin (ör. ornek.com).');
        $data=$this->request('GET','/domains/available?'.http_build_query(['domainName'=>strtolower($domain)]));
        $available=$data['available']??null;
        if(is_string($available))$available=strtolower($available)==='true';
        if(!is_bool($available))throw new RuntimeException('Registrar yanıtında kullanılabilirlik bilgisi bulunamadı.');
        return ['domain'=>strtolower($domain),'available'=>$available,'price'=>isset($data['price'])?(string)$data['price']:null,'raw_status'=>(string)($data['status']??'')];
    }
    private function request(string $method,string $path,?array $payload=null):array
    {
        $headers=['Accept: application/json','X-User-Id: '.$this->userId,'Authorization: ApiKey '.$this->apiKey];
        $content=null;
        if($payload!==null){$headers[]='Content-Type: application/json';$content=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content??'','timeout'=>12,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $body=@file_get_contents($this->baseUrl.$path,false,$context);
        $status=0;
        foreach(($http_response_header??[]) as $line)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$line,$m))$status=(int)$m[1];
        if($body===false)throw new RuntimeException('ResellerClub sunucusuna güvenli bağlantı kurulamadı.');
        if(strlen($body)>1048576)throw new RuntimeException('Registrar yanıtı beklenen boyutu aştı.');
        $data=json_decode($body,true);
        if(!is_array($data))throw new RuntimeException('Registrar geçerli JSON yanıtı döndürmedi.');
        if($status<200||$status>=300){$message=(string)($data['message']??$data['error']??'Registrar isteği reddetti.');throw new RuntimeException('ResellerClub HTTP '.$status.': '.substr(strip_tags($message),0,300));}
        return $data;
    }
}