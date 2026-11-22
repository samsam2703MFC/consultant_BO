<?php
declare(strict_types=1);

/**
 * Purge des 33 anciennes tables sig_* de la base du serveur, après « PURGE des anciennes tables sig_ » écrit par
 * Sam (20/11/2026).
 *
 *   php reorg/purger_sig.php --code /var/www/consulant_bo --dump-dir /var/lib/consulant_bo/prive/reorg [--purger]
 *
 * Ce sont les anciennes COPIES MariaDB de la régie d'affichage. La régie est entrée dans le Marketing le 20/11/2026 :
 * sa base SQLite a été renommée sig_ → mar_sig_ (sauvegardée dans /var/backups/signage), et elle refait sa copie MariaDB
 * sous les noms mar_sig_*. Les tables sig_* ne sont plus écrites par personne depuis.
 *
 * Sans --purger : contrôles bloquants, sauvegarde et sa vérification ; rien n'est supprimé.
 * Avec --purger : la même chose, puis DROP TABLE de ces 33 tables seules, puis le contrôle de toutes les autres.
 * Au premier contrôle qui échoue : arrêt (code 3), rien n'est supprimé. Aucune valeur de ligne affichée.
 */

// Les 33 tables et leurs lignes, mesurées le 20/11/2026 à 00:0x (sonde de la réorganisation), avant la bascule.
// Une table qui n'a plus ce nombre de lignes est encore écrite par quelqu'un : arrêt.
const SIG_TABLES = [
    'sig_aliases' => 0, 'sig_bundles' => 0, 'sig_campaign_runs' => 0, 'sig_campaigns' => 0, 'sig_dayplans' => 0,
    'sig_element_types' => 11, 'sig_ingredients' => 0, 'sig_langs' => 4, 'sig_lib_categories' => 4, 'sig_media' => 3,
    'sig_media_sources' => 1, 'sig_module_items' => 0, 'sig_modules' => 4, 'sig_players' => 1, 'sig_playlist' => 0,
    'sig_playlist_items' => 3, 'sig_playlists' => 3, 'sig_product_categories' => 84, 'sig_product_moments' => 0,
    'sig_product_prices' => 2672, 'sig_products' => 752, 'sig_promo_types' => 6, 'sig_publications' => 13,
    'sig_screen_groups' => 3, 'sig_seasons' => 14, 'sig_sequence_items' => 0, 'sig_sequences' => 0,
    'sig_settings' => 18, 'sig_store_unavailable' => 0, 'sig_stores' => 6, 'sig_tfb_promos' => 0,
    'sig_translations' => 0, 'sig_weekplans' => 15,
];
const SIG_BASE = 'atelierby_db';
const SIG_HOTES = ['127.0.0.1', 'localhost'];
// Code déployé où chercher une lecture des anciens noms (les dossiers absents sont passés).
const SIG_CODES = ['/var/www/consulant_bo', '/var/www/latelierby-landing', '/var/www/html/marketing', '/var/www/signage'];
// Seule mention admise : le repli de la landing (lu seulement si mar_sig_seasons manquait ; retiré après la purge).
const SIG_ADMIS = ['/var/www/latelierby-landing/lp_seasons.php'];

$code = null; $dossier = null; $purger = false;
foreach ($argv as $i => $a) {
    if ($a === '--code') { $code = rtrim((string) ($argv[$i + 1] ?? ''), '/'); }
    if ($a === '--dump-dir') { $dossier = rtrim((string) ($argv[$i + 1] ?? ''), '/'); }
    if ($a === '--purger') { $purger = true; }
}
function stop(string $pourquoi): never { echo "\nARRÊT — contrôle échoué : $pourquoi\nRien n'a été supprimé.\n"; exit(3); }
function ok(string $quoi): void { echo "  ok  $quoi\n"; }
set_exception_handler(static function (Throwable $e): void {
    echo "\nARRÊT — erreur : " . mb_substr($e->getMessage(), 0, 300) . "\nRien n'a été supprimé, sauf si « DROP TABLE … fait » est écrit plus haut.\n";
    exit(3);
});
if ($code === null || $dossier === null) { fwrite(STDERR, "usage : --code <dossier du code déployé> --dump-dir <dossier privé> [--purger]\n"); exit(2); }
if (is_file("$code/shared/src/Db.php")) { require_once "$code/shared/src/Db.php"; }
if (!class_exists('Db')) { stop("Db.php introuvable sous $code/shared/src"); }
$q = static fn (string $t): string => '`' . str_replace('`', '``', $t) . '`';

