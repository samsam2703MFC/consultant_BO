-- [382] Asystent « Powiadomienia o dostawie » (codziennie ; matrice des obligations 224 : LOGISTYKA 4 « Informacja o dostawie
-- dla klientów ») : chaque jour, les clients du jour d'expédition (LogisticsService::day, même liste que Dzień wysyłki et
-- Logistyka › Powiadomienia klientów) — et ceux du jour ouvré suivant quand l'automate (règle n_mail_rule « delivery_planned »
-- active, system.auto_send ≠ 0) envoie l'avis la veille (days_before) — avec l'état de leur avis de livraison :
--   - n_delivery_notice (migration 062) : ship_date, id_customer, channel 'mail' / 'sms', status queued / logged / sent /
--     failed, error, id_message (communication_message.status 'failed' = e-mail rejeté par la file) ;
--   - « powiadomiony » comme l'étape 3 de la barre Dzień wysyłki (ShippingDayRepository::notifiedCustomers : status hors
--     'failed' et 'logged') ;
--   - contact du client comme l'écran Powiadomienia klientów (e-mail email_head sinon email_salesman, téléphone phone_head
--     sinon phone_alt).
-- Un client est à expliquer quand un avis du jour est en échec (e-mail ou SMS), ou qu'il n'est pas encore powiadomiony
-- (aucun avis, ou SMS seulement enregistré sans envoi), ou qu'il n'a ni e-mail ni téléphone. Une décision par client et
-- par jour d'expédition — « Wysłane ponownie » (accepté seulement si un avis réussi existe maintenant, après le dernier
-- échec), « Kontakt telefoniczny » (note obligatoire), « Bez powiadomienia — uzasadnione » (note obligatoire) —, puis
-- « Zakończ » écrit n_wizard_done (code delivery_notices_checked) et la tâche du poste se valide seule.
-- L'assistant n'envoie rien (ni e-mail ni SMS) : il renvoie vers Logistyka › Powiadomienia klientów. Lecture seule de
-- l'ERP : aucun avis, client ni message modifié.
--  - n_delivery_notice_check : une décision par (jour d'expédition, client) ; signaux au moment de l'enregistrement ;
--  - matrice des obligations (224) : l'assistant proposé « Powiadomienia o dostawie » devient delivery_notices_checked.
-- Rejouable : CREATE TABLE IF NOT EXISTS, UPDATE limité aux lignes encore « proposées ».
-- Pas de clé étrangère vers les tables de l'ERP : les tests et les bases partielles appliquent les migrations sans elles.

CREATE TABLE IF NOT EXISTS n_delivery_notice_check (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ship_date    DATE NOT NULL COMMENT 'dzień wysyłki (n_delivery_notice.ship_date)',
    id_customer  INT NOT NULL COMMENT 'customer.id',
    checked_on   DATE NOT NULL COMMENT 'dzień kontroli (asystent dnia)',
    decision     ENUM('resent','phone','justified') NOT NULL COMMENT 'resent = wysłane ponownie (sprawdzone) ; phone = kontakt telefoniczny ; justified = bez powiadomienia — uzasadnione',
    note         VARCHAR(500) NULL COMMENT 'wymagana dla phone i justified',
    id_notice    INT NULL COMMENT 'n_delivery_notice.id udanego powiadomienia (resent)',
    flags        VARCHAR(255) NULL COMMENT 'sygnały w chwili zapisu (kody, przecinek)',
    id_user      INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_notice_check (ship_date, id_customer),
    KEY idx_delivery_notice_check_day (checked_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Powiadomienia o dostawie : decyzja dla klienta w dniu wysyłki [382]';

-- Matrice des obligations : LOGISTYKA 4 « Informacja o dostawie dla klientów » (daily).
UPDATE n_position_duty SET wizard_code = 'delivery_notices_checked', proposed_wizard = NULL WHERE proposed_wizard = 'Powiadomienia o dostawie';
