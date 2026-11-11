<?php
declare(strict_types=1);
namespace HostPilot\Payment;

final class GatewayTransport
{
    public static function postForm(string $url,array $data):array
    {
        $body=http_build_query($data,'','&',PHP_QUERY_RFC3986);
        [$status,$response]=self::request($url,$body,'application/x-www-form-urlencoded');
        $json=json_decode($response,true);
        if($status<200||$status>=300||!is_array($json))throw new \RuntimeException('Ödeme sağlayıcısından geçerli yanıt alınamadı (HTTP '.$status.').');
        return $json;
    }

    public static function postJson(string $url,string $path,array $data,string $apiKey,string $secretKey):array
    {
        $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        if(!is_string($body))throw new \RuntimeException('Ödeme isteği hazırlanamadı.');
        $rnd=(string)round(microtime(true)*1000).bin2hex(random_bytes(5));
        $authorization=self::buildIyzicoAuthorization($path,$body,$apiKey,$secretKey,$rnd);
        [$status,$response]=self::request($url,$body,'application/json',[
            'Authorization: '.$authorization,'x-iyzi-rnd: '.$rnd
        ]);
        $json=json_decode($response,true);
        if($status<200||$status>=300||!is_array($json))throw new \RuntimeException('iyzico geçerli bir yanıt döndürmedi (HTTP '.$status.').');
        return $json;
    }

    public static function buildIyzicoAuthorization(string $path,string $body,string $apiKey,string $secretKey,string $randomKey):string
    {
        $signature=hash_hmac('sha256',$randomKey.$path.$body,$secretKey);
        return 'IYZWSv2 '.base64_encode('apiKey:'.$apiKey.'&randomKey:'.$randomKey.'&signature:'.$signature);
    }

    private static function request(string $url,string $body,string $contentType,array $extra=[]):array
    {
        $headers=array_merge(['Content-Type: '.$contentType,'Accept: application/json'],$extra);
        if(function_exists('curl_init')){
            $ch=curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers
            ]);
            $response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if(!is_string($response))throw new \RuntimeException('Ödeme bağlantısı kurulamadı: '.$error);
            return [$status,$response];
        }
        $headerText=implode("\r\n",$headers);
        $context=stream_context_create(['http'=>['method'=>'POST','header'=>$headerText,'content'=>$body,'timeout'=>35,'ignore_errors'=>true],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $response=@file_get_contents($url,false,$context);
        if(!is_string($response))throw new \RuntimeException('Sunucuda cURL veya etkin HTTPS stream desteği bulunamadı.');
        $status=0;
        foreach(($http_response_header??[]) as $line)if(preg_match('#^HTTP/\\S+\\s+(\\d{3})#',$line,$m))$status=(int)$m[1];
        return [$status,$response];
    }
}
