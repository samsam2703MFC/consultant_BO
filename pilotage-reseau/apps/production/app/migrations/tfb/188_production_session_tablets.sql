-- [299] Sesja Produkcja (Kierownik produkcji) : + « Tablety » (parowanie, typ, blokada tabletów, PIN), obok Czas pracy.
--   Strona /workshop/execution (klucz « tablet ») i pozycja railu « tablets ». Tylko dodanie (INSERT IGNORE) : ustawienia
--   administratora (Uprawnienia i menu) zostają.
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'tablet' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';

INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'tablets', NULL FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
