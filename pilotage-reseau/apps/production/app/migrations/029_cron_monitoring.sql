-- Durable execution history and reliable Slack-notification outbox for CLI cron jobs.

CREATE TABLE IF NOT EXISTS `cron_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(191) NOT NULL,
    `status` ENUM('running', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'running',
    `started_at` DATETIME NOT NULL,
    `finished_at` DATETIME NULL,
    `duration_ms` INT UNSIGNED NULL,
    `exit_code` SMALLINT NULL,
    `error_message` TEXT NULL,
    `context_json` JSON NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cron_runs_job_started` (`job_name`, `started_at`),
    KEY `idx_cron_runs_status_started` (`status`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_notification_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cron_run_id` BIGINT UNSIGNED NOT NULL,
    `payload_json` JSON NOT NULL,
    `status` ENUM('pending', 'processing', 'sent', 'dead') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `next_try_at` DATETIME NOT NULL,
    `locked_at` DATETIME NULL,
    `locked_by` VARCHAR(100) NULL,
    `sent_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cron_notification_run` (`cron_run_id`),
    KEY `idx_cron_notification_due` (`status`, `next_try_at`),
    CONSTRAINT `fk_cron_notification_run`
        FOREIGN KEY (`cron_run_id`) REFERENCES `cron_runs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
