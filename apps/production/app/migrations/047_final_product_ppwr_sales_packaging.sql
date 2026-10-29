-- PPWR configuration of the sold unit: in this application one carton is one
-- sales unit. The selected outer material is deliberately stored on the product,
-- not inferred only from the flattened recipe.

CREATE TABLE final_product_ppwr_sales_packaging (
    id INT NOT NULL AUTO_INCREMENT,
    id_final_product INT NOT NULL,
    id_outer_raw_material INT NULL,
    sales_unit_name VARCHAR(100) NOT NULL DEFAULT 'Karton',
    verification_status ENUM('DRAFT', 'REQUIRES_DATA', 'COMPLETE') NOT NULL DEFAULT 'DRAFT',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_final_product_ppwr_sales_packaging_product (id_final_product),
    KEY idx_final_product_ppwr_sales_packaging_outer (id_outer_raw_material),
    CONSTRAINT fk_final_product_ppwr_sales_packaging_product
        FOREIGN KEY (id_final_product) REFERENCES final_product (id),
    CONSTRAINT fk_final_product_ppwr_sales_packaging_outer
        FOREIGN KEY (id_outer_raw_material) REFERENCES raw_material (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
