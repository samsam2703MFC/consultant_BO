-- Prognoza 13 tygodni [226] : klient wyłączony z prognozy (okno « Kto bierze ile », przełącznik przy kliencie). Jego
-- kartony — zamówienia i prognoza wg udziału — nie liczą się w kartonach, paletach, godzinach, pieniądzach na produkcję
-- i zakupach. Wiersz = klient wyłączony ; ponowne włączenie usuwa wiersz. client_id 0 = « Bez klienta ». Rejouable.

CREATE TABLE IF NOT EXISTS n_forecast_client_excluded (
    client_id INT UNSIGNED NOT NULL COMMENT 'customer.id ; 0 = prognoza bez klienta',
    created_by INT NULL COMMENT 'user.id',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Prognoza 13 tygodni : klienci wyłączeni z obliczeń';
