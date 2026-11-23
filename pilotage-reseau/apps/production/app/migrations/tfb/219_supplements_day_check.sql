-- [371] Asystent « Domówienia dnia » (Kierownik operacyjny, obowiązek 1.7 : koordynowanie przepływu informacji pomiędzy
-- odbiorcami a produkcją — domówienia) : chaque jour, les domówienia (customer_order.order_type = 'SUPPLEMENT') passées
-- depuis la validation précédente ou à expédier d'ici le jour ouvré suivant, avec leur état côté production (zlecenie
-- du plan, plan validé avant elles, proposition du plan sur le vif, stock, gratis hors quantités) ; signaux « do
-- wyjaśnienia » et un résultat par domówienie signalée ; « Zakończ » écrit n_wizard_done (code supplements_day_checked)
-- et la tâche du poste se valide seule. Rien n'est envoyé aux clients ; ni les commandes ni la production ne sont modifiées.
--  - n_supplement_check : un résultat par domówienie et par jour (przekazane do produkcji / kontakt z klientem /
--    przesunięte albo odrzucone), note, signaux et état production au moment de l'enregistrement, auteur ;
--  - n_prod_param orders.supplement_cutoff : heure limite (HH:MM) du jour de production précédant l'expédition ;
--  - matrice des obligations (216) : l'assistant proposé « Domówienia dnia » devient supplements_day_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers customer_order : les tests et les bases partielles appliquent les migrations sans elle.

CREATE TABLE IF NOT EXISTS n_supplement_check (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_order         INT NOT NULL COMMENT 'customer_order.id (domówienie, order_type SUPPLEMENT)',
    day              DATE NOT NULL COMMENT 'dzień sprawdzenia (asystent « Domówienia dnia »)',
    result           ENUM('sent','contact','moved') NOT NULL COMMENT 'sent = przekazane do produkcji, contact = kontakt z klientem, moved = przesunięte / odrzucone',
    note             VARCHAR(500) NULL,
    flags            VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    production_state VARCHAR(20) NULL COMMENT 'stan po stronie produkcji w chwili zapisu (in_plan, live, stock, missing, freebie, unplanned)',
    id_user          INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplement_check (id_order, day),
    KEY idx_supplement_check_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Domówienia dnia : wynik sprawdzenia domówienia [371]';

-- Heure limite : Ustawienia › Parametry. Une domówienie passée après cette heure du jour de production précédant
-- l'expédition (ou plus tard) est à vérifier.
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'orders.supplement_cutoff', '12:00', '12:00', 'time', NULL,
    'Domówienia dnia : godzina graniczna w dniu produkcji przed wysyłką — domówienie złożone później trafia do wyjaśnienia',
    'Domówienia du jour : heure limite du jour de production avant l''expédition — une domówienie passée plus tard est à vérifier'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'orders.supplement_cutoff');

-- Matrice des obligations : 1.7 « Koordynowanie przepływu informacji pomiędzy odbiorcami a produkcją (domówienia) ».
UPDATE n_position_duty SET wizard_code = 'supplements_day_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Domówienia dnia';
