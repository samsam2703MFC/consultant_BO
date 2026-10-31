-- ============================================================
-- Migracja 023: Moduł kompetencji recepturowych
-- Data: 2026-03-12
-- UWAGA: tabele recipe_competency i recipe_competency_connection
--        mogą już istnieć w bazie – skrypt jest idempotentny.
-- ============================================================

-- Tabela kompetencji recepturowych (jeśli nie istnieje)
CREATE TABLE IF NOT EXISTS `recipe_competency` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(255) NOT NULL,
  `description` TEXT         NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabela powiązania receptura <-> kompetencja (jeśli nie istnieje)
CREATE TABLE IF NOT EXISTS `recipe_competency_connection` (
  `recipe_id`     INT(11) NOT NULL,
  `competency_id` INT(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dodaj PRIMARY KEY do recipe_competency_connection (jeśli brak)
ALTER TABLE `recipe_competency_connection`
  ADD PRIMARY KEY IF NOT EXISTS (`recipe_id`, `competency_id`);

-- Dodaj FK z ON DELETE CASCADE (jeśli brak)
ALTER TABLE `recipe_competency_connection`
  ADD CONSTRAINT `fk_rcc_competency`
  FOREIGN KEY IF NOT EXISTS (`competency_id`)
  REFERENCES `recipe_competency` (`id`) ON DELETE CASCADE;
