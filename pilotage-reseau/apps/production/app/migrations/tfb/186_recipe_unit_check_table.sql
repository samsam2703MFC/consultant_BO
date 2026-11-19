-- [295] Table des décisions « recette au carton » sans la mise à l'échelle de 127.
-- La migration 127 crée cette table ET ramène au carton les recettes des produits test ; en mode cohabitation
-- (COHABITATION=1, base de l'ERP d'origine) 127 est mise de côté (cohabitation_skip.txt), mais l'assistant
-- « Nowy produkt z recepturą » lit cette table. Ici : seulement la table, identique à 127 (sans effet si elle existe).
CREATE TABLE IF NOT EXISTS n_recipe_unit_check (
    id_product INT NOT NULL COMMENT 'final_product.id',
    id_recipe INT NOT NULL COMMENT 'product_recipe.id w chwili decyzji',
    decision ENUM('scaled', 'kept') NOT NULL COMMENT 'scaled = ilości × factor (na karton) ; kept = receptura już na karton',
    factor INT NULL COMMENT 'mnożnik (sztuk w kartonie) przy scaled',
    cost_before DECIMAL(10,2) NULL COMMENT 'koszt surowców / karton przed decyzją',
    decided_by INT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Receptura na 1 karton : decyzje po sprawdzeniu [201]';
