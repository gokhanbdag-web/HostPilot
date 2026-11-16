<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;
use Throwable;

/**
 * Minimal security audit trail. Never stores raw IP addresses, credentials,
 * tokens, email addresses, or request payloads.
 */
final class SecurityAudit
{
    private const EVENTS = [
        'auth.login_succeeded',
        'auth.login_failed',
        'auth.sessions_revoked',
        'auth.password_reset_completed',
        'auth.mfa_enabled',
        'auth.mfa_disabled',
        'auth.mfa_challenge_succeeded',
        'auth.mfa_challenge_failed',
        'staff.permissions_updated',
        'auth.mfa_recovery_used',
        'auth.mfa_recovery_rotated',
        'auth.customer_mfa_enabled',
        'auth.customer_mfa_disabled',
        'auth.customer_mfa_challenge_succeeded',
        'auth.customer_mfa_challenge_failed',
        'auth.customer_mfa_recovery_used',
        'auth.customer_mfa_recovery_rotated',
        'privacy.exported',
        'privacy.erasure_requested',
        'privacy.request_reviewed',
        'privacy.erasure_completed',
        'payment.gateway_settings_updated',
        'payment.transaction_reviewed',
        'payment.transaction_settled',
        'payment.transaction_failed',
        'payment.transaction_reconciled',
        'payment.manual_refund_confirmed',
        'server.created',
        'server.connection_tested',
        'server.product_mapping_updated',
        'service.provision_completed',
        'service.provision_failed',
        'service.lifecycle_completed',
        'service.lifecycle_failed',
        'service.termination_requested',
        'domain.registrar_settings_updated',
        'domain.nameservers_change_requested',
        'domain.renewal_requested',
        'domain.expiry_sync_queued',
        'domain.renewal_reconciled',
        'domain.nameservers_reconciled',
        'domain.registration_reconciled',
        'domain.exported',
        'security.attachment_scanner_settings_updated',
        'attachment.scanned_clean',
        'attachment.scan_infected',
        'attachment.scan_unavailable',
        'attachment.uploaded_unscanned',
        'database.restore_completed',
        'database.restore_failed',
    ];

    public static function record(PDO $db, string $event, ?int $userId = null, array $metadata = []): void
    {
        if (!self::isAllowedEvent($event)) return;
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ipHash = $ip !== '' ? self::hashIp($ip) : null;
            $agent = self::sanitizeUserAgent((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $safeMetadata = self::sanitizeMetadata($metadata);
            $statement = $db->prepare(
                'INSERT INTO security_audit_logs (user_id, event_key, ip_hash, user_agent, metadata_json, created_at)
                 VALUES (:user_id, :event_key, :ip_hash, :user_agent, :metadata_json, UTC_TIMESTAMP())'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':event_key' => $event,
                ':ip_hash' => $ipHash,
                ':user_agent' => $agent,
                ':metadata_json' => json_encode($safeMetadata, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (Throwable $exception) {
            // Audit storage must never make authentication unavailable; report operational failure.
            error_log('[HostPilot] Security audit write failed: ' . $exception->getMessage());
        }
    }

    public static function isAllowedEvent(string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    public static function hashIp(string $ip): ?string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return null;
        return hash('sha256', 'hostpilot-audit-ip-v1:' . $ip);
    }

    public static function sanitizeUserAgent(string $agent): ?string
    {
        $agent = preg_replace('/[\x00-\x1F\x7F]/', '', $agent) ?? '';
        $agent = trim($agent);
        return $agent === '' ? null : substr($agent, 0, 255);
    }

    public static function sanitizeMetadata(array $metadata): array
    {
        $safe = [];
        if (isset($metadata['role']) && is_string($metadata['role'])
            && in_array($metadata['role'], ['super_admin', 'admin', 'staff', 'client'], true)) {
            $safe['role'] = $metadata['role'];
        }
        if (isset($metadata['request_id']) && is_int($metadata['request_id']) && $metadata['request_id'] > 0) $safe['request_id'] = $metadata['request_id'];
        if (isset($metadata['target_user_id']) && is_int($metadata['target_user_id']) && $metadata['target_user_id'] > 0) $safe['target_user_id'] = $metadata['target_user_id'];
        if (isset($metadata['resource_id']) && is_int($metadata['resource_id']) && $metadata['resource_id'] > 0) $safe['resource_id'] = $metadata['resource_id'];
        if (isset($metadata['action']) && is_string($metadata['action']) && in_array($metadata['action'], ['export','under_review','approved','declined','completed','updated','created','success','failed','queued','review','paid','partially_paid','suspend','unsuspend','remote_applied','remote_not_applied','mark_failed','resume_customer','resume_contact','resume_action','mark_active','unavailable','unscanned','payment_confirmed','refund_confirmed'], true)) $safe['action'] = $metadata['action'];
        return $safe;
    }
}
