-- [355] QR pointage : kod krótki zamiast pełnego jetonu w adresie (…/kiosk/scan#t=KOD).
-- Pełny jeton (≈ 340 znaków) dawał QR 73×73 modułów — telefon czytał go z ekranu tabletu wolno albo wcale ;
-- z kodem 11 znaków QR ma ≈ 33×33 modułów. Jeton podpisany zostaje na serwerze (n_kiosk_qr), kod go wskazuje ;
-- wiersz trzymany dzień po wygaśnięciu (zdjęcie starego QR : QR_EXPIRED zamiast QR_INVALID), potem usuwany.
-- Pierwsza litera kodu : Q tablet (grupa), P osobisty (osoba), L logowanie do ERP, M logowanie wybranej osoby — telefon dobiera ekran bez czytania jetonu.
-- Rejouable : CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_kiosk_qr (
    code        CHAR(11) NOT NULL COMMENT 'litera rodzaju + 10 znaków losowych',
    token       TEXT NOT NULL COMMENT 'jeton QR podpisany (aud tfb-clock-qr)',
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code),
    KEY idx_kiosk_qr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kody krótkie QR pointage [355]';
