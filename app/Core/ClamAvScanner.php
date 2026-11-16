<?php
declare(strict_types=1);

namespace HostPilot\Core;

use Closure;

final class ClamAvScanner
{
    private ?Closure $connector;

    public function __construct(?Closure $connector=null)
    {
        $this->connector=$connector;
    }

    public static function isAllowedEndpoint(string $endpoint):bool
    {
        $endpoint=trim($endpoint);
        if($endpoint===''||strlen($endpoint)>240||str_contains($endpoint,'..'))return false;
        if(str_starts_with($endpoint,'unix://')){
            $path=substr($endpoint,7);
            return $path!==''&&str_starts_with($path,'/')&&!str_contains($path,"\0");
        }
        if(!str_starts_with($endpoint,'tcp://'))return false;
        $parts=parse_url($endpoint);
        if(!is_array($parts)||!isset($parts['host'],$parts['port']))return false;
        $host=strtolower(trim((string)$parts['host'],'[]'));
        return in_array($host,['127.0.0.1','localhost','::1'],true)&&(int)$parts['port']>0&&(int)$parts['port']<=65535;
    }

    public function ping(string $endpoint):bool
    {
        if(!self::isAllowedEndpoint($endpoint))return false;
        $socket=$this->connect($endpoint);
        if(!is_resource($socket))return false;
        try{
            stream_set_timeout($socket,3);
            if(!$this->writeAll($socket,"PING\n"))return false;
            $response=fgets($socket,128);
            return is_string($response)&&trim($response)==='PONG';
        }finally{fclose($socket);}
    }

    public function scanFile(string $file,string $endpoint):array
    {
        if(!self::isAllowedEndpoint($endpoint)||!is_file($file)||!is_readable($file))return ['status'=>'unavailable','signature'=>null];
        $input=@fopen($file,'rb');if(!is_resource($input))return ['status'=>'unavailable','signature'=>null];
        $socket=$this->connect($endpoint);
        if(!is_resource($socket)){fclose($input);return ['status'=>'unavailable','signature'=>null];}
        try{
            stream_set_timeout($socket,8);
            if(!$this->writeAll($socket,"INSTREAM\n"))return ['status'=>'unavailable','signature'=>null];
            while(!feof($input)){
                $chunk=fread($input,65536);
                if($chunk===false)return ['status'=>'unavailable','signature'=>null];
                if($chunk==='')continue;
                if(!$this->writeAll($socket,pack('N',strlen($chunk)).$chunk))return ['status'=>'unavailable','signature'=>null];
            }
            if(!$this->writeAll($socket,pack('N',0)))return ['status'=>'unavailable','signature'=>null];
            $response=fgets($socket,4096);
            if(!is_string($response))return ['status'=>'unavailable','signature'=>null];
            $response=trim($response);
            if(preg_match('/^stream:\s*OK$/i',$response))return ['status'=>'clean','signature'=>null];
            if(preg_match('/^stream:\s*(.+?)\s+FOUND$/i',$response,$match)){
                $signature=preg_replace('/[^A-Za-z0-9_. -]/','',(string)$match[1]);
                return ['status'=>'infected','signature'=>substr((string)$signature,0,120)];
            }
            return ['status'=>'unavailable','signature'=>null];
        }catch(\Throwable){return ['status'=>'unavailable','signature'=>null];}
        finally{fclose($input);fclose($socket);}
    }

    private function connect(string $endpoint)
    {
        if($this->connector!==null){
            try{return ($this->connector)($endpoint);}catch(\Throwable){return false;}
        }
        $errno=0;$error='';
        return @stream_socket_client($endpoint,$errno,$error,2,STREAM_CLIENT_CONNECT);
    }

    private function writeAll($socket,string $data):bool
    {
        $length=strlen($data);$offset=0;
        while($offset<$length){
            $written=@fwrite($socket,substr($data,$offset));
            if($written===false||$written===0)return false;
            $offset+=$written;
        }
        return true;
    }
}
