-- Audit 2, lot D (P12, assistant « Przyjęcie dostawy ») : lot et date limite (DLC) saisis une fois à la réception de
-- chaque ligne de commande fournisseur ; la tablette les propose ensuite pour les parties (FEFO : DLC la plus proche
-- d'abord). id_nc = non-conformité fournisseur créée depuis l'assistant pour cette réception (problème signalé).
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_po_receipt' AND COLUMN_NAME = 'lot_number') = 0,
    'ALTER TABLE n_po_receipt
        ADD COLUMN lot_number VARCHAR(64) NULL AFTER unit_price,
        ADD COLUMN best_before DATE NULL AFTER lot_number,
        ADD COLUMN id_nc INT NULL AFTER best_before,
        ADD KEY idx_n_po_receipt_lot (lot_number),
        ADD KEY idx_n_po_receipt_best_before (best_before)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
