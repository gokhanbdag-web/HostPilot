<?php
declare(strict_types=1);

$files=[
    'PaymentController.php'=>[
        'payment.gateway_settings_updated','payment.transaction_reviewed','payment.transaction_settled','payment.transaction_failed','payment.finance_report_exported',
    ],
    'ServerController.php'=>[
        'server.created','server.connection_tested','server.product_mapping_updated','service.provision_completed',
        'service.provision_failed','service.lifecycle_completed','service.lifecycle_failed','service.termination_requested',
    ],
    'DomainController.php'=>[
        'domain.registrar_settings_updated','domain.nameservers_change_requested','domain.renewal_requested',
        'domain.expiry_sync_queued','domain.renewal_reconciled','domain.nameservers_reconciled',
        'domain.registration_reconciled','domain.exported',
    ],
];
foreach($files as $file=>$events){
    $source=file_get_contents(__DIR__.'/../app/Http/'.$file);
    if(!is_string($source))throw new RuntimeException('Cannot read '.$file);
    foreach($events as $event)if(!str_contains($source,"'".$event."'"))throw new RuntimeException($file.' missing audit event '.$event);
    if(!str_contains($source,'SecurityAudit::record'))throw new RuntimeException($file.' does not write security audit events');
}
$audit=file_get_contents(__DIR__.'/../app/Core/SecurityAudit.php');
if(!is_string($audit)||!str_contains($audit,"'resource_id'"))throw new RuntimeException('Resource identifiers are not supported by audit metadata.');
echo "HostPilot operational audit coverage tests passed.\n";
