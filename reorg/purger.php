<?php
declare(strict_types=1);

/**
 * Phase 4 de REORG_PLAN.md — la purge, après « PURGE » écrit par Sam (19/11/2026).
 *
 *   php reorg/purger.php --code /var/www/consulant_bo --dump-dir /var/lib/consulant_bo/prive/reorg [--purger]
 *
 * Supprime UNE table, et une seule : ceo_assistant_question (liste définitive de REORG_REPORT.md §6, 15 lignes, toutes
 * reprises dans panel_aia_question). Aucune autre table, même si elle paraît inutile.
 *
 * Sans --purger : les contrôles bloquants, la sauvegarde et sa vérification, rien n'est supprimé.
 * Avec --purger : la même chose, puis DROP TABLE de cette table seule, puis le contrôle des lignes de toutes les autres.
 *
 * Au premier contrôle qui échoue, le script s'arrête (code 3) et dit lequel : il ne contourne rien. N'affiche aucune
 * valeur de ligne ni aucun secret : des noms, des nombres, une empreinte.
 */

const PURGE_TABLE = 'ceo_assistant_question';
const PURGE_LIGNES = 15;                                    // mesurées le 19/11/2026 (REORG_REPORT.md §5 bis et §6)
const PURGE_BASE = 'atelierby_db';
const PURGE_HOTES = ['127.0.0.1', 'localhost'];
const PURGE_COPIE = ['panel_aia_question', 'aia_question'];  // où ses lignes ont été reprises (nom après / avant 004)

$code = null; $dossier = null; $purger = false;
foreach ($argv as $i => $a) {
    if ($a === '--code') { $code = rtrim((string) ($argv[$i + 1] ?? ''), '/'); }
    if ($a === '--dump-dir') { $dossier = rtrim((string) ($argv[$i + 1] ?? ''), '/'); }
    if ($a === '--purger') { $purger = true; }
}

function stop(string $pourquoi): never
{
    echo "\nARRÊT — contrôle échoué : $pourquoi\nRien n'a été supprimé.\n";
    exit(3);
}
function ok(string $quoi): void { echo "  ok  $quoi\n"; }
// Toute erreur imprévue est un contrôle échoué : on s'arrête, on ne contourne pas.
set_exception_handler(static function (Throwable $e): void {
    echo "\nARRÊT — erreur : " . mb_substr($e->getMessage(), 0, 300) . "\nRien n'a été supprimé, sauf si « DROP TABLE … fait » est écrit plus haut.\n";
    exit(3);
});

if ($code === null || $dossier === null) { fwrite(STDERR, "usage : --code <dossier du code déployé> --dump-dir <dossier privé> [--purger]\n"); exit(2); }
foreach (["$code/shared/src/Db.php"] as $f) { if (is_file($f)) { require_once $f; } }
if (!class_exists('Db')) { stop("Db.php introuvable sous $code/shared/src"); }

echo ($purger ? 'MODE PURGE' : 'MODE CONTRÔLE (rien ne sera supprimé)') . ' — table : ' . PURGE_TABLE . "\n\n";

/* 1. Le bon serveur, la bonne base ------------------------------------------------------------------------------- */
$cfg = Db::config()['db'];
$base = (string) Db::row('SELECT DATABASE() b')['b'];
echo "Hôte : {$cfg['host']}:{$cfg['port']} · base : $base · MySQL " . Db::row('SELECT VERSION() v')['v'] . "\n";
if (!in_array((string) $cfg['host'], PURGE_HOTES, true)) { stop("hôte inattendu ({$cfg['host']})"); }
if ($base !== PURGE_BASE) { stop("base inattendue ($base)"); }
ok('base du serveur de test (' . PURGE_BASE . ' en local)');

