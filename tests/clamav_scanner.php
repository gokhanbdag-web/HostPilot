<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Core/ClamAvScanner.php';

use HostPilot\Core\ClamAvScanner;

$allowed=[
    'unix:///var/run/clamav/clamd.ctl',
    'unix:///run/clamav/clamd.ctl',
    'tcp://127.0.0.1:3310',
    'tcp://localhost:3310',
    'tcp://[::1]:3310',
];
foreach($allowed as $endpoint){
    if(!ClamAvScanner::isAllowedEndpoint($endpoint))throw new RuntimeException('Local ClamAV endpoint rejected: '.$endpoint);
}
$denied=[
    'tcp://example.com:3310',
    'tcp://192.168.1.10:3310',
    'tcp://10.0.0.2:3310',
    'tcp://0.0.0.0:3310',
    'tcp://127.0.0.1:0',
    'tcp://127.0.0.1:65536',
    'https://127.0.0.1:3310',
    'file:///etc/passwd',
    'tcp://user:pass@127.0.0.1:3310',
    'tcp://127.0.0.1:3310/path',
    'unix://relative/clamd.sock',
    'unix:///tmp/../etc/passwd',
    '',
];
foreach($denied as $endpoint){
    if(ClamAvScanner::isAllowedEndpoint($endpoint))throw new RuntimeException('Unsafe ClamAV endpoint accepted: '.$endpoint);
}

$calls=0;
$scanner=new ClamAvScanner(static function(string $endpoint)use(&$calls){$calls++;return false;});
if($scanner->ping('tcp://example.com:3310'))throw new RuntimeException('Remote scanner ping unexpectedly succeeded.');
if($calls!==0)throw new RuntimeException('Rejected remote endpoint reached the connector.');
if($scanner->ping('tcp://127.0.0.1:3310'))throw new RuntimeException('Unavailable local scanner unexpectedly succeeded.');
if($calls!==1)throw new RuntimeException('Allowed local scanner connector was not called exactly once.');

$missing=$scanner->scanFile(sys_get_temp_dir().'/hostpilot-no-such-attachment-'.bin2hex(random_bytes(6)),'tcp://127.0.0.1:3310');
if(($missing['status']??'')!=='unavailable'||$calls!==1)throw new RuntimeException('Missing attachment should fail closed without opening a socket.');

$tmp=tempnam(sys_get_temp_dir(),'hp-clamav-');
if($tmp===false)throw new RuntimeException('Cannot create scanner test file.');
try{
    file_put_contents($tmp,'harmless test payload');
    $result=$scanner->scanFile($tmp,'tcp://127.0.0.1:3310');
    if(($result['status']??'')!=='unavailable'||$calls!==2)throw new RuntimeException('Unavailable ClamAV daemon must fail closed.');
}finally{@unlink($tmp);}

$source=file_get_contents(dirname(__DIR__).'/app/Core/ClamAvScanner.php');
foreach(["INSTREAM\\n","pack('N',strlen("),"pack('N',0)","FOUND","'clean'","'infected'","'unavailable'"] as $needle){
    if(!is_string($source)||!str_contains($source,$needle))throw new RuntimeException('ClamAV protocol implementation is missing '.$needle);
}
echo "ClamAV scanner endpoint and fail-closed policy tests passed.\n";
