-- Lot 2 « porządek zadań stanowisk » [228] : zadania, które są krokami istniejących kreatorów, łączą się z zadaniem kreatora
-- (jedno zadanie = jeden kreator). Strony tych kroków zostają pod ręką : kolumna extra_links (« Też w tym zadaniu »).
-- Kierownik produkcji : 21 → 13 zadań. Zadania połączone = nieaktywne (historia wystąpień zostaje). Dopasowanie po
-- stanowisku, stronie i częstotliwości (nie po id), tylko gdy zadanie docelowe istnieje i jest aktywne ; rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'extra_links') = 0,
    'ALTER TABLE n_position_task ADD COLUMN extra_links VARCHAR(600) NULL COMMENT ''inne strony zadania (JSON : lista ścieżek ERP) — « Też w tym zadaniu »'' AFTER link_path',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1. Zakupy tygodnia (pon., kreator) ← oferty cenowe (wt.) + dostawcy pozycji (śr.).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Zakupy tygodnia : co kupić → komu → wyślij, oferty dostawców, pozycje z mniej niż 3 dostawcami',
    t.title_fr = 'Achats de la semaine : quoi acheter → à qui → envoyer, offres reçues, articles à moins de 3 fournisseurs',
    t.minutes = 85, t.extra_links = '["/purchasing/offers","/workshop/buy#tab=suppliers"]'
WHERE t.active = 1 AND t.frequency = 'weekly' AND t.link_path = '/workshop/buy' AND t.extra_links IS NULL
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_task WHERE active = 1 AND frequency = 'weekly' AND link_path IN ('/purchasing/offers', '/workshop/buy#tab=suppliers')) s WHERE s.id_position = t.id_position);

UPDATE n_position_task s JOIN n_position p ON p.id = s.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
JOIN n_position_task t ON t.id_position = s.id_position AND t.active = 1 AND t.link_path = '/workshop/buy' AND t.extra_links LIKE '%/purchasing/offers%'
SET s.active = 0
WHERE s.active = 1 AND s.frequency = 'weekly' AND s.link_path IN ('/purchasing/offers', '/workshop/buy#tab=suppliers');

-- 2. Faktury tygodnia (śr., kreator faktur) ← wgrać faktury (wt.) + nowe ceny surowców z faktur i cenników (pt.).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Faktury tygodnia : wgraj (PDF / KSeF), sprawdź pozycje, nowe ceny surowców (kreator faktur)',
    t.title_fr = 'Factures de la semaine : importer (PDF / KSeF), vérifier les lignes, nouveaux prix matières (assistant factures)',
    t.minutes = 60, t.extra_links = '["/workshop/supply#tab=invoices","/materials/prices"]'
WHERE t.active = 1 AND t.frequency = 'weekly' AND t.link_path = '/workshop/supply#tab=invoices&inv=wizard' AND t.extra_links IS NULL
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_task WHERE active = 1 AND frequency = 'weekly' AND link_path IN ('/workshop/supply#tab=invoices', '/materials/prices')) s WHERE s.id_position = t.id_position);

UPDATE n_position_task s JOIN n_position p ON p.id = s.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
JOIN n_position_task t ON t.id_position = s.id_position AND t.active = 1 AND t.link_path = '/workshop/supply#tab=invoices&inv=wizard' AND t.extra_links LIKE '%/materials/prices%'
SET s.active = 0
WHERE s.active = 1 AND s.frequency = 'weekly' AND s.link_path IN ('/workshop/supply#tab=invoices', '/materials/prices');

-- 3. Koszty i marże (śr., kreator cen krok 1) ← przegląd kosztu receptur + przeliczyć marże kategorii (co miesiąc).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Koszty i marże : przelicz po fakturach, koszt receptur, marże kategorii (Kreator cen, krok 1)',
    t.title_fr = 'Coûts et marges : recalcul après factures, coût des recettes, marges des catégories (assistant prix, étape 1)',
    t.extra_links = '["/finances/category-margin","/purchasing"]'
WHERE t.active = 1 AND t.frequency = 'weekly' AND t.link_path = '/finances/price-wizard' AND t.extra_links IS NULL
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_task WHERE active = 1 AND frequency = 'monthly'
      AND (link_path = '/finances/category-margin' OR (link_path = '/purchasing' AND title_pl LIKE 'Przegląd kosztu receptur%'))) s WHERE s.id_position = t.id_position);

UPDATE n_position_task s JOIN n_position p ON p.id = s.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
JOIN n_position_task t ON t.id_position = s.id_position AND t.active = 1 AND t.link_path = '/finances/price-wizard' AND t.extra_links LIKE '%/finances/category-margin%'
SET s.active = 0
WHERE s.active = 1 AND s.frequency = 'monthly' AND (s.link_path = '/finances/category-margin' OR (s.link_path = '/purchasing' AND s.title_pl LIKE 'Przegląd kosztu receptur%'));

-- 4. Nowe ceny (czw., kreator cen krok 2) ← sprawdź publikację cen (pt., krok 3).
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Nowe ceny i publikacja : marża bazowa + współczynnik, publikacja od daty, strona franczyzy i e-maile (Kreator cen, krok 2–3)',
    t.title_fr = 'Nouveaux prix et publication : marge de base + coefficient, publication datée, site franchise et e-mails (assistant prix, étapes 2–3)',
    t.minutes = 40, t.extra_links = '["/finances/price-wizard#step=3"]'
WHERE t.active = 1 AND t.frequency = 'weekly' AND t.link_path = '/finances/price-wizard#step=2' AND t.extra_links IS NULL
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_task WHERE active = 1 AND frequency = 'weekly' AND link_path = '/finances/price-wizard#step=3') s WHERE s.id_position = t.id_position);

UPDATE n_position_task s JOIN n_position p ON p.id = s.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
JOIN n_position_task t ON t.id_position = s.id_position AND t.active = 1 AND t.link_path = '/finances/price-wizard#step=2' AND t.extra_links LIKE '%step=3%'
SET s.active = 0
WHERE s.active = 1 AND s.frequency = 'weekly' AND s.link_path = '/finances/price-wizard#step=3';

-- 5. Co kwartał : ocena dostawców + zapytanie ofertowe na kluczowe surowce = jedno zadanie.
UPDATE n_position_task t JOIN n_position p ON p.id = t.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
SET t.title_pl = 'Ocena dostawców i zapytanie ofertowe na kluczowe surowce (ceny, terminy, niezgodności)',
    t.title_fr = 'Évaluation des fournisseurs et appel d''offres sur les matières clés (prix, délais, non-conformités)',
    t.extra_links = '["/purchasing/nc"]'
WHERE t.active = 1 AND t.frequency = 'quarterly' AND t.link_path = '/purchasing/tenders' AND t.extra_links IS NULL
  AND EXISTS (SELECT 1 FROM (SELECT id_position FROM n_position_task WHERE active = 1 AND frequency = 'quarterly' AND link_path = '/purchasing/nc') s WHERE s.id_position = t.id_position);

UPDATE n_position_task s JOIN n_position p ON p.id = s.id_position AND p.code = 'KIEROWNIK_PRODUKCJI'
JOIN n_position_task t ON t.id_position = s.id_position AND t.active = 1 AND t.link_path = '/purchasing/tenders' AND t.extra_links LIKE '%/purchasing/nc%'
SET s.active = 0
WHERE s.active = 1 AND s.frequency = 'quarterly' AND s.link_path = '/purchasing/nc';
