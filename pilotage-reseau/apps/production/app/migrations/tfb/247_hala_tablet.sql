-- [409] Tablet hali « Mój dzień » (makieta asystentów stanowisk produkcji, ekrany A–H) : ścieżka dnia grupy na tablecie
-- produkcji, krok po kroku — Co robię dziś → Skanuj worki → Schładzanie → Sprzątanie → Pakuję → Koniec dnia. Les données
-- de production restent celles de l'ERP (parties n_prod_output, lots n_prod_output_lot, pertes n_prod_waste, validation du
-- jour n_prod_day_close) ; ici seulement ce qui n'existait pas :
--   - n_hala_group : réglage par groupe (ścieżka prowadzona oui / non, étapes du groupe) — Jakość / Kierownik produkcji le
--     modifient dans l'ERP (Produkcja › Tablet hali : kroki i strefy) ; sans ligne : étapes par défaut (HalaService) ;
--   - n_hala_clean_zone : strefy sprzątania de chaque groupe (stół, maszyna, podłoga, kosz…), configurables ;
--   - n_hala_clean_record : strefa sprzątnięta — jour, groupe, strefa, personne, heure (une par jour et strefa) ;
--   - n_hala_step : étape « Dalej » confirmée par le groupe dans la journée (accueil : zrobione / teraz / później) ;
--   - n_hala_day : fin de journée d'un groupe sans production (Pakowanie) ou après la validation du jour de production ;
--   - n_hala_shift : « Zatwierdź zmianę » du brygadzista (jour, personne, heure, résumé).
-- Données de départ (modifiables ensuite dans l'ERP) : quatre strefy par groupe actif qui n'en a aucune ; groupe
-- d'emballage (logistics.packing_group, sinon PAKOWANIE) : sans « Co robię » ni « Worki », avec « Pakuję ».
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE. Pas de clé étrangère vers les tables
-- de l'ERP (bases partielles des tests).

CREATE TABLE IF NOT EXISTS n_hala_group (
    id_group    INT NOT NULL COMMENT 'n_prod_group.id',
    guided      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = tablet grupy otwiera « Mój dzień » po PIN-ie',
    step_make   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Co robię dziś (plan, licznik zrobionych, straty)',
    step_bags   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Skanuj worki (partie surowców)',
    step_chill  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Schładzanie (temperatura w środku produktu + minutnik)',
    step_clean  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Sprzątanie (strefy grupy)',
    step_pack   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Pakuję (zamówienie po zamówieniu, tablet pakowania)',
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : kroki dnia grupy [409]';

CREATE TABLE IF NOT EXISTS n_hala_clean_zone (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_group    INT NOT NULL COMMENT 'n_prod_group.id',
    name_pl     VARCHAR(80) NOT NULL,
    name_fr     VARCHAR(80) NULL,
    icon        ENUM('table','machine','floor','bin','oven','fridge','sink','tools','other') NOT NULL DEFAULT 'other',
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by  INT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hala_zone_group (id_group, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : strefy sprzątania grupy [409]';

CREATE TABLE IF NOT EXISTS n_hala_clean_record (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    work_date   DATE NOT NULL,
    id_group    INT NOT NULL,
    id_zone     INT UNSIGNED NOT NULL COMMENT 'n_hala_clean_zone.id',
    id_employee INT NULL COMMENT 'n_prod_employee.id',
    id_device   INT NULL,
    done_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hala_clean (work_date, id_zone),
    KEY idx_hala_clean_group (id_group, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : strefa sprzątnięta (dzień, osoba, godzina) [409]';

CREATE TABLE IF NOT EXISTS n_hala_step (
    work_date   DATE NOT NULL,
    id_group    INT NOT NULL,
    step        ENUM('make','bags','chill','clean','pack') NOT NULL,
    id_employee INT NULL,
    id_device   INT NULL,
    done_at     DATETIME NOT NULL,
    PRIMARY KEY (work_date, id_group, step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : krok dnia zakończony (« Dalej ») [409]';

CREATE TABLE IF NOT EXISTS n_hala_day (
    work_date   DATE NOT NULL,
    id_group    INT NOT NULL,
    finished_at DATETIME NOT NULL,
    id_employee INT NULL,
    id_device   INT NULL,
    summary     VARCHAR(255) NULL,
    PRIMARY KEY (work_date, id_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : « Zatwierdź dzień » grupy [409]';

CREATE TABLE IF NOT EXISTS n_hala_shift (
    work_date    DATE NOT NULL,
    validated_at DATETIME NOT NULL,
    id_employee  INT NULL,
    id_device    INT NULL,
    summary      VARCHAR(255) NULL,
    PRIMARY KEY (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : « Zatwierdź zmianę » brygadzisty [409]';

-- Groupe d'emballage : Sprzątanie → Pakuję → Koniec dnia (sans plan de production ni lots).
INSERT IGNORE INTO n_hala_group (id_group, guided, step_make, step_bags, step_chill, step_clean, step_pack)
SELECT g.id, 1, 0, 0, 0, 1, 1 FROM n_prod_group g
WHERE g.code = COALESCE((SELECT p.value FROM n_prod_param p WHERE p.code = 'logistics.packing_group'), 'PAKOWANIE');

-- Strefy de départ : quatre par groupe actif sans strefa (Jakość les adapte ensuite).
INSERT INTO n_hala_clean_zone (id_group, name_pl, name_fr, icon, sort_order)
SELECT g.id, z.name_pl, z.name_fr, z.icon, z.sort_order FROM n_prod_group g
JOIN (SELECT 'Stół' AS name_pl, 'Table' AS name_fr, 'table' AS icon, 1 AS sort_order
      UNION ALL SELECT 'Maszyna', 'Machine', 'machine', 2
      UNION ALL SELECT 'Podłoga', 'Sol', 'floor', 3
      UNION ALL SELECT 'Kosz', 'Poubelle', 'bin', 4) z
WHERE g.active = 1 AND NOT EXISTS (SELECT 1 FROM n_hala_clean_zone x WHERE x.id_group = g.id);
