-- [378] Asystent « Faktury i KSeF dnia » (Administracja i fakturowanie, obowiązek 2 « Proformy i faktury (KSeF) »,
-- codziennie) : chaque jour, les expéditions (shipment_log) du jour ouvré précédent à aujourd'hui — depuis la dernière
-- validation quand l'assistant n'a pas été validé (rattrapage borné par ksef_day.catchup_days) — et leur facture WDT
-- (document_wdt : doc_num, invoiced_date) avec sa soumission KSeF (ksef_submission : status, error_code, error_message,
-- submitted_at, ksef_number ; dernière erreur de ksef_event_log quand l'envoi n'a même pas commencé). Signaux : expédition
-- sans WDT, WDT sans date de facture, WDT pas envoyée à KSeF, KSeF rejetée, KSeF en échec, KSeF sans réponse depuis
-- ksef_day.pending_days jours ; les signaux encore ouverts des jours précédents (au plus ksef_day.lookback_days jours)
-- reviennent aussi. Un résultat par document signalé, puis « Zakończ » écrit n_wizard_done (code invoices_ksef_checked)
-- et la tâche du poste se valide seule. Lecture seule de l'ERP : rien n'est renvoyé à KSeF, aucune facture modifiée,
-- aucun e-mail ni SMS (le renvoi se fait dans Wysyłki i dokumenty, écran existant).
--  - n_invoice_ksef_check : un résultat par expédition (id_shipment_log) — fixed « Wysłane ponownie / poprawione »
--    (vérifié : KSeF « accepted » au moment de l'enregistrement), in_progress « W toku » (note obligatoire, vaut pour le
--    jour où elle est écrite), justified « Bez faktury — uzasadnione » (note obligatoire) ; signaux et statut KSeF au
--    moment de l'enregistrement ;
--  - n_prod_param ksef_day.catchup_days, ksef_day.pending_days, ksef_day.lookback_days (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Faktury i KSeF dnia » devient invoices_ksef_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_invoice_ksef_check (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_shipment_log  INT NOT NULL COMMENT 'shipment_log.id (jedna wysyłka = jedna WDT = jedno zgłoszenie KSeF)',
    shipment_date    DATE NOT NULL COMMENT 'shipment_log.shipment_date w chwili zapisu',
    id_wdt           INT NULL COMMENT 'document_wdt.id w chwili zapisu (NULL = wysyłka bez WDT)',
    result           ENUM('fixed','in_progress','justified') NOT NULL COMMENT 'fixed = wysłane ponownie / poprawione (sprawdzone : KSeF accepted) ; in_progress = w toku ; justified = bez faktury — uzasadnione',
    note             VARCHAR(500) NULL COMMENT 'wymagana dla in_progress i justified',
    flags            VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    ksef_status      VARCHAR(16) NULL COMMENT 'ksef_submission.status w chwili zapisu (NULL = brak zgłoszenia)',
    checked_on       DATE NOT NULL COMMENT 'dzień sprawdzenia (asystent)',
    id_user          INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_ksef_check (id_shipment_log),
    KEY idx_invoice_ksef_check_day (checked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Faktury i KSeF dnia : wynik dla dokumentu wysyłki [378]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'ksef_day.catchup_days', '7', '7', 'int', 'dni',
    'Faktury i KSeF dnia : ile dni wstecz asystent nadrabia wysyłki, gdy nie był zatwierdzany (zwykle : od poprzedniego dnia roboczego)',
    'Factures et KSeF du jour : jours d''expédition rattrapés au plus quand l''assistant n''a pas été validé (sinon : depuis le jour ouvré précédent)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'ksef_day.catchup_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'ksef_day.pending_days', '1', '1', 'int', 'dni',
    'Faktury i KSeF dnia : po ilu dniach faktura niewysłana do KSeF albo bez odpowiedzi KSeF trafia do wyjaśnienia',
    'Factures et KSeF du jour : jours après lesquels une facture non envoyée à KSeF ou sans réponse de KSeF est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'ksef_day.pending_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'ksef_day.lookback_days', '30', '30', 'int', 'dni',
    'Faktury i KSeF dnia : ile dni wstecz wracają niewyjaśnione problemy (odrzucone, błąd, bez odpowiedzi, bez faktury) z wcześniejszych wysyłek',
    'Factures et KSeF du jour : jours pendant lesquels les problèmes encore ouverts des expéditions antérieures reviennent dans l''assistant'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'ksef_day.lookback_days');

-- Matrice des obligations : ADMINISTRACJA 2 « Proformy i faktury (KSeF) » (daily).
UPDATE n_position_duty SET wizard_code = 'invoices_ksef_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Faktury i KSeF dnia';
