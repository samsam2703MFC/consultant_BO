-- Karta produktu : pokrycie (dni), partia minimalna i wielokrotność partii usunięte z kreatora — wartości
-- zapisane wcześniej czyszczone, żeby niewidoczne pola nie wpływały na planowanie (pokrycie wraca do
-- planning.coverage_days_by_group / planning.default_coverage_days, partie bez zaokrąglania).

UPDATE tfb_prod_product_settings SET coverage_days = NULL, batch_min = NULL, batch_multiple = NULL
WHERE coverage_days IS NOT NULL OR batch_min IS NOT NULL OR batch_multiple IS NOT NULL;
