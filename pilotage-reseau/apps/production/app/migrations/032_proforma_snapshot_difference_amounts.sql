-- Proformy mają już własne pozycje w document_proforma_product.
-- Ta migracja dodaje wyłącznie zapisane sumy do nowych wierszy dokumentów różnic.
-- Historyczne dokumenty pozostają odczytywalne: pola są nullable.

ALTER TABLE `document_order_shipping_difference_products`
    ADD COLUMN IF NOT EXISTS `ordered_total_amount` DECIMAL(14,2) NULL
        AFTER `price_during_ordering`,
    ADD COLUMN IF NOT EXISTS `invoiced_total_amount` DECIMAL(14,2) NULL
        AFTER `price_during_invoicing`;
