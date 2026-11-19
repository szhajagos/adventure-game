-- Adventure Game – a kezdő világ (első próbaterület)
SET NAMES utf8mb4;

INSERT INTO locations (id, name, description, image) VALUES
('park_pad', 'Kopott pad a parkban',
 'Egy hideg, kopott padon ébredsz. Fölötted csupasz faágak, a távolban villamos csörömpöl. A pad mellett egy telerakott szemetes áll.',
 NULL),
('park_kut', 'A park közepe',
 'Kiszáradt díszkút áll a park közepén, a peremén galambok tollászkodnak. Kavicsos utak indulnak szerteszét.',
 NULL),
('park_kapu', 'A park kapuja',
 'Kovácsoltvas kapu, mellette megfakult térkép a környékről. A kapun túl forgalmas utca zaja hallatszik.',
 NULL),
('utca', 'A Fő utca',
 'Széles, forgalmas utca. Az emberek sietve haladnak munkába. Az egyik oldalon egy pékség kirakata világít, a sarkon aluljáró lépcsője vezet lefelé.',
 NULL),
('pekseg', 'Pékség',
 'Meleg, kenyérillatú kis bolt. A pult mögött egy kerek arcú, lisztes kötényes pék rendezgeti a pékárut.',
 NULL),
('aluljaro', 'Aluljáró',
 'Huzatos, félhomályos aluljáró. Az egyik sarokban egy üvegvisszaváltó automata zümmög, a falon kopott hirdetések.',
 NULL);

INSERT INTO exits (from_location, direction, to_location, description, minutes) VALUES
('park_pad',  'eszak',  'park_kut',  'egy kavicsos ösvény',            3),
('park_kut',  'del',    'park_pad',  'az ösvény a padhoz',             3),
('park_kut',  'kelet',  'park_kapu', 'a fasor a kapu felé',            4),
('park_kapu', 'nyugat', 'park_kut',  'a fasor vissza a park közepére', 4),
('park_kapu', 'ki',     'utca',      'a kovácsoltvas kapu',            1),
('utca',      'be',     'pekseg',    'a pékség ajtaja',                1),
('utca',      'le',     'aluljaro',  'az aluljáró lépcsője',           2),
('utca',      'park',   'park_kapu', 'a park kapuja',                  1),
('pekseg',    'ki',     'utca',      'az ajtó az utcára',              1),
('aluljaro',  'fel',    'utca',      'a lépcső fel az utcára',         2);

INSERT INTO items (id, name, description, price, nutrition) VALUES
('ures_uveg',  'üres üveg',     'Visszaváltható üres üdítősüveg. Az automata 50 Ft-ot ad érte.', 50, 0),
('ujsag',      'tegnapi újság', 'Gyűrött, tegnapi napilap. Az apróhirdetések között talán van munka.', 0, 0),
('kifli',      'kifli',         'Friss, ropogós kifli.', 60, 8),
('pogacsa',    'pogácsa',       'Meleg, sajtos pogácsa.', 150, 15),
('kenyer',     'fél kenyér',    'Fél kiló friss fehér kenyér.', 450, 35);

INSERT INTO location_items (location_id, item_id, quantity) VALUES
('park_pad', 'ures_uveg', 2),
('park_pad', 'ujsag',     1),
('park_kut', 'ures_uveg', 1);

-- Prototípus: egyetlen tesztjátékos (később a bejelentkezésből jön)
INSERT INTO players (id, name, location_id, money, hunger, fatigue, health, game_minute) VALUES
(1, 'Tesztjátékos', 'park_pad', 340, 45, 35, 90, 390);
