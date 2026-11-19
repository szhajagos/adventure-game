-- Adventure Game – adatbázis-séma
-- Csak az első induláskor fut le (amikor a db_data kötet még üres).
-- Újrafuttatás: docker compose down -v  →  docker compose up -d
SET NAMES utf8mb4;

-- Helyszínek
CREATE TABLE locations (
    id          VARCHAR(50)  NOT NULL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    image       VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Kijáratok: honnan, merre, hová
CREATE TABLE exits (
    from_location VARCHAR(50)  NOT NULL,
    direction     VARCHAR(20)  NOT NULL,  -- eszak, del, kelet, nyugat, fel, le, be, ki
    to_location   VARCHAR(50)  NOT NULL,
    description   VARCHAR(255) NULL,      -- pl. 'egy rozsdás kapu'
    minutes       INT          NOT NULL DEFAULT 5,  -- mennyi játékidő az út
    PRIMARY KEY (from_location, direction),
    FOREIGN KEY (from_location) REFERENCES locations(id),
    FOREIGN KEY (to_location)   REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Tárgytípusok
CREATE TABLE items (
    id          VARCHAR(50)  NOT NULL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    price       INT          NOT NULL DEFAULT 0,  -- alapár forintban
    nutrition   INT          NOT NULL DEFAULT 0   -- ennyivel csökkenti az éhséget, ha megeszik
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Mi van az egyes helyszíneken
CREATE TABLE location_items (
    location_id VARCHAR(50) NOT NULL,
    item_id     VARCHAR(50) NOT NULL,
    quantity    INT         NOT NULL DEFAULT 1,
    PRIMARY KEY (location_id, item_id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (item_id)     REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Játékosok
CREATE TABLE players (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    location_id VARCHAR(50)  NOT NULL,
    money       INT          NOT NULL DEFAULT 0,    -- forint
    hunger      INT          NOT NULL DEFAULT 0,    -- 0 = jóllakott, 100 = éhezik
    fatigue     INT          NOT NULL DEFAULT 0,    -- 0 = kipihent, 100 = kimerült
    health      INT          NOT NULL DEFAULT 100,  -- 0–100
    game_minute INT          NOT NULL DEFAULT 390,  -- játékidő percben az 1. nap 0:00-tól (390 = 1. nap 6:30)
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A játékos leltára
CREATE TABLE player_items (
    player_id INT         NOT NULL,
    item_id   VARCHAR(50) NOT NULL,
    quantity  INT         NOT NULL DEFAULT 1,
    PRIMARY KEY (player_id, item_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id)   REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
