-- [369] Asystent « Zamówienia dnia » (Kierownik operacyjny, obowiązki 1.1, 1.2 et 1.8) : contrôle quotidien des commandes
-- clients nouvelles et modifiées depuis la validation précédente, signaux automatiques « Do wyjaśnienia » et résultat
-- par commande ; « Zakończ » écrit n_wizard_done (code orders_day_checked) et la tâche du poste se valide seule.
--  - n_order_check : un résultat par commande et par jour (ok / contact client / modifiée), note, auteur ;
--  - n_prod_param orders.min_lead_days : délai minimal entre la commande (ou sa modification) et l'expédition ;
--  - matrice des obligations (216) : l'assistant proposé « Zamówienia dnia » devient l'assistant orders_day_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers customer_order : les tests et les bases partielles appliquent les migrations sans elle.

CREATE TABLE IF NOT EXISTS n_order_check (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_order    INT NOT NULL COMMENT 'customer_order.id',
    day         DATE NOT NULL COMMENT 'dzień sprawdzenia (asystent « Zamówienia dnia »)',
    result      ENUM('ok','contact','changed') NOT NULL COMMENT 'ok = sprawdzone, contact = kontakt z klientem, changed = zmienione',
    note        VARCHAR(500) NULL,
    flags       VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user     INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_check (id_order, day),
    KEY idx_order_check_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Zamówienia dnia : wynik sprawdzenia zamówienia [369]';

-- Délai minimal (jours) : Ustawienia › Parametry. 1 = expédition au plus tôt le lendemain de la commande.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'orders.min_lead_days', '1', '1', 'int', 'dni',
    'Zamówienia dnia : najkrótszy termin od złożenia (albo zmiany) zamówienia do wysyłki — krótszy trafia do wyjaśnienia',
    'Commandes du jour : délai minimal entre la commande (ou sa modification) et l''expédition — plus court, à vérifier'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'orders.min_lead_days');

-- Matrice des obligations : 1.1 « Przyjmowanie i bieżąca weryfikacja zamówień » est contrôlée par le nouvel assistant.
UPDATE n_position_duty SET wizard_code = 'orders_day_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Zamówienia dnia';

-- 1.2 (kontakt z kontrahentami) et 1.8 (reagowanie na zmiany) : le même assistant les contrôle (résultats « Kontakt
-- z klientem » et « Zmienione »). Seulement si l'obligation n'a encore aucun assistant ; rythme passé à quotidien.
UPDATE n_position_duty d JOIN n_position p ON p.id = d.id_position AND p.code = 'KIEROWNIK_OPERACYJNY'
SET d.wizard_code = 'orders_day_checked', d.proposed_wizard = NULL, d.rhythm = 'daily'
WHERE d.number IN ('1.2', '1.8') AND d.wizard_code IS NULL AND d.proposed_wizard IS NULL;
