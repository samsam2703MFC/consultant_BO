-- Tablet grupy (PWA) : produkcja dnia i identyfikowalność.
--
-- Partia wyrobu (tfb_prod_output) : produkt, ilość (sztuki / kg), dzień, grupa, kto i na jakim tablecie,
-- własny numer partii (RRMMDD-GRUPA-NN). Do każdej partii numery partii surowców i opakowań z receptury
-- (tfb_prod_output_lot) z datą przydatności, zdjęciem etykiety (tfb_prod_lot_photo, w bazie, nic nie
-- wychodzi na zewnątrz) i tekstem odczytanym przez OCR na tablecie.

CREATE TABLE IF NOT EXISTS tfb_prod_lot_photo (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    mime        VARCHAR(32) NOT NULL,
    bytes       INT NOT NULL,
    sha256      CHAR(64) NOT NULL,
    image       MEDIUMBLOB NOT NULL,
    id_device   INT NULL,
    id_employee INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_lot_photo_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_output (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    work_date     DATE NOT NULL,
    id_group      INT NOT NULL,
    id_item       INT NOT NULL,
    quantity      DECIMAL(12,3) NOT NULL,
    lot_code      VARCHAR(32) NOT NULL,
    id_employee   INT NULL,
    id_device     INT NULL,
    client_uuid   CHAR(36) NULL,
    note          VARCHAR(255) NULL,
    cancelled_at  DATETIME NULL,
    cancelled_by  INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_output_lot (lot_code),
    UNIQUE KEY uq_tfb_output_uuid (client_uuid),
    KEY ix_tfb_output_day (work_date, id_group),
    KEY ix_tfb_output_item (id_item, work_date),
    CONSTRAINT fk_tfb_output_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id),
    CONSTRAINT fk_tfb_output_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id),
    CONSTRAINT fk_tfb_output_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_output_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_output_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_output_lot (
    id           BIGINT NOT NULL AUTO_INCREMENT,
    id_output    BIGINT NOT NULL,
    id_material  INT NOT NULL COMMENT 'raw_material.id (surowiec lub opakowanie z receptury)',
    quantity     DECIMAL(14,3) NULL COMMENT 'Potrzebna ilość w jednostce surowca',
    lot_number   VARCHAR(64) NULL,
    best_before  DATE NULL,
    id_photo     BIGINT NULL,
    ocr_text     TEXT NULL,
    id_employee  INT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_output_lot_material (id_output, id_material),
    KEY ix_tfb_output_lot_number (lot_number),
    KEY ix_tfb_output_lot_material (id_material, updated_at),
    CONSTRAINT fk_tfb_output_lot_output FOREIGN KEY (id_output) REFERENCES tfb_prod_output (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_output_lot_photo FOREIGN KEY (id_photo) REFERENCES tfb_prod_lot_photo (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('kiosk.lot_photo_max_kb', '1500', '1500', 'int', 'kB', 'Maksymalny rozmiar zdjęcia etykiety partii', 'Taille maximale de la photo d''étiquette de lot'),
('kiosk.output_edit_days', '1', '1', 'int', 'd', 'Ile dni wstecz tablet może poprawiać partie', 'Jours pendant lesquels la tablette peut corriger un lot');
