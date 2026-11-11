-- Wartości odżywcze surowców z internetu (Open Food Facts, baza otwarta) : nazwy kategorii OFF (pl, fr, en) do
-- dopasowania nazwy surowca, mediany wartości produktów kategorii (pamięć podręczna 30 dni) i źródło wartości
-- zastosowanych do surowca (kategoria, liczba produktów, kto i kiedy). Wartości zawsze zatwierdza użytkownik.

CREATE TABLE IF NOT EXISTS tfb_nutrition_category (
    tag        VARCHAR(191) NOT NULL COMMENT 'kategoria OFF, np. en:butters',
    lang       CHAR(2) NOT NULL,
    name       VARCHAR(255) NOT NULL,
    loaded_at  DATETIME NOT NULL,
    PRIMARY KEY (tag, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_nutrition_off_cache (
    tag         VARCHAR(191) NOT NULL,
    products    INT NOT NULL DEFAULT 0 COMMENT 'produktów w kategorii (OFF)',
    sample      INT NOT NULL DEFAULT 0 COMMENT 'produktów z wartością energii w medianie',
    values_json TEXT NULL COMMENT 'mediany na 100 g (kolumny raw_material)',
    fetched_at  DATETIME NOT NULL,
    PRIMARY KEY (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_material_nutrition_source (
    id_raw_material INT NOT NULL,
    source          VARCHAR(20) NOT NULL DEFAULT 'off',
    ref             VARCHAR(191) NOT NULL COMMENT 'kategoria OFF',
    label           VARCHAR(255) NOT NULL,
    sample          INT NOT NULL DEFAULT 0,
    applied_by      INT NULL,
    applied_at      DATETIME NOT NULL,
    PRIMARY KEY (id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
