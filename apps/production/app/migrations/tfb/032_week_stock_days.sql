-- Tydzień produkcji : najpierw « na wysyłkę » (zamówienia — przed wyjazdem ciężarówki), « na magazyn »
-- tylko w dni magazynowe (domyślnie środa–piątek ; 1 = poniedziałek … 7 = niedziela).

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('week.stock_days', '3,4,5', '3,4,5', 'string', NULL, 'Dni produkcji na magazyn (1 = pn … 7 = nd) ; wysyłka ma pierwszeństwo przez cały tydzień', 'Jours de production pour le stock (1 = lun … 7 = dim) ; l''expédition reste prioritaire toute la semaine');
