-- [377] Asystent « Partie i kontrola dnia » (Specjalista ds. jakości, obowiązek 5 : identyfikowalność partii — surowce →
-- produkty, codziennie ; en lecture, contrôle des obligations atelier PIEKARZ 4, TARTY 4, CUKIERNIK 5, GARMAZER 5) :
-- la traçabilité est vérifiée depuis le bureau, parce que le kiosque laisse clore une journée de groupe avec une simple
-- note (OutputService::closeDay, DAY_GAPS_NOTE_REQUIRED). Chaque jour, les journées de groupe closes (n_prod_day_close)
-- du jour ouvré précédent à aujourd'hui, avec leurs trous : partie de production sans numéro de lot matière (ou avec
-- un motif missing_reason), sans contrôle qualité (n_prod_output.qc_status), lot matière périmé au jour de production
-- ou sans numéro dans le registre (n_material_lot), note de clôture ; un résultat par journée de groupe, puis
-- « Zakończ » écrit n_wizard_done (code lots_qc_day_checked) et la tâche du poste se valide seule. Lecture seule de
-- l'ERP : aucune NC créée, rien n'est modifié dans la production, rien n'est envoyé.
--  - n_lots_qc_check : un résultat par journée de groupe (work_date, id_group) — uzupełnione (vérifié : plus aucun trou),
--    niezgodność (NC, note obligatoire), zaakceptowane (note obligatoire) ; trous au moment de l'enregistrement ;
--  - n_prod_param lots_qc.catchup_days : jours rattrapés au plus quand l'assistant n'a pas été validé (bornes dans
--    ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Partie i kontrola dnia » devient lots_qc_day_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_lots_qc_check (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_date   DATE NOT NULL COMMENT 'dzień produkcji grupy (n_prod_day_close.work_date)',
    id_group    INT NOT NULL COMMENT 'n_prod_group.id',
    result      ENUM('completed','nc','accepted') NOT NULL COMMENT 'completed = uzupełnione w ERP (sprawdzone : brak braków) ; nc = niezgodność ; accepted = zaakceptowane',
    note        VARCHAR(500) NULL COMMENT 'wymagana dla nc i accepted',
    flags       VARCHAR(255) NULL COMMENT 'braki w chwili zapisu (kod:liczba, przecinek)',
    gaps        INT NOT NULL DEFAULT 0 COMMENT 'liczba braków w chwili zapisu',
    checked_on  DATE NOT NULL COMMENT 'dzień sprawdzenia (asystent)',
    id_user     INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lots_qc_check (work_date, id_group),
    KEY idx_lots_qc_check_day (checked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Partie i kontrola dnia : wynik dla dnia grupy [377]';

-- Réglage : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'lots_qc.catchup_days', '7', '7', 'int', 'dni',
    'Partie i kontrola dnia : ile dni wstecz asystent nadrabia, gdy nie był zatwierdzany (zwykle : od poprzedniego dnia roboczego)',
    'Lots et contrôle du jour : jours rattrapés au plus quand l''assistant n''a pas été validé (sinon : depuis le jour ouvré précédent)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'lots_qc.catchup_days');

-- Matrice des obligations : JAKOSC 5, PIEKARZ 4, TARTY 4, CUKIERNIK 5, GARMAZER 5 (daily).
UPDATE n_position_duty SET wizard_code = 'lots_qc_day_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Partie i kontrola dnia';
