-- Zadania stanowisk : kategorie (ceny, marże, upload, produkcja, zebrania…), dzień terminu zadań tygodniowych,
-- zadania z formularzem (protokół zebrania produkcyjnego : uczestnicy, śledzenie produkcji, tematy, działania).
--
-- tfb_task_form : wypełniony formularz okresu zadania (jeden na okres), szkic albo zakończony.
-- tfb_task_action : działania z protokołów (kto, do kiedy) ; otwarte wracają na następne zebranie.
-- Kolumny dodawane warunkowo (MySQL bez ADD COLUMN IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS tfb_task_category (
    id          INT NOT NULL AUTO_INCREMENT,
    code        VARCHAR(32)  NOT NULL,
    name_pl     VARCHAR(100) NOT NULL,
    name_fr     VARCHAR(100) NOT NULL,
    icon        VARCHAR(40)  NOT NULL DEFAULT 'bi-check2-square',
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_task_category_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_task_category (code, name_pl, name_fr, icon, sort_order) VALUES
('PRODUKCJA', 'Produkcja i śledzenie', 'Production et suivi', 'bi-gear-wide-connected', 10),
('ZEBRANIA', 'Zebrania i protokoły', 'Réunions et comptes rendus', 'bi-people', 20),
('CENY', 'Ceny i oferty', 'Prix et offres', 'bi-tag', 30),
('MARZE', 'Marże i koszty', 'Marges et coûts', 'bi-percent', 40),
('UPLOAD', 'Dokumenty i upload', 'Documents et téléversements', 'bi-cloud-upload', 50),
('JAKOSC', 'Jakość', 'Qualité', 'bi-shield-check', 60),
('LOGISTYKA', 'Logistyka', 'Logistique', 'bi-truck', 70),
('ADMIN', 'Administracja', 'Administration', 'bi-briefcase', 80);

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_position_task' AND COLUMN_NAME = 'id_category');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_position_task ADD COLUMN id_category INT NULL AFTER id_position', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_position_task' AND COLUMN_NAME = 'form_code');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_position_task ADD COLUMN form_code VARCHAR(32) NULL AFTER link_path', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_position_task' AND COLUMN_NAME = 'due_weekday');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_position_task ADD COLUMN due_weekday TINYINT NULL COMMENT ''Zadanie tygodniowe : dzień terminu 1 = pon. … 7 = niedz. (domyślnie piątek)'' AFTER frequency', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tfb_task_form (
    id             BIGINT NOT NULL AUTO_INCREMENT,
    id_occurrence  BIGINT NOT NULL,
    form_code      VARCHAR(32) NOT NULL,
    meeting_date   DATE NOT NULL,
    period_from    DATE NULL,
    period_to      DATE NULL,
    data           LONGTEXT NOT NULL COMMENT 'JSON : uczestnicy, tematy, migawka śledzenia produkcji',
    status         VARCHAR(8) NOT NULL DEFAULT 'draft',
    created_by     INT NULL,
    updated_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_task_form_occurrence (id_occurrence),
    KEY ix_tfb_task_form_code (form_code, meeting_date),
    CONSTRAINT fk_tfb_task_form_occurrence FOREIGN KEY (id_occurrence) REFERENCES tfb_task_occurrence (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_task_action (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    id_form       BIGINT NOT NULL,
    title         VARCHAR(300) NOT NULL,
    id_user       INT NULL,
    due_date      DATE NULL,
    status        VARCHAR(8) NOT NULL DEFAULT 'open',
    done_at       DATETIME NULL,
    done_by       INT NULL,
    done_in_form  BIGINT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_task_action_status (status, due_date),
    CONSTRAINT fk_tfb_task_action_form FOREIGN KEY (id_form) REFERENCES tfb_task_form (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Kategorie istniejących zadań (tylko bez kategorii) : według strony w ERP, potem według stanowiska (inne stanowiska : bez kategorii).
UPDATE tfb_position_task t JOIN tfb_task_category c ON c.code = 'CENY' SET t.id_category = c.id
 WHERE t.id_category IS NULL AND (t.link_path IN ('/materials/prices', '/purchasing/offers', '/purchasing/tenders'));
UPDATE tfb_position_task t JOIN tfb_task_category c ON c.code = 'MARZE' SET t.id_category = c.id
 WHERE t.id_category IS NULL AND t.link_path = '/purchasing';
UPDATE tfb_position_task t JOIN tfb_task_category c ON c.code = 'JAKOSC' SET t.id_category = c.id
 WHERE t.id_category IS NULL AND t.link_path = '/purchasing/nc';
UPDATE tfb_position_task t JOIN tfb_task_category c ON c.code = 'LOGISTYKA' SET t.id_category = c.id
 WHERE t.id_category IS NULL AND t.link_path IN ('/shipping-documents/create', '/shipments');
UPDATE tfb_position_task t JOIN tfb_position p ON p.id = t.id_position
  JOIN tfb_task_category c ON c.code = CASE p.code WHEN 'KIEROWNIK_PRODUKCJI' THEN 'PRODUKCJA' WHEN 'JAKOSC' THEN 'JAKOSC' WHEN 'LOGISTYKA' THEN 'LOGISTYKA'
                                                    WHEN 'ADMINISTRACJA' THEN 'ADMIN' WHEN 'ZAKUPY' THEN 'CENY' END
  SET t.id_category = c.id WHERE t.id_category IS NULL;
-- « Plan produkcji na jutro » : strona planu zespołów (stara ścieżka przekierowuje).
UPDATE tfb_position_task SET link_path = '/workshop/team-sheet' WHERE link_path = '/production/planning';

-- Nowe zadania cykliczne (raz na stanowisko : bez duplikatu tytułu).
INSERT INTO tfb_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, link_path, form_code, sort_order)
SELECT p.id, c.id, t.title_pl, t.title_fr, t.frequency, t.due_weekday, t.link_path, t.form_code, t.sort_order
FROM (
    SELECT 'KIEROWNIK_PRODUKCJI' AS pos, 'ZEBRANIA' AS cat, 'Zebranie produkcyjne — protokół' AS title_pl, 'Réunion de production — compte rendu' AS title_fr,
           'weekly' AS frequency, 1 AS due_weekday, NULL AS link_path, 'production_meeting' AS form_code, 5 AS sort_order
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'PRODUKCJA', 'Śledzenie produkcji : plan, zrobione, straty, zatwierdzone dni', 'Suivi de la production : plan, réalisé, pertes, journées validées',
           'daily', NULL, '/production/follow-up', NULL, 15
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'MARZE', 'Przeliczyć marże kategorii po zmianach cen', 'Recalculer les marges des catégories après les changements de prix',
           'monthly', NULL, '/finances/category-margin', NULL, 25
    UNION ALL SELECT 'ZAKUPY', 'UPLOAD', 'Wgrać faktury zakupowe (PDF / KSeF) i przypisać pozycje', 'Téléverser les factures d''achat (PDF / KSeF) et affecter les lignes',
           'weekly', 2, '/workshop/supply#tab=invoices', NULL, 15
    UNION ALL SELECT 'ZAKUPY', 'CENY', 'Zaimportować ostatnie ceny surowców z faktur', 'Importer les derniers prix des matières depuis les factures',
           'weekly', 3, '/workshop/supply#tab=invoices', NULL, 25
) t
JOIN tfb_position p ON p.code = t.pos
JOIN tfb_task_category c ON c.code = t.cat
WHERE NOT EXISTS (SELECT 1 FROM tfb_position_task x WHERE x.id_position = p.id AND x.title_pl = t.title_pl);
