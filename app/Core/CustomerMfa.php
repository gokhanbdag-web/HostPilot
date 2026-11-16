<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Optional TOTP and single-use recovery codes for customer accounts. */
final class CustomerMfa
{
    public static function isEnabled(PDO $db, int $userId): bool
    {
        $table = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name');
        $table->execute([':name' => 'customer_mfa']);
        if ((int)$table->fetchColumn() !== 1) return false;
        $query = $db->prepare('SELECT 1 FROM customer_mfa WHERE user_id=:id AND enabled_at IS NOT NULL LIMIT 1');
        $query->execute([':id' => $userId]);
        return (bool)$query->fetchColumn();
    }

    /** Returns "totp", "recovery", or null. Each factor can only be consumed once. */
    public static function verifyCode(PDO $db, int $userId, string $code, string $applicationKey, ?int $timestamp = null): ?string
    {
        if (preg_match('/^[0-9]{6}$/D', $code) === 1) {
            $query = $db->prepare('SELECT secret_enc FROM customer_mfa WHERE user_id=:id AND enabled_at IS NOT NULL LIMIT 1');
            $query->execute([':id' => $userId]);
            $encrypted = $query->fetchColumn();
            if (!is_string($encrypted) || $encrypted === '') return null;
            try {
                $secret = (new SecretVault($applicationKey))->decrypt($encrypted);
            } catch (Throwable $exception) {
                error_log('[HostPilot] Customer MFA secret decryption failed: ' . $exception->getMessage());
                return null;
            }
            $step = Mfa::matchingStep($secret, $code, $timestamp);
            if ($step === null) return null;
            $update = $db->prepare('UPDATE customer_mfa SET last_used_step=:step,updated_at=UTC_TIMESTAMP() WHERE user_id=:id AND enabled_at IS NOT NULL AND last_used_step<:previous_step');
            $update->execute([':step' => $step, ':previous_step' => $step, ':id' => $userId]);
            return $update->rowCount() === 1 ? 'totp' : null;
        }

        $hash = Mfa::recoveryCodeHash($code);
        if ($hash === null) return null;
        $update = $db->prepare('UPDATE customer_mfa_recovery_codes SET used_at=UTC_TIMESTAMP() WHERE user_id=:id AND code_hash=:hash AND used_at IS NULL');
        $update->execute([':id' => $userId, ':hash' => $hash]);
        return $update->rowCount() === 1 ? 'recovery' : null;
    }

    public static function enable(PDO $db, int $userId, string $secret, int $verifiedStep, array $codes, string $applicationKey): void
    {
        if (Mfa::base32Decode($secret) === null || $verifiedStep < 1 || count($codes) !== 10) {
            throw new RuntimeException('Invalid customer MFA enrollment data.');
        }
        $encrypted = (new SecretVault($applicationKey))->encrypt($secret);
        $db->beginTransaction();
        try {
            $upsert = $db->prepare('INSERT INTO customer_mfa(user_id,secret_enc,enabled_at,last_used_step,created_at,updated_at) VALUES(:id,:secret,UTC_TIMESTAMP(),:step,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE secret_enc=VALUES(secret_enc),enabled_at=UTC_TIMESTAMP(),last_used_step=VALUES(last_used_step),updated_at=UTC_TIMESTAMP()');
            $upsert->execute([':id' => $userId, ':secret' => $encrypted, ':step' => $verifiedStep]);
            $db->prepare('DELETE FROM customer_mfa_recovery_codes WHERE user_id=:id')->execute([':id' => $userId]);
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
            $db->prepare('DELETE FROM customer_mfa_recovery_codes WHERE user_id=:id')->execute([':id' => $userId]);
            self::insertRecoveryCodes($db, $userId, $codes);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function disable(PDO $db, int $userId): void
    {
        $delete = $db->prepare('DELETE FROM customer_mfa WHERE user_id=:id');
        $delete->execute([':id' => $userId]);
    }

    public static function remainingRecoveryCodes(PDO $db, int $userId): int
    {
        $query = $db->prepare('SELECT COUNT(*) FROM customer_mfa_recovery_codes WHERE user_id=:id AND used_at IS NULL');
        $query->execute([':id' => $userId]);
        return (int)$query->fetchColumn();
    }

    private static function insertRecoveryCodes(PDO $db, int $userId, array $codes): void
    {
        $insert = $db->prepare('INSERT INTO customer_mfa_recovery_codes(user_id,code_hash,created_at) VALUES(:id,:hash,UTC_TIMESTAMP())');
        foreach ($codes as $code) {
            $hash = Mfa::recoveryCodeHash((string)$code);
            if ($hash === null) throw new RuntimeException('Invalid recovery code format.');
            $insert->execute([':id' => $userId, ':hash' => $hash]);
        }
    }
}
