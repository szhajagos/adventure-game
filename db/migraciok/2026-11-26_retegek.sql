-- ============================================================================
--  Képrétegek: a helyszínképre rajzolt tárgyak és szereplők
--  A tárgyképek: assets/images/<játék>/targyak/<tárgy_id>.webp (vagy .png),
--  a szereplőké: assets/images/<játék>/szereplok/<szereplő_id>.webp (átlátszó háttérrel).
--  Hogy hova kerüljenek a képen, azt helyszínenként a layers mező mondja meg:
--  {"ures_uveg": {"x": 20, "y": 85, "w": 6}, "@pek": {"x": 60, "y": 90, "w": 18}}
--  (x, y: a kép talppontja százalékban; w: a szélessége a kép szélességének százalékában).
--  Ha egy képréteg vagy egy pozíció hiányzik, az a tárgy egyszerűen nem jelenik meg a képen.
--  A MÁR MŰKÖDŐ adatbázisra EGYSZER kell importálni, a 2026-11-25_szereplok_kereskedelem.sql UTÁN.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE locations ADD COLUMN layers LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(layers)) AFTER open_hours;
