-- [275] Wejście do ERP przez kod QR (makieta 40, validée le 12/11/2026) : pour les postes cochés (Administracja i
--   fakturowanie, Kierownik produkcji), après la connexion l'ERP affiche le QR personnel (personne + jour) ; la personne le
--   scanne avec son téléphone appairé → entrée dans Czas pracy → l'ERP s'ouvre. Le soir « Zakończ dzień » → même QR →
--   sortie (question marchandise) → déconnexion. Entrée déjà pointée à la tablette de bureau = ERP ouvert directement.
--   L'administrateur n'est jamais concerné. Rejouable : CREATE IF NOT EXISTS, ALTER conditionnel, INSERT … WHERE NOT EXISTS.

-- Compte ERP ↔ personne de Czas pracy (une liaison, réglée dans la fiche de l'utilisateur).
CREATE TABLE IF NOT EXISTS n_user_employee (
    id_user     INT NOT NULL PRIMARY KEY COMMENT 'user.id',
    id_employee INT NOT NULL COMMENT 'n_prod_employee.id',
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_n_user_employee_employee (id_employee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Poste : entrée dans l'ERP par QR (activé à la création pour les deux postes de la maquette).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'n_position' AND COLUMN_NAME = 'erp_qr');
SET @sql := IF(@c = 0, 'ALTER TABLE n_position ADD COLUMN erp_qr TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''wejście do ERP przez QR (czas pracy)'' AFTER active', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@c = 0, 'UPDATE n_position SET erp_qr = 1 WHERE code IN (''ADMINISTRACJA'', ''KIEROWNIK_PRODUKCJI'')', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- « Appareil » interne de l'ERP (mode office, sans tablette) : il signe les QR personnels affichés par l'ERP, comme la
-- tablette de bureau ; caché de la liste des tablettes. Son jeton n'est donné à personne.
INSERT INTO n_prod_device (name, mode, token_hash, active)
SELECT 'ERP — wejście przez QR', 'office', SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256), 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM n_prod_param WHERE code = 'erp.qr_device_id');
INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr)
SELECT 'erp.qr_device_id', CAST(MAX(id) AS CHAR), NULL, 'int', NULL, 'ERP : wewnętrzne urządzenie kodów QR wejścia (nie zmieniać)', 'ERP : appareil interne des QR d''entrée (ne pas modifier)'
FROM n_prod_device WHERE name = 'ERP — wejście przez QR';

INSERT IGNORE INTO n_prod_param (code, value, default_value, value_type, unit, description_pl, description_fr) VALUES
('erp.qr_ttl_s', '180', '180', 'int', 's', 'ERP : ważność kodu QR wejścia / wyjścia (odnawiany sam)', 'ERP : validité du QR d''entrée / sortie (renouvelé tout seul)');
