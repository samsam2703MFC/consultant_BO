-- [379] Asystent « Reklamacje klientów » (Specjalista ds. jakości, obowiązek 3 « Reklamacje klientów : ocena, odpowiedź,
-- działania », co tydzień) : chaque semaine, les réclamations clients (complaint) ouvertes ou mal suivies — NEW / IN_REVIEW
-- depuis plus de complaints_review.open_days jours (reported_at, sinon acceptance_timestamp), ACCEPTED sans réponse au
-- magasin (production_response vide), ACCEPTED pas encore vues par le groupe de production du produit (n_complaint_seen,
-- migration 215 ; groupe comme ComplaintBoardRepository), ACCEPTED d'un produit sans groupe (aucun tablet ne la montre) —
-- sur les réclamations acceptées depuis complaints_review.lookback_days jours ; et les réclamations de la semaine écoulée
-- par type, motif, client, produit et groupe (valeur au carton entamé seulement pour le profil Finanse / CEO, [312]).
-- Une décision par réclamation signalée et par semaine examinée, puis « Zakończ » écrit n_wizard_done (code
-- complaints_reviewed) et la tâche du poste se valide seule. Lecture seule de l'ERP : aucun statut de réclamation changé,
-- aucune NC créée (lien vers le registre NC existant), aucun e-mail ni SMS.
--  - n_complaint_review : une décision par (semaine examinée, réclamation) — answered « Odpowiedź / zamknięta » (vérifiée :
--    réclamation REJECTED, ou ACCEPTED avec réponse, au moment de l'enregistrement), nc « Niezgodność (NC) », watch « Do
--    obserwacji » (note obligatoire) ; signaux et statut de la réclamation au moment de l'enregistrement ;
--  - n_prod_param complaints_review.open_days, complaints_review.lookback_days (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Reklamacje klientów » devient complaints_reviewed.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_complaint_review (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start    DATE NOT NULL COMMENT 'poniedziałek ocenianego tygodnia',
    id_complaint  INT NOT NULL COMMENT 'complaint.id',
    decision      ENUM('answered','nc','watch') NOT NULL COMMENT 'answered = odpowiedź / zamknięta (sprawdzone : REJECTED albo ACCEPTED z odpowiedzią) ; nc = niezgodność (NC) ; watch = do obserwacji',
    note          VARCHAR(500) NULL COMMENT 'wymagana dla watch',
    flags         VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    status_at     VARCHAR(16) NULL COMMENT 'complaint.acceptance_status w chwili zapisu',
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_review (week_start, id_complaint),
    KEY idx_complaint_review_complaint (id_complaint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reklamacje klientów : decyzja dla reklamacji w tygodniu [379]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'complaints_review.open_days', '3', '3', 'int', 'dni',
    'Reklamacje klientów : po ilu dniach reklamacja bez decyzji (nowa, w weryfikacji) albo przyjęta i niewidziana przez grupę trafia do wyjaśnienia',
    'Réclamations clients : jours après lesquels une réclamation sans décision (nouvelle, en vérification) ou acceptée et non vue par le groupe est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'complaints_review.open_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'complaints_review.lookback_days', '56', '56', 'int', 'dni',
    'Reklamacje klientów : ile dni wstecz sprawdzane są reklamacje przyjęte (bez odpowiedzi do magazynu, niewidziane przez grupę)',
    'Réclamations clients : jours en arrière pendant lesquels les réclamations acceptées sont contrôlées (sans réponse au magasin, non vues par le groupe)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'complaints_review.lookback_days');

-- Matrice des obligations : JAKOSC 3 « Reklamacje klientów : ocena, odpowiedź, działania » (weekly).
UPDATE n_position_duty SET wizard_code = 'complaints_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Reklamacje klientów';
