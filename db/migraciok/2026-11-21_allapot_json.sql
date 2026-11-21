-- ============================================================================
--  Migráció: rugalmas játékállapot (JSON) és játékonkénti tulajdonságok
--  Csak a MÁR MŰKÖDŐ adatbázisra kell lefuttatni (pl. a play.mcpadventure.com-on),
--  EGYSZER. Új telepítésnél a db/init fájlok már az új szerkezetet hozzák létre.
--
--  phpMyAdmin → az adatbázis → Importálás → ez a fájl.
--  Előtte érdemes biztonsági mentést készíteni: phpMyAdmin → Exportálás → Indítás.
-- ============================================================================
SET NAMES utf8mb4;

-- 1. A játékok tulajdonságainak meghatározása (pénz, éhség, oxigén, hírnév…)
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

-- 2. A mostani négy tulajdonság átvétele minden meglévő játékhoz
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order)
SELECT id, 'penz', 'Pénz', 'money', 0, NULL, start_money, 0, money_label, 10 FROM games;
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order)
SELECT id, 'ehseg', 'Éhség', 'bar_low_good', 0, 100, start_hunger, 30, NULL, 20 FROM games;
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order)
SELECT id, 'faradtsag', 'Fáradtság', 'bar_low_good', 0, 100, start_fatigue, 20, NULL, 30 FROM games;
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order)
SELECT id, 'egeszseg', 'Egészség', 'bar_high_good', 0, 100, start_health, 0, NULL, 40 FROM games;

-- 3. A mentések új, rugalmas állapotmezője, a meglévő értékek átmásolásával
ALTER TABLE saves ADD COLUMN state LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(state)) AFTER game_minute;

UPDATE saves SET state = JSON_OBJECT(
    'stats',     JSON_OBJECT('penz', money, 'ehseg', hunger, 'faradtsag', fatigue, 'egeszseg', health),
    'flags',     JSON_OBJECT(),
    'relations', JSON_OBJECT(),
    'counters',  JSON_OBJECT(),
    'visited',   JSON_ARRAY(location_id)
);

-- 3/b. A Kepler-bemutató saját tulajdonságokat kap (oxigén éhség helyett, kredit pénz helyett),
--      ugyanúgy, mint egy friss telepítésnél
DELETE FROM game_stats WHERE game_id = 'kepler';
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order)
SELECT 'kepler', 'kredit',    'Kredit',    'money',         0, NULL,  0,   0, 'kredit', 10 FROM games WHERE id = 'kepler'
UNION ALL SELECT 'kepler', 'oxigen',    'Oxigén',    'bar_high_good', 0, 100, 70, -12, NULL, 20 FROM games WHERE id = 'kepler'
UNION ALL SELECT 'kepler', 'faradtsag', 'Fáradtság', 'bar_low_good',  0, 100, 60,  15, NULL, 30 FROM games WHERE id = 'kepler'
UNION ALL SELECT 'kepler', 'egeszseg',  'Egészség',  'bar_high_good', 0, 100, 80,   0, NULL, 40 FROM games WHERE id = 'kepler';
UPDATE saves SET state = JSON_SET(state, '$.stats', JSON_OBJECT('kredit', money, 'oxigen', 70, 'faradtsag', fatigue, 'egeszseg', health))
WHERE game_id = 'kepler';

-- 4. A régi, rögzített oszlopok törlése
ALTER TABLE saves DROP COLUMN money, DROP COLUMN hunger, DROP COLUMN fatigue, DROP COLUMN health;
ALTER TABLE games DROP COLUMN start_money, DROP COLUMN start_hunger, DROP COLUMN start_fatigue,
                  DROP COLUMN start_health, DROP COLUMN money_label;
