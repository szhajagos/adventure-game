<?php

declare(strict_types=1);

namespace AdventureGame\Web;

/**
 * A játékosoldal munkamenete (külön a szerkesztőétől), CSRF-védelemmel és villanóüzenetekkel.
 */
final class Session
{
    public const NAME = 'mcpadv_fiok';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::https(),
            'httponly' => true,
            'samesite' => 'Lax', // Lax: a Claude-ból érkező bejelentkezési átirányításnál is megmaradjon
        ]);
        session_start();
    }

    public static function https(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $sent = (string) ($_POST['_csrf'] ?? '');

        return $sent !== '' && hash_equals(self::csrfToken(), $sent);
    }

    public static function flash(string $type, string $text): void
    {
        $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    }

    public static function takeFlash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $f;
    }
}
