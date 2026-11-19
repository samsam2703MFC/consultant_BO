-- [312] Zamówienia klientów : przypomnienie o płatności proformy wysłane SMS-em z listy zamówień (historia wysyłek).
CREATE TABLE IF NOT EXISTS n_proforma_sms (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_proforma  INT NOT NULL COMMENT 'document_proforma.id',
    id_order     INT NULL COMMENT 'customer_order.id (lista zamówień)',
    recipient    VARCHAR(40) NOT NULL,
    language     VARCHAR(5) NOT NULL,
    body         VARCHAR(400) NOT NULL,
    status       VARCHAR(20) NOT NULL COMMENT 'sent, logged (bez bramki SMS), failed',
    provider     VARCHAR(40) NULL,
    error        VARCHAR(255) NULL,
    created_by   VARCHAR(60) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_proforma_sms_proforma (id_proforma, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='SMS przypomnień o płatności proform';
