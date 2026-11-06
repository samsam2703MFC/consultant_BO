-- Kierownik Produkcji i Rozwoju Produktu : karta stanowiska, cele, raport miesięczny, premia i rozmowa oceniająca.
-- Wszystko z danych ERP ; brak danej = wskaźnik nieliczony (nigdy zgadywany). Cele obowiązują w okresie tylko,
-- gdy zostały zatwierdzone przed jego początkiem (inaczej brak premii za sekcję — celowo).

-- Plan roku : osoba na stanowisku, wynagrodzenie, pula premii (domyślnie 20 % rocznego brutto), warunki HACCP,
-- wymiar czasu pracy i godziny miesięcznie na każdą misję stanowiska (JSON : sekcja → godziny).
CREATE TABLE IF NOT EXISTS tfb_mgr_plan (
    id                    INT NOT NULL AUTO_INCREMENT,
    year                  SMALLINT NOT NULL,
    holder_user_id        INT NULL,
    holder_name           VARCHAR(120) NULL,
    holder_start          DATE NULL,
    salary_monthly        DECIMAL(12,2) NULL,
    envelope_pct          DECIMAL(5,2) NOT NULL DEFAULT 20,
    currency              CHAR(3) NOT NULL DEFAULT 'PLN',
    team_organisation     TEXT NULL,
    auditor               VARCHAR(160) NULL,
    audit_threshold       DECIMAL(5,2) NULL,
    margin_threshold_pct  DECIMAL(5,2) NULL,
    price_reference_date  DATE NULL,
    workplace             VARCHAR(160) NULL,
    contract_type         VARCHAR(160) NULL,
    working_time          VARCHAR(160) NULL,
    monthly_hours         DECIMAL(6,1) NULL,
    mission_hours         TEXT NULL,
    updated_by            INT NULL,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mgr_plan_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cele wskaźników : każda zmiana to nowa wersja ; validated_at NULL = szkic. W okresie obowiązuje ostatnia wersja
-- zatwierdzona przed jego początkiem.
CREATE TABLE IF NOT EXISTS tfb_mgr_objective (
    id            INT NOT NULL AUTO_INCREMENT,
    id_plan       INT NOT NULL,
    indicator     VARCHAR(40) NOT NULL,
    target        DECIMAL(14,4) NOT NULL,
    validated_at  DATETIME NULL,
    validated_by  INT NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tfb_mgr_objective (id_plan, indicator, validated_at),
    CONSTRAINT fk_tfb_mgr_objective_plan FOREIGN KEY (id_plan) REFERENCES tfb_mgr_plan (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Wartości miesiąca wpisane ręcznie (tylko dla wskaźników, których ERP nie liczy, np. braki blokujące) ;
-- wpis samej osoby na stanowisku = « niezweryfikowane ».
CREATE TABLE IF NOT EXISTS tfb_mgr_value (
    id          INT NOT NULL AUTO_INCREMENT,
    month       CHAR(7) NOT NULL,
    indicator   VARCHAR(40) NOT NULL,
    value       DECIMAL(14,4) NOT NULL,
    note        VARCHAR(255) NULL,
    entered_by  INT NULL,
    entered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mgr_value (month, indicator)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- HACCP (warunek premii, nie premiowany) : kontrole urzędowe (Sanepid, AFSCA), przerwania łańcucha chłodniczego,
-- niezależne audyty kwartalne (wynik %), samokontrole.
CREATE TABLE IF NOT EXISTS tfb_mgr_haccp_event (
    id           INT NOT NULL AUTO_INCREMENT,
    event_date   DATE NOT NULL,
    kind         ENUM('official_control','cold_chain_break','independent_audit','self_check') NOT NULL,
    authority    VARCHAR(80) NULL,
    result       ENUM('favorable','unfavorable') NULL,
    score        DECIMAL(5,2) NULL,
    treated      TINYINT(1) NOT NULL DEFAULT 0,
    treated_at   DATE NULL,
    description  TEXT NULL,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tfb_mgr_haccp_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Nowy produkt : cele ustalone przed wprowadzeniem (koszt, cena, marża min., wolumen na sklep) i decyzja po 8–12 tyg.
CREATE TABLE IF NOT EXISTS tfb_mgr_launch (
    id                    INT NOT NULL AUTO_INCREMENT,
    id_product            INT NOT NULL,
    launch_date           DATE NOT NULL,
    target_cost           DECIMAL(12,2) NULL,
    target_price          DECIMAL(12,2) NULL,
    min_margin_pct        DECIMAL(5,2) NULL,
    expected_week_shop    DECIMAL(10,2) NULL,
    decision              ENUM('keep','fix','stop') NULL,
    decision_note         VARCHAR(255) NULL,
    updated_by            INT NULL,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mgr_launch_product (id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Trzy działania priorytetowe na następny miesiąc (z raportu miesiąca).
CREATE TABLE IF NOT EXISTS tfb_mgr_action (
    id          INT NOT NULL AUTO_INCREMENT,
    month       CHAR(7) NOT NULL,
    position    TINYINT NOT NULL,
    text        VARCHAR(500) NOT NULL,
    owner       VARCHAR(120) NULL,
    due_date    DATE NULL,
    status      ENUM('open','done') NOT NULL DEFAULT 'open',
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mgr_action (month, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Audyt kompetencji stanowiska : ocena 0–3 na pytanie (kwestionariusz), komentarze, wynik na obszar.
CREATE TABLE IF NOT EXISTS tfb_mgr_competency_audit (
    id                 INT NOT NULL AUTO_INCREMENT,
    audit_date         DATE NOT NULL,
    assessed_name      VARCHAR(120) NOT NULL,
    assessed_user_id   INT NULL,
    answers            LONGTEXT NOT NULL,
    comment            TEXT NULL,
    created_by         INT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tfb_mgr_competency_audit (audit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_role_permission (role_code, permission) VALUES
('prod_admin', 'prod.manager.read'), ('prod_admin', 'prod.manager.report'), ('prod_admin', 'prod.manager.write'),
('direction', 'prod.manager.read'), ('direction', 'prod.manager.report'), ('direction', 'prod.manager.write'),
('prod_manager', 'prod.manager.read'), ('prod_manager', 'prod.manager.report');
