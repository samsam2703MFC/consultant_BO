-- Kreator cen [218] (Finanse › Marże i koszty › Kreator cen) : po fakturach koszty i marże, nowe ceny wg marży bazowej
-- (category_product.target_margin_percentage) i współczynnika surowców kategorii, publikacja od daty : ceny standardowe
-- i ceny klientów zmieniane w dniu zmiany (dispatcher co 5 minut), e-mail z cennikiem do klientów X dni przed,
-- cennik na stronę franczyzy (webhook cennika) w dniu zmiany. Rejouable.

-- Współczynnik surowców kategorii : cena fabryczna kartonu ≥ koszt surowców × współczynnik (NULL = domyślny z parametrów).
CREATE TABLE IF NOT EXISTS n_category_price_rule (
    id_category   INT NOT NULL,
    material_coef DECIMAL(5,2) NULL COMMENT 'cena ≥ koszt surowców × współczynnik ; NULL = prices.material_coef_default',
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kreator cen : współczynnik surowców kategorii [218]';

-- Publikacja cen od daty : planned → applied (w dniu zmiany) albo cancelled.
CREATE TABLE IF NOT EXISTS n_price_publication (
    id             INT NOT NULL AUTO_INCREMENT,
    effective_date DATE NOT NULL COMMENT 'nowe ceny od tego dnia (00:05, dispatcher)',
    status         VARCHAR(10) NOT NULL DEFAULT 'planned' COMMENT 'planned, applied, cancelled',
    notify_email   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'e-mail z cennikiem do klientów notice_days przed',
    notice_days    SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    products       INT NOT NULL DEFAULT 0 COMMENT 'ceny standardowe',
    client_prices  INT NOT NULL DEFAULT 0,
    avg_pct        DECIMAL(6,2) NULL COMMENT 'średnia podwyżka cen standardowych (%)',
    month_eur      DECIMAL(12,2) NULL COMMENT 'efekt na obrót / mies. (ceny klientów × średnia sprzedaż kartonów)',
    created_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at     DATETIME NULL,
    cancelled_by   INT NULL,
    cancelled_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_price_pub_status (status, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kreator cen : publikacje cen od daty [218]';

-- Ceny publikacji : id_customer = 0 → cena standardowa ; > 0 → cena klienta (obliczona przy planowaniu).
CREATE TABLE IF NOT EXISTS n_price_publication_item (
    id             INT NOT NULL AUTO_INCREMENT,
    id_publication INT NOT NULL,
    id_product     INT NOT NULL,
    id_customer    INT NOT NULL DEFAULT 0 COMMENT '0 = cena standardowa',
    old_price      DECIMAL(10,2) NOT NULL,
    new_price      DECIMAL(10,2) NOT NULL,
    manual         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cena poprawiona ręcznie w kreatorze',
    status         VARCHAR(10) NOT NULL DEFAULT 'planned' COMMENT 'planned, applied, skipped (cena zmieniona w międzyczasie)',
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_pub_item (id_publication, id_product, id_customer),
    KEY idx_price_pub_item_product (id_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kreator cen : ceny publikacji [218]';

-- Klienci publikacji : e-mail z cennikiem (przed datą) i cennik na stronę franczyzy (w dniu zmiany).
CREATE TABLE IF NOT EXISTS n_price_publication_client (
    id_publication INT NOT NULL,
    id_customer    INT NOT NULL,
    site           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'franczyza : cennik na stronę w dniu zmiany',
    email          TINYINT(1) NOT NULL DEFAULT 0,
    email_status   VARCHAR(10) NULL COMMENT 'queued, skipped, failed',
    email_note     VARCHAR(255) NULL,
    email_at       DATETIME NULL,
    id_message     INT NULL,
    site_status    VARCHAR(10) NULL COMMENT 'sent, retry, failed, no_site',
    site_note      VARCHAR(255) NULL,
    site_at        DATETIME NULL,
    site_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    site_version   BIGINT NULL,
    PRIMARY KEY (id_publication, id_customer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kreator cen : klienci publikacji [218]';

-- Parametry (Ustawienia › Pozostałe).
INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.material_coef_default', '2.5', '2.5', 'decimal', '×',
    'Kreator cen : domyślny współczynnik surowców kategorii (cena kartonu ≥ koszt surowców × współczynnik)',
    'Assistant prix : coefficient matières par défaut des catégories (prix du carton ≥ coût matières × coefficient)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.material_coef_default');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'prices.notice_days', '7', '7', 'int', 'dni',
    'Kreator cen : e-mail z nowym cennikiem do klientów tyle dni przed datą zmiany',
    'Assistant prix : e-mail avec le nouveau tarif aux clients autant de jours avant la date de changement'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'prices.notice_days');

-- Limit +X % i zaokrąglenie : jedna reguła dla cen standardowych i cen klientów.
UPDATE n_prod_param SET
    description_pl = 'Ceny : największa podwyżka jednej ceny na raz — ceny standardowe (Kreator cen) i ceny klientów (reszta do celu przy następnej zmianie)',
    description_fr = 'Prix : hausse maximale d''un prix en une fois — prix standard (assistant prix) et prix clients (le reste vers la cible à la prochaine hausse)'
WHERE code = 'prices.client_cap_pct';
UPDATE n_prod_param SET
    description_pl = 'Ceny : zaokrąglenie nowej ceny kartonu w górę do (ceny standardowe i ceny klientów)',
    description_fr = 'Prix : arrondi du nouveau prix du carton vers le haut à (prix standard et prix clients)'
WHERE code = 'prices.client_round_eur';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_product_price_change') = 1,
    'ALTER TABLE n_product_price_change MODIFY source VARCHAR(30) NOT NULL COMMENT ''general_price_list, category_margin, margin_clients, price_wizard''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- E-mail « nowe ceny od » (cennik od daty w załączniku), w języku klienta.
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_change_notice', 'fr', 'Nouveaux prix à partir d''une date', 'Nouveaux prix à partir du {{ prices.valid_from }} — {{ client.company }}',
'Bonjour {{ client.contact_name }},\n\nà partir du {{ prices.valid_from }}, nos prix évoluent ({{ prices.changed }} produits). Vous trouverez en pièce jointe votre tarif valable à partir de cette date.\n\nLes commandes livrées avant cette date restent au tarif actuel.\n\nCordialement,\n{{ company.name }}\nSL Food — producteur référencé pour L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_change_notice' AND language_code = 'fr' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_change_notice', 'nl', 'Nieuwe prijzen vanaf een datum', 'Nieuwe prijzen vanaf {{ prices.valid_from }} — {{ client.company }}',
'Beste {{ client.contact_name }},\n\nvanaf {{ prices.valid_from }} wijzigen onze prijzen ({{ prices.changed }} producten). In bijlage vindt u uw prijslijst die vanaf die datum geldt.\n\nBestellingen die vóór die datum geleverd worden, blijven aan de huidige prijs.\n\nMet vriendelijke groeten,\n{{ company.name }}\nSL Food — erkende producent voor L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_change_notice' AND language_code = 'nl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_change_notice', 'pl', 'Nowe ceny od daty', 'Nowe ceny od {{ prices.valid_from }} — {{ client.company }}',
'Dzień dobry {{ client.contact_name }},\n\nod {{ prices.valid_from }} zmieniają się nasze ceny ({{ prices.changed }} produktów). W załączniku przesyłamy cennik obowiązujący od tego dnia.\n\nZamówienia dostarczone przed tą datą pozostają w obecnych cenach.\n\nZ poważaniem,\n{{ company.name }}\nSL Food — producent zatwierdzony przez L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_change_notice' AND language_code = 'pl' AND is_default = 1);
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'price_change_notice', 'en', 'New prices from a date', 'New prices from {{ prices.valid_from }} — {{ client.company }}',
'Hello {{ client.contact_name }},\n\nfrom {{ prices.valid_from }}, our prices change ({{ prices.changed }} products). Please find attached your price list valid from that date.\n\nOrders delivered before that date keep the current prices.\n\nKind regards,\n{{ company.name }}\nSL Food — approved producer for L''Atelier By', 1, 'migration'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'price_change_notice' AND language_code = 'en' AND is_default = 1);
