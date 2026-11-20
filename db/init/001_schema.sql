-- Adventure Game – adatbázis-séma (több játék egy szerveren)
-- Dockerben csak az első induláskor fut le (amikor a db_data kötet még üres).
-- Újrafuttatás: docker compose down -v  →  docker compose up -d
-- Webtárhelyen: phpMyAdmin → Importálás, a fájlokat sorrendben (001, 002, 003…).
SET NAMES utf8mb4;

-- Játékok (világok)
CREATE TABLE games (
    id              VARCHAR(50)  NOT NULL PRIMARY KEY,
    title           VARCHAR(150) NOT NULL,
    description     TEXT         NOT NULL,             -- rövid bemutató a választáshoz
    intro           TEXT         NOT NULL,             -- a játék kezdetén elmondandó helyzet
    narrator_style  TEXT         NOT NULL,             -- hogyan meséljen az AI ebben a játékban
    start_location  VARCHAR(50)  NOT NULL,
    start_money     INT          NOT NULL DEFAULT 0,
    start_hunger    INT          NOT NULL DEFAULT 0,
    start_fatigue   INT          NOT NULL DEFAULT 0,
    start_health    INT          NOT NULL DEFAULT 100,
    start_minute    INT          NOT NULL DEFAULT 480, -- játékidő percben az 1. nap 0:00-tól
    money_label     VARCHAR(20)  NOT NULL DEFAULT 'Ft',
    sort_order      INT          NOT NULL DEFAULT 0,
    active          TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Helyszínek (játékonként)
CREATE TABLE locations (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    image       VARCHAR(255) NULL,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Kijáratok: honnan, merre, hová (ugyanazon a játékon belül)
CREATE TABLE exits (
    game_id       VARCHAR(50)  NOT NULL,
    from_location VARCHAR(50)  NOT NULL,
    direction     VARCHAR(20)  NOT NULL,  -- eszak, del, kelet, nyugat, fel, le, be, ki…
    to_location   VARCHAR(50)  NOT NULL,
    description   VARCHAR(255) NULL,      -- pl. 'egy rozsdás kapu'
    minutes       INT          NOT NULL DEFAULT 5,
    PRIMARY KEY (game_id, from_location, direction),
    FOREIGN KEY (game_id, from_location) REFERENCES locations(game_id, id),
    FOREIGN KEY (game_id, to_location)   REFERENCES locations(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Tárgytípusok (játékonként)
CREATE TABLE items (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    price       INT          NOT NULL DEFAULT 0,
    nutrition   INT          NOT NULL DEFAULT 0,  -- ennyivel csökkenti az éhséget, ha megeszik
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A világ kezdeti tárgyai: mi hever az egyes helyszíneken
CREATE TABLE location_items (
    game_id     VARCHAR(50) NOT NULL,
    location_id VARCHAR(50) NOT NULL,
    item_id     VARCHAR(50) NOT NULL,
    quantity    INT         NOT NULL DEFAULT 1,
    PRIMARY KEY (game_id, location_id, item_id),
    FOREIGN KEY (game_id, location_id) REFERENCES locations(game_id, id),
    FOREIGN KEY (game_id, item_id)     REFERENCES items(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Játékosok (később a regisztrált felhasználókhoz kötve)
CREATE TABLE players (
    id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    current_game_id VARCHAR(50)  NULL,  -- amelyik játékkal éppen játszik
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (current_game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Mentések: játékosonként és játékonként egy-egy állás
CREATE TABLE saves (
    player_id   INT         NOT NULL,
    game_id     VARCHAR(50) NOT NULL,
    location_id VARCHAR(50) NOT NULL,
    money       INT         NOT NULL DEFAULT 0,
    hunger      INT         NOT NULL DEFAULT 0,    -- 0 = jóllakott, 100 = éhezik
    fatigue     INT         NOT NULL DEFAULT 0,    -- 0 = kipihent, 100 = kimerült
    health      INT         NOT NULL DEFAULT 100,  -- 0–100
    game_minute INT         NOT NULL DEFAULT 480,
    started_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, game_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (game_id, location_id) REFERENCES locations(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A mentéshez tartozó leltár
CREATE TABLE save_items (
    player_id INT         NOT NULL,
    game_id   VARCHAR(50) NOT NULL,
    item_id   VARCHAR(50) NOT NULL,
    quantity  INT         NOT NULL DEFAULT 1,
    PRIMARY KEY (player_id, game_id, item_id),
    FOREIGN KEY (player_id, game_id) REFERENCES saves(player_id, game_id) ON DELETE CASCADE,
    FOREIGN KEY (game_id, item_id)   REFERENCES items(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Prototípus: egyetlen tesztjátékos (később a bejelentkezésből jön)
INSERT INTO players (id, name) VALUES (1, 'Tesztjátékos');
