-- P7 — tablety na produkcji : urządzenie (tablet) sparowane kodem, identyfikacja osoby (identyfikator
-- NFC/QR + PIN), rejestracja czasu pracy jednym dotknięciem (wejście / wyjście), podpis odręczny.
--
-- Biometria (odcisk palca) : tylko za dobrowolną, pisemną zgodą pracownika i z alternatywą bez
-- biometrii (art. 22^1b Kodeksu pracy, wytyczne UODO) — pole zgody przygotowane, identyfikacja
-- domyślnie identyfikator + PIN. Kody i tokeny przechowywane wyłącznie jako skróty (SHA-256 / bcrypt).

CREATE TABLE IF NOT EXISTS tfb_prod_device (
    id                  INT NOT NULL AUTO_INCREMENT,
    name                VARCHAR(100) NOT NULL,
    id_group            INT NULL COMMENT 'Grupa, przy której stoi tablet (lista osób i operacji)',
    token_hash          CHAR(64) NULL,
    pairing_code_hash   CHAR(64) NULL,
    pairing_expires_at  DATETIME NULL,
    active              TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at        DATETIME NULL,
    created_by          INT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_device_token (token_hash),
    UNIQUE KEY uq_tfb_device_pairing (pairing_code_hash),
    CONSTRAINT fk_tfb_device_group FOREIGN KEY (id_group) REFERENCES tfb_prod_group (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_employee_credential (
    id_employee          INT NOT NULL,
    badge_hash           CHAR(64) NULL COMMENT 'SHA-256 (HMAC) numeru identyfikatora NFC / QR',
    pin_hash             VARCHAR(255) NULL,
    failed_attempts      INT NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    biometric_consent    TINYINT(1) NOT NULL DEFAULT 0,
    biometric_consent_at DATETIME NULL,
    biometric_consent_by INT NULL,
    updated_by           INT NULL,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_employee),
    UNIQUE KEY uq_tfb_credential_badge (badge_hash),
    CONSTRAINT fk_tfb_credential_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_clock (
    id               BIGINT NOT NULL AUTO_INCREMENT,
    id_employee      INT NOT NULL,
    clock_in         DATETIME NOT NULL,
    clock_out        DATETIME NULL,
    id_device        INT NULL,
    source           ENUM('kiosk','manual') NOT NULL DEFAULT 'kiosk',
    note             VARCHAR(255) NULL,
    in_client_uuid   CHAR(36) NULL,
    out_client_uuid  CHAR(36) NULL,
    corrected_by     INT NULL,
    corrected_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_clock_in_uuid (in_client_uuid),
    UNIQUE KEY uq_tfb_clock_out_uuid (out_client_uuid),
    KEY ix_tfb_clock_employee (id_employee, clock_in),
    CONSTRAINT fk_tfb_clock_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id),
    CONSTRAINT fk_tfb_clock_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_clock_order CHECK (clock_out IS NULL OR clock_out >= clock_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tfb_prod_signature (
    id           BIGINT NOT NULL AUTO_INCREMENT,
    id_employee  INT NOT NULL,
    purpose      ENUM('clock_out','timesheet_month','operation') NOT NULL,
    ref          VARCHAR(64) NOT NULL COMMENT 'clock_out : id pointage ; timesheet_month : AAAA-MM ; operation : id opération',
    storage      ENUM('db','s3') NOT NULL DEFAULT 'db',
    image_png    MEDIUMBLOB NULL,
    s3_key       VARCHAR(255) NULL,
    sha256       CHAR(64) NOT NULL COMMENT 'Empreinte de l''image : intégrité du document signé',
    id_device    INT NULL,
    signed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_signature_ref (id_employee, purpose, ref),
    CONSTRAINT fk_tfb_signature_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id),
    CONSTRAINT fk_tfb_signature_device FOREIGN KEY (id_device) REFERENCES tfb_prod_device (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('kiosk.pin_max_attempts', '5', '5', 'int', NULL, 'Błędne PIN-y przed blokadą', 'Codes PIN erronés avant blocage'),
('kiosk.lock_minutes', '15', '15', 'int', 'min', 'Czas blokady po błędnych PIN-ach', 'Durée du blocage après des PIN erronés'),
('kiosk.employee_token_ttl_s', '120', '120', 'int', 's', 'Ważność identyfikacji osoby na tablecie', 'Validité de l''identification d''une personne sur la tablette'),
('kiosk.idle_logout_s', '45', '45', 'int', 's', 'Wylogowanie z tabletu po bezczynności', 'Déconnexion de la tablette après inactivité'),
('kiosk.pairing_ttl_min', '15', '15', 'int', 'min', 'Ważność kodu parowania tabletu', 'Validité du code d''appairage de la tablette'),
('kiosk.signature_on_clock_out', '1', '1', 'bool', NULL, 'Podpis przy wyjściu (potwierdzenie godzin)', 'Signature à la sortie (confirmation des heures)'),
('kiosk.max_shift_hours', '16', '16', 'int', 'h', 'Wejście bez wyjścia dłużej niż tyle godzin : do korekty', 'Entrée sans sortie au-delà : à corriger');
