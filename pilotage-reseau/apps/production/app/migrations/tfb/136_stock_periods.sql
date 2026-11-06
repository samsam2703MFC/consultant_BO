-- [216] Zapas wg planu logistyki i okresy stanów wyjątkowych (Magazyn › Prognoza zapasu) : zapas potrzebny na produkt
-- i tydzień z zatwierdzonego planu logistyki (wyjazdy, dni pracy, palety klientów × koszyk produktów) ; okres wyjątkowy
-- (np. Boże Narodzenie 15.11 – 15.01) ze stanami min. / maks. z prognozy — po zatwierdzeniu obowiązują od pierwszego dnia
-- okresu, po ostatnim dniu wracają stany normalne (n_prod_product_settings). Rejouable.
CREATE TABLE IF NOT EXISTS n_stock_period (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    status ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_stock_period_dates (status, date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Okresy stanów wyjątkowych produktów (Boże Narodzenie…) [216]';

CREATE TABLE IF NOT EXISTS n_stock_period_item (
    id_period INT NOT NULL,
    id_item INT NOT NULL COMMENT 'n_prod_item (produkt)',
    stock_min DECIMAL(12,3) NOT NULL COMMENT 'stan min. w okresie (jednostka bazowa, jak n_prod_product_settings)',
    stock_max DECIMAL(12,3) NOT NULL COMMENT 'stan maks. w okresie (jednostka bazowa)',
    normal_min DECIMAL(12,3) NULL COMMENT 'stan normalny przy utworzeniu (podgląd)',
    normal_max DECIMAL(12,3) NULL,
    forecast_avg DECIMAL(10,1) NULL COMMENT 'prognoza : średnio kartonów na wyjazd w okresie',
    forecast_peak DECIMAL(10,1) NULL COMMENT 'prognoza : szczyt zapasu potrzebnego (kartony)',
    PRIMARY KEY (id_period, id_item),
    KEY idx_n_stock_period_item_item (id_item),
    CONSTRAINT fk_n_stock_period_item_period FOREIGN KEY (id_period) REFERENCES n_stock_period (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_stock_period_item_item FOREIGN KEY (id_item) REFERENCES n_prod_item (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stany wyjątkowe produktów w okresie [216]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'stock.plan_safety_pct', '25', '25', 'decimal', '%',
    'Zapas wg planu logistyki : bezpieczeństwo ponad kartony wyjazdu (zapas potrzebny = wyjazd + wyjazdy bez produkcji + ten %)',
    'Stock selon le plan logistique : sécurité au-dessus des cartons du départ (stock nécessaire = départ + départs sans production + ce %)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'stock.plan_safety_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'stock.freezer_pallet_places', '0', '0', 'int', 'palet',
    'Zamrażarka : miejsca paletowe na produkty gotowe (kontrola okresów stanów wyjątkowych ; 0 = nie podano)',
    'Congélateur : places palettes pour les produits finis (contrôle des périodes de stock d''exception ; 0 = non renseigné)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'stock.freezer_pallet_places');
