<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/Hosting/HostingTransport.php';
require dirname(__DIR__).'/app/Hosting/HostingAdapter.php';
require dirname(__DIR__).'/app/Hosting/CpanelWhmAdapter.php';
require dirname(__DIR__).'/app/Hosting/DirectAdminAdapter.php';
require dirname(__DIR__).'/app/Hosting/PleskXmlAdapter.php';

function hostingExpect(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message.PHP_EOL;
}

hostingExpect(HostPilot\Hosting\HostingTransport::validateBaseUrl('https://whm.example.test:2087')==='https://whm.example.test:2087','HTTPS control-panel URL accepted');
foreach(['http://whm.example.test:2087','https://127.0.0.1:2087','https://localhost:2087','https://user:pass@example.test'] as $invalid){
    $rejected=false;try{HostPilot\Hosting\HostingTransport::validateBaseUrl($invalid);}catch(Throwable){$rejected=true;}
    hostingExpect($rejected,'Unsafe server URL rejected: '.$invalid);
}
$cpanel=new HostPilot\Hosting\CpanelWhmAdapter(['base_url'=>'https://whm.example.test:2087','username'=>'root','secret'=>'test']);
$badCpanel=false;try{$cpanel->createAccount(['username'=>'Bad_Name','domain'=>'example.test','password'=>'secret','email'=>'user@example.test','package'=>'Starter']);}catch(Throwable){$badCpanel=true;}
hostingExpect($badCpanel,'Invalid cPanel username rejected before API request');

$directAdmin=new HostPilot\Hosting\DirectAdminAdapter(['base_url'=>'https://da.example.test:2222','username'=>'admin','secret'=>'test']);
$badDa=false;try{$directAdmin->createAccount(['username'=>'abc','domain'=>'example.test','password'=>'secret','email'=>'user@example.test','package'=>'Starter']);}catch(Throwable){$badDa=true;}
hostingExpect($badDa,'Invalid DirectAdmin username rejected before API request');

$plesk=new HostPilot\Hosting\PleskXmlAdapter(['base_url'=>'https://plesk.example.test:8443','username'=>'admin','secret'=>'test','shared_ip'=>'']);
$badPlesk=false;try{$plesk->createAccount(['username'=>'hp123456','domain'=>'example.test','password'=>'secret','email'=>'user@example.test','package'=>'Starter']);}catch(Throwable){$badPlesk=true;}
hostingExpect($badPlesk,'Plesk account creation requires a valid shared IP');


$contract=new ReflectionClass(HostPilot\Hosting\HostingAdapter::class);
hostingExpect($contract->hasMethod('suspendAccount'),'Hosting adapter contract exposes account suspension');
hostingExpect($contract->hasMethod('unsuspendAccount'),'Hosting adapter contract exposes account reactivation');
hostingExpect($contract->hasMethod('terminateAccount'),'Hosting adapter contract exposes permanent account termination');
hostingExpect($contract->hasMethod('changePackage'),'Hosting adapter contract exposes package changes');
$daPackageRejected=false;try{$directAdmin->changePackage('validuser','example.test','Business');}catch(RuntimeException $e){$daPackageRejected=str_contains($e->getMessage(),'OpenAPI sözleşmesi');}
hostingExpect($daPackageRejected,'DirectAdmin package change fails safely until server-specific API contract is verified');
$pleskMissingPlanRejected=false;try{$plesk->changePackage('hp123456','example.test','Business');}catch(RuntimeException $e){$pleskMissingPlanRejected=str_contains($e->getMessage(),'GUID');}
hostingExpect($pleskMissingPlanRejected,'Plesk package change requires both plan identifiers before network access');
foreach([HostPilot\Hosting\CpanelWhmAdapter::class,HostPilot\Hosting\DirectAdminAdapter::class,HostPilot\Hosting\PleskXmlAdapter::class] as $adapterClass){
    $adapter=new ReflectionClass($adapterClass);
    hostingExpect($adapter->implementsInterface(HostPilot\Hosting\HostingAdapter::class),'Adapter implements shared lifecycle contract: '.$adapterClass);
    hostingExpect($adapter->hasMethod('suspendAccount')&&$adapter->hasMethod('unsuspendAccount'),'Adapter provides suspend/reactivate methods: '.$adapterClass);
    hostingExpect($adapter->hasMethod('terminateAccount'),'Adapter provides termination method: '.$adapterClass);
    hostingExpect($adapter->hasMethod('changePackage'),'Adapter provides package change method: '.$adapterClass);
}

echo 'Hosting adapter smoke tests completed.'.PHP_EOL;
