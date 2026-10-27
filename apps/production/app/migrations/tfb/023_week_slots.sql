-- Gantt tygodnia : który produkt w który dzień tygodnia (przeciągnij i upuść, « Rozdziel na dni »).
--
-- Produkcja jest tygodniowa : ilości tygodnia pochodzą z planu (lub z propozycji zamówienia + stany),
-- a tu zapisuje się tylko podział na dni : produkt, dzień, ilość. Produkt może być podzielony na kilka
-- dni ; to, czego nie przydzielono, czeka w « Do rozplanowania ». Uszeregowanie planu kończy zlecenie
-- najpóźniej w dniu, na który produkt przydzielono.

CREATE TABLE IF NOT EXISTS tfb_prod_week_slot (
    week_start   DATE NOT NULL,
    id_item      INT NOT NULL,
    work_date    DATE NOT NULL,
    quantity     DECIMAL(12,3) NOT NULL,
    updated_by   INT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (week_start, id_item, work_date),
    KEY idx_tfb_week_slot_item (id_item, work_date),
    CONSTRAINT fk_tfb_week_slot_item FOREIGN KEY (id_item) REFERENCES tfb_prod_item (id) ON DELETE CASCADE,
    CONSTRAINT ck_tfb_week_slot_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('week.production_days', '1,2,3,4,5', '1,2,3,4,5', 'string', NULL, 'Dni produkcji w tygodniu (1 = poniedziałek … 7 = niedziela)', 'Jours de production de la semaine (1 = lundi … 7 = dimanche)');
