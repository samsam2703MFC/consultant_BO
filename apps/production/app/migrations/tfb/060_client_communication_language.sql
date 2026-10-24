-- Język komunikacji klienta (dokumenty PDF, maile, SMS logistyki) : pl, fr, nl, en.
-- Dotąd jeden język dla wszystkich klientów (parametr mail.client_default_language) i default_document_lang
-- (1 = pl, 2 = fr) używany tylko w cenniku. Start : fr dla klientów z Belgii albo z default_document_lang = 2,
-- pl dla pozostałych ; zmiana na karcie klienta.

-- MySQL 8 : bez ADD COLUMN IF NOT EXISTS (krok warunkowy).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = 'communication_lang');
SET @sql := IF(@col = 0, 'ALTER TABLE customer ADD COLUMN communication_lang CHAR(2) NOT NULL DEFAULT ''fr'' COMMENT ''pl, fr, nl, en''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE customer
SET communication_lang = CASE
    WHEN default_document_lang = 2 THEN 'fr'
    WHEN id_country = 2 OR fac_country LIKE 'Belg%' THEN 'fr'
    ELSE 'pl'
END;