echo ($purger ? 'MODE PURGE' : 'MODE CONTRÔLE (rien ne sera supprimé)') . ' — ' . count(SIG_TABLES) . " tables sig_*\n\n";

/* 1. Le bon serveur, la bonne base */
$cfg = Db::config()['db'];
$base = (string) Db::row('SELECT DATABASE() b')['b'];
echo "Hôte : {$cfg['host']}:{$cfg['port']} · base : $base · MySQL " . Db::row('SELECT VERSION() v')['v'] . "\n";
if (!in_array((string) $cfg['host'], SIG_HOTES, true)) { stop("hôte inattendu ({$cfg['host']})"); }
if ($base !== SIG_BASE) { stop("base inattendue ($base)"); }
ok('base du serveur de test (' . SIG_BASE . ' en local)');

/* 2. Chaque table : présente, une vraie table, ses lignes de cette nuit ; son double mar_sig_ présent */
$types = [];
foreach (Db::rows("SELECT table_name t, table_type k FROM information_schema.tables WHERE table_schema = ? AND (table_name LIKE 'sig\\_%' OR table_name LIKE 'mar\\_sig\\_%')", [$base]) as $r) {
    $types[$r['t']] = $r['k'];
}
$total = 0; $infos = [];
foreach (SIG_TABLES as $t => $attendu) {
    if (!isset($types[$t])) { stop("$t absente (déjà supprimée ?)"); }
    if ($types[$t] !== 'BASE TABLE') { stop("$t n'est pas une table ({$types[$t]})"); }
    $n = (int) Db::row('SELECT COUNT(*) n FROM ' . $q($t))['n'];
    if ($n !== $attendu) { stop("$t a $n ligne(s), $attendu mesurées cette nuit : elle est encore écrite par quelqu'un"); }
    $jumelle = 'mar_' . $t;
    if (($types[$jumelle] ?? '') !== 'BASE TABLE') { stop("$jumelle absente : la régie n'a pas refait sa copie sous le nouveau nom"); }
    $total += $n;
    // Information (pas bloquant) : les lignes de l'ancienne copie qu'on ne retrouve pas à l'identique dans la nouvelle
    // (la régie a pu les mettre à jour depuis : prix, catalogue…). La sauvegarde ci-dessous les garde toutes.
    $cols = array_column(Db::rows('SELECT column_name c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$base, $t]), 'c');
    $colsJ = array_flip(array_column(Db::rows('SELECT column_name c FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$base, $jumelle]), 'c'));
    $communes = array_values(array_filter($cols, static fn ($c) => isset($colsJ[$c])));
    if ($n > 0 && $communes) {
        $egal = implode(' AND ', array_map(static fn ($c) => "j.`$c` <=> s.`$c`", $communes));
        $diff = (int) Db::row('SELECT COUNT(*) n FROM ' . $q($t) . ' s WHERE NOT EXISTS (SELECT 1 FROM ' . $q($jumelle) . " j WHERE $egal)")['n'];
        if ($diff) { $infos[] = "$t : $diff/$n ligne(s) différente(s) dans $jumelle"; }
    }
}
ok(count(SIG_TABLES) . " tables présentes, $total lignes, chacune exactement comme mesurée cette nuit ; chacune a son double mar_sig_");
echo '  info  ' . ($infos ? implode(' · ', $infos) . ' (mises à jour par la régie depuis ; gardées dans la sauvegarde)' : 'les lignes des anciennes copies sont toutes dans les nouvelles') . "\n";
$autres = array_values(array_diff(array_keys(array_filter($types, static fn ($k, $t) => str_starts_with($t, 'sig_'), ARRAY_FILTER_USE_BOTH)), array_keys(SIG_TABLES)));
echo '  info  autres tables sig_* (pas touchées) : ' . ($autres ? implode(', ', $autres) : 'aucune') . "\n";

/* 3. Rien n'en dépend en base */
$noms = array_keys(SIG_TABLES);
$place = implode(', ', array_fill(0, count($noms), '?'));
$fk = Db::rows("SELECT table_name de, referenced_table_name vers FROM information_schema.key_column_usage WHERE table_schema = ? AND referenced_table_name IS NOT NULL AND (table_name IN ($place) OR referenced_table_name IN ($place))", array_merge([$base], $noms, $noms));
if ($fk) { stop('clé(s) étrangère(s) : ' . implode(', ', array_map(static fn ($k) => "{$k['de']}→{$k['vers']}", $fk))); }
$dec = Db::rows("SELECT trigger_name d FROM information_schema.triggers WHERE trigger_schema = ? AND event_object_table IN ($place)", array_merge([$base], $noms));
if ($dec) { stop('déclencheur(s) : ' . implode(', ', array_column($dec, 'd'))); }
foreach ([['views', 'table_schema', 'view_definition', 'vue'], ['routines', 'routine_schema', 'routine_definition', 'routine'], ['events', 'event_schema', 'event_definition', 'événement']] as [$vue, $sch, $def, $lib]) {
    foreach (Db::rows("SELECT $def d FROM information_schema.$vue WHERE $sch = ?", [$base]) as $r) {
        foreach ($noms as $t) { if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($t, '/') . '(?![A-Za-z0-9_])/', (string) $r['d'])) { stop("une $lib lit $t"); } }
    }
}
ok('aucune vue, clé étrangère, déclencheur, routine ni événement ne les touche');

/* 4. Le code déployé ne les lit plus */
$motif = '/(?<![A-Za-z0-9_])(' . implode('|', array_map(static fn ($t) => preg_quote($t, '/'), $noms)) . ')(?![A-Za-z0-9_])/';
$trouves = [];
foreach (SIG_CODES as $racine) {
    if (!is_dir($racine)) { echo "  info  $racine absent : passé\n"; continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        $rel = substr($p, strlen($racine) + 1);
        if (preg_match('#(^|/)(vendor|node_modules|\.git|uploads|media|data|surprise|anim|reorg|docs|maquettes)/|^pilotage-reseau/apps/|^public/|\.(md|png|jpe?g|gif|webp|pdf|woff2?|ttf|otf|zip|gz|mp4|db|sqlite)$#i', $rel)) { continue; }
        if (!$f->isFile() || $f->getSize() > 5_000_000) { continue; }
        if (preg_match($motif, (string) @file_get_contents($p)) && !in_array($p, SIG_ADMIS, true)) { $trouves[] = $p; }
    }
}
if ($trouves) { stop('le code déployé nomme encore une ancienne table : ' . implode(', ', array_slice($trouves, 0, 10))); }
ok('le code déployé ne les nomme plus (seul le repli de la landing, sans effet tant que mar_sig_seasons existe)');

/* 5. La sauvegarde, puis sa vérification par restauration dans des tables temporaires */
if (!is_dir($dossier) && !mkdir($dossier, 0700, true)) { stop("dossier de sauvegarde impossible à créer ($dossier)"); }
$fichier = $dossier . '/purge_' . date('Ymd_His') . '_sig_33_tables.sql';
$pdo = Db::pdo();
$sql = "-- Sauvegarde des " . count(SIG_TABLES) . " anciennes tables sig_* avant purge, base $base, " . date('c') . "\n"
     . "-- $total lignes. Restaurer : mysql $base < ce_fichier\nSET NAMES utf8mb4;\n";
$creer = [];
foreach (SIG_TABLES as $t => $n) {
    $creer[$t] = (string) array_values(Db::row('SHOW CREATE TABLE ' . $q($t)))[1];
    $sql .= "\n-- $t : $n ligne(s)\n" . $creer[$t] . ";\n";
    foreach (Db::rows('SELECT * FROM ' . $q($t)) as $r) {
        $v = array_map(static fn ($x) => $x === null ? 'NULL' : $pdo->quote((string) $x), array_values($r));
        $sql .= 'INSERT INTO ' . $q($t) . ' (`' . implode('`, `', array_keys($r)) . '`) VALUES (' . implode(', ', $v) . ");\n";
    }
}
$sql .= "-- Fin de la sauvegarde : $total lignes.\n";
if (file_put_contents($fichier, $sql) !== strlen($sql)) { stop("écriture de la sauvegarde incomplète ($fichier)"); }
chmod($fichier, 0600);
$relu = (string) file_get_contents($fichier);
if ($relu !== $sql || !str_contains($relu, "-- Fin de la sauvegarde : $total lignes.")) { stop('la sauvegarde relue diffère de ce qui a été écrit'); }
echo '  ok  sauvegarde écrite : ' . $fichier . ' (' . strlen($relu) . ' octets, sha256 ' . hash('sha256', $relu) . ")\n";

$lignes = explode("\n", $relu);
foreach (SIG_TABLES as $t => $n) {
    $tmp = 'purge_verif_' . bin2hex(random_bytes(4));
    $creerTmp = preg_replace('/^CREATE TABLE ' . preg_quote($q($t), '/') . '/', "CREATE TEMPORARY TABLE `$tmp`", $creer[$t], 1, $nb);
    if ($nb !== 1) { stop("définition de $t illisible pour la vérification"); }
    $creerTmp = preg_replace('/,\s*CONSTRAINT [^\n]*FOREIGN KEY[^\n]*/i', '', $creerTmp);
    $pdo->exec($creerTmp);
    $prefixe = 'INSERT INTO ' . $q($t) . ' ';
    $rest = 0;
    foreach ($lignes as $l) { if (str_starts_with($l, $prefixe)) { $pdo->exec("INSERT INTO `$tmp` " . substr($l, strlen($prefixe))); $rest++; } }
    $cols = array_column(Db::rows('SELECT column_name c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$base, $t]), 'c');
    $egal = implode(' AND ', array_map(static fn ($c) => "x.`$c` <=> a.`$c`", $cols));
    $nTmp = (int) Db::row("SELECT COUNT(*) n FROM `$tmp`")['n'];
    $manque = $n ? (int) Db::row('SELECT COUNT(*) n FROM ' . $q($t) . " a WHERE NOT EXISTS (SELECT 1 FROM `$tmp` x WHERE $egal)")['n'] : 0;
    $pdo->exec("DROP TEMPORARY TABLE `$tmp`");
    if ($rest !== $n || $nTmp !== $n || $manque !== 0) { stop("$t : la sauvegarde restaurée ne redonne pas la table (restaurées $rest, relues $nTmp, manquantes $manque)"); }
}
ok('sauvegarde vérifiée : chaque table restaurée dans une table temporaire, ' . $total . ' lignes identiques colonne par colonne');

if (!$purger) { echo "\nTous les contrôles sont passés. Mode contrôle : rien n'a été supprimé.\n"; exit(0); }

/* 6. La purge : ces 33 tables seules */
$compter = static function () use ($base, $q): array {
    $o = [];
    foreach (Db::rows("SELECT table_name t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [$base]) as $r) {
        $o[$r['t']] = (int) Db::row('SELECT COUNT(*) n FROM ' . $q($r['t']))['n'];
    }
    return $o;
};
$avant = $compter();
foreach (SIG_TABLES as $t => $n) { if (($avant[$t] ?? -1) !== $n) { stop("$t a bougé depuis la sauvegarde"); } }
$pdo->exec('DROP TABLE ' . implode(', ', array_map($q, array_keys(SIG_TABLES))));
echo "\nDROP TABLE des " . count(SIG_TABLES) . " tables sig_* fait.\n";
$apres = $compter();
$disparues = array_keys(array_diff_key($avant, $apres));
$apparues = array_keys(array_diff_key($apres, $avant));
$bouge = [];
foreach ($apres as $t => $c) { if (isset($avant[$t]) && $avant[$t] !== $c) { $bouge[] = "$t {$avant[$t]}→$c"; } }
sort($disparues);
printf("Tables : %d avant, %d après\n", count($avant), count($apres));
echo 'Disparues : ' . count($disparues) . ' (' . implode(', ', $disparues) . ")\n";
echo 'Apparues : ' . (implode(', ', $apparues) ?: 'aucune') . "\n";
echo 'Lignes changées pendant la purge (activité du serveur) : ' . (implode(', ', $bouge) ?: 'aucune') . "\n";
$voulu = array_keys(SIG_TABLES); sort($voulu);
if ($disparues !== $voulu) { echo "ATTENTION : les tables disparues ne sont pas exactement les 33 prévues\n"; exit(4); }
echo "Purge terminée : 33 tables supprimées, leur sauvegarde vérifiée est dans $fichier\n";
