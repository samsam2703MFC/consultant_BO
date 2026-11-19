-- [351] Ścieżka cen (makieta 66 B) : jedna ścieżka w 4 krokach, chaque jeudi — 1. ceny z faktur KSeF (asystent faktur),
--   2. sprawdź ceny katalogowe (coût matières d'aujourd'hui vs coût au dernier tarif, seuil ±3 % : hausse ET baisse),
--   3. zatwierdź zmianę cennika (date, prix clients — remises conservées), 4. e-mail aux clients (file d'envoi existante).
-- Base de coût par produit (n_price_cost_baseline) : coût matières du carton au dernier tarif publié ; initialisée une fois
-- (n_margin_recalc.old_cost_carton si recalculé depuis moins de 60 jours, sinon coût actuel), remise au coût du jour
-- pour les produits publiés (cena standardowa) à chaque publication — les produits décochés gardent leur base.
-- Rejouable : information_schema, CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_price_cost_baseline (
    id_product          INT NOT NULL COMMENT 'final_product.id',
    food_cost           DECIMAL(12,4) NOT NULL COMMENT 'koszt surowców kartonu (PLN) przy ostatnim cenniku',
    set_at              DATETIME NOT NULL COMMENT 'data kosztu bazowego (ceny surowców z tej chwili) — przyczyny : zmiany cen od tej daty',
    id_publication      INT NULL COMMENT 'publikacja, która ustawiła bazę ; NULL = inicjalizacja',
    prev_food_cost      DECIMAL(12,4) NULL COMMENT 'baza przed publikacją (przywracana przy anulowaniu)',
    prev_set_at         DATETIME NULL,
    prev_id_publication INT NULL,
    PRIMARY KEY (id_product),
    KEY idx_price_cost_baseline_pub (id_publication)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Ścieżka cen : koszt surowców przy ostatnim cenniku [351]';

-- Kolumny « prev_* » dodane, gdy tabela istniała już bez nich.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_price_cost_baseline' AND COLUMN_NAME = 'prev_food_cost');
SET @sql := IF(@c = 0, 'ALTER TABLE n_price_cost_baseline
        ADD COLUMN prev_food_cost DECIMAL(12,4) NULL COMMENT ''baza przed publikacją (przywracana przy anulowaniu)'',
        ADD COLUMN prev_set_at DATETIME NULL, ADD COLUMN prev_id_publication INT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Parametry reguły (Ustawienia › Pozostałe i « ⚙ Parametry reguły » Kreatora cen).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.cost_up_pct', '3', '3', 'decimal', '%',
    'Ścieżka cen : próg wzrostu kosztu surowców od ostatniego cennika — od niego podwyżka ceny katalogowej',
    'Parcours des prix : seuil de hausse du coût matières depuis le dernier tarif — au-delà, hausse du prix catalogue'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.cost_up_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.cost_down_pct', '3', '3', 'decimal', '%',
    'Ścieżka cen : próg spadku kosztu surowców od ostatniego cennika — od niego obniżka ceny katalogowej',
    'Parcours des prix : seuil de baisse du coût matières depuis le dernier tarif — au-delà, baisse du prix catalogue'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.cost_down_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.client_cut_pct', '8', '8', 'decimal', '%',
    'Ceny : największa obniżka jednej ceny na raz — ceny standardowe (Kreator cen) i ceny klientów (reszta przy następnej zmianie)',
    'Prix : baisse maximale d''un prix en une fois — prix standard (assistant prix) et prix clients (le reste au prochain changement)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.client_cut_pct');

-- Zaokrąglenie : w górę dla podwyżki, w dół dla obniżki (opis bez « w górę »).
UPDATE n_prod_param SET
    description_pl = 'Ceny : zaokrąglenie nowej ceny kartonu do (podwyżka w górę, obniżka w dół — ceny standardowe i ceny klientów)',
    description_fr = 'Prix : arrondi du nouveau prix du carton à (hausse vers le haut, baisse vers le bas — prix standard et prix clients)'
WHERE code = 'prices.client_round_eur';

-- Zadanie stanowiska KIEROWNIK_PRODUKCJI (sesja « Produkcja ») : co czwartek ścieżka cen (zalicza się samo : publikacja
-- zaplanowana w tygodniu albo « Sprawdzone — bez zmian w tym tygodniu » w kroku 2). Nie dubluje : żadne zadanie z kodem
-- price_path_done ani aktywne zadanie stanowiska z tym samym linkiem (dawne « Nowe ceny » 139 jest nieaktywne).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'CENY'),
    'Ścieżka cen (czwartek) : ceny z faktur KSeF → ceny katalogowe ±3 % → cennik → e-mail do klientów (asystent)',
    'Parcours des prix (jeudi) : prix des factures KSeF → prix catalogue ±3 % → tarif → e-mail aux clients (assistant)',
    'weekly', 4, 20, '/finances/price-wizard#step=2', 'price_path_done',
    COALESCE((SELECT MAX(t2.sort_order) FROM n_position_task t2 WHERE t2.id_position = p.id), 0) + 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI'
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.auto_code = 'price_path_done')
  AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.active = 1 AND t.link_path = '/finances/price-wizard#step=2');
