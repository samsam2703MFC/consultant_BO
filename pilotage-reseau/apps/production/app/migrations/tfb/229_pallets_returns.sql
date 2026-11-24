-- [381] Asystent « Palety zwrotne » (co tydzień ; matrice des obligations 224 : Logistyka 6 « Zwroty palet i opakowań »,
-- Kierowca-dostawca 6 « Rozliczenie palet EUR i dokumentów ») : chaque semaine, la semaine écoulée (lundi → dimanche) :
--   - solde des palettes EUR par client sur pallets_returns.balance_weeks semaines jusqu'à la fin de la semaine examinée :
--     palettes livrées − palettes rendues saisies sur la tablette chauffeur (n_delivery_stop.pallets_delivered,
--     pallets_returned — migration 082 ; arrêts confirmés status 'delivered' ou 'problem', jour d'expédition ship_date,
--     client id_customer ; libellés « Palety dostarczone » / « Palety zwrócone (EUR) » de la tablette et de l'écran
--     Dostawy › potwierdzenia) ;
--   - arrêts de la semaine où il manque des palettes : livrées > rendues sans motif (n_delivery_stop.pallets_note vide,
--     migration 195) ;
--   - palettes abîmées signalées à la livraison (n_delivery_incident.kind = 'pallet_damaged', migration 195).
-- Un client est à expliquer quand son solde dépasse pallets_returns.threshold palettes ou qu'il a dans la semaine un arrêt
-- avec palette manquante ou une palette abîmée. Une décision par client et par semaine examinée — « Przypomnienie / odbiór
-- zaplanowany » (note obligatoire), « OK — saldo uzgodnione » (note), « Spisane / do faktury » (note obligatoire) —, puis
-- « Zakończ » écrit n_wizard_done (code pallets_returns_reviewed) et la tâche du poste se valide seule. Lecture seule de
-- l'ERP : aucun arrêt ni incident modifié, aucune facture créée, aucun e-mail ni SMS.
--  - n_pallet_return_review : une décision par (semaine examinée, client) ; solde et signaux au moment de l'enregistrement ;
--  - n_prod_param pallets_returns.balance_weeks, pallets_returns.threshold (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Palety zwrotne » devient pallets_returns_reviewed.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_pallet_return_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start   DATE NOT NULL COMMENT 'poniedziałek ocenianego tygodnia',
    id_customer  INT NOT NULL COMMENT 'customer.id',
    decision     ENUM('reminder','agreed','written_off') NOT NULL COMMENT 'reminder = przypomnienie / odbiór zaplanowany ; agreed = OK — saldo uzgodnione ; written_off = spisane / do faktury',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla reminder i written_off',
    balance      INT NULL COMMENT 'saldo palet EUR klienta (dostarczone − zwrócone) w chwili zapisu',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pallet_return_review (week_start, id_customer),
    KEY idx_pallet_return_review_customer (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Palety zwrotne : decyzja dla klienta w tygodniu [381]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'pallets_returns.balance_weeks', '8', '8', 'int', 'tyg.',
    'Palety zwrotne : z ilu tygodni (do końca ocenianego tygodnia) liczone jest saldo palet EUR klienta (dostarczone − zwrócone)',
    'Palettes consignées : nombre de semaines (jusqu''à la fin de la semaine examinée) sur lesquelles le solde des palettes EUR du client est calculé (livrées − rendues)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'pallets_returns.balance_weeks');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'pallets_returns.threshold', '4', '4', 'int', 'pal.',
    'Palety zwrotne : od ilu palet EUR salda (powyżej) klient trafia do wyjaśnienia',
    'Palettes consignées : solde de palettes EUR au-dessus duquel le client est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'pallets_returns.threshold');

-- Matrice des obligations : LOGISTYKA 6 « Zwroty palet i opakowań », KIEROWCA 6 « Rozliczenie palet EUR i dokumentów » (weekly).
UPDATE n_position_duty SET wizard_code = 'pallets_returns_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Palety zwrotne';
