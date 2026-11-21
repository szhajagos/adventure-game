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
    start_minute    INT          NOT NULL DEFAULT 480, -- játékidő percben az 1. nap 0:00-tól
    sort_order      INT          NOT NULL DEFAULT 0,
    active          TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- A játék tulajdonságai (játékonként mások lehetnek: pénz, éhség, oxigén, hírnév…)
--   kind: money          – pénz (a tárgyak ára ebben értendő; játékonként egy legyen)
--         bar_low_good   – sáv, ahol a kevesebb a jobb (éhség, fáradtság)
--         bar_high_good  – sáv, ahol a több a jobb (egészség, oxigén)
--         number         – sima szám (pl. hírnév, pontszám)
--         hidden         – rejtett érték, a panel nem mutatja
--   per_hour: óránkénti változás a játékidő múlásával (pl. éhség +30/óra)
CREATE TABLE game_stats (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    label       VARCHAR(100) NOT NULL,
    kind        ENUM('money', 'bar_low_good', 'bar_high_good', 'number', 'hidden') NOT NULL DEFAULT 'number',
    min_value   INT          NULL,
    max_value   INT          NULL,
    start_value INT          NOT NULL DEFAULT 0,
    per_hour    DECIMAL(8,2) NOT NULL DEFAULT 0,
    unit        VARCHAR(20)  NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
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
    game_minute INT         NOT NULL DEFAULT 480,
    -- Minden más, játékonként eltérő állapot JSON-ban:
    -- {"stats":{…}, "flags":{…}, "relations":{…}, "counters":{…}, "visited":[…]}
    state       LONGTEXT    NOT NULL DEFAULT '{}' CHECK (JSON_VALID(state)),
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
