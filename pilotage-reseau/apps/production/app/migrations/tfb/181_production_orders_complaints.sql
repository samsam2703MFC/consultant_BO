-- [293] Sesja Produkcja (Kierownik produkcji) : + Zamówienia i Reklamacje i zwroty (demande du 12/11/2026 : « dans Session
--   Production, on met aussi zamowienie, reklamacja »).
--   - menu (rail) : pozycje « orders » (/client-orders) i « complaints » (/franchise-complaints, /client-complaints) ;
--   - uprawnienia do stron : orders, franchise (reklamacje franczyzy), returns (zwroty klientów).
--   Dodaje tylko (INSERT IGNORE) : pozycje ustawione wcześniej przez administratora zostają.
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, i.item, NULL FROM n_position p
JOIN (SELECT 'orders' AS item UNION ALL SELECT 'complaints') i
WHERE p.code = 'KIEROWNIK_PRODUKCJI';

INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, k.page_key FROM n_position p
JOIN (SELECT 'orders' AS page_key UNION ALL SELECT 'franchise' UNION ALL SELECT 'returns') k
WHERE p.code = 'KIEROWNIK_PRODUKCJI';
