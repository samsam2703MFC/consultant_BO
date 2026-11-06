-- Karta produktu, krok « Tryb i zapasy » : przycisk liczący stan minimalny i maksymalny ze sprzedaży
-- (WDT bez duplikatów) z ostatnich tygodni — min = 1 tydzień sprzedaży, maks = 3 tygodnie, w pełnych kartonach.

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('stock.suggest_weeks', '6', '6', 'int', 'tyg.', 'Ile ostatnich tygodni sprzedaży liczy przycisk stanów min./maks.', 'Nombre de semaines de ventes pour le bouton de stocks min./max.'),
('stock.suggest_min_weeks', '1', '1', 'decimal', 'tyg.', 'Stan minimalny = tygodnie średniej sprzedaży', 'Stock minimum = semaines de ventes moyennes'),
('stock.suggest_max_weeks', '3', '3', 'decimal', 'tyg.', 'Stan maksymalny = tygodnie średniej sprzedaży', 'Stock maximum = semaines de ventes moyennes');
