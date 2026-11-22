-- [370] Asystent « Klienci bez zamówienia » (Kierownik operacyjny, obowiązek 1.3 : monitorowanie terminowości składania
-- zamówień i kontakt z odbiorcami w przypadku ich braku) : chaque jour, les clients actifs réguliers (rythme habituel des
-- 8 dernières semaines : jours de commande récurrents, sinon intervalle moyen) dont la commande attendue n'est pas
-- arrivée ; un résultat par client et par commande attendue ; « Zakończ » écrit n_wizard_done (code
-- customers_ordered_checked) et la tâche du poste se valide seule. Rien n'est envoyé aux clients.
--  - n_customer_no_order : un résultat par client et par date attendue (kontakt + notatka / zamówienie przyszło /
--    przerwa, avec une date de fin facultative), jour de la saisie, auteur ;
--  - n_prod_param orders.regular_min_orders, orders.weekday_share_pct, orders.missing_grace_days : seuils réglables ;
--  - matrice des obligations (216) : l'assistant proposé « Klienci bez zamówienia » devient customers_ordered_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers customer : les tests et les bases partielles appliquent les migrations sans elle.

CREATE TABLE IF NOT EXISTS n_customer_no_order (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_customer   INT NOT NULL COMMENT 'customer.id',
    expected_on   DATE NOT NULL COMMENT 'dzień, w którym klient zwykle składa zamówienie (z rytmu 8 tygodni)',
    day           DATE NOT NULL COMMENT 'dzień zapisu wyniku (asystent « Klienci bez zamówienia »)',
    result        ENUM('contacted','arrived','paused') NOT NULL COMMENT 'contacted = kontakt z klientem, arrived = zamówienie przyszło, paused = brak zamówienia w planie',
    note          VARCHAR(500) NULL,
    paused_until  DATE NULL COMMENT 'przerwa : do tego dnia włącznie klient nie trafia do kontaktu',
    rhythm        VARCHAR(80) NULL COMMENT 'rytm klienta w chwili zapisu',
    id_user       INT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_no_order (id_customer, expected_on),
    KEY idx_customer_no_order_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Klienci bez zamówienia : wynik dla oczekiwanego zamówienia [370]';

-- Seuils : Ustawienia › Parametry.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'orders.regular_min_orders', '4', '4', 'int', 'zam.',
    'Klienci bez zamówienia : najmniej dni z zamówieniem w 8 tygodniach, żeby klient był stały (sprawdzany)',
    'Clients sans commande : nombre minimal de jours de commande sur 8 semaines pour qu''un client soit régulier (suivi)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'orders.regular_min_orders');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'orders.weekday_share_pct', '50', '50', 'int', '%',
    'Klienci bez zamówienia : dzień tygodnia jest stałym dniem zamówień, gdy klient zamawiał w ten dzień w co najmniej tylu % tygodni',
    'Clients sans commande : un jour de la semaine est un jour de commande habituel si le client a commandé ce jour-là dans au moins ce % des semaines'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'orders.weekday_share_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'orders.missing_grace_days', '1', '1', 'int', 'dni',
    'Klienci bez zamówienia : ile dni po oczekiwanym dniu zamówienia klient trafia do kontaktu (0 = już w tym samym dniu)',
    'Clients sans commande : jours après le jour de commande attendu avant que le client soit à contacter (0 = le jour même)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'orders.missing_grace_days');

-- Matrice des obligations : 1.3 « Monitorowanie terminowości składania zamówień » est contrôlée par le nouvel assistant.
UPDATE n_position_duty SET wizard_code = 'customers_ordered_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Klienci bez zamówienia';
