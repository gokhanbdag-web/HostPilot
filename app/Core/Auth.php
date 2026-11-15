<?php
declare(strict_types=1);

namespace HostPilot\\Core;

use PDO;

final class Auth
{
    private const IDLE_TIMEOUT = 1800;
    private const ABSOLUTE_TIMEOUT = 43200;
    private const RATE_WINDOW = 900;
    private const CREDENTIAL_LIMIT = 8;
    private const IP_LIMIT = 30;

    public static function attempt(PDO $db, string $identifier, string $password): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || strlen($identifier) > 254 || self::isRateLimited($identifier)) {
            return false;
        }

        $stmt = $db->prepare("SELECT id, name, email, password_hash, role, status FROM users WHERE email = :email OR username = :username LIMIT 1");
        $stmt->execute([':email' => $identifier, ':username' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            self::recordFailure($identifier);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['hostpilot_user'] = [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
        ];
        $_SESSION['hostpilot_auth_created_at'] = time();
        $_SESSION['hostpilot_auth_last_activity'] = time();
        unset($_SESSION['hostpilot_csrf']);
        self::clearCredentialFailures($identifier);

        $update = $db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([':id' => (int)$user['id']]);
        return true;
    }

    public static function user(): ?array
    {
        $user = $_SESSION['hostpilot_user'] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['role'])) return null;

        $now = time();
        // Existing sessions are upgraded in place instead of being invalidated at rollout.
        $created = (int)($_SESSION['hostpilot_auth_created_at'] ?? $now);
        $last = (int)($_SESSION['hostpilot_auth_last_activity'] ?? $now);
        if ($created <= 0 || $last <= 0 || ($now - $last) > self::IDLE_TIMEOUT || ($now - $created) > self::ABSOLUTE_TIMEOUT) {
            self::expireSession();
            return null;
        }

        $_SESSION['hostpilot_auth_created_at'] = $created;
        $_SESSION['hostpilot_auth_last_activity'] = $now;
        return $user;
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
        if (empty($_SESSION['hostpilot_csrf'])) {
            $_SESSION['hostpilot_csrf'] = bin2hex(random_bytes(32));
        }
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
        unset($_SESSION['hostpilot_user'], $_SESSION['hostpilot_auth_created_at'], $_SESSION['hostpilot_auth_last_activity'], $_SESSION['hostpilot_csrf']);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    private static function rateLimitDirectory(): ?string
    {
        if (!defined('HOSTPILOT_STORAGE')) return null;
        $directory = HOSTPILOT_STORAGE . DIRECTORY_SEPARATOR . 'auth-rate-limits';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
        return is_writable($directory) ? $directory : null;
    }

    private static function rateLimitKey(string $kind, string $value): string
    {
        return hash('sha256', $kind . "\\0" . $value);
    }

    private static function readBucket(string $key): ?array
    {
        $directory = self::rateLimitDirectory();
        if ($directory === null) return null;
        $path = $directory . DIRECTORY_SEPARATOR . $key . '.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_EX)) return null;
            rewind($handle);
            $raw = stream_get_contents($handle);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($data) || (int)($data['window_started'] ?? 0) < ($now - self::RATE_WINDOW)) {
                $data = ['window_started' => $now, 'attempts' => 0, 'locked_until' => 0];
            }
            return ['handle' => $handle, 'data' => $data];
        } catch (\\Throwable $e) {
            error_log('[HostPilot] Authentication rate limiter failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function writeBucket(array $bucket): bool
    {
        $handle = $bucket['handle'];
        $json = json_encode($bucket['data'], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        rewind($handle);
        if (!ftruncate($handle, 0)) return false;
        $written = fwrite($handle, $json);
        fflush($handle);
        return $written === strlen($json);
    }

    private static function closeBucket(array $bucket): void
    {
        $handle = $bucket['handle'];
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function isRateLimited(string $identifier): bool
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $credential = self::readBucket(self::rateLimitKey('credential', $identifier . "\\0" . $ip));
        $ipBucket = self::readBucket(self::rateLimitKey('ip', $ip));
        if ($credential === null || $ipBucket === null) {
            if ($credential !== null) self::closeBucket($credential);
            if ($ipBucket !== null) self::closeBucket($ipBucket);
            error_log('[HostPilot] Authentication rate limiter storage is unavailable; login denied.');
            return true;
        }
        $limited = (int)$credential['data']['locked_until'] > time()
            || (int)$credential['data']['attempts'] >= self::CREDENTIAL_LIMIT
            || (int)$ipBucket['data']['locked_until'] > time()
            || (int)$ipBucket['data']['attempts'] >= self::IP_LIMIT;
        self::closeBucket($credential);
        self::closeBucket($ipBucket);
        return $limited;
    }

    private static function recordFailure(string $identifier): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        foreach ([
            [self::rateLimitKey('credential', $identifier . "\\0" . $ip), self::CREDENTIAL_LIMIT],
            [self::rateLimitKey('ip', $ip), self::IP_LIMIT],
        ] as [$key, $limit]) {
            $bucket = self::readBucket($key);
            if ($bucket === null) {
                error_log('[HostPilot] Unable to persist authentication failure counter.');
                continue;
            }
            $bucket['data']['attempts'] = (int)$bucket['data']['attempts'] + 1;
            if ($bucket['data']['attempts'] >= $limit) $bucket['data']['locked_until'] = time() + self::RATE_WINDOW;
            self::writeBucket($bucket);
            self::closeBucket($bucket);
        }
    }

    private static function clearCredentialFailures(string $identifier): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = self::rateLimitKey('credential', $identifier . "\\0" . $ip);
        $bucket = self::readBucket($key);
        if ($bucket === null) return;
        $bucket['data'] = ['window_started' => time(), 'attempts' => 0, 'locked_until' => 0];
        self::writeBucket($bucket);
        self::closeBucket($bucket);
    }
}
