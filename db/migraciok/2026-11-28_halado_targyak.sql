-- ============================================================================
--  5. réteg: haladó tárgyak – romlás, kopás, eszközhasználat, receptek, jármű, tűz
--  A tárgyak új jellemzői a props JSON-ban vannak (nincs új oszlop):
--    romlik: {"ora": 48, "mive": "szaraz_kifli"}   tartossag: 5, torik_mive: "..."
--    eghet: 1 (ennyi órát ég)   gyujto: true   alvas_szorzo: 0.8
--    marad: "ures_uveg" (elfogyasztás után)   fogyasztas_hatas: [hatások]
--    jarmu: {"sebesseg": 0.4, "utak": ["szabadter"]}
--  Friss telepítésnél magától lefut (db/init). A MÁR MŰKÖDŐ adatbázisra
--  ugyanezt a fájlt kell EGYSZER importálni, a 2026-11-27_megbizasok_kornyezet.sql UTÁN.
-- ============================================================================
SET NAMES utf8mb4;

-- Receptek: alapanyagok + eszközök + hely (pl. égő tűz) → új tárgy
CREATE TABLE recipes (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description TEXT         NULL,       -- a mesélőnek: hogyan készül
    ingredients LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(ingredients)),  -- [{"targy": "ujsag", "darab": 2}]
    tools       LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(tools)),        -- ["kes"] – nem fogynak el, csak kopnak
    features    LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(features)),     -- ["tuz"] vagy ["konyha"]
    minutes     INT          NOT NULL DEFAULT 15,
    result_item VARCHAR(50)  NOT NULL,
    result_qty  INT          NOT NULL DEFAULT 1,
    conditions  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),   -- ki ismeri (pl. jelző)
    effects     LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(effects)),
    sort_order  INT          NOT NULL DEFAULT 100,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id, result_item) REFERENCES items(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- --- Ébredés a parkban ---------------------------------------------------------------

INSERT INTO items (game_id, id, name, description, size, price, nutrition, props) VALUES
('ebredes', 'szaraz_kifli', 'száraz kifli', 'Kőkemény, tegnapi kifli. Ehető, ha nagyon kell.', 1, 0, 8,
 '{"cimkek": ["etel"], "romlik": {"ora": 72}}'),
('ebredes', 'uveg_viz', 'üveg víz', 'Egy üveg hideg kútvíz.', 1, 0, 3,
 '{"cimkek": ["ital"], "marad": "ures_uveg", "fogyasztas_hatas": [{"tulajdonsag": ["energia", 3]}]}'),
('ebredes', 'agak', 'száraz ágak', 'Egy nyaláb száraz gally és ág, jó tüzelő.', 2, 0, 0,
 '{"cimkek": ["tuzelo"], "eghet": 1}'),
('ebredes', 'gyufa', 'doboz gyufa', 'Kis doboz gyufa, néhány szál van benne.', 1, 100, 0,
 '{"cimkek": ["eszkoz"], "gyujto": true, "tartossag": 5}'),
('ebredes', 'ujsag_takaro', 'újságpapír takaró', 'Több réteg összehajtogatott újságpapír. Nem szép, de meglepően meleg.', 2, 0, 0,
 '{"cimkek": ["papir"], "alvas_szorzo": 0.8, "eghet": 1}'),
('ebredes', 'piritos', 'pirítós', 'Tűzön pirított, ropogós kenyérszelet.', 1, 0, 25,
 '{"cimkek": ["etel"], "romlik": {"ora": 24}, "fogyasztas_hatas": [{"tulajdonsag": ["jokedv", 5]}]}'),
('ebredes', 'bicikli', 'rozsdás bicikli', 'Gazdátlan, rozsdás bicikli, évek óta itt áll. A csomagtartóján egy drótkosár.', 10, 0, 0,
 '{"nem_felveheto": true, "jarmu": {"sebesseg": 0.4, "utak": ["szabadter"]}, "tarolo": {"kapacitas": 4}}');

UPDATE items SET props = JSON_SET(props, '$.romlik', JSON_OBJECT('ora', 48, 'mive', 'szaraz_kifli')) WHERE game_id = 'ebredes' AND id = 'kifli';
UPDATE items SET props = JSON_SET(props, '$.romlik', JSON_OBJECT('ora', 48)) WHERE game_id = 'ebredes' AND id = 'pogacsa';
UPDATE items SET props = JSON_SET(props, '$.romlik', JSON_OBJECT('ora', 96)) WHERE game_id = 'ebredes' AND id = 'kenyer';
UPDATE items SET props = JSON_SET(props, '$.eghet', 0.5) WHERE game_id = 'ebredes' AND id = 'ujsag';

INSERT INTO location_items (game_id, location_id, item_id, quantity) VALUES
('ebredes', 'park_pad', 'agak', 3),
('ebredes', 'aluljaro', 'ujsag', 2),
('ebredes', 'park_kapu', 'bicikli', 1);

UPDATE locations SET features = '["szabadter", "tuzrakohely"]',
       description = CONCAT(description, ' A pad mögött kövekkel körberakott, kormos tűzrakóhely.')
WHERE game_id = 'ebredes' AND id = 'park_pad';
UPDATE locations SET features = '["szabadter", "mosdo", "ivokut"]' WHERE game_id = 'ebredes' AND id = 'park_kut';

INSERT INTO trades (game_id, trader_type, trader_id, mode, item_id, price, daily_limit, conditions, sort_order) VALUES
('ebredes', 'npc', 'pek', 'arul', 'gyufa', NULL, 3, '[]', 40);

INSERT INTO recipes (game_id, id, name, description, ingredients, tools, features, minutes, result_item, result_qty) VALUES
('ebredes', 'ujsag_takaro', 'Újságpapír takaró', 'Több réteg újságot hajtogat össze és gyűr a kabátja alá – a hajléktalanok régi trükkje.',
 '[{"targy": "ujsag", "darab": 2}]', '[]', '[]', 15, 'ujsag_takaro', 1),
('ebredes', 'piritos', 'Pirítós', 'Pálcára tűzött kenyérszeleteket pirít a tűz fölött.',
 '[{"targy": "kenyer", "darab": 1}]', '[]', '["tuz"]', 20, 'piritos', 3);

INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, priority) VALUES
('ebredes', 'Víz a kútból', 'hasznal', '[{"targy": "ures_uveg"}, {"celpont": "ivokut"}]',
 '[{"targy_atalakit": ["ures_uveg", "uveg_viz"]}]', 'A játékos megtöltötte az üveget a kút hideg vizével.', 10),
('ebredes', 'A tűz melege', 'orankent', '[{"felszereltseg": "tuz"}, {"napszak": ["este", "ejszaka", "hajnal"]}]',
 '[{"tulajdonsag": ["jokedv", 3]}]', 'A tűz melege jólesik a hideg éjszakában.', 30);
