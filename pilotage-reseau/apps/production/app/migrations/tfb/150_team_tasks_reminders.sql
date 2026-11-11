-- Zadania zespołu [232] (makiety 7 i 8) : tablica Kierownika produkcji « zrobione / niezrobione » wszystkich grup i osób
-- (filtry : okres, grupa / stanowisko, osoba, stan, częstotliwość, kategoria, szukaj) i « Przypomnij » osobie albo grupie :
--   * osoba z kontem ERP → dzwonek tylko dla niej (app_notification.id_user) + e-mail na adres z konta ;
--   * grupa bez kont (tablet grupy produkcji, tablet pakowania, tablet kierowcy, brygadzista na tabletach grup) →
--     komunikat na tablecie, potwierdzany PIN-em (« Przeczytane ») ; znika sam, gdy zadanie się zaliczy.
-- Historia : kto, kiedy, do kogo, wiadomość, przeczytane (kiedy, kto, jak). Rejouable.

CREATE TABLE IF NOT EXISTS n_task_reminder (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch CHAR(16) NOT NULL COMMENT 'jedno « Wyślij przypomnienie » (kilka zadań i odbiorców)',
    id_task INT NULL COMMENT 'n_position_task ; NULL = dzień grupy bez stanowiska (np. Tarty)',
    id_occurrence BIGINT NULL COMMENT 'n_task_occurrence, gdy istnieje',
    due_date DATE NOT NULL COMMENT 'termin zadania',
    title VARCHAR(255) NOT NULL COMMENT 'nazwa zadania w chwili wysłania',
    message TEXT NOT NULL,
    urgent TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = pilne (na czerwono)',
    target_kind ENUM('user', 'tablet') NOT NULL,
    id_user INT NULL COMMENT 'odbiorca z kontem ERP',
    audience VARCHAR(24) NULL COMMENT 'tablet : group:<id> | packing | driver | shift',
    target_label VARCHAR(120) NOT NULL COMMENT 'nazwa odbiorcy (osoba, grupa, tablet)',
    channels VARCHAR(40) NOT NULL COMMENT 'bell,email | tablet',
    id_notification BIGINT UNSIGNED NULL COMMENT 'app_notification (dzwonek osoby)',
    id_message BIGINT NULL COMMENT 'communication_message (e-mail)',
    sent_by INT NULL,
    sent_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    read_by_employee INT NULL COMMENT 'osoba, która potwierdziła na tablecie',
    read_via ENUM('pin', 'tap', 'bell') NULL,
    closed_at DATETIME NULL COMMENT 'zadanie zaliczone → komunikat zniknął z tabletu',
    PRIMARY KEY (id),
    KEY idx_task_reminder_task (id_task, due_date),
    KEY idx_task_reminder_audience (audience, read_at, closed_at),
    KEY idx_task_reminder_sent (sent_at),
    KEY idx_task_reminder_batch (batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Przypomnienia zadań (tablica « Zadania zespołu ») : osoba (dzwonek + e-mail) albo tablet grupy (PIN)';

-- Dzwonek dla jednej osoby : NULL = wszyscy (jak dotąd), id = tylko ten użytkownik widzi i potwierdza.
-- (Base sans app_notification — bases de test des seules migrations tfb : rien à faire.)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notification') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_notification' AND COLUMN_NAME = 'id_user') = 0,
    'ALTER TABLE app_notification ADD COLUMN id_user INT NULL AFTER payload_json, ADD KEY idx_app_notification_user (id_user, acknowledged_at)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
