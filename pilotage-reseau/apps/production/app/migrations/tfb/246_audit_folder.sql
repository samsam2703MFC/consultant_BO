-- [406] Asystent « Teczka do audytu i kontroli (przegląd kwartału) » (co kwartał ; zakres obowiązków « produkcja » 237 :
-- Kierownik produkcji 2.8, 2.9, 7.4, 8.4 — documentation produits / emballages, audits, Sanepid, organes de contrôle,
-- proposed_wizard = 'Teczka do audytu i kontroli'). Chaque trimestre, l'état de préparation à un audit ou à une kontrola à
-- partir de ce que l'ERP sait déjà :
--   - assistants de contrôle en usage (une tâche de poste active les porte : n_position_task.auto_code) — haccp_month_reviewed,
--     ppwr_month_reviewed, nc_reviewed, complaints_reviewed, lots_qc_day_checked, samples_done, staff_deadlines_reviewed,
--     maintenance_reviewed : dernier « Zakończ » (n_wizard_done) plus ancien que leur rythme + audit_folder.grace_days jours ;
--   - éléments ouverts : ruptures de la chaîne du froid non traitées (n_mgr_haccp_event), niezgodności ouvertes (n_nc),
--     réclamations sans décision (complaint), opakowania d'un produit actif au profil PPWR incomplet (règle du Rejestr PPWR),
--     procédures SOP en vigueur après leur date de revue ou sans date (n_sop) ;
--   - liste des documents à tenir prêts (papier et électronique) : registre propre n_audit_folder_item (document, domaine,
--     forme, responsable, où — fichier / classeur —, contrôlé tous les N mois, dernier contrôle), modifiable dans l'assistant ;
--     une liste de départ est proposée si le registre est vide (à adapter, rien d'obligatoire) ; signaux : jamais contrôlé,
--     contrôle échu ou dans audit_folder.alert_days jours, sans responsable, sans emplacement.
-- Une décision par élément et par trimestre (n_audit_folder_review) : « Gotowe — w teczce » (document : responsable et
-- emplacement exigés, marque le contrôle du jour ; élément de l'ERP encore signalé : note obligatoire), « Do uzupełnienia »
-- (date d'ici un an et note), « Nie dotyczy » (note) ; « Zakończ » écrit n_wizard_done (code audit_folder_reviewed).
-- Sortie : « teczka » imprimable en PDF (TCPDFService). Lecture seule des tables de l'ERP ; rien n'est envoyé.
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Teczka do audytu i kontroli » → audit_folder_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co kwartał, 10e jour ouvré du premier mois) ; droit « audit_folder » pour ce poste seulement.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_audit_folder_item (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(200) NOT NULL,
    area          ENUM('haccp','sanepid','ppwr','bdo','hr','equipment','quality','other') NOT NULL DEFAULT 'other',
    format        ENUM('paper','electronic','both') NOT NULL DEFAULT 'both' COMMENT 'papier, elektronicznie, oba',
    owner         VARCHAR(120) NULL COMMENT 'kto odpowiada (osoba albo stanowisko)',
    location      VARCHAR(255) NULL COMMENT 'gdzie : segregator, szafa, folder, plik',
    check_months  TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'co ile miesięcy sprawdzać',
    last_checked  DATE NULL,
    checked_by    INT NULL,
    note          VARCHAR(255) NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_folder_item_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Teczka do audytu : dokumenty do przygotowania [406]';

CREATE TABLE IF NOT EXISTS n_audit_folder_review (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    quarter_start DATE NOT NULL COMMENT 'pierwszy dzień kwartału',
    item_key      VARCHAR(80) NOT NULL COMMENT 'element : doc:<id>, wizard:<kod>, erp:<haccp|nc|complaints|ppwr|sop>',
    kind          ENUM('doc','wizard','erp') NOT NULL,
    decision      ENUM('ready','action','not_applicable') NOT NULL COMMENT 'ready = gotowe — w teczce ; action = do uzupełnienia ; not_applicable = nie dotyczy',
    due_on        DATE NULL COMMENT 'do uzupełnienia : termin',
    note          VARCHAR(500) NULL,
    flags         VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_audit_folder_review (quarter_start, item_key),
    KEY idx_audit_folder_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Teczka do audytu : decyzja dla elementu w kwartale [406]';

-- Liste de départ (seulement si le registre est vide ; responsables et emplacements à compléter, à adapter ou retirer).
INSERT INTO n_audit_folder_item (title, area, format, sort_order)
SELECT x.title, x.area, x.format, x.sort_order FROM (
    SELECT 'Plan HACCP — aktualna, podpisana wersja' AS title, 'haccp' AS area, 'both' AS format, 10 AS sort_order
    UNION ALL SELECT 'Decyzja Sanepidu o zatwierdzeniu / wpisie zakładu do rejestru', 'sanepid', 'paper', 20
    UNION ALL SELECT 'Wyniki badań wody (aktualne)', 'sanepid', 'both', 30
    UNION ALL SELECT 'Umowa i protokoły DDD (deratyzacja, dezynsekcja)', 'sanepid', 'paper', 40
    UNION ALL SELECT 'Orzeczenia lekarskie pracowników (do celów sanitarno-epidemiologicznych)', 'hr', 'paper', 50
    UNION ALL SELECT 'Rejestr szkoleń BHP i higieny', 'hr', 'both', 60
    UNION ALL SELECT 'Deklaracje dostawców opakowań (PPWR, kontakt z żywnością)', 'ppwr', 'electronic', 70
    UNION ALL SELECT 'Potwierdzenie wpisu do BDO i sprawozdania roczne', 'bdo', 'electronic', 80
    UNION ALL SELECT 'Protokoły przeglądów i pomiarów (elektryka, chłodnictwo, wagi)', 'equipment', 'both', 90
) x
WHERE NOT EXISTS (SELECT 1 FROM n_audit_folder_item);

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'audit_folder.grace_days', '7', '7', 'int', 'dni',
    'Teczka do audytu : asystent kontroli bez « Zakończ » dłużej niż jego rytm + tyle dni to sygnał',
    'Dossier d''audit : un assistant de contrôle sans « Zakończ » depuis plus que son rythme + ce nombre de jours est signalé'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'audit_folder.grace_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'audit_folder.alert_days', '14', '14', 'int', 'dni',
    'Teczka do audytu : dokument do sprawdzenia w ciągu tylu dni to sygnał',
    'Dossier d''audit : un document à contrôler dans ce nombre de jours est signalé'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'audit_folder.alert_days');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 2.8, 2.9, 7.4, 8.4 (quarterly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'audit_folder_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Teczka do audytu i kontroli';

-- Tâche du poste (co kwartał, 10e jour ouvré du premier mois) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'JAKOSC' LIMIT 1),
    'Teczka do audytu i kontroli : asystenci kontroli i otwarte sprawy w ERP, dokumenty do teczki (papier i elektronicznie) → decyzja, teczka PDF (asystent)',
    'Dossier d''audit et de contrôle : assistants de contrôle et points ouverts dans l''ERP, documents du dossier (papier et électronique) → décision, dossier PDF (assistant)',
    'quarterly', 60, 'wd10', '/audit-folder', 'audit_folder_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'audit_folder_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/audit-folder');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout). Les liens vers les écrans d'origine ne
-- sont montrés que si l'utilisateur les voit : aucun autre droit donné.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'audit_folder' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
