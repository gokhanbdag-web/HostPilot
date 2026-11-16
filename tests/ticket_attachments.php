<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Http/TicketAttachmentController.php';
use HostPilot\\Http\\TicketAttachmentController;
$allowed=['application/pdf','image/jpeg','image/png','text/plain'];
foreach($allowed as $mime)if(!TicketAttachmentController::allowedMime($mime))throw new RuntimeException('Allowed MIME rejected: '.$mime);
foreach(['image/svg+xml','text/html','application/zip','application/x-php','application/octet-stream','image/gif'] as $mime)if(TicketAttachmentController::allowedMime($mime))throw new RuntimeException('Unsafe MIME accepted: '.$mime);
if(TicketAttachmentController::maxBytes()!==5242880)throw new RuntimeException('Unexpected upload size policy.');
echo "Ticket attachment policy tests passed.\\n";
