-- Szablony e-maili do klientów we wszystkich językach komunikacji (fr, nl, pl, en) : brakujące typy (proforma,
-- różnice, przypomnienia o dopłacie) i brakujące języki. Podpis : SL Food, producent zatwierdzony przez L'Atelier By.
-- Szablony domyślne z migracji (created_by = 'migration', bez zmian użytkownika) dostają ten sam podpis.

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'order_confirmation', 'pl', 'Potwierdzenie zamówienia', 'Potwierdzenie zamówienia {{ order.number }} — dostawa {{ order.delivery_date }}',
'Szanowni Państwo,\n\notrzymaliśmy Państwa zamówienie {{ order.number }} ({{ order.reference }}): {{ order.cartons }} kart., dostawa planowana na {{ order.delivery_date }}.\nProforma znajduje się w załączniku.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'order_confirmation' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'order_confirmation', 'en', 'Order confirmation', 'Order {{ order.number }} confirmed — delivery on {{ order.delivery_date }}',
'Hello {{ client.company }},\n\nwe have received your order {{ order.number }} ({{ order.reference }}): {{ order.cartons }} carton(s), delivery planned on {{ order.delivery_date }}.\nThe proforma invoice is attached.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'order_confirmation' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_planned', 'pl', 'Planowana dostawa', 'Planowana dostawa {{ delivery.date }}',
'Szanowni Państwo,\n\nPaństwa dostawa jest zaplanowana na {{ delivery.date }}: zamówienie(-a) {{ delivery.orders }}, {{ delivery.cartons }} kart.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_planned' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_planned', 'en', 'Planned delivery', 'Delivery planned on {{ delivery.date }}',
'Hello {{ client.company }},\n\nyour delivery is planned on {{ delivery.date }}: order(s) {{ delivery.orders }}, {{ delivery.cartons }} carton(s).\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_planned' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'shipment_sent', 'pl', 'Wysyłka w drodze', 'Dostawa z {{ shipment.date }} jest w drodze',
'Szanowni Państwo,\n\nPaństwa towar wyjechał z naszej pracowni {{ shipment.date }} (WZ {{ shipment.wz_number }}, faktura {{ shipment.wdt_number }}).\nDokument WZ znajduje się w załączniku.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'shipment_sent' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'shipment_sent', 'en', 'Shipment on its way', 'Your delivery of {{ shipment.date }} is on its way',
'Hello {{ client.company }},\n\nyour goods left our workshop on {{ shipment.date }} (delivery note {{ shipment.wz_number }}, invoice {{ shipment.wdt_number }}).\nThe delivery note is attached.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'shipment_sent' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'invoice', 'pl', 'Faktura', 'Faktura {{ document.number }}',
'Szanowni Państwo,\n\nw załączniku przesyłamy fakturę {{ document.number }} z dnia {{ document.date }} na kwotę {{ amount.value }} {{ amount.currency }}.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'invoice' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'invoice', 'en', 'Invoice', 'Invoice {{ document.number }}',
'Hello {{ client.company }},\n\nplease find attached invoice {{ document.number }} of {{ document.date }}, amounting to {{ amount.value }} {{ amount.currency }}.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'invoice' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'wdt', 'pl', 'Faktura WDT', 'Faktura {{ document.number }}',
'Szanowni Państwo,\n\nw załączniku przesyłamy fakturę {{ document.number }} z dnia {{ document.date }} na kwotę {{ amount.value }} {{ amount.currency }}.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'wdt' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'wdt', 'en', 'Invoice', 'Invoice {{ document.number }}',
'Hello {{ client.company }},\n\nplease find attached invoice {{ document.number }} of {{ document.date }}, amounting to {{ amount.value }} {{ amount.currency }}.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'wdt' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma', 'fr', 'Proforma', 'Proforma {{ proforma.number }} — {{ client.company }}',
'Bonjour {{ client.company }},\n\nveuillez trouver ci-joint la proforma {{ proforma.number }} du {{ proforma.date }}, d''un montant de {{ proforma.amount }} {{ proforma.currency }}.\nMerci d''effectuer le paiement avec la communication « {{ proforma.number }} » et de nous envoyer la preuve de paiement en réponse à ce message : nous préparons la livraison dès réception.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma', 'nl', 'Proforma', 'Proforma {{ proforma.number }} — {{ client.company }}',
'Beste {{ client.company }},\n\nin bijlage vindt u proforma {{ proforma.number }} van {{ proforma.date }}, voor een bedrag van {{ proforma.amount }} {{ proforma.currency }}.\nGelieve te betalen met de mededeling « {{ proforma.number }} » en ons het betalingsbewijs te sturen als antwoord op dit bericht: wij bereiden de levering voor zodra wij het ontvangen.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma', 'pl', 'Proforma', 'Proforma {{ proforma.number }} — {{ client.company }}',
'Szanowni Państwo,\n\nw załączniku przesyłamy proformę {{ proforma.number }} z dnia {{ proforma.date }} na kwotę {{ proforma.amount }} {{ proforma.currency }}.\nProsimy o przelew z tytułem « {{ proforma.number }} » i przesłanie potwierdzenia płatności w odpowiedzi na tę wiadomość: dostawę przygotujemy po jego otrzymaniu.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma', 'en', 'Proforma', 'Proforma {{ proforma.number }} — {{ client.company }}',
'Hello {{ client.company }},\n\nplease find attached proforma invoice {{ proforma.number }} of {{ proforma.date }}, amounting to {{ proforma.amount }} {{ proforma.currency }}.\nPlease pay with the reference « {{ proforma.number }} » and send us the proof of payment in reply to this message: we prepare the delivery as soon as we receive it.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_difference', 'fr', 'Écarts commande / facture', 'Écarts commande / facture — facture {{ invoice.number }}',
'Bonjour {{ client.company }},\n\nnous avons constaté des écarts entre votre commande et la facture {{ invoice.number }} du {{ invoice.date }}. Le détail est joint à ce message.\nMontant de la différence : {{ difference.amount }} {{ difference.currency }}.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_difference' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_difference', 'nl', 'Verschillen bestelling / factuur', 'Verschillen bestelling / factuur — factuur {{ invoice.number }}',
'Beste {{ client.company }},\n\nwij stelden verschillen vast tussen uw bestelling en factuur {{ invoice.number }} van {{ invoice.date }}. Het overzicht vindt u in bijlage.\nBedrag van het verschil: {{ difference.amount }} {{ difference.currency }}.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_difference' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_difference', 'pl', 'Różnice zamówienie / faktura', 'Różnice zamówienie / faktura — faktura {{ invoice.number }}',
'Szanowni Państwo,\n\nstwierdziliśmy różnice między zamówieniem a fakturą {{ invoice.number }} z dnia {{ invoice.date }}. Zestawienie znajduje się w załączniku.\nKwota różnicy: {{ difference.amount }} {{ difference.currency }}.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_difference' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'delivery_difference', 'en', 'Order / invoice differences', 'Order / invoice differences — invoice {{ invoice.number }}',
'Hello {{ client.company }},\n\nwe found differences between your order and invoice {{ invoice.number }} of {{ invoice.date }}. The details are attached.\nDifference amount: {{ difference.amount }} {{ difference.currency }}.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'delivery_difference' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder', 'fr', 'Rappel — différence à régler', 'Rappel — différence à régler, facture {{ invoice.number }}',
'Bonjour {{ client.company }},\n\nsauf erreur de notre part, la différence de {{ difference.amount }} {{ difference.currency }} relative à la facture {{ invoice.number }} du {{ invoice.date }} n''a pas encore été réglée. Merci d''effectuer le paiement avec la mention « Facture : {{ invoice.number }} » et de nous envoyer la preuve de paiement.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder', 'nl', 'Herinnering — te betalen verschil', 'Herinnering — te betalen verschil, factuur {{ invoice.number }}',
'Beste {{ client.company }},\n\nbehoudens vergissing werd het verschil van {{ difference.amount }} {{ difference.currency }} voor factuur {{ invoice.number }} van {{ invoice.date }} nog niet betaald. Gelieve te betalen met de vermelding « Factuur: {{ invoice.number }} » en ons het betalingsbewijs te sturen.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder', 'pl', 'Przypomnienie — dopłata', 'Przypomnienie — dopłata do faktury {{ invoice.number }}',
'Szanowni Państwo,\n\nuprzejmie przypominamy, że dopłata {{ difference.amount }} {{ difference.currency }} do faktury {{ invoice.number }} z dnia {{ invoice.date }} nie została jeszcze uregulowana. Prosimy o przelew z tytułem « Faktura: {{ invoice.number }} » i przesłanie potwierdzenia płatności.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder', 'en', 'Reminder — difference to pay', 'Reminder — difference to pay, invoice {{ invoice.number }}',
'Hello {{ client.company }},\n\nunless we are mistaken, the difference of {{ difference.amount }} {{ difference.currency }} on invoice {{ invoice.number }} of {{ invoice.date }} has not been paid yet. Please pay with the reference « Invoice: {{ invoice.number }} » and send us the proof of payment.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder_final', 'fr', 'Dernier rappel — différence', 'Dernier rappel — différence facture {{ invoice.number }}',
'Bonjour {{ client.company }},\n\nmalgré notre précédent rappel, la différence de {{ difference.amount }} {{ difference.currency }} relative à la facture {{ invoice.number }} du {{ invoice.date }} reste impayée. Merci de la régler sans délai avec la mention « Facture : {{ invoice.number }} », ou de nous contacter si vous constatez une erreur.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder_final' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder_final', 'nl', 'Laatste herinnering — verschil', 'Laatste herinnering — verschil factuur {{ invoice.number }}',
'Beste {{ client.company }},\n\nondanks onze vorige herinnering blijft het verschil van {{ difference.amount }} {{ difference.currency }} voor factuur {{ invoice.number }} van {{ invoice.date }} onbetaald. Gelieve dit onmiddellijk te betalen met de vermelding « Factuur: {{ invoice.number }} », of ons te contacteren als u een fout vaststelt.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder_final' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder_final', 'pl', 'Ostatnie przypomnienie — dopłata', 'Ostatnie przypomnienie — dopłata do faktury {{ invoice.number }}',
'Szanowni Państwo,\n\nmimo wcześniejszego przypomnienia dopłata {{ difference.amount }} {{ difference.currency }} do faktury {{ invoice.number }} z dnia {{ invoice.date }} pozostaje nieuregulowana. Prosimy o niezwłoczny przelew z tytułem « Faktura: {{ invoice.number }} » albo o kontakt, jeśli widzą Państwo błąd.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder_final' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'payment_reminder_final', 'en', 'Final reminder — difference', 'Final reminder — difference on invoice {{ invoice.number }}',
'Hello {{ client.company }},\n\ndespite our previous reminder, the difference of {{ difference.amount }} {{ difference.currency }} on invoice {{ invoice.number }} of {{ invoice.date }} remains unpaid. Please pay it without delay with the reference « Invoice: {{ invoice.number }} », or contact us if you see an error.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'payment_reminder_final' AND language_code = 'en' AND is_default = 1);

