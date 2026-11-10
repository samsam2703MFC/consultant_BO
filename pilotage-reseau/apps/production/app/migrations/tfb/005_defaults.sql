-- Module « Gestion de production atelier » — valeurs par défaut [PARAM], rôles, groupes et shifts.
-- INSERT IGNORE : une valeur déjà modifiée en base n'est jamais écrasée.

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('auth.access_ttl_s', '900', '900', 'int', 's', 'Ważność tokenu dostępu', 'Durée de validité du jeton d''accès'),
('auth.refresh_ttl_days', '30', '30', 'int', 'd', 'Ważność tokenu odświeżania', 'Durée de validité du jeton de renouvellement'),
('auth.max_failed_logins', '10', '10', 'int', NULL, 'Maks. nieudanych logowań w oknie czasowym', 'Échecs de connexion tolérés dans la fenêtre'),
('auth.lockout_window_min', '15', '15', 'int', 'min', 'Okno liczenia nieudanych logowań', 'Fenêtre de comptage des échecs de connexion'),
('api.default_language', 'pl', 'pl', 'string', NULL, 'Domyślny język komunikatów API', 'Langue par défaut des messages de l''API'),
('api.page_size_max', '200', '200', 'int', NULL, 'Maks. liczba elementów na stronie', 'Nombre maximal d''éléments par page'),
('workstation.capacity_units', '["piece","plaque","kg","chariot","bac","litre"]', '["piece","plaque","kg","chariot","bac","litre"]', 'json', NULL, 'Jednostki pojemności stanowisk', 'Unités de capacité des postes'),
('load.target_pct', '95', '95', 'decimal', '%', 'Docelowe obciążenie zespołu', 'Taux de charge cible d''une équipe'),
('load.green_min_pct', '90', '90', 'decimal', '%', 'Dolny próg zielonego obciążenia', 'Seuil bas de la charge verte'),
('load.green_max_pct', '100', '100', 'decimal', '%', 'Górny próg zielonego obciążenia', 'Seuil haut de la charge verte'),
('shift.night_premium_pct', '20', '20', 'decimal', '%', 'Dodatek nocny', 'Prime de nuit'),
('shift.night_staff_ratio', '0.70', '0.70', 'decimal', NULL, 'Obsada nocna względem dziennej', 'Effectif de nuit rapporté au jour'),
('optimizer.levers_order', '["advance_mts","alternative_workstation","reassign_polyvalent","open_close_shift","overtime"]', '["advance_mts","alternative_workstation","reassign_polyvalent","open_close_shift","overtime"]', 'json', NULL, 'Kolejność dźwigni optymalizatora zmian', 'Ordre des leviers de l''optimiseur de shifts'),
('planning.consumption_weeks', '6', '6', 'int', 'wk', 'Liczba tygodni wydań do sklepów jako baza zużycia', 'Semaines de sorties vers les boutiques servant de base de consommation'),
('planning.horizon_firm_weeks', '1', '1', 'int', 'wk', 'Horyzont planu stałego', 'Horizon ferme du plan'),
('planning.horizon_forecast_weeks', '3', '3', 'int', 'wk', 'Horyzont prognozy', 'Horizon prévisionnel du plan'),
('planning.default_cutoff_days_before', '2', '2', 'int', 'd', 'Zamknięcie zamówień: dni przed dostawą', 'Clôture des commandes : jours avant livraison'),
('planning.default_cutoff_time', '12:00', '12:00', 'time', NULL, 'Godzina zamknięcia zamówień', 'Heure de clôture des commandes'),
('planning.default_departure_time', '20:00', '20:00', 'time', NULL, 'Godzina wyjazdu ciężarówki (D-1)', 'Heure de départ du camion (J-1)'),
('planning.loading_time_min', '60', '60', 'int', 'min', 'Czas pakowania i załadunku przed wyjazdem', 'Temps de conditionnement et de chargement avant départ'),
('planning.default_coverage_days', '7', '7', 'decimal', 'd', 'Domyślne pokrycie zapasu (dni)', 'Couverture de stock cible par défaut (jours)'),
('planning.coverage_days_by_group', '{}', '{}', 'json', 'd', 'Pokrycie zapasu wg grupy (kod grupy → dni)', 'Couverture cible par groupe (code du groupe → jours)'),
('timing.moving_average_n', '5', '5', 'int', NULL, 'Liczba pomiarów średniej kroczącej czasów', 'Nombre de mesures de la moyenne glissante des temps');

