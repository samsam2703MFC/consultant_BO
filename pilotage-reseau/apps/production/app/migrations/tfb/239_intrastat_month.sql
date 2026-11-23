-- [398] Asystent « Intrastat miesiąca » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 6.5
-- « Przygotowywanie oraz prowadzenie dokumentacji Intrastat dla firm Samloc, Slfood sp. z o.o. oraz Atelier »,
-- proposed_wizard = 'Intrastat miesiąca') : chaque mois, les données Intrastat du mois écoulé, à partir de ce que l'ERP sait :
--   - wywóz : WDT (document_wdt non remplacées — n_wdt_superseded —, mois = date de wysyłka, sinon date d'établissement ;
--     lignes document_wdt_product : valeur = quantité × prix carton dans la monnaie du document, masse nette =
--     weight_carton_total sinon final_product.weight × ppc × cartons) ; société = vendeur du document (consignor_nip /
--     consignor_name : l'ERP n'en connaît qu'une, erp_company_data, les trois sociétés de l'obligation ne sont pas
--     distinguées au-delà de ce NIP) ; partenaire = VAT UE du destinataire (consignee_nip, règle KSeF de la WDT) ;
--   - przywóz : factures d'achat (n_inv, date d'émission) d'un vendeur au VAT UE hors Pologne (n_inv.seller_nip sinon
--     supplier.nip), lignes rapprochées d'un surowiec (n_inv_line.id_raw_material, quantité × facteur en unité de base :
--     g, kg ; autre unité → masse par unité saisie ici) ; société = celle de l'ERP (n_inv ne porte pas le NIP acheteur) ;
--   - codes CN (8 chiffres) saisis ici par produit / surowiec : n_intrastat_code (l'ERP n'en avait aucun) ;
--   - kursy : saisis ici par mois et devise (n_intrastat_rate) — la règle de conversion est celle de la déclaration,
--     l'ERP ne l'invente pas ;
--   - seuils et valeurs par défaut (rodzaj transakcji, warunki dostawy, rodzaj transportu) : réglages n_prod_param
--     intrastat.* — seuils à 0 = « nie ustawiono » (aucun signal de seuil), textes vides = non renseignés : les valeurs
--     légales changent chaque année et ne sont pas écrites ici.
-- Une décision par signal et par mois (n_intrastat_review) : « Uzupełnione » (acceptée seulement si le signal a disparu),
-- « Nie dotyczy » (note), et pour la déclaration d'une société / d'un sens : « Zgłoszone w PUESC » (numéro de référence en
-- note) ou « Poniżej progu — bez zgłoszenia » (note) ; « Zakończ » écrit n_wizard_done (code intrastat_month_reviewed).
-- Sortie : tableau par société et par sens + CSV à recopier dans PUESC ; aucun XML officiel, aucun appel extérieur, aucun envoi.
--  - matrice des obligations (237) : la ligne KIEROWNIK_PRODUKCJI « Intrastat miesiąca » → intrastat_month_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ; droit « intrastat » pour ce poste seulement.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_intrastat_code (
    item_kind    ENUM('product','material') NOT NULL COMMENT 'product = final_product ; material = raw_material',
    item_id      INT NOT NULL COMMENT 'final_product.id albo raw_material.id (bez klucza obcego)',
    cn_code      CHAR(8) NULL COMMENT 'kod CN, 8 cyfr',
    unit_mass_kg DECIMAL(14,6) NULL COMMENT 'surowiec w jednostce innej niż g / kg : masa netto (kg) jednej jednostki bazowej',
    updated_by   INT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_kind, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Intrastat : kod CN produktu / surowca [398]';

CREATE TABLE IF NOT EXISTS n_intrastat_rate (
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca deklaracji',
    currency     CHAR(3) NOT NULL,
    rate         DECIMAL(12,6) NOT NULL COMMENT 'PLN za 1 jednostkę waluty (wpisany przez użytkownika)',
    updated_by   INT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (month_start, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Intrastat : kurs waluty miesiąca [398]';

CREATE TABLE IF NOT EXISTS n_intrastat_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca deklaracji',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : product:<id>, material:<id>, partner:<id_customer>, invoice:<id>, rate:<waluta>, decl:<nip>:<dispatch|arrival>',
    kind         ENUM('product','material','partner','invoice','rate','declaration') NOT NULL,
    decision     ENUM('completed','not_applicable','declared','below_threshold') NOT NULL COMMENT 'completed = uzupełnione ; not_applicable = nie dotyczy ; declared = zgłoszone w PUESC ; below_threshold = poniżej progu — bez zgłoszenia',
    note         VARCHAR(500) NULL COMMENT 'wymagana poza completed (declared : numer referencyjny PUESC)',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_intrastat_review (month_start, item_key),
    KEY idx_intrastat_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Intrastat miesiąca : decyzja dla sygnału w miesiącu [398]';

-- Réglages : Ustawienia › Parametry (bornes des seuils dans ParamRules). Aucune valeur légale écrite ici.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'intrastat.threshold_dispatch_kpln', '0', '0', 'decimal', 'tys. zł',
    'Intrastat : próg wywozu w roku (tys. zł) z obowiązujących przepisów ; 0 = nie ustawiono (brak sygnału progu)',
    'Intrastat : seuil annuel des expéditions (milliers de zł) selon la réglementation en vigueur ; 0 = non défini (pas de signal de seuil)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'intrastat.threshold_dispatch_kpln');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'intrastat.threshold_arrival_kpln', '0', '0', 'decimal', 'tys. zł',
    'Intrastat : próg przywozu w roku (tys. zł) z obowiązujących przepisów ; 0 = nie ustawiono (brak sygnału progu)',
    'Intrastat : seuil annuel des introductions (milliers de zł) selon la réglementation en vigueur ; 0 = non défini (pas de signal de seuil)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'intrastat.threshold_arrival_kpln');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'intrastat.transaction_nature', '', '', 'string', NULL,
    'Intrastat : domyślny rodzaj transakcji (kod do pliku CSV) ; pusty albo « - » = nie ustawiono',
    'Intrastat : nature de transaction par défaut (code du fichier CSV) ; vide ou « - » = non défini'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'intrastat.transaction_nature');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'intrastat.delivery_terms', '', '', 'string', NULL,
    'Intrastat : domyślne warunki dostawy (kod do pliku CSV) ; pusty albo « - » = nie ustawiono',
    'Intrastat : conditions de livraison par défaut (code du fichier CSV) ; vide ou « - » = non défini'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'intrastat.delivery_terms');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'intrastat.transport_mode', '', '', 'string', NULL,
    'Intrastat : domyślny rodzaj transportu (kod do pliku CSV) ; pusty albo « - » = nie ustawiono',
    'Intrastat : mode de transport par défaut (code du fichier CSV) ; vide ou « - » = non défini'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'intrastat.transport_mode');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 6.5 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'intrastat_month_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Intrastat miesiąca';

-- Tâche du poste (co miesiąc, 5e jour ouvré — échéance de travail, à ajuster dans Zadania stanowisk) : ne double pas une tâche existante.
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'ADMIN' LIMIT 1),
    'Intrastat miesiąca : wywóz (WDT) i przywóz (faktury dostawców UE) poprzedniego miesiąca, kody CN, masa, kursy → CSV do PUESC, decyzja (asystent)',
    'Intrastat du mois : expéditions (WDT) et introductions (factures des fournisseurs UE) du mois précédent, codes NC, masse, taux → CSV pour PUESC, décision (assistant)',
    'monthly', 45, 'wd5', '/intrastat/month', 'intrastat_month_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'intrastat_month_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/intrastat/month');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout). Pas d'entrée de menu : aucun élément
-- du rail ne correspond ; la tâche du mois ouvre l'assistant.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'intrastat' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
