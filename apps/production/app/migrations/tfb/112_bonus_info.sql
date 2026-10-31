-- Premie zespołów (27/10/2026) : vignette (i) de l'onglet « Premia » de la tablette, texte modifiable dans l'ERP
-- (Zespół › Premie zespołów), en polonais et en français ; vide = pas de vignette. Rejouable.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'bonus.info_pl',
    'Coboty nie liczą się jako osoby grupy : ich praca zwiększa sprzedaż na osobę bez dodatkowej osoby. Używajcie ich jak najwięcej — to czysty zysk dla premii.',
    'Coboty nie liczą się jako osoby grupy : ich praca zwiększa sprzedaż na osobę bez dodatkowej osoby. Używajcie ich jak najwięcej — to czysty zysk dla premii.',
    'string', NULL,
    'Premie zespołów : informacja (i) w zakładce « Premia » tabletu, po polsku (pusta = brak)',
    'Primes d''équipe : information (i) de l''onglet « Premia » de la tablette, en polonais (vide = aucune)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'bonus.info_pl');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'bonus.info_fr',
    'Les cobots ne comptent pas comme des personnes du groupe : leur travail augmente les ventes par personne sans personne en plus. Utilisez-les le plus possible — c''est tout bénéfice pour la prime.',
    'Les cobots ne comptent pas comme des personnes du groupe : leur travail augmente les ventes par personne sans personne en plus. Utilisez-les le plus possible — c''est tout bénéfice pour la prime.',
    'string', NULL,
    'Premie zespołów : informacja (i) w zakładce « Premia » tabletu, po francusku (pusta = brak)',
    'Primes d''équipe : information (i) de l''onglet « Premia » de la tablette, en français (vide = aucune)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'bonus.info_fr');
