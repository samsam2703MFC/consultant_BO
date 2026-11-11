-- [264] Sortie « marchandise » : sélection rapide au lieu du texte libre (demande du 11/11/2026).
--   Question yes_no avec « picker = products » : réponse « Tak » → catégorie → produit → quantité au pavé numérique
--   (pièces), plusieurs lignes, « z braków » (rebut) par ligne ; « Inne » garde un texte court pour ce qui n'est pas
--   un produit (matière première…). Les lignes sont gardées avec le pointage (n_clock_goods, nom du produit figé) ;
--   n_clock_answer.note en garde le résumé lisible (Czas pracy › Dzień).
-- Rejouable : ALTER conditionnels, CREATE IF NOT EXISTS, UPDATE idempotent.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_question' AND COLUMN_NAME = 'picker') = 0,
    'ALTER TABLE n_clock_question ADD COLUMN picker ENUM(''text'',''products'') NOT NULL DEFAULT ''text'' COMMENT ''yes_no, réponse Tak : texte court ou choix de produits'' AFTER kind',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Le résumé de plusieurs lignes dépasse vite 255 caractères.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_clock_answer' AND COLUMN_NAME = 'note'
        AND CHARACTER_MAXIMUM_LENGTH < 600) = 1,
    'ALTER TABLE n_clock_answer MODIFY COLUMN note VARCHAR(600) NULL COMMENT ''réponse Tak d''''une question yes_no : ce qui est pris (résumé)''',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_clock_goods (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    id_clock         INT NOT NULL COMMENT 'n_prod_clock.id',
    id_question      INT NOT NULL COMMENT 'n_clock_question.id',
    id_final_product INT NULL COMMENT 'final_product.id ; NULL = « Inne » (texte)',
    product          VARCHAR(255) NOT NULL COMMENT 'nom figé au moment du pointage',
    quantity         DECIMAL(8,2) NOT NULL DEFAULT 1 COMMENT 'pièces',
    from_waste       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = pris dans les rebuts (braki)',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_n_clock_goods_clock (id_clock),
    KEY ix_n_clock_goods_product (id_final_product, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La question « marchandise » posée à la sortie [263] passe au choix de produits.
UPDATE n_clock_question SET picker = 'products'
WHERE kind = 'yes_no' AND picker = 'text' AND direction IN ('out', 'both') AND text_pl LIKE '%towar%';
