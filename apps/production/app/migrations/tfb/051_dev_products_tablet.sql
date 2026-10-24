-- Nowe produkty (produkty testowe ERP, test_product) na tablecie produkcji : zakładka « Nowe produkty » z recepturą,
-- filmem, zdjęciami i notatkami. Biuro wybiera, co pokazać (on_tablet) ; produkt bez działu widzą wszystkie grupy,
-- z działem — grupa tego działu. Zdjęcia w bazie (JPEG zmniejszony w przeglądarce), jak zdjęcia etykiet partii.

CREATE TABLE IF NOT EXISTS tfb_dev_product (
    id_test_product  INT NOT NULL,
    on_tablet        TINYINT(1) NOT NULL DEFAULT 0,
    video_url        VARCHAR(500) NULL COMMENT 'YouTube, Vimeo, plik mp4 albo dowolny link https',
    notes            TEXT NULL COMMENT 'wskazówki dla zespołu (wykonanie, uwagi do testu)',
    updated_by       INT NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_test_product),
    CONSTRAINT fk_tfb_dev_product_tp FOREIGN KEY (id_test_product) REFERENCES test_product (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_dev_product_photo (
    id               BIGINT NOT NULL AUTO_INCREMENT,
    id_test_product  INT NOT NULL,
    mime             VARCHAR(32) NOT NULL,
    bytes            INT NOT NULL,
    image            MEDIUMBLOB NOT NULL,
    caption          VARCHAR(200) NULL,
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_dev_photo_tp (id_test_product, id),
    CONSTRAINT fk_tfb_dev_photo_tp FOREIGN KEY (id_test_product) REFERENCES test_product (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
