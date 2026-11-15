-- [289] Urlop osoby z kontem ERP (Administrator / IT, CEO, Finanse, Logistyka, Produkcja, Jakość…) — makieta 50.
-- Wpis « Mój urlop » z zastępcą (kto przejmuje obowiązki) ; urlop zatwierdza CEO (albo administrator). Przekazanie zadań
-- zastępcy nie wymaga zatwierdzenia : w dni zatwierdzonego urlopu zadania stanowiska osoby (Moje zadania, obowiązkowe,
-- przypomnienia) idą do zastępcy, po urlopie wracają same. Zastępcę można zmienić w każdej chwili.
CREATE TABLE IF NOT EXISTS n_user_leave (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    id_user       INT NOT NULL COMMENT 'user.id (osoba na urlopie)',
    type          ENUM('urlop','na_zadanie','inna') NOT NULL DEFAULT 'urlop',
    date_from     DATE NOT NULL,
    date_to       DATE NOT NULL,
    days          INT NOT NULL COMMENT 'jours ouvrés (lun.–ven. hors fériés PL)',
    note          VARCHAR(255) NULL,
    id_substitute INT NULL COMMENT 'user.id du remplaçant (tâches transférées pendant le congé validé)',
    status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    decision_note VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by    INT NULL COMMENT 'user.id (CEO / administrateur)',
    decided_at    DATETIME NULL,
    KEY ix_n_user_leave_user (id_user, date_from),
    KEY ix_n_user_leave_sub (id_substitute, status, date_from),
    KEY ix_n_user_leave_status (status, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
