-- Module atelier — P3 : paramètres du calcul des besoins et du plan.
-- Hypothèses à confirmer (points ouverts) : départ du camion le jour d'expédition de la commande
-- (customer_order.expected_shipping_date = date du WDT) à planning.default_departure_time ;
-- capacité du surgélateur non renseignée (0) : aucun plafond appliqué, le plan le signale.

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('cold.freezer_capacity_cartons', '0', '0', 'int', 'ctn', 'Pojemność mroźni (kartony; 0 = nieustawiona)', 'Capacité du surgélateur en cartons (0 = non renseignée)'),
('planning.mts_reorder_on_min', '1', '1', 'bool', NULL, 'MTS: uzupełniaj dopiero, gdy prognozowany stan spadnie poniżej minimum', 'MTS : réapprovisionner seulement quand le stock projeté passe sous le minimum'),
('planning.round_mto_to_batch', '1', '1', 'bool', NULL, 'MTO: zaokrąglaj do partii (nadwyżka na magazyn)', 'MTO : arrondir au lot (le surplus va en stock)'),
('planning.max_period_days', '28', '28', 'int', 'd', 'Najdłuższy okres jednego planu', 'Durée maximale d''un plan');
