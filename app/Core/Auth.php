<?php
declare(strict_types=1);

namespace HostPilot\Core;

use PDO;

final class Auth
{
    public static function attempt(PDO $db, string $identifier, string $password): bool
    {
        $identifier = strtolower(trim($identifier));
        $stmt = $db->prepare("SELECT id, name, email, password_hash, role, status FROM users WHERE email = :email OR username = :username LIMIT 1");
        $stmt->execute([':email' => $identifier, ':username' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || ($user['status'] ?? '') !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['hostpilot_user'] = [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
        ];
        $update = $db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute([':id' => (int)$user['id']]);
        return true;
    }

    public static function user(): ?array
    {
        $user = $_SESSION['hostpilot_user'] ?? null;
        return is_array($user) && isset($user['id'], $user['role']) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function logout(): void
    {
        unset($_SESSION['hostpilot_user']);
        session_regenerate_id(true);
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
}
