<?php

declare(strict_types=1);

namespace AdventureGame\Auth;

use PDO;

/**
 * Felhasználói fiókok: regisztráció, belépés, e-mail-megerősítés, új jelszó,
 * és a fiókhoz tartozó játékos.
 */
final class Users
{
    public const MIN_PASSWORD = 10;
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $db)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, email, display_name, role, email_verified_at, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array{0: ?int, 1: ?string} [új felhasználó azonosítója, hibaüzenet] */
    public function register(string $email, string $name, string $password, string $password2): array
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return [null, 'Érvénytelen e-mail-cím.'];
        }
        if ($name === '' || mb_strlen($name) > 60) {
            return [null, 'Add meg a játékosneved (legfeljebb 60 karakter).'];
        }
        if ($password !== $password2) {
            return [null, 'A két jelszó nem egyezik.'];
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return [null, 'A jelszó legalább '.self::MIN_PASSWORD.' karakter legyen.'];
        }
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            return [null, 'Ezzel az e-mail-címmel már van fiók. Ha elfelejtetted a jelszavad, kérj újat.'];
        }

        $this->db->prepare("INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, 'player')")
            ->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]);

        return [(int) $this->db->lastInsertId(), null];
    }

    /** @return array{0: ?int, 1: ?string} [felhasználó azonosítója, hibaüzenet] */
    public function login(string $email, string $password): array
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL '.self::WINDOW_MINUTES.' MINUTE)');
        $stmt->execute([$ip]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS) {
            return [null, 'Túl sok sikertelen próbálkozás. Próbáld újra '.self::WINDOW_MINUTES.' perc múlva.'];
        }

        $stmt = $this->db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            $this->db->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)')->execute([$ip, mb_substr($email, 0, 190)]);
            usleep(600_000);

            return [null, 'Hibás e-mail-cím vagy jelszó.'];
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);

        return [(int) $u['id'], null];
    }

    // --- egyszer használatos kódok (megerősítés, új jelszó) -------------------------

    public function createToken(int $userId, string $purpose, int $validMinutes): string
    {
        $token = bin2hex(random_bytes(24));
        $this->db->prepare("UPDATE user_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL")->execute([$userId, $purpose]);
        $this->db->prepare('INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)')
            ->execute([$userId, $purpose, hash('sha256', $token), $validMinutes]);

        return $token;
    }

    /** A kód érvényes-e; ha igen, felhasználja, és visszaadja a felhasználó azonosítóját. */
    public function useToken(string $token, string $purpose, bool $consume = true): ?int
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, user_id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()');
        $stmt->execute([hash('sha256', $token), $purpose]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($consume) {
            $this->db->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        }

        return (int) $row['user_id'];
    }

    public function markVerified(int $userId): void
    {
        $this->db->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')->execute([$userId]);
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT id, email, display_name, email_verified_at FROM users WHERE email = ?');
        $stmt->execute([mb_strtolower(trim($email))]);

        return $stmt->fetch() ?: null;
    }

    public function setPassword(int $userId, string $password): void
    {
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        // Jelszócsere után minden korábbi AI-kapcsolat kijelentkezik
        $this->db->prepare('UPDATE oauth_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$userId]);
    }

    // --- a fiókhoz tartozó játékos ------------------------------------------------

    /** A felhasználó játékosa; ha még nincs, létrehozza. */
    public function playerFor(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM players WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ($id = $stmt->fetchColumn()) {
            return (int) $id;
        }
        $user = $this->find($userId);
        try {
            $this->db->prepare('INSERT INTO players (user_id, name) VALUES (?, ?)')->execute([$userId, $user['display_name'] ?? 'Játékos']);
        } catch (\PDOException) {
            // Egyszerre két kérés hozta létre: a másikét használjuk
            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();
        }

        return (int) $this->db->lastInsertId();
    }

    // --- csatlakoztatott alkalmazások -------------------------------------------------

    public function connectedApps(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.client_id, c.client_name, MAX(t.created_at) AS last_seen
             FROM oauth_tokens t LEFT JOIN oauth_clients c ON c.client_id = t.client_id
             WHERE t.user_id = ? AND t.revoked_at IS NULL AND (t.refresh_expires_at > NOW() OR t.access_expires_at > NOW())
             GROUP BY t.client_id, c.client_name ORDER BY last_seen DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function revokeApp(int $userId, string $clientId): void
    {
        $this->db->prepare('UPDATE oauth_tokens SET revoked_at = NOW() WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL')->execute([$userId, $clientId]);
    }
}
