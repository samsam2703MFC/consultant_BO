-- Prognoza 13 tygodni [222] : « pieniądze na produkcję » = tylko wydatki (surowce i opakowania, płace i media działów produkcji,
-- wspólne działy produkcji) — bez amortyzacji, kosztów stałych (Finanse) i działu Administracja. Opisy kolumny i parametru.

ALTER TABLE n_forecast_run MODIFY COLUMN production_cost DECIMAL(12,2) NULL
    COMMENT 'pieniądze na produkcję 13 tygodni (zł) : surowce, płace i media działów produkcji ; bez amortyzacji, kosztów stałych, Administracji, logistyki';

UPDATE n_prod_param
SET description_pl = 'Prognoza : wydatki na karton (płace i media działów produkcji) ze średniej tylu ostatnich miesięcy kosztowych (zamknięte najpierw)',
    description_fr = 'Prévision : sorties de caisse par carton (salaires et médias des départements de production) en moyenne sur autant de derniers mois de coûts (clôturés d''abord)'
WHERE code = 'forecast.cost_months';
