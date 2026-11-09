-- Proformy starsze niż 90 dni bez płatności i bez anulowania : zarchiwizowane (decyzja 24.10.2026).
-- Status « archived » : poza listą nieopłaconych, przypomnieniami i należnościami ; nadal można ją oznaczyć jako opłaconą.
-- Daty proform są tekstem « RRRR/MM/DD » : porównanie po zamianie « / » na « - ».
-- Baza testowa API nie ma tabel ERP : wtedy nic.

SET @tbl_exists := (SELECT COUNT(*) FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_proforma');
SET @sql := IF(@tbl_exists = 1,
    'UPDATE document_proforma SET status = ''archived''
     WHERE LOWER(COALESCE(status, ''issued'')) NOT IN (''paid'', ''cancelled'', ''archived'')
       AND REPLACE(established_date, ''/'', ''-'') < DATE_FORMAT(CURDATE() - INTERVAL 90 DAY, ''%Y-%m-%d'')',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
