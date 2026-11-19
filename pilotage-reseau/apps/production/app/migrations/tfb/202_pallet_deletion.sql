-- [333] Stany i palety : usunięcie palety (w budowie albo gotowej, nigdy wysłanej). Kartony wracają do magazynu albo są
-- usuwane razem z paletą. Każde usunięcie zostaje w dzienniku : paleta, klient, dzień, tryb, skład (produkt, partia, kartony), kto, powód.
CREATE TABLE IF NOT EXISTS n_pallet_deletion (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_palet      INT NOT NULL COMMENT 'palets_delivery.id_palet (usunięta)',
    id_customer   INT NULL,
    customer      VARCHAR(255) NULL,
    delivery_date DATE NULL,
    pallet_status TINYINT NOT NULL COMMENT '1 = w budowie, 2 = gotowa',
    mode          VARCHAR(10) NOT NULL COMMENT 'stock : kartony do magazynu ; delete : kartony usunięte',
    cartons       INT NOT NULL DEFAULT 0,
    detail        TEXT NULL COMMENT 'JSON : produkt, partia (dzień etykiety), kartony',
    reason        VARCHAR(255) NULL,
    created_by    INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pallet_deletion_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
