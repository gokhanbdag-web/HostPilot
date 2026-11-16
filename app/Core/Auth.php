<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;

final class Auth
{
    private const IDLE_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 43200;
    private const RATE_WINDOW = 900;
    private const IP_LIMIT = 30;
    private const PASSWORD_RESET_IP_LIMIT = 5;

    public static function attempt(PDO $db, string $identifier, string $password): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || strlen($identifier) > 254 || self::consumeAttempt()) return false;

        $versionSelect = self::hasAuthVersionColumn($db) ? ', auth_version' : ', 1 AS auth_version';
        $stmt = $db->prepare("SELECT id, name, email, password_hash, role, status" . $versionSelect . " FROM users WHERE email = :email OR username = :username LIMIT 1");
        $stmt->execute([':email' => $identifier, ':username' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            SecurityAudit::record($db, 'auth.login_failed');
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['hostpilot_user'] = [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'auth_version' => (int)$user['auth_version'],
        ];
        $_SESSION['hostpilot_auth_created_at'] = time();
        $_SESSION['hostpilot_auth_last_activity'] = time();
        unset($_SESSION['hostpilot_csrf']);
        self::clearRateLimitBucket();

        $update = $db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([':id' => (int)$user['id']]);
        SecurityAudit::record($db, 'auth.login_succeeded', (int)$user['id'], ['role' => (string)$user['role']]);
        return true;
    }


    /** Verify a password without creating an authenticated session; used by the admin MFA gate. */
    public static function verifyCredentials(PDO $db, string $identifier, string $password): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || strlen($identifier) > 254 || $password === '' || strlen($password) > 4096 || self::consumeAttempt()) return null;
        $versionSelect = self::hasAuthVersionColumn($db) ? ', auth_version' : ', 1 AS auth_version';
        $statement = $db->prepare("SELECT id, name, email, password_hash, role, status" . $versionSelect . " FROM users WHERE email = :email OR username = :username LIMIT 1");
        $statement->execute([':email' => $identifier, ':username' => $identifier]);
        $user = $statement->fetch();
        if (!$user || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            SecurityAudit::record($db, 'auth.login_failed');
            return null;
        }
        return $user;
    }

    /** Complete login only after any required second factor has been verified. */
    public static function completeLogin(PDO $db, array $user): void
    {
        if (($user['status'] ?? '') !== 'active' || !in_array((string)($user['role'] ?? ''), ['super_admin', 'admin', 'staff', 'client', 'customer'], true)) {
            throw new \RuntimeException('Account cannot be authenticated.');
        }
        session_regenerate_id(true);
        $_SESSION['hostpilot_user'] = [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'auth_version' => (int)($user['auth_version'] ?? 1),
        ];
        $_SESSION['hostpilot_auth_created_at'] = time();
        $_SESSION['hostpilot_auth_last_activity'] = time();
        unset($_SESSION['hostpilot_csrf'], $_SESSION['hostpilot_mfa_challenge'], $_SESSION['hostpilot_mfa_pending_secret'], $_SESSION['hostpilot_customer_mfa_challenge'], $_SESSION['hostpilot_customer_mfa_pending']);
        self::clearRateLimitBucket();
        $update = $db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([':id' => (int)$user['id']]);
        SecurityAudit::record($db, 'auth.login_succeeded', (int)$user['id'], ['role' => (string)$user['role']]);
    }

    public static function consumeMfaAttempt(): bool
    {
        return self::consumeRateLimit('mfa', 10);
    }

    public static function user(): ?array
    {
        $user = $_SESSION['hostpilot_user'] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['role'])) return null;

        $now = time();
        // Existing sessions are upgraded in place instead of being invalidated at rollout.
        $created = (int)($_SESSION['hostpilot_auth_created_at'] ?? $now);
        $last = (int)($_SESSION['hostpilot_auth_last_activity'] ?? $now);
        if (self::sessionExpired($created, $last, $now)) {
            self::expireSession();
            return null;
        }

        $_SESSION['hostpilot_auth_created_at'] = $created;
        $_SESSION['hostpilot_auth_last_activity'] = $now;
        return $user;
    }

    /**
     * Reject sessions whose account security version changed (password reset or explicit revocation).
     * Called once by the front controller after the database is available.
     */
    public static function validateSessionVersion(PDO $db): bool
    {
        $user = $_SESSION['hostpilot_user'] ?? null;
        if (!is_array($user) || !isset($user['id'])) return true;
        try {
            if (!self::hasAuthVersionColumn($db)) return true; // Allow the administrator to log in and apply the pending migration.
            $statement = $db->prepare('SELECT auth_version FROM users WHERE id = :id LIMIT 1');
            $statement->execute([':id' => (int)$user['id']]);
            $version = $statement->fetchColumn();
            if ($version === false) {
                self::expireSession();
                return false;
            }
            $current = (int)$version;
            if (!array_key_exists('auth_version', $user)) {
                $_SESSION['hostpilot_user']['auth_version'] = $current;
                return true;
            }
            if (!self::sessionVersionMatches((int)$user['auth_version'], $current)) {
                self::expireSession();
                return false;
            }
            return true;
        } catch (\Throwable $exception) {
            error_log('[HostPilot] Session version validation failed: ' . $exception->getMessage());
            self::expireSession();
            return false;
        }
    }

    public static function sessionVersionMatches(int $sessionVersion, int $currentVersion): bool
    {
        return $sessionVersion > 0 && $sessionVersion === $currentVersion;
    }

    private static function hasAuthVersionColumn(PDO $db): bool
    {
        $statement = $db->query("SHOW COLUMNS FROM users LIKE 'auth_version'");
        return $statement !== false && $statement->fetchColumn() !== false;
    }

    public static function revokeOtherSessions(PDO $db): bool
    {
        $user = self::user();
        if ($user === null) return false;
        $statement = $db->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = :id');
        $statement->execute([':id' => (int)$user['id']]);
        if ($statement->rowCount() !== 1) return false;
        $version = $db->prepare('SELECT auth_version FROM users WHERE id = :id');
        $version->execute([':id' => (int)$user['id']]);
        $_SESSION['hostpilot_user']['auth_version'] = (int)$version->fetchColumn();
        session_regenerate_id(true);
        unset($_SESSION['hostpilot_csrf']);
        SecurityAudit::record($db, 'auth.sessions_revoked', (int)$user['id']);
        return true;
    }

    public static function consumePasswordResetAttempt(): bool
    {
        return self::consumeRateLimit('password-reset', self::PASSWORD_RESET_IP_LIMIT);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        unset($_COOKIE[session_name()]);
        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['hostpilot_csrf'])) $_SESSION['hostpilot_csrf'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['hostpilot_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['hostpilot_csrf'])
            && hash_equals((string)$_SESSION['hostpilot_csrf'], $token);
    }

    /** Testable policy helper; timestamps are Unix seconds. */
    public static function sessionExpired(int $createdAt, int $lastActivityAt, ?int $now = null): bool
    {
        $now ??= time();
        return $createdAt <= 0 || $lastActivityAt <= 0
            || ($now - $lastActivityAt) > self::IDLE_TIMEOUT
            || ($now - $createdAt) > self::ABSOLUTE_TIMEOUT;
    }

    private static function expireSession(): void
    {
        unset($_SESSION['hostpilot_user'], $_SESSION['hostpilot_auth_created_at'], $_SESSION['hostpilot_auth_last_activity'], $_SESSION['hostpilot_csrf'], $_SESSION['hostpilot_mfa_challenge'], $_SESSION['hostpilot_mfa_pending_secret'], $_SESSION['hostpilot_customer_mfa_challenge'], $_SESSION['hostpilot_customer_mfa_pending']);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    private static function rateLimitDirectory(): ?string
    {
        if (!defined('HOSTPILOT_STORAGE')) return null;
        $directory = HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'auth-rate-limits';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
        return is_writable($directory) ? $directory : null;
    }

    private static function rateLimitKey(string $ip, string $purpose = 'login'): string
    {
        return hash('sha256', 'hostpilot-auth-' . $purpose . "\0" . $ip);
    }

    private static function openRateLimitBucket(string $purpose = 'login'): ?array
    {
        $directory = self::rateLimitDirectory();
        if ($directory === null) return null;
        $path = $directory . DIRECTORY_SEPARATOR . self::rateLimitKey((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $purpose) . '.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) return null;
        @chmod($path, 0600);
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($data) || (int)($data['window_started'] ?? 0) < ($now - self::RATE_WINDOW)) {
            $data = ['window_started' => $now, 'attempts' => 0];
        }
        return ['handle' => $handle, 'data' => $data];
    }

    private static function saveRateLimitBucket(array $bucket): bool
    {
        $json = json_encode($bucket['data'], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        $handle = $bucket['handle'];
        rewind($handle);
        if (!ftruncate($handle, 0)) return false;
        $written = fwrite($handle, $json);
        fflush($handle);
        return $written === strlen($json);
    }

    private static function closeRateLimitBucket(array $bucket): void
    {
        flock($bucket['handle'], LOCK_UN);
        fclose($bucket['handle']);
    }

    private static function consumeAttempt(): bool
    {
        return self::consumeRateLimit('login', self::IP_LIMIT);
    }

    private static function consumeRateLimit(string $purpose, int $limit): bool
    {
        $bucket = self::openRateLimitBucket($purpose);
        if ($bucket === null) {
            error_log('[HostPilot] Authentication rate limiter storage is unavailable; login denied.');
            return true;
        }
        if ((int)$bucket['data']['attempts'] >= $limit) {
            self::closeRateLimitBucket($bucket);
            return true;
        }
        $bucket['data']['attempts'] = (int)$bucket['data']['attempts'] + 1;
        $saved = self::saveRateLimitBucket($bucket);
        self::closeRateLimitBucket($bucket);
        if (!$saved) error_log('[HostPilot] Authentication rate limit counter could not be saved; login denied.');
        return !$saved;
    }

    private static function clearRateLimitBucket(): void
    {
        $bucket = self::openRateLimitBucket();
        if ($bucket === null) return;
        $bucket['data'] = ['window_started' => time(), 'attempts' => 0];
        if (!self::saveRateLimitBucket($bucket)) error_log('[HostPilot] Authentication rate limit counter could not be reset.');
        self::closeRateLimitBucket($bucket);
    }
}
