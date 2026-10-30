-- Prognoza 13 tygodni [224] : samochody w kosztach stałych (kategoria LOGISTICS : « Auto - 1 », « Drugi Auto », « Passat »,
-- « Toyata ») to leasing (decyzja właściciela) — do nazwy dopisane « (leasing) » we wszystkich miesiącach, kwoty bez zmian.
-- Prognoza liczy je wtedy jako inwestycje (ForecastService::isInvestment), poza « pieniędzmi na produkcję ». « Skopiuj z
-- poprzedniego » przenosi nową nazwę na kolejne miesiące. Rejouable : wiersze z « leasing » w nazwie nie są ruszane.

UPDATE cost_month_fixed_cost
SET name = CONCAT(TRIM(name), ' (leasing)')
WHERE category = 'LOGISTICS'
  AND LOWER(TRIM(name)) IN ('auto - 1', 'drugi auto', 'passat', 'toyata')
  AND LOWER(name) NOT LIKE '%leasing%';
