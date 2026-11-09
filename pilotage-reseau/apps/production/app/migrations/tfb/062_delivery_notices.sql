-- Logistyka : informacja dla klienta o dostawie (kierowca, telefon, pojazd, trasa, przystanek, godzina, palety),
-- e-mail albo SMS w języku klienta. Historia powiadomień ; SMS w trybie zapisu, dopóki nie ma dostawcy SMS.

CREATE TABLE IF NOT EXISTS tfb_delivery_notice (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ship_date   DATE NOT NULL,
    id_customer INT NOT NULL,
    channel     VARCHAR(8) NOT NULL COMMENT 'mail, sms',
    language    CHAR(2) NOT NULL,
    recipient   VARCHAR(190) NOT NULL,
    subject     VARCHAR(255) NULL,
    body        TEXT NOT NULL,
    status      VARCHAR(16) NOT NULL COMMENT 'queued (e-mail w kolejce), logged (SMS zapisany, bez dostawcy), sent, failed',
    id_message  INT NULL COMMENT 'communication_message (e-mail)',
    provider    VARCHAR(40) NULL,
    error       VARCHAR(255) NULL,
    created_by  VARCHAR(160) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_delivery_notice_day (ship_date, id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('sms.provider', 'log_only', 'log_only', 'string', NULL, 'Dostawca SMS (log_only = tylko zapis w historii, bez wysyłki)', 'Fournisseur SMS (log_only = journal seulement, pas d''envoi)');

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details', 'fr', 'Informations de livraison', 'Votre livraison du {{ delivery.date }} — chauffeur et palettes',
'Bonjour {{ client.company }},\n\nvoici les informations pour votre livraison du {{ delivery.date }} :\n\n{{ delivery.details }}\n\nMerci de prévoir l''accès au quai et une personne pour réceptionner la marchandise. En cas d''empêchement, répondez simplement à ce message.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details', 'nl', 'Leveringsinformatie', 'Uw levering van {{ delivery.date }} — chauffeur en pallets',
'Beste {{ client.company }},\n\nhier vindt u de informatie over uw levering van {{ delivery.date }}:\n\n{{ delivery.details }}\n\nGelieve de toegang tot de laadkade en iemand voor de ontvangst te voorzien. Bij verhindering kunt u gewoon op dit bericht antwoorden.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details', 'pl', 'Informacja o dostawie', 'Dostawa {{ delivery.date }} — kierowca i palety',
'Szanowni Państwo,\n\nprzesyłamy informacje o dostawie {{ delivery.date }}:\n\n{{ delivery.details }}\n\nProsimy o zapewnienie dojazdu do rampy i osoby do odbioru towaru. W razie przeszkód prosimy po prostu odpowiedzieć na tę wiadomość.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details', 'en', 'Delivery information', 'Your delivery of {{ delivery.date }} — driver and pallets',
'Hello {{ client.company }},\n\nhere is the information about your delivery of {{ delivery.date }}:\n\n{{ delivery.details }}\n\nPlease make sure the unloading area is accessible and someone is there to receive the goods. If anything prevents it, simply reply to this message.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details_sms', 'fr', 'SMS : informations de livraison', 'SMS',
'L''Atelier By / SL Food — livraison {{ delivery.date }} : {{ delivery.sms_details }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details_sms' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details_sms', 'nl', 'SMS: leveringsinformatie', 'SMS',
'L''Atelier By / SL Food — levering {{ delivery.date }}: {{ delivery.sms_details }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details_sms' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details_sms', 'pl', 'SMS: informacja o dostawie', 'SMS',
'L''Atelier By / SL Food — dostawa {{ delivery.date }}: {{ delivery.sms_details }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details_sms' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_details_sms', 'en', 'SMS: delivery information', 'SMS',
'L''Atelier By / SL Food — delivery {{ delivery.date }}: {{ delivery.sms_details }}', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_details_sms' AND language_code = 'en' AND is_default = 1);
