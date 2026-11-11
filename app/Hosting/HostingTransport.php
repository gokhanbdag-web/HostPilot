<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

use RuntimeException;

final class HostingTransport
{
    public static function request(string $baseUrl,string $path,string $method,array $headers=[],?string $body=null,?array $basicAuth=null):array
    {
        $base=self::validateBaseUrl($baseUrl);
        if($path===''||$path[0]!=='/'||str_contains($path,"\r")||str_contains($path,"\n"))throw new RuntimeException('API yolu geçersiz.');
        if(!function_exists('curl_init'))throw new RuntimeException('Sunucunuzda PHP cURL eklentisi etkin olmalıdır.');
        $url=$base.$path;$ch=curl_init($url);
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'HostPilot/1.0'];
        if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
        if($basicAuth!==null)$opts[CURLOPT_USERPWD]=$basicAuth[0].':'.$basicAuth[1];
        curl_setopt_array($ch,$opts);$response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($response===false)throw new RuntimeException('Sunucu bağlantısı başarısız: '.$error);
        if($status<200||$status>=300)throw new RuntimeException('Kontrol paneli HTTP '.$status.' yanıtı döndürdü: '.substr(strip_tags((string)$response),0,350));
        return ['status'=>$status,'body'=>(string)$response,'json'=>json_decode((string)$response,true)];
    }

    public static function validateBaseUrl(string $url):string
    {
        $parts=parse_url(trim($url));
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new RuntimeException('Sunucu adresi HTTPS olmalı ve kullanıcı bilgisi içermemelidir.');
        $host=strtolower((string)$parts['host']);
        if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))throw new RuntimeException('Yerel sunucu adreslerine bağlantı engellendi.');
        if(filter_var($host,FILTER_VALIDATE_IP)!==false&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Özel veya rezerve IP adreslerine bağlantı engellendi.');
        $port=isset($parts['port'])?(int)$parts['port']:null;
        if($port!==null&&($port<1||$port>65535))throw new RuntimeException('Sunucu portu geçersiz.');
        $path=rtrim((string)($parts['path']??''),'/');
        return 'https://'.$host.($port!==null?':'.$port:'').$path;
    }
}
