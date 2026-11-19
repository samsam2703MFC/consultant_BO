<?php
declare(strict_types=1);

/**
 * Banc local de la réorganisation — JAMAIS sur le serveur : il refuse toute base dont l'hôte n'est pas 127.0.0.1 ou
 * localhost, ou dont le port est 3306 (le port du serveur de test).
 *
 *   php reorg/banc.php construire   crée toutes les tables du code (installation automatique + toutes les fonctions
 *                                   ensure…/…Schema/…Table sans argument), puis 3 lignes dans chaque table vide
 *   php reorg/banc.php compter      une ligne par table : nom et COUNT(*) exact (JSON sur la sortie)
 *
 * Connexion : les variables COCKPIT_DB_* (comme l'application).
 */

$h = getenv('COCKPIT_DB_HOST') ?: '';
$p = getenv('COCKPIT_DB_PORT') ?: '';
if (!in_array($h, ['127.0.0.1', 'localhost'], true) || $p === '' || $p === '3306') {
    fwrite(STDERR, "banc : base refusée (hôte « $h », port « $p ») — banc local seulement\n");
    exit(2);
}

$racine = getenv('BANC_RACINE') ?: dirname(__DIR__);
$_SERVER += ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_URI' => '/'];

/** Charge le code serveur comme le fait le point d'entrée de l'API, sans router la requête. */
function bancCharger(string $racine): void
{
    $entree = is_file("$racine/shared/public/api/index.php") ? "$racine/shared/public/api/index.php" : "$racine/pilotage-reseau/public/api/index.php";
    $src = file_get_contents($entree);
    // Seuls les require du point d'entrée : tout ce qui suit (le routage) est ignoré.
    preg_match_all('/^\s*require(?:_once)?\s+(.+?);/m', $src, $m);
    $dir = dirname($entree);
    foreach ($m[1] as $expr) {
        $chemin = eval('return ' . str_replace('__DIR__', var_export($dir, true), $expr) . ';');
        if (is_string($chemin) && is_file($chemin)) { require_once $chemin; }
    }
}

bancCharger($racine);
$action = $argv[1] ?? 'compter';

if ($action === 'construire') {
    Db::pdo()->exec("SET SESSION sql_mode = ''");
    try { ensureInstalled(); } catch (Throwable $e) { fwrite(STDERR, 'ensureInstalled : ' . $e->getMessage() . "\n"); }
    $faites = 0; $ko = [];
    foreach (get_defined_functions()['user'] as $f) {
        $rf = new ReflectionFunction($f);
        $nom = $rf->getName();
        if ($rf->getNumberOfRequiredParameters() > 0) { continue; }
        if (!preg_match('/^(ensure[A-Z]\w*|\w+(Schema|Table|Tables|Tables?Ensure))$/', $nom)) { continue; }
        if (preg_match('/^(ensureInstalled)$/', $nom)) { continue; }
        try { $nom(); $faites++; } catch (Throwable $e) { $ko[] = "$nom : " . mb_substr($e->getMessage(), 0, 140); }
    }
    fwrite(STDERR, "fonctions de création appelées : $faites ; en échec : " . count($ko) . "\n" . implode("\n", $ko) . "\n");
    // Trois lignes dans chaque table vide (valeurs par défaut, mode non strict) : de quoi compter.
    $tables = array_column(Db::rows('SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"'), 't');
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    $remplies = 0;
    foreach ($tables as $t) {
        if ((int) Db::row("SELECT COUNT(*) n FROM `$t`")['n'] > 0) { continue; }
        try {
            for ($i = 0; $i < 3; $i++) { Db::pdo()->exec("INSERT INTO `$t` () VALUES ()"); }
            $remplies++;
        } catch (Throwable $e) {
            // Clé primaire sans auto-incrément : une ligne suffit, avec des valeurs distinctes si possible.
            try { Db::pdo()->exec("INSERT IGNORE INTO `$t` () VALUES ()"); $remplies++; } catch (Throwable $x) { /* table spéciale */ }
        }
    }
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    fwrite(STDERR, count($tables) . " tables, $remplies remplies\n");
    exit(0);
}

if ($action === 'compter') {
    $out = [];
    foreach (Db::rows('SELECT table_name t, table_type k FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name') as $r) {
        $out[$r['t']] = $r['k'] === 'VIEW' ? 'vue' : (int) Db::row("SELECT COUNT(*) n FROM `{$r['t']}`")['n'];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

fwrite(STDERR, "action inconnue\n");
exit(1);
