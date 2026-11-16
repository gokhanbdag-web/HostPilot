<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Core/SecurityAudit.php';

use HostPilot\Core\SecurityAudit;

if (!SecurityAudit::isAllowedEvent('auth.mfa_enabled') || !SecurityAudit::isAllowedEvent('auth.mfa_challenge_succeeded')) throw new RuntimeException('MFA audit events were not allowlisted.');
if (!SecurityAudit::isAllowedEvent('privacy.exported') || !SecurityAudit::isAllowedEvent('privacy.erasure_requested') || !SecurityAudit::isAllowedEvent('privacy.request_reviewed') || !SecurityAudit::isAllowedEvent('privacy.erasure_completed')) throw new RuntimeException('Privacy audit events were not allowlisted.');
if (!SecurityAudit::isAllowedEvent('auth.login_succeeded')) throw new RuntimeException('Known event was rejected.');
if (SecurityAudit::isAllowedEvent('auth.custom_payload')) throw new RuntimeException('Unknown event was accepted.');
$hash = SecurityAudit::hashIp('192.0.2.10');
if (!is_string($hash) || strlen($hash) !== 64 || $hash === '192.0.2.10') throw new RuntimeException('IP hash policy failed.');
if (SecurityAudit::hashIp('not-an-ip') !== null) throw new RuntimeException('Invalid IP should not be retained.');
if (SecurityAudit::sanitizeUserAgent("Browser\r\nInjected: yes") !== 'BrowserInjected: yes') throw new RuntimeException('User-agent control characters were not removed.');
if (SecurityAudit::sanitizeUserAgent(str_repeat('a', 300)) !== str_repeat('a', 255)) throw new RuntimeException('User-agent length limit failed.');
$metadata = SecurityAudit::sanitizeMetadata(['role' => 'admin', 'email' => 'secret@example.test', 'token' => 'secret', 'request_id' => 7, 'action' => 'declined']);
if ($metadata !== ['role' => 'admin', 'request_id' => 7, 'action' => 'declined']) throw new RuntimeException('Sensitive metadata was not discarded.');
echo 'PASS security audit event allowlist, IP hashing, user-agent sanitization and metadata minimization.' . PHP_EOL;
