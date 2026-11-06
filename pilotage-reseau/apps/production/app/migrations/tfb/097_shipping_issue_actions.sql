-- Audit 2, lot E (P7) : décisions d'écart exécutables. « Dosłanie » / « Ponowna dostawa » ouvrent la domówienie
-- pré-remplie et la relient à l'écart (id_order : l'écart se clôt quand cette commande a sa WDT) ; « Przekazanie do
-- reklamacji » crée la non-conformité client (id_nc). Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_shipping_issue' AND COLUMN_NAME = 'id_order') = 0,
    'ALTER TABLE n_shipping_issue
        ADD COLUMN id_order INT NULL AFTER note,
        ADD COLUMN id_nc INT NULL AFTER id_order,
        ADD KEY idx_shipping_issue_order (id_order),
        ADD KEY idx_shipping_issue_nc (id_nc)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