UPDATE communication_template SET body_template = REPLACE(body_template, 'Cordialement,\n{{ company.name }}', 'Cordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By'), version = version + 1
WHERE created_by = 'migration' AND language_code = 'fr' AND body_template NOT LIKE '%L''Atelier By%'
  AND template_type IN ('order_confirmation', 'delivery_planned', 'shipment_sent', 'invoice', 'wdt', 'proforma_payment_reminder', 'proforma_payment_reminder_final');

UPDATE communication_template SET body_template = REPLACE(body_template, 'Met vriendelijke groet,\n{{ company.name }}', 'Met vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By'), version = version + 1
WHERE created_by = 'migration' AND language_code = 'nl' AND body_template NOT LIKE '%L''Atelier By%'
  AND template_type IN ('order_confirmation', 'delivery_planned', 'shipment_sent', 'invoice', 'wdt', 'proforma_payment_reminder', 'proforma_payment_reminder_final');

UPDATE communication_template SET body_template = REPLACE(body_template, 'Z poważaniem,\n{{ company.name }}', 'Z poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By'), version = version + 1
WHERE created_by = 'migration' AND language_code = 'pl' AND body_template NOT LIKE '%L''Atelier By%'
  AND template_type IN ('order_confirmation', 'delivery_planned', 'shipment_sent', 'invoice', 'wdt', 'proforma_payment_reminder', 'proforma_payment_reminder_final');
