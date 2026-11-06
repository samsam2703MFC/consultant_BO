-- Audit 2, lot E (P21, P22) : prix des matières, une seule porte. Chaque changement de prix garde sa source (manual :
-- Ceny surowców, offer : offre acceptée, invoice : facture d'achat, new : création de la matière) et l'utilisateur ;
-- prices.manual_edit = 0 rend « Ceny surowców » en lecture seule (prix seulement par offre, facture ou appel d'offres).
-- Valeur de départ 1 : rien ne change tant que la direction ne le décide pas. Conditionnel et rejouable.

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'raw_material_price_changelog' AND COLUMN_NAME = 'source') = 0,
    'ALTER TABLE raw_material_price_changelog
        ADD COLUMN source VARCHAR(16) NULL COMMENT ''manual, offer, invoice, new'' AFTER package_size_in_base_unit,
        ADD COLUMN id_user INT NULL AFTER source',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.manual_edit', '1', '1', 'bool', NULL,
    'Ceny surowców : ręczna zmiana ceny dozwolona (0 = tylko przez ofertę, fakturę albo zapytanie ofertowe)',
    'Prix des matières : modification manuelle autorisée (0 = seulement par offre, facture ou appel d''offres)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.manual_edit');

-- P22 : traçabilité lot → clients. Les cartons d'un produit étiquetés le jour de la production ou jusqu'à N jours après
-- sont rattachés au lot (l'étiquette du carton ne porte pas encore le numéro de lot de la tablette).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'trace.label_days', '1', '1', 'int', 'dni',
    'Identyfikowalność : kartony oklejone do N dni po produkcji należą do partii (dokąd poszła partia)',
    'Traçabilité : cartons étiquetés jusqu''à N jours après la production rattachés au lot (où est parti le lot)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'trace.label_days');
