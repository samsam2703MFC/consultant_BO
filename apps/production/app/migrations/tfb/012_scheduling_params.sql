-- Module atelier — P4 : paramètres de l'ordonnancement.
INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('scheduling.max_operation_min', '240', '240', 'int', 'min', 'Najdłuższa operacja (większe zlecenia dzielone na partie)', 'Durée maximale d''une opération (les gros OF sont découpés en lots)');
