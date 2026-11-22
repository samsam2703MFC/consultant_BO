-- [385] Asystent « Dane klientów » (co miesiąc ; matrice des obligations 224 : ADMINISTRACJA 4 « Dane klientów i
-- cenniki », proposed_wizard = 'Dane klientów') : chaque mois, les clients ACTIFS — au moins une commande non archivée
-- (customer_order.create_order_date, status <> 5) dans les customers_data.active_months derniers mois — dont la fiche
-- (customer, colonnes de ClientRepository::$allowedColumns, celles du formulaire Klienci › Edytuj) casse quelque chose :
--   - e-mail : email_head / email_salesman / email_accountant vides (avis de livraison : email_head sinon email_salesman ;
--     proformas, factures, rappels : email_accountant sinon email_head sinon email_salesman), adresse invalide (même règle
--     que ClientService::invalidEmails, sans connexion réseau), dernier e-mail envoyé à une adresse de la fiche en erreur
--     (communication_message.status 'failed', sans envoi réussi plus récent à la même adresse) ;
--   - téléphone : phone_head et phone_alt vides, ou illisibles pour la passerelle SMS (SmsGateway::e164) ;
--   - langue : communication_lang hors de DocumentLabels::LANGUAGES (textes en langue par défaut) ;
--   - délai de paiement : payment_termin vide (Płynność prend liquidity.client_terms_days, facture sans terme) ;
--   - NIP / VAT : tax_number vide (consignee_nip des WZ, CMR, WDT, proformas, factures) ; client livré en WDT (document_wdt
--     sur la période, ou pays de la fiche hors Pologne) sans VAT UE à préfixe pays — règle de
--     KsefWdtInvoiceXmlMapper::splitEuVat (« WDT nie zawiera wymaganego pola: consignee_nip » / « WDT musi zawierać VAT UE… ») ;
--   - adresse : fac_street, fac_street_num, fac_zip, fac_town, id_country (consignee_address_1 / 2 des documents, tablette
--     du chauffeur) ;
--   - cennik : aucune ligne final_product_customer_price, ou produit commandé sur la période sans prix client (> 0) — WDT
--     au prix standard (IFNULL(fpcp.price, fp.standard_price)) ; clients intégrés (franchisee_uid) non contrôlés : leur
--     cennik vient de la boutique par le réseau, que l'assistant n'appelle pas.
-- Une décision par client signalé et par mois : « Poprawione » (acceptée seulement si la fiche n'a plus aucun problème au
-- moment de l'enregistrement), « Brak danych od klienta » (note obligatoire), « Nie dotyczy » (note obligatoire, reprise les
-- mois suivants tant que les problèmes sont les mêmes) ; « Zakończ » écrit n_wizard_done (code customers_data_checked).
-- L'assistant NE MODIFIE PAS la fiche client (lien vers /clients/{id}/edit et /clients/{id}/price-list), n'envoie rien.
-- Aucune donnée personnelle copiée ici : seulement l'id du client et les codes des problèmes.
--  - n_customers_data_check : une décision par (mois du contrôle, client) ;
--  - n_prod_param customers_data.active_months (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Dane klientów » devient customers_data_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_customers_data_check (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca kontroli',
    id_customer  INT NOT NULL COMMENT 'customer.id (bez klucza obcego) — żadnych danych klienta',
    decision     ENUM('fixed','no_data','not_applicable') NOT NULL COMMENT 'fixed = poprawione (karta bez problemów w chwili zapisu) ; no_data = brak danych od klienta ; not_applicable = nie dotyczy',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla no_data i not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'kody problemów w chwili zapisu (przecinek), bez wartości z karty',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_data_check (month_start, id_customer),
    KEY idx_customers_data_check_customer (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Dane klientów : decyzja dla klienta z brakami w miesiącu [385]';

-- Réglage : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'customers_data.active_months', '3', '3', 'int', 'mies.',
    'Dane klientów : klient aktywny = zamówienie w ostatnich N miesiącach (te miesiące są też oknem e-maili z błędem i zamówionych produktów bez ceny)',
    'Données clients : client actif = commande dans les N derniers mois (fenêtre aussi des e-mails en erreur et des produits commandés sans prix)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'customers_data.active_months');

-- Matrice des obligations : ADMINISTRACJA 4 « Dane klientów i cenniki » (monthly).
UPDATE n_position_duty SET wizard_code = 'customers_data_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Dane klientów';
