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

    // Jeu de démonstration : chargé UNIQUEMENT s'il est explicitement activé
    // (config 'seed' = true, ou variable d'env COCKPIT_SEED=1). Désactivé par
    // défaut → une base neuve reste vide, prête pour les vraies données.
    if (seedEnabled()) {
        $n = Db::row('SELECT COUNT(*) AS n FROM ceo_shop');
        if ((int) $n['n'] === 0) {
            runSqlFile(__DIR__ . '/../sql/seed.sql');
        }
    }

    if (setting('authSecret') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['authSecret', json_encode(bin2hex(random_bytes(32)))]);
    }
}

/** Le jeu de démonstration doit-il être chargé ? Désactivé par défaut. */
function seedEnabled(): bool
{
    if ((string) (getenv('COCKPIT_SEED') ?: '') === '1') { return true; }
    $cfg = Db::config();
    return !empty($cfg['seed']);
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
