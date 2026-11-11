-- [267] Demande du 11/11/2026 (« retirer pour le moment premia i koszty de la session Kierownik produkcji ») :
--   le Kierownik produkcji ne voit plus l'onglet « Koszty i premie » (droit team_costs retiré). L'onglet, les réglages et
--   la prime mensuelle restent ; l'administrateur rend l'accès dans Administracja › Uprawnienia i menu (Koszty i premie).
-- Rejouable : DELETE.

DELETE a FROM n_page_access a JOIN n_position p ON p.id = a.id_position
WHERE p.code = 'KIEROWNIK_PRODUKCJI' AND a.page_key = 'team_costs';
