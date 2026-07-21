<?php
namespace App\Core;

class Auth
{
    /** Approval ranking used to gate approval screens. */
    public const ROLE_RANK = [
        'employee'  => 0,
        'dept_head' => 1,
        'fa'        => 2,
        'mrd'       => 3,
        'coo'       => 4,
        'admin'     => 9,
    ];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(Config::get('security.session_name', 'DUTYROSTER_SID'));
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function attempt(string $username, string $password): bool
    {
        $db = Database::app();
        $u = $db->one(
            "SELECT * FROM users WHERE username = :u AND active = 1",
            [':u' => $username]
        );
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return false;
        }
        $_SESSION['uid']  = (int) $u['id'];
        $_SESSION['user'] = [
            'id'            => (int) $u['id'],
            'username'      => $u['username'],
            'full_name'     => $u['full_name'],
            'role'          => $u['role'],
            'employee_id'   => $u['employee_id'] !== null ? (int) $u['employee_id'] : null,
            'department_id' => $u['department_id'] !== null ? (int) $u['department_id'] : null,
        ];
        $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', [':id' => $u['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return $_SESSION['uid'] ?? null;
    }

    public static function role(): string
    {
        return $_SESSION['user']['role'] ?? 'guest';
    }

    /** True if current user's role rank is >= the given role. */
    public static function atLeast(string $role): bool
    {
        $have = self::ROLE_RANK[self::role()] ?? -1;
        $need = self::ROLE_RANK[$role] ?? 99;
        return $have >= $need;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function require(): void
    {
        if (!self::check()) {
            header('Location: ' . url('login'));
            exit;
        }
    }

    public static function requireRole(string $role): void
    {
        self::require();
        if (!self::atLeast($role)) {
            http_response_code(403);
            echo 'Forbidden — insufficient permissions.';
            exit;
        }
    }
}
