-- [367] Reklamacje : le groupe de production lié à la réclamation (groupe du produit) confirme sur sa tablette qu'il l'a vue
-- (« Widziane ») — qui et quand, une fois par groupe ; affiché dans la liste des réclamations et dans la fenêtre de détail de
-- l'ERP. Rejouable : CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS n_complaint_seen (
    id            INT NOT NULL AUTO_INCREMENT,
    id_complaint  INT NOT NULL COMMENT 'complaint.id',
    id_group      INT NOT NULL COMMENT 'n_prod_group.id (groupe du produit)',
    id_employee   INT NOT NULL COMMENT 'n_prod_employee.id (personne connectée sur la tablette)',
    seen_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_seen (id_complaint, id_group),
    KEY idx_complaint_seen_group (id_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Reklamacje widziane przez grupę [367]';