/* 2. La table : présente, une vraie table, le nombre de lignes validé ---------------------------------------------- */
$t = Db::row('SELECT table_type k FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$base, PURGE_TABLE]);
if ($t === null) { stop(PURGE_TABLE . ' absente (déjà supprimée ?)'); }
if ($t['k'] !== 'BASE TABLE') { stop(PURGE_TABLE . " n'est pas une table ({$t['k']})"); }
$n = (int) Db::row('SELECT COUNT(*) n FROM `' . PURGE_TABLE . '`')['n'];
if ($n !== PURGE_LIGNES) { stop(PURGE_TABLE . " a $n lignes, " . PURGE_LIGNES . ' validées : la table a bougé'); }
ok(PURGE_TABLE . " : $n lignes, comme validé");

/* 3. Toutes ses lignes ont leur copie -------------------------------------------------------------------------------- */
$copie = null;
foreach (PURGE_COPIE as $c) {
    if (Db::row('SELECT 1 x FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$base, $c])) { $copie = $c; break; }
}
if ($copie === null) { stop('aucune table de reprise (' . implode(', ', PURGE_COPIE) . ')'); }
$sans = (int) Db::row('SELECT COUNT(*) n FROM `' . PURGE_TABLE . "` a WHERE NOT EXISTS (SELECT 1 FROM `$copie` q
    WHERE q.cree_le <=> a.cree_le AND q.shop_id <=> a.shop_id AND q.question <=> a.question AND q.reponse <=> a.reponse)")['n'];
if ($sans !== 0) { stop("$sans ligne(s) sans copie dans $copie"); }
ok("les $n lignes ont leur copie dans $copie (date, magasin, question, réponse)");
$reprise = Db::row("SELECT 1 x FROM panel_aia_reglage WHERE cle = 'repriseQuestions'");
if ($reprise === null) { stop("la reprise n'est pas marquée faite (panel_aia_reglage.repriseQuestions)"); }
ok('reprise marquée faite : le code ne relira plus la table');

/* 4. Rien n'en dépend en base : vues, clés étrangères, déclencheurs, routines, événements --------------------------- */
$vues = Db::rows('SELECT table_name v FROM information_schema.views WHERE table_schema = ? AND view_definition LIKE ?', [$base, '%' . PURGE_TABLE . '%']);
if ($vues) { stop('vue(s) qui la lisent : ' . implode(', ', array_column($vues, 'v'))); }
$fk = Db::rows('SELECT table_name de, referenced_table_name vers FROM information_schema.key_column_usage
    WHERE table_schema = ? AND referenced_table_name IS NOT NULL AND (table_name = ? OR referenced_table_name = ?)', [$base, PURGE_TABLE, PURGE_TABLE]);
if ($fk) { stop('clé(s) étrangère(s) : ' . implode(', ', array_map(static fn ($k) => "{$k['de']}→{$k['vers']}", $fk))); }
$dec = Db::rows('SELECT trigger_name d FROM information_schema.triggers WHERE trigger_schema = ? AND (event_object_table = ? OR action_statement LIKE ?)', [$base, PURGE_TABLE, '%' . PURGE_TABLE . '%']);
if ($dec) { stop('déclencheur(s) : ' . implode(', ', array_column($dec, 'd'))); }
$rou = Db::rows('SELECT routine_name r FROM information_schema.routines WHERE routine_schema = ? AND routine_definition LIKE ?', [$base, '%' . PURGE_TABLE . '%']);
if ($rou) { stop('routine(s) : ' . implode(', ', array_column($rou, 'r'))); }
$eve = Db::rows('SELECT event_name e FROM information_schema.events WHERE event_schema = ? AND event_definition LIKE ?', [$base, '%' . PURGE_TABLE . '%']);
if ($eve) { stop('événement(s) : ' . implode(', ', array_column($eve, 'e'))); }
ok('aucune vue, clé étrangère, déclencheur, routine ni événement ne la touche');

/* 5. Le code déployé ne la lit plus (hors la reprise déjà faite et un commentaire) ----------------------------------- */
$admis = ['panel/src/assistant_ia.php', 'panel/src/assistant_magasin.php', 'panel/src/LISEZMOI.md'];
$trouves = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($code, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = substr($f->getPathname(), strlen($code) + 1);
    if (preg_match('#(^|/)(vendor|node_modules|\.git|uploads)/|^pilotage-reseau/apps/|^public/|^reorg/|^REORG_|\.(png|jpe?g|gif|webp|pdf|woff2?|ttf|zip|gz|mp4)$#i', $p)) { continue; }
    if ($f->getSize() > 5_000_000) { continue; }
    if (str_contains((string) @file_get_contents($f->getPathname()), PURGE_TABLE) && !in_array($p, $admis, true)) { $trouves[] = $p; }
}
if ($trouves) { stop('le code déployé la nomme encore : ' . implode(', ', $trouves)); }
ok('le code déployé ne la nomme que dans ' . implode(', ', $admis) . ' (reprise faite, commentaires)');

/* 6. La sauvegarde, puis sa vérification par restauration dans une table temporaire --------------------------------- */
if (!is_dir($dossier) && !mkdir($dossier, 0700, true)) { stop("dossier de sauvegarde impossible à créer ($dossier)"); }
$fichier = $dossier . '/purge_' . date('Ymd_His') . '_' . PURGE_TABLE . '.sql';
$pdo = Db::pdo();
$creer = (string) array_values(Db::row('SHOW CREATE TABLE `' . PURGE_TABLE . '`'))[1];
$cols = array_column(Db::rows('SELECT column_name c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [$base, PURGE_TABLE]), 'c');
$sql = "-- Sauvegarde de `" . PURGE_TABLE . "` avant purge (REORG phase 4), base $base, " . date('c') . "\n"
     . "-- $n lignes. Restaurer : mysql $base < ce_fichier\n"
     . "SET NAMES utf8mb4;\n" . $creer . ";\n";
$inserts = [];
foreach (Db::rows('SELECT * FROM `' . PURGE_TABLE . '` ORDER BY 1') as $r) {
    $v = array_map(static fn ($x) => $x === null ? 'NULL' : $pdo->quote((string) $x), array_values($r));
    $inserts[] = 'INSERT INTO `' . PURGE_TABLE . '` (`' . implode('`, `', array_keys($r)) . '`) VALUES (' . implode(', ', $v) . ');';
}
$sql .= implode("\n", $inserts) . "\n-- Fin de la sauvegarde : $n lignes.\n";
if (file_put_contents($fichier, $sql) !== strlen($sql)) { stop("écriture de la sauvegarde incomplète ($fichier)"); }
chmod($fichier, 0600);
$relu = (string) file_get_contents($fichier);
if ($relu !== $sql) { stop('la sauvegarde relue diffère de ce qui a été écrit'); }
if (substr_count($relu, "\nINSERT INTO `" . PURGE_TABLE . '`') !== $n || !str_contains($relu, "-- Fin de la sauvegarde : $n lignes.")) { stop('la sauvegarde ne contient pas les ' . $n . ' lignes'); }
echo '  ok  sauvegarde écrite : ' . $fichier . ' (' . strlen($relu) . ' octets, sha256 ' . hash('sha256', $relu) . ")\n";

// Restauration réelle du fichier relu, dans une table TEMPORAIRE (propre à cette connexion, disparaît avec elle)
$tmp = 'purge_verif_' . bin2hex(random_bytes(4));
$creerTmp = preg_replace('/^CREATE TABLE `' . PURGE_TABLE . '`/', "CREATE TEMPORARY TABLE `$tmp`", $creer, 1, $nb);
if ($nb !== 1) { stop('définition de la table illisible pour la vérification'); }
$pdo->exec($creerTmp);
$restaurees = 0;
foreach (explode("\n", $relu) as $l) {
    if (!str_starts_with($l, 'INSERT INTO `' . PURGE_TABLE . '`')) { continue; }
    $pdo->exec('INSERT INTO `' . $tmp . '`' . substr($l, strlen('INSERT INTO `' . PURGE_TABLE . '`')));
    $restaurees++;
}
$egal = implode(' AND ', array_map(static fn ($c) => "t.`$c` <=> a.`$c`", $cols));
$nTmp = (int) Db::row("SELECT COUNT(*) n FROM `$tmp`")['n'];
$manque = (int) Db::row('SELECT COUNT(*) n FROM `' . PURGE_TABLE . "` a WHERE NOT EXISTS (SELECT 1 FROM `$tmp` t WHERE $egal)")['n'];
$enTrop = (int) Db::row("SELECT COUNT(*) n FROM `$tmp` t WHERE NOT EXISTS (SELECT 1 FROM `" . PURGE_TABLE . "` a WHERE $egal)")['n'];
$pdo->exec("DROP TEMPORARY TABLE `$tmp`");
if ($restaurees !== $n || $nTmp !== $n || $manque !== 0 || $enTrop !== 0) {
    stop("la sauvegarde restaurée ne redonne pas la table (restaurées $restaurees, relues $nTmp, manquantes $manque, en trop $enTrop)");
}
ok("sauvegarde vérifiée : restaurée dans une table temporaire, $n lignes identiques colonne par colonne (" . count($cols) . ' colonnes)');

if (!$purger) { echo "\nTous les contrôles sont passés. Mode contrôle : rien n'a été supprimé.\n"; exit(0); }

/* 7. La purge : cette table seule ------------------------------------------------------------------------------------ */
$avant = [];
foreach (Db::rows("SELECT table_name t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [$base]) as $r) {
    $avant[$r['t']] = (int) Db::row('SELECT COUNT(*) n FROM `' . str_replace('`', '``', $r['t']) . '`')['n'];
}
// Dernier contrôle juste avant : rien n'a été écrit dans la table depuis la sauvegarde
if ((int) Db::row('SELECT COUNT(*) n FROM `' . PURGE_TABLE . '`')['n'] !== $n) { stop('la table a bougé depuis la sauvegarde'); }
$pdo->exec('DROP TABLE `' . PURGE_TABLE . '`');
echo "\nDROP TABLE `" . PURGE_TABLE . "` fait.\n";

$apres = [];
foreach (Db::rows("SELECT table_name t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name", [$base]) as $r) {
    $apres[$r['t']] = (int) Db::row('SELECT COUNT(*) n FROM `' . str_replace('`', '``', $r['t']) . '`')['n'];
}
$disparues = array_keys(array_diff_key($avant, $apres));
$apparues = array_keys(array_diff_key($apres, $avant));
$bouge = [];
foreach ($apres as $t => $c) { if (isset($avant[$t]) && $avant[$t] !== $c) { $bouge[] = "$t {$avant[$t]}→$c"; } }
printf("Tables : %d avant, %d après\n", count($avant), count($apres));
echo 'Disparues : ' . (implode(', ', $disparues) ?: 'aucune') . "\n";
echo 'Apparues : ' . (implode(', ', $apparues) ?: 'aucune') . "\n";
echo 'Lignes changées pendant la purge (activité du serveur) : ' . (implode(', ', $bouge) ?: 'aucune') . "\n";
if ($disparues !== [PURGE_TABLE]) { echo "ATTENTION : la seule table disparue devrait être " . PURGE_TABLE . "\n"; exit(4); }
echo "Purge terminée : une table supprimée, sa sauvegarde vérifiée est dans $fichier\n";
