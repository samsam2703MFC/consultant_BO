-- [294] Sesja Finanse (Administracja i fakturowanie) : + « Wysyłki i dokumenty » w menu — faktury WDT, WZ, CMR, dokumenty
--   różnic i KSeF każdej wysyłki (demande du 12/11/2026 : « dans la finance rajouter les factures, CMR, WZ… »).
--   Faktury i proformy zostają w « Faktury i płatności ». Dodaje tylko (INSERT IGNORE) : ustawienia administratora zostają.
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'shipments', NULL FROM n_position p WHERE p.code = 'ADMINISTRACJA';

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'shipping' AS page_key UNION ALL SELECT 'archive' UNION ALL SELECT 'invoices') k
WHERE p.code = 'ADMINISTRACJA';
