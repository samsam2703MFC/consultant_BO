-- [380] Asystent « HACCP miesiąca » (co miesiąc ; matrice des obligations 224 : Specjalista ds. jakości 1 « Plan HACCP,
-- jego aktualizacja i kontrole na hali », Kierownik produkcji 5 « Jakość i HACCP razem z działem jakości », Brygadzista
-- zmiany 4 « Kontrola higieny i zapisów HACCP », Kierowca-dostawca 7 « Dbałość o pojazd i temperaturę w chłodni ») :
-- chaque mois, le mois écoulé (et le mois en cours jusqu'à aujourd'hui, en rattrapage) :
--   - événements HACCP du module Kierownik produkcji (n_mgr_haccp_event, migration 041 renommée par 069) : rupture de la
--     chaîne du froid non traitée (treated = 0, aussi les plus anciennes sur haccp_month.backlog_months mois), contrôle,
--     audit ou autocontrôle au résultat défavorable (result = 'unfavorable'), audit indépendant sous le seuil du plan de
--     l'année (n_mgr_plan.audit_threshold) ;
--   - procédures SOP en vigueur (n_sop.status = 'active', migration 210) dont la revue est dépassée (review_at < aujourd'hui) ;
--   - exécutions de SOP avec écart (n_sop_run.result = 'deviation'), étape HACCP (n_sop_step.tag = 'haccp') signalée ;
--   - incidents « Temperatura » signalés à la livraison sur la tablette chauffeur (n_delivery_incident.kind = 'temperature',
--     migration 195).
-- Une décision par élément signalé (Wyjaśnione / zamknięte, Działanie korygujące avec note et responsable / poste
-- optionnels, Niezgodność (NC) avec note et lien vers le registre NC), puis « Zakończ » écrit n_wizard_done (code
-- haccp_month_reviewed) et la tâche du poste se valide seule. Lecture seule de l'ERP : aucun événement HACCP marqué
-- « obsłużone », aucune SOP changée, aucune NC créée, aucun e-mail ni SMS.
--  - n_haccp_month_review : une décision par (mois examiné, élément) ; signaux au moment de l'enregistrement ;
--  - n_prod_param haccp_month.backlog_months, haccp_month.trend_months (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « HACCP miesiąca » devient haccp_month_reviewed.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_haccp_month_review (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start    DATE NOT NULL COMMENT 'pierwszy dzień ocenianego miesiąca',
    item_key       VARCHAR(80) NOT NULL COMMENT 'element : haccp:<id>, sop_review:<id>:<review_at>, sop_run:<id>, temp:<id>',
    kind           ENUM('haccp_event','sop_review','sop_run','delivery_temp') NOT NULL,
    source_id      INT NOT NULL COMMENT 'n_mgr_haccp_event.id, n_sop.id, n_sop_run.id albo n_delivery_incident.id',
    decision       ENUM('explained','corrective','nc') NOT NULL COMMENT 'explained = wyjaśnione / zamknięte ; corrective = działanie korygujące ; nc = niezgodność (NC)',
    note           VARCHAR(500) NULL COMMENT 'wymagana dla działania korygującego',
    id_responsible INT NULL COMMENT 'user.id — osoba odpowiedzialna (opcjonalnie, działanie korygujące)',
    id_position    INT NULL COMMENT 'n_position.id — stanowisko odpowiedzialne (opcjonalnie, działanie korygujące)',
    flags          VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user        INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_haccp_month_review (month_start, item_key),
    KEY idx_haccp_month_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='HACCP miesiąca : decyzja dla elementu w miesiącu [380]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'haccp_month.backlog_months', '12', '12', 'int', 'mies.',
    'HACCP miesiąca : ile miesięcy wstecz nieobsłużone przerwania łańcucha chłodniczego wracają do wyjaśnienia',
    'HACCP du mois : mois en arrière pendant lesquels les ruptures de la chaîne du froid non traitées reviennent à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'haccp_month.backlog_months');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'haccp_month.trend_months', '3', '3', 'int', 'mies.',
    'HACCP miesiąca : z iloma poprzednimi miesiącami porównywany jest oceniany miesiąc (trend)',
    'HACCP du mois : nombre de mois précédents comparés au mois examiné (tendance)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'haccp_month.trend_months');

-- Matrice des obligations : JAKOSC 1, KIEROWNIK_PRODUKCJI 5, BRYGADZISTA 4, KIEROWCA 7 (monthly).
UPDATE n_position_duty SET wizard_code = 'haccp_month_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'HACCP miesiąca';
