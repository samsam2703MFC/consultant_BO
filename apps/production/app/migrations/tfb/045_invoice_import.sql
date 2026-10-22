-- Kontrola faktur : import ostatnich cen z faktury do kartoteki surowców (kiedy i kto) ; ilości bez zamówienia
-- przyjmowane przez zamówienie utworzone z faktury (status « received »).
ALTER TABLE tfb_inv
    ADD COLUMN IF NOT EXISTS prices_imported_at DATETIME NULL AFTER decided_at,
    ADD COLUMN IF NOT EXISTS prices_imported_by INT NULL AFTER prices_imported_at;
