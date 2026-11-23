-- [405] Asystent « Serwisy i przeglądy maszyn (przegląd miesiąca) » (co miesiąc, utilisable à tout moment ; zakres obowiązków
-- « produkcja » 237 : Kierownik produkcji 10.1 serwisy, 10.2 awarie, 10.3 pomiary i przeglądy, 10.6 terminy zleconych usług,
-- proposed_wizard = 'Serwisy i przeglądy maszyn'). L'ERP connaît les machines (n_prod_workstation : moce produkcyjne, TRS) et
-- leurs arrêts « awaria » saisis sur la tablette du groupe (n_prod_ws_state.reason = 'breakdown', 049), mais aucun registre
-- d'entretien : registres propres, saisis dans l'assistant :
--   - n_equipment : urządzenie (maszyna liée à n_prod_workstation quand c'en est une, ou autre équipement : chłodnia, waga,
--     gaśnice, instalacja elektryczna, pojazd…) ;
--   - n_equipment_check : serwis / przegląd / pomiar / legalizacja / UDT d'un urządzenie, co ile miesięcy, dernier fait,
--     prochain terme, firma ;
--   - n_equipment_ticket : zgłoszenie awarii ou zlecony serwis, firma, date de l'zgłoszenie, termin realizacji, statut
--     (otwarte, zlecone, zakończone, anulowane), lien éventuel vers l'arrêt TRS.
-- Signaux : przegląd po terminie / dans les maintenance.alert_days jours / sans date ; zgłoszenie ouvert après son termin ou
-- sans termin ; arrêt « awaria » (depuis le début du mois écoulé, au moins maintenance.breakdown_min_minutes minutes) sans
-- zgłoszenie (lié, ou sur la même machine de la veille à 7 jours après) ; machine active (hors stanowisko ręczne) absente du
-- registre. Une décision par élément et par mois (n_maintenance_review) : « Umówione — termin » (date ; pour un zgłoszenie,
-- devient son nouveau termin), « Załatwione » (seulement quand le signal a disparu — enregistrée seule après la saisie :
-- wykonanie, zamknięcie, zgłoszenie, rejestr), « Nie dotyczy » (note ; reprise tant que les signaux sont les mêmes) ;
-- « Zakończ » écrit n_wizard_done (code maintenance_reviewed). Rien n'est commandé ni envoyé : appeler le serwisant reste humain.
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Serwisy i przeglądy maszyn » → maintenance_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ; droit « maintenance » pour ce poste seulement.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_equipment (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(160) NOT NULL,
    kind           ENUM('machine','cold','scale','fire','electrical','vehicle','other') NOT NULL COMMENT 'maszyna, chłodnia / mroźnia, waga, ppoż., instalacja elektryczna, pojazd, inne',
    id_workstation INT NULL COMMENT 'n_prod_workstation.id (maszyna ERP ; bez klucza obcego)',
    location       VARCHAR(160) NULL,
    note           VARCHAR(255) NULL,
    active         TINYINT(1) NOT NULL DEFAULT 1,
    created_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_ws (id_workstation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Rejestr urządzeń (serwisy i przeglądy) [405]';

CREATE TABLE IF NOT EXISTS n_equipment_check (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_equipment    INT UNSIGNED NOT NULL COMMENT 'n_equipment.id',
    kind            ENUM('service','inspection','measurement','calibration','udt','other') NOT NULL COMMENT 'serwis okresowy, przegląd, pomiar, legalizacja / wzorcowanie, UDT, inne',
    title           VARCHAR(160) NULL,
    interval_months SMALLINT NULL COMMENT 'co ile miesięcy (NULL = bez stałego okresu)',
    last_done       DATE NULL,
    next_due        DATE NULL COMMENT 'następny termin',
    provider        VARCHAR(160) NULL COMMENT 'firma serwisowa',
    note            VARCHAR(255) NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_check_eq (id_equipment, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Serwisy, przeglądy i pomiary urządzeń : terminy [405]';

CREATE TABLE IF NOT EXISTS n_equipment_ticket (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_equipment   INT UNSIGNED NULL COMMENT 'n_equipment.id',
    id_workstation INT NULL COMMENT 'n_prod_workstation.id (bez klucza obcego)',
    id_ws_state    BIGINT NULL COMMENT 'n_prod_ws_state.id : postój « awaria » z tabletu (bez klucza obcego)',
    kind           ENUM('breakdown','service') NOT NULL COMMENT 'awaria, zlecony serwis',
    title          VARCHAR(200) NOT NULL,
    provider       VARCHAR(160) NULL COMMENT 'firma / serwisant',
    reported_on    DATE NOT NULL,
    due_date       DATE NULL COMMENT 'termin realizacji',
    status         ENUM('open','ordered','done','cancelled') NOT NULL DEFAULT 'open' COMMENT 'otwarte, zlecone, zakończone, anulowane',
    closed_on      DATE NULL,
    note           VARCHAR(255) NULL,
    created_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_equipment_ticket_status (status, due_date),
    KEY idx_equipment_ticket_ws (id_workstation, reported_on),
    KEY idx_equipment_ticket_state (id_ws_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Zgłoszenia awarii i zlecone serwisy [405]';

CREATE TABLE IF NOT EXISTS n_maintenance_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca przeglądu',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : check:<id>, ticket:<id>, stop:<n_prod_ws_state.id>, machine:<n_prod_workstation.id>',
    kind         ENUM('check','ticket','stop','machine') NOT NULL,
    decision     ENUM('booked','done','not_applicable') NOT NULL COMMENT 'booked = umówione — termin ; done = załatwione ; not_applicable = nie dotyczy',
    booked_on    DATE NULL,
    note         VARCHAR(500) NULL COMMENT 'wymagana dla not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_maintenance_review (month_start, item_key),
    KEY idx_maintenance_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Serwisy i przeglądy : decyzja dla elementu w miesiącu [405]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'maintenance.alert_days', '30', '30', 'int', 'dni',
    'Serwisy i przeglądy : przegląd / serwis kończący się w ciągu tylu dni to sygnał',
    'Entretien : un contrôle / service échéant dans ce nombre de jours est signalé'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'maintenance.alert_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'maintenance.breakdown_min_minutes', '15', '15', 'int', 'min',
    'Serwisy i przeglądy : postój « awaria » z tabletu krótszy niż tyle minut nie wymaga zgłoszenia',
    'Entretien : un arrêt « panne » de la tablette plus court que ce nombre de minutes ne demande pas de déclaration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'maintenance.breakdown_min_minutes');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 10.1, 10.2, 10.3, 10.6 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'maintenance_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Serwisy i przeglądy maszyn';

-- Tâche du poste (co miesiąc, 5e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
    'Serwisy i przeglądy maszyn : przeglądy i pomiary po terminie albo wkrótce, zgłoszenia awarii i serwisów po terminie, awarie z tabletu bez zgłoszenia → decyzja (asystent)',
    'Entretien des machines : contrôles et mesures échus ou proches, pannes et services commandés en retard, pannes de la tablette sans déclaration → décision (assistant)',
    'monthly', 30, 'wd5', '/maintenance', 'maintenance_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'maintenance_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/maintenance');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'maintenance' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
