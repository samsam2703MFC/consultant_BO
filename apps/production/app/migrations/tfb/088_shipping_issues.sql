-- File des écarts d'expédition [155] : une ligne par écart à régler avec le client — document de różnice (proforma
-- payée ↔ WDT, montant à rembourser ou à faire payer) ou problème signalé par le chauffeur (fermé, refus, casse,
-- manquant, retard). Chaque écart a un responsable (utilisateur ERP), une échéance de décision, une décision
-- (remboursement, déduction sur la prochaine commande, dopłata, renvoi, nouvelle livraison, réclamation, correction,
-- sans suite) et un statut (à décider → en cours → clos). Alimentée par Bin/mail_dispatch.php et à l'ouverture de la page.
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

CREATE TABLE IF NOT EXISTS n_shipping_issue (
    id               INT NOT NULL AUTO_INCREMENT,
    source           ENUM('difference','delivery') NOT NULL,
    id_difference    INT NULL,
    id_delivery_stop INT NULL,
    id_customer      INT NULL,
    opened_on        DATE NOT NULL,
    due_on           DATE NOT NULL,
    id_owner         INT NULL,
    status           ENUM('open','decided','done') NOT NULL DEFAULT 'open',
    decision         ENUM('refund','credit_next','collect','resend','redeliver','complaint','correction','waive') NULL,
    note             TEXT NULL,
    decided_by       INT NULL,
    decided_at       DATETIME NULL,
    closed_by        INT NULL,
    closed_at        DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_shipping_issue_difference (id_difference),
    UNIQUE KEY uq_n_shipping_issue_stop (id_delivery_stop),
    KEY idx_n_shipping_issue_status (status, due_on),
    KEY idx_n_shipping_issue_owner (id_owner, status),
    CONSTRAINT fk_n_shipping_issue_difference FOREIGN KEY (id_difference) REFERENCES document_order_shipping_difference (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_shipping_issue_stop FOREIGN KEY (id_delivery_stop) REFERENCES n_delivery_stop (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_shipping_issue_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_shipping_issue_owner FOREIGN KEY (id_owner) REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_shipping_issue_decided_by FOREIGN KEY (decided_by) REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT fk_n_shipping_issue_closed_by FOREIGN KEY (closed_by) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'shipping_issue.decision_days', '3', '3', 'int', 'dni', 'Różnica wysyłki lub problem przy dostawie : ile dni na decyzję (potem alert w dzwonku)', 'Écart d''expédition ou problème de livraison : jours pour décider (ensuite alerte dans la cloche)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'shipping_issue.decision_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'shipping_issue.min_amount', '1', '1', 'decimal', 'EUR', 'Różnica proforma ↔ WDT poniżej tej kwoty nie trafia do kolejki', 'Écart proforma ↔ WDT sous ce montant : pas dans la file'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'shipping_issue.min_amount');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'shipping_issue.lookback_days', '45', '45', 'int', 'dni', 'Kolejka bierze różnice i problemy z ostatnich tylu dni (starsze uznane za rozliczone)', 'La file reprend les écarts et problèmes des derniers jours (plus anciens : considérés réglés)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'shipping_issue.lookback_days');
