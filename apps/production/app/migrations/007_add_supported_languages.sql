-- Migration: Add supported_language table
-- Date: 2026-02-20
-- Description: Stores the list of languages supported for product labels (ISO 639-1 codes)

CREATE TABLE IF NOT EXISTS `supported_language` (
  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(2)   NOT NULL COMMENT 'ISO 639-1 language code (e.g. pl, fr, en, nl)',
  `name`       VARCHAR(255) NOT NULL COMMENT 'User-friendly language name',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = disabled',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supported_language_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed with the two languages already used in the application
INSERT INTO `supported_language` (`code`, `name`, `is_active`) VALUES
  ('fr', 'Français', 1),
  ('pl', 'Polski',   1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
