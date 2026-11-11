-- Zakupy tygodnia (27/10/2026, makiety 1 à 5) : co tydzień zakupy surowców i opakowań na uzupełnienie stanów — kreator
-- w 3 krokach (co kupić → komu i na jakich warunkach → wyślij), oferty z minimum zamówienia, zamówienie u najtańszych,
-- oszczędność operatora (miesiąc / kwartał / rok) dla niego i dyrektora zakładu. Rejouable.
-- 1. n_material_supplier : dostawcy pozycji (1 do 3 na surowiec / opakowanie) — kogo pytać o ofertę ; pozycje z mniej niż 3
--    dostawcami = zadanie « znajdź dostawców » (Zakupy). Wypełnione z historii (dostawca bieżący, faktury, zamówienia, oferty).
-- 2. n_buy_round : zakupy jednego tygodnia (kto, tydzień produkcji, zapas +0/+5/+10 %, warunki : płatność 7/14/30 dni
--    albo proforma, dostawa albo odbiór, dostawa najpóźniej, odpowiedź do, cena za podwójną ilość).
-- 3. n_buy_line : pozycja do kupienia (potrzeba, stan, w drodze, do kupienia w pełnych opakowaniach, ostatnia najtańsza cena).
-- 4. n_buy_request / n_buy_request_line : zapytanie do dostawcy (tylko jego pozycje), link do wpisania cen bez logowania
--    (token), e-mail (kolejka Komunikacji), odpowiedź, przypomnienie.
-- 5. n_mp_offer : oferty z zapytań tygodnia (source = weekly, id_buy_line) : cena, cena za podwójną ilość, minimum zamówienia
--    (ilość w jednostce surowca — min_order_qty, kwota zł — min_value, albo opis, np. « 1 paleta » — min_note), dostawa.
-- 6. n_buy_order : decyzja na pozycję (oferta albo ostatni dostawca, ilość, cena, ostatnia cena, oszczędność zł, zamówienie
--    n_po) — « zakup udany » = taniej niż ostatnio ; źródło wyników operatora.
-- 7. Szablony e-mail buy_request (pl, fr, nl, en), zadania stanowiska ZAKUPY (zakupy w poniedziałek, dostawcy w środę), parametry.

