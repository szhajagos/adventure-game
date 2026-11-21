-- Játék: Csend a Kepler-állomáson (kis bemutató a többjátékos szerkezethez)
SET NAMES utf8mb4;

INSERT INTO games (id, title, description, intro, narrator_style, start_location, start_minute, sort_order) VALUES
('kepler',
 'Csend a Kepler-állomáson',
 'Egy elhagyatottnak tűnő űrállomáson ébredsz a hibernációból. Gyűjts alkatrészeket és kredit-zsetonokat, derítsd ki, hová tűnt a legénység, és juss haza. (Bemutató, még csak néhány helyszínnel.)',
 'A hibernációs kapszula fedele szisszenve felnyílik. A fények tompák, a levegő hideg és fémes ízű. Az állomás csendes – túlságosan is.',
 'Feszült, szűkszavú sci-fi hangulat, klasszikus űrkalandok stílusában. Gépzúgás, villódzó fények, rádiósercegés. A technikai részletek legyenek hihetők, de érthetők.',
 'hibernacio', 0, 20);

-- A Kepler-állomáson más tulajdonságok számítanak, mint a parkban
INSERT INTO game_stats (game_id, id, label, kind, min_value, max_value, start_value, per_hour, unit, sort_order) VALUES
('kepler', 'kredit',    'Kredit',    'money',         0, NULL,   0,   0, 'kredit', 10),
('kepler', 'oxigen',    'Oxigén',    'bar_high_good', 0, 100,  70, -12, NULL, 20),
('kepler', 'faradtsag', 'Fáradtság', 'bar_low_good',  0, 100,  60,  15, NULL, 30),
('kepler', 'egeszseg',  'Egészség',  'bar_high_good', 0, 100,  80,   0, NULL, 40);

INSERT INTO locations (game_id, id, name, description, image) VALUES
('kepler', 'hibernacio', 'Hibernációs terem',
 'Hat kapszula sorakozik a fal mentén, öt üres és nyitott. Egy vészlámpa lassan pulzál vörösen. Az ajtó a folyosóra félig nyitva áll.',
 NULL),
('kepler', 'folyoso', 'Központi folyosó',
 'Hosszú, kör keresztmetszetű folyosó, a padlórácsok alatt kábelek futnak. Az egyik végén a parancsnoki híd, a másikon a raktár felirata dereng.',
 NULL),
('kepler', 'raktar', 'Raktár',
 'Rögzítőhálókkal lefogott ládák, néhány felborult. A sarokban egy szerszámszekrény ajtaja nyitva maradt.',
 NULL),
('kepler', 'hid', 'Parancsnoki híd',
 'A panorámaablakon túl egy kékesen derengő gázóriás tölti be a látómezőt. A konzolok többsége sötét, csak a kommunikációs panel villog.',
 NULL);

INSERT INTO exits (game_id, from_location, direction, to_location, description, minutes) VALUES
('kepler', 'hibernacio', 'ki',     'folyoso',    'a félig nyitott ajtó',      1),
('kepler', 'folyoso',    'be',     'hibernacio', 'a hibernációs terem ajtaja', 1),
('kepler', 'folyoso',    'eszak',  'hid',        'a folyosó a híd felé',       2),
('kepler', 'folyoso',    'del',    'raktar',     'a folyosó a raktár felé',    2),
('kepler', 'hid',        'del',    'folyoso',    'vissza a folyosóra',         2),
('kepler', 'raktar',     'eszak',  'folyoso',    'vissza a folyosóra',         2);

INSERT INTO items (game_id, id, name, description, price, nutrition) VALUES
('kepler', 'zseton',   'kredit-zseton',  'Kis, fémes zseton az állomás belső fizetőeszközéből.', 10, 0),
('kepler', 'csavarhuzo', 'plazma-csavarhúzó', 'Kopott, de működő szerszám. Sok panelt ki lehet vele nyitni.', 40, 0),
('kepler', 'tapszeles', 'tápszelet', 'Vákuumcsomagolt, ízetlen, de tápláló szelet.', 15, 20);

INSERT INTO location_items (game_id, location_id, item_id, quantity) VALUES
('kepler', 'hibernacio', 'tapszeles', 1),
('kepler', 'raktar',     'csavarhuzo', 1),
('kepler', 'raktar',     'zseton',     3);
