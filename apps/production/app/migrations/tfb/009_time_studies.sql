-- Module « Gestion de production atelier » — P2 : chronométrages des étapes de gamme.
-- Un chronométrage = une mesure réelle d'une étape : quantité, nombre de passages, durée.
-- La norme proposée est la moyenne glissante des timing.moving_average_n dernières mesures
-- retenues ; elle ne remplace la norme de la gamme que sur décision (apply-standard).

CREATE TABLE IF NOT EXISTS tfb_prod_time_study (
    id               INT NOT NULL AUTO_INCREMENT,
    id_routing_step  INT NOT NULL,
    id_workstation   INT NULL COMMENT 'Poste mesuré (alternative éventuelle) ; NULL = poste de l''étape',
    component        ENUM('variable','fixed') NOT NULL COMMENT 'variable = temps par pièce, fixed = temps par passage',
    quantity         DECIMAL(12,3) NOT NULL,
    passes           INT NOT NULL,
    duration_s       INT NOT NULL,
    unit_time_s      DECIMAL(12,4) NOT NULL COMMENT 'Temps unitaire déduit (par pièce ou par passage)',
    measured_at      DATETIME NOT NULL,
    id_employee      INT NULL COMMENT 'Opérateur mesuré (tfb_prod_employee)',
    measured_by      INT NULL COMMENT 'Utilisateur qui saisit (user)',
    note             VARCHAR(500) NULL,
    excluded         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mesure écartée (incident, valeur aberrante)',
    applied_at       DATETIME NULL COMMENT 'Date à laquelle la mesure a servi à une nouvelle norme',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tfb_time_study_step (id_routing_step, measured_at),
    CONSTRAINT fk_tfb_time_study_step FOREIGN KEY (id_routing_step) REFERENCES tfb_prod_routing_step (id) ON DELETE CASCADE,
    CONSTRAINT fk_tfb_time_study_ws FOREIGN KEY (id_workstation) REFERENCES tfb_prod_workstation (id),
    CONSTRAINT fk_tfb_time_study_employee FOREIGN KEY (id_employee) REFERENCES tfb_prod_employee (id) ON DELETE SET NULL,
    CONSTRAINT ck_tfb_time_study_values CHECK (quantity > 0 AND passes >= 1 AND duration_s > 0 AND unit_time_s >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tfb_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('timing.min_measurements', '3', '3', 'int', NULL, 'Minimalna liczba pomiarów przed propozycją normy', 'Nombre minimal de mesures avant de proposer une norme'),
('timing.deviation_alert_pct', '15', '15', 'decimal', '%', 'Alarm: odchylenie pomiarów od normy powyżej', 'Alerte : écart des mesures à la norme au-delà de'),
('timing.sample_quantities', '[10,60,240]', '[10,60,240]', 'json', NULL, 'Ilości przykładowe do kontroli czasu w kreatorze', 'Quantités d''exemple pour contrôler les temps dans l''assistant');
