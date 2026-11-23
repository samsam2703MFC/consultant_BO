-- [401] Asystent « Kartoteka surowców » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 1.3
-- « Dodawanie surowców wykorzystywanych w produkcji » et 1.5 « Kategoryzacja surowców i produktów », proposed_wizard =
-- 'Kartoteka surowców (kompletność)') : chaque mois, les fiches des surowce utilisés dans la recette d'un produit actif
-- (flattened_recipe_ingredient → final_product.active = 1 ; les autres sont comptés, pas signalés) et les nowe surowce,
-- avec la fiche surowca de l'ERP (rien de nouveau n'est inventé sur ce qui est « complet ») :
--   - kategoria (raw_material.id_category → category_material), jednostka bazowa (id_unit → unit), cena aktualna
--     (price_per_unit > 0), wielkość opakowania (package_size_in_base_unit > 0), dostawca (id_current_supplier → supplier,
--     is_active) ; wartości odżywcze des surowce spożywcze (hors opakowania : is_part_of_package, catégorie du groupe 1) :
--     toutes vides ou à 0 = brak (l'écran Wartości odżywcze affiche alors des zéros) ;
--   - alergeny : material_allergen_connection ne distingue pas « aucun » de « non saisi » — comptés, jamais signalés ;
--   - nowe surowce : raw_material.id au-delà du repère du dernier « Zakończ » (n_materials_month_mark) ; avant le premier
--     repère, surowce dont la première cena (raw_material_price_changelog) date du mois écoulé ou après.
-- Une décision par surowiec signalé et par mois : « Uzupełnione — sprawdzone » (acceptée seulement si la fiche n'a plus de
-- brak au moment de l'enregistrement ; nouveau surowiec complet : sprawdzone), « Zapytanie do dostawcy wysłane » (note
-- obligatoire ; revient le mois suivant tant que les braki restent), « Nie dotyczy » (note obligatoire, reprise les mois
-- suivants tant que les braki sont les mêmes) ; « Zakończ » écrit n_wizard_done (code materials_month_reviewed) et le
-- repère des nowe surowce ; la tâche du mois se valide seule. L'assistant NE MODIFIE AUCUNE fiche (liens vers Karta
-- surowca, Ceny surowców, Dostawcy surowców, Wartości odżywcze) et n'envoie rien.
--  - n_materials_month_review : une décision par (mois du contrôle, surowiec) ;
--  - n_materials_month_mark : plus grand raw_material.id au « Zakończ » du mois ;
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Kartoteka surowców (kompletność) » → materials_month_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ;
--  - droits du KIEROWNIK_PRODUKCJI seulement : materials_month (l'assistant) et — pour que les écrans où l'on complète
--    s'ouvrent (ses obligations 1.3 à 1.5 sont la saisie des surowce et de leurs prix) — materials (Karta surowca,
--    kategorie, Wartości odżywcze) et supplier_prices (Ceny surowców, Dostawcy surowców).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_materials_month_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca kontroli',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : material:<id>',
    source_id    INT NOT NULL COMMENT 'raw_material.id (bez klucza obcego)',
    decision     ENUM('checked','supplier_asked','not_applicable') NOT NULL COMMENT 'checked = uzupełnione — sprawdzone ; supplier_asked = zapytanie do dostawcy wysłane ; not_applicable = nie dotyczy',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla supplier_asked i not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'kody braków w chwili zapisu (przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_materials_month_review (month_start, item_key),
    KEY idx_materials_month_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kartoteka surowców : decyzja dla surowca w miesiącu [401]';

CREATE TABLE IF NOT EXISTS n_materials_month_mark (
    month_start      DATE NOT NULL COMMENT 'pierwszy dzień miesiąca kontroli',
    max_material_id  INT NOT NULL COMMENT 'największy raw_material.id przy « Zakończ » (nowe surowce = większe id)',
    id_user          INT NULL,
    marked_at        DATETIME NOT NULL,
    PRIMARY KEY (month_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kartoteka surowców : znane surowce przy zakończeniu przeglądu [401]';

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 1.3 et 1.5 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'materials_month_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Kartoteka surowców (kompletność)';

-- Tâche du poste (co miesiąc, 5e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
    'Kartoteka surowców : karty surowców z brakami, nowe surowce → decyzja (asystent)',
    'Fichier des matières : fiches matières incomplètes, nouvelles matières → décision (assistant)',
    'monthly', 30, 'wd5', '/materials/month', 'materials_month_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'materials_month_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/materials/month');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'materials_month' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Écrans où l'on complète (Karta surowca, Wartości odżywcze ; Ceny surowców, Dostawcy surowców) : même poste seulement.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'materials' AS page_key UNION ALL SELECT 'supplier_prices') k
WHERE p.code = 'KIEROWNIK_PRODUKCJI';
