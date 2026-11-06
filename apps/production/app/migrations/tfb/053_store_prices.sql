-- Sprzedawalność w Belgii (marże) : ceny w sklepach franczyzy z API panelu (konto konsultanta), żeby sprawdzić,
-- czy cena fabryczna za sztukę × mnożnik franczyzy (2,5) mieści się w cenie sklepu.
-- Konto API : hasło i token zaszyfrowane (AES-256-GCM, klucz z sekretu serwera), nigdy w repozytorium ani w logach ;
-- to samo konto co w cockpicie konsultanta (kopiowane przy wdrożeniu : Bin/store_price_cockpit_account.php).

CREATE TABLE IF NOT EXISTS tfb_store_price_config (
    id             TINYINT NOT NULL DEFAULT 1,
    base_url       VARCHAR(255) NOT NULL DEFAULT 'https://atelierby.tfbuddy.com/api/v1',
    phone          VARCHAR(64) NULL,
    password_enc   TEXT NULL COMMENT 'hasło konta konsultanta, zaszyfrowane',
    token_enc      TEXT NULL COMMENT 'JWT panelu (krótki), zaszyfrowany',
    token_exp      DATETIME NULL,
    multiplier     DECIMAL(5,2) NOT NULL DEFAULT 2.50 COMMENT 'mnożnik franczyzy : cena w sklepie netto ≥ cena fabryczna za sztukę × mnożnik',
    limit_coef     DECIMAL(5,2) NOT NULL DEFAULT 2.20 COMMENT 'poniżej : za drogi ; od limitu do mnożnika : na granicy',
    reserve_coef   DECIMAL(5,2) NOT NULL DEFAULT 3.20 COMMENT 'od : rezerwa ceny fabrycznej',
    source         VARCHAR(10) NULL COMMENT 'cockpit = konto skopiowane z cockpitu konsultanta przy wdrożeniu ; manual = wpisane w ERP',
    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_store_price_config (id) VALUES (1);

-- Produkt sklepu (nazwa w sieci) : mediana cen sklepów za całą sztukę (produkt na porcje : cena porcji ÷ udział porcji).
CREATE TABLE IF NOT EXISTS tfb_store_product (
    id               INT NOT NULL AUTO_INCREMENT,
    name_key         VARCHAR(191) NOT NULL COMMENT 'nazwa znormalizowana (klucz dopasowania)',
    name             VARCHAR(255) NOT NULL,
    category         VARCHAR(255) NULL,
    shops            INT NOT NULL DEFAULT 0,
    price_net        DECIMAL(10,4) NULL COMMENT 'mediana ceny netto za sztukę (EUR)',
    price_gross      DECIMAL(10,2) NULL COMMENT 'mediana ceny brutto za sztukę (EUR)',
    price_gross_min  DECIMAL(10,2) NULL,
    price_gross_max  DECIMAL(10,2) NULL,
    vat              DECIMAL(5,2) NULL,
    synced_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_tfb_store_product_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_store_product_shop (
    id_store_product INT NOT NULL,
    shop_id          INT NOT NULL,
    shop_name        VARCHAR(191) NULL,
    price_gross      DECIMAL(10,2) NULL,
    price_net        DECIMAL(10,4) NULL,
    PRIMARY KEY (id_store_product, shop_id),
    CONSTRAINT fk_tfb_store_product_shop FOREIGN KEY (id_store_product) REFERENCES tfb_store_product (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Powiązanie produktu fabryki z produktem sklepu, ustawione ręcznie (bez wiersza : dopasowanie po nazwie).
-- store_key NULL = « nie sprzedawany w sklepach » (bez dopasowania automatycznego).
CREATE TABLE IF NOT EXISTS tfb_store_product_link (
    id_final_product INT NOT NULL,
    store_key        VARCHAR(191) NULL,
    linked_by        INT NULL,
    linked_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_final_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_store_price_sync (
    id           INT NOT NULL AUTO_INCREMENT,
    started_at   DATETIME NOT NULL,
    finished_at  DATETIME NULL,
    status       VARCHAR(10) NOT NULL DEFAULT 'running' COMMENT 'running / ok / partial / failed',
    shops        INT NOT NULL DEFAULT 0,
    shops_failed INT NOT NULL DEFAULT 0,
    products     INT NOT NULL DEFAULT 0,
    message      VARCHAR(500) NULL,
    run_by       INT NULL,
    PRIMARY KEY (id),
    KEY ix_tfb_store_price_sync_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
