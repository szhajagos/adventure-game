-- ============================================================================
--  Migráció: játékosfiókok és OAuth-bejelentkezés
--  A MÁR MŰKÖDŐ adatbázisra kell lefuttatni (play.mcpadventure.com), EGYSZER,
--  a 2026-11-21_allapot_json.sql UTÁN. Előtte: phpMyAdmin → Exportálás (mentés).
-- ============================================================================

SET NAMES utf8mb4;
-- Egyszer használatos kódok a fiókhoz: e-mail-megerősítés, elfelejtett jelszó
CREATE TABLE user_tokens (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          NOT NULL,
    purpose     ENUM('verify', 'reset') NOT NULL,
    token_hash  CHAR(64)     NOT NULL UNIQUE,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A játékos a felhasználói fiókhoz tartozik
ALTER TABLE players ADD COLUMN user_id INT NULL UNIQUE AFTER id,
    ADD CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- OAuth-kliensek: a regisztrált (DCR) és a metaadat-dokumentummal azonosított (CIMD) alkalmazások
CREATE TABLE oauth_clients (
    client_id     VARCHAR(255) NOT NULL PRIMARY KEY,
    kind          ENUM('dcr', 'cimd') NOT NULL,
    client_name   VARCHAR(255) NULL,
    redirect_uris TEXT         NOT NULL CHECK (JSON_VALID(redirect_uris)),
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fetched_at    DATETIME     NULL     -- CIMD: mikor olvastuk be utoljára a dokumentumot
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Engedélyezési kódok (rövid életűek, egyszer használhatók)
CREATE TABLE oauth_codes (
    code_hash      CHAR(64)     NOT NULL PRIMARY KEY,
    client_id      VARCHAR(255) NOT NULL,
    user_id        INT          NOT NULL,
    redirect_uri   TEXT         NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    scope          VARCHAR(255) NOT NULL,
    resource       VARCHAR(255) NULL,
    expires_at     DATETIME     NOT NULL,
    used_at        DATETIME     NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Hozzáférési és frissítő tokenek (csak a hash-ük kerül az adatbázisba)
CREATE TABLE oauth_tokens (
    id                 INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    access_hash        CHAR(64)     NOT NULL UNIQUE,
    refresh_hash       CHAR(64)     NULL UNIQUE,
    client_id          VARCHAR(255) NOT NULL,
    user_id            INT          NOT NULL,
    scope              VARCHAR(255) NOT NULL,
    resource           VARCHAR(255) NULL,
    access_expires_at  DATETIME     NOT NULL,
    refresh_expires_at DATETIME     NULL,
    revoked_at         DATETIME     NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A meglévő tesztjátékos (és a mentései) az első adminhoz kerül, így a saját fiókoddal folytatod
UPDATE players SET user_id = (SELECT id FROM (SELECT MIN(id) AS id FROM users WHERE role = 'admin') AS a)
WHERE id = 1 AND user_id IS NULL;

-- Az adminok e-mail-címét megerősítettnek tekintjük (ők a beállító oldalon hozták létre a fiókot)
UPDATE users SET email_verified_at = NOW() WHERE role = 'admin' AND email_verified_at IS NULL;
