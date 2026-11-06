-- Nowy produkt z filmu [196] : link Facebook / Pinterest (albo plik wideo, opis, gotowa karta .docx) → asystent AI
-- (Anthropic, klucz w Ustawieniach) → karta technologiczna (A4 .docx / PDF + widok tabletu grupy) → produkt w rozwoju.
-- Rejouable (MySQL 8 / MariaDB).
-- 1. n_dev_card : karta (szkic do zapisania albo powiązana z produktem testowym) — źródło, nazwa, grupa, prognoza,
--    treść karty (JSON : tytuł, wydajność, pieczenie, czas, składniki w grupach, kroki, zdjęcia etapów z podpisami).
-- 2. n_dev_card_frame : klatki filmu wycięte w przeglądarce (JPEG), okładka i zdjęcia z zaimportowanej karty .docx.
-- 3. n_dev_product.weekly_sales / season_quarters : sprzedaż ± na tydzień i kwartały sezonu (NULL = cały rok).
-- 4. n_ai_prompt : prompt karty technologicznej w wersjach (Ustawienia › Asystent AI) ; klucz Anthropic w n_app_secret
--    (zaszyfrowany, migracja 117) albo ANTHROPIC_API_KEY w .env serwera — nigdy w repozytorium.

CREATE TABLE IF NOT EXISTS n_dev_card (
    id               INT NOT NULL AUTO_INCREMENT,
    id_test_product  INT NULL COMMENT 'produkt w rozwoju (po zapisaniu karty)',
    status           ENUM('draft','ready','saved') NOT NULL DEFAULT 'draft' COMMENT 'szkic → karta wygenerowana → zapisana (produkt)',
    source_kind      ENUM('facebook','pinterest','file','text','docx') NOT NULL DEFAULT 'text',
    source_url       VARCHAR(1000) NULL,
    source_author    VARCHAR(255) NULL,
    source_title     VARCHAR(500) NULL,
    source_text      MEDIUMTEXT NULL COMMENT 'opis filmu (przepis) w języku oryginału',
    source_info      TEXT NULL COMMENT 'JSON : wideo, jakość, długość, ściana logowania, uwagi z odczytu linku',
    video_url        VARCHAR(2000) NULL COMMENT 'adres pliku wideo znaleziony w stronie (tymczasowo, do wycięcia klatek)',
    name             VARCHAR(255) NOT NULL,
    id_group         INT NULL,
    weekly_sales     INT NULL,
    season_quarters  VARCHAR(7) NULL COMMENT 'np. 1,4 ; NULL = cały rok',
    card_json        MEDIUMTEXT NULL,
    model            VARCHAR(64) NULL,
    id_prompt        INT NULL,
    usage_json       TEXT NULL COMMENT 'tokeny wejścia / wyjścia ostatniego generowania',
    generated_at     DATETIME NULL,
    generation_ms    INT NULL,
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_dev_card_product (id_test_product),
    KEY idx_n_dev_card_status (status, created_at),
    CONSTRAINT fk_n_dev_card_tp FOREIGN KEY (id_test_product) REFERENCES test_product (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_dev_card_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_dev_card_frame (
    id         BIGINT NOT NULL AUTO_INCREMENT,
    id_card    INT NOT NULL,
    position   INT NOT NULL COMMENT 'kolejność w filmie (0 = okładka)',
    at_ms      INT NULL COMMENT 'chwila w filmie',
    kind       ENUM('frame','cover','docx') NOT NULL DEFAULT 'frame',
    mime       VARCHAR(32) NOT NULL,
    bytes      INT NOT NULL,
    width      INT NULL,
    height     INT NULL,
    image      MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_dev_card_frame (id_card, position),
    CONSTRAINT fk_n_dev_card_frame FOREIGN KEY (id_card) REFERENCES n_dev_card (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_dev_product' AND COLUMN_NAME = 'weekly_sales');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_dev_product ADD COLUMN weekly_sales INT NULL COMMENT ''sprzedaż ± sztuk na tydzień (prognoza)'' AFTER target_date,
        ADD COLUMN season_quarters VARCHAR(7) NULL COMMENT ''kwartały sezonu, np. 1,4 ; NULL = cały rok'' AFTER weekly_sales',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_ai_prompt (
    id          INT NOT NULL AUTO_INCREMENT,
    code        VARCHAR(64) NOT NULL COMMENT 'dev_card = karta technologiczna z filmu',
    body        TEXT NOT NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_ai_prompt_code (code, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'ai.model', 'claude-opus-5-5', 'claude-opus-5-5', 'string', NULL,
    'Asystent AI (karta technologiczna z filmu) : model Anthropic — claude-opus-5-5 (domyślny) albo claude-sonnet-5-5 (tańszy)',
    'Assistant IA (fiche technique depuis une vidéo) : modèle Anthropic — claude-opus-5-5 (par défaut) ou claude-sonnet-5-5 (moins cher)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'ai.model');
