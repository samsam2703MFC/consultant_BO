-- Prognoza 13 tygodni [221] : pieniądze potrzebne na produkcję (zł) — kartony tygodnia × koszt pełny kartonu (ta sama reguła co
-- Marże i Rentowność : surowce i opakowania + wynagrodzenia działu + media i amortyzacja + koszty ogólne, z ostatnich miesięcy
-- kosztowych). Zatwierdzenie zapisuje sumę. Warunkowe i rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_forecast_run' AND COLUMN_NAME = 'production_cost') = 0,
    'ALTER TABLE n_forecast_run ADD COLUMN production_cost DECIMAL(12,2) NULL COMMENT ''pieniądze na produkcję 13 tygodni (zł, koszt pełny bez logistyki)'' AFTER hours',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'forecast.cost_months', '3', '3', 'int', 'mies.',
    'Prognoza : koszt kartonu (wynagrodzenia, media, koszty ogólne) ze średniej tylu ostatnich miesięcy kosztowych (zamknięte najpierw)',
    'Prévision : coût du carton (salaires, médias, frais généraux) en moyenne sur autant de derniers mois de coûts (clôturés d''abord)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'forecast.cost_months');
