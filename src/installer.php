<?php
declare(strict_types=1);

/**
 * Auto-installation — même philosophie que le panel consultant
 * (ReportShareRepository::ensureSchema) : l'application crée ses tables
 * elle-même au premier appel, avec le compte MySQL applicatif. Aucun accès
 * DBA n'est requis, seulement le privilège CREATE sur la base.
 *
 *  - tables absentes  → exécute sql/schema.sql (CREATE TABLE IF NOT EXISTS,
 *    ne touche pas aux tables existantes du panel) ;
 *  - base vide        → charge sql/seed.sql (réseau de démonstration) ;
 *  - premier passage  → génère le secret de session (ceo_app_setting).
 */

function ensureInstalled(): void
{
    try {
        Db::row('SELECT 1 FROM ceo_app_setting LIMIT 1');
    } catch (PDOException $e) {
        if (!isMissingTable($e)) { throw $e; }
        runSqlFile(__DIR__ . '/../sql/schema.sql');
    }

    $n = Db::row('SELECT COUNT(*) AS n FROM ceo_shop');
    if ((int) $n['n'] === 0) {
        runSqlFile(__DIR__ . '/../sql/seed.sql');
    }

    if (setting('authSecret') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['authSecret', json_encode(bin2hex(random_bytes(32)))]);
    }

    ensureValidation();
    ensureScouting();
}

/**
 * Tables du scouting commercial, à chaque démarrage — même raison que
 * ensureValidation() : une installation déjà en service ne rejoue pas
 * schema.sql. Idempotent (CREATE TABLE IF NOT EXISTS).
 */
