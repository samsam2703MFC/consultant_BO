-- Karta produkcyjna : logistyka kartonu i palety, wypiek produktu i temperatura etapu.
-- Pola logistyczne już obecne w ERP (waga sztuki, sztuk / opakowanie / karton, kartonów na warstwę,
-- warstw, kartonów na paletę) zostają w final_product ; tu tylko to, czego ERP nie ma.
-- Wypiek : stan produktu po pracowni (surowy, podpieczony, upieczony) ; piec = etap marszruty na
-- stanowisku typu « four », więc piece, czasy i temperatury są połączone z kartą (zarządzanie piecami,
-- obliczenia inwestycji później).

CREATE TABLE IF NOT EXISTS tfb_prod_item_logistics (
    id_item            INT NOT NULL,
    carton_length_mm   SMALLINT UNSIGNED NULL,
    carton_width_mm    SMALLINT UNSIGNED NULL,
    carton_height_mm   SMALLINT UNSIGNED NULL,
    carton_tare_g      INT UNSIGNED NULL,
    carton_type        ENUM('klapowy','fasonowy','inny') NULL,
    carton_ean         VARCHAR(14) NULL,
    pallet_type        ENUM('eur','industrial','other') NULL,
    storage            ENUM('frozen','chilled','ambient') NULL,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by         INT NULL,
    PRIMARY KEY (id_item),
    CONSTRAINT fk_tfb_logistics_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE tfb_prod_product_settings ADD COLUMN bake_state ENUM('none','par_baked','baked') NULL AFTER never_frozen;

ALTER TABLE tfb_prod_routing_step ADD COLUMN temperature_c DECIMAL(5,1) NULL AFTER cleaning_time_s;
