-- ============================================================
-- Migration 021: Tworzenie tabeli product_recipe_preparation_step
-- Cel: Przechowywanie kroków przygotowania receptury/podreceptury
-- Data: 2026-03-10
-- ============================================================

CREATE TABLE IF NOT EXISTS `product_recipe_preparation_step` (
    `id`               int(11)      NOT NULL AUTO_INCREMENT,
    `recipe_id`        int(11)      NOT NULL COMMENT 'Klucz obcy do product_recipe.id',
    `step_number`      int(11)      NOT NULL COMMENT 'Numer kolejności kroku (1, 2, 3, ...)',
    `step_description` text         NOT NULL COMMENT 'Opis czynności do wykonania',
    PRIMARY KEY (`id`),
    KEY `idx_recipe_id` (`recipe_id`),
    CONSTRAINT `fk_prep_step_recipe`
        FOREIGN KEY (`recipe_id`)
        REFERENCES `product_recipe` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci
  COMMENT='Kroki przygotowania receptury produktu';

