-- Audit 2, lot E (P5) : rappels de paiement des proformas — les niveaux 1 à reminders.auto_levels partent seuls
-- (cron mail_dispatch, modèle dans la langue du client, PDF joint, tryb MAIL_DELIVERY_MODE), les suivants restent
-- relus à la main. 0 = désactivé (valeur de départ : rien ne change tant que la direction ne l'active pas).
-- Rejouable (MySQL 8 / MariaDB).

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'reminders.auto_levels', '0', '0', 'int', NULL,
    'Przypomnienia płatności proform : poziomy wysyłane automatycznie (0 = wyłączone, 1 = poziom 1, 2 = poziomy 1–2 ; 3–5 zawsze ręcznie)',
    'Rappels de paiement des proformas : niveaux envoyés automatiquement (0 = désactivé, 1 = niveau 1, 2 = niveaux 1–2 ; 3–5 toujours relus)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'reminders.auto_levels');
