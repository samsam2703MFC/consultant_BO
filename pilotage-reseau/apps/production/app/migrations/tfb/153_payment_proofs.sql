-- Wpłata z e-maila [233] (makieta 9) : potwierdzenie przelewu klienta (PDF, zdjęcie) z Poczty → odczyt (tekst PDF albo
-- OCR) → kwota, data, numer proformy, rachunek, płacący → proforma dopasowana → « Zatwierdź wpłatę » = proforma opłacona
-- (data + kwota w n_proforma_payment, jak Płatności dnia), dowód zapisany przy proformie, przypomnienia zatrzymane.
-- Plik i odczytany tekst zaszyfrowane (dane klienta : rachunek, nazwa). Rejouable.

CREATE TABLE IF NOT EXISTS n_payment_proof (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_user INT NOT NULL COMMENT 'skrzynka (Poczta), z której przyszło potwierdzenie',
    id_mail BIGINT NULL COMMENT 'n_user_mail',
    attachment_index INT NULL COMMENT 'NULL = wiadomość odrzucona bez odczytu',
    id_customer INT NULL,
    filename VARCHAR(255) NULL,
    mime VARCHAR(100) NULL,
    size_bytes INT NULL,
    content_sha256 CHAR(64) NULL,
    content LONGBLOB NULL COMMENT 'plik zaszyfrowany (p1:)',
    method ENUM('pdf_text', 'ocr') NULL COMMENT 'tekst z PDF albo OCR (zdjęcie, skan)',
    text_enc MEDIUMTEXT NULL COMMENT 'odczytany tekst zaszyfrowany (p1:)',
    read_amount DECIMAL(12,2) NULL,
    read_currency CHAR(3) NULL,
    read_date DATE NULL,
    read_numbers VARCHAR(255) NULL COMMENT 'numery proform znalezione w dokumencie',
    iban_ours TINYINT(1) NULL COMMENT '1 = nasz rachunek, 0 = inny, NULL = nie odczytano',
    payer_enc VARCHAR(512) NULL COMMENT 'płacący (zaszyfrowany)',
    status ENUM('to_check', 'confirmed', 'rejected') NOT NULL DEFAULT 'to_check',
    paid_on DATE NULL,
    amount DECIMAL(12,2) NULL COMMENT 'kwota zatwierdzona',
    decided_by INT NULL,
    decided_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_proof_mail (id_mail, attachment_index),
    KEY idx_payment_proof_status (id_user, status, created_at),
    KEY idx_payment_proof_customer (id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Potwierdzenia wpłat klientów z Poczty (odczyt, dopasowanie do proform, zatwierdzenie)';

CREATE TABLE IF NOT EXISTS n_payment_proof_proforma (
    id_proof INT UNSIGNED NOT NULL,
    id_proforma INT NOT NULL,
    amount DECIMAL(12,2) NULL,
    PRIMARY KEY (id_proof, id_proforma),
    KEY idx_payment_proof_proforma (id_proforma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Proformy opłacone jednym potwierdzeniem (przelew za kilka proform)';
