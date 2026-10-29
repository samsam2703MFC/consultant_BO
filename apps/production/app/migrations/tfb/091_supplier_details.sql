-- Audit 2, lot C (assistant Fournisseur) : NIP du fournisseur (rapprochement des factures KSeF, n_inv_supplier),
-- jours de livraison habituels (masque lundi = 1 … dimanche = 64, NULL = inconnus) et langue des commandes envoyées
-- par e-mail (NULL = polonais). Saisis à la création ; utilisés par les commandes fournisseurs (date de livraison
-- proposée au prochain jour habituel) au lieu de la veille de la semaine et du « pl » en dur.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier' AND COLUMN_NAME = 'nip') = 0,
    'ALTER TABLE supplier
        ADD COLUMN nip VARCHAR(20) NULL AFTER email,
        ADD COLUMN delivery_weekdays TINYINT UNSIGNED NULL AFTER nip,
        ADD COLUMN order_lang CHAR(2) NULL AFTER delivery_weekdays',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
