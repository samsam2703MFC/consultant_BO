-- Oznaczenie podstawy ewidencji opakowań BDO.
-- Uruchom ręcznie po migracji 040_shipment_packaging_bdo.sql.
-- Nie jest wykonywane automatycznie przez aplikację.

ALTER TABLE shipment_packaging_record
    ADD COLUMN evidence_basis ENUM('SHIPMENT_SNAPSHOT', 'CURRENT_DATA_RECONSTRUCTION') NOT NULL
        DEFAULT 'SHIPMENT_SNAPSHOT' AFTER status;

-- Dotychczasowe rekordy zachowują wartość SHIPMENT_SNAPSHOT.
-- CURRENT_DATA_RECONSTRUCTION będzie ustawiane wyłącznie dla ewidencji
-- uzupełnianej dla historycznych wysyłek z wykorzystaniem aktualnych danych.