function ensureScouting(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scouting_tile ('
        . 'sector TINYINT UNSIGNED PRIMARY KEY,'
        . 'fetched_at DATETIME NOT NULL,'
        . 'payload MEDIUMTEXT NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scouting_competitor ('
        . 'osm_id VARCHAR(20) PRIMARY KEY,'
        . "name VARCHAR(200) NOT NULL DEFAULT '',"
        . "commune VARCHAR(120) NOT NULL DEFAULT '',"
        . "arrondissement VARCHAR(60) NOT NULL DEFAULT '',"
        . 'rating DECIMAL(2,1) NULL,'
        . 'reviews INT UNSIGNED NULL,'
        . "rating_source ENUM('google','manuel') NULL,"
        . 'comment VARCHAR(200) NULL,'
        . 'updated_at DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scouting_candidate ('
        . 'id BIGINT UNSIGNED PRIMARY KEY,'
        . 'name VARCHAR(200) NOT NULL,'
        . 'commune VARCHAR(120) NOT NULL,'
        . 'arrondissement VARCHAR(60) NOT NULL,'
        . 'province VARCHAR(60) NOT NULL,'
        . 'lat DECIMAL(9,6) NOT NULL,'
        . 'lng DECIMAL(9,6) NOT NULL,'
        . 'households INT UNSIGNED NOT NULL,'
        . 'market INT UNSIGNED NOT NULL,'
        . 'emprise DECIMAL(5,4) NOT NULL,'
        . 'revenue INT UNSIGNED NOT NULL,'
        . 'score TINYINT UNSIGNED NOT NULL,'
        . 'shops SMALLINT UNSIGNED NOT NULL,'
        . 'strong SMALLINT UNSIGNED NOT NULL,'
        . 'revenue_m2 INT UNSIGNED NOT NULL,'
        . 'created_at DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_scouting_population ('
        . 'ins CHAR(5) PRIMARY KEY,'
        . 'population INT UNSIGNED NOT NULL,'
        . 'imported_at DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Les ajouts de la validation des tâches, à chaque démarrage.
 *
 * `ensureInstalled()` ne rejoue `schema.sql` que si `ceo_app_setting` est
 * absente, et `seed.sql` que si la base est vide : sur une installation DÉJÀ
 * en service, aucun des deux ne repasse. Sans ce bloc, la mise à jour laissait
 * une base sans colonne `note`, sans `ceo_task_issue` et sans réglage — donc
 * un écran de validation aux niveaux vides, où l'on ne peut plus rien
 * signaler. La panne était silencieuse : ni erreur, ni trace.
 *
 * Tout est idempotent : on regarde avant d'écrire.
 */
function ensureValidation(): void
{
    $manque = static function (string $table, string $colonne): bool {
        $r = Db::row('SELECT COUNT(*) AS n FROM information_schema.columns'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $colonne]);
        return (int) $r['n'] === 0;
    };
    if ($manque('ceo_project_task', 'note')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN note TINYINT NULL');
    }
    if ($manque('ceo_project_task', 'validated_by')) {
        Db::exec('ALTER TABLE ceo_project_task ADD COLUMN validated_by VARCHAR(80) NULL');
    }

    Db::exec('CREATE TABLE IF NOT EXISTS ceo_task_issue ('
        . 'id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'task_id VARCHAR(16) NOT NULL,'
        . 'note TINYINT NOT NULL,'
        . 'famille VARCHAR(60) NOT NULL,'
        . 'type VARCHAR(80) NOT NULL,'
        . 'comment TEXT NULL,'
        . 'recipients VARCHAR(400) NULL,'
        . "status ENUM('nouveau','vu','traite') NOT NULL DEFAULT 'nouveau',"
        . 'created_at DATETIME NOT NULL,'
        . 'created_by VARCHAR(80) NOT NULL,'
        . 'seen_at DATETIME NULL,'
        . 'closed_at DATETIME NULL,'
        . 'closed_by VARCHAR(80) NULL,'
        . 'KEY idx_task (task_id),'
        . 'KEY idx_status (status, created_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    if (setting('signalement') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['signalement', json_encode(signalementDefaut(), JSON_UNESCAPED_UNICODE)]);
    }
}

/**
 * Les cinq niveaux, le seuil et le référentiel famille → type.
 *
 * LA source du référentiel livré. Elle n'est pas dans `seed.sql` : ce fichier
 * ne se charge que sur une base vide, et le réglage doit exister partout.
 * Modifiable ensuite par `PUT /parametres/signalement` — ce défaut ne réécrit
 * jamais une valeur déjà posée.
 *
 * Les mêmes cinq niveaux servent au panel consultant pour les tâches boutique :
 * un « majeur » doit vouloir dire la même chose des deux côtés, sinon les
 * chiffres des deux applications ne s'additionnent pas.
 */
function signalementDefaut(): array
{
    return [
        'seuil'   => 4,
        'niveaux' => [
            ['n' => 5, 'nom' => 'Exemplaire',              'couleur' => '#C9A227', 'aide' => "au-dessus de l'attendu"],
            ['n' => 4, 'nom' => 'Conforme',                'couleur' => '#2D7A3E', 'aide' => 'livrable accepté'],
            ['n' => 3, 'nom' => 'Non conforme — mineur',   'couleur' => '#D97706', 'aide' => 'détail à reprendre'],
            ['n' => 2, 'nom' => 'Non conforme — majeur',   'couleur' => '#C0182B', 'aide' => 'écart net, à reprendre'],
            ['n' => 1, 'nom' => 'Non conforme — critique', 'couleur' => '#8D1D2C', 'aide' => 'à reprendre immédiatement'],
        ],
        'familles' => [
            ['nom' => 'Livrable',           'types' => ['Incomplet', 'Non conforme au brief', 'Sans relecture', 'Format inexploitable']],
            ['nom' => 'Délai',              'types' => ['Rendu hors délai', 'Sans prévenir', 'Report répété']],
            ['nom' => 'Qualité de service', 'types' => ['Injoignable', 'Compte rendu absent', 'Consigne non suivie']],
            ['nom' => 'Budget',             'types' => ['Dépassement', 'Non justifié']],
            ['nom' => 'Autre',              'types' => ['À préciser']],
        ],
    ];
}

function isMissingTable(PDOException $e): bool
{
    return $e->getCode() === '42S02' || str_contains($e->getMessage(), '42S02')
        || str_contains($e->getMessage(), "doesn't exist");
}

/** Exécute un fichier SQL instruction par instruction (fins « ;\n »). */
function runSqlFile(string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Fichier SQL introuvable : ' . $path);
    }
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $stmt = trim($stmt);
        // ignorer le vide et les blocs entièrement en commentaire
        if ($stmt === '' || preg_match('/^(--[^\n]*\n?)+$/', $stmt)) { continue; }
        Db::pdo()->exec($stmt);
    }
}
