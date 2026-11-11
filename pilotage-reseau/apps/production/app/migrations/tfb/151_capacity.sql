-- Moce produkcyjne [234] (makiety 10 i 11) : wykorzystanie maszyn wobec planu tygodnia i ile jeszcze można dodać.
-- Ustawienia maszyny : wydajność realna (TRS, %) — mnoży czas dostępny ; typowy cykl wsadu (s) — gdy maszyna nie ma
-- jeszcze etapów w kartach produkcyjnych ; widoczna na stronie mocy (stoły ręczne domyślnie ukryte — ich limit to ludzie).
-- Mroźnia zapasu : miejsca paletowe (stock.freezer_pallet_places, już jest) + próg ostrzeżenia. Rejouable.

SET @cap_new := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_prod_workstation' AND COLUMN_NAME = 'efficiency_pct') = 0;
SET @sql := IF(@cap_new,
    'ALTER TABLE n_prod_workstation ADD COLUMN efficiency_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT ''wydajność realna (TRS) — % czasu dostępnego'' AFTER capacity_unit,
        ADD COLUMN cycle_s INT NULL COMMENT ''typowy cykl wsadu (s), gdy brak etapów w kartach'' AFTER efficiency_pct,
        ADD COLUMN capacity_view TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''pokazana w Mocach produkcyjnych'' AFTER cycle_s',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Stoły ręczne : limit to ludzie (plan zespołów), nie maszyna — ukryte w mocach (można włączyć w ustawieniach).
UPDATE n_prod_workstation SET capacity_view = 0 WHERE @cap_new AND type = 'manuel';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'stock.freezer_alert_pct', '90', '90', 'decimal', '%',
       'Mroźnia zapasu : ostrzeżenie od tylu % miejsc paletowych (Moce produkcyjne)',
       'Congélateur de stock : alerte à partir de ce % des places palettes (Capacités de production)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'stock.freezer_alert_pct');
