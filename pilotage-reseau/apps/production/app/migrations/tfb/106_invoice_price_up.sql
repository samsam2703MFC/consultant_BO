-- Factures d'achat : une hausse du prix d'une matière au-dessus du prix de base (fiche matière, celui du calcul des
-- recettes) n'est jamais appliquée automatiquement. Décision par matière sur la facture : « accept » (le nouveau prix
-- devient le prix de base) ou « keep » (le prix de base ne change pas, la facture peut être acceptée). Qui, quand et le
-- prix validé sont gardés ; une correspondance ou un facteur modifié annule la décision. Conditionnel et rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_inv_line' AND COLUMN_NAME = 'price_decision') = 0,
    'ALTER TABLE n_inv_line
        ADD COLUMN price_decision VARCHAR(8) NULL COMMENT ''hausse au-dessus du prix de base : accept, keep'' AFTER factor,
        ADD COLUMN price_ok_price DECIMAL(16,8) NULL COMMENT ''prix validé (zł / unité de base)'' AFTER price_decision,
        ADD COLUMN price_ok_by INT NULL AFTER price_ok_price,
        ADD COLUMN price_ok_at DATETIME NULL AFTER price_ok_by',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'supply.invoice_price_up_pct', '0', '0', 'decimal', '%',
    'Faktury zakupowe : podwyżka ceny ponad cenę bazową (kalkulacja receptur) tolerowana bez zatwierdzenia (0 = każda podwyżka do zatwierdzenia)',
    'Factures d''achat : hausse au-dessus du prix de base (calcul des recettes) tolérée sans validation (0 = toute hausse à valider)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'supply.invoice_price_up_pct');
