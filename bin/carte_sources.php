<?php
declare(strict_types=1);
/**
 * LA CARTE DES SOURCES — d'où vient ce que chaque écran affiche.
 *
 *   php bin/carte_sources.php           établit la carte et l'enregistre
 *   php bin/carte_sources.php --dire    l'affiche sans rien enregistrer
 *
 * La question n'est pas théorique : 57 des 151 lectures passent par le panel
 * consultant. Quand il ralentit, ces écrans-là ralentissent ; quand il tombe,
 * ils tombent. Savoir lesquels, c'est savoir ce qu'on gagnerait à passer en
 * base — et ce qui ne peut pas y passer.
 *
 * La carte se lit dans le CODE, pas dans une liste tenue à la main : une liste
 * à la main ment dès la semaine suivante. Chaque fonction `ep_*` est découpée
 * et l'on regarde ce qu'elle appelle. Le résultat va dans `ceo_app_setting`
 * sous `carteSources`, servi par `GET /carte-sources`.
 *
 * Posé en cron quotidien par bin/deploy.sh : « le faire tous les jours ».
 */
require __DIR__ . '/../src/Db.php';

$dire = in_array('--dire', array_slice($argv, 1), true);
$RACINE = dirname(__DIR__);

/* --- ce qu'une source veut dire ---------------------------------------------- */
$SOURCES = [
    'base'      => ['nom' => 'Base MySQL',        'tenue' => 'chez nous'],
    'panel'     => ['nom' => 'Panel consultant',  'tenue' => 'API extérieure'],
    'erp'       => ['nom' => 'ERP TFBuddy',       'tenue' => 'API extérieure'],
    'google'    => ['nom' => 'Google Places',     'tenue' => 'API extérieure'],
    'anthropic' => ['nom' => 'Assistance IA',     'tenue' => 'API extérieure'],
    'osm'       => ['nom' => 'OpenStreetMap',     'tenue' => 'cache serveur'],
];

/* --- découper les fichiers en fonctions -------------------------------------- */
$fns = [];
foreach (glob($RACINE . '/src/*.php') as $f) {
    $t = (string) file_get_contents($f);
    $nom = basename($f);
    if (!preg_match_all('/^function (ep_[a-z0-9_]+|wr_[a-z0-9_]+)\s*\(/m', $t, $m, PREG_OFFSET_CAPTURE)) { continue; }
    foreach ($m[1] as $i => [$fn, $pos]) {
        $deb = $pos;
        $fin = isset($m[1][$i + 1]) ? $m[1][$i + 1][1] : strlen($t);
        $fns[$fn] = ['fichier' => $nom, 'corps' => substr($t, $deb, $fin - $deb)];
    }
}

/* --- les routes, pour nommer les endpoints par leur chemin -------------------- */
$routes = [];
$idx = (string) file_get_contents($RACINE . '/public/api/index.php');
if (preg_match_all('/\$path === \'([^\']+)\'\s*=>\s*(ep_[a-z0-9_]+)\(/', $idx, $m)) {
    foreach ($m[1] as $i => $chemin) { $routes[$m[2][$i]][] = $chemin; }
}
if (preg_match_all('/preg_match\(\'#\^([^\']+)\$#\'[^)]*\)[^{]*\{\s*return (ep_[a-z0-9_]+)\(/', $idx, $m)) {
    foreach ($m[1] as $i => $chemin) { $routes[$m[2][$i]][] = str_replace(['\\w', '\\d', '(', ')', '+', '{1,3}'], ['', '', '{', '}', '', ''], $chemin); }
}

/* --- classer --------------------------------------------------------------- */
$sourcesDe = static function (string $c): array {
    $s = [];
    if (preg_match('/\bDb::(rows|row|exec|scalar|col)\b/', $c)) { $s[] = 'base'; }
    if (str_contains($c, 'PanelApi::')) { $s[] = 'panel'; }
    if (preg_match('/\bErpApi::/', $c)) { $s[] = 'erp'; }
    if (str_contains($c, 'GoogleApi::')) { $s[] = 'google'; }
    if (str_contains($c, 'Anthropic::')) { $s[] = 'anthropic'; }
    if (str_contains($c, 'ScoutingOsm::')) { $s[] = 'osm'; }
    return $s;
};

$lignes = []; $compte = [];
foreach ($fns as $fn => $d) {
    if (!str_starts_with($fn, 'ep_')) { continue; }
    $s = $sourcesDe($d['corps']);
    $ext = array_values(array_diff($s, ['base']));
    $cat = !$s ? 'calcule' : (!$ext ? 'base' : (count($s) > count($ext) ? 'mixte' : 'api'));
    $compte[$cat] = ($compte[$cat] ?? 0) + 1;
    $lignes[] = [
        'fonction' => $fn,
        'fichier' => $d['fichier'],
        'routes' => array_values(array_unique($routes[$fn] ?? [])),
        'sources' => $s,
        'categorie' => $cat,
        'lignes' => substr_count($d['corps'], "\n"),
    ];
}
usort($lignes, static fn ($a, $b) => [$a['categorie'], $a['fonction']] <=> [$b['categorie'], $b['fonction']]);

$carte = [
    'etabliLe' => date('c'),
    'endpoints' => count($lignes),
    'compte' => $compte,
    'sources' => $SOURCES,
    'lignes' => $lignes,
];

if ($dire) {
    printf("%d endpoints · %s\n", count($lignes),
        implode(' · ', array_map(static fn ($k, $v) => "$k $v", array_keys($compte), $compte)));
    foreach ($lignes as $l) {
        printf("  %-10s %-34s %-22s %s\n", $l['categorie'], $l['fonction'], $l['fichier'], implode('+', $l['sources']) ?: '—');
    }
    exit(0);
}

Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
    ['carteSources', json_encode($carte, JSON_UNESCAPED_UNICODE)]);
printf("[%s] carte établie : %d endpoints (%s)\n", date('Y-m-d H:i:s'), count($lignes),
    implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($compte), $compte)));
