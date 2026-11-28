-- [400] Asystent « Receptury i dane produktów » (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji 1.2
-- « Tworzenie i aktualizowanie receptur produktów » et 1.6 « Aktualizacja danych produktowych oraz kontrola poprawności »,
-- proposed_wizard = 'Receptury i dane produktów') : chaque mois, les produits actifs (final_product.active = 1) dont la
-- recette ou les données sont incomplètes, avec les règles des écrans existants (rien de nouveau n'est inventé) :
--   - receptura : absente (id_recipe NULL — liste et PDF « Produkty bez receptury »), introuvable (product_recipe), sans
--     składnik (product_recipe_connection), coût du carton nul (pre_calculated_raw_price_per_carton), surowce de la recette
--     aplatie sans prix (raw_material.price_per_unit), « na 1 sztukę » encore à décider (Receptura produktu = 1 karton [201],
--     RecipeUnitCheckRepository::candidates — n_recipe_unit_check) ;
--   - dane produktu : masse (ProductWeightAuditEvaluator), sztuk w kartonie et palette (ProductLogistics : cpl, lpp, cpp
--     exigés, cpp ≤ cpl × lpp), przydatność (ProductLogistics::SHELF_LIFE), kategoria et dział produkcyjny (formulaire produit).
-- Une décision par produit signalé et par mois : « Uzupełnione — sprawdzone » (acceptée seulement si le produit n'a plus de
-- brak au moment de l'enregistrement), « Przekazane do uzupełnienia » (note obligatoire : komu, do kiedy ; revient le mois
-- suivant tant que les braki restent), « Nie dotyczy » (note obligatoire, reprise les mois suivants tant que les braki sont
-- les mêmes) ; « Zakończ » écrit n_wizard_done (code recipes_month_reviewed) et la tâche du mois se valide seule.
-- L'assistant NE MODIFIE AUCUNE recette ni fiche produit (liens vers Karta produktu, Edycja receptury, Wagi produktów,
-- Receptury do sprawdzenia « 1 karton »).
--  - n_recipes_month_review : une décision par (mois du contrôle, produit) ;
--  - matrice des obligations (237) : les lignes KIEROWNIK_PRODUKCJI « Receptury i dane produktów » → recipes_month_reviewed ;
--  - tâche du poste KIEROWNIK_PRODUKCJI (co miesiąc, 5e jour ouvré) ;
--  - droits du KIEROWNIK_PRODUKCJI seulement : recipes_month (l'assistant) et — pour que les écrans où l'on complète
--    s'ouvrent (ses obligations 1.2 et 1.6 sont la saisie des recettes et des fiches) — products (Receptury, /products/…)
--    et product_cards (Karta produktu, /workshop/products) ; Wagi produktów (bdo) lui est déjà donné par la migration 238.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_recipes_month_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca kontroli',
    item_key     VARCHAR(80) NOT NULL COMMENT 'element : product:<id>',
    source_id    INT NOT NULL COMMENT 'final_product.id (bez klucza obcego)',
    decision     ENUM('checked','assigned','not_applicable') NOT NULL COMMENT 'checked = uzupełnione — sprawdzone ; assigned = przekazane do uzupełnienia ; not_applicable = nie dotyczy',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla assigned i not_applicable',
    flags        VARCHAR(255) NULL COMMENT 'kody braków w chwili zapisu (przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recipes_month_review (month_start, item_key),
    KEY idx_recipes_month_review_item (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Receptury i dane produktów : decyzja dla produktu w miesiącu [400]';

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 1.2 et 1.6 (monthly).
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position
SET d.wizard_code = 'recipes_month_reviewed', d.proposed_wizard = NULL
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND d.proposed_wizard = 'Receptury i dane produktów';

-- Tâche du poste (co miesiąc, 5e jour ouvré) : ne double pas une tâche existante (même code ou même lien actif).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, minutes, month_day, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA' LIMIT 1),
    'Receptury i dane produktów : produkty bez receptury, receptury i dane z brakami → decyzja (asystent)',
    'Recettes et données produits : produits sans recette, recettes et données incomplètes → décision (assistant)',
    'monthly', 30, 'wd5', '/recipes/month', 'recipes_month_reviewed',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'recipes_month_reviewed')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/recipes/month');

-- Droit de l'assistant : Kierownik produkcji seulement (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'recipes_month' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

-- Écrans où l'on complète (Receptury, Karta produktu) : même poste seulement.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'products' AS page_key UNION ALL SELECT 'product_cards') k
WHERE p.code = 'KIEROWNIK_PRODUKCJI';
