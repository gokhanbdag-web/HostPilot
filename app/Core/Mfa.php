<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;
use RuntimeException;
use Throwable;

/** RFC 6238 TOTP and one-time recovery-code support for administrator accounts. */
final class Mfa
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $data): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) $encoded .= self::BASE32[($buffer << (5 - $bits)) & 31];
        return $encoded;
    }

    public static function base32Decode(string $encoded): ?string
    {
        $encoded = strtoupper(preg_replace('/[\s=-]/', '', $encoded) ?? '');
        if ($encoded === '' || preg_match('/^[A-Z2-7]+$/D', $encoded) !== 1) return null;
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
            $value = strpos(self::BASE32, $encoded[$i]);
            if ($value === false) return null;
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
            }
        }
        return $decoded === '' ? null : $decoded;
    }

    public static function codeAt(string $secret, int $timestamp): ?string
    {
        $key = self::base32Decode($secret);
        if ($key === null || $timestamp < 0) return null;
        $counter = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function matchingStep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) return null;
        $timestamp ??= time();
        $currentStep = intdiv($timestamp, 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            $step = $currentStep + $offset;
            if ($step < 0) continue;
            $expected = self::codeAt($secret, $step * 30);
            if ($expected !== null && hash_equals($expected, $code)) return $step;
        }
        return null;
    }

    /** @return list<string> Plain recovery codes; callers must display them once only. */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        if ($count < 1 || $count > 20) throw new RuntimeException('Invalid recovery code count.');
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(8)));
            $codes[] = implode('-', str_split($raw, 4));
        }
        return $codes;
    }

    public static function recoveryCodeHash(string $code): ?string
    {
        $normalized = strtoupper(str_replace('-', '', trim($code)));
        if (preg_match('/^[A-F0-9]{16}$/D', $normalized) !== 1) return null;
        return hash('sha256', 'hostpilot-mfa-recovery-v1:' . $normalized);
    }

    public static function isEnabled(PDO $db, int $userId): bool
    {
        // Before the admin-confirmed migration is applied, preserve password-only login
        // so an administrator can enter the panel and run the controlled upgrade.
        $table = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
        $table->execute([':table_name' => 'admin_mfa']);
        if ((int)$table->fetchColumn() !== 1) return false;
        $statement = $db->prepare('SELECT 1 FROM admin_mfa WHERE user_id = :user_id AND enabled_at IS NOT NULL LIMIT 1');
        $statement->execute([':user_id' => $userId]);
        return (bool)$statement->fetchColumn();
    }

    /** Returns "totp", "recovery", or null. Atomic updates prevent replay/reuse. */
    public static function verifyCode(PDO $db, int $userId, string $code, string $applicationKey, ?int $timestamp = null): ?string
    {
        if (preg_match('/^[0-9]{6}$/D', $code) === 1) {
            $query = $db->prepare('SELECT secret_enc FROM admin_mfa WHERE user_id = :user_id AND enabled_at IS NOT NULL LIMIT 1');
            $query->execute([':user_id' => $userId]);
            $encrypted = $query->fetchColumn();
            if (!is_string($encrypted) || $encrypted === '') return null;
            try {
                $secret = (new SecretVault($applicationKey))->decrypt($encrypted);
            } catch (Throwable $exception) {
                error_log('[HostPilot] MFA secret decryption failed: ' . $exception->getMessage());
                return null;
            }
            $step = self::matchingStep($secret, $code, $timestamp);
            if ($step === null) return null;
            $update = $db->prepare('UPDATE admin_mfa SET last_used_step = :step, updated_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND enabled_at IS NOT NULL AND last_used_step < :step');
            $update->execute([':step' => $step, ':user_id' => $userId]);
            return $update->rowCount() === 1 ? 'totp' : null;
        }
        $hash = self::recoveryCodeHash($code);
        if ($hash === null) return null;
        $update = $db->prepare('UPDATE admin_mfa_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND code_hash = :code_hash AND used_at IS NULL');
        $update->execute([':user_id' => $userId, ':code_hash' => $hash]);
        return $update->rowCount() === 1 ? 'recovery' : null;
    }

    public static function enable(PDO $db, int $userId, string $secret, int $verifiedStep, array $codes, string $applicationKey): void
    {
        if (self::base32Decode($secret) === null || $verifiedStep < 1 || count($codes) !== 10) {
            throw new RuntimeException('Invalid MFA enrollment data.');
        }
        $encrypted = (new SecretVault($applicationKey))->encrypt($secret);
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO admin_mfa (user_id, secret_enc, enabled_at, last_used_step, created_at, updated_at) VALUES (:user_id, :secret_enc, UTC_TIMESTAMP(), :last_used_step, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), enabled_at = UTC_TIMESTAMP(), last_used_step = VALUES(last_used_step), updated_at = UTC_TIMESTAMP()');
            $upsert->execute([':user_id' => $userId, ':secret_enc' => $encrypted, ':last_used_step' => $verifiedStep]);
            $db->prepare('DELETE FROM admin_mfa_recovery_codes WHERE user_id = :user_id')->execute([':user_id' => $userId]);
            self::insertRecoveryCodes($db, $userId, $codes);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function rotateRecoveryCodes(PDO $db, int $userId, array $codes): void
    {
        if (count($codes) !== 10) throw new RuntimeException('Invalid recovery code set.');
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM admin_mfa_recovery_codes WHERE user_id = :user_id')->execute([':user_id' => $userId]);
            self::insertRecoveryCodes($db, $userId, $codes);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function disable(PDO $db, int $userId): void
    {
        $statement = $db->prepare('DELETE FROM admin_mfa WHERE user_id = :user_id');
        $statement->execute([':user_id' => $userId]);
    }

    public static function remainingRecoveryCodes(PDO $db, int $userId): int
    {
        $statement = $db->prepare('SELECT COUNT(*) FROM admin_mfa_recovery_codes WHERE user_id = :user_id AND used_at IS NULL');
        $statement->execute([':user_id' => $userId]);
        return (int)$statement->fetchColumn();
    }

    private static function insertRecoveryCodes(PDO $db, int $userId, array $codes): void
    {
        $insert = $db->prepare('INSERT INTO admin_mfa_recovery_codes (user_id, code_hash, created_at) VALUES (:user_id, :code_hash, UTC_TIMESTAMP())');
        foreach ($codes as $code) {
            $hash = self::recoveryCodeHash((string)$code);
            if ($hash === null) throw new RuntimeException('Invalid recovery code format.');
            $insert->execute([':user_id' => $userId, ':code_hash' => $hash]);
        }
    }
}
