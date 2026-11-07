-- Audit 2, lot D (P14, assistant « Zamknięcie miesiąca ») : kartony działu pobrane z WDT (sprzedaż miesiąca) mają
-- własne źródło 'WDT' obok 'AUTO' (z zamówień) i 'MANUAL'. Dotąd enum nie znał 'WDT' i zapis z WDT zostawał jako
-- 'AUTO' — nie dało się odróżnić kartonów sprzedanych od zamówionych. Warunkowe i powtarzalne (MySQL 8 / MariaDB).

-- Tylko gdy tabela istnieje (bazy testowe modułu jej nie mają) i enum nie zna jeszcze 'WDT'.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cost_month_section'
        AND COLUMN_NAME = 'source' AND COLUMN_TYPE NOT LIKE '%''WDT''%') = 1,
    'ALTER TABLE cost_month_section MODIFY COLUMN source ENUM(''AUTO'',''MANUAL'',''WDT'') NOT NULL DEFAULT ''AUTO''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
