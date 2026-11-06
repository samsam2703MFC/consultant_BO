-- Prix de revient (27/10/2026) : le prix de base d'une matière — celui du coût des recettes — est le plus haut prix validé,
-- pas le plus récent. Une facture ou une offre acceptée moins chère ne le fait jamais baisser (prices.cost_max_months = 0,
-- défaut) ; avec N > 0, un prix de base de plus de N mois descend au plus haut prix validé de la période (factures et offres
-- acceptées). Ceny surowców (saisie manuelle) le fixe directement. Rejouable.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.cost_max_months', '0', '0', 'int', 'mies.',
    'Koszt receptur po najwyższej zatwierdzonej cenie surowca : niższa cena z faktury lub oferty nie obniża ceny bazowej (0 = nigdy ; N = tylko cena bazowa starsza niż N miesięcy)',
    'Coût des recettes au plus haut prix validé : un prix plus bas (facture, offre) ne fait pas baisser le prix de base (0 = jamais ; N = seulement un prix de base de plus de N mois)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.cost_max_months');
