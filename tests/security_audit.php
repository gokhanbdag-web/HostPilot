<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Core/SecurityAudit.php';

use HostPilot\Core\SecurityAudit;

if (!SecurityAudit::isAllowedEvent('auth.mfa_enabled') || !SecurityAudit::isAllowedEvent('auth.mfa_challenge_succeeded')) throw new RuntimeException('MFA audit events were not allowlisted.');
if (!SecurityAudit::isAllowedEvent('staff.permissions_updated')) throw new RuntimeException('Staff permission audit event was not allowlisted.');
if (!SecurityAudit::isAllowedEvent('privacy.exported') || !SecurityAudit::isAllowedEvent('privacy.erasure_requested') || !SecurityAudit::isAllowedEvent('privacy.request_reviewed') || !SecurityAudit::isAllowedEvent('privacy.erasure_completed')) throw new RuntimeException('Privacy audit events were not allowlisted.');
foreach ([
    'payment.gateway_settings_updated','payment.transaction_reviewed','payment.transaction_settled','payment.transaction_failed',
    'server.created','server.connection_tested','server.product_mapping_updated',
    'service.provision_completed','service.provision_failed','service.lifecycle_completed','service.lifecycle_failed','service.termination_requested',
    'domain.registrar_settings_updated','domain.nameservers_change_requested','domain.renewal_requested','domain.expiry_sync_queued',
    'domain.renewal_reconciled','domain.nameservers_reconciled','domain.registration_reconciled','domain.exported'
] as $event) if (!SecurityAudit::isAllowedEvent($event)) throw new RuntimeException('Operational audit event not allowlisted: '.$event);
foreach (['security.attachment_scanner_settings_updated','attachment.scanned_clean','attachment.scan_infected','attachment.scan_unavailable','attachment.uploaded_unscanned'] as $event) {
    if (!SecurityAudit::isAllowedEvent($event)) throw new RuntimeException('Attachment scan audit event not allowlisted: '.$event);
}
$attachmentMetadata = SecurityAudit::sanitizeMetadata(['resource_id'=>12,'action'=>'unavailable','signature'=>'Eicar.Test']);
if ($attachmentMetadata !== ['resource_id'=>12,'action'=>'unavailable']) throw new RuntimeException('Attachment scan metadata was not safely minimized.');
foreach (['payment.transaction_reconciled','payment.manual_refund_confirmed','payment.partial_refund_recorded'] as $event) {
    if (!SecurityAudit::isAllowedEvent($event)) throw new RuntimeException('Payment reconciliation audit event not allowlisted: '.$event);
}
$paymentMetadata = SecurityAudit::sanitizeMetadata(['resource_id'=>42,'action'=>'refund_confirmed','provider_reference'=>'private-reference']);
$partialRefundMetadata = SecurityAudit::sanitizeMetadata(['resource_id'=>42,'refund_id'=>73,'action'=>'refund_confirmed','provider_reference'=>'private-reference']);
if ($partialRefundMetadata !== ['resource_id'=>42,'refund_id'=>73,'action'=>'refund_confirmed']) throw new RuntimeException('Partial refund audit metadata was not safely minimized.');
if ($paymentMetadata !== ['resource_id'=>42,'action'=>'refund_confirmed']) throw new RuntimeException('Payment reconciliation metadata was not minimized.');
if (!SecurityAudit::isAllowedEvent('auth.login_succeeded')) throw new RuntimeException('Known event was rejected.');
if (SecurityAudit::isAllowedEvent('auth.custom_payload')) throw new RuntimeException('Unknown event was accepted.');
$hash = SecurityAudit::hashIp('192.0.2.10');
if (!is_string($hash) || strlen($hash) !== 64 || $hash === '192.0.2.10') throw new RuntimeException('IP hash policy failed.');
if (SecurityAudit::hashIp('not-an-ip') !== null) throw new RuntimeException('Invalid IP should not be retained.');
if (SecurityAudit::sanitizeUserAgent("Browser\r\nInjected: yes") !== 'BrowserInjected: yes') throw new RuntimeException('User-agent control characters were not removed.');
if (SecurityAudit::sanitizeUserAgent(str_repeat('a', 300)) !== str_repeat('a', 255)) throw new RuntimeException('User-agent length limit failed.');
$metadata = SecurityAudit::sanitizeMetadata(['role' => 'admin', 'email' => 'secret@example.test', 'token' => 'secret', 'request_id' => 7, 'action' => 'declined']);
if ($metadata !== ['role' => 'admin', 'request_id' => 7, 'action' => 'declined']) throw new RuntimeException('Sensitive metadata was not discarded.');
$permissionMetadata = SecurityAudit::sanitizeMetadata(['target_user_id'=>10,'action'=>'updated','email'=>'private@example.test']);
if ($permissionMetadata !== ['target_user_id'=>10,'action'=>'updated']) throw new RuntimeException('Staff permission audit metadata was not safely allowlisted.');
$resourceMetadata = SecurityAudit::sanitizeMetadata(['resource_id'=>25,'action'=>'queued','api_key'=>'secret']);
if ($resourceMetadata !== ['resource_id'=>25,'action'=>'queued']) throw new RuntimeException('Operational audit metadata was not safely allowlisted.');
$completedMetadata = SecurityAudit::sanitizeMetadata(['request_id'=>9,'action'=>'completed','email'=>'private@example.test']);
if ($completedMetadata !== ['request_id'=>9,'action'=>'completed']) throw new RuntimeException('Completed erasure audit metadata was not safely allowlisted.');
echo 'PASS security audit event allowlist, IP hashing, user-agent sanitization and metadata minimization.' . PHP_EOL;
