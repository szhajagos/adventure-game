-- ============================================================================
--  Részletek és közelképek a megvizsgáláshoz
--  A helyszín részletei (látnivalók) a helyszínhez rögzített dolgok, nem tárgyak:
--  egy hirdetés a lámpaoszlopon, a műszerfal, a konyhapult. Megvizsgálhatók,
--  lehet saját közelképük: assets/images/<játék>/reszletek/<részlet_id>.webp
--  A tárgyak közelképe (bárhol is van a tárgy): assets/images/<játék>/targyak/<tárgy_id>~nezet.webp
--  A MÁR MŰKÖDŐ adatbázisra EGYSZER kell importálni, a 2026-11-28_halado_targyak.sql UTÁN.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE location_details (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,          -- játékon belül egyedi (a kép neve is ez)
    location_id VARCHAR(50)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    keywords    VARCHAR(255) NULL,              -- vesszővel: a játékos szavai (pl. hirdetés, cetli, plakát)
    description TEXT         NOT NULL,          -- amit alapos megnézésre lát
    hidden      TINYINT(1)   NOT NULL DEFAULT 0, -- rejtett: nem szerepel a látnivalók között, csak ha a játékos rákérdez
    conditions  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),  -- mikor látható / vizsgálható
    effects     LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(effects)),     -- az első megnézéskor
    sort_order  INT          NOT NULL DEFAULT 100,
    PRIMARY KEY (game_id, id),
    INDEX idx_loc (game_id, location_id),
    FOREIGN KEY (game_id, location_id) REFERENCES locations(game_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

INSERT INTO location_details (game_id, id, location_id, name, keywords, description, hidden, conditions, effects, sort_order) VALUES
('ebredes', 'hirdetes', 'utca', 'hirdetés a lámpaoszlopon', 'hirdetés, cetli, plakát, lámpaoszlop, oszlop',
 'Kézzel írt, eső áztatta cetli, celluxszal felragasztva: „RAKODÓKAT KERESÜNK! Vásárcsarnok, hátsó bejárat, hajnali 6-tól. Napi fizetés. – Béla”',
 0, '[]', '[{"jelzo": ["tudja_a_piaci_munkat", true]}]', 10),
('ebredes', 'pad_felirat', 'park_pad', 'karcolás a pad támláján', 'karcolás, felirat, támla, vésés',
 'Valaki bicskával vésett egy szót a fába, egészen frissen: „EMLÉKEZZ”. Alatta egy elmosódott dátum.',
 1, '[]', '[]', 20),
('kepler', 'muszerfal', 'hid', 'műszerfal', 'műszerfal, konzol, kijelző, képernyő, panel',
 'A legtöbb kijelző sötét. A kommunikációs konzolon egy villogó sor: „KÜLSŐ ADÓ: NINCS JEL. VÉSZJELADÓ: GÉPTEREM, ÁLLVÁNY 2.”',
 0, '[]', '[{"jelzo": ["tudja_hol_a_jelado", true]}]', 10);
