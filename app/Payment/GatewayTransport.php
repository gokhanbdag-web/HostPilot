<?php
declare(strict_types=1);
namespace HostPilot\Payment;

final class GatewayTransport
{
    private const MAX_RESPONSE_BYTES=1048576;

    public static function postForm(string $url,array $data):array
    {
        $body=http_build_query($data,'','&',PHP_QUERY_RFC3986);
        self::validateEndpoint($url);
        try{[$status,$response]=self::request($url,$body,'application/x-www-form-urlencoded');}
        catch(\Throwable $e){throw new GatewayTransportException('Ödeme sağlayıcısı bağlantısının sonucu doğrulanamadı.',$e->getCode(),$e);}
        $json=json_decode($response,true);
        if($status<200||$status>=300||!is_array($json))throw new GatewayTransportException('Ödeme sağlayıcısından doğrulanabilir yanıt alınamadı (HTTP '.$status.').');
        return $json;
    }

    public static function getJson(string $baseUrl,string $path,array $query,string $apiKey,string $secretKey):array
    {
        self::validateEndpoint(rtrim($baseUrl,'/').$path,$path);
        $queryString=http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $url=rtrim($baseUrl,'/').$path.($queryString!==''?'?'.$queryString:'');
        $rnd=(string)round(microtime(true)*1000).bin2hex(random_bytes(5));
        $authorization=self::buildIyzicoAuthorization($path,'',$apiKey,$secretKey,$rnd);
        try{
            [$status,$response]=self::request($url,'','application/json',[
                'Authorization: '.$authorization,'x-iyzi-rnd: '.$rnd
            ],$path,'GET');
        }catch(\Throwable $e){throw new GatewayTransportException('iyzico raporlama bağlantısının sonucu doğrulanamadı.',$e->getCode(),$e);}
        $json=json_decode($response,true);
        if($status<200||$status>=300||!is_array($json))throw new GatewayTransportException('iyzico raporlama servisi doğrulanabilir yanıt döndürmedi (HTTP '.$status.').');
        return $json;
    }

    public static function postJson(string $url,string $path,array $data,string $apiKey,string $secretKey):array
    {
        $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        if(!is_string($body))throw new \RuntimeException('Ödeme isteği hazırlanamadı.');
        $rnd=(string)round(microtime(true)*1000).bin2hex(random_bytes(5));
        $authorization=self::buildIyzicoAuthorization($path,$body,$apiKey,$secretKey,$rnd);
        self::validateEndpoint($url,$path);
        try{
            [$status,$response]=self::request($url,$body,'application/json',[
                'Authorization: '.$authorization,'x-iyzi-rnd: '.$rnd
            ],$path);
        }catch(\Throwable $e){throw new GatewayTransportException('iyzico bağlantısının sonucu doğrulanamadı.',$e->getCode(),$e);}
        $json=json_decode($response,true);
        if($status<200||$status>=300||!is_array($json))throw new GatewayTransportException('iyzico doğrulanabilir yanıt döndürmedi (HTTP '.$status.').');
        return $json;
    }

    public static function buildIyzicoAuthorization(string $path,string $body,string $apiKey,string $secretKey,string $randomKey):string
    {
        $signature=hash_hmac('sha256',$randomKey.$path.$body,$secretKey);
        return 'IYZWSv2 '.base64_encode('apiKey:'.$apiKey.'&randomKey:'.$randomKey.'&signature='.$signature);
    }

    /**
     * Only the fixed HTTPS API endpoints used by HostPilot payment integrations are allowed.
     */
    public static function validateEndpoint(string $url,?string $expectedPath=null,bool $allowQuery=false):array
    {
        $parts=parse_url($url);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            ||isset($parts['user'])||isset($parts['pass'])||(!$allowQuery&&isset($parts['query']))||isset($parts['fragment'])
            ||isset($parts['port'])&&$parts['port']!==443){
            throw new \RuntimeException('Ödeme sağlayıcısı HTTPS adresi güvenlik politikasına uymuyor.');
        }
        $host=strtolower(rtrim((string)($parts['host']??''),'.'));
        $path=(string)($parts['path']??'');
        $allowed=[
            'www.paytr.com'=>['/odeme/api/get-token','/rapor/islem-dokumu'],
            'api.iyzipay.com'=>['/payment/iyzipos/checkoutform/initialize/auth/ecom','/payment/iyzipos/checkoutform/auth/ecom/detail','/v2/reporting/payment/transactions'],
            'sandbox-api.iyzipay.com'=>['/payment/iyzipos/checkoutform/initialize/auth/ecom','/payment/iyzipos/checkoutform/auth/ecom/detail','/v2/reporting/payment/transactions']
        ];
        if(!isset($allowed[$host])||!in_array($path,$allowed[$host],true)
            ||($expectedPath!==null&&$expectedPath!==$path)){
            throw new \RuntimeException('Ödeme sağlayıcısı adresi izin verilen uç noktalardan biri değil.');
        }
        return ['host'=>$host,'path'=>$path];
    }

    public static function assertResponseSize(int $bytes):void
    {
        if($bytes<0||$bytes>self::MAX_RESPONSE_BYTES)throw new \RuntimeException('Ödeme sağlayıcısı yanıtı izin verilen boyutu aşıyor.');
    }

    private static function request(string $url,string $body,string $contentType,array $extra=[],?string $expectedPath=null,string $method='POST'):array
    {
        if(!in_array($method,['GET','POST'],true))throw new \RuntimeException('Ödeme HTTP metodu izinli değil.');
        self::validateEndpoint($url,$expectedPath,$method==='GET');
        $headers=array_merge(['Content-Type: '.$contentType,'Accept: application/json'],$extra);
        if(function_exists('curl_init')){
            $ch=curl_init($url);$response='';$tooLarge=false;
            $options=[
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{
                    if(strlen($response)+strlen($chunk)>self::MAX_RESPONSE_BYTES){$tooLarge=true;return 0;}
                    $response.=$chunk;return strlen($chunk);
                }
            ];
            if($method==='GET')$options[CURLOPT_HTTPGET]=true;
            else{$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$body;}
            curl_setopt_array($ch,$options);
            $ok=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if($tooLarge)throw new \RuntimeException('Ödeme sağlayıcısı yanıtı izin verilen boyutu aşıyor.');
            if($ok===false)throw new \RuntimeException('Ödeme bağlantısı kurulamadı: '.$error);
            return [$status,$response];
        }
        $headerText=implode("\r\n",$headers);
        $httpOptions=[
            'method'=>$method,'header'=>$headerText,'timeout'=>35,'ignore_errors'=>true,
            'follow_location'=>0,'max_redirects'=>0,'protocol_version'=>1.1
        ];
        if($method==='POST')$httpOptions['content']=$body;
        $context=stream_context_create(['http'=>$httpOptions,'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $stream=@fopen($url,'rb',false,$context);
        if($stream===false)throw new \RuntimeException('Sunucuda güvenli ödeme HTTPS bağlantısı kurulamadı.');
        $response=stream_get_contents($stream,self::MAX_RESPONSE_BYTES+1);
        fclose($stream);
        if(!is_string($response))throw new \RuntimeException('Ödeme sağlayıcısından yanıt okunamadı.');
        self::assertResponseSize(strlen($response));
        $status=0;
        foreach(($http_response_header??[]) as $line)if(preg_match('#^HTTP/\\S+\\s+(\\d{3})#',$line,$m))$status=(int)$m[1];
        return [$status,$response];
    }
}
