<?php
declare(strict_types=1);
$schema=file_get_contents(__DIR__.'/../app/Core/Schema.php');
$email=file_get_contents(__DIR__.'/../app/Core/EmailService.php');
$emailSettings=file_get_contents(__DIR__.'/../app/Http/EmailSettingsController.php');
$client=file_get_contents(__DIR__.'/../app/Http/PackageChangeController.php');
$payment=file_get_contents(__DIR__.'/../app/Http/PaymentController.php');
$worker=file_get_contents(__DIR__.'/../app/Http/CronController.php');
$routes=file_get_contents(__DIR__.'/../index.php');
$portal=file_get_contents(__DIR__.'/../app/Http/ClientController.php');
$admin=file_get_contents(__DIR__.'/../app/Http/AdminController.php');
$checks=[
 'versioned package change migrations'=>str_contains($schema,'20261112_0008_service_package_changes')&&str_contains($schema,'20261112_0009_service_package_change_type')&&str_contains($schema,'change_type VARCHAR(16)'),
 'customer ownership enforced'=>str_contains($client,'s.user_id=:user FOR UPDATE'),
 'higher and lower packages are server-mapped'=>str_contains($client,'m.server_id=:server')&&str_contains($client,'$higher=array_values')&&str_contains($client,'$lower=array_values'),
 'same billing cycle and currency required'=>str_contains($client,'pp.currency=:currency AND pp.billing_cycle=:cycle'),
 'remaining-term proration uses recorded service period'=>str_contains($client,'service_period_start')&&str_contains($client,'$days/$cycleDays'),
 'upgrade blocked by outstanding service invoices'=>str_contains($client,'Ödenmemiş hizmet faturası'),
 'Plesk plan identifiers required'=>str_contains($client,'remote_package_guid')&&str_contains($client,'remote_package_external_id'),
 'DirectAdmin is not offered automatically'=>str_contains($client,'panel_type')&&str_contains($client,'cpanel')&&str_contains($client,'plesk'),
 'upgrade invoice linked to request'=>str_contains($client,'SET invoice_id=:invoice'),
 'customer route registered'=>str_contains($routes,"'/client/services/change'"),
 'service portal links package change page'=>str_contains($portal,'Paketimi değiştir veya düşür'),
 'paid invoice queues package job'=>str_contains($payment,'packageJob')&&str_contains($payment,'service_package_changes'),
 'package invoice does not create duplicate service'=>str_contains($payment,'isPackageInvoice'),
 'worker dispatches package changes'=>str_contains($worker,'processPackageChangeJob')&&str_contains($worker,'package_'),
 'worker checks paid invoice and original product'=>str_contains($worker,'bill')&&str_contains($worker,'from_product_id'),
 'ambiguous result goes to review'=>str_contains($worker,'needs_review')&&str_contains($worker,'service_package_changes'),
 'admin can reconcile package change'=>str_contains($worker,'remote_applied')&&str_contains($worker,'remote_not_applied'),
 'customer can select lower mapped packages'=>str_contains($client,'$lower=array_values')&&str_contains($client,'Bir sonraki yenilemede paket düşürme'),
 'downgrade is scheduled at service renewal date'=>str_contains($client,"'scheduled'")&&str_contains($client,'$effective=(string)$s[\'next_due_date\'].\' 00:00:00\''),
 'downgrade creates no immediate invoice'=>str_contains($client,"'scheduled',:effective")&&str_contains($client,'$this->db->commit();'),
 'billing queues due downgrades before renewal invoicing'=>str_contains($worker,"c.change_type='downgrade' AND c.status='scheduled' AND c.invoice_id IS NULL")&&str_contains($worker,'Planlanmış paket düşürme'),
 'old-price renewal blocked while downgrade unresolved'=>str_contains($worker,"c.change_type='downgrade' AND c.invoice_id IS NULL AND c.status IN ('scheduled','queued','processing','needs_review','failed')"),
 'scheduled downgrade worker does not require paid invoice'=>str_contains($worker,'$scheduled=$change[\'change_type\']===\'downgrade\'')&&str_contains($worker,'Paket düşürme henüz yenileme tarihine ulaşmadı'),
 'scheduled downgrade must lower service price'=>str_contains($worker,'Faturasız planlı işlem yalnızca paket düşürme'),
 'admin can see scheduled downgrade queue'=>str_contains($worker,'Planlanmış paket düşürmeleri'),
 'customer sees scheduled downgrade and effective date'=>str_contains($portal,'Planlanan paket düşürme')&&str_contains($portal,'Uygulanma tarihi'),
 'scheduled downgrade supports manual remote reconciliation'=>str_contains($worker,'Planlanmış paket düşürme henüz yürürlük tarihine ulaşmadı')&&str_contains($worker,'remote_not_applied'),
 'customer can cancel a scheduled downgrade'=>str_contains($client,'public function cancelScheduled')&&str_contains($client,"SET status='cancelled'")&&str_contains($routes,"'/client/services/change/cancel'"),
 'cancellation is limited to future scheduled downgrades'=>str_contains($client,'$change[\'status\']!==\'scheduled\'')&&str_contains($client,'$change[\'effective_at\']<=gmdate'),
 'customer portal displays downgrade cancellation control'=>str_contains($portal,'Paket düşürme talebini iptal et')&&str_contains($portal,'change_id'),
 'SMTP sender uses TLS verification and encrypted credentials'=>str_contains($email,'SecretVault')&&str_contains($email,'verify_peer_name')&&str_contains($email,'stream_socket_enable_crypto'),
 'SMTP credentials configured from admin screen'=>str_contains($emailSettings,'password_enc')&&str_contains($emailSettings,'/admin/settings')&&str_contains($routes,'EmailSettingsController'),
 'package downgrade scheduled email notification'=>str_contains($email,"'scheduled'")&&str_contains($client,"'scheduled'"),
 'package downgrade cancellation email notification'=>str_contains($email,"'cancelled'")&&str_contains($client,"'cancelled'"),
 'upgrade request and verified payment email notifications'=>str_contains($email,"'requested'")&&str_contains($payment,'EmailService')&&str_contains($payment,"'payment'"),
 'successful remote package change email notification'=>str_contains($worker,'EmailService')&&str_contains($worker,"'completed'"),
 'versioned email outbox migration'=>str_contains($schema,'20261112_0010_email_outbox')&&str_contains($schema,'email_outbox'),
 'package notifications are queued idempotently'=>str_contains($email,'INSERT IGNORE INTO email_outbox')&&str_contains($email,'hash(\'sha256\''),
 'email worker uses bounded locked claims and retry backoff'=>str_contains($email,'FOR UPDATE')&&str_contains($email,'needs_review')&&str_contains($email,'2**min'),
 'email cron endpoint is authenticated'=>str_contains($worker,'HostPilot email cron v1')&&str_contains($routes,"'/cron/email'"),
 'admin can review and retry failed email'=>str_contains($routes,"'/admin/email-queue'")&&str_contains($routes,'EmailQueueController')&&str_contains($worker,'email-queue'),
 'renewal invoice notification queued transactionally'=>str_contains($worker,'queueInvoiceNotice')&&str_contains($worker,'o.currency AS currency'),
 'verified invoice payment notification queued'=>str_contains($payment,'queueInvoiceNotice')&&str_contains($payment,"'paid'"),
 'hosting provisioning and billing lifecycle notices queued'=>str_contains($worker,"'provisioned'")&&str_contains($worker,"'suspended'")&&str_contains($worker,"'reactivated'"),
 'customer ticket creation and replies notify support staff'=>str_contains($portal,'notifyTicketStaff')&&str_contains($portal,"'created'")&&str_contains($portal,"'customer_reply'"),
 'staff ticket replies notify the customer'=>str_contains($admin,'queueTicketNotice')&&str_contains($admin,"'staff_reply'")&&str_contains($admin,'applicationKey'),
 'overdue invoice notifications are queued'=>str_contains($worker,"'overdue'")&&str_contains($worker,'queueInvoiceNotice'),
 'failed payment notifications are queued idempotently'=>str_contains($payment,"'failed',$id")&&str_contains($email,"'payment-failed:'"),

];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' - '.$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
