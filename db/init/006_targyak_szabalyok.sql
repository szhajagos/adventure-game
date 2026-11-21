-- ============================================================================
--  1. réteg: tárgypéldányok, tárolók, szabályok, beállítások, ellenőrző pontok
--  Friss telepítésnél magától lefut (db/init). A MÁR MŰKÖDŐ adatbázisra
--  (play.mcpadventure.com) ugyanezt a fájlt kell EGYSZER importálni,
--  a 2026-11-22_bejelentkezes.sql UTÁN. Előtte: phpMyAdmin → Exportálás (mentés).
-- ============================================================================
SET NAMES utf8mb4;

-- --- Szerkezet ---------------------------------------------------------------

-- Játékonkénti beállítások (kezek, zseb, étkezés, vereség…) JSON-ban
ALTER TABLE games ADD COLUMN settings LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(settings)) AFTER start_minute;

-- A helyszínek felszereltsége (címkék: mosdo, furdo, agy, bolt, fedett, szabadter…)
ALTER TABLE locations ADD COLUMN features LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(features)) AFTER image;

-- Feltételes kijáratok: csak akkor használhatók, ha a feltételek teljesülnek
ALTER TABLE exits ADD COLUMN conditions LONGTEXT NULL CHECK (conditions IS NULL OR JSON_VALID(conditions)),
                  ADD COLUMN blocked_message VARCHAR(255) NULL;

-- A tárgytípusok mérete és jellemzői (címkék, tároló, cél…) JSON-ban
ALTER TABLE items ADD COLUMN size INT NOT NULL DEFAULT 1 AFTER description,
                  ADD COLUMN props LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(props));

