-- Tablet pakowania (28/10/2026) : pakowanie to osobny tablet (/tablet/packing, sparowany jak inne tablety), nie strona ERP.
-- Licznik spakowanych kartonów zapisuje tablet : updated_device = tablet (updated_by = użytkownik ERP, gdy z biura). Rejouable.
ALTER TABLE n_packing_count ADD COLUMN IF NOT EXISTS updated_device INT NULL AFTER updated_by;
