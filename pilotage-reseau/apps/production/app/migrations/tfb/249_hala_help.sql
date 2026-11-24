-- [411] Tablet hali : bouton « Pomoc » sur chaque écran et vue « Zmiana » du brygadzista (makieta, ekran H).
--   - n_hala_help : wezwanie d'un groupe (personne, tablette, étape, motif : pomoc, zły worek, za ciepło) — statut
--     open → taken (« Idę », qui et quand) → closed ; lu par la vue du brygadzista (rafraîchie toutes les hala.help_poll_s s) ;
--   - qui voit « Zmiana » : les postes de hala.shift_lead_positions (n_position.code, séparés par des virgules) ;
--   - zmiana : hala.shift_start / hala.shift_end (retard affiché = avance du plan sur l'heure, au-delà de
--     hala.delay_tolerance_pct %) ;
--   - droit ERP « hala » (Produkcja › Tablet hali : kroki i strefy sprzątania) : Jakość et Kierownik produkcji seulement.
-- Rejouable : CREATE TABLE IF NOT EXISTS, INSERT … WHERE NOT EXISTS / INSERT IGNORE.

CREATE TABLE IF NOT EXISTS n_hala_help (
    id          BIGINT NOT NULL AUTO_INCREMENT,
    work_date   DATE NOT NULL,
    id_group    INT NULL COMMENT 'n_prod_group.id',
    id_device   INT NULL,
    id_employee INT NULL COMMENT 'kto woła (n_prod_employee.id)',
    step        VARCHAR(16) NULL COMMENT 'ekran : home, make, bags, chill, clean, pack, end',
    reason      ENUM('help','bag','chill') NOT NULL DEFAULT 'help' COMMENT 'pomoc, zły / przeterminowany worek, za ciepło po czasie',
    note        VARCHAR(255) NULL,
    status      ENUM('open','taken','closed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL,
    taken_at    DATETIME NULL,
    taken_by    INT NULL COMMENT 'n_prod_employee.id brygadzisty',
    closed_at   DATETIME NULL,
    closed_by   INT NULL,
    PRIMARY KEY (id),
    KEY idx_hala_help_status (status, work_date),
    KEY idx_hala_help_group (id_group, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tablet hali : wezwania « Pomoc » do brygadzisty [411]';

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.shift_lead_positions', 'BRYGADZISTA,KIEROWNIK_PRODUKCJI', 'BRYGADZISTA,KIEROWNIK_PRODUKCJI', 'string', NULL,
    'Tablet hali : stanowiska (kody, po przecinku), które po PIN-ie widzą « Zmianę » (stan grup, wezwania, zatwierdzenie zmiany)',
    'Tablette atelier : postes (codes, séparés par des virgules) qui voient « Zmiana » après le PIN (état des groupes, appels, validation)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.shift_lead_positions');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.shift_start', '06:00', '06:00', 'time', NULL,
    'Tablet hali : początek zmiany (opóźnienie grup w widoku brygadzisty)',
    'Tablette atelier : début de la journée (retard des groupes dans la vue du chef d''équipe)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.shift_start');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.shift_end', '14:00', '14:00', 'time', NULL,
    'Tablet hali : koniec zmiany',
    'Tablette atelier : fin de la journée'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.shift_end');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.delay_tolerance_pct', '15', '15', 'int', '%',
    'Tablet hali : grupa na złoto, gdy zrobione jest o tyle procent mniej niż czas zmiany, który minął',
    'Tablette atelier : groupe en or quand le fait est en retard de ce pourcentage sur le temps écoulé'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.delay_tolerance_pct');

INSERT INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'hala.help_poll_s', '15', '15', 'int', 's',
    'Tablet hali : co ile sekund widok « Zmiana » i ekran z wezwaniem sprawdzają wezwania',
    'Tablette atelier : intervalle de rafraîchissement des appels (vue « Zmiana », écran avec un appel)'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'hala.help_poll_s');

-- Droit de la page ERP « Tablet hali : kroki i strefy » : Jakość et Kierownik produkcji (l'administrateur voit tout).
INSERT IGNORE INTO n_page_access (id_position, page_key)
SELECT p.id, 'hala' FROM n_position p WHERE p.code IN ('JAKOSC', 'KIEROWNIK_PRODUKCJI');
