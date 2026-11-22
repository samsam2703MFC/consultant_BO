-- [374] Asystent « Opóźnienia dostaw » (Kierownik operacyjny, obowiązek 3.7 : reagowanie na opóźnienia, zmiany
-- harmonogramów oraz problemy organizacyjne — LOGISTYKA) : chaque jour, les livraisons clients (customer_order, sans les
-- gratis ni les archivées) à expédier aujourd'hui et jusqu'au jour ouvré suivant, celles qui auraient dû partir (date
-- d'expédition passée sans WDT) et celles parties sans livraison confirmée (« W transporcie »), avec leur préparation
-- (palets_delivery, picking_list_generation), leur transport (LogisticsService::day : véhicule, chauffeur, départ,
-- remplissage) et la tablette chauffeur (n_delivery_stop) ; signaux « do wyjaśnienia » et une décision par livraison
-- signalée ; « Zakończ » écrit n_wizard_done (code deliveries_delays_checked) et la tâche du poste se valide seule.
-- Rien n'est écrit dans la commande : « Nowy termin » note la date, il ne la change pas. Aucun SMS automatique.
--  - n_delivery_delay_check : une décision par commande et par jour (w drodze / OK, nowy termin avec date, kontakt z
--    klientem), note, signaux et date d'expédition au moment de l'enregistrement, auteur ;
--  - n_prod_param deliveries.* : fenêtre de retard, durée maximale « W transporcie », changements de date, marge après
--    le départ (bornes dans ParamRules) ;
--  - matrice des obligations (216) : l'assistant proposé « Opóźnienia dostaw » devient deliveries_delays_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_delivery_delay_check (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_order      INT NOT NULL COMMENT 'customer_order.id (dostawa sprawdzona w asystencie « Opóźnienia dostaw »)',
    day           DATE NOT NULL COMMENT 'dzień sprawdzenia',
    decision      ENUM('ok','new_date','contact') NOT NULL COMMENT 'ok = wyjaśnione, w drodze / OK ; new_date = nowy termin (tylko zapisany, zamówienie bez zmian) ; contact = kontakt z klientem',
    new_date      DATE NULL COMMENT 'nowy termin wysyłki / dostawy uzgodniony (decision new_date) — nie jest wpisywany do zamówienia',
    note          VARCHAR(500) NULL,
    flags         VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    ship_date     DATE NULL COMMENT 'data wysyłki zamówienia w chwili zapisu',
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_delay_check (id_order, day),
    KEY idx_delivery_delay_check_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Opóźnienia dostaw : decyzja dla dostawy z sygnałem [374]';

-- Seuils : Ustawienia › Parametry (bornes dans ParamRules).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'deliveries.delay_lookback_days', '14', '14', 'int', 'dni',
    'Opóźnienia dostaw : ile dni wstecz (od daty wysyłki) szukać zamówień niewysłanych i bez potwierdzonej dostawy',
    'Retards de livraison : nombre de jours en arrière (date d''expédition) pour les commandes non parties ou sans livraison confirmée'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'deliveries.delay_lookback_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'deliveries.transit_max_days', '2', '2', 'int', 'dni',
    'Opóźnienia dostaw : zamówienie « W transporcie » bez potwierdzenia dostawy (tablet kierowcy) od tylu dni trafia do wyjaśnienia',
    'Retards de livraison : une commande « W transporcie » sans livraison confirmée (tablette chauffeur) depuis ce nombre de jours est à vérifier'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'deliveries.transit_max_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'deliveries.date_changes_max', '2', '2', 'int', NULL,
    'Opóźnienia dostaw : zamówienie, którego data wysyłki była zmieniana co najmniej tyle razy, trafia do wyjaśnienia',
    'Retards de livraison : une commande dont la date d''expédition a été changée au moins ce nombre de fois est à vérifier'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'deliveries.date_changes_max');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'deliveries.departure_grace_min', '30', '30', 'int', 'min',
    'Opóźnienia dostaw : tyle minut po godzinie wyjazdu zamówienie dnia bez WDT trafia do wyjaśnienia (nie wyjechało)',
    'Retards de livraison : minutes après l''heure de départ au-delà desquelles une commande du jour sans WDT est à vérifier (pas partie)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'deliveries.departure_grace_min');

-- Matrice des obligations : 3.7 « Reagowanie na opóźnienia, zmiany harmonogramów oraz problemy organizacyjne » (daily).
UPDATE n_position_duty SET wizard_code = 'deliveries_delays_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Opóźnienia dostaw';
