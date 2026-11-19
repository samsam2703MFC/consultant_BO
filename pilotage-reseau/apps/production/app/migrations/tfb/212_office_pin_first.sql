-- [356] Tablette de bureau : décision du 19/11/2026 — même parcours que le QR, sans QR : PIN (4 chiffres), gros bouton
-- WEJŚCIE / WYJŚCIE, questions (Tak / Nie, points) et signature, pour tout le monde (y compris avec un téléphone
-- enregistré ; le QR reste un bouton sur l'écran du PIN). kiosk.office_pin_all passe à 1 une fois (Ustawienia ›
-- Parametry pour revenir à 0) ; la valeur par défaut aussi.

UPDATE n_prod_param SET value = '1', default_value = '1',
    description_pl = 'Tablet biurowy : PIN (4 cyfry), przycisk wejście / wyjście, pytania i podpis dla wszystkich ; kod QR telefonem — przycisk na ekranie PIN-u (0 = PIN tylko dla osób bez zarejestrowanego telefonu, reszta kodem QR)',
    description_fr = 'Tablette de bureau : PIN (4 chiffres), bouton entrée / sortie, questions et signature pour tout le monde ; QR avec le téléphone : bouton sur l''écran du PIN (0 = PIN seulement sans téléphone enregistré, les autres par QR)'
WHERE code = 'kiosk.office_pin_all';
