-- Receptura produktu = 1 karton (cały produkt sprzedawany) [201]. Rejouable (MySQL 8 / MariaDB).
-- Kreator « Nowy produkt z recepturą » prosił o recepturę « na 1 sztukę », a kalkulacja i marże czytają ją jako 1 karton :
-- koszt surowców kartonu był za niski (÷ sztuk w kartonie). Teraz kreator pyta o karton (albo sztukę × sztuk w kartonie
-- przy zapisie). Receptury już zapisane : lista do sprawdzenia w kreatorze (masa składników ≈ 1 sztuka zamiast kartonu,
-- produkt utworzony w kreatorze) ; decyzja operatora (× sztuk w kartonie albo « już na karton ») zapisana tutaj.
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

-- Produkt testowy wprowadzony : receptura testu = receptura produktu ERP (karta testu pokazywała « Brak receptury »).
UPDATE test_product tp
    JOIN n_dev_product d ON d.id_test_product = tp.id
    JOIN final_product fp ON fp.id = d.id_final_product
SET tp.id_recipe = fp.id_recipe
WHERE d.stage = 'launched' AND tp.id_recipe IS NULL AND fp.id_recipe IS NOT NULL;
