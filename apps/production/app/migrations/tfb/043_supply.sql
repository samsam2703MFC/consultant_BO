-- Zaopatrzenie : surowce i opakowania dla produkcji. Zapotrzebowanie z arkusza tygodnia (do produkcji × receptura na
-- karton), tryb surowca (na stanie z min. / maks. albo na zamówienie pod produkcję), zamówienia do dostawców (mailem),
-- przyjęcia z ilością i ceną. Stan = ostatnia inwentaryzacja + przyjęcia − zużycie z produkcji (kartony wyprodukowane
-- × receptura na karton) po dniu inwentaryzacji (stan na koniec dnia).

-- Tryb zaopatrzenia surowca (brak wiersza = na zamówienie, bez stanów).
CREATE TABLE IF NOT EXISTS tfb_mp_supply (
    id_raw_material  INT NOT NULL,
    supply_mode      ENUM('order','stock') NOT NULL DEFAULT 'order',
    stock_min        DECIMAL(14,3) NULL,
    stock_max        DECIMAL(14,3) NULL,
    updated_by       INT NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_raw_material),
    CONSTRAINT ck_tfb_mp_supply_stock CHECK (stock_min IS NULL OR stock_max IS NULL OR stock_max >= stock_min)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inwentaryzacje (stan na koniec dnia, w jednostce bazowej surowca) ; liczy się ostatnia.
CREATE TABLE IF NOT EXISTS tfb_mp_count (
    id               INT NOT NULL AUTO_INCREMENT,
    id_raw_material  INT NOT NULL,
    count_date       DATE NOT NULL,
    quantity         DECIMAL(14,3) NOT NULL,
    note             VARCHAR(255) NULL,
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tfb_mp_count (id_raw_material, count_date, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Zamówienie do dostawcy : szkic → wysłane (mailem albo ręcznie) → przyjęte częściowo / całkowicie ; anulowane.
CREATE TABLE IF NOT EXISTS tfb_po (
    id              INT NOT NULL AUTO_INCREMENT,
    code            VARCHAR(20) NOT NULL,
    id_supplier     INT NOT NULL,
    status          ENUM('draft','sent','partial','received','cancelled') NOT NULL DEFAULT 'draft',
    week_start      DATE NULL,
    delivery_date   DATE NULL,
    language        ENUM('pl','fr') NOT NULL DEFAULT 'pl',
    email_to        VARCHAR(255) NULL,
    note            VARCHAR(1000) NULL,
    id_message      BIGINT NULL,
    sent_at         DATETIME NULL,
    sent_by         ENUM('email','manual') NULL,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_po_code (code),
    KEY idx_tfb_po_status (status, delivery_date),
    KEY idx_tfb_po_supplier (id_supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Linia zamówienia : ilość w jednostce bazowej surowca, opakowanie dostawcy, cena oczekiwana (cennik w chwili zamówienia).
CREATE TABLE IF NOT EXISTS tfb_po_line (
    id               INT NOT NULL AUTO_INCREMENT,
    id_po            INT NOT NULL,
    id_raw_material  INT NOT NULL,
    quantity         DECIMAL(14,3) NOT NULL,
    package_size     DECIMAL(14,3) NULL,
    unit_price       DECIMAL(14,6) NULL,
    PRIMARY KEY (id),
    KEY idx_tfb_po_line_material (id_raw_material),
    CONSTRAINT fk_tfb_po_line_po FOREIGN KEY (id_po) REFERENCES tfb_po (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Przyjęcie (dostawa) linii : ilość i cena rzeczywista ; kilka przyjęć na linię (dostawy częściowe).
CREATE TABLE IF NOT EXISTS tfb_po_receipt (
    id            INT NOT NULL AUTO_INCREMENT,
    id_po_line    INT NOT NULL,
    received_on   DATE NOT NULL,
    quantity      DECIMAL(14,3) NOT NULL,
    unit_price    DECIMAL(14,6) NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tfb_po_receipt_day (received_on),
    CONSTRAINT fk_tfb_po_receipt_line FOREIGN KEY (id_po_line) REFERENCES tfb_po_line (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_role_permission (role_code, permission) VALUES
('prod_admin', 'prod.supply.read'), ('prod_admin', 'prod.supply.write'),
('direction', 'prod.supply.read'), ('direction', 'prod.supply.write'),
('prod_manager', 'prod.supply.read'), ('prod_manager', 'prod.supply.write');

-- Mail zamówienia do dostawcy (polski domyślnie, francuski możliwy).
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'supplier_order', 'pl', 'Zamówienie do dostawcy', 'Zamówienie {{ order.code }} — {{ company.name }}',
'Dzień dobry,\n\nprosimy o dostawę poniższych towarów{{ order.delivery }}:\n\n{{ order.lines }}\n{{ order.note }}\nProsimy o potwierdzenie zamówienia i terminu dostawy.\n\nZ poważaniem,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'supplier_order' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'supplier_order', 'fr', 'Commande fournisseur', 'Commande {{ order.code }} — {{ company.name }}',
'Bonjour,\n\nmerci de nous livrer les articles suivants{{ order.delivery }} :\n\n{{ order.lines }}\n{{ order.note }}\nMerci de nous confirmer la commande et la date de livraison.\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'supplier_order' AND language_code = 'fr' AND is_default = 1);
