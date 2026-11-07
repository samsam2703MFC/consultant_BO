-- Marże kategorii : « Przelicz wszystkie marże » przelicza koszt surowców wszystkich receptur po
-- bieżących cenach surowców (ceny sprzedaży się nie zmieniają). Dla każdego produktu zapisuje koszt
-- surowców kartonu przed i po ostatnim przeliczeniu — strona pokazuje efekt na sztukę (€ i %).

CREATE TABLE IF NOT EXISTS tfb_margin_recalc (
    id_product       INT NOT NULL,
    old_cost_carton  DECIMAL(12,4) NOT NULL,
    new_cost_carton  DECIMAL(12,4) NOT NULL,
    ppc              INT NULL,
    recalculated_at  DATETIME NOT NULL,
    recalculated_by  INT NULL,
    PRIMARY KEY (id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
