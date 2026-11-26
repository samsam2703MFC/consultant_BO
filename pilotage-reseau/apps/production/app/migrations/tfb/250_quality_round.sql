-- [415] Tablet jakości Kierownika produkcji (makieta hali, ekrany I « obchód jakości » i J « kontrola partii ») : le
-- Kierownik produkcji (ou la personne au poste Jakość), identifié par son PIN sur une tablette appairée (/tablet/quality),
-- parcourt les parties du jour (n_prod_output, toutes les grupy) et en contrôle une : poids de N pièces (cible = poids net
-- d'une pièce de la fiche produit final_product.weight, tolérance en %), aspect bon / mauvais (+ photo, stockée comme les
-- étiquettes de lot dans n_prod_lot_photo), sacs de matières de la partie (les mêmes scans que la tablette du groupe :
-- n_prod_output_lot), échantillon (n_prod_sample, « Próbki produkcji »). Hors tolérance ou aspect mauvais : non-conformité
-- PROPOSÉE (registre n_nc existant), jamais créée sans accord.
--  - n_qc_control : une ligne par contrôle (le dernier compte) ; le résultat est aussi porté sur la partie par le mécanisme
--    existant de la tablette du groupe (n_prod_output.qc_status / qc_at / qc_employee / qc_note / id_nc), ce qui comble le
--    trou « brak kontroli jakości » de l'assistant « Partie i kontrola dnia » sans toucher à n_wizard_done ;
--  - n_prod_param qc_round.* : nombre de pièces pesées, tolérance du poids en %, heures des obchody du jour (Ustawienia ›
--    Parametry ; bornes dans ParamRules).
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS. Pas de clé étrangère vers les tables de l'ERP.

CREATE TABLE IF NOT EXISTS n_qc_control (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_output       BIGINT NOT NULL COMMENT 'n_prod_output.id (partia produkcji)',
    work_date       DATE NOT NULL COMMENT 'dzień produkcji partii',
    round_no        TINYINT UNSIGNED NULL COMMENT 'obchód dnia (qc_round.times)',
    id_employee     INT NULL COMMENT 'n_prod_employee.id : Kierownik produkcji / Jakość (PIN na tablecie)',
    id_device       INT NULL COMMENT 'n_prod_device.id',
    weights         VARCHAR(255) NULL COMMENT 'wagi sztuk w g, po przecinku',
    target_g        DECIMAL(10,2) NULL COMMENT 'cel : waga netto sztuki z karty produktu',
    tolerance_pct   DECIMAL(5,2) NULL,
    weight_result   ENUM('ok','light','heavy','none') NOT NULL COMMENT 'średnia w zakresie / za lekka / za ciężka / bez celu',
    appearance      ENUM('good','bad') NOT NULL,
    id_photo        BIGINT NULL COMMENT 'n_prod_lot_photo.id',
    lots_total      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lots_missing    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'worki bez skanu w chwili zapisu',
    lots_requested  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = brak wysłany grupie (komunikat na tablecie grupy)',
    sample          ENUM('stored','planned','not_required') NULL COMMENT 'próbka partii w chwili zapisu',
    id_sample       INT NULL COMMENT 'n_prod_sample.id',
    result          ENUM('ok','warn','nc') NOT NULL COMMENT 'dobrze / odchylenie zaakceptowane bez NC / niezgodność',
    id_nc           INT NULL COMMENT 'n_nc.id (niezgodność zgłoszona z kontroli)',
    note            VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_qc_control_day (work_date),
    KEY idx_qc_control_output (id_output, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Kontrola partii na tablecie jakości (obchód Kierownika produkcji) [415]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'qc_round.pieces', '3', '3', 'int', 'szt.',
    'Tablet jakości : ile sztuk partii ważyć przy kontroli',
    'Tablette qualité : nombre de pièces du lot pesées au contrôle'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'qc_round.pieces');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'qc_round.weight_tolerance_pct', '2', '2', 'decimal', '%',
    'Tablet jakości : tolerancja średniej wagi sztuki wobec wagi z karty produktu (± %)',
    'Tablette qualité : tolérance du poids moyen d''une pièce par rapport au poids de la fiche produit (± %)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'qc_round.weight_tolerance_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'qc_round.times', '08:00,10:30,13:00', '08:00,10:30,13:00', 'string', NULL,
    'Tablet jakości : godziny obchodów dnia (GG:MM po przecinku) — « obchód 2 z 3 »',
    'Tablette qualité : heures des tournées du jour (HH:MM séparées par des virgules) — « tournée 2 sur 3 »'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'qc_round.times');
