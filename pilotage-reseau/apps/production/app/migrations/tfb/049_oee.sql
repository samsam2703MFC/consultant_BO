-- TRS (OEE) na tablecie grupy : stany maszyn zapisywane przez zespół — praca, postój z powodem (awaria,
-- przezbrojenie, brak produktu, mycie, przerwa, inne), koniec pracy. TRS = dostępność × wydajność × jakość :
-- dostępność = praca ÷ (praca + postoje nieplanowane) ; wydajność = czas normatywny partii zrobionych na maszynie
-- (marszruta) ÷ czas pracy ; jakość = dobre ÷ zrobione (straty z tabletu).
--
-- oee_tracked : maszyna pokazana na tablecie grupy (domyślnie roboty i coboty). Kolumna dodawana warunkowo (MySQL).

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tfb_prod_workstation' AND COLUMN_NAME = 'oee_tracked');
SET @sql := IF(@col = 0, 'ALTER TABLE tfb_prod_workstation ADD COLUMN oee_tracked TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''TRS na tablecie grupy'' AFTER operator_share', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @init := IF(@col = 0, 'UPDATE tfb_prod_workstation SET oee_tracked = 1 WHERE type = ''robot''', 'DO 0');
PREPARE stmt FROM @init; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tfb_prod_ws_state (
    id              BIGINT NOT NULL AUTO_INCREMENT,
    id_workstation  INT NOT NULL,
    state           VARCHAR(8) NOT NULL COMMENT 'run / stop',
    reason          VARCHAR(16) NULL COMMENT 'postój : breakdown, setup, waiting, cleaning, break, other',
    note            VARCHAR(255) NULL,
    started_at      DATETIME NOT NULL,
    ended_at        DATETIME NULL,
    id_employee     INT NULL,
    id_device       INT NULL,
    client_uuid     CHAR(36) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_ws_state_uuid (client_uuid),
    KEY ix_tfb_ws_state_ws (id_workstation, started_at),
    KEY ix_tfb_ws_state_open (id_workstation, ended_at),
    CONSTRAINT fk_tfb_ws_state_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT fk_tfb_ws_state_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT fk_tfb_ws_state_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
