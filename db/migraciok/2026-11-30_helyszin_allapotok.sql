-- ============================================================================
--  A helyszín állapotai: ha a helyszín a játék során tartósan megváltozik
--  (leégett, megjavították, elárasztotta a víz). Az állapot új leírást, nevet,
--  felszereltséget és képet adhat; a tárgyak, a szereplők és a részletek megmaradnak.
--  Bekapcsolás szabályból: {"helyszin_allapot": ["haz", "leegett"]}, visszaállítás: ["haz", ""]
--  Képek: <helyszín>~<állapot>.webp, <helyszín>~<állapot>-ejszaka.webp, <helyszín>~<állapot>-esos.webp
--  A MÁR MŰKÖDŐ adatbázisra EGYSZER kell importálni, a 2026-11-29_reszletek.sql UTÁN.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE location_states (
    game_id     VARCHAR(50)  NOT NULL,
    location_id VARCHAR(50)  NOT NULL,
    state       VARCHAR(50)  NOT NULL,
    name        VARCHAR(150) NULL,      -- üres: marad a helyszín neve
    description TEXT         NOT NULL,  -- a helyszín leírása ebben az állapotban
    features    LONGTEXT     NULL CHECK (features IS NULL OR JSON_VALID(features)),  -- üres: marad a helyszín felszereltsége
    PRIMARY KEY (game_id, location_id, state),
    FOREIGN KEY (game_id, location_id) REFERENCES locations(game_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Bemutató a Keplerben: a műszerfal megnézése vészüzemmódba kapcsolja a hidat
INSERT INTO location_states (game_id, location_id, state, name, description, features) VALUES
('kepler', 'hid', 'veszuzem', 'Parancsnoki híd (vészüzem)',
 'A híd vészüzemmódba kapcsolt: minden konzol vörösen villog, egy gépi hang ismétli, hogy a külső adó halott. A panorámaablakon túl a gázóriás kék fénye hidegen dereng.',
 NULL);

UPDATE location_details SET effects = '[{"jelzo": ["tudja_hol_a_jelado", true]}, {"helyszin_allapot": ["hid", "veszuzem"]}]'
WHERE game_id = 'kepler' AND id = 'muszerfal';
