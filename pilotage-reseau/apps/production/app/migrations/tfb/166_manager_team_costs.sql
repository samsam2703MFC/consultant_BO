-- [267] Session Kierownik produkcji : onglet « Koszty i premie » de Czas pracy (demande du 11/11/2026).
--   Lecture seule : coût employeur par groupe et par personne, palier de prime atteint, chiffre d'affaires manquant,
--   prime par personne ; sa propre prime = % du chiffre d'affaires général du mois (0,3 %, chaque mois — décision du
--   11/11/2026), à la place de la prime trimestrielle sur objectifs (manager.quarterly_bonus = 0).
--   Option de l'administrateur (Premie zespołów) : manager.show_bonuses — montrer ou non les primes au Kierownik.
-- Rejouable : INSERT IGNORE.

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('manager.sales_bonus_pct', '0.3', '0.3', 'decimal', '%', 'Kierownik produkcji : premia miesięczna = % sprzedaży firmy w miesiącu (0 = brak)', 'Kierownik produkcji : prime mensuelle = % du chiffre d''affaires général du mois (0 = aucune)'),
('manager.show_bonuses', '1', '1', 'bool', NULL, 'Kierownik produkcji widzi premie (grupy, osoby, własna) w « Koszty i premie »', 'Le Kierownik produkcji voit les primes (groupes, personnes, la sienne) dans « Koszty i premie »'),
('manager.quarterly_bonus', '0', '0', 'bool', NULL, 'Kierownik produkcji : premia kwartalna za cele (moduł Kierownik) — wyłączona, zastąpiona premią miesięczną', 'Kierownik produkcji : prime trimestrielle sur objectifs (module Kierownik) — désactivée, remplacée par la prime mensuelle');

-- Le Kierownik produkcji voit l'onglet (droit « team_costs » ; l'administrateur peut le retirer dans Uprawnienia i menu).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'team_costs' FROM n_position p WHERE p.code = 'KIEROWNIK_PRODUKCJI';
