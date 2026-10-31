-- Alertes et échéances [153] : délais au-delà desquels la cloche de l'ERP prévient (réclamation sans décision, escalade)
-- et jour du mois à partir duquel les coûts du mois précédent doivent être saisis. Le contrôle tourne avec
-- Bin/mail_dispatch.php ; chaque alerte n'est publiée qu'une fois (clé de dédoublonnage).
-- Conditionnel et rejouable (MySQL 8 / MariaDB).

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'alerts.complaint_days', '3', '3', 'int', 'dni', 'Reklamacja bez decyzji (nowa albo w weryfikacji) dłużej niż tyle dni : alert w dzwonku', 'Réclamation sans décision (nouvelle ou en vérification) depuis plus de tant de jours : alerte dans la cloche'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'alerts.complaint_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'alerts.complaint_escalate_days', '7', '7', 'int', 'dni', 'Reklamacja bez decyzji dłużej niż tyle dni : alert pilny (czerwony)', 'Réclamation sans décision depuis plus de tant de jours : alerte urgente (rouge)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'alerts.complaint_escalate_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'alerts.cost_due_day', '10', '10', 'int', 'dzień miesiąca', 'Od tego dnia miesiąca : alert, gdy koszty poprzedniego miesiąca (personel) nie są wprowadzone', 'À partir de ce jour du mois : alerte si les coûts du mois précédent (personnel) ne sont pas saisis'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'alerts.cost_due_day');
