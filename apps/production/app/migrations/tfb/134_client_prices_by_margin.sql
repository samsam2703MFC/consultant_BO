-- [212] Ceny klientów wg marży (Marża kategorii › Ceny klientów, makieta 2 « klient po kliencie ») : parametry reguły.
-- Nowa cena = max(obecna, min(cel, obecna × (1 + limit))) zaokrąglona w górę ; cel = koszt pełny ÷ (1 − marża kategorii),
-- z rabatem klienta (cena klienta ÷ cena standardowa, najwyżej 1) ; nigdy niżej niż obecna cena.
-- Zastosowanie zapisuje zmiany w n_product_price_change (source = 'margin_clients') ; ta sama cena klienta znowu w górę
-- najwcześniej po prices.client_interval_days dniach. Rejouable.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.client_cap_pct', '8', '8', 'decimal', '%',
    'Ceny klientów wg marży : największa podwyżka jednej ceny na raz (reszta do celu przy następnej zmianie)',
    'Prix clients par la marge : hausse maximale d''un prix en une fois (le reste vers la cible à la prochaine hausse)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.client_cap_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.client_round_eur', '0.05', '0.05', 'decimal', '€',
    'Ceny klientów wg marży : zaokrąglenie nowej ceny kartonu w górę do',
    'Prix clients par la marge : arrondi du nouveau prix du carton vers le haut à'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.client_round_eur');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.client_keep_discount', '1', '1', 'bool', NULL,
    'Ceny klientów wg marży : rabat klienta zachowany (cel × cena klienta ÷ cena standardowa, gdy klient płaci mniej)',
    'Prix clients par la marge : remise du client conservée (cible × prix client ÷ prix standard quand le client paie moins)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.client_keep_discount');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.client_interval_days', '90', '90', 'int', 'dni',
    'Ceny klientów wg marży : następna podwyżka tej samej ceny klienta najwcześniej po tylu dniach (0 = bez przerwy)',
    'Prix clients par la marge : prochaine hausse du même prix client au plus tôt après autant de jours (0 = sans délai)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.client_interval_days');

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_product_price_change') = 1,
    'ALTER TABLE n_product_price_change MODIFY source VARCHAR(30) NOT NULL COMMENT ''general_price_list, category_margin, margin_clients''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
