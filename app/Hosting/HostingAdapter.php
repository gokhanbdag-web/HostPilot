<?php
declare(strict_types=1);
namespace HostPilot\Hosting;

interface HostingAdapter
{
    public function testConnection():string;
    /** @return array{identifier:string,username:string,domain:string} */
    public function createAccount(array $account):array;
}
