<?php
declare(strict_types=1);

/**
 * Ligne de base de la réorganisation — LECTURE SEULE (étape 0 de REORG_PLAN.md).
 *
 *   php reorg/ligne_de_base.php [--json fichier]
 *
 * Utilise la connexion de l'application (config.php, sinon COCKPIT_DB_*). N'écrit rien en base. N'affiche aucune
 * valeur de ligne : des noms d'objets, des nombres de lignes, des définitions de clés étrangères et de vues, les droits
 * du compte (sans mot de passe). Dit l'hôte et le nom de la base en premier.
 */

$racine = dirname(__DIR__);
foreach (["$racine/shared/src/Db.php", "$racine/pilotage-reseau/src/Db.php"] as $f) {
    if (is_file($f)) { require_once $f; break; }
}
$json = null;
foreach ($argv as $i => $a) { if ($a === '--json') { $json = $argv[$i + 1] ?? null; } }

$cfg = Db::config()['db'];
$base = (string) Db::row('SELECT DATABASE() b')['b'];
$out = ['hote' => $cfg['host'] . ':' . $cfg['port'], 'base' => $base, 'le' => date('c'),
        'version' => (string) Db::row('SELECT VERSION() v')['v']];
echo "Hôte : {$out['hote']} · base : {$out['base']} · MySQL {$out['version']}\n";

// Les droits du compte (un RENAME demande ALTER, DROP, CREATE, INSERT)
$out['droits'] = array_map(static fn ($r) => (string) reset($r), Db::rows('SHOW GRANTS FOR CURRENT_USER()'));

// Toutes les tables et vues, avec le nombre EXACT de lignes
$out['tables'] = [];
$out['vues'] = [];
foreach (Db::rows('SELECT table_name t, table_type k, engine e FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [$base]) as $r) {
    if ($r['k'] === 'VIEW') { $out['vues'][$r['t']] = null; continue; }
    $n = Db::row('SELECT COUNT(*) n FROM `' . str_replace('`', '``', $r['t']) . '`');
    $out['tables'][$r['t']] = (int) $n['n'];
}
foreach (array_keys($out['vues']) as $v) {
    $d = Db::row('SELECT view_definition d FROM information_schema.views WHERE table_schema = ? AND table_name = ?', [$base, $v]);
    $out['vues'][$v] = ['definition' => (string) ($d['d'] ?? '')];
    try {
        $out['vues'][$v]['tables'] = array_column(Db::rows('SELECT table_name t FROM information_schema.view_table_usage WHERE view_schema = ? AND view_name = ?', [$base, $v]), 't');
    } catch (Throwable $e) { $out['vues'][$v]['tables'] = null; }   // MariaDB sans VIEW_TABLE_USAGE
}

// Les clés étrangères réellement posées
$out['fk'] = Db::rows('SELECT table_name de, column_name colonne, referenced_table_name vers, referenced_column_name colonne_vers, constraint_name nom
    FROM information_schema.key_column_usage WHERE table_schema = ? AND referenced_table_name IS NOT NULL ORDER BY table_name, constraint_name', [$base]);

// Déclencheurs, fonctions, procédures, événements
$lire = static function (string $sql) use ($base): array {
    try { return Db::rows($sql, [$base]); } catch (Throwable $e) { return [['erreur' => mb_substr($e->getMessage(), 0, 160)]]; }
};
$out['declencheurs'] = $lire('SELECT trigger_name nom, event_object_table sur, action_timing quand, event_manipulation quoi FROM information_schema.triggers WHERE trigger_schema = ?');
$out['routines'] = $lire('SELECT routine_name nom, routine_type genre FROM information_schema.routines WHERE routine_schema = ?');
$out['evenements'] = $lire('SELECT event_name nom, status etat FROM information_schema.events WHERE event_schema = ?');

// Confrontation avec l'inventaire du plan
$plan = [];
$tsv = __DIR__ . '/tables.tsv';
if (is_file($tsv)) {
    $l = file($tsv, FILE_IGNORE_NEW_LINES);
    $tete = explode("\t", array_shift($l));
    foreach ($l as $ligne) { $c = array_combine($tete, explode("\t", $ligne)); $plan[$c['table']] = $c; }
}
$noms = array_keys($out['tables']);
$out['collisions'] = [];
$out['renommages'] = [];
foreach ($plan as $t => $c) {
    if (($c['nouveau'] ?? '=') === '=') { continue; }
    $out['renommages'][$t] = ['vers' => $c['nouveau'], 'lignes' => $out['tables'][$t] ?? null, 'cible_existe' => isset($out['tables'][$c['nouveau']])];
    if (isset($out['tables'][$c['nouveau']])) { $out['collisions'][] = $c['nouveau']; }
}
$out['prefixes_reserves'] = array_values(array_filter($noms, static fn ($n) => preg_match('/^(panel|for)_/', $n)
    && !in_array($n, array_column($out['renommages'], 'vers'), true)));
$out['hors_inventaire'] = array_values(array_diff($noms, array_keys($plan)));

// Résumé lisible
printf("%d tables, %d vues, %d clés étrangères, %d déclencheurs, %d routines, %d événements\n",
    count($out['tables']), count($out['vues']), count($out['fk']), count($out['declencheurs']), count($out['routines']), count($out['evenements']));
echo "Droits : " . implode(' | ', array_map(static fn ($g) => preg_replace("/IDENTIFIED BY .*/", '', $g), $out['droits'])) . "\n";
echo 'Renommages prévus présents en base : ' . count(array_filter($out['renommages'], static fn ($r) => $r['lignes'] !== null)) . ' / ' . count($out['renommages']) . "\n";
echo 'Collisions (nom cible déjà pris) : ' . (implode(', ', $out['collisions']) ?: 'aucune') . "\n";
echo 'Tables panel_/for_ hors plan : ' . (implode(', ', $out['prefixes_reserves']) ?: 'aucune') . "\n";
echo 'Tables en base absentes de l’inventaire : ' . count($out['hors_inventaire']) . "\n";
foreach ($out['tables'] as $t => $n) { printf("  %-48s %10d\n", $t, $n); }
foreach ($out['vues'] as $v => $d) { echo "  vue $v → " . implode(', ', (array) ($d['tables'] ?? [])) . "\n"; }
if ($json) { file_put_contents($json, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); echo "JSON : $json\n"; }
