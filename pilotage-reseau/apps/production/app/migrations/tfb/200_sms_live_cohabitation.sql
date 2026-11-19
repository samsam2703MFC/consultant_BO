-- [318] Serwer atelier (tryb współistnienia ze starym ERP) : e-maile zostają w trybie testowym (bez podwójnych wysyłek),
-- ale SMS mogą wychodzić naprawdę — przełącznik administratora w Ustawieniach › SMS. Kopia testowa (bez współistnienia) : nigdy.
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('sms.live_cohab', '0', '0', 'bool', NULL, 'Serwer atelier (współistnienie) : SMS wychodzą naprawdę, choć e-maile są w trybie testowym', 'Serveur de l''atelier (cohabitation) : les SMS partent vraiment, même si les e-mails restent en mode test');