INSERT IGNORE INTO tfb_prod_role (code, name_pl, name_fr) VALUES
('prod_admin', 'Administrator produkcji', 'Administrateur production'),
('prod_manager', 'Kierownik produkcji', 'Responsable production'),
('team_lead', 'Brygadzista', 'Chef d''équipe'),
('direction', 'Dyrekcja', 'Direction'),
('operator', 'Operator', 'Opérateur');

INSERT IGNORE INTO tfb_prod_role_permission (role_code, permission) VALUES
('prod_admin', 'prod.referentiels.read'), ('prod_admin', 'prod.referentiels.write'),
('prod_admin', 'prod.params.write'), ('prod_admin', 'prod.employees.write'),
('prod_admin', 'prod.products.read'), ('prod_admin', 'prod.products.write'),
('prod_admin', 'prod.plan.read'), ('prod_admin', 'prod.plan.validate'),
('prod_admin', 'prod.gantt.move'), ('prod_admin', 'prod.kpis.read'), ('prod_admin', 'prod.execution.write'),
('prod_manager', 'prod.referentiels.read'), ('prod_manager', 'prod.referentiels.write'),
('prod_manager', 'prod.params.write'), ('prod_manager', 'prod.employees.write'),
('prod_manager', 'prod.products.read'), ('prod_manager', 'prod.products.write'),
('prod_manager', 'prod.plan.read'), ('prod_manager', 'prod.plan.validate'),
('prod_manager', 'prod.gantt.move'), ('prod_manager', 'prod.kpis.read'), ('prod_manager', 'prod.execution.write'),
('team_lead', 'prod.referentiels.read'), ('team_lead', 'prod.products.read'), ('team_lead', 'prod.plan.read'),
('team_lead', 'prod.gantt.move'), ('team_lead', 'prod.kpis.read'), ('team_lead', 'prod.execution.write'),
('direction', 'prod.referentiels.read'), ('direction', 'prod.products.read'), ('direction', 'prod.plan.read'),
('direction', 'prod.kpis.read'),
('operator', 'prod.referentiels.read'), ('operator', 'prod.execution.write');

INSERT IGNORE INTO tfb_prod_group (code, name_pl, name_fr, color, sort_order, id_section) VALUES
('LEVAIN', 'Zakwas i miesienie', 'Levain & Pétrissage', '#8D1D2C', 10,
    (SELECT id FROM production_sections WHERE name = 'Piekarnia' LIMIT 1)),
('FACONNAGE', 'Formowanie pieczywa', 'Façonnage boulangerie', '#B5651D', 20,
    (SELECT id FROM production_sections WHERE name = 'Piekarnia' LIMIT 1)),
('VIENNOISERIE', 'Wiennoiserie', 'Viennoiserie', '#C9A227', 30,
    (SELECT id FROM production_sections WHERE name = 'Rogal' LIMIT 1)),
('PATISSERIE', 'Cukiernia i ciastka', 'Pâtisserie & Biscuiterie', '#6D4C8F', 40,
    (SELECT id FROM production_sections WHERE name = 'Cukiernia' LIMIT 1)),
('TRAITEUR', 'Garmażerka', 'Traiteur', '#2E7D4F', 50,
    (SELECT id FROM production_sections WHERE name = 'Garmazeria' LIMIT 1)),
('CONDITIONNEMENT', 'Pakowanie i wysyłka', 'Conditionnement & Expédition', '#4A6FA5', 60,
    (SELECT id FROM production_sections WHERE name LIKE 'Pakowanie%' LIMIT 1));

INSERT IGNORE INTO tfb_prod_shift_template (code, name_pl, name_fr, start_time, end_time, active_days, premium_pct, is_night, sort_order) VALUES
('JOUR', 'Dzienna', 'Jour', '06:00:00', '14:00:00', '1,2,3,4,5,6', 0, 0, 10),
('APRES_MIDI', 'Popołudniowa', 'Après-midi', '14:00:00', '22:00:00', '1,2,3,4,5,6', 0, 0, 20),
('NUIT', 'Nocna', 'Nuit', '22:00:00', '06:00:00', '1,2,3,4,5,6', 20, 1, 30);
