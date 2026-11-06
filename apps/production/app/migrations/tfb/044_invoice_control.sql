-- Kontrola faktur zakupowych : faktura z KSeF (XML FA), z pliku XML albo PDF (tekst), linie dostawcy,
-- przypisanie nazw dostawcy do naszych surowców (pamiętane na dostawcę), porównanie z zamówieniem i przyjęciami.

-- Faktura : źródło, sprzedawca (NIP, nazwa) i dostawca ERP, numer, data, sumy, zamówienie, decyzja.
CREATE TABLE IF NOT EXISTS tfb_inv (
    id            INT NOT NULL AUTO_INCREMENT,
    source        ENUM('ksef','xml','pdf','manual') NOT NULL,
    ksef_number   VARCHAR(64) NULL,
    id_supplier   INT NULL,
    seller_nip    VARCHAR(20) NULL,
    seller_name   VARCHAR(255) NULL,
    number        VARCHAR(80) NULL,
    issue_date    DATE NULL,
    currency      CHAR(3) NOT NULL DEFAULT 'PLN',
    total_net     DECIMAL(14,2) NULL,
    total_gross   DECIMAL(14,2) NULL,
    id_po         INT NULL,
    status        ENUM('open','accepted','disputed') NOT NULL DEFAULT 'open',
    note          VARCHAR(1000) NULL,
    decided_by    INT NULL,
    decided_at    DATETIME NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_inv_ksef (ksef_number),
    KEY idx_tfb_inv_status (status, issue_date),
    KEY idx_tfb_inv_supplier (id_supplier),
    KEY idx_tfb_inv_po (id_po)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Plik faktury (PDF albo XML KSeF) i tekst odczytany z PDF.
CREATE TABLE IF NOT EXISTS tfb_inv_file (
    id_inv     INT NOT NULL,
    filename   VARCHAR(255) NOT NULL,
    mime       VARCHAR(80) NOT NULL,
    content    LONGBLOB NOT NULL,
    text       MEDIUMTEXT NULL,
    PRIMARY KEY (id_inv),
    CONSTRAINT fk_tfb_inv_file FOREIGN KEY (id_inv) REFERENCES tfb_inv (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Linia faktury w języku dostawcy ; surowiec i przelicznik (jednostek bazowych surowca na jednostkę faktury).
CREATE TABLE IF NOT EXISTS tfb_inv_line (
    id               INT NOT NULL AUTO_INCREMENT,
    id_inv           INT NOT NULL,
    line_no          SMALLINT NOT NULL,
    name             VARCHAR(500) NOT NULL,
    code             VARCHAR(80) NULL,
    quantity         DECIMAL(14,4) NOT NULL,
    unit             VARCHAR(20) NULL,
    unit_price       DECIMAL(14,6) NULL,
    net_value        DECIMAL(14,2) NULL,
    vat_rate         VARCHAR(8) NULL,
    id_raw_material  INT NULL,
    factor           DECIMAL(14,4) NULL,
    PRIMARY KEY (id),
    KEY idx_tfb_inv_line (id_inv, line_no),
    KEY idx_tfb_inv_line_material (id_raw_material),
    CONSTRAINT fk_tfb_inv_line FOREIGN KEY (id_inv) REFERENCES tfb_inv (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Nazwa dostawcy → nasz surowiec (na dostawcę : NIP albo dostawca ERP ; nazwa i jednostka znormalizowane).
CREATE TABLE IF NOT EXISTS tfb_inv_mapping (
    id               INT NOT NULL AUTO_INCREMENT,
    supplier_key     VARCHAR(40) NOT NULL,
    id_supplier      INT NULL,
    name_norm        VARCHAR(300) NOT NULL,
    unit_norm        VARCHAR(20) NOT NULL DEFAULT '',
    supplier_name    VARCHAR(500) NOT NULL,
    supplier_unit    VARCHAR(20) NULL,
    id_raw_material  INT NOT NULL,
    factor           DECIMAL(14,4) NOT NULL DEFAULT 1,
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_inv_mapping (supplier_key, name_norm, unit_norm),
    KEY idx_tfb_inv_mapping_material (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- NIP sprzedawcy → dostawca ERP (tabela supplier nie ma NIP) ; zapamiętane przy pierwszym przypisaniu.
CREATE TABLE IF NOT EXISTS tfb_inv_supplier (
    seller_nip   VARCHAR(20) NOT NULL,
    id_supplier  INT NOT NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (seller_nip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
