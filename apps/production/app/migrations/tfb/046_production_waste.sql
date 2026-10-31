-- Tablet grupy : straty dnia i zatwierdzenie dnia przez zespół.
--
-- Strata (tfb_prod_waste) : produkt, ilość (sztuki / kg), powód (jakość, przypalone, uszkodzone, próbki,
-- inne), opcjonalnie partia wyrobu ; odejmowana od wyprodukowanej ilości (dobra = zrobione − straty).
-- Zatwierdzenie dnia (tfb_prod_day_close) : zespół potwierdza produkcję dnia (kto, kiedy, podsumowanie) ;
-- ponowne zatwierdzenie nadpisuje podsumowanie.

CREATE TABLE IF NOT EXISTS tfb_prod_waste (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    work_date     DATE NOT NULL,
    id_group      INT NOT NULL,
    id_item       INT NOT NULL,
    id_output     BIGINT NULL,
    quantity      DECIMAL(12,3) NOT NULL,
    reason        VARCHAR(16) NOT NULL,
    note          VARCHAR(255) NULL,
    id_employee   INT NULL,
    id_device     INT NULL,
    client_uuid   CHAR(36) NULL,
    cancelled_at  DATETIME NULL,
    cancelled_by  INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_waste_uuid (client_uuid),
    KEY ix_tfb_waste_day (work_date, id_group),
    KEY ix_tfb_waste_item (id_item, work_date),
    CONSTRAINT fk_tfb_waste_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id),
    CONSTRAINT fk_tfb_waste_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id),
    CONSTRAINT fk_tfb_waste_output FOREIGN KEY (id_output) REFERENCES tfb_prod_output (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_waste_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_waste_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_waste_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_day_close (
    id            BIGINT NOT NULL AUTO_INCREMENT,
    work_date     DATE NOT NULL,
    id_group      INT NOT NULL,
    id_employee   INT NULL,
    id_device     INT NULL,
    note          VARCHAR(255) NULL,
    summary       TEXT NULL COMMENT 'JSON : zaplanowane, zrobione, straty, dobre na produkt',
    closed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_day_close (work_date, id_group),
    CONSTRAINT fk_tfb_day_close_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id),
    CONSTRAINT fk_tfb_day_close_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_day_close_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
