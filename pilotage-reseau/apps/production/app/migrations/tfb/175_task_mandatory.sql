-- [280] Zadania obowiązkowe (makieta 46, validée le 12/11/2026) : l'administrateur marque une tâche de poste comme
--   obligatoire et règle quand elle bloque l'ERP (le jour du terme à une heure, ou le lendemain à une heure, + tolérance).
--   Tâche obligatoire non faite → l'ERP de la personne n'ouvre que l'écran « Najpierw to zadanie » et l'assistant de la
--   tâche. Chaque tâche ouvre toujours un assistant : son assistant à elle, sinon un assistant à étapes (wizard_steps,
--   JSON [{title, hint, link}] ; vide = étapes par défaut). Déblocage pour la journée par le supérieur ou l'administrateur
--   (n_task_unblock, avec motif). Aucune tâche n'est obligatoire tant que l'administrateur ne l'a pas cochée.
--   Rejouable : ALTER conditionnel, CREATE IF NOT EXISTS.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME = 'mandatory');
SET @sql := IF(@c = 0, 'ALTER TABLE n_position_task
    ADD COLUMN mandatory TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''obowiązkowe : blokuje ERP, gdy niezrobione'' AFTER escalate_days,
    ADD COLUMN block_mode ENUM(''due'', ''next_day'') NOT NULL DEFAULT ''due'' COMMENT ''due = dzień terminu, next_day = dzień po terminie'' AFTER mandatory,
    ADD COLUMN block_time TIME NULL COMMENT ''od której godziny (puste : termin zadania albo 09:00 dnia po)'' AFTER block_mode,
    ADD COLUMN block_tolerance SMALLINT NOT NULL DEFAULT 0 COMMENT ''tolerancja w minutach (0–120)'' AFTER block_time,
    ADD COLUMN wizard_steps TEXT NULL COMMENT ''kroki kreatora zadania bez własnego kreatora (JSON)'' AFTER block_tolerance', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS n_task_unblock (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_user    INT NOT NULL COMMENT 'user.id odblokowanej osoby',
    id_task    INT NOT NULL COMMENT 'n_position_task.id',
    day        DATE NOT NULL,
    reason     VARCHAR(255) NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_task_unblock (id_user, id_task, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
