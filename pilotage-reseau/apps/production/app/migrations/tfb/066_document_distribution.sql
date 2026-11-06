-- Dystrybucja dokumentów : jedna tabela « kto dostaje który dokument, jak (e-mail / papier z dostawą), na jaki adres
-- i kiedy ». Wiersz 0 = ustawienia domyślne (nowi klienci), wiersz -1 = biuro rachunkowe SL Food (kopie).
-- E-maile automatyczne (tfb_mail_rule) : odbiorcy według tej tabeli (recipient_kind = 'distribution') ;
-- reguła działa, gdy któryś dokument ma jej moment, od chwili włączenia (bez historii).

CREATE TABLE IF NOT EXISTS tfb_doc_distribution (
    id_customer INT NOT NULL,
    doc_key VARCHAR(20) NOT NULL,
    mail_to VARCHAR(10) NOT NULL DEFAULT '',
    mail_address VARCHAR(190) NULL,
    paper_copies TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sms TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_customer, doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tfb_doc_distribution_moment (
    doc_key VARCHAR(20) NOT NULL PRIMARY KEY,
    moment VARCHAR(30) NOT NULL,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Odcisk cennika klienta : zmiana odcisku = « zmiana cen » (pierwszy odczyt tylko zapisuje stan).
CREATE TABLE IF NOT EXISTS tfb_price_list_state (
    id_customer INT NOT NULL PRIMARY KEY,
    hash CHAR(32) NOT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tfb_doc_distribution_moment (doc_key, moment, updated_by) VALUES
    ('proforma', 'order_received', 'migration'), ('wdt', 'wdt_ksef_accepted', 'migration'), ('invoice', 'invoice_issued', 'migration'),
    ('wz', 'shipment_sent', 'migration'), ('cmr', 'shipment_sent', 'migration'), ('diff', 'difference_issued', 'migration'),
    ('ppwr', 'shipment_sent', 'migration'), ('notice', 'delivery_planned', 'migration'), ('price', 'price_list_changed', 'migration'),
    ('summary', 'month_start', 'migration'), ('goal', 'month_start', 'migration');

-- Ustawienia domyślne (zatwierdzone) : WZ ×2 i CMR ×3 na papierze z dostawą, reszta e-mailem.
INSERT IGNORE INTO tfb_doc_distribution (id_customer, doc_key, mail_to, paper_copies, sms, updated_by) VALUES
    (0, 'proforma', 'head', 0, 0, 'migration'), (0, 'wdt', 'acc', 0, 0, 'migration'), (0, 'invoice', 'acc', 0, 0, 'migration'),
    (0, 'wz', '', 2, 0, 'migration'), (0, 'cmr', '', 3, 0, 'migration'), (0, 'diff', 'head', 0, 0, 'migration'),
    (0, 'ppwr', 'head', 0, 0, 'migration'), (0, 'notice', 'head', 0, 1, 'migration'), (0, 'price', 'head', 0, 0, 'migration'),
    (0, 'summary', '', 0, 0, 'migration'), (0, 'goal', '', 0, 0, 'migration');

-- Biuro rachunkowe SL Food : kopie proformy, faktury WDT i faktury (adres z ustawień, gdy pusty).
INSERT IGNORE INTO tfb_doc_distribution (id_customer, doc_key, mail_to, paper_copies, sms, updated_by) VALUES
    (-1, 'proforma', 'other', 0, 0, 'migration'), (-1, 'wdt', 'other', 0, 0, 'migration'), (-1, 'invoice', 'other', 0, 0, 'migration');

-- Aktywni klienci : ustawienia domyślne ; franczyzobiorcy dostają też podsumowanie sprzedaży i cel obrotu.
INSERT IGNORE INTO tfb_doc_distribution (id_customer, doc_key, mail_to, paper_copies, sms, updated_by)
SELECT c.id, d.doc_key,
       IF(d.doc_key IN ('summary', 'goal'), IF(c.franchise_network_id IS NOT NULL OR c.type = 1, 'head', ''), d.mail_to),
       d.paper_copies, d.sms, 'migration'
FROM customer c CROSS JOIN tfb_doc_distribution d
WHERE d.id_customer = 0 AND c.active = 1;

-- Klienci « (O.S.) » : faktura WDT także na papierze z dostawą (makieta zatwierdzona).
UPDATE tfb_doc_distribution d JOIN customer c ON c.id = d.id_customer
SET d.paper_copies = 1
WHERE d.doc_key = 'wdt' AND d.updated_by = 'migration' AND c.company LIKE '%(O.S.)%';

-- E-mail « wysyłka w drodze » : załączniki według dystrybucji (lista w treści zamiast « WZ w załączniku »).
UPDATE communication_template SET body_template = REPLACE(body_template, 'Le bon de livraison est joint à ce message.', 'Documents joints : {{ documents.list }}.')
WHERE template_type = 'shipment_sent' AND language_code = 'fr';
UPDATE communication_template SET body_template = REPLACE(body_template, 'De leveringsbon vindt u in bijlage.', 'In bijlage: {{ documents.list }}.')
WHERE template_type = 'shipment_sent' AND language_code = 'nl';
UPDATE communication_template SET body_template = REPLACE(body_template, 'Dokument WZ znajduje się w załączniku.', 'W załączniku: {{ documents.list }}.')
WHERE template_type = 'shipment_sent' AND language_code = 'pl';
UPDATE communication_template SET body_template = REPLACE(body_template, 'The delivery note is attached.', 'Attached: {{ documents.list }}.')
WHERE template_type = 'shipment_sent' AND language_code = 'en';

-- Reguły e-maili : nowe zdarzenia i odbiorca « według dystrybucji ».
ALTER TABLE tfb_mail_rule MODIFY event ENUM('order_received', 'delivery_planned', 'shipment_sent', 'wdt_issued', 'invoice_issued', 'proforma_issued',
    'wdt_ksef_accepted', 'difference_issued', 'price_list_changed', 'month_start') NOT NULL;
ALTER TABLE tfb_mail_rule MODIFY recipient_kind ENUM('client_head', 'client_accountant', 'client_salesman', 'fixed', 'distribution') NOT NULL;

UPDATE tfb_mail_rule SET recipient_kind = 'distribution', language = 'client', updated_by = 'migration'
WHERE code IN ('ORDER_CONFIRMATION', 'DELIVERY_PLANNED', 'SHIPMENT_SENT', 'WDT_TO_CLIENT', 'INVOICE_TO_CLIENT');

INSERT INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, enabled, sort_order, updated_by)
SELECT 'PROFORMA_TO_CLIENT', 'proforma_issued', 'proforma', 'distribution', 'client', 1, 1, 0, 25, 'migration' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tfb_mail_rule WHERE code = 'PROFORMA_TO_CLIENT');
INSERT INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, enabled, sort_order, updated_by)
SELECT 'WDT_KSEF_TO_CLIENT', 'wdt_ksef_accepted', 'wdt', 'distribution', 'client', 1, 1, 0, 45, 'migration' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tfb_mail_rule WHERE code = 'WDT_KSEF_TO_CLIENT');
INSERT INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, enabled, sort_order, updated_by)
SELECT 'DIFFERENCE_TO_CLIENT', 'difference_issued', 'delivery_difference', 'distribution', 'client', 1, 1, 0, 55, 'migration' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tfb_mail_rule WHERE code = 'DIFFERENCE_TO_CLIENT');
INSERT INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, enabled, sort_order, updated_by)
SELECT 'PRICE_LIST_TO_CLIENT', 'price_list_changed', 'price_list', 'distribution', 'client', 1, 1, 0, 65, 'migration' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tfb_mail_rule WHERE code = 'PRICE_LIST_TO_CLIENT');
INSERT INTO tfb_mail_rule (code, event, template_type, recipient_kind, language, attach_document, days_before, enabled, sort_order, updated_by)
SELECT 'MONTHLY_REPORT', 'month_start', 'monthly_report', 'distribution', 'client', 1, 1, 0, 75, 'migration' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tfb_mail_rule WHERE code = 'MONTHLY_REPORT');

