-- Tablette de production (27/10/2026) : l'entrée et la sortie se font sur la tablette de bureau ; sur la tablette du
-- groupe, une personne présente (entrée en cours) touche son nom et arrive au menu (Produkcja, Magazyn…) sans PIN.
-- kiosk.production_tap_present (1) : 0 = PIN demandé comme avant. Rejouable.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.production_tap_present', '1', '1', 'bool', NULL,
    'Tablet produkcji : osoba obecna (wejście na tablecie biurowym) dotyka nazwiska bez PIN-u (0 = PIN jak wcześniej)',
    'Tablette de production : une personne présente (entrée sur la tablette de bureau) touche son nom sans PIN (0 = PIN comme avant)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.production_tap_present');
