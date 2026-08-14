-- ============================================================================
-- Cockpit CEO — compte MySQL applicatif (moindre privilège).
-- À exécuter UNE FOIS par le DBA (compte root/admin), après schema.sql.
--
-- Remplacez CHANGEZ_MOI par le mot de passe généré (voir docs/DEPLOIEMENT.md).
-- ============================================================================

CREATE USER IF NOT EXISTS 'cockpit_app'@'localhost' IDENTIFIED BY 'CHANGEZ_MOI';

-- Tables propres au cockpit : lecture/écriture.
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_shop               TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_shop_month_perf    TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_shop_budget        TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_shop_budget_month  TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_shop_budget_line   TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_network_target     TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project            TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_levid      TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_milestone  TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_cost       TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_task       TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_crm        TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_consultant         TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_consultant_visit   TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_supplier           TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_person             TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_report_schedule    TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_alert_rule         TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_email_template     TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_project_template   TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_journal_entry      TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_app_setting        TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_product            TO 'cockpit_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON atelierby_db.ceo_product_month_sales TO 'cockpit_app'@'localhost';

-- Tables du Manuel Opératoire / panel consultant : LECTURE SEULE.
GRANT SELECT ON atelierby_db.of_tag           TO 'cockpit_app'@'localhost';
GRANT SELECT ON atelierby_db.kpi              TO 'cockpit_app'@'localhost';
GRANT SELECT ON atelierby_db.position         TO 'cockpit_app'@'localhost';
GRANT SELECT ON atelierby_db.mac_report_share TO 'cockpit_app'@'localhost';

-- Exception : PUT /parametres/seuil-* met à jour les seuils dans `kpi`.
-- Décommentez si vous voulez autoriser cette écriture depuis le cockpit :
-- GRANT UPDATE (seuil_haut) ON atelierby_db.kpi TO 'cockpit_app'@'localhost';

FLUSH PRIVILEGES;
