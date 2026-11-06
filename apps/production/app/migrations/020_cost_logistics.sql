-- ============================================================
-- MIGRACJA 020: Koszty logistyczne
-- ============================================================

-- Słownik typów kosztów logistycznych
-- Klucze (code) są enum-ready — uppercase, bez spacji
CREATE TABLE IF NOT EXISTS `cost_logistics_type` (
  `id`         int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       varchar(60)  NOT NULL COMMENT 'Klucz enum-ready, np. FUEL, FREIGHT, CUSTOMS',
  `name`       varchar(150) NOT NULL COMMENT 'Wyświetlana nazwa PL',
  `is_active`  tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order` int(11)      NOT NULL DEFAULT 0,
  `created_at` datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wstępna lista typów (code = przyszły enum, name = etykieta UI)
INSERT INTO `cost_logistics_type` (`code`, `name`, `sort_order`) VALUES
  ('FUEL',           'Paliwo',                              10),
  ('FREIGHT_IN',     'Transport przychodzący (dostawy)',    20),
  ('FREIGHT_OUT',    'Transport wychodzący (wysyłki)',      30),
  ('COURIER',        'Przesyłki kurierskie',                40),
  ('CUSTOMS',        'Cło / Opłaty celne',                  50),
  ('FORWARDER',      'Usługi spedycyjne',                   60),
  ('PALLET',         'Opłaty paletowe / zarządzanie',       70),
  ('PACKAGING_LOG',  'Materiały opakowaniowe (logistyka)',   80),
  ('WAREHOUSE_EXT',  'Magazyn zewnętrzny',                  90),
  ('HANDLING',       'Załadunek / Rozładunek',             100),
  ('COLD_CHAIN',     'Transport w łańcuchu chłodniczym',   110),
  ('INSURANCE_SHIP', 'Ubezpieczenie transportu',           120),
  ('CUSTOMS_AGENT',  'Agencja celna',                      130),
  ('OTHER_LOG',      'Pozostałe koszty logistyczne',       999)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`);

-- Tabela kosztów logistycznych per miesiąc
CREATE TABLE IF NOT EXISTS `cost_month_logistics` (
  `id`                  int(11)        NOT NULL AUTO_INCREMENT,
  `month_id`            int(11)        NOT NULL COMMENT 'FK -> cost_month.id',
  `logistics_type_id`   int(11) UNSIGNED NOT NULL COMMENT 'FK -> cost_logistics_type.id',
  `amount`              decimal(12,2)  NOT NULL DEFAULT 0.00,
  `note`                varchar(255)   DEFAULT NULL,
  `created_at`          datetime       NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_month_id` (`month_id`),
  KEY `idx_logistics_type_id` (`logistics_type_id`),
  CONSTRAINT `fk_cml_month`
    FOREIGN KEY (`month_id`) REFERENCES `cost_month` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cml_type`
    FOREIGN KEY (`logistics_type_id`) REFERENCES `cost_logistics_type` (`id`)
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

