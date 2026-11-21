#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Test de fonctionnement avant livraison (16/11/2026, après la panne de l'API : une fonction déclarée deux fois,
 * `auReglage`, empêchait PHP de charger public/api/index.php — tout le cockpit répondait 500, alors que `php -l`
 * passait sur chaque fichier).
 *
 * Chaque point d'entrée de l'API est lancé pour de vrai, en ligne de commande, sans base de données : il doit charger
 * TOUS ses fichiers et aller jusqu'à la connexion à la base (« base de données indisponible », SQLSTATE 2002/1045).
 * Échoue s'il s'arrête avant : fonction ou classe déclarée deux fois, erreur d'analyse, fonction ou classe introuvable,
 * constante redéfinie.
 *
 *   php bin/test_chargement.php        (0 : tout se charge ; 1 : l'erreur de chargement, point d'entrée par point d'entrée)
 *
 * Sans effet de bord : aucune base n'est jointe (127.0.0.1:1, port fermé), aucune requête ne part.
 */

// Chemins depuis la racine du dépôt (réorganisation du 19/11/2026) : le routeur partagé et le RH (la copie de l'API
// du module Marketing, marketing/public/api/marketing/, est retirée le 21/11/2026).
const ENTREES = [
    'shared/public/api/index.php' => '/consulant_bo/api/cockpit/__test_chargement__',
    'pilotage-reseau/public/api/hr/index.php' => '/hr/api/v1/__test_chargement__',
];
// Ce qui dit qu'un fichier n'a pas pu se charger (et non que la base manque).
const ARRETS = '/Cannot redeclare|Cannot declare (class|interface|trait|enum)|Parse error|syntax error|Call to undefined (function|method)'
    . '|(Class|Interface|Trait|Enum) "[^"]+" not found|Constant \S+ already defined|already in use|Failed opening required|Failed to open stream'
    . '|Cannot use .+ as .+ because|must be compatible with|Unsupported operand types/i';
// Ce qui dit que tout s'est chargé et que l'exécution est arrivée à la base.
const BASE = '/SQLSTATE\[HY000\] \[(2002|2003|1045|2005)\]|could not find driver|base de données indisponible|indisponible \(base/i';

$racine = dirname(__DIR__, 2);
$env = array_merge(getenv(), [
    'REQUEST_METHOD' => 'GET', 'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1',
    // Une base qui ne répond pas, quelle que soit la configuration trouvée : rien ne s'écrit nulle part.
    'COCKPIT_DB_HOST' => '127.0.0.1', 'COCKPIT_DB_PORT' => '1', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
]);
$ko = 0;
foreach (ENTREES as $f => $uri) {
    if (!is_file($racine . '/' . $f)) { echo "  --  $f absent\n"; continue; }
    $e = $env + [];
    $e['REQUEST_URI'] = $uri; $e['SCRIPT_NAME'] = dirname($uri) . '/index.php'; $e['SCRIPT_FILENAME'] = $racine . '/' . $f;
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', '-d', 'log_errors=0', $f],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $racine, $e);
    if (!is_resource($p)) { echo "  KO  $f : impossible de lancer PHP\n"; $ko++; continue; }
    $out = stream_get_contents($tubes[1]) . "\n" . stream_get_contents($tubes[2]);
    fclose($tubes[1]); fclose($tubes[2]);
    $rc = proc_close($p);
    $premiere = trim((string) strtok(trim($out), "\n"));
    if (preg_match(ARRETS, $out, $m)) {
        preg_match('/^.*' . preg_quote($m[0], '/') . '.*$/m', $out, $l);
        echo "  KO  $f ne se charge pas : " . trim(mb_substr($l[0] ?? $m[0], 0, 400)) . "\n"; $ko++;
    } elseif (preg_match(BASE, $out)) {
        echo "  ok  $f se charge en entier (arrêt attendu : pas de base)\n";
    } elseif ($rc === 0 && $premiere !== '' && json_decode($premiere, true) !== null) {
        echo "  ok  $f se charge et répond : " . mb_substr($premiere, 0, 120) . "\n";
    } else {
        echo "  KO  $f : sortie inattendue (code $rc) : " . mb_substr($premiere, 0, 300) . "\n"; $ko++;
    }
}
echo $ko ? "$ko point(s) d'entrée ne se chargent pas\n" : "tous les points d'entrée se chargent\n";
exit($ko ? 1 : 0);
