-- Rapport mensuel de direction [143] : rentabilité, marge, réclamations (page Analiza › Raport miesięczny, PDF PL / FR).
-- Envoi par e-mail : modèle « management_report » (PL, FR) ; envoi automatique chaque mois pour le mois précédent,
-- aux adresses du paramètre report.monthly_recipients (vide = désactivé), à partir du jour report.monthly_send_day.
-- Rejouable (MySQL 8 / MariaDB).

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'report.monthly_recipients', '', '', 'string', NULL, 'Raport miesięczny : adresy e-mail wysyłki automatycznej (przecinek ; puste = wyłączona)', 'Rapport mensuel : adresses e-mail de l''envoi automatique (virgule ; vide = désactivé)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'report.monthly_recipients');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'report.monthly_send_day', '3', '3', 'int', 'dzień', 'Raport miesięczny : dzień miesiąca wysyłki automatycznej za poprzedni miesiąc (1–28)', 'Rapport mensuel : jour du mois de l''envoi automatique du mois précédent (1–28)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'report.monthly_send_day');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'report.monthly_lang', 'pl', 'pl', 'string', NULL, 'Raport miesięczny : język PDF wysyłki automatycznej (pl, fr)', 'Rapport mensuel : langue du PDF de l''envoi automatique (pl, fr)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'report.monthly_lang');

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'management_report', 'pl', 'Raport miesięczny zarządu', 'Raport miesięczny — {{ report.period }} : sprzedaż {{ report.revenue }}, marża {{ report.margin }}',
'Dzień dobry,\n\nw załączniku raport miesięczny za {{ report.period }} (rentowność, marża, reklamacje).\n\nNajważniejsze :\n{{ report.highlights }}\n\nSprzedaż : {{ report.revenue }} · marża brutto : {{ report.margin }} · wynik produkcji : {{ report.result }} · reklamacje : {{ report.complaints }}\n\n{{ company.name }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'management_report' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'management_report', 'fr', 'Rapport mensuel de direction', 'Rapport mensuel — {{ report.period }} : CA {{ report.revenue }}, marge {{ report.margin }}',
'Bonjour,\n\nvous trouverez en pièce jointe le rapport mensuel de {{ report.period }} (rentabilité, marge, réclamations).\n\nL''essentiel :\n{{ report.highlights }}\n\nChiffre d''affaires : {{ report.revenue }} · marge brute : {{ report.margin }} · résultat de production : {{ report.result }} · réclamations : {{ report.complaints }}\n\n{{ company.name }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'management_report' AND language_code = 'fr' AND is_default = 1);
