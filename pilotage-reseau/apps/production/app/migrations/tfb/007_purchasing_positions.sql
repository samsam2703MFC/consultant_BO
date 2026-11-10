-- Achats de matières premières (offres, appels d'offres, non-conformités) et postes des
-- utilisateurs de l'ERP avec leurs tâches récurrentes.
-- Les prix appliqués restent ceux de l'ERP : raw_material.price_per_unit et l'historique
-- raw_material_price_changelog, mis à jour par MaterialPriceService (coûts recettes recalculés).

-- --- Postes et tâches --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tfb_position (
    id          INT NOT NULL AUTO_INCREMENT,
    code        VARCHAR(32)  NOT NULL,
    name_pl     VARCHAR(100) NOT NULL,
    name_fr     VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_position_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Poste d'un utilisateur de l'ERP (table user).
CREATE TABLE IF NOT EXISTS tfb_user_position (
    id_user      INT NOT NULL,
    id_position  INT NOT NULL,
    since        DATE NULL,
    PRIMARY KEY (id_user),
    CONSTRAINT fk_tfb_user_position_user FOREIGN KEY (id_user) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_user_position_position FOREIGN KEY (id_position) REFERENCES tfb_position (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tâche récurrente d'un poste. link_path : page de l'ERP où la faire (ex. /purchasing/nc).
CREATE TABLE IF NOT EXISTS tfb_position_task (
    id           INT NOT NULL AUTO_INCREMENT,
    id_position  INT NOT NULL,
    title_pl     VARCHAR(200) NOT NULL,
    title_fr     VARCHAR(200) NOT NULL,
    frequency    ENUM('daily','weekly','monthly','quarterly') NOT NULL,
    link_path    VARCHAR(255) NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    active       TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY ix_tfb_position_task (id_position),
    CONSTRAINT fk_tfb_position_task_position FOREIGN KEY (id_position) REFERENCES tfb_position (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Une tâche pour une période (jour, semaine ISO, mois, trimestre) : faite, sautée ou ouverte.
CREATE TABLE IF NOT EXISTS tfb_task_occurrence (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    id_task     INT NOT NULL,
    period_key  VARCHAR(16) NOT NULL,
    due_date    DATE NOT NULL,
    status      ENUM('open','done','skipped') NOT NULL DEFAULT 'open',
    done_by     INT NULL,
    done_at     DATETIME NULL,
    note        VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_task_occurrence (id_task, period_key),
    KEY ix_tfb_task_occurrence_due (due_date),
    CONSTRAINT fk_tfb_task_occurrence_task FOREIGN KEY (id_task) REFERENCES tfb_position_task (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --- Non-conformités ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tfb_nc (
    id                 INT NOT NULL AUTO_INCREMENT,
    number             VARCHAR(20) NOT NULL,
    reported_at        DATE NOT NULL,
    source             ENUM('supplier_delivery','production','customer','internal') NOT NULL,
    id_supplier        INT NULL,
    id_raw_material    INT NULL,
    id_final_product   INT NULL,
    description        TEXT NOT NULL,
    severity           ENUM('minor','major','critical') NOT NULL DEFAULT 'minor',
    quantity           DECIMAL(12,3) NULL,
    unit               VARCHAR(16) NULL,
    immediate_action   TEXT NULL,
    root_cause         TEXT NULL,
    corrective_action  TEXT NULL,
    cost_impact        DECIMAL(12,2) NULL,
    status             ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
    owner_user         INT NULL,
    due_date           DATE NULL,
    closed_at          DATETIME NULL,
    created_by         INT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_nc_number (number),
    KEY ix_tfb_nc_supplier (id_supplier),
    KEY ix_tfb_nc_material (id_raw_material),
    CONSTRAINT ck_tfb_nc_cost CHECK (cost_impact IS NULL OR cost_impact >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --- Offres de prix et appels d'offres ------------------------------------------------
CREATE TABLE IF NOT EXISTS tfb_tender (
    id              INT NOT NULL AUTO_INCREMENT,
    code            VARCHAR(20) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    status          ENUM('draft','sent','closed','awarded') NOT NULL DEFAULT 'draft',
    reply_deadline  DATE NULL,
    language        ENUM('pl','fr','nl') NOT NULL DEFAULT 'pl',
    intro           TEXT NULL,
    created_by      INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME NULL,
    closed_at       DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_tender_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Offre d'un fournisseur pour une matière : reçue (à la main, par mail ou en réponse à un
-- appel d'offres), acceptée (appliquée comme prix courant) ou refusée.
CREATE TABLE IF NOT EXISTS tfb_mp_offer (
    id                INT NOT NULL AUTO_INCREMENT,
    id_supplier       INT NOT NULL,
    id_raw_material   INT NOT NULL,
    pack_price        DECIMAL(12,4) NOT NULL,
    pack_size_base    DECIMAL(12,3) NOT NULL,
    price_per_base    DECIMAL(14,6) NOT NULL,
    currency          CHAR(3) NOT NULL DEFAULT 'PLN',
    valid_from        DATE NOT NULL,
    valid_to          DATE NULL,
    min_order_qty     DECIMAL(12,3) NULL,
    lead_time_days    INT NULL,
    source            ENUM('manual','tender','email','price_list') NOT NULL DEFAULT 'manual',
    id_tender         INT NULL,
    status            ENUM('received','accepted','rejected') NOT NULL DEFAULT 'received',
    notes             VARCHAR(500) NULL,
    created_by        INT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_mp_offer_material (id_raw_material, status),
    KEY ix_tfb_mp_offer_tender (id_tender),
    CONSTRAINT fk_tfb_mp_offer_tender FOREIGN KEY (id_tender) REFERENCES tfb_tender (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_mp_offer_price CHECK (pack_price > 0 AND pack_size_base > 0 AND price_per_base > 0),
    CONSTRAINT ck_tfb_mp_offer_validity CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Liste réduite de matières d'un appel d'offres ; id_awarded_offer = offre retenue.
CREATE TABLE IF NOT EXISTS tfb_tender_item (
    id                INT NOT NULL AUTO_INCREMENT,
    id_tender         INT NOT NULL,
    id_raw_material   INT NOT NULL,
    annual_qty_base   DECIMAL(14,3) NULL,
    notes             VARCHAR(255) NULL,
    id_awarded_offer  INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_tender_item (id_tender, id_raw_material),
    CONSTRAINT fk_tfb_tender_item_tender FOREIGN KEY (id_tender) REFERENCES tfb_tender (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_tender_item_offer FOREIGN KEY (id_awarded_offer) REFERENCES tfb_mp_offer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fournisseurs invités ; id_message = l'e-mail d'appel d'offres (communication_message).
CREATE TABLE IF NOT EXISTS tfb_tender_supplier (
    id            INT NOT NULL AUTO_INCREMENT,
    id_tender     INT NOT NULL,
    id_supplier   INT NOT NULL,
    email         VARCHAR(255) NOT NULL,
    id_message    BIGINT UNSIGNED NULL,
    invited_at    DATETIME NULL,
    responded_at  DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tfb_tender_supplier (id_tender, id_supplier),
    CONSTRAINT fk_tfb_tender_supplier_tender FOREIGN KEY (id_tender) REFERENCES tfb_tender (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('purchasing.consumption_months', '12', '12', 'int', 'mo', 'Okres zużycia surowców do analizy kosztów', 'Période de consommation MP pour l''analyse des coûts'),
('purchasing.price_alert_pct', '10', '10', 'decimal', '%', 'Alarm: wzrost ceny surowca powyżej', 'Alerte : hausse de prix MP au-delà de'),
('purchasing.offer_validity_days', '90', '90', 'int', 'd', 'Domyślna ważność oferty', 'Validité par défaut d''une offre'),
('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string', NULL, 'Dni robocze zadań dziennych (1 = poniedziałek)', 'Jours ouvrés des tâches quotidiennes (1 = lundi)');

-- Postes par défaut et leurs tâches (modifiables dans Organizacja › Stanowiska i zadania).
INSERT IGNORE INTO tfb_position (code, name_pl, name_fr, sort_order) VALUES
('ZAKUPY', 'Specjalista ds. zakupów', 'Acheteur', 10),
('JAKOSC', 'Specjalista ds. jakości', 'Responsable qualité', 20),
('KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Responsable production', 30),
('LOGISTYKA', 'Logistyka i wysyłki', 'Logistique et expéditions', 40),
('ADMINISTRACJA', 'Administracja i fakturowanie', 'Administration et facturation', 50);

INSERT INTO tfb_position_task (id_position, title_pl, title_fr, frequency, link_path, sort_order)
SELECT p.id, t.title_pl, t.title_fr, t.frequency, t.link_path, t.sort_order
FROM (
    SELECT 'ZAKUPY' AS code, 'Sprawdzić przyjęcia surowców i zarejestrować niezgodności' AS title_pl, 'Contrôler les réceptions de MP et encoder les non-conformités' AS title_fr, 'daily' AS frequency, '/purchasing/nc' AS link_path, 10 AS sort_order
    UNION ALL SELECT 'ZAKUPY', 'Wprowadzić nowe ceny surowców z faktur i cenników', 'Encoder les nouveaux prix MP (factures, tarifs)', 'weekly', '/materials/prices', 20
    UNION ALL SELECT 'ZAKUPY', 'Zarejestrować otrzymane oferty cenowe', 'Encoder les offres de prix reçues', 'weekly', '/purchasing/offers', 30
    UNION ALL SELECT 'ZAKUPY', 'Przegląd kosztów surowców i możliwych oszczędności', 'Revue des coûts MP et des gains possibles', 'monthly', '/purchasing', 40
    UNION ALL SELECT 'ZAKUPY', 'Zapytanie ofertowe na kluczowe surowce', 'Appel d''offres sur les MP principales', 'quarterly', '/purchasing/tenders', 50
    UNION ALL SELECT 'ZAKUPY', 'Ocena dostawców (ceny, terminy, niezgodności)', 'Évaluation des fournisseurs (prix, délais, NC)', 'quarterly', '/purchasing/nc', 60
    UNION ALL SELECT 'JAKOSC', 'Kontrola jakości przyjęć i produkcji — rejestr NC', 'Contrôle qualité réceptions et production — registre NC', 'daily', '/purchasing/nc', 10
    UNION ALL SELECT 'JAKOSC', 'Analiza niezgodności i działań korygujących', 'Analyse des NC et des actions correctives', 'monthly', '/purchasing/nc', 20
    UNION ALL SELECT 'JAKOSC', 'Audyt dostawcy', 'Audit fournisseur', 'quarterly', NULL, 30
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Plan produkcji na jutro', 'Plan de production du lendemain', 'daily', '/production/planning', 10
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Przegląd kosztu receptur (wpływ cen surowców)', 'Revue du coût des recettes (impact des prix MP)', 'monthly', '/purchasing', 20
    UNION ALL SELECT 'KIEROWNIK_PRODUKCJI', 'Przegląd wydajności i odpadów', 'Revue de la performance et des pertes', 'quarterly', NULL, 30
    UNION ALL SELECT 'LOGISTYKA', 'Wysyłki dnia i dokumenty (WZ, CMR, WDT)', 'Expéditions du jour et documents (WZ, CMR, WDT)', 'daily', '/shipping-documents/create', 10
    UNION ALL SELECT 'LOGISTYKA', 'Rozliczenie przewoźników', 'Décompte des transporteurs', 'monthly', '/shipments', 20
    UNION ALL SELECT 'ADMINISTRACJA', 'Kontrola wysłanych faktur i płatności', 'Contrôle des factures envoyées et des paiements', 'daily', '/communication/messages', 10
    UNION ALL SELECT 'ADMINISTRACJA', 'Zamknięcie miesiąca — dokumenty dla księgowości', 'Clôture du mois — documents pour la comptabilité', 'monthly', '/communication/automations', 20
) t
JOIN tfb_position p ON p.code = t.code
WHERE NOT EXISTS (SELECT 1 FROM tfb_position_task x WHERE x.id_position = p.id);

-- Modèles d'e-mail des appels d'offres (fournisseurs polonais par défaut, français possible).
INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'tender_request', 'pl', 'Zapytanie ofertowe', 'Zapytanie ofertowe {{ tender.code }} — {{ tender.title }}',
'Dzień dobry,\n\nprosimy o ofertę cenową na poniższe surowce (zapotrzebowanie roczne orientacyjne):\n\n{{ tender.items }}\n\nProsimy o podanie ceny za opakowanie, wielkości opakowania, minimalnej ilości zamówienia i terminu dostawy.\nOdpowiedź prosimy przesłać do {{ tender.deadline }}.\n{{ tender.intro }}\n\nZ poważaniem,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'tender_request' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'tender_request', 'fr', 'Appel d''offres', 'Appel d''offres {{ tender.code }} — {{ tender.title }}',
'Bonjour,\n\nnous vous remercions de nous faire une offre de prix pour les matières suivantes (besoin annuel indicatif) :\n\n{{ tender.items }}\n\nMerci d''indiquer le prix par conditionnement, la taille du conditionnement, la quantité minimale de commande et le délai de livraison.\nRéponse souhaitée pour le {{ tender.deadline }}.\n{{ tender.intro }}\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'tender_request' AND language_code = 'fr' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'tender_award', 'pl', 'Wynik zapytania ofertowego', 'Wynik zapytania ofertowego {{ tender.code }}',
'Dzień dobry,\n\ndziękujemy za ofertę w zapytaniu {{ tender.code }} ({{ tender.title }}).\n{{ tender.result }}\n\nZ poważaniem,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'tender_award' AND language_code = 'pl' AND is_default = 1);

INSERT INTO communication_template (template_type, language_code, name, subject_template, body_template, is_default, created_by)
SELECT 'tender_award', 'fr', 'Résultat de l''appel d''offres', 'Résultat de l''appel d''offres {{ tender.code }}',
'Bonjour,\n\nmerci pour votre offre dans le cadre de l''appel d''offres {{ tender.code }} ({{ tender.title }}).\n{{ tender.result }}\n\nCordialement,\n{{ company.name }}', 1, 'migration'
WHERE NOT EXISTS (SELECT 1 FROM communication_template WHERE template_type = 'tender_award' AND language_code = 'fr' AND is_default = 1);
