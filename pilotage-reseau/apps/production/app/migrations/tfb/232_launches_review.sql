-- [384] Asystent « Nowe produkty » (co miesiąc ; matrice des obligations 224 : Kierownik produkcji 6 « Nowe produkty i
-- karty produktów », proposed_wizard = 'Nowe produkty') : chaque mois, les lancements des launches_review.months derniers
-- mois (date de lancement n_mgr_launch.launch_date, sinon date de création du produit final_product.create_timestamp —
-- même règle que ManagerRepository::launches et ManagerService::newProducts) :
--   - objectifs du module Kierownik produkcji (n_mgr_launch, migration 041 renommée par 069 : target_cost, target_price en
--     € / carton, min_margin_pct, expected_week_shop en cartons / magasin / semaine, decision keep / fix / stop) ;
--   - réel : coût matières / carton et prix pratiqué des cartons vendus depuis le mois du lancement, marge après matières
--     (ProfitabilityService::production, la règle de Rentowność et du rapport du Kierownik produkcji), sinon coût de la
--     fiche produit (final_product.pre_calculated_raw_price_per_carton, comme Marża produktów) et prix standard ;
--     cartons par magasin et par semaine depuis le lancement (ManagerRepository::productShops, lignes WDT) ;
--   - décision déjà prise ou non dans le module (n_mgr_launch.decision).
-- Une décision par lancement loin de ses objectifs (seuils launches_review.*) ou sans décision : Zostaje (note),
-- Korekta (note obligatoire, domaine optionnel : cena, receptura, koszt…), Wycofać (note obligatoire). L'assistant
-- n'écrit PAS n_mgr_launch.decision : sa décision reste dans n_launch_review ; l'écran indique où la reporter (module
-- Kierownik produkcji › Raport miesięczny). « Zakończ » écrit n_wizard_done (code launches_reviewed) et la tâche se
-- valide seule. Lecture seule de l'ERP : aucun produit, aucune recette, aucun prix changé, aucun e-mail ni SMS.
--  - n_launch_review : une décision par (mois du contrôle, produit) ; signaux au moment de l'enregistrement ;
--  - n_prod_param launches_review.* (bornes dans ParamRules) ;
--  - matrice des obligations (224) : l'assistant proposé « Nowe produkty » devient launches_reviewed.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_launch_review (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    month_start  DATE NOT NULL COMMENT 'pierwszy dzień miesiąca przeglądu',
    id_product   INT NOT NULL COMMENT 'final_product.id (bez klucza obcego)',
    launch_date  DATE NULL COMMENT 'data wprowadzenia w chwili zapisu (n_mgr_launch.launch_date albo data utworzenia produktu)',
    decision     ENUM('keep','fix','stop') NOT NULL COMMENT 'keep = zostaje ; fix = korekta ; stop = wycofać',
    fix_area     ENUM('price','recipe','cost','packaging','sales','other') NULL COMMENT 'korekta : czego dotyczy (opcjonalnie)',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla korekty i wycofania',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_launch_review (month_start, id_product),
    KEY idx_launch_review_product (id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Nowe produkty : decyzja dla wprowadzenia w miesiącu [384]';

-- Réglages : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.months', '3', '3', 'int', 'mies.',
    'Nowe produkty : z ilu ostatnich miesięcy wprowadzenia są przeglądane',
    'Nouveaux produits : nombre de mois de lancements examinés'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.months');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.cost_over_pct', '10', '10', 'int', '%',
    'Nowe produkty : o ile % koszt surowców / karton może przekroczyć koszt docelowy, zanim produkt trafi do wyjaśnienia',
    'Nouveaux produits : dépassement du coût cible (en %) au-delà duquel le lancement est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.cost_over_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.price_under_pct', '5', '5', 'int', '%',
    'Nowe produkty : o ile % cena / karton może być niższa od ceny docelowej, zanim produkt trafi do wyjaśnienia',
    'Nouveaux produits : écart sous le prix cible (en %) au-delà duquel le lancement est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.price_under_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.margin_gap_pts', '0', '0', 'int', 'pkt',
    'Nowe produkty : o ile punktów marża może być niższa od marży minimalnej (0 = każda marża poniżej minimum)',
    'Nouveaux produits : points de marge tolérés sous la marge minimale (0 = toute marge sous le minimum)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.margin_gap_pts');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.volume_under_pct', '30', '30', 'int', '%',
    'Nowe produkty : o ile % sprzedaż na sklep / tydzień może być niższa od oczekiwanej, zanim produkt trafi do wyjaśnienia',
    'Nouveaux produits : écart sous les ventes attendues par magasin et par semaine (en %) au-delà duquel le lancement est à expliquer'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.volume_under_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'launches_review.min_weeks', '4', '4', 'int', 'tyg.',
    'Nowe produkty : po ilu tygodniach od wprowadzenia sprawdzana jest sprzedaż (wcześniej : za wcześnie)',
    'Nouveaux produits : semaines après le lancement avant de juger les ventes (avant : trop tôt)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'launches_review.min_weeks');

-- Matrice des obligations : KIEROWNIK_PRODUKCJI 6 (monthly).
UPDATE n_position_duty SET wizard_code = 'launches_reviewed', proposed_wizard = NULL WHERE proposed_wizard = 'Nowe produkty';
