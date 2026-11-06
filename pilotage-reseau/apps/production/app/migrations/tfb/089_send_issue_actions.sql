-- Stan wysyłek [159] : journal des actions sur les envois en erreur — e-mails clients (communication_message),
-- envois aux franchisés (outbox), webhooks d'expédition (webhook_outbox_shipments), notifications de surveillance
-- (cron_notification_outbox), e-mails automatiques (n_mail_dispatch). « Ponów » remet UNE position en file pour une
-- seule tentative de plus ; « Rozwiązane » la retire de la liste à traiter avec un motif obligatoire. Rien n'est
-- envoyé depuis la page : les tâches planifiées existantes envoient. Conditionnel et rejouable (MySQL 8 / MariaDB).

CREATE TABLE IF NOT EXISTS n_send_issue_action (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    source      ENUM('email','franchise','shipment','monitoring','automation') NOT NULL,
    id_item     BIGINT NOT NULL,
    action      ENUM('retry','resolve') NOT NULL,
    reason      VARCHAR(500) NULL,
    id_user     INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_n_send_issue_action_item (source, id_item, id),
    CONSTRAINT fk_n_send_issue_action_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'send_status.stuck_minutes', '30', '30', 'int', 'min', 'Wysyłka w kolejce dłużej niż tyle minut po terminie : « czeka » (zadanie planowane nie działa?)', 'Envoi en file depuis plus de minutes après son heure : « bloqué » (tâche planifiée arrêtée ?)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'send_status.stuck_minutes');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'send_status.lookback_days', '30', '30', 'int', 'dni', 'Stan wysyłek : domyślnie błędy z ostatnich tylu dni (starsze w filtrze « wszystkie »)', 'Stan wysyłek : par défaut les erreurs des derniers jours (plus anciennes dans « toutes »)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'send_status.lookback_days');
