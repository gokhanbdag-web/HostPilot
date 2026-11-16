<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Core/CronAuthenticator.php';

use HostPilot\Core\CronAuthenticator;

$key=str_repeat('k',64);
$purpose='HostPilot email cron v1';
$token=hash_hmac('sha256',$purpose,$key);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message.PHP_EOL;
};
$assert(CronAuthenticator::isAuthorized($key,$purpose,['HTTP_AUTHORIZATION'=>'Bearer '.$token]),'Authorization Bearer header is accepted');
$assert(CronAuthenticator::isAuthorized($key,$purpose,['REDIRECT_HTTP_AUTHORIZATION'=>'Bearer '.$token]),'Apache/FastCGI forwarded Authorization header is accepted');
$assert(CronAuthenticator::isAuthorized($key,$purpose,['HTTP_X_HOSTPILOT_CRON_TOKEN'=>$token]),'dedicated cron token header is accepted');
$assert(!CronAuthenticator::isAuthorized($key,$purpose,['QUERY_STRING'=>'token='.$token,'REQUEST_URI'=>'/cron/email?token='.$token]),'query-string token alone is rejected');
$assert(!CronAuthenticator::isAuthorized($key,$purpose,['HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('0',64)]),'incorrect bearer token is rejected');
$assert(!CronAuthenticator::isAuthorized($key,$purpose,['HTTP_AUTHORIZATION'=>'Basic '.$token]),'non-Bearer authorization scheme is rejected');
$assert(!CronAuthenticator::isAuthorized('',$purpose,['HTTP_AUTHORIZATION'=>'Bearer '.$token]),'empty application key is rejected');
$assert(!CronAuthenticator::isAuthorized($key,'HostPilot domain cron v1',['HTTP_AUTHORIZATION'=>'Bearer '.$token]),'tokens are scoped to their worker purpose');
foreach(['CronController.php','DomainController.php'] as $file){
    $source=file_get_contents(__DIR__.'/../app/Http/'.$file);
    if(!is_string($source)||str_contains($source,"\$_GET['token']"))throw new RuntimeException('Query-token fallback remains in '.$file);
    $assert(str_contains($source,'CronAuthenticator::isAuthorized'),'header-only helper is used by '.$file);
}
$htaccess=file_get_contents(__DIR__.'/../.htaccess');
$assert(is_string($htaccess)&&str_contains($htaccess,'HTTP_AUTHORIZATION:%{HTTP:Authorization}'),'Apache forwards Authorization to PHP');
echo "HostPilot cron authentication policy tests passed.\n";
