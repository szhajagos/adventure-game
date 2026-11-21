-- ============================================================================
--  3. réteg: szereplők és kereskedelem
--  Friss telepítésnél magától lefut (db/init). A MÁR MŰKÖDŐ adatbázisra
--  ugyanezt a fájlt kell EGYSZER importálni, a 2026-11-24_test_es_ido.sql UTÁN.
--  Előtte: phpMyAdmin → Exportálás (mentés).
-- ============================================================================
SET NAMES utf8mb4;

-- Szereplők: az adatlap alapján az AI beszél a nevükben
CREATE TABLE npcs (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NULL,      -- hogy néz ki (a játékos ezt látja)
    personality TEXT         NULL,      -- személyiség, beszédstílus (a mesélőnek)
    schedule    LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(schedule)),  -- [{"helyszin": "pekseg", "tol": "06:00", "ig": "18:00"}]
    sort_order  INT          NOT NULL DEFAULT 100,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Beszélgetési témák: mit tud és mit hajlandó elmondani a szereplő
CREATE TABLE npc_topics (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_id     VARCHAR(50)  NOT NULL,
    npc_id      VARCHAR(50)  NOT NULL,
    title       VARCHAR(150) NOT NULL,
    keywords    VARCHAR(255) NULL,      -- vesszővel: a játékos szavai, amelyekre a téma előjön
    content     TEXT         NOT NULL,  -- amit a szereplő elmond (a mesélő a saját szavaival adja elő)
    conditions  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),
    effects     LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(effects)),
    sort_order  INT          NOT NULL DEFAULT 100,
    INDEX idx_npc (game_id, npc_id),
    FOREIGN KEY (game_id, npc_id) REFERENCES npcs(game_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Kereskedés: egy szereplő vagy egy helyszín (automata, bolt) árul vagy felvásárol
CREATE TABLE trades (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_id       VARCHAR(50)  NOT NULL,
    trader_type   ENUM('npc', 'location') NOT NULL,
    trader_id     VARCHAR(50)  NOT NULL,
    mode          ENUM('arul', 'felvasarol') NOT NULL,  -- arul: a játékos vehet; felvasarol: a játékos eladhat
    item_id       VARCHAR(50)  NOT NULL,
    price         INT          NULL,    -- üres: a tárgy alapára
    price_item_id VARCHAR(50)  NULL,    -- csere: pénz helyett ezt a tárgyat kéri (darabonként egyet)
    daily_limit   INT          NULL,    -- naponta legfeljebb ennyi darab (üres: korlátlan)
    conditions    LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),  -- kivel hajlandó kereskedni
    sort_order    INT          NOT NULL DEFAULT 100,
    INDEX idx_trader (game_id, trader_type, trader_id),
    FOREIGN KEY (game_id, item_id) REFERENCES items(game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Egy tárgy egy szereplőnél is lehet (odaadta a játékos)
ALTER TABLE item_instances MODIFY place_type ENUM('location', 'slot', 'container', 'npc') NOT NULL;

-- --- Ébredés a parkban ---------------------------------------------------------------

INSERT INTO npcs (game_id, id, name, description, personality, schedule) VALUES
('ebredes', 'pek', 'Marika, a pék',
 'Kerek arcú, lisztes kötényes asszony, a keze sosem áll meg.',
 'Kedves, anyáskodó, de mindig siet. Rövid mondatokban beszél, „aranyom”-nak szólítja a vevőit. Az ingyenélőket nem szereti, a dolgos embereket viszont szívesen segíti.',
 '[{"helyszin": "pekseg", "tol": "06:00", "ig": "19:00"}]');

INSERT INTO npc_topics (game_id, npc_id, title, keywords, content, conditions, effects, sort_order) VALUES
('ebredes', 'pek', 'Munka', 'munka, dolgozni, állás, pénz, kereset',
 'A vásárcsarnokban mindig kell rakodó, hajnali hatkor a hátsó bejáratnál válogatják az embereket. Aki rendesen dolgozik, annak a nap végén ott a pénze.',
 '[]', '[{"jelzo": ["tudja_a_piaci_munkat", true]}]', 10),
('ebredes', 'pek', 'Üvegek', 'üveg, palack, visszaváltás, automata',
 'Az üres üvegeket ne dobd ki, aranyom! Lent az aluljáróban van egy visszaváltó automata, darabjáért ötven forintot ad.',
 '[]', '[{"jelzo": ["tudja_az_automatat", true]}]', 20);

INSERT INTO trades (game_id, trader_type, trader_id, mode, item_id, price, daily_limit, conditions, sort_order) VALUES
('ebredes', 'npc', 'pek', 'arul', 'kifli',   NULL, 30, '[{"tulajdonsag": ["higienia", ">=", 10]}]', 10),
('ebredes', 'npc', 'pek', 'arul', 'pogacsa', NULL, 20, '[{"tulajdonsag": ["higienia", ">=", 10]}]', 20),
('ebredes', 'npc', 'pek', 'arul', 'kenyer',  NULL, 5,  '[{"tulajdonsag": ["higienia", ">=", 10]}]', 30),
('ebredes', 'location', 'aluljaro', 'felvasarol', 'ures_uveg', 50, 20, '[]', 10);

UPDATE games SET settings = JSON_SET(settings, '$.keszletpotlas_ora', 6) WHERE id = 'ebredes';

-- --- Csend a Kepler-állomáson --------------------------------------------------------

UPDATE locations SET description = CONCAT(description, ' A fal mellett egy ellátóautomata pislákol: tápszeletet ad kreditért, és visszaváltja a kredit-zsetonokat.')
WHERE game_id = 'kepler' AND id = 'raktar';

INSERT INTO trades (game_id, trader_type, trader_id, mode, item_id, price, daily_limit, conditions, sort_order) VALUES
('kepler', 'location', 'raktar', 'arul', 'tapszeles', NULL, 10, '[]', 10),
('kepler', 'location', 'raktar', 'felvasarol', 'zseton', 10, NULL, '[]', 20);

UPDATE games SET settings = JSON_SET(settings, '$.keszletpotlas_ora', 6) WHERE id = 'kepler';
