-- P7 — wejście / wyjście kodem QR zeskanowanym własnym telefonem.
--
-- Tablet przy wejściu pokazuje kod QR zmieniany co kiosk.qr_refresh_s sekund, ważny kiosk.qr_ttl_s
-- (dowód obecności na miejscu : zdjęcie kodu szybko traci ważność). Telefon rejestrowany raz (osoba
-- + PIN), potem jedno dotknięcie. Token telefonu przechowywany jako skrót, odwoływalny przez kierownika.
-- Logistyka : resztki różnych formatów kartonów zapełnione poniżej logistics.leftover_fill_max jadą
-- razem na jednej palecie (0 = nigdy).

CREATE TABLE IF NOT EXISTS tfb_prod_phone (
    id            INT NOT NULL AUTO_INCREMENT,
    id_employee   INT NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    label         VARCHAR(120) NULL COMMENT 'Opis urządzenia (przeglądarka) dla kierownika',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME NULL,
    revoked_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_phone_token (token_hash),
    KEY ix_tfb_phone_employee (id_employee),
    CONSTRAINT fk_tfb_phone_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('kiosk.qr_ttl_s', '90', '90', 'int', 's', 'Ważność kodu QR wejścia / wyjścia', 'Validité du QR code d''entrée / sortie'),
('kiosk.qr_refresh_s', '20', '20', 'int', 's', 'Zmiana kodu QR na tablecie co', 'Renouvellement du QR code sur la tablette'),
('kiosk.phone_token_days', '180', '180', 'int', 'd', 'Ważność rejestracji telefonu', 'Validité de l''enregistrement d''un téléphone'),
('logistics.leftover_fill_max', '0.5', '0.5', 'decimal', NULL, 'Resztki formatów poniżej tego zapełnienia palety razem (0 = nigdy)', 'Restes de formats sous ce remplissage regroupés sur une palette (0 = jamais)');
