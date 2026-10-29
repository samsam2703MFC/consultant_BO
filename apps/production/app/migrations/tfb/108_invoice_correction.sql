-- Factures d'achat de correction (KSeF RodzajFaktury KOR / KOR_ZAL / KOR_ROZ, « faktura korygująca » en PDF, total négatif) :
-- leurs lignes « avant correction » ne sont pas des achats. Une facture de correction ne change ni le prix de base des
-- matières, ni l'historique des prix, ni le stock ; elle s'accepte après confirmation. Les factures déjà importées sont
-- marquées depuis le fichier conservé. Conditionnel et rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv' AND COLUMN_NAME = 'is_correction') = 0,
    'ALTER TABLE n_inv ADD COLUMN is_correction TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''facture de correction (KOR) : ni prix, ni stock'' AFTER total_gross',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE n_inv i
LEFT JOIN n_inv_file f ON f.id_inv = i.id
SET i.is_correction = 1
WHERE i.is_correction = 0
  AND (i.total_net < 0
       OR (f.mime LIKE '%xml%' AND (f.content LIKE '%RodzajFaktury>KOR<%' OR f.content LIKE '%RodzajFaktury>KOR\_ZAL<%' OR f.content LIKE '%RodzajFaktury>KOR\_ROZ<%'))
       OR f.text LIKE '%faktura korygująca%' OR f.text LIKE '%FAKTURA KORYGUJĄCA%');
