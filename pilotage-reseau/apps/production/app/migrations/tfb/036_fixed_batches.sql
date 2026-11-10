-- Stałe partie co tydzień (📌 « Co tydzień » na tablicy tygodnia produkcji) : produkcja jest prawie taka sama
-- co tydzień, więc blok produktu w dniu można przypiąć — ten sam produkt, ta sama ilość, ten sam dzień
-- tygodnia (1 = poniedziałek … 7 = niedziela) w każdym kolejnym tygodniu.

CREATE TABLE IF NOT EXISTS tfb_prod_fixed_batch (
    id          INT NOT NULL AUTO_INCREMENT,
    id_item     INT NOT NULL,
    weekday     TINYINT NOT NULL,
    quantity    DECIMAL(12,3) NOT NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_fixed_batch (id_item, weekday),
    CONSTRAINT fk_tfb_fixed_batch_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_fixed_batch CHECK (weekday BETWEEN 1 AND 7 AND quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stała partia w danym tygodniu : wchodzi raz (ilość tygodnia + ile dołożono na dzień), więc przesunięcie
-- albo zdjęcie w jednym tygodniu zostaje, a odpięcie cofa tylko tygodnie późniejsze.
CREATE TABLE IF NOT EXISTS tfb_prod_fixed_batch_week (
    week_start  DATE NOT NULL,
    id_item     INT NOT NULL,
    work_date   DATE NOT NULL,
    quantity    DECIMAL(12,3) NOT NULL,
    placed      DECIMAL(12,3) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, id_item, work_date),
    KEY idx_tfb_fixed_week_item (id_item, work_date),
    CONSTRAINT fk_tfb_fixed_week_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Linie planu : część ze stałych partii ponad zamówienia i uzupełnienie stanu (na magazyn).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_plan_line' AND COLUMN_NAME = 'qty_fixed');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE tfb_prod_plan_line ADD COLUMN qty_fixed DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER qty_semi', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
