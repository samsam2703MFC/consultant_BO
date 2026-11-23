-- [373] Asystent « Płynność i gotówka » (Kierownik operacyjny, obowiązki 2.1, 2.2 i 2.8 : monitorowanie sytuacji
-- finansowej, planowanie i zabezpieczanie środków, przeciwdziałanie brakowi środków, który zakłóciłby produkcję) :
-- chaque semaine, une revue de trésorerie à 4 semaines d'après les données de l'ERP (proformas non payées et leur
-- échéance, commandes ouvertes sans proforma, factures d'achat et commandes fournisseurs à payer, liste de paie),
-- le solde disponible saisi à la main (l'ERP n'a pas de solde bancaire), une décision par signal, puis « Zakończ »
-- écrit n_wizard_done (code liquidity_checked) et la tâche hebdomadaire du poste se valide seule.
-- Rien n'est envoyé (ni e-mail ni SMS) ; rien n'est écrit hors des deux tables ci-dessous et de n_wizard_done.
--  - n_liquidity_balance : une ligne par semaine (lundi) : solde banque PLN, banque EUR, caisse, autres dépenses
--    connues des semaines 1 à 4 (saisies), date du solde, auteur ;
--  - n_liquidity_decision : une décision par signal et par semaine (monitorować / działanie avec note / bez ryzyka),
--    avec le libellé, les codes et le montant du signal au moment de l'enregistrement ;
--  - n_prod_param liquidity.* : seuils (créances échues, gros achat, coussin minimal) et délais de paiement par défaut ;
--  - matrice des obligations (216) : l'assistant proposé « Płynność i gotówka » devient liquidity_checked (weekly).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_liquidity_balance (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start    DATE NOT NULL COMMENT 'poniedziałek tygodnia przeglądu (asystent « Płynność i gotówka »)',
    balance_date  DATE NOT NULL COMMENT 'dzień, na który podano saldo',
    bank_pln      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'saldo rachunków w zł',
    bank_eur      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'saldo rachunków w € (przeliczane kursem z ustawień firmy)',
    cash_pln      DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'gotówka w kasie (zł)',
    other_out_1   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'inne znane wydatki w tygodniu 1 (zł : czynsz, raty, podatki…)',
    other_out_2   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'inne znane wydatki w tygodniu 2 (zł)',
    other_out_3   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'inne znane wydatki w tygodniu 3 (zł)',
    other_out_4   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'inne znane wydatki w tygodniu 4 (zł)',
    note          VARCHAR(500) NULL,
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_liquidity_balance_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Płynność i gotówka : saldo dostępne i inne wydatki tygodnia [373]';

CREATE TABLE IF NOT EXISTS n_liquidity_decision (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start    DATE NOT NULL COMMENT 'poniedziałek tygodnia przeglądu',
    signal_key    VARCHAR(80) NOT NULL COMMENT 'sygnał : client:<id>, supplier:<klucz>, week:<poniedziałek>',
    signal_type   ENUM('client','supplier','week') NOT NULL,
    label         VARCHAR(160) NULL COMMENT 'nazwa sygnału w chwili zapisu (klient, dostawca, tydzień)',
    codes         VARCHAR(120) NULL COMMENT 'kody sygnału w chwili zapisu (przecinek)',
    amount_pln    DECIMAL(14,2) NULL COMMENT 'kwota sygnału w zł w chwili zapisu',
    decision      ENUM('monitor','action','no_risk') NOT NULL COMMENT 'monitor = monitorować, action = działanie (notatka), no_risk = bez ryzyka',
    note          VARCHAR(500) NULL,
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_liquidity_decision (week_start, signal_key),
    KEY idx_liquidity_decision_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Płynność i gotówka : decyzja dla sygnału tygodnia [373]';

-- Seuils et délais : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'liquidity.overdue_min', '5000', '5000', 'decimal', 'zł',
    'Płynność i gotówka : zaległe należności klienta (po terminie) od tej kwoty w zł trafiają do sygnałów',
    'Trésorerie : créances échues d''un client à partir de ce montant (zł) signalées'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'liquidity.overdue_min');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'liquidity.big_purchase_min', '10000', '10000', 'decimal', 'zł',
    'Płynność i gotówka : płatności jednemu dostawcy w ciągu 14 dni od tej kwoty w zł trafiają do sygnałów',
    'Trésorerie : paiements à un même fournisseur dans les 14 jours à partir de ce montant (zł) signalés'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'liquidity.big_purchase_min');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'liquidity.min_balance', '0', '0', 'decimal', 'zł',
    'Płynność i gotówka : najmniejsze bezpieczne saldo (poduszka) w zł — tydzień, w którym prognoza spada poniżej, trafia do sygnałów',
    'Trésorerie : solde minimal de sécurité (zł) — une semaine dont la prévision passe en dessous est signalée'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'liquidity.min_balance');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'liquidity.supplier_terms_days', '14', '14', 'int', 'dni',
    'Płynność i gotówka : termin płatności faktur zakupowych i zamówień u dostawców (dni od wystawienia / dostawy) — ERP nie zapisuje terminu ani zapłaty',
    'Trésorerie : délai de paiement des factures d''achat et commandes fournisseurs (jours après émission / livraison) — l''ERP n''enregistre ni échéance ni paiement'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'liquidity.supplier_terms_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'liquidity.client_terms_days', '14', '14', 'int', 'dni',
    'Płynność i gotówka : termin płatności klienta bez « czasu na płatność » w karcie klienta (dni od wystawienia proformy / wysyłki)',
    'Trésorerie : délai de paiement d''un client sans « délai de paiement » dans sa fiche (jours après la proforma / l''expédition)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'liquidity.client_terms_days');

-- Matrice des obligations : 2.1, 2.2 et 2.8 (FINANSE — zabezpieczenie płynności i ciągłości produkcji), rythme weekly.
UPDATE n_position_duty SET wizard_code = 'liquidity_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Płynność i gotówka';
