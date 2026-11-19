<?php
declare(strict_types=1);

/**
 * Les migrations de la réorganisation en quatre domaines (19/11/2026) — `shared/db/migrations/NNN_nom.php`.
 *
 * Chaque fichier rend ['quoi' => '…', 'up' => callable, 'down' => callable]. Elles passent dans l'ordre de leur
 * numéro, une fois chacune ; l'historique vit dans ceo_app_setting (clé `migrations`), sans nouvelle table. Elles
 * sont lancées :
 *   - en tête de ensureInstalled(), AVANT toute création de table (sinon un CREATE TABLE IF NOT EXISTS poserait une
 *     table vide sous le nouveau nom, et le renommage deviendrait impossible) ;
 *   - par bin/deploy.sh, juste après la copie des fichiers : php shared/bin/migrer.php up.
 *
 * Renommer une table : migrationRenommer(['ancien' => 'nouveau', …]). Une seule instruction RENAME TABLE (atomique :
 * les données, index, clés étrangères et déclencheurs suivent ; rien n'est copié, rien n'est supprimé). Jamais de DROP :
 * si l'ancien et le nouveau nom existent tous les deux, ou si une vue lit l'ancien nom, la migration s'arrête et le
 * dit. Le nombre de lignes de chaque table est relevé avant et après, et doit être identique.
 */

const MIGRATIONS_DOSSIER = __DIR__ . '/../db/migrations';
const MIGRATIONS_CLE = 'migrations';

/** Les migrations connues, dans l'ordre : [nom => fichier]. */
function migrationsListe(): array
{
    $out = [];
    foreach (glob(MIGRATIONS_DOSSIER . '/[0-9][0-9][0-9]_*.php') ?: [] as $f) { $out[basename($f, '.php')] = $f; }
    ksort($out, SORT_STRING);
    return $out;
}

/** Celles déjà passées : [nom => ['le' => …, 'bilan' => …]]. */
function migrationsFaites(): array
{
    try {
        $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [MIGRATIONS_CLE]);
    } catch (Throwable $e) { return []; }
    $v = $r === null ? [] : json_decode((string) $r['value'], true);
    return is_array($v) ? $v : [];
}

function migrationsNoter(array $faites): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [MIGRATIONS_CLE, json_encode($faites, JSON_UNESCAPED_UNICODE)]);
}

/** Une migration chargée : ['quoi', 'up', 'down']. */
function migrationCharger(string $fichier): array
{
    $m = require $fichier;
    if (!is_array($m) || !is_callable($m['up'] ?? null) || !is_callable($m['down'] ?? null)) {
        throw new RuntimeException('migration mal formée : ' . basename($fichier));
    }
    return $m;
}

/**
 * Passe les migrations en attente, dans l'ordre ; s'arrête à la première qui échoue (les suivantes attendent).
 * Rend ['faites' => [nom => bilan], 'erreur' => null|texte]. Un verrou MySQL évite deux passages en même temps.
 */
function migrationsAppliquer(): array
{
    $out = ['faites' => [], 'erreur' => null];
    $verrou = false;
    try { $verrou = (int) (Db::row("SELECT GET_LOCK('cockpit_migrations', 60) AS v")['v'] ?? 0) === 1; } catch (Throwable $e) { /* sans verrou */ }
    try {
        $faites = migrationsFaites();
        foreach (migrationsListe() as $nom => $f) {
            if (isset($faites[$nom])) { continue; }
            $m = migrationCharger($f);
            try {
                $bilan = ($m['up'])();
            } catch (Throwable $e) {
                $out['erreur'] = "$nom : " . $e->getMessage();
                migrationJournal('Erreur', "Migration $nom arrêtée : " . $e->getMessage());
                break;
            }
            $faites[$nom] = ['le' => date('Y-m-d H:i:s'), 'quoi' => (string) ($m['quoi'] ?? ''), 'bilan' => $bilan];
            migrationsNoter($faites);
            $out['faites'][$nom] = $bilan;
            migrationJournal('Info', "Migration $nom passée : " . migrationResume($bilan));
        }
    } finally {
        if ($verrou) { try { Db::row("SELECT RELEASE_LOCK('cockpit_migrations')"); } catch (Throwable $e) { /* rien */ } }
    }
    return $out;
}

