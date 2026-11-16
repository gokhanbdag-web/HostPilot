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
