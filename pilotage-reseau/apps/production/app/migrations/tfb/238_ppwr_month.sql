-- [397] Asystent « PPWR i opakowania » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 2.1–2.7 et
-- 2.10, proposed_wizard = 'PPWR i opakowania (kompletność)') : chaque mois, l'état des données PPWR / BDO de l'ERP, avec les
-- règles des écrans existants (rien de nouveau n'est inventé sur ce qui est « complet ») :
--   - opakowania (raw_material.is_part_of_package = 1) utilisés dans la recette d'un produit actif : profil PPWR incomplet
--     (RawMaterialPpwrService::issues — numéro, source du numéro, masse, poziom, rodzaj, matériau dominant, recyclat,
--     recyclabilité, minimalisation, wymiary, cykle) et données BDO absentes (bdo_material_category, packaging_usage_type,
--     comme ShipmentPackagingSourceRepository::getDataIssues) ;
--   - produits actifs (final_product.active = 1) : karton sprzedażowy PPWR incomplet (FinalProductPpwrSalesPackagingService::
--     issueCodes — receptura, opakowanie w recepturze, karton wybrany, PPC) et masse du produit (ProductWeightAuditEvaluator :
--     absente, 0, 1 g douteux) ;
--   - zmiany od ostatniego przeglądu : profil PPWR modifié (raw_material_ppwr_profile.updated_at), karton sprzedażowy modifié
--     (final_product_ppwr_sales_packaging.updated_at), produit créé (final_product.create_timestamp) ;
--   - wysyłki du mois écoulé sans dowód opakowań (shipment_packaging_record absent), « wymaga danych » (status
--     REQUIRES_DATA) ou avec des lignes PPWR incomplètes (shipment_packaging_item.ppwr_calculation_status <> 'COMPLETE') —
--     les règles de Raporty PPWR (ShipmentPpwrReportRepository::getCoverage).
-- Une décision par élément signalé et par mois : « Uzupełnione — sprawdzone » (acceptée seulement si les données sont
-- complètes au moment de l'enregistrement), « Zapytanie do dostawcy wysłane » (opakowania seulement, note obligatoire :
-- quel dostawca, o co), « Nie dotyczy » (note obligatoire, reprise les mois suivants tant que les braki sont les mêmes) ;
-- « Zakończ » écrit n_wizard_done (code ppwr_month_reviewed) et la tâche du mois se valide seule. L'assistant NE MODIFIE
-- AUCUNE donnée PPWR / BDO (liens vers Rejestr PPWR, Kartony sprzedażowe PPWR, Wagi produktów, Dane opakowań BDO,
-- Wysyłki i dokumenty) et n'envoie rien : le texte de la demande au fournisseur est à copier (ou mailto), l'humain l'envoie.
--  - n_ppwr_month_review : une décision par (mois du contrôle, élément) ;
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « PPWR i opakowania (kompletność) » → ppwr_month_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ;
--  - droits du KIEROWNIK_PRODUKCJI seulement : ppwr_month (l'assistant) et ppwr + bdo (les écrans où l'on complète les
--    données : ses obligations 2.4–2.7 sont la saisie PPWR et BDO) ; menu : « Opakowania » s'il a son propre menu.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_ppwr_month_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca kontroli',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : material:<id>, product:<id>, shipment:<id>',
    kind         ENUM('material','product','shipment') NOT NULL,
    source_id    INT NOT NULL COMMENT 'raw_material.id, final_product.id albo shipment_log.id (bez klucza obcego)',
    decision     ENUM('checked','supplier_asked','not_applicable') NOT NULL COMMENT 'checked = uzupełnione — sprawdzone ; supplier_asked = zapytanie do dostawcy wysłane ; not_applicable = nie dotyczy',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla supplier_asked i not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'kody braków w chwili zapisu (przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ppwr_month_review (month_start, item_key),
    KEY idx_ppwr_month_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='PPWR i opakowania : decyzja dla elementu w miesiącu [397]';

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 2.1–2.7 et 2.10 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'ppwr_month_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'PPWR i opakowania (kompletność)';

-- Tâche du poste (co miesiąc, 5e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'UPLOAD' LIMIT 1),
    'PPWR i opakowania : braki danych PPWR i BDO opakowań i produktów, zmiany, dowody wysyłek → decyzja (asystent)',
    'PPWR et emballages : données PPWR et BDO manquantes des emballages et produits, changements, preuves des expéditions → décision (assistant)',
    'monthly', 30, 'wd5', '/ppwr/month', 'ppwr_month_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'ppwr_month_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/ppwr/month');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'ppwr_month' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Écrans où l'on complète les données (Rejestr PPWR, Kartony sprzedażowe PPWR, Raporty PPWR ; Wagi produktów, Dane
-- opakowań BDO) : même poste seulement.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'ppwr' AS page_key UNION ALL SELECT 'bdo') k
WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Menu du poste : « Opakowania » (ouvre le Rejestr PPWR), seulement s'il a son propre menu (comme 236).
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'packaging', '/ppwr/register' FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND EXISTS (SELECT 1 FROM n_position_menu x WHERE x.id_position = p.id);
