-- Tablette de production, « Zakoduj produkcję » : les boutons sont les produits du groupe commandés par les clients
-- (commandes ouvertes, expédition depuis 7 jours en retard jusqu'au jour + kiosk.ordered_days) ; un produit absent
-- se trouve par la barre de recherche (tous les produits du groupe). Rejouable (MySQL 8 / MariaDB).

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'kiosk.ordered_days', '7', '7', 'int', 'dni',
    'Tablet produkcji, « Zakoduj produkcję » : przyciski = produkty zamówione z wysyłką do N dni od dnia (reszta przez wyszukiwarkę)',
    'Tablette de production, « Encoder la production » : boutons = produits commandés à expédier dans les N jours (les autres par la recherche)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'kiosk.ordered_days');
