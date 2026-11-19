-- [335] « Stany i palety » : osobne prawo stock_pallets (strona /logistics/stock i usunięcie palety). Kto miał logistykę,
-- zachowuje dostęp ; dochodzą Administracja i fakturowanie (finanse), Kierownik produkcji i Jakość — prawo i pozycja w menu.
-- CEO widzi wszystkie strony (bez ustawień). Tylko dodanie (INSERT IGNORE) : ustawienia administratora zostają.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT id_position, 'stock_pallets' FROM n_page_access WHERE page_key = 'logistics';

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'stock_pallets' FROM n_position p WHERE p.code IN ('ADMINISTRACJA', 'KIEROWNIK_PRODUKCJI', 'JAKOSC');

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'stock_pallets', NULL FROM n_position p WHERE p.code IN ('ADMINISTRACJA', 'KIEROWNIK_PRODUKCJI', 'JAKOSC');
