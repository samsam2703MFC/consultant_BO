-- Tablette de production (27/10/2026) : « Receptura na zamówienie » — commande en cartons × pièces par carton,
-- + perte de production (0 / 2 / 5 % ou autre) → pièces à faire et ingrédients de la recette ; « Zatwierdź » crée la
-- partie et ouvre les numéros de lot de chaque ingrédient (photo du sac, OCR ou à la main).
-- kiosk.recipe_loss_pct (2) : perte proposée par défaut. Rejouable.

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.recipe_loss_pct', '2', '2', 'decimal', '%',
    'Tablet produkcji : domyślna strata produkcyjna w recepturze na zamówienie (dolicza się do sztuk do zrobienia)',
    'Tablette de production : perte de production par défaut dans la recette sur commande (ajoutée aux pièces à faire)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.recipe_loss_pct');
