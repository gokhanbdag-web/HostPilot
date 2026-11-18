<?php
declare(strict_types=1);

$interface=file_get_contents(dirname(__DIR__).'/app/Core/DomainRegistrarInterface.php');
$factory=file_get_contents(dirname(__DIR__).'/app/Core/RegistrarFactory.php');
$client=file_get_contents(dirname(__DIR__).'/app/Core/ResellerClubClient.php');
$domain=file_get_contents(dirname(__DIR__).'/app/Http/DomainController.php');
foreach([$interface,$factory,$client,$domain] as $source)if(!is_string($source))throw new RuntimeException('Registrar abstraction source missing.');
$methods=['checkAvailability','createCustomer','createContact','registerDomain','updateNameservers','renewDomain','domainDetails','actionStatus'];
$checks=[
 'registrar interface declares every domain lifecycle operation'=>count(array_filter($methods,fn($m)=>str_contains($interface,'function '.$m.'(')))===count($methods),
 'ResellerClub client implements the provider contract'=>str_contains($client,'implements DomainRegistrarInterface'),
 'factory exposes supported provider identifiers'=>str_contains($factory,"return ['resellerclub'];"),
 'factory creates the ResellerClub adapter'=>str_contains($factory,'$provider===\'resellerclub\'')&&str_contains($factory,'new ResellerClubClient'),
 'factory rejects unsupported providers'=>str_contains($factory,'Desteklenmeyen domain registrar sağlayıcısı'),
 'domain controller no longer constructs a provider directly'=>!str_contains($domain,'new ResellerClubClient')&&str_contains($domain,"RegistrarFactory::create('resellerclub'"),
 'registrar contract retains optional EAQ polling'=>str_contains($interface,'actionStatus(string $orderId,string $eaqId=\'\')'),
];
foreach($checks as $label=>$ok){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
echo "HostPilot registrar abstraction policy tests passed.".PHP_EOL;
