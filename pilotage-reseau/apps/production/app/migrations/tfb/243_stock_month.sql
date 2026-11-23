-- [403] Asystent « Stany magazynowe na koniec miesiąca » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik
-- produkcji 6.4 « Sporządzanie miesięcznych zestawień stanów magazynowych na koniec miesiąca i przekazywanie ich do Asi »,
-- proposed_wizard = 'Stany magazynowe na koniec miesiąca') : chaque mois, l'état des stocks au dernier jour du mois écoulé,
-- avec les règles des écrans existants (rien de nouveau n'est inventé) :
--   - produits finis : cartons (carton_stock) en stock au début et à la fin du mois, règle du rejestr ruchów
--     (StockLedgerRepository::summary : entrée = date de l'étiquette, sortie = palette expédiée) ; valeur = coût surowców de
--     la karta produktu (final_product.pre_calculated_raw_price_per_carton, règle de FinalProductStockStateService : sans
--     coût, hors somme) au moment de l'instantané — l'ERP n'a pas d'historique de ce coût ;
--   - surowce : règle de Zaopatrzenie (dernière inwentaryzacja n_mp_count + przyjęcia n_po_receipt − zużycie des recettes
--     du carton produit, en poids acheté) bornée à la fin du mois ; prix = raw_material_price_changelog à cette date,
--     sinon prix actuel.
-- L'ERP n'enregistre aucun état de stock daté : l'assistant enregistre un instantané (n_stock_month_statement,
-- n_stock_month_item), refait à la demande tant que l'ensemble n'est pas przekazane ; le PDF et le CSV sont toujours tirés
-- de l'instantané (reproductibles). Signaux : produit sans coût, cartons périmés en stock, produit inactif avec stock ;
-- surowiec en stock négatif, sans inwentaryzacja, sans prix ; écart de valeur début → fin au-delà des deux réglages
-- stock_month.variance_pct (%) et stock_month.variance_min_pln (zł). Décisions par ligne signalée (n_stock_month_review) :
-- « Sprawdzone — stan poprawny » et « Do korekty » (note obligatoire), puis « Przekazane do księgowości » pour l'ensemble
-- (note obligatoire : komu, jak, kiedy) — la remise est faite par l'humain, l'ERP n'envoie aucun e-mail ; « Zakończ » écrit
-- n_wizard_done (code stock_month_reported). Aucun stock ni prix n'est modifié.
--  - n_stock_month_statement / n_stock_month_item : instantané du mois examiné ; n_stock_month_review : décisions ;
--  - réglages n_prod_param stock_month.variance_pct (50) et stock_month.variance_min_pln (1000), bornes dans ParamRules ;
--  - matrice des obligations (237) : la ligne KIEROWNIK_PRODUKCJI « Stany magazynowe na koniec miesiąca » → stock_month_reported ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 3e jour ouvré : la comptabilité clôture le mois) ;
--  - droit du KIEROWNIK_PRODUKCJI seulement : stock_month. Aucun autre droit : Stany i palety (stock_pallets), Zaopatrzenie
--    (supply) sont déjà les siens (203, 236), Ceny surowców (supplier_prices) et Karta produktu (product_cards) viennent de
--    241 et 240 ; Ruchy magazynowe (production) n'est pas donné, son lien n'apparaît qu'à qui a déjà ce droit.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_stock_month_statement (
    month_start      DATE NOT NULL COMMENT 'pierwszy dzień ocenianego miesiąca (stan na jego ostatni dzień)',
    taken_at         DATETIME NOT NULL COMMENT 'chwila zapisu zestawienia',
    taken_by         INT NULL,
    products_value   DECIMAL(14,2) NOT NULL DEFAULT 0,
    materials_value  DECIMAL(14,2) NOT NULL DEFAULT 0,
    products_rows    INT NOT NULL DEFAULT 0,
    materials_rows   INT NOT NULL DEFAULT 0,
    note             VARCHAR(255) NULL COMMENT 'podstawa obliczenia (dzień stanu, progi)',
    PRIMARY KEY (month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stany magazynowe na koniec miesiąca : zapisane zestawienie [403]';

CREATE TABLE IF NOT EXISTS n_stock_month_item (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start    DATE NOT NULL,
    kind           ENUM('product','material') NOT NULL,
    item_id        INT NOT NULL COMMENT 'final_product.id albo raw_material.id (bez klucza obcego)',
    name           VARCHAR(160) NOT NULL,
    unit           VARCHAR(20) NULL COMMENT 'kart. (produkt) albo jednostka bazowa surowca',
    quantity       DECIMAL(14,3) NOT NULL COMMENT 'stan na koniec miesiąca',
    opening        DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'stan na początek miesiąca',
    unit_price     DECIMAL(14,6) NULL COMMENT 'koszt kartonu albo cena jednostki bazowej',
    price_source   VARCHAR(20) NULL COMMENT 'card = karta produktu ; history = historia cen na koniec miesiąca ; current = cena aktualna',
    value          DECIMAL(14,2) NULL COMMENT 'NULL = bez ceny, poza sumą',
    opening_value  DECIMAL(14,2) NULL,
    basis          VARCHAR(20) NOT NULL COMMENT 'ledger (kartony), count (inwentaryzacja), receipts (tylko przyjęcia), none',
    count_date     DATE NULL COMMENT 'ostatnia inwentaryzacja surowca do końca miesiąca',
    flags          VARCHAR(255) NULL COMMENT 'kody sygnałów (przecinek)',
    detail         VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_month_item (month_start, kind, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stany magazynowe na koniec miesiąca : pozycje zestawienia [403]';

CREATE TABLE IF NOT EXISTS n_stock_month_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień ocenianego miesiąca',
    item_key     VARCHAR(80) NOT NULL COMMENT 'product:<id>, material:<id> albo statement (całe zestawienie)',
    decision     ENUM('confirmed','to_correct','handed') NOT NULL COMMENT 'confirmed = sprawdzone — stan poprawny ; to_correct = do korekty ; handed = przekazane do księgowości',
    note         VARCHAR(500) NULL COMMENT 'wymagana',
    flags        VARCHAR(255) NULL COMMENT 'kody sygnałów w chwili zapisu (przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_month_review (month_start, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stany magazynowe na koniec miesiąca : decyzje i przekazanie [403]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'stock_month.variance_pct', '50', '50', 'int', '%',
    'Stany magazynowe na koniec miesiąca : duża zmiana wartości pozycji wobec początku miesiąca od tylu % (razem z progiem w zł)',
    'Stocks de fin de mois : variation importante de la valeur d''une ligne par rapport au début du mois à partir de ce % (avec le seuil en zł)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'stock_month.variance_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'stock_month.variance_min_pln', '1000', '1000', 'int', 'zł',
    'Stany magazynowe na koniec miesiąca : duża zmiana wartości pozycji od tylu zł (razem z progiem w %)',
    'Stocks de fin de mois : variation importante de la valeur d''une ligne à partir de ce montant en zł (avec le seuil en %)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'stock_month.variance_min_pln');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 6.4 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'stock_month_reported', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Stany magazynowe na koniec miesiąca';

-- Tâche du poste (co miesiąc, 3e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'UPLOAD' LIMIT 1),
    'Stany magazynowe na koniec miesiąca : zestawienie (PDF, CSV) → sygnały → przekazane do księgowości (asystent)',
    'Stocks de fin de mois : état (PDF, CSV) → signaux → remis à la comptabilité (assistant)',
    'monthly', 30, 'wd3', '/stock/month-end', 'stock_month_reported',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'stock_month_reported')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/stock/month-end');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'stock_month' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
