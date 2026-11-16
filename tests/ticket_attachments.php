<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Http/TicketAttachmentController.php';
use HostPilot\Http\TicketAttachmentController;
$allowed=['application/pdf','image/jpeg','image/png','text/plain'];
foreach($allowed as $mime)if(!TicketAttachmentController::allowedMime($mime))throw new RuntimeException('Allowed MIME rejected: '.$mime);
foreach(['image/svg+xml','text/html','application/zip','application/x-php','application/octet-stream','image/gif'] as $mime)if(TicketAttachmentController::allowedMime($mime))throw new RuntimeException('Unsafe MIME accepted: '.$mime);
if(TicketAttachmentController::maxBytes()!==5242880)throw new RuntimeException('Unexpected upload size policy.');
if(!TicketAttachmentController::isCustomerRole('customer'))throw new RuntimeException('Customer role must be allowed to download owned ticket attachments.');
if(!TicketAttachmentController::isCustomerRole('client'))throw new RuntimeException('Client role alias must be allowed to download owned ticket attachments.');
foreach(['staff','admin','super_admin','guest'] as $role)if(TicketAttachmentController::isCustomerRole($role))throw new RuntimeException('Non-customer role accepted for customer attachment access: '.$role);
echo "Ticket attachment policy tests passed.\n";
