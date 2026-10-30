-- Prognoza 13 tygodni [220] (Produkcja › Prognoza 13 tygodni, makiety 1–3) : co tydzień te same 13 tygodni do przodu
-- (kartony na produkt : zamówienia, N-1 × trend sieci, gamy sezonowe panelu TF Buddy — tylko produkty SL Food), palety,
-- godziny produkcji i zakupy surowców ; zatwierdzenie w poniedziałek (zadanie Kierownika produkcji, zalicza się samo).
-- Panel : tylko odczyt (GET). Warunkowe i rejouable.

-- Zatwierdzone prognozy : jedna na tydzień (poniedziałek), zdjęcie liczb w chwili zatwierdzenia.
CREATE TABLE IF NOT EXISTS n_forecast_run (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_start DATE NOT NULL COMMENT 'poniedziałek tygodnia zatwierdzenia = pierwszy z 13 tygodni',
    weeks TINYINT UNSIGNED NOT NULL DEFAULT 13,
    trend DECIMAL(6,3) NULL COMMENT 'trend sieci (ostatnie tygodnie ÷ te same tygodnie rok temu)',
    cartons INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'kartony 13 tygodni',
    season_cartons INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'w tym produkty sezonowe (gamy panelu)',
    hours DECIMAL(10,1) NULL COMMENT 'godziny produkcji 13 tygodni',
    purchase_value DECIMAL(12,2) NULL COMMENT 'zakupy surowców do kupienia (zł)',
    payload LONGTEXT NOT NULL COMMENT 'JSON : tygodnie, produkty × tygodnie (kartony, źródło), sezony, zakupy',
    note VARCHAR(255) NULL,
    approved_at DATETIME NOT NULL,
    approved_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forecast_run_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Prognoza 13 tygodni : zatwierdzenia (poniedziałek) [220]';

-- Odczyty panelu (gamy, produkty SL Food w gamach, sprzedaż N-1) : ostatni odczyt używany przez prognozę.
CREATE TABLE IF NOT EXISTS n_forecast_panel_read (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    read_at DATETIME NOT NULL,
    read_by INT NULL COMMENT 'NULL = odczyt automatyczny',
    ok TINYINT(1) NOT NULL DEFAULT 1,
    error VARCHAR(255) NULL,
    payload LONGTEXT NULL COMMENT 'JSON : gamy z datami, produkty gamy (SKU), sprzedaż N-1',
    PRIMARY KEY (id),
    KEY ix_forecast_panel_read_at (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Prognoza 13 tygodni : odczyty API panelu TF Buddy (GET) [220]';

-- Produkt sklepu z gamy sezonowej panelu → nasz produkt (SKU SL Food = final_product.id) : połączenie potwierdzone ręcznie
-- albo pominięcie (inny dostawca) ; bez wpisu prognoza używa podpowiedzi z nazwy (gdy pewna).
CREATE TABLE IF NOT EXISTS n_forecast_panel_link (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    panel_product_id INT UNSIGNED NOT NULL COMMENT 'id produktu sklepu w panelu (gamy)',
    panel_name VARCHAR(255) NULL,
    id_final_product INT NULL COMMENT 'NULL + ignored = 1 : pominięty (nie od nas)',
    ignored TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forecast_panel_link (panel_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Prognoza 13 tygodni : produkt gamy panelu → produkt ERP [220]';

-- Parametry.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.weeks', '13', '13', 'int', 'tyg.',
    'Prognoza produkcji i logistyki : liczba tygodni kroczących (od bieżącego poniedziałku)',
    'Prévision production et logistique : nombre de semaines glissantes (à partir du lundi en cours)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.weeks');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.trend_weeks', '8', '8', 'int', 'tyg.',
    'Prognoza : trend sieci = kartony ostatnich tygodni ÷ te same tygodnie rok temu (tyle tygodni)',
    'Prévision : tendance du réseau = cartons des dernières semaines ÷ mêmes semaines l''an dernier (autant de semaines)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.trend_weeks');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.trend_min', '0.5', '0.5', 'decimal', '×',
    'Prognoza : najniższy trend sieci (ochrona przed brakami danych)',
    'Prévision : tendance du réseau minimale (protection contre les trous de données)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.trend_min');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.trend_max', '2', '2', 'decimal', '×',
    'Prognoza : najwyższy trend sieci',
    'Prévision : tendance du réseau maximale'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.trend_max');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.capacity_hours', '0', '0', 'decimal', 'h',
    'Prognoza : zdolność produkcji na tydzień (h) ; 0 = aktywni pracownicy produkcji × godziny etatu (week.fte_hours)',
    'Prévision : capacité de production par semaine (h) ; 0 = salariés de production actifs × heures du temps plein (week.fte_hours)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.capacity_hours');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.lead_days', '7', '7', 'int', 'dni',
    'Zakupy 13 tygodni : czas dostawy surowca, gdy dostawca nie podał (« zamówić do » = pierwszy tydzień braku − czas dostawy)',
    'Achats 13 semaines : délai de livraison d''une matière quand le fournisseur ne l''a pas donné (« commander avant » = première semaine de manque − délai)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.lead_days');

-- Plan logistyki : 13 tygodni jak prognoza (tylko gdy zostało 12 z migracji 126).
UPDATE n_prod_param SET value = '13', default_value = '13' WHERE code = 'logistics.plan_weeks' AND value = '12';

-- Zadanie co poniedziałek : Kierownik produkcji zatwierdza prognozę (30 min, zalicza się samo po zatwierdzeniu).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, minutes, link_path, auto_code, sort_order, active)
SELECT p.id, (SELECT c.id FROM n_task_category c WHERE c.code = 'PRODUKCJA'),
    'Zatwierdź prognozę 13 tygodni (sezony z panelu, palety, godziny, zakupy)',
    'Valider la prévision 13 semaines (saisons du panel, palettes, heures, achats)',
    'weekly', 1, 30, '/production/forecast', 'forecast_approved', 1, 1
FROM n_position p
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.id_position = p.id AND t.link_path = '/production/forecast');
