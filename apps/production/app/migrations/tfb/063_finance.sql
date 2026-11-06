-- Finanse : sekcja w railu (Proformy i faktury, Przypomnienia płatności, Klienci : wolumen i marża) i przypomnienia
-- o potwierdzeniu płatności proformy w 5 poziomach (od uprzejmego do ostatniego) w językach klientów.

-- Uprawnienia : nowe strony katalogu dostają prawa stron, z których pochodzą (bez utraty dostępu).
INSERT IGNORE INTO tfb_page_access (id_position, page_key, updated_by)
SELECT id_position, 'reminders', updated_by FROM tfb_page_access WHERE page_key = 'communication';
INSERT IGNORE INTO tfb_page_access (id_position, page_key, updated_by)
SELECT id_position, 'client_volume', updated_by FROM tfb_page_access WHERE page_key = 'margins';

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_1', 'fr', 'Rappel 1/5 — preuve de paiement', 'Proforma {{ proforma.number }} — preuve de paiement',
'Bonjour {{ client.company }},\n\nsauf erreur de notre part, nous n''avons pas encore reçu la preuve de paiement de la proforma {{ proforma.number }} du {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}).\nSi le paiement est déjà effectué, pourriez-vous nous envoyer la preuve en réponse à ce message ? Nous pourrons ainsi préparer votre livraison.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_1' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_1', 'nl', 'Herinnering 1/5 — betalingsbewijs', 'Proforma {{ proforma.number }} — betalingsbewijs',
'Beste {{ client.company }},\n\nbehoudens vergissing hebben wij het betalingsbewijs van proforma {{ proforma.number }} van {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) nog niet ontvangen.\nIs de betaling al uitgevoerd? Stuur ons dan het bewijs als antwoord op dit bericht, zodat wij uw levering kunnen voorbereiden.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_1' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_1', 'pl', 'Przypomnienie 1/5 — potwierdzenie płatności', 'Proforma {{ proforma.number }} — potwierdzenie płatności',
'Szanowni Państwo,\n\nnie otrzymaliśmy jeszcze potwierdzenia płatności proformy {{ proforma.number }} z dnia {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}).\nJeśli płatność została już wykonana, prosimy o przesłanie potwierdzenia w odpowiedzi na tę wiadomość — pozwoli nam to przygotować dostawę.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_1' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_1', 'en', 'Reminder 1/5 — proof of payment', 'Proforma {{ proforma.number }} — proof of payment',
'Hello {{ client.company }},\n\nunless we are mistaken, we have not yet received the proof of payment for proforma {{ proforma.number }} of {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}).\nIf you have already paid, could you send us the proof in reply to this message? This lets us prepare your delivery.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_1' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_2', 'fr', 'Rappel 2/5', 'Rappel — preuve de paiement de la proforma {{ proforma.number }}',
'Bonjour {{ client.company }},\n\nnous revenons vers vous concernant la proforma {{ proforma.number }} du {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) : nous n''avons toujours pas reçu de preuve de paiement.\nVotre commande sera planifiée dès réception. Merci de nous l''envoyer en réponse à ce message.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_2' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_2', 'nl', 'Herinnering 2/5', 'Herinnering — betalingsbewijs proforma {{ proforma.number }}',
'Beste {{ client.company }},\n\nwij komen terug op proforma {{ proforma.number }} van {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}): wij hebben nog steeds geen betalingsbewijs ontvangen.\nUw bestelling wordt ingepland zodra wij het ontvangen. Gelieve het als antwoord op dit bericht te sturen.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_2' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_2', 'pl', 'Przypomnienie 2/5', 'Przypomnienie — potwierdzenie płatności proformy {{ proforma.number }}',
'Szanowni Państwo,\n\nwracamy do proformy {{ proforma.number }} z dnia {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}): nadal nie mamy potwierdzenia płatności.\nZamówienie zaplanujemy zaraz po jego otrzymaniu. Prosimy o przesłanie go w odpowiedzi na tę wiadomość.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_2' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_2', 'en', 'Reminder 2/5', 'Reminder — proof of payment for proforma {{ proforma.number }}',
'Hello {{ client.company }},\n\nwe are following up on proforma {{ proforma.number }} of {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}): we still have not received the proof of payment.\nYour order will be scheduled as soon as we receive it. Please send it in reply to this message.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_2' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_3', 'fr', 'Rappel 3/5', 'Deuxième rappel — proforma {{ proforma.number }} non réglée',
'Bonjour {{ client.company }},\n\nmalgré nos messages, la proforma {{ proforma.number }} du {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) reste sans preuve de paiement.\nSans celle-ci, nous ne pouvons pas planifier la livraison de votre commande. Merci de régulariser la situation ou de nous indiquer la date prévue du paiement.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_3' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_3', 'nl', 'Herinnering 3/5', 'Tweede herinnering — proforma {{ proforma.number }} onbetaald',
'Beste {{ client.company }},\n\nondanks onze berichten blijft proforma {{ proforma.number }} van {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) zonder betalingsbewijs.\nZonder dit bewijs kunnen wij de levering van uw bestelling niet inplannen. Gelieve de situatie te regelen of ons de geplande betaaldatum te laten weten.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_3' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_3', 'pl', 'Przypomnienie 3/5', 'Drugie przypomnienie — proforma {{ proforma.number }} nieopłacona',
'Szanowni Państwo,\n\nmimo naszych wiadomości proforma {{ proforma.number }} z dnia {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) nadal nie ma potwierdzenia płatności.\nBez niego nie możemy zaplanować dostawy zamówienia. Prosimy o uregulowanie płatności albo informację o planowanym terminie.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_3' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_3', 'en', 'Reminder 3/5', 'Second reminder — proforma {{ proforma.number }} unpaid',
'Hello {{ client.company }},\n\ndespite our messages, proforma {{ proforma.number }} of {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) is still without proof of payment.\nWithout it we cannot schedule the delivery of your order. Please settle it or let us know the planned payment date.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_3' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_4', 'fr', 'Rappel 4/5 — urgent', 'Urgent — proforma {{ proforma.number }}',
'Bonjour {{ client.company }},\n\nla proforma {{ proforma.number }} du {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) n''est toujours pas réglée.\nNous vous demandons d''effectuer le paiement dans les plus brefs délais et de nous envoyer la preuve en réponse à ce message. Sans nouvelles de votre part, votre commande reste en attente.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_4' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_4', 'nl', 'Herinnering 4/5 — dringend', 'Dringend — proforma {{ proforma.number }}',
'Beste {{ client.company }},\n\nproforma {{ proforma.number }} van {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) is nog steeds niet betaald.\nWij vragen u de betaling zo snel mogelijk uit te voeren en ons het bewijs als antwoord op dit bericht te sturen. Zonder nieuws blijft uw bestelling in afwachting.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_4' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_4', 'pl', 'Przypomnienie 4/5 — pilne', 'Pilne — proforma {{ proforma.number }}',
'Szanowni Państwo,\n\nproforma {{ proforma.number }} z dnia {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) nadal nie jest opłacona.\nProsimy o jak najszybszą płatność i przesłanie potwierdzenia w odpowiedzi na tę wiadomość. Bez odpowiedzi zamówienie pozostaje wstrzymane.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_4' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_4', 'en', 'Reminder 4/5 — urgent', 'Urgent — proforma {{ proforma.number }}',
'Hello {{ client.company }},\n\nproforma {{ proforma.number }} of {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}) is still unpaid.\nPlease make the payment as soon as possible and send us the proof in reply to this message. Without news from you, your order stays on hold.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_4' AND language_code = 'en' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_5', 'fr', 'Rappel 5/5 — dernier', 'Dernier rappel — proforma {{ proforma.number }}',
'Bonjour {{ client.company }},\n\nceci est notre dernier rappel concernant la proforma {{ proforma.number }} du {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}), toujours impayée.\nMerci de nous contacter sans délai. À défaut de paiement ou de réponse, nous devrons annuler la commande correspondante.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_5' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_5', 'nl', 'Herinnering 5/5 — laatste', 'Laatste herinnering — proforma {{ proforma.number }}',
'Beste {{ client.company }},\n\ndit is onze laatste herinnering voor proforma {{ proforma.number }} van {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}), die nog steeds onbetaald is.\nGelieve onmiddellijk contact met ons op te nemen. Zonder betaling of antwoord moeten wij de bijbehorende bestelling annuleren.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkend producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_5' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_5', 'pl', 'Przypomnienie 5/5 — ostatnie', 'Ostatnie przypomnienie — proforma {{ proforma.number }}',
'Szanowni Państwo,\n\nto nasze ostatnie przypomnienie dotyczące proformy {{ proforma.number }} z dnia {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}), która nadal nie jest opłacona.\nProsimy o pilny kontakt. Bez płatności lub odpowiedzi będziemy zmuszeni anulować zamówienie.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_5' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'proforma_reminder_5', 'en', 'Reminder 5/5 — final', 'Final reminder — proforma {{ proforma.number }}',
'Hello {{ client.company }},\n\nthis is our final reminder about proforma {{ proforma.number }} of {{ proforma.date }} ({{ proforma.amount }} {{ proforma.currency }}), which is still unpaid.\nPlease contact us without delay. Without payment or a reply, we will have to cancel the corresponding order.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_reminder_5' AND language_code = 'en' AND is_default = 1);
