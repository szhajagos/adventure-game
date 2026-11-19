-- Csak az első induláskor fut le (amikor a db_data kötet még üres).
SET NAMES utf8mb4;

CREATE TABLE locations (
    id          VARCHAR(50)  NOT NULL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         NOT NULL,
    image       VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

INSERT INTO locations (id, name, description, image) VALUES
('park_pad', 'Kopott pad a parkban',
 'Egy hideg, kopott padon ébredsz. Fölötted csupasz faágak, a távolban villamos csörömpöl. A zsebeid szinte üresek.',
 NULL);
