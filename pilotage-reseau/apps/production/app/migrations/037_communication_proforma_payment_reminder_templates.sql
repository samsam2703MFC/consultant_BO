-- Dedicated templates for payment reminders referring to a proforma.
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'proforma_payment_reminder', 'fr', 'Rappel de paiement — proforma', 'Rappel de paiement — proforma {{ proforma.number }}',
'Bonjour {{ client.company }},\n\nnous vous rappelons le règlement de la proforma {{ proforma.number }} du {{ proforma.date }}, d''un montant de {{ proforma.amount }} {{ proforma.currency }}.\n\nCordialement,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_payment_reminder' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'proforma_payment_reminder', 'nl', 'Betalingsherinnering — proforma', 'Betalingsherinnering — proforma {{ proforma.number }}',
'Beste {{ client.company }},\n\nwij herinneren u aan de betaling van proforma {{ proforma.number }} van {{ proforma.date }}, voor een bedrag van {{ proforma.amount }} {{ proforma.currency }}.\n\nMet vriendelijke groet,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_payment_reminder' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'proforma_payment_reminder_final', 'fr', 'Dernier rappel de paiement — proforma', 'Rappel urgent — proforma {{ proforma.number }}',
'Bonjour {{ client.company }},\n\nnous n''avons pas encore reçu le paiement de la proforma {{ proforma.number }} du {{ proforma.date }}, d''un montant de {{ proforma.amount }} {{ proforma.currency }}. Merci de régulariser cette somme sans délai.\n\nCordialement,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_payment_reminder_final' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default)
SELECT 'proforma_payment_reminder_final', 'nl', 'Laatste betalingsherinnering — proforma', 'Dringende herinnering — proforma {{ proforma.number }}',
'Beste {{ client.company }},\n\nwij hebben de betaling van proforma {{ proforma.number }} van {{ proforma.date }}, voor {{ proforma.amount }} {{ proforma.currency }}, nog niet ontvangen. Wij verzoeken u dit bedrag zo snel mogelijk te voldoen.\n\nMet vriendelijke groet,\n{{ company.name }}', 1
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'proforma_payment_reminder_final' AND language_code = 'nl' AND is_default = 1);
