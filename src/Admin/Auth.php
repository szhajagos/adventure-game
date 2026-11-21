<?php

declare(strict_types=1);

namespace AdventureGame\Admin;

use PDO;

/**
 * Belépés, munkamenet és CSRF-védelem a szerkesztőfelülethez.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;      // ennyi sikertelen próbálkozás…
    private const WINDOW_MINUTES = 15;   // …ennyi percen belül → átmeneti tiltás

    public function __construct(private readonly PDO $db)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('mcpadv_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /** A bejelentkezett admin adatai, vagy null. */
    public function user(): ?array
    {
        $id = $_SESSION['admin_id'] ?? null;
        if (!$id) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id, email, display_name, role FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function hasAdmin(): bool
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    }

    /** @return string|null hibaüzenet, vagy null, ha sikerült */
    public function login(string $email, string $password): ?string
    {
        $ip = self::ip();
        if ($this->isLocked($ip)) {
            return 'Túl sok sikertelen próbálkozás. Próbáld újra '.self::WINDOW_MINUTES.' perc múlva.';
        }

        $stmt = $this->db->prepare("SELECT id, password_hash FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->db->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)')->execute([$ip, mb_substr($email, 0, 190)]);
            usleep(700_000); // lassítja a találgatást

            return 'Hibás e-mail-cím vagy jelszó.';
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);

        return null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Az első admin létrehozása (csak ha még nincs admin). @return string|null hiba */
    public function createFirstAdmin(string $email, string $name, string $password): ?string
    {
        if ($this->hasAdmin()) {
            return 'Már van admin felhasználó.';
        }
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Érvénytelen e-mail-cím.';
        }
        if (mb_strlen($password) < 12) {
            return 'A jelszó legalább 12 karakter legyen.';
        }
        $name = trim($name) !== '' ? trim($name) : 'Admin';

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($id = $stmt->fetchColumn()) {
            $this->db->prepare("UPDATE users SET role = 'admin', password_hash = ?, display_name = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $name, $id]);
        } else {
            $this->db->prepare("INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, 'admin')")
                ->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
        }

        return null;
    }

    // --- CSRF ---------------------------------------------------------------

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

    // --- segédek ------------------------------------------------------------

    private function isLocked(string $ip): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL '.self::WINDOW_MINUTES.' MINUTE)'
        );
        $stmt->execute([$ip]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    private static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
