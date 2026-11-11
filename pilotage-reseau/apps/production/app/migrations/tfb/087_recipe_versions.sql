-- Versions de recette « en vigueur depuis » [154] : chaque fois que la composition d'une recette change (édition de la
-- recette, d'un półprodukt ou d'une kompozycja qu'elle contient), l'ERP garde un instantané daté : composition
-- directe (matières, półprodukty, préparations, rendement), liste aplatie des matières [[material_id, quantité]] et
-- coût matières au moment du changement. La rentabilité valorise chaque mois passé avec la version en vigueur à la
-- fin du mois (et non plus la composition actuelle). La version de départ (valid_from NULL = « depuis toujours »)
-- est créée par Bin/mail_dispatch.php et juste avant toute modification.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

CREATE TABLE IF NOT EXISTS n_recipe_version (
    id            INT NOT NULL AUTO_INCREMENT,
    id_recipe     INT NOT NULL,
    version_no    INT NOT NULL,
    valid_from    DATETIME NULL COMMENT 'NULL = version de départ (avant le suivi)',
    source        ENUM('baseline','create','edit','component') NOT NULL DEFAULT 'edit',
    note          VARCHAR(255) NULL,
    content_hash  CHAR(64) NOT NULL,
    composition   LONGTEXT NOT NULL COMMENT 'JSON : rendement, unité, matières, półprodukty, préparations',
    ingredients   LONGTEXT NOT NULL COMMENT 'JSON : [[material_id, quantité]] (flattened_recipe_ingredient)',
    material_cost DECIMAL(12,4) NULL COMMENT 'PLN, prix des matières au moment de la version',
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_recipe_version_no (id_recipe, version_no),
    KEY idx_n_recipe_version_valid (id_recipe, valid_from),
    CONSTRAINT fk_n_recipe_version_recipe FOREIGN KEY (id_recipe) REFERENCES product_recipe (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_recipe_version_user FOREIGN KEY (created_by) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
