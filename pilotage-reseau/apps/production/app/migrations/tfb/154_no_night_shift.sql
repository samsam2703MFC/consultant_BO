-- Bez zmiany nocnej [235] : zakład nie pracuje w nocy — zmiana nocna (NUIT, 22:00–06:00, albo każda oznaczona « nocna »)
-- i zespoły na tej zmianie nieaktywne. Planowanie (okna maszyn), Moce produkcyjne, plan zespołów, tablety i tabela do
-- wypełnienia biorą tylko zmiany aktywne. Nic nie jest usuwane (historia obsady, ustawienia maszyn zostają). Rejouable.
UPDATE n_prod_team SET active = 0
WHERE active = 1 AND id_shift_template IN (SELECT id FROM n_prod_shift_template WHERE is_night = 1 OR code = 'NUIT');

UPDATE n_prod_shift_template SET active = 0 WHERE active = 1 AND (is_night = 1 OR code = 'NUIT');