-- Kopie dla biura rachunkowego : wiersz -1 dystrybucji zamiast osobnych reguł.
UPDATE tfb_mail_rule SET enabled = 0, updated_by = 'migration' WHERE code LIKE 'ACCOUNTING\_%';

-- Wysyłka automatyczna (zatwierdzona) : reguła włączona, gdy któryś dokument ma jej moment ; od teraz, bez historii
-- (start_ref_id pusty : pierwszy przebieg zapisuje ostatni istniejący dokument i niczego nie wysyła).
UPDATE tfb_mail_rule r
SET r.enabled = IF(EXISTS (SELECT 1 FROM tfb_doc_distribution_moment m WHERE m.moment COLLATE utf8mb4_unicode_ci = CAST(r.event AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci), 1, 0),
    r.start_ref_id = NULL, r.enabled_at = NOW(), r.updated_by = 'migration'
WHERE r.recipient_kind = 'distribution' AND r.enabled = 0;

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_list', 'fr', 'Nouveau tarif', 'Votre tarif mis à jour — {{ client.company }}',
'Bonjour {{ client.contact_name }},\n\nnos prix ont été mis à jour. Vous trouverez votre tarif actuel en pièce jointe.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_list' AND language_code = 'fr' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_list', 'nl', 'Nieuwe prijslijst', 'Uw bijgewerkte prijslijst — {{ client.company }}',
'Beste {{ client.contact_name }},\n\nonze prijzen zijn bijgewerkt. In bijlage vindt u uw actuele prijslijst.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkende producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_list' AND language_code = 'nl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_list', 'pl', 'Nowy cennik', 'Zaktualizowany cennik — {{ client.company }}',
'Dzień dobry {{ client.contact_name }},\n\nnasze ceny zostały zaktualizowane. W załączniku przesyłamy aktualny cennik.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_list' AND language_code = 'pl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_list', 'en', 'New price list', 'Your updated price list — {{ client.company }}',
'Hello {{ client.contact_name }},\n\nour prices have been updated. Please find your current price list attached.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_list' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'monthly_report', 'fr', 'Rapport mensuel', 'Votre rapport mensuel — {{ report.period }}',
'Bonjour {{ client.contact_name }},\n\nvous trouverez en pièce jointe votre rapport pour {{ report.period }} : {{ report.documents }}.\n\nN''hésitez pas à revenir vers nous pour en parler.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'monthly_report' AND language_code = 'fr' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'monthly_report', 'nl', 'Maandrapport', 'Uw maandrapport — {{ report.period }}',
'Beste {{ client.contact_name }},\n\nin bijlage vindt u uw rapport voor {{ report.period }}: {{ report.documents }}.\n\nAarzel niet om contact met ons op te nemen.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkende producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'monthly_report' AND language_code = 'nl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'monthly_report', 'pl', 'Raport miesięczny', 'Raport miesięczny — {{ report.period }}',
'Dzień dobry {{ client.contact_name }},\n\nw załączniku przesyłamy raport za {{ report.period }} : {{ report.documents }}.\n\nChętnie porozmawiamy o wynikach.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'monthly_report' AND language_code = 'pl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'monthly_report', 'en', 'Monthly report', 'Your monthly report — {{ report.period }}',
'Hello {{ client.contact_name }},\n\nplease find attached your report for {{ report.period }}: {{ report.documents }}.\n\nFeel free to get back to us to discuss it.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'monthly_report' AND language_code = 'en' AND is_default = 1);
