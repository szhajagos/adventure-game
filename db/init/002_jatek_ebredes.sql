-- Játék: Ébredés a parkban
SET NAMES utf8mb4;

INSERT INTO games (id, title, description, intro, narrator_style, start_location, start_minute, sort_order) VALUES
('ebredes',
 'Ébredés a parkban',
 'Egy nagyváros parkjában ébredsz, szinte üres zsebbel és emlékek nélkül. Szerezz pénzt, ételt és szállást, és építsd fel az életed a semmiből.',
 'Egy hideg, kora őszi reggelen egy parkban, egy kopott padon térsz magadhoz. Nem emlékszel, hogyan kerültél ide. A zsebedben néhány száz forint lapul.',
 'Realista, kissé melankolikus, de reményteli városi hangulat, időnként finom, száraz humorral. Rövid, érzékletes mondatok: hangok, szagok, hideg, éhség. A pénz és a megélhetés mindig fontos téma.',
 'park_pad', 390, 10);

INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order) VALUES
('ebredes', 'penz',      'Pénz',      'money',         0, NULL, 340,  0, 'Ft', 10),
('ebredes', 'ehseg',     'Éhség',     'bar_low_good',  0, 100,   45, 30, NULL, 20),
('ebredes', 'faradtsag', 'Fáradtság', 'bar_low_good',  0, 100,   35, 20, NULL, 30),
('ebredes', 'egeszseg',  'Egészség',  'bar_high_good', 0, 100,   90,  0, NULL, 40);

INSERT INTO locations (game_id, id, name, description, image) VALUES
('ebredes', 'park_pad', 'Kopott pad a parkban',
 'Egy hideg, kopott pad a park szélén. Fölötte csupasz faágak, a távolban villamos csörömpöl. A pad mellett egy telerakott szemetes áll.',
 NULL),
('ebredes', 'park_kut', 'A park közepe',
 'Kiszáradt díszkút áll a park közepén, a peremén galambok tollászkodnak. Kavicsos utak indulnak szerteszét.',
 NULL),
('ebredes', 'park_kapu', 'A park kapuja',
 'Kovácsoltvas kapu, mellette megfakult térkép a környékről. A kapun túl forgalmas utca zaja hallatszik.',
 NULL),
('ebredes', 'utca', 'A Fő utca',
 'Széles, forgalmas utca. Az emberek sietve haladnak munkába. Az egyik oldalon egy pékség kirakata világít, a sarkon aluljáró lépcsője vezet lefelé.',
 NULL),
('ebredes', 'pekseg', 'Pékség',
 'Meleg, kenyérillatú kis bolt. A pult mögött egy kerek arcú, lisztes kötényes pék rendezgeti a pékárut.',
 NULL),
('ebredes', 'aluljaro', 'Aluljáró',
 'Huzatos, félhomályos aluljáró. Az egyik sarokban egy üvegvisszaváltó automata zümmög, a falon kopott hirdetések.',
 NULL);

INSERT INTO exits (game_id, from_location, direction, to_location, description, minutes) VALUES
('ebredes', 'park_pad',  'eszak',  'park_kut',  'egy kavicsos ösvény',            3),
('ebredes', 'park_kut',  'del',    'park_pad',  'az ösvény a padhoz',             3),
('ebredes', 'park_kut',  'kelet',  'park_kapu', 'a fasor a kapu felé',            4),
('ebredes', 'park_kapu', 'nyugat', 'park_kut',  'a fasor vissza a park közepére', 4),
('ebredes', 'park_kapu', 'ki',     'utca',      'a kovácsoltvas kapu',            1),
('ebredes', 'utca',      'be',     'pekseg',    'a pékség ajtaja',                1),
('ebredes', 'utca',      'le',     'aluljaro',  'az aluljáró lépcsője',           2),
('ebredes', 'utca',      'park',   'park_kapu', 'a park kapuja',                  1),
('ebredes', 'pekseg',    'ki',     'utca',      'az ajtó az utcára',              1),
('ebredes', 'aluljaro',  'fel',    'utca',      'a lépcső fel az utcára',         2);

INSERT INTO items (game_id, id, name, description, price, nutrition) VALUES
('ebredes', 'ures_uveg', 'üres üveg',     'Visszaváltható üres üdítősüveg. Az automata 50 Ft-ot ad érte.', 50, 0),
('ebredes', 'ujsag',     'tegnapi újság', 'Gyűrött, tegnapi napilap. Az apróhirdetések között talán van munka.', 0, 0),
('ebredes', 'kifli',     'kifli',         'Friss, ropogós kifli.', 60, 8),
('ebredes', 'pogacsa',   'pogácsa',       'Meleg, sajtos pogácsa.', 150, 15),
('ebredes', 'kenyer',    'fél kenyér',    'Fél kiló friss fehér kenyér.', 450, 35);

INSERT INTO location_items (game_id, location_id, item_id, quantity) VALUES
('ebredes', 'park_pad', 'ures_uveg', 2),
('ebredes', 'park_pad', 'ujsag',     1),
('ebredes', 'park_kut', 'ures_uveg', 1);