CREATE TABLE IF NOT EXISTS n_material_supplier (
    id_raw_material INT NOT NULL,
    id_supplier INT NOT NULL,
    sort TINYINT NOT NULL DEFAULT 0,
    source ENUM('history', 'manual', 'round') NOT NULL DEFAULT 'manual',
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_raw_material, id_supplier),
    KEY idx_n_material_supplier_supplier (id_supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dostawcy z historii (najnowsi najpierw, najwyżej 3) : bieżący dostawca, faktury zakupowe i zamówienia z 24 miesięcy, oferty.
INSERT IGNORE INTO n_material_supplier (id_raw_material, id_supplier, sort, source)
SELECT x.id_raw_material, x.id_supplier, x.rn - 1, 'history'
FROM (
    SELECT h.id_raw_material, h.id_supplier, ROW_NUMBER() OVER (PARTITION BY h.id_raw_material ORDER BY MAX(h.prio) DESC, MAX(h.day) DESC, h.id_supplier) AS rn
    FROM (
        SELECT rm.id AS id_raw_material, rm.id_current_supplier AS id_supplier, '2999-12-31' AS day, 1 AS prio
        FROM raw_material rm WHERE rm.id_current_supplier IS NOT NULL AND rm.id_current_supplier > 0
        UNION ALL
        SELECT l.id_raw_material, i.id_supplier, COALESCE(i.issue_date, DATE(i.created_at)), 0
        FROM n_inv_line l JOIN n_inv i ON i.id = l.id_inv
        WHERE l.id_raw_material IS NOT NULL AND i.id_supplier IS NOT NULL AND i.kind = 'goods' AND COALESCE(i.issue_date, DATE(i.created_at)) >= CURDATE() - INTERVAL 24 MONTH
        UNION ALL
        SELECT pl.id_raw_material, p.id_supplier, DATE(p.created_at), 0
        FROM n_po_line pl JOIN n_po p ON p.id = pl.id_po
        WHERE p.status <> 'cancelled' AND p.created_at >= NOW() - INTERVAL 24 MONTH
        UNION ALL
        SELECT o.id_raw_material, o.id_supplier, o.valid_from, 0
        FROM n_mp_offer o WHERE o.valid_from >= CURDATE() - INTERVAL 24 MONTH
    ) h
    JOIN supplier s ON s.id = h.id_supplier AND s.is_active = 1
    JOIN raw_material r ON r.id = h.id_raw_material
    GROUP BY h.id_raw_material, h.id_supplier
) x
WHERE x.rn <= 3;

CREATE TABLE IF NOT EXISTS n_buy_round (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    week_start DATE NOT NULL COMMENT 'pierwszy dzień tygodnia produkcji',
    week_end DATE NOT NULL,
    status ENUM('draft', 'sent', 'ordered', 'closed') NOT NULL DEFAULT 'draft',
    safety_pct TINYINT NOT NULL DEFAULT 0 COMMENT 'zapas bezpieczeństwa : 0, 5, 10 % potrzeby',
    pay_terms ENUM('7', '14', '30', 'proforma') NOT NULL DEFAULT '7',
    delivery ENUM('delivery', 'pickup') NOT NULL DEFAULT 'delivery',
    deliver_by DATE NULL,
    reply_by DATETIME NULL,
    ask_double TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'cena także za podwójną ilość',
    id_user INT NOT NULL COMMENT 'operator zakupów',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    ordered_at DATETIME NULL,
    closed_at DATETIME NULL,
    UNIQUE KEY uq_n_buy_round_code (code),
    KEY idx_n_buy_round_week (week_start, status),
    KEY idx_n_buy_round_user (id_user, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_buy_line (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_round INT NOT NULL,
    id_raw_material INT NOT NULL,
    need_base DECIMAL(16,3) NOT NULL DEFAULT 0 COMMENT 'potrzeba tygodnia (jednostka bazowa)',
    stock_base DECIMAL(16,3) NULL,
    on_order_base DECIMAL(16,3) NOT NULL DEFAULT 0,
    quantity_base DECIMAL(16,3) NOT NULL COMMENT 'do kupienia (pełne opakowania, z zapasem)',
    package_size_base DECIMAL(14,3) NULL,
    last_price DECIMAL(14,6) NULL COMMENT 'ostatnia najtańsza cena za jednostkę bazową',
    last_supplier_id INT NULL,
    last_day DATE NULL,
    CONSTRAINT fk_n_buy_line_round FOREIGN KEY (id_round) REFERENCES n_buy_round (id) ON DELETE CASCADE,
    UNIQUE KEY uq_n_buy_line (id_round, id_raw_material)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_buy_request (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_round INT NOT NULL,
    id_supplier INT NOT NULL,
    email VARCHAR(255) NULL,
    lang CHAR(2) NOT NULL DEFAULT 'pl',
    token CHAR(48) NOT NULL COMMENT 'link do wpisania cen bez logowania',
    id_message BIGINT NULL,
    sent_at DATETIME NULL,
    opened_at DATETIME NULL,
    responded_at DATETIME NULL,
    reminded_at DATETIME NULL,
    reminders TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_n_buy_request_round FOREIGN KEY (id_round) REFERENCES n_buy_round (id) ON DELETE CASCADE,
    UNIQUE KEY uq_n_buy_request (id_round, id_supplier),
    UNIQUE KEY uq_n_buy_request_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_buy_request_line (
    id_request INT NOT NULL,
    id_line INT NOT NULL,
    PRIMARY KEY (id_request, id_line),
    KEY idx_n_buy_request_line_line (id_line),
    CONSTRAINT fk_n_buy_request_line_request FOREIGN KEY (id_request) REFERENCES n_buy_request (id) ON DELETE CASCADE,
    CONSTRAINT fk_n_buy_request_line_line FOREIGN KEY (id_line) REFERENCES n_buy_line (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS n_buy_order (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_round INT NOT NULL,
    id_line INT NOT NULL,
    id_offer INT NULL COMMENT 'n_mp_offer ; NULL = ostatni dostawca po ostatniej cenie',
    id_supplier INT NOT NULL,
    quantity_base DECIMAL(16,3) NOT NULL,
    price DECIMAL(14,6) NOT NULL COMMENT 'za jednostkę bazową',
    last_price DECIMAL(14,6) NULL,
    saving DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '(ostatnia cena − cena) × ilość, zł',
    id_po INT NULL,
    id_user INT NOT NULL,
    decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_n_buy_order_round FOREIGN KEY (id_round) REFERENCES n_buy_round (id) ON DELETE CASCADE,
    UNIQUE KEY uq_n_buy_order_line (id_line),
    KEY idx_n_buy_order_user (id_user, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Oferty z zapytań tygodnia w tej samej tabeli ofert co zapytania ofertowe i oferty wpisane ręcznie.
ALTER TABLE n_mp_offer MODIFY COLUMN source ENUM('manual', 'tender', 'email', 'price_list', 'weekly') NOT NULL DEFAULT 'manual';

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_mp_offer' AND COLUMN_NAME = 'id_buy_line');
SET @sql := IF(@c = 0,
    'ALTER TABLE n_mp_offer ADD COLUMN id_buy_line INT NULL COMMENT ''pozycja zakupów tygodnia'' AFTER id_tender,
        ADD COLUMN price_double_base DECIMAL(14,6) NULL COMMENT ''cena za jednostkę bazową przy podwójnej ilości'' AFTER price_per_base,
        ADD COLUMN min_value DECIMAL(12,2) NULL COMMENT ''minimum zamówienia w zł'' AFTER min_order_qty,
        ADD COLUMN min_note VARCHAR(60) NULL COMMENT ''minimum opisem, np. 1 paleta'' AFTER min_value,
        ADD COLUMN delivery_date DATE NULL AFTER lead_time_days,
        ADD KEY idx_n_mp_offer_buy_line (id_buy_line)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Szablony e-mail : zapytanie tygodnia (dostawca widzi tylko swoje pozycje ; link do wpisania cen).
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'buy_request', 'pl', 'Zakupy tygodnia : zapytanie ofertowe', 'Zapytanie ofertowe {{ company.name }} — {{ request.code }} (odpowiedź do {{ request.deadline }})',
'Dzień dobry,\n\nprosimy o ofertę na poniższe pozycje (zapytanie {{ request.code }}) :\n\n{{ request.items }}\n\nWarunki : {{ request.conditions }}.\n{{ request.double }}Prosimy podać cenę, minimum zamówienia (ilość, palety albo kwota) i termin dostawy.\n\nNajprościej : ceny można wpisać tutaj, bez logowania (1 minuta) :\n{{ request.link }}\nalbo po prostu odpowiedzieć na tę wiadomość.\n\nOdpowiedź prosimy do {{ request.deadline }}.\n\nZ poważaniem,\n{{ user.name }}\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'buy_request' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'buy_request', 'fr', 'Achats de la semaine : demande d''offre', 'Demande d''offre {{ company.name }} — {{ request.code }} (réponse avant le {{ request.deadline }})',
'Bonjour,\n\nmerci de nous faire une offre pour les articles suivants (demande {{ request.code }}) :\n\n{{ request.items }}\n\nConditions : {{ request.conditions }}.\n{{ request.double }}Merci d''indiquer le prix, le minimum de commande (quantité, palettes ou montant) et le délai de livraison.\n\nLe plus simple : saisissez vos prix ici, sans compte (1 minute) :\n{{ request.link }}\nou répondez simplement à ce message.\n\nRéponse souhaitée avant le {{ request.deadline }}.\n\nCordialement,\n{{ user.name }}\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'buy_request' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'buy_request', 'nl', 'Inkoop van de week : offerteaanvraag', 'Offerteaanvraag {{ company.name }} — {{ request.code }} (antwoord vóór {{ request.deadline }})',
'Goedendag,\n\ngraag ontvangen wij een offerte voor de volgende artikelen (aanvraag {{ request.code }}) :\n\n{{ request.items }}\n\nVoorwaarden : {{ request.conditions }}.\n{{ request.double }}Graag de prijs, de minimale bestelling (hoeveelheid, pallets of bedrag) en de levertermijn vermelden.\n\nHet eenvoudigst : vul uw prijzen hier in, zonder account (1 minuut) :\n{{ request.link }}\nof beantwoord gewoon dit bericht.\n\nGraag een antwoord vóór {{ request.deadline }}.\n\nMet vriendelijke groeten,\n{{ user.name }}\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'buy_request' AND language_code = 'nl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'buy_request', 'en', 'Weekly purchasing : request for quotation', 'Request for quotation {{ company.name }} — {{ request.code }} (reply by {{ request.deadline }})',
'Hello,\n\nplease send us your offer for the following items (request {{ request.code }}) :\n\n{{ request.items }}\n\nTerms : {{ request.conditions }}.\n{{ request.double }}Please state the price, the minimum order (quantity, pallets or amount) and the delivery time.\n\nThe easiest way : enter your prices here, no account needed (1 minute) :\n{{ request.link }}\nor simply reply to this message.\n\nPlease reply by {{ request.deadline }}.\n\nKind regards,\n{{ user.name }}\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'buy_request' AND language_code = 'en' AND is_default = 1);

-- Zadanie co poniedziałek : stanowisko Zakupy (kategoria zadań jak « Wpisz otrzymane oferty cen »).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, link_path, sort_order, active)
SELECT p.id, (SELECT t.id_category FROM n_position_task t WHERE t.link_path = '/purchasing/offers' ORDER BY t.id LIMIT 1),
    'Zakupy tygodnia : zapytania ofertowe do dostawców (kreator : co kupić → komu → wyślij)',
    'Achats de la semaine : demandes d''offres aux fournisseurs (assistant : quoi acheter → à qui → envoyer)',
    'weekly', 1, '/workshop/buy', 5, 1
FROM n_position p
WHERE p.code = 'ZAKUPY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.link_path = '/workshop/buy');

-- Zadanie co tydzień : pozycje z mniej niż 3 dostawcami (zakładka « Dostawcy pozycji » ; lista także w « Do obsłużenia »).
INSERT INTO n_position_task (id_position, id_category, title_pl, title_fr, frequency, due_weekday, link_path, sort_order, active)
SELECT p.id, (SELECT t.id_category FROM n_position_task t WHERE t.link_path = '/purchasing/offers' ORDER BY t.id LIMIT 1),
    'Znajdź dostawców : pozycje z mniej niż 3 dostawcami (cel : 3 oferty na pozycję)',
    'Trouver des fournisseurs : articles avec moins de 3 fournisseurs (objectif : 3 offres par article)',
    'weekly', 3, '/workshop/buy#tab=suppliers', 6, 1
FROM n_position p
WHERE p.code = 'ZAKUPY' AND NOT EXISTS (SELECT 1 FROM n_position_task t WHERE t.link_path = '/workshop/buy#tab=suppliers');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'buy.reply_hours', '24', '24', 'int', 'h',
    'Zakupy tygodnia : domyślny czas na odpowiedź dostawców (od wysłania zapytań)',
    'Achats de la semaine : délai de réponse par défaut des fournisseurs (après l''envoi des demandes)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'buy.reply_hours');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'buy.link_days', '7', '7', 'int', 'dni',
    'Zakupy tygodnia : link do wpisania cen działa tyle dni po terminie odpowiedzi',
    'Achats de la semaine : le lien de saisie des prix reste valable ce nombre de jours après la date de réponse'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'buy.link_days');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'buy.supplier_weeks', '8', '8', 'int', 'tyg.',
    'Zakupy : zadanie « znajdź dostawców » dla pozycji zużytych w tylu ostatnich tygodniach (mniej niż 3 dostawców)',
    'Achats : tâche « trouver des fournisseurs » pour les positions consommées ces dernières semaines (moins de 3 fournisseurs)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'buy.supplier_weeks');
