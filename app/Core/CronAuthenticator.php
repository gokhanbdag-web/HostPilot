<?php
declare(strict_types=1);

namespace HostPilot\Core;

/**
 * Authenticate scheduled workers without putting bearer secrets in URLs.
 * Supports Authorization, Apache/FastCGI's REDIRECT_HTTP_AUTHORIZATION,
 * and an explicit X-HostPilot-Cron-Token header for constrained hosts.
 */
final class CronAuthenticator
{
    public static function isAuthorized(string $applicationKey,string $purpose,array $server):bool
    {
        if($applicationKey===''||$purpose==='')return false;
        $expected=hash_hmac('sha256',$purpose,$applicationKey);
        $candidates=[];
        foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $key){
            $header=trim((string)($server[$key]??''));
            if($header!==''&&preg_match('/^Bearer\s+([a-f0-9]{64})$/i',$header,$match))$candidates[]=strtolower($match[1]);
        }
        $custom=trim((string)($server['HTTP_X_HOSTPILOT_CRON_TOKEN']??''));
        if(preg_match('/^[a-f0-9]{64}$/i',$custom))$candidates[]=strtolower($custom);
        foreach($candidates as $candidate)if(hash_equals($expected,$candidate))return true;
        return false;
    }
}
