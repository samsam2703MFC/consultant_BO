-- Audit 2, lot A (P9) : échecs des commandes des boutiques visibles dans Stan wysyłek. Jusqu'ici une récupération
-- refusée (adresse ou clé de boutique, HTTP), une commande rejetée à l'import ou une ligne dont le produit est inconnu
-- (ignorée sans alerte) n'allaient que dans le journal du serveur. Une ligne par cas (dedup_key) : les répétitions
-- (le collecteur tourne chaque minute) incrémentent occurrences ; le cas se ferme seul quand la boutique répond de
-- nouveau (closed_at). Les domówienia non transmises sont lues dans customer_order (supplement_push_status).
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

CREATE TABLE IF NOT EXISTS n_order_collect_issue (
    id           BIGINT NOT NULL AUTO_INCREMENT,
    id_customer  INT NULL,
    shop_uid     VARCHAR(64) NOT NULL DEFAULT '',
    kind         ENUM('config','pull','import','unknown_product') NOT NULL,
    order_key    VARCHAR(128) NULL,
    id_order     INT NULL,
    dedup_key    CHAR(64) NOT NULL,
    message      VARCHAR(500) NOT NULL DEFAULT '',
    occurrences  INT NOT NULL DEFAULT 1,
    first_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_n_order_collect_issue_dedup (dedup_key),
    KEY idx_n_order_collect_issue_open (closed_at, last_at),
    CONSTRAINT fk_n_order_collect_issue_customer FOREIGN KEY (id_customer) REFERENCES customer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Journal des actions de Stan wysyłek : deux nouvelles files (boutiques : récupération ; domówienia → boutique).
ALTER TABLE n_send_issue_action
    MODIFY source ENUM('email','franchise','shipment','monitoring','automation','shops','supplement') NOT NULL;
