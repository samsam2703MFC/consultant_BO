-- Karta produktu : zdjęcie produktu (slot 0) i 4 etapy produkcji ze zdjęciem i opisem (sloty 1–4), pokazywane na
-- tablecie produkcji (zakładka Receptury). Obraz zmniejszony w przeglądarce (JPEG / PNG / WebP, najwyżej 3 MB).
CREATE TABLE IF NOT EXISTS tfb_product_media (
    id_final_product INT NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    mime VARCHAR(32) NULL,
    bytes INT NULL,
    image MEDIUMBLOB NULL,
    caption VARCHAR(300) NULL,
    caption_fr VARCHAR(300) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_final_product, slot),
    CONSTRAINT chk_tfb_product_media_slot CHECK (slot BETWEEN 0 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Półprodukt / kompozycja : grupa, która go przygotowuje (czasem jedna grupa robi recepturę dla innej).
-- Pokazywane w kalkulacji, w formularzu receptury i na tablecie (drzewo receptury, lista grupy przygotowującej).
CREATE TABLE IF NOT EXISTS tfb_recipe_producer (
    id_recipe INT NOT NULL PRIMARY KEY,
    id_group INT NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tfb_recipe_producer_group (id_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
