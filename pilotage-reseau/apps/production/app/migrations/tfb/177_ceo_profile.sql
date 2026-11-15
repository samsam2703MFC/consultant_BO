-- [281] Profil CEO (makieta 47, validée le 12/11/2026) : tableau « Kontrola » (/control) — tout le monde aujourd'hui (entrée,
--   tâches, obligatoires, remplissage, activité ERP), la semaine par poste, les décisions (débloquer, rappeler).
--   L'administrateur choisit son profil sous l'avatar (Administrator = travail et réglages ; CEO = contrôle). Un compte peut
--   aussi recevoir le seul profil CEO (n_user_ceo, ex. associé) : il voit tout, ne règle rien — seulement les décisions ;
--   jamais de verrou (QR, tâches obligatoires). Rejouable.
CREATE TABLE IF NOT EXISTS n_user_ceo (
    id_user    INT NOT NULL PRIMARY KEY COMMENT 'konto z samym profilem CEO (kontrola, bez ustawień)',
    granted_by INT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Profil CEO : konta tylko do kontroli [281]';
