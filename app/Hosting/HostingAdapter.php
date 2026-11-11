<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

interface HostingAdapter
{
    public function testConnection():string;
    /** @return array{identifier:string,username:string,domain:string} */
    public function createAccount(array $account):array;
    public function suspendAccount(string $username,string $domain,string $reason):void;
    public function unsuspendAccount(string $username,string $domain):void;
    /** Permanently removes the remote hosting account. */
    public function terminateAccount(string $username,string $domain):void;
    /** Changes the remote hosting plan. Plesk requires both plan GUID and external ID. */
    public function changePackage(string $username,string $domain,string $package,string $packageGuid='',string $packageExternalId=''):void;
}
