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

        $parts=parse_url($base);
        $host=(string)($parts['host']??'');
        $host=trim($host,'[]');
        $port=(int)($parts['port']??443);
        $resolvedIp=self::resolvePublicAddress($host);
        $url=$base.$path;
        $ch=curl_init($url);
        $opts=[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>25,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CUSTOMREQUEST=>strtoupper($method),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_USERAGENT=>'HostPilot/1.0',
            // Never inherit an outbound proxy that could bypass the validated destination.
            CURLOPT_PROXY=>'',
        ];
        // Pin DNS hostnames to the exact public address validated above. TLS SNI and
        // certificate verification still use the original URL hostname.
        if(filter_var($host,FILTER_VALIDATE_IP)===false){
            $resolveAddress=filter_var($resolvedIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false?'['.$resolvedIp.']':$resolvedIp;
            $opts[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$resolveAddress];
        }
        if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
        if($basicAuth!==null)$opts[CURLOPT_USERPWD]=$basicAuth[0].':'.$basicAuth[1];
        curl_setopt_array($ch,$opts);
        $response=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($response===false)throw new RuntimeException('Sunucu bağlantısı başarısız: '.$error);
        if($status<200||$status>=300)throw new RuntimeException('Kontrol paneli HTTP '.$status.' yanıtı döndürdü: '.substr(strip_tags((string)$response),0,350));
        return ['status'=>$status,'body'=>(string)$response,'json'=>json_decode((string)$response,true)];
    }

    public static function validateBaseUrl(string $url):string
    {
        $parts=parse_url(trim($url));
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new RuntimeException('Sunucu adresi HTTPS olmalı ve kullanıcı bilgisi içermemelidir.');
        $host=rtrim(trim(strtolower((string)$parts['host']),'[]'),'.');
        if($host==='')throw new RuntimeException('Sunucu adresi geçersiz.');
        if(filter_var($host,FILTER_VALIDATE_IP)===false){
            if(preg_match('/[^\x20-\x7E]/',$host)){
                if(!function_exists('idn_to_ascii'))throw new RuntimeException('Uluslararası alan adı doğrulanamıyor; alan adını ASCII/Punycode biçiminde girin.');
                $ascii=idn_to_ascii($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46);
                if($ascii===false)throw new RuntimeException('Sunucu alan adı geçersiz.');
                $host=strtolower($ascii);
            }
            if(filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false)throw new RuntimeException('Sunucu alan adı geçersiz.');
            foreach(['localhost','local','internal','test','example','invalid','home.arpa'] as $suffix){
                if($host===$suffix||str_ends_with($host,'.'.$suffix))throw new RuntimeException('Yerel veya ayrılmış alan adlarına bağlantı engellendi.');
            }
        }elseif(!self::isPublicAddress($host)){
            throw new RuntimeException('Özel veya rezerve IP adreslerine bağlantı engellendi.');
        }
        $port=isset($parts['port'])?(int)$parts['port']:null;
        if($port!==null&&($port<1||$port>65535))throw new RuntimeException('Sunucu portu geçersiz.');
        $path=rtrim((string)($parts['path']??''),'/');
        if(str_contains($path,"\r")||str_contains($path,"\n"))throw new RuntimeException('Sunucu adresi geçersiz.');
        $urlHost=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false?'['.$host.']':$host;
        return 'https://'.$urlHost.($port!==null?':'.$port:'').$path;
    }

    /**
     * Resolve every A/AAAA answer, reject the entire host if any answer is non-public,
     * and return one validated address for CURLOPT_RESOLVE pinning.
     */
    private static function resolvePublicAddress(string $host):string
    {
        if(filter_var($host,FILTER_VALIDATE_IP)!==false){
            if(!self::isPublicAddress($host))throw new RuntimeException('Özel veya rezerve IP adreslerine bağlantı engellendi.');
            return $host;
        }
        if(!function_exists('dns_get_record'))throw new RuntimeException('Güvenli DNS doğrulaması bu sunucuda kullanılamıyor.');
        $addresses=[];
        foreach([DNS_A,DNS_AAAA] as $type){
            $records=@dns_get_record($host,$type);
            if(!is_array($records))continue;
            foreach($records as $record){
                if(($record['type']??'')==='A'&&!empty($record['ip']))$addresses[]=(string)$record['ip'];
                if(($record['type']??'')==='AAAA'&&!empty($record['ipv6']))$addresses[]=(string)$record['ipv6'];
            }
        }
        // gethostbynamel follows ordinary CNAME chains on installations where
        // dns_get_record returns only the alias record for an A lookup.
        if(!$addresses&&function_exists('gethostbynamel')){
            $fallback=@gethostbynamel($host);
            if(is_array($fallback))$addresses=array_merge($addresses,$fallback);
        }
        $addresses=array_values(array_unique($addresses));
        if(!$addresses)throw new RuntimeException('Sunucu alan adı güvenli bir IP adresine çözümlenemedi.');
        foreach($addresses as $address){
            if(!self::isPublicAddress($address))throw new RuntimeException('Sunucu alan adı özel veya rezerve bir IP adresine çözülüyor; bağlantı engellendi.');
        }
        return $addresses[0];
    }

    private static function isPublicAddress(string $ip):bool
    {
        if(filter_var($ip,FILTER_VALIDATE_IP)===false)return false;
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false){
            // Only globally-routable unicast space is accepted. This excludes
            // loopback, link-local, ULA, multicast, mapped and transition ranges.
            if(!self::inCidr($ip,'2000::/3'))return false;
            foreach(['2001::/23','2001:db8::/32','2002::/16'] as $range)if(self::inCidr($ip,$range))return false;
        }
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return false;
        foreach([
            '100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15',
            '198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'
        ] as $range)if(self::inCidr($ip,$range))return false;
        return true;
    }

    private static function inCidr(string $ip,string $cidr):bool
    {
        [$network,$bitsText]=explode('/',$cidr,2);
        $addressBytes=@inet_pton($ip);$networkBytes=@inet_pton($network);
        if($addressBytes===false||$networkBytes===false||strlen($addressBytes)!==strlen($networkBytes))return false;
        $bits=(int)$bitsText;$maxBits=strlen($addressBytes)*8;
        if($bits<0||$bits>$maxBits)return false;
        $wholeBytes=intdiv($bits,8);$remainingBits=$bits%8;
        if($wholeBytes>0&&substr($addressBytes,0,$wholeBytes)!==substr($networkBytes,0,$wholeBytes))return false;
        if($remainingBits>0){
            $mask=(0xFF << (8-$remainingBits))&0xFF;
            if((ord($addressBytes[$wholeBytes])&$mask)!==(ord($networkBytes[$wholeBytes])&$mask))return false;
        }
        return true;
    }
}
