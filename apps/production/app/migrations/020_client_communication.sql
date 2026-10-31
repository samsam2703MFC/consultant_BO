-- Szablony komunikacji z klientem oraz niezmienny audyt przygotowanych/wysłanych wiadomości.
CREATE TABLE IF NOT EXISTS communication_template (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_type VARCHAR(64) NOT NULL,
    language_code VARCHAR(8) NOT NULL DEFAULT 'fr',
    name VARCHAR(160) NOT NULL,
    subject_template VARCHAR(255) NOT NULL,
    body_template MEDIUMTEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_communication_template_lookup (template_type, language_code, is_active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_message (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_type VARCHAR(64) NOT NULL,
    template_id INT UNSIGNED NULL,
    template_version INT UNSIGNED NULL,
    id_customer INT UNSIGNED NULL,
    id_difference INT UNSIGNED NULL,
    id_wdt INT UNSIGNED NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject_text VARCHAR(255) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    variables_json JSON NOT NULL,
    was_edited TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('draft','queued','sent','failed') NOT NULL DEFAULT 'draft',
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_by VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_communication_message_customer (id_customer, created_at),
    INDEX idx_communication_message_difference (id_difference, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'payment_reminder', 'fr', 'Zwykłe przypomnienie o płatności', 'Przypomnienie o płatności — {{ invoice.number }}',
'Dzień dobry {{ client.company }},\n\nprzypominamy o konieczności uregulowania różnicy {{ difference.amount }} {{ difference.currency }} dotyczącej dokumentu {{ invoice.number }} z dnia {{ invoice.date }}.\n\nPozdrawiamy,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'payment_reminder_final', 'fr', 'Stanowcze przypomnienie o płatności', 'Pilne: płatność za {{ invoice.number }}',
'Dzień dobry {{ client.company }},\n\ndo tej pory nie otrzymaliśmy płatności różnicy {{ difference.amount }} {{ difference.currency }} dotyczącej dokumentu {{ invoice.number }} z dnia {{ invoice.date }}. Prosimy o niezwłoczne uregulowanie należności.\n\nPozdrawiamy,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder_final' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'proforma', 'fr', 'Proforma — standardowy', 'Proforma {{ document.number }}',
'Dzień dobry {{ client.company }},\n\nw załączniku przesyłamy proformę {{ document.number }} z dnia {{ document.date }} na kwotę {{ amount.value }} {{ amount.currency }}.\n\nPozdrawiamy,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma' AND language_code = 'fr' AND is_default = 1);
