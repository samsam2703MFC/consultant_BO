-- [438] Różnice kursowe (Finanse) : écarts de change sur les paiements des clients en devise, par facture.
-- Règles décidées le 25.11.2026 :
--   - cours de la facture = cours moyen NBP (table A) du dernier jour ouvré AVANT la date de la facture ;
--   - cours du paiement : compte en devise (EUR) → cours moyen NBP du dernier jour ouvré avant le paiement ; compte PLN
--     (la banque a converti) → cours appliqué par la banque (ING), lu sur la ligne du relevé ;
--   - écart = montant en devise × (cours du paiement − cours de la facture), en zł arrondi au grosz ; > 0 dodatnia,
--     < 0 ujemna ; calculé quand le paiement et la facture existent (proforma payée sans facture = « czeka na fakturę »).
-- Les factures et proformas restent celles de l'ERP (document_wdt + document_wdt_product, document_proforma +
-- document_proforma_product, lien proforma → facture par customer_order.id_proforma / id_wdt) : aucun second registre.
-- Tables propres :
--   - n_fx_rate : cours moyens NBP table A (toutes les devises de la table : celles des clients en font partie), par jour
--     de publication ;
--   - n_fx_rate_day : jours déjà demandés à NBP (table publiée ou non : week-end, jour férié) — un jour sans table renvoie
--     à la table précédente sans redemander ;
--   - n_bank_statement : relevés ING (PDF chiffré comme les preuves de paiement, texte lu chiffré, compte EUR / PLN choisi
--     à l'envoi, résultat de la lecture) ;
--   - n_bank_statement_line : paiements de clients en devise (relevé lu ou saisie / correction à la main) ; payeur et
--     titre chiffrés (données du client) ;
--   - n_fx_allocation : part d'un paiement affectée à une facture (WDT) ou à une proforma (un virement pour plusieurs
--     factures, paiement partiel) ;
--   - n_prod_param fx.nbp_api_url : adresse de l'API publique NBP (réglage, jamais dans le code).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS. Pas de clé étrangère vers les tables de l'ERP
-- (bases partielles des tests).

CREATE TABLE IF NOT EXISTS n_fx_rate (
    currency    CHAR(3) NOT NULL COMMENT 'kod waluty ISO (tabela A NBP)',
    rate_date   DATE NOT NULL COMMENT 'dzień publikacji tabeli (effectiveDate)',
    mid         DECIMAL(14,8) NOT NULL COMMENT 'kurs średni NBP : zł za 1 jednostkę',
    table_no    VARCHAR(32) NOT NULL COMMENT 'numer tabeli, np. 227/A/NBP/2026',
    fetched_at  DATETIME NOT NULL,
    PRIMARY KEY (currency, rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Różnice kursowe : kursy średnie NBP, tabela A [438]';

CREATE TABLE IF NOT EXISTS n_fx_rate_day (
    day         DATE NOT NULL,
    has_table   TINYINT(1) NOT NULL COMMENT '1 = NBP opublikował tabelę A tego dnia ; 0 = dzień bez tabeli (weekend, święto)',
    table_no    VARCHAR(32) NULL,
    checked_at  DATETIME NOT NULL,
    PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Różnice kursowe : dni sprawdzone w NBP (z tabelą albo bez) [438]';

CREATE TABLE IF NOT EXISTS n_bank_statement (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank             VARCHAR(20) NOT NULL DEFAULT 'ING',
    account_currency ENUM('EUR','PLN') NOT NULL COMMENT 'rachunek walutowy (EUR) albo złotowy (PLN, bank przewalutował)',
    period_from      DATE NULL,
    period_to        DATE NULL,
    filename         VARCHAR(255) NULL,
    mime             VARCHAR(100) NULL,
    size_bytes       INT NULL,
    content_sha256   CHAR(64) NULL,
    content          LONGBLOB NULL COMMENT 'plik zaszyfrowany (p1:), jak potwierdzenia wpłat',
    text_enc         MEDIUMTEXT NULL COMMENT 'tekst odczytany (pdftotext) zaszyfrowany (p1:)',
    parser           VARCHAR(40) NULL,
    parse_status     ENUM('parsed','unsupported','no_text','manual') NOT NULL DEFAULT 'manual'
                     COMMENT 'parsed = linie odczytane ; unsupported = format jeszcze nieobsługiwany ; no_text = PDF bez tekstu ; manual = wpisany ręcznie',
    parse_message    VARCHAR(255) NULL,
    lines_other      INT NOT NULL DEFAULT 0 COMMENT 'inne linie wyciągu (nie wpłaty klientów w walucie)',
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bank_statement_sha (content_sha256),
    KEY idx_bank_statement_period (period_from, period_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Różnice kursowe : wyciągi bankowe (ING) [438]';

CREATE TABLE IF NOT EXISTS n_bank_statement_line (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_statement     INT UNSIGNED NULL COMMENT 'n_bank_statement.id ; NULL = wpłata wpisana bez wyciągu',
    account_currency ENUM('EUR','PLN') NOT NULL,
    booked_on        DATE NOT NULL COMMENT 'data wpłaty (księgowania)',
    amount           DECIMAL(14,2) NOT NULL COMMENT 'kwota w walucie wpłaty',
    currency         CHAR(3) NOT NULL DEFAULT 'EUR',
    amount_pln       DECIMAL(14,2) NULL COMMENT 'rachunek PLN : kwota uznana w zł',
    bank_rate        DECIMAL(14,6) NULL COMMENT 'rachunek PLN : kurs zastosowany przez bank',
    payer_enc        VARCHAR(1024) NULL COMMENT 'zleceniodawca (zaszyfrowany)',
    title_enc        VARCHAR(2048) NULL COMMENT 'tytuł przelewu (zaszyfrowany)',
    ref_numbers      VARCHAR(255) NULL COMMENT 'numery dokumentów znalezione w tytule',
    id_customer      INT NULL,
    source           ENUM('pdf','manual') NOT NULL DEFAULT 'manual',
    match_state      ENUM('matched','to_confirm','ignored') NOT NULL DEFAULT 'to_confirm',
    match_note       VARCHAR(255) NULL,
    created_by       INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by       INT NULL,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bank_line_booked (booked_on),
    KEY idx_bank_line_statement (id_statement),
    KEY idx_bank_line_customer (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Różnice kursowe : wpłaty klientów w walucie z wyciągów [438]';

CREATE TABLE IF NOT EXISTS n_fx_allocation (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_line     INT UNSIGNED NOT NULL COMMENT 'n_bank_statement_line.id',
    doc_kind    ENUM('wdt','proforma') NOT NULL COMMENT 'faktura (document_wdt) albo proforma (document_proforma)',
    id_doc      INT NOT NULL,
    amount      DECIMAL(14,2) NOT NULL COMMENT 'część wpłaty na ten dokument (waluta wpłaty)',
    source      ENUM('number','amount','manual') NOT NULL COMMENT 'numer w tytule, kwota klienta, wybór ręczny',
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fx_allocation_line (id_line),
    KEY idx_fx_allocation_doc (doc_kind, id_doc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Różnice kursowe : wpłata → faktura / proforma [438]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'fx.nbp_api_url', 'https://api.nbp.pl/api', 'https://api.nbp.pl/api', 'string', NULL,
    'Różnice kursowe : adres API kursów NBP (tabela A, kursy średnie) ; pusty = bez pobierania',
    'Écarts de change : adresse de l''API des cours NBP (table A, cours moyens) ; vide = pas de récupération'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'fx.nbp_api_url');
