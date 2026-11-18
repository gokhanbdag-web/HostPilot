<?php
declare(strict_types=1);

namespace HostPilot\Core;

interface DomainRegistrarInterface
{
    public function checkAvailability(string $domain):array;
    public function createCustomer(array $customer):string;
    public function createContact(array $contact):string;
    public function registerDomain(string $domain,int $years,string $customerId,string $contactId,array $nameservers,string $invoiceOption,bool $autoRenew=false):array;
    public function updateNameservers(string $orderId,array $nameservers):array;
    public function renewDomain(string $orderId,int $years,int $currentExpiryTimestamp,bool $autoRenew=false):array;
    public function domainDetails(string $orderId):array;
    public function actionStatus(string $orderId,string $eaqId=''):array;
}