/** Défait une migration passée (et elle seule) : le retour arrière. */
function migrationDefaire(string $nom): array
{
    $faites = migrationsFaites();
    $liste = migrationsListe();
    if (!isset($faites[$nom])) { throw new RuntimeException("$nom n'a pas été passée"); }
    if (!isset($liste[$nom])) { throw new RuntimeException("$nom : fichier introuvable"); }
    $apres = array_filter(array_keys($faites), static fn ($n) => strcmp($n, $nom) > 0);
    if ($apres) { throw new RuntimeException("défaire d'abord : " . implode(', ', $apres)); }
    $bilan = (migrationCharger($liste[$nom])['down'])();
    unset($faites[$nom]);
    migrationsNoter($faites);
    migrationJournal('Info', "Migration $nom défaite : " . migrationResume($bilan));
    return $bilan;
}

function migrationResume(mixed $bilan): string
{
    if (!is_array($bilan)) { return 'faite'; }
    $p = [];
    foreach ($bilan as $k => $v) { $p[] = $k . ' ' . (is_array($v) ? count($v) : (string) $v); }
    return implode(', ', $p);
}

function migrationJournal(string $genre, string $texte): void
{
    if (function_exists('journalAdd')) { try { journalAdd('Installation', $genre, 'Réorganisation', mb_substr($texte, 0, 900)); } catch (Throwable $e) { /* journal absent */ } }
}

/** Les tables (pas les vues) de la base, et leur nombre EXACT de lignes si demandé. */
function migrationTables(): array
{
    return array_column(Db::rows("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"), 't');
}

function migrationLignes(string $t): int
{
    return (int) (Db::row('SELECT COUNT(*) AS n FROM `' . str_replace('`', '``', $t) . '`')['n'] ?? 0);
}

/**
 * Renomme des tables, ancien → nouveau, en UNE instruction. Rend ['renommees' => [ancien => [nouveau, lignes]],
 * 'deja' => [...], 'absentes' => [...]]. Lève une exception, sans rien renommer, si un nouveau nom est déjà pris par une
 * table qui existe aussi sous l'ancien nom, ou si une vue lit un ancien nom.
 */
function migrationRenommer(array $paires): array
{
    $tables = array_flip(migrationTables());
    $out = ['renommees' => [], 'deja' => [], 'absentes' => []];
    $conflits = [];
    $faire = [];
    foreach ($paires as $a => $n) {
        $aE = isset($tables[$a]); $nE = isset($tables[$n]);
        if ($aE && $nE) { $conflits[] = "$a et $n existent tous les deux"; continue; }
        if ($aE) { $faire[$a] = $n; continue; }
        if ($nE) { $out['deja'][] = $n; continue; }
        $out['absentes'][] = $a;
    }
    if ($faire) {
        foreach (Db::rows('SELECT table_name AS v, view_definition AS d FROM information_schema.views WHERE table_schema = DATABASE()') as $v) {
            foreach (array_keys($faire) as $a) {
                if (preg_match('/[`.\s(]' . preg_quote($a, '/') . '[`\s,)]/', ' ' . (string) $v['d'] . ' ')) { $conflits[] = "la vue {$v['v']} lit $a"; }
            }
        }
    }
    if ($conflits) { throw new RuntimeException('rien n’est renommé : ' . implode(' ; ', $conflits)); }
    if (!$faire) { return $out; }
    $avant = [];
    foreach ($faire as $a => $n) { $avant[$a] = migrationLignes($a); }
    Db::exec('RENAME TABLE ' . implode(', ', array_map(static fn ($a, $n) => "`$a` TO `$n`", array_keys($faire), $faire)));
    foreach ($faire as $a => $n) {
        $apres = migrationLignes($n);
        if ($apres !== $avant[$a]) { throw new RuntimeException("$a → $n : $avant[$a] lignes avant, $apres après"); }
        $out['renommees'][$a] = [$n, $apres];
    }
    return $out;
}

/** Le retour arrière d'un renommage : les mêmes paires, dans l'autre sens. */
function migrationRendre(array $paires): array
{
    return migrationRenommer(array_flip($paires));
}
