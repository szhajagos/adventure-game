-- Felhasználók (adminok, később a regisztrált játékosok is) és a belépési próbálkozások
-- Webtárhelyen: phpMyAdmin → az adatbázis → Importálás → ez a fájl.
SET NAMES utf8mb4;

CREATE TABLE users (
    id                INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(190) NOT NULL UNIQUE,
    display_name      VARCHAR(100) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    role              ENUM('player', 'admin') NOT NULL DEFAULT 'player',
    email_verified_at TIMESTAMP    NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at     TIMESTAMP    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A sikertelen belépések naplója (túl sok próbálkozás esetén átmeneti tiltás)
CREATE TABLE login_attempts (
    id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    email        VARCHAR(190) NULL,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
