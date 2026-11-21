-- ============================================================================
--  2. réteg: a test és az idő
--  jóllakottság, energia, higiénia, jókedv; nyitvatartás; óránkénti szabályok
--  Friss telepítésnél magától lefut (db/init). A MÁR MŰKÖDŐ adatbázisra
--  ugyanezt a fájlt kell EGYSZER importálni, a 2026-11-23_targyak_szabalyok.sql UTÁN.
--  Előtte: phpMyAdmin → Exportálás (mentés).
-- ============================================================================
SET NAMES utf8mb4;

-- Nyitvatartás, pl. '06:00-19:00' (vesszővel több sáv is). Üres: mindig nyitva.
ALTER TABLE locations ADD COLUMN open_hours VARCHAR(100) NULL AFTER features;

-- --- Ébredés a parkban -------------------------------------------------------------
-- Az éhségből jóllakottság, a fáradtságból energia lesz (a több a jobb);
-- a meglévő mentések értékei átfordulnak (éhség 45 → jóllakottság 55).

UPDATE saves SET state = JSON_SET(state,
        '$.stats.jollakottsag', GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.ehseg'), 45))),
        '$.stats.energia',      GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.faradtsag'), 35))))
WHERE game_id = 'ebredes';
UPDATE saves SET state = JSON_REMOVE(state, '$.stats.ehseg') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.ehseg');
UPDATE saves SET state = JSON_REMOVE(state, '$.stats.faradtsag') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.faradtsag');
UPDATE saves SET state = JSON_REMOVE(state, '$.stats.egeszseg') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.egeszseg');

UPDATE save_checkpoints SET state = JSON_SET(state,
        '$.stats.jollakottsag', GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.ehseg'), 45))),
        '$.stats.energia',      GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.faradtsag'), 35))))
WHERE game_id = 'ebredes';
UPDATE save_checkpoints SET state = JSON_REMOVE(state, '$.stats.ehseg') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.ehseg');
UPDATE save_checkpoints SET state = JSON_REMOVE(state, '$.stats.faradtsag') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.faradtsag');
UPDATE save_checkpoints SET state = JSON_REMOVE(state, '$.stats.egeszseg') WHERE game_id = 'ebredes' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.egeszseg');

UPDATE game_stats SET id = 'jollakottsag', label = 'Jóllakottság', kind = 'bar_high_good', start_value = 55, per_hour = -5, sort_order = 30
WHERE game_id = 'ebredes' AND id = 'ehseg';
UPDATE game_stats SET id = 'energia', label = 'Energia', kind = 'bar_high_good', start_value = 60, per_hour = -4, sort_order = 40
WHERE game_id = 'ebredes' AND id = 'faradtsag';
DELETE FROM game_stats WHERE game_id = 'ebredes' AND id = 'egeszseg';
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order) VALUES
('ebredes', 'jokedv',   'Jókedv',   'bar_high_good', 0, 100, 50, 0,  NULL, 20),
('ebredes', 'higienia', 'Higiénia', 'bar_high_good', 0, 100, 35, -2, NULL, 50);

UPDATE games SET settings = '{"kez_meret": 4, "zseb_kapacitas": 2, "etkezes": {"tulajdonsag": "jollakottsag", "irany": 1}, "test": {"energia": "energia", "higienia": "higienia", "alvas_orankent": 12, "alvas_agy_nelkul": 0.5, "pihenes": 15, "pihenes_naponta": 1}, "vereseg": [{"feltetelek": [{"tulajdonsag": ["jokedv", "<=", 0]}, {"tulajdonsag": ["jollakottsag", "<=", 0]}, {"tulajdonsag": ["energia", "<=", 0]}], "szoveg": "Kimerültél, éhes vagy és minden reményed elfogyott. Egy járókelő mentőt hív, és a kórházban térsz magadhoz – a kaland itt véget ér."}]}'
WHERE id = 'ebredes';

-- Az ételek tápértéke most a jóllakottságot növeli
UPDATE items SET nutrition = 15 WHERE game_id = 'ebredes' AND id = 'kifli';
UPDATE items SET nutrition = 25 WHERE game_id = 'ebredes' AND id = 'pogacsa';
UPDATE items SET nutrition = 45 WHERE game_id = 'ebredes' AND id = 'kenyer';

UPDATE locations SET features = '["szabadter", "mosdo"]' WHERE game_id = 'ebredes' AND id = 'park_kut';
UPDATE locations SET open_hours = '06:00-19:00' WHERE game_id = 'ebredes' AND id = 'pekseg';

INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, priority) VALUES
('ebredes', 'Az éhség rontja a kedvet', 'orankent', '[{"tulajdonsag": ["jollakottsag", "<=", 0]}]',
 '[{"tulajdonsag": ["jokedv", -5]}]', 'A játékos nagyon éhes, ez egyre jobban rontja a kedvét.', 10),
('ebredes', 'A kimerültség rontja a kedvet', 'orankent', '[{"tulajdonsag": ["energia", "<=", 0]}]',
 '[{"tulajdonsag": ["jokedv", -5]}]', 'A játékos teljesen kimerült, alig áll a lábán; a kedve egyre rosszabb.', 10),
('ebredes', 'A piszok rontja a kedvet', 'orankent', '[{"tulajdonsag": ["higienia", "<=", 0]}]',
 '[{"tulajdonsag": ["jokedv", -3]}]', 'A játékos koszosnak és ápolatlannak érzi magát.', 10),
('ebredes', 'Jóllakottan, kipihenten jobb a kedv', 'orankent',
 '[{"tulajdonsag": ["jollakottsag", ">=", 50]}, {"tulajdonsag": ["energia", ">=", 40]}, {"tulajdonsag": ["higienia", ">=", 30]}]',
 '[{"tulajdonsag": ["jokedv", 2]}]', NULL, 20);

-- --- Csend a Kepler-állomáson ------------------------------------------------------

UPDATE saves SET state = JSON_SET(state,
        '$.stats.energia', GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.faradtsag'), 60))))
WHERE game_id = 'kepler';
UPDATE saves SET state = JSON_REMOVE(state, '$.stats.faradtsag') WHERE game_id = 'kepler' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.faradtsag');
UPDATE save_checkpoints SET state = JSON_SET(state,
        '$.stats.energia', GREATEST(0, LEAST(100, 100 - COALESCE(JSON_EXTRACT(state, '$.stats.faradtsag'), 60))))
WHERE game_id = 'kepler';
UPDATE save_checkpoints SET state = JSON_REMOVE(state, '$.stats.faradtsag') WHERE game_id = 'kepler' AND JSON_CONTAINS_PATH(state, 'one', '$.stats.faradtsag');

UPDATE game_stats SET id = 'energia', label = 'Energia', kind = 'bar_high_good', start_value = 40, per_hour = -4
WHERE game_id = 'kepler' AND id = 'faradtsag';

UPDATE games SET settings = JSON_SET(settings, '$.test', JSON_OBJECT('energia', 'energia', 'higienia', '', 'alvas_orankent', 15, 'alvas_agy_nelkul', 0.5, 'pihenes', 15, 'pihenes_naponta', 1))
WHERE id = 'kepler';

UPDATE locations SET features = '["fedett", "agy"]' WHERE game_id = 'kepler' AND id = 'hibernacio';
