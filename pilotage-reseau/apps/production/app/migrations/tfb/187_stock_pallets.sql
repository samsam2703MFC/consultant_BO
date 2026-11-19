-- [296] Rail « Stany i palety » (Logistyka, makieta 53) : magazyn → palety w budowie → gotowe → wysłane, skład palety,
--   śledzenie partii (produkt + dzień etykiety : u których klientów jest). Strona /logistics/stock, w prawie « logistics ».
-- Menu du poste Logistyka : + « Stany i palety ». Ajout seul (INSERT IGNORE) : les réglages de l'administrateur restent.
INSERT IGNORE INTO n_position_menu (id_position, item, open_path)
SELECT p.id, 'stock_pallets', NULL FROM n_position p WHERE p.code = 'LOGISTYKA';