-- Tárgypéldányok: minden mentésnek saját világa van. Egy példány egy helyen van:
--   location  – egy helyszínen (place_id = a helyszín azonosítója)
--   slot      – a játékosnál (place_id = bal_kez, jobb_kez, zseb, hat)
--   container – egy másik példányban, pl. táskában (place_id = a tároló példány azonosítója)
CREATE TABLE item_instances (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id      INT          NOT NULL,
    game_id        VARCHAR(50)  NOT NULL,
    item_id        VARCHAR(50)  NOT NULL,
    quantity       INT          NOT NULL DEFAULT 1,
    place_type     ENUM('location', 'slot', 'container') NOT NULL,
    place_id       VARCHAR(50)  NOT NULL,
    created_minute INT          NOT NULL DEFAULT 0,
    wear           INT          NOT NULL DEFAULT 0,
    props          LONGTEXT     NOT NULL DEFAULT '{}' CHECK (JSON_VALID(props)),
    INDEX idx_place (player_id, game_id, place_type, place_id),
    FOREIGN KEY (player_id, game_id) REFERENCES saves(player_id, game_id) ON DELETE CASCADE,
    FOREIGN KEY (game_id, item_id) REFERENCES items(game_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Szabályok: kiváltó művelet → feltételek → hatások → üzenet a mesélőnek
CREATE TABLE rules (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_id        VARCHAR(50)  NOT NULL,
    name           VARCHAR(150) NOT NULL,
    trigger_action VARCHAR(30)  NOT NULL,  -- felvesz, letesz, belerak, kivesz, megvizsgal, fogyaszt, menj, megerkezik, barmely
    conditions     LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),
    effects        LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(effects)),
    message        TEXT         NULL,
    once           TINYINT(1)   NOT NULL DEFAULT 0,
    priority       INT          NOT NULL DEFAULT 100,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    INDEX idx_trigger (game_id, trigger_action),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Ellenőrző pontok: a mentés pillanatképe, ahonnan vereség után folytatni lehet
CREATE TABLE save_checkpoints (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id   INT          NOT NULL,
    game_id     VARCHAR(50)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    location_id VARCHAR(50)  NOT NULL,
    game_minute INT          NOT NULL,
    state       LONGTEXT     NOT NULL,
    instances   LONGTEXT     NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_save (player_id, game_id),
    FOREIGN KEY (player_id, game_id) REFERENCES saves(player_id, game_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- --- Tartalom: Ébredés a parkban ---------------------------------------------------

UPDATE games SET settings = '{"kez_meret": 4, "zseb_kapacitas": 2, "etkezes": {"tulajdonsag": "ehseg", "irany": -1}, "vereseg": [{"feltetelek": [{"tulajdonsag": ["egeszseg", "<=", 0]}], "szoveg": "Elfogyott minden erőd. Egy járókelő mentőt hív, és a kórházban térsz magadhoz."}]}' WHERE id = 'ebredes';

UPDATE locations SET features = '["szabadter"]' WHERE game_id = 'ebredes' AND id IN ('park_pad', 'park_kut', 'park_kapu', 'utca');
UPDATE locations SET features = '["fedett", "bolt"]' WHERE game_id = 'ebredes' AND id = 'pekseg';
UPDATE locations SET features = '["fedett"]' WHERE game_id = 'ebredes' AND id = 'aluljaro';

UPDATE items SET size = 1, props = '{"cimkek": ["uveg"]}' WHERE game_id = 'ebredes' AND id = 'ures_uveg';
UPDATE items SET size = 1, props = '{"cimkek": ["papir"], "vizsgalat": "Az apróhirdetések között valaki bekarikázott egyet: „Piaci rakodómunkára keresünk embert. Jelentkezés hajnali hatkor a vásárcsarnok hátsó bejáratánál.”"}' WHERE game_id = 'ebredes' AND id = 'ujsag';
UPDATE items SET size = 1, props = '{"cimkek": ["etel"]}' WHERE game_id = 'ebredes' AND id IN ('kifli', 'pogacsa');
UPDATE items SET size = 2, props = '{"cimkek": ["etel"]}' WHERE game_id = 'ebredes' AND id = 'kenyer';

INSERT INTO items (game_id, id, name, description, size, price, nutrition, props) VALUES
('ebredes', 'nejlonszatyor', 'nejlonszatyor', 'Gyűrött, de ép nejlonszatyor. Néhány apróság elfér benne.', 1, 0, 0,
 '{"cimkek": ["taska"], "tarolo": {"kapacitas": 6}}');

INSERT INTO location_items (game_id, location_id, item_id, quantity) VALUES
('ebredes', 'park_pad', 'nejlonszatyor', 1);

INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, once) VALUES
('ebredes', 'Az újság apróhirdetése', 'megvizsgal', '[{"targy": "ujsag"}]',
 '[{"jelzo": ["tudja_a_piaci_munkat", true]}]',
 'A játékos most megtudta, hogy hajnali hatkor rakodómunkást keresnek a vásárcsarnoknál.', 1);

-- --- Tartalom: Csend a Kepler-állomáson ---------------------------------------------

UPDATE games SET settings = '{"kez_meret": 4, "zseb_kapacitas": 2, "etkezes": {"tulajdonsag": "egeszseg", "irany": 1}, "vereseg": [{"feltetelek": [{"tulajdonsag": ["oxigen", "<=", 0]}], "szoveg": "Elfogyott az oxigén. Elsötétül előtted a világ."}, {"feltetelek": [{"tulajdonsag": ["egeszseg", "<=", 0]}], "szoveg": "A tested feladja a küzdelmet."}]}' WHERE id = 'kepler';

UPDATE locations SET features = '["fedett"]' WHERE game_id = 'kepler';
UPDATE items SET size = 1, props = '{"cimkek": ["zseton"]}' WHERE game_id = 'kepler' AND id = 'zseton';
UPDATE items SET size = 2, props = '{"cimkek": ["eszkoz"]}' WHERE game_id = 'kepler' AND id = 'csavarhuzo';
UPDATE items SET size = 1, props = '{"cimkek": ["etel"]}' WHERE game_id = 'kepler' AND id = 'tapszeles';

INSERT INTO locations (game_id, id, name, description, features) VALUES
('kepler', 'gepterem', 'Gépterem',
 'Szűk, meleg terem, a falakat csövek és kábelkötegek borítják. A sarokban egy vészjeladó állványa áll, rajta piros fény villog.',
 '["fedett"]');

INSERT INTO exits (game_id, from_location, direction, to_location, description, minutes, conditions, blocked_message) VALUES
('kepler', 'folyoso', 'le', 'gepterem', 'a padló egy kiemelhető lemeze', 2,
 '[{"van_nala": "csavarhuzo"}]', 'A gépterembe vezető padlólemezt csavarok rögzítik. Valamivel ki kellene nyitni.'),
('kepler', 'gepterem', 'fel', 'folyoso', 'a létra fel a folyosóra', 2, NULL, NULL);

INSERT INTO items (game_id, id, name, description, size, price, nutrition, props) VALUES
('kepler', 'jelado', 'vészjeladó', 'Hordozható vészjeladó. Ha bekapcsolod, a legközelebbi mentőhajó megtalálja az állomást.', 3, 0, 0,
 '{"cimkek": ["eszkoz"], "cel": true, "vege_szoveg": "Bekapcsolod a vészjeladót. Pár perc múlva recsegő hang szól a hangszóróból: egy mentőhajó felvette a jelet, és úton van érted. Megmenekültél."}');

INSERT INTO location_items (game_id, location_id, item_id, quantity) VALUES
('kepler', 'gepterem', 'jelado', 1);

INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, once) VALUES
('kepler', 'Ellenőrző pont a gépteremben', 'megerkezik', '[{"helyszin": "gepterem"}]',
 '[{"ellenorzo_pont": "A gépterem"}]', 'A játékos bejutott a gépterembe. Ez fontos pillanat: innen folytathatja, ha később baj éri.', 1);
