-- [375] Asystent « Terminowość tygodnia (OTIF) » (Kierownik operacyjny, obowiązek 4.3 : kontrolowanie terminowości
-- realizacji zamówień i dostaw — ZARZĄDZANIE, co tydzień) : chaque semaine, l'OTIF (On Time In Full) de la semaine
-- écoulée (commandes clients dues du lundi au dimanche, sans gratis), la tendance des 4 semaines précédentes, la
-- ventilation par client, par jour et par cause, puis une action par cause principale ; « Zakończ » écrit
-- n_wizard_done (code otif_week_reviewed) et la tâche du poste se valide seule. Lecture seule de l'ERP : rien n'est
-- écrit dans les commandes, rien n'est envoyé.
--  - n_otif_week_action : une action par semaine examinée et par cause principale (akceptowane jednorazowo, działanie
--    korygujące avec note et responsable / poste optionnels, do obserwacji), nombre de commandes au moment de l'enregistrement ;
--  - n_otif_week : le résultat de la semaine examinée, figé à « Zakończ » (commandes, à l'heure, complètes, OTIF, objectif) ;
--  - n_prod_param otif.* : objectif OTIF en %, jours admis entre la date d'expédition et la livraison confirmée
--    (bornes dans ParamRules) ;
--  - matrice des obligations (216) : l'assistant proposé « Terminowość tygodnia (OTIF) » devient otif_week_reviewed.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_otif_week_action (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start     DATE NOT NULL COMMENT 'poniedziałek ocenianego tygodnia',
    cause          VARCHAR(30) NOT NULL COMMENT 'główna przyczyna (OtifWeekService::CAUSES)',
    decision       ENUM('accepted','corrective','watch') NOT NULL COMMENT 'accepted = akceptowane (jednorazowe) ; corrective = działanie korygujące ; watch = do obserwacji',
    note           VARCHAR(500) NULL COMMENT 'wymagana dla działania korygującego',
    id_responsible INT NULL COMMENT 'user.id — osoba odpowiedzialna (opcjonalnie)',
    id_position    INT NULL COMMENT 'n_position.id — stanowisko odpowiedzialne (opcjonalnie)',
    orders         INT NOT NULL DEFAULT 0 COMMENT 'liczba zamówień z tą przyczyną w chwili zapisu',
    id_user        INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_otif_week_action (week_start, cause)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Terminowość tygodnia (OTIF) : działanie dla przyczyny [375]';

CREATE TABLE IF NOT EXISTS n_otif_week (
    week_start     DATE NOT NULL COMMENT 'poniedziałek ocenianego tygodnia',
    orders         INT NOT NULL DEFAULT 0 COMMENT 'zamówienia z terminem w tygodniu (bez gratisów)',
    on_time        INT NOT NULL DEFAULT 0,
    measured_full  INT NOT NULL DEFAULT 0 COMMENT 'zamówienia z danymi o ilościach wysłanych (WDT)',
    in_full        INT NOT NULL DEFAULT 0,
    measured_otif  INT NOT NULL DEFAULT 0 COMMENT 'mianownik OTIF (bez zamówień wysłanych bez danych o ilościach)',
    otif           INT NOT NULL DEFAULT 0,
    otif_pct       DECIMAL(5,1) NULL,
    target_pct     DECIMAL(5,1) NULL,
    summary        VARCHAR(255) NULL,
    id_user        INT NULL,
    closed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Terminowość tygodnia (OTIF) : wynik zatwierdzony [375]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'otif.target_pct', '95', '95', 'decimal', '%',
    'Terminowość tygodnia (OTIF) : cel — odsetek zamówień wysłanych na czas i w całości',
    'Ponctualité de la semaine (OTIF) : objectif — part des commandes expédiées à l''heure et complètes'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'otif.target_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'otif.delivery_days', '1', '1', 'int', 'dni',
    'Terminowość tygodnia (OTIF) : ile dni po dacie wysyłki dostawa potwierdzona na tablecie kierowcy jest jeszcze na czas',
    'Ponctualité de la semaine (OTIF) : jours après la date d''expédition pendant lesquels une livraison confirmée (tablette chauffeur) reste à l''heure'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'otif.delivery_days');

-- Matrice des obligations : 4.3 « Kontrolowanie terminowości realizacji zamówień i dostaw » (weekly).
UPDATE n_position_duty SET wizard_code = 'otif_week_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Terminowość tygodnia (OTIF)';
