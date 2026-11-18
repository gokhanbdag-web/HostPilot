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
    public function createCustomer(array $customer):string
    {
        $required=['username','password','name','addressLine1','city','country','zipcode','phoneCountryCode','phone'];
        foreach($required as $key)if(!isset($customer[$key])||trim((string)$customer[$key])==='')throw new RuntimeException('Registrar müşteri bilgileri eksik: '.$key);
        $data=$this->request('POST','/customers',$customer);
        $id=(string)($data['customerId']??'');
        if($id===''||!ctype_digit($id))throw new RuntimeException('Registrar müşteri kaydından geçerli customerId dönmedi.');
        return $id;
    }

    public function createContact(array $contact):string
    {
        $required=['customerId','name','email','addressLine1','city','country','zipcode','phoneCountryCode','phone'];
        foreach($required as $key)if(!isset($contact[$key])||trim((string)$contact[$key])==='')throw new RuntimeException('Registrar iletişim bilgileri eksik: '.$key);
        $contact['type']='Contact';
        $data=$this->request('POST','/contacts',$contact);
        $id=(string)($data['contactId']??$data['value']??'');
        if($id===''||!ctype_digit($id))throw new RuntimeException('Registrar contact kaydından geçerli contactId dönmedi.');
        return $id;
    }

    public function registerDomain(string $domain,int $years,string $customerId,string $contactId,array $nameservers,string $invoiceOption,bool $autoRenew=false):array
    {
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i',$domain))throw new RuntimeException('Geçersiz alan adı.');
        if($years<1||$years>10||!ctype_digit($customerId)||!ctype_digit($contactId))throw new RuntimeException('Domain kayıt parametreleri geçersiz.');
        if(!in_array($invoiceOption,['NoInvoice','PayInvoice','KeepInvoice','OnlyAdd'],true))throw new RuntimeException('Registrar faturalandırma seçeneği geçersiz.');
        $ns=[];foreach($nameservers as $server){$server=strtolower(trim((string)$server));if($server!==''&&!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',$server))throw new RuntimeException('Nameserver adresi geçersiz.');if($server!=='')$ns[]=$server;}
        $payload=['domainName'=>strtolower($domain),'years'=>$years,'customerId'=>$customerId,'regContactId'=>$contactId,'adminContactId'=>$contactId,'techContactId'=>$contactId,'billingContactId'=>$contactId,'invoiceOption'=>$invoiceOption,'autoRenew'=>$autoRenew];
        if($ns)$payload['ns']=$ns;
        $data=$this->request('POST','/domains',$payload);
        if(empty($data['orderId'])||!ctype_digit((string)$data['orderId']))throw new RuntimeException('Registrar kayıt yanıtında geçerli orderId bulunamadı.');
        $eaqId=(string)($data['eaqId']??'');
        if($eaqId!==''&&!ctype_digit($eaqId))throw new RuntimeException('Registrar kayıt yanıtındaki EAQ action ID geçersiz.');
        return $data;
    }

    public function updateNameservers(string $orderId,array $nameservers):array
    {
        if(!ctype_digit($orderId))throw new RuntimeException('Geçersiz registrar orderId.');
        $servers=[];
        foreach($nameservers as $server){
            $server=strtolower(trim((string)$server));
            if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/',$server))throw new RuntimeException('Nameserver adresi geçersiz.');
            $servers[]=$server;
        }
        $servers=array_values(array_unique($servers));
        if(count($servers)<2||count($servers)>5)throw new RuntimeException('En az iki, en fazla beş farklı nameserver girin.');
        return $this->request('PUT','/domains/'.$orderId.'/nameservers',['ns'=>$servers]);
    }

    public function renewDomain(string $orderId,int $years,int $currentExpiryTimestamp,bool $autoRenew=false):array
    {
        if(!ctype_digit($orderId))throw new RuntimeException('Geçersiz registrar orderId.');
        if($years<1||$years>10)throw new RuntimeException('Domain yenileme süresi 1 ile 10 yıl arasında olmalı.');
        if($currentExpiryTimestamp<=time())throw new RuntimeException('Registrar tarafından doğrulanmış geçerli bitiş tarihi gerekli.');
        // ResellerClub expects the currently registered expiry date as a UNIX timestamp.
        // OnlyAdd prevents an unexpected debit from the reseller balance.
        return $this->request('POST','/domains/'.$orderId.'/renew',[
            'years'=>$years,
            'expDate'=>$currentExpiryTimestamp,
            'invoiceOption'=>'OnlyAdd',
            'autoRenew'=>$autoRenew
        ]);
    }

    public function domainDetails(string $orderId):array
    {
        if(!ctype_digit($orderId))throw new RuntimeException('Geçersiz registrar orderId.');
        $data=$this->request('GET','/domains/'.$orderId.'?'.http_build_query(['options'=>'OrderDetails,StatusDetails']));
        $domain=$data['domain']??null;
        if(!is_array($domain))throw new RuntimeException('Registrar domain detaylarını döndürmedi.');
        return $domain;
    }

    public function actionStatus(string $orderId,string $eaqId=''):array
    {
        if(!ctype_digit($orderId))throw new RuntimeException('Geçersiz registrar orderId.');
        if($eaqId!==''&&!ctype_digit($eaqId))throw new RuntimeException('Geçersiz registrar action ID.');
        $filters=['orderId'=>$orderId,'size'=>10,'page'=>1];
        if($eaqId!=='')$filters['eaqId']=$eaqId;
        $query=http_build_query($filters);
        $current=$this->request('GET','/actions/search-current?'.$query);
        if(!empty($current['actions']))return $current;
        // Completed and failed actions move out of the current list after 24 hours.
        return $this->request('GET','/actions/search-archived?'.$query);
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