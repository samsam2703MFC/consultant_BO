-- E-mails automatiques (logistique, factures, comptabilité), branchés sur le module Komunikacja
-- existant : les messages vont dans communication_message et partent par sa file d'envoi.
-- Une règle écoute un événement (commande reçue, livraison prévue, expédition, WDT, facture,
-- proforma) et met en file un message rendu depuis un modèle de communication_template.
-- Toutes les règles sont désactivées par défaut ; en activer une ne concerne que les
-- événements postérieurs (start_ref_id), jamais l'historique.

CREATE TABLE IF NOT EXISTS tfb_mail_rule (
    id               INT NOT NULL AUTO_INCREMENT,
    code             VARCHAR(32)  NOT NULL,
    event            ENUM('order_received','delivery_planned','shipment_sent','wdt_issued','invoice_issued','proforma_issued') NOT NULL,
    template_type    VARCHAR(64)  NOT NULL,
    recipient_kind   ENUM('client_head','client_accountant','client_salesman','fixed') NOT NULL,
    fixed_address    VARCHAR(255) NULL,
    language         ENUM('client','fr','nl','pl') NOT NULL DEFAULT 'client',
    attach_document  TINYINT(1)   NOT NULL DEFAULT 1,
    days_before      TINYINT      NOT NULL DEFAULT 1,
    enabled          TINYINT(1)   NOT NULL DEFAULT 0,
    start_ref_id     BIGINT       NULL,
    enabled_at       DATETIME     NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by       VARCHAR(160) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mail_rule_code (code),
    CONSTRAINT ck_tfb_mail_rule_days CHECK (days_before BETWEEN 0 AND 14),
    CONSTRAINT ck_tfb_mail_rule_fixed CHECK (recipient_kind <> 'fixed' OR fixed_address IS NULL OR fixed_address <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Un événement traité par une règle : une seule fois (clé unique), avec le message produit
-- ou la raison pour laquelle rien n'est parti.
CREATE TABLE IF NOT EXISTS tfb_mail_dispatch (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    id_rule     INT NOT NULL,
    ref_key     VARCHAR(64) NOT NULL,
    id_message  BIGINT UNSIGNED NULL,
    status      ENUM('queued','skipped','failed') NOT NULL,
    reason      VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_mail_dispatch (id_rule, ref_key),
    KEY ix_tfb_mail_dispatch_created (created_at),
    CONSTRAINT fk_tfb_mail_dispatch_rule FOREIGN KEY (id_rule) REFERENCES tfb_mail_rule (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('mail.client_default_language', 'fr', 'fr', 'string', NULL, 'Język e-maili do klientów (fr, nl, pl)', 'Langue des e-mails aux clients (fr, nl, pl)'),
('mail.accounting_address', '', '', 'string', NULL, 'Adres biura rachunkowego (kopie dokumentów)', 'Adresse du serveur de comptabilité (copie des documents)'),
('mail.dispatch_batch', '50', '50', 'int', NULL, 'Maks. zdarzeń na regułę w jednym przebiegu', 'Événements traités au plus par règle et par passage');

INSERT IGNORE INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, sort_order) VALUES
('ORDER_CONFIRMATION', 'order_received', 'order_confirmation', 'client_head', 'client', 1, 1, 10),
('DELIVERY_PLANNED', 'delivery_planned', 'delivery_planned', 'client_head', 'client', 0, 1, 20),
('SHIPMENT_SENT', 'shipment_sent', 'shipment_sent', 'client_head', 'client', 1, 1, 30),
('WDT_TO_CLIENT', 'wdt_issued', 'wdt', 'client_accountant', 'client', 1, 1, 40),
('INVOICE_TO_CLIENT', 'invoice_issued', 'invoice', 'client_accountant', 'client', 1, 1, 50),
('ACCOUNTING_WDT', 'wdt_issued', 'accounting_document', 'fixed', 'pl', 1, 1, 60),
('ACCOUNTING_INVOICE', 'invoice_issued', 'accounting_document', 'fixed', 'pl', 1, 1, 70),
('ACCOUNTING_PROFORMA', 'proforma_issued', 'accounting_document', 'fixed', 'pl', 1, 1, 80);

-- Modèles par défaut (ajoutés seulement s'il n'y a pas déjà un modèle par défaut du même type et de la même langue).
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'order_confirmation', 'fr', 'Confirmation de commande', 'Confirmation de votre commande {{ order.number }} — livraison le {{ order.delivery_date }}',
'Bonjour {{ client.company }},\n\nnous avons bien reçu votre commande {{ order.number }} ({{ order.reference }}) : {{ order.cartons }} carton(s), livraison prévue le {{ order.delivery_date }}.\nLa proforma est jointe à ce message.\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'order_confirmation' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'order_confirmation', 'nl', 'Orderbevestiging', 'Bevestiging van uw bestelling {{ order.number }} — levering op {{ order.delivery_date }}',
'Beste {{ client.company }},\n\nwij hebben uw bestelling {{ order.number }} ({{ order.reference }}) goed ontvangen: {{ order.cartons }} doos/dozen, levering gepland op {{ order.delivery_date }}.\nDe proforma vindt u in bijlage.\n\nMet vriendelijke groet,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'order_confirmation' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_planned', 'fr', 'Livraison prévue', 'Livraison prévue le {{ delivery.date }}',
'Bonjour {{ client.company }},\n\nvotre livraison est prévue le {{ delivery.date }} : commande(s) {{ delivery.orders }}, {{ delivery.cartons }} carton(s).\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_planned' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_planned', 'nl', 'Geplande levering', 'Levering gepland op {{ delivery.date }}',
'Beste {{ client.company }},\n\nuw levering is gepland op {{ delivery.date }}: bestelling(en) {{ delivery.orders }}, {{ delivery.cartons }} doos/dozen.\n\nMet vriendelijke groet,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_planned' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'shipment_sent', 'fr', 'Expédition en route', 'Votre livraison du {{ shipment.date }} est en route',
'Bonjour {{ client.company }},\n\nvotre marchandise a quitté notre atelier le {{ shipment.date }} (bon de livraison {{ shipment.wz_number }}, facture {{ shipment.wdt_number }}).\nLe bon de livraison est joint à ce message.\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'shipment_sent' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'shipment_sent', 'nl', 'Zending onderweg', 'Uw levering van {{ shipment.date }} is onderweg',
'Beste {{ client.company }},\n\nuw goederen hebben ons atelier verlaten op {{ shipment.date }} (leveringsbon {{ shipment.wz_number }}, factuur {{ shipment.wdt_number }}).\nDe leveringsbon vindt u in bijlage.\n\nMet vriendelijke groet,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'shipment_sent' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'wdt', 'fr', 'Envoi de facture (WDT)', 'Facture {{ document.number }}',
'Bonjour {{ client.company }},\n\nveuillez trouver ci-joint la facture {{ document.number }} du {{ document.date }}, d''un montant de {{ amount.value }} {{ amount.currency }}.\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'wdt' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'wdt', 'nl', 'Factuur (WDT)', 'Factuur {{ document.number }}',
'Beste {{ client.company }},\n\nin bijlage vindt u factuur {{ document.number }} van {{ document.date }}, voor een bedrag van {{ amount.value }} {{ amount.currency }}.\n\nMet vriendelijke groet,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'wdt' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'invoice', 'fr', 'Envoi de facture', 'Facture {{ document.number }}',
'Bonjour {{ client.company }},\n\nveuillez trouver ci-joint la facture {{ document.number }} du {{ document.date }}, d''un montant de {{ amount.value }} {{ amount.currency }}.\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'invoice' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'invoice', 'nl', 'Factuur', 'Factuur {{ document.number }}',
'Beste {{ client.company }},\n\nin bijlage vindt u factuur {{ document.number }} van {{ document.date }}, voor een bedrag van {{ amount.value }} {{ amount.currency }}.\n\nMet vriendelijke groet,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'invoice' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'accounting_document', 'pl', 'Kopia dokumentu dla księgowości', '{{ document.label }} {{ document.number }} — {{ client.company }}',
'Dzień dobry,\n\nw załączniku {{ document.label }} {{ document.number }} z dnia {{ document.date }} dla {{ client.company }}, kwota {{ amount.value }} {{ amount.currency }}.\n\nWiadomość wysłana automatycznie z ERP {{ company.name }}.', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'accounting_document' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'accounting_document', 'fr', 'Copie pour la comptabilité', '{{ document.label }} {{ document.number }} — {{ client.company }}',
'Bonjour,\n\nci-joint {{ document.label }} {{ document.number }} du {{ document.date }} pour {{ client.company }}, montant {{ amount.value }} {{ amount.currency }}.\n\nMessage envoyé automatiquement par l''ERP {{ company.name }}.', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'accounting_document' AND language_code = 'fr' AND is_default = 1);
