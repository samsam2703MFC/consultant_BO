-- Strona klienta (opcja w karcie klienta) : dokumenty klienta + cel obrotu franczyzy (zakupy × współczynnik sprzedaży).
-- Współczynnik : kategorii (wszyscy klienci) → klienta → domyślny z Ustawień (tfb_store_price_config.multiplier).
-- Obrót do osiągnięcia = zakupy netto × współczynnik = obrót sklepu brutto (TTC).

CREATE TABLE IF NOT EXISTS tfb_customer_space (
    id_customer INT NOT NULL PRIMARY KEY,
    docs_enabled TINYINT(1) NOT NULL DEFAULT 0,
    goal_enabled TINYINT(1) NOT NULL DEFAULT 0,
    coefficient DECIMAL(5,2) NULL,
    open_days TINYINT UNSIGNED NULL,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tfb_category_sales_coefficient (
    id_category INT NOT NULL PRIMARY KEY,
    coefficient DECIMAL(5,2) NULL,
    excluded TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usługi nie są odsprzedawane w sklepie : poza celem obrotu (można zmienić na stronie celu).
INSERT IGNORE INTO tfb_category_sales_coefficient (id_category, coefficient, excluded, updated_by)
SELECT id, NULL, 1, 'migration' FROM category_product WHERE LOWER(TRIM(name)) IN ('produit de services', 'produit de service');

-- Aktywni franczyzobiorcy : strona klienta włączona (wyłączenie w karcie klienta).
INSERT IGNORE INTO tfb_customer_space (id_customer, docs_enabled, goal_enabled, updated_by)
SELECT id, 1, 1, 'migration' FROM customer WHERE active = 1 AND (franchise_network_id IS NOT NULL OR type = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'sales_goal', 'fr', 'Objectif de chiffre d''affaires', 'Votre objectif de chiffre d''affaires — {{ goal.period }}',
'Bonjour {{ client.contact_name }},\n\nvous trouverez en pièce jointe votre objectif de chiffre d''affaires pour {{ goal.period }}.\nVos achats chez nous ({{ goal.purchases }} € HT), multipliés par le coefficient de vente de chaque catégorie, correspondent à un chiffre d''affaires de {{ goal.target }} € TTC à réaliser en magasin, soit environ {{ goal.per_day }} € par jour d''ouverture.\nLe détail par catégorie et par produit, avec le prix de vente conseillé par pièce, se trouve dans le document.\n\nN''hésitez pas à revenir vers nous pour en parler.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'sales_goal' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'sales_goal', 'nl', 'Omzetdoelstelling', 'Uw omzetdoelstelling — {{ goal.period }}',
'Beste {{ client.contact_name }},\n\nin bijlage vindt u uw omzetdoelstelling voor {{ goal.period }}.\nUw aankopen bij ons ({{ goal.purchases }} € excl. btw), vermenigvuldigd met de verkoopcoëfficiënt van elke categorie, komen overeen met een omzet van {{ goal.target }} € incl. btw in de winkel, of ongeveer {{ goal.per_day }} € per openingsdag.\nHet detail per categorie en per product, met de aanbevolen verkoopprijs per stuk, staat in het document.\n\nAarzel niet om contact met ons op te nemen.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkende producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'sales_goal' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'sales_goal', 'pl', 'Cel obrotu', 'Cel obrotu — {{ goal.period }}',
'Dzień dobry {{ client.contact_name }},\n\nw załączniku przesyłamy cel obrotu na {{ goal.period }}.\nZakupy u nas ({{ goal.purchases }} € netto) pomnożone przez współczynnik sprzedaży każdej kategorii odpowiadają obrotowi {{ goal.target }} € brutto w sklepie, czyli około {{ goal.per_day }} € na dzień otwarcia.\nSzczegóły według kategorii i produktów, z zalecaną ceną sprzedaży za sztukę, są w dokumencie.\n\nChętnie porozmawiamy o wynikach.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'sales_goal' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'sales_goal', 'en', 'Revenue target', 'Your revenue target — {{ goal.period }}',
'Hello {{ client.contact_name }},\n\nplease find attached your revenue target for {{ goal.period }}.\nYour purchases from us ({{ goal.purchases }} € excl. VAT), multiplied by the sales coefficient of each category, correspond to {{ goal.target }} € of in-store revenue incl. VAT, i.e. about {{ goal.per_day }} € per opening day.\nThe detail by category and product, with the recommended selling price per piece, is in the document.\n\nFeel free to get back to us to discuss it.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'sales_goal' AND language_code = 'en' AND is_default = 1);
