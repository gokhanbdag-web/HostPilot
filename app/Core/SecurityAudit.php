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
        return $safe;
    }
}
