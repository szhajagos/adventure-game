-- ============================================================================
--  4. réteg: megbízások, kölcsönök, munka, rendszeres költségek, időjárás
--  Friss telepítésnél magától lefut (db/init). A MÁR MŰKÖDŐ adatbázisra
--  ugyanezt a fájlt kell EGYSZER importálni, a 2026-11-26_retegek.sql UTÁN.
--  Előtte: phpMyAdmin → Exportálás (mentés).
-- ============================================================================
SET NAMES utf8mb4;

-- Megbízások: egy szereplő ajánlja fel; a cél feltételek teljesülése, határidővel
CREATE TABLE quests (
    game_id          VARCHAR(50)  NOT NULL,
    id               VARCHAR(50)  NOT NULL,
    npc_id           VARCHAR(50)  NOT NULL,             -- aki felajánlja
    title            VARCHAR(150) NOT NULL,
    offer_text       TEXT         NOT NULL,             -- amit a szereplő mond, amikor felajánlja
    offer_conditions LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(offer_conditions)),
    accept_effects   LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(accept_effects)),   -- pl. megkapja a csomagot
    goal_text        VARCHAR(255) NOT NULL,             -- a cél röviden (a játékosnak és a panelnek)
    goal_conditions  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(goal_conditions)),
    deadline_minutes INT          NULL,                 -- az elfogadástól számítva; üres: nincs határidő
    reward_effects   LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(reward_effects)),
    penalty_effects  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(penalty_effects)),
    success_text     TEXT         NULL,
    fail_text        TEXT         NULL,
    repeatable       TINYINT(1)   NOT NULL DEFAULT 0,   -- elutasítás vagy kudarc után újra felajánlható
    sort_order       INT          NOT NULL DEFAULT 100,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id, npc_id) REFERENCES npcs(game_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Munka: időért pénz, egy helyszínen, feltételekkel
CREATE TABLE jobs (
    game_id     VARCHAR(50)  NOT NULL,
    id          VARCHAR(50)  NOT NULL,
    location_id VARCHAR(50)  NOT NULL,
    npc_id      VARCHAR(50)  NULL,      -- ha meg van adva, csak akkor lehet dolgozni, ha ő is ott van
    title       VARCHAR(150) NOT NULL,
    description TEXT         NULL,
    minutes     INT          NOT NULL DEFAULT 240,
    pay         INT          NOT NULL DEFAULT 0,
    conditions  LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(conditions)),  -- pl. időablak, higiénia
    effects     LONGTEXT     NOT NULL DEFAULT '[]' CHECK (JSON_VALID(effects)),     -- pl. fáradás, piszkolódás
    daily_limit INT          NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 100,
    PRIMARY KEY (game_id, id),
    FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Kölcsön: a szereplő adhat kölcsönt (összeg, kamat, napok, feltételek)
ALTER TABLE npcs ADD COLUMN loan LONGTEXT NULL CHECK (loan IS NULL OR JSON_VALID(loan)) AFTER schedule;

-- --- Ébredés a parkban ---------------------------------------------------------------

INSERT INTO locations (game_id, id, name, description, features) VALUES
('ebredes', 'vasarcsarnok', 'A vásárcsarnok hátsó bejárata',
 'Teherautók tolatnak a rámpához, ládák, zsákok és rekeszek mindenütt. Hideg van, dízelszag és káposztaszag keveredik. Egy raktárajtó felett hunyorgó lámpa ég.',
 '["szabadter"]');

INSERT INTO exits (game_id, from_location, direction, to_location, description, minutes) VALUES
('ebredes', 'utca', 'kelet', 'vasarcsarnok', 'a Fő utca vége, a vásárcsarnok felé', 12),
('ebredes', 'vasarcsarnok', 'nyugat', 'utca', 'vissza a Fő utcára', 12);

INSERT INTO npcs (game_id, id, name, description, personality, schedule, loan) VALUES
('ebredes', 'bela', 'Béla, a raktáros',
 'Zömök, borostás férfi kopott kezeslábasban, a füle mögött ceruza.',
 'Morcos, szűkszavú, de igazságos. Nem kedveli a lógósokat, a szorgalmas embert viszont megbecsüli. Néha ad kölcsönt, de a tartozását mindenkin behajtja.',
 '[{"helyszin": "vasarcsarnok", "tol": "05:00", "ig": "14:00"}]',
 '{"osszeg": 2000, "kamat": 20, "napok": 3, "kesedelmi_kamat": 10, "feltetelek": [{"kapcsolat": ["bela", ">=", 1]}]}');

INSERT INTO npc_topics (game_id, npc_id, title, keywords, content, conditions, effects, sort_order) VALUES
('ebredes', 'bela', 'Munka', 'munka, rakodás, dolgozni, fizetés',
 'Reggel hattól nyolcig veszek fel embert rakodni. Négy óra, négyezer forint, a végén fizetek. Aki büdös vagy részeg, az mehet is.',
 '[]', '[]', 10),
('ebredes', 'bela', 'Kölcsön', 'kölcsön, pénz, tartozás',
 'Aki már dolgozott nálam, annak adok kétezret kölcsön. Három nap múlva húsz százalékkal többet kérek vissza. Aki nem fizet, azt megtalálom.',
 '[]', '[]', 20);

INSERT INTO jobs (game_id, id, location_id, npc_id, title, description, minutes, pay, conditions, effects, daily_limit) VALUES
('ebredes', 'rakodas', 'vasarcsarnok', 'bela', 'Rakodás a vásárcsarnokban',
 'Ládák és zsákok cipelése a teherautókról a raktárba.', 240, 4000,
 '[{"ido_kozott": ["06:00", "08:00"]}, {"tulajdonsag": ["higienia", ">=", 15]}, {"tulajdonsag": ["energia", ">=", 25]}]',
 '[{"tulajdonsag": ["energia", -20]}, {"tulajdonsag": ["higienia", -20]}, {"tulajdonsag": ["jokedv", 5]}, {"kapcsolat": ["bela", 1]}]', 1);

INSERT INTO items (game_id, id, name, description, size, price, nutrition, props) VALUES
('ebredes', 'kiflis_kosar', 'kosár kifli', 'Ruhával letakart fonott kosár, meleg kifliillat száll belőle.', 3, 0, 0,
 '{"cimkek": ["szallitmany"], "vizsgalat": "A kosár fülére cetli van kötve: „Bélának, a piacra. Marika”"}');

INSERT INTO quests (game_id, id, npc_id, title, offer_text, offer_conditions, accept_effects, goal_text, goal_conditions,
                    deadline_minutes, reward_effects, penalty_effects, success_text, fail_text, repeatable, sort_order) VALUES
('ebredes', 'kifli_belanak', 'pek', 'Kifli Bélának',
 'Aranyom, megtennél egy szívességet? Vidd el ezt a kosár kiflit Bélának a vásárcsarnokhoz, délig várja. Kapsz érte ötszázat, és hálás leszek.',
 '[{"kapcsolat": ["pek", ">=", 1]}, {"ido_kozott": ["06:00", "10:00"]}]',
 '[{"targy_letrehoz": {"targy": "kiflis_kosar", "hova": "nala"}}]',
 'Vidd el a kosár kiflit Bélának a vásárcsarnokhoz.',
 '[{"jelen_van": "bela"}, {"van_nala": "kiflis_kosar"}]',
 240,
 '[{"targy_elvesz": "kiflis_kosar"}, {"tulajdonsag": ["penz", 500]}, {"kapcsolat": ["pek", 2]}, {"kapcsolat": ["bela", 1]}]',
 '[{"targy_elvesz": "kiflis_kosar"}, {"kapcsolat": ["pek", -3]}]',
 'Béla átveszi a kosarat, morog valamit, és kezedbe nyom ötszáz forintot Marika nevében.',
 'Késő: Béla már nem várja a kiflit. Marika csalódott lesz.', 1, 10);

-- Időjárás és véletlenek a parkban
UPDATE games SET settings = JSON_SET(settings, '$.idojaras', JSON_OBJECT('derult', 55, 'felhos', 30, 'esos', 15)) WHERE id = 'ebredes';

INSERT INTO rules (game_id, name, trigger_action, conditions, effects, message, priority) VALUES
('ebredes', 'Az eső áztat a szabadban', 'orankent', '[{"idojaras": "esos"}, {"felszereltseg": "szabadter"}]',
 '[{"tulajdonsag": ["jokedv", -3]}, {"tulajdonsag": ["higienia", -3]}]', 'Esik az eső, a játékos ázik a szabad ég alatt.', 30),
('ebredes', 'Éjszakai kötekedő a parkban', 'orankent',
 '[{"napszak": "ejszaka"}, {"helyszin": ["park_pad", "park_kut", "park_kapu"]}, {"veletlen": 15}]',
 '[{"tulajdonsag": ["jokedv", -5]}, {"tulajdonsag": ["energia", -3]}]', 'Egy részeg alak kötekedni kezd a játékossal, alig lehet lerázni.', 40);
