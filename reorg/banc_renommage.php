<?php
declare(strict_types=1);

/**
 * Banc local — une migration de renommage, aller, retour, aller : chaque table garde EXACTEMENT son nombre de lignes,
 * aucune autre table ne bouge. Refuse toute base qui n'est pas locale.
 *
 *   php reorg/banc_renommage.php 002_formation_tables FOR
 */

$h = getenv('COCKPIT_DB_HOST') ?: ''; $p = getenv('COCKPIT_DB_PORT') ?: '';
if (!in_array($h, ['127.0.0.1', 'localhost'], true) || $p === '' || $p === '3306') { fwrite(STDERR, "banc : base refusée\n"); exit(2); }
$racine = dirname(__DIR__);
require "$racine/shared/src/Db.php";
require_once "$racine/shared/src/erp_cles.php";
require_once "$racine/shared/src/writes.php";
require_once "$racine/shared/src/migrations.php";
require_once "$racine/shared/src/reorg_tables.php";

[, $nom, $dom] = $argv + [null, '', ''];
$paires = REORG_TABLES[$dom] ?? [];
if ($nom === '' || !$paires) { fwrite(STDERR, "usage : banc_renommage.php <migration> <domaine>\n"); exit(2); }

// Le journal et les réglages : la migration y note son passage (une ligne de journal, l'historique des migrations).
// Ils sont vérifiés à part ; toute autre table doit garder exactement ses lignes.
const TENUE = ['ceo_journal_entry', 'ceo_app_setting'];
$compter = static function (): array {
    $o = [];
    foreach (migrationTables() as $t) { if (!in_array($t, TENUE, true)) { $o[$t] = migrationLignes($t); } }
    ksort($o);
    return $o;
};
$ko = 0;
$dire = static function (string $quoi, bool $vrai, string $detail = '') use (&$ko): void {
    if (!$vrai) { $ko++; }
    echo ($vrai ? '  ok  ' : '  KO  ') . $quoi . ($vrai || $detail === '' ? '' : " — $detail") . "\n";
};
/** Les comptes d'avant, traduits dans les nouveaux noms : ce que la base doit montrer après. */
$traduire = static function (array $c, array $paires): array {
    $o = [];
    foreach ($c as $t => $n) { $o[$paires[$t] ?? $t] = $n; }
    ksort($o);
    return $o;
};

$journal0 = migrationLignes('ceo_journal_entry');
$avant = $compter();
$presentes = array_intersect_key($paires, $avant);
echo "$nom : " . count($presentes) . ' / ' . count($paires) . " tables présentes sous l'ancien nom\n";
$dire('déjà passée ? non', !isset(migrationsFaites()[$nom]));

$r = migrationsAppliquer();
$dire('aller : passée sans erreur', $r['erreur'] === null && isset($r['faites'][$nom]), (string) $r['erreur']);
$apres = $compter();
$dire('aller : chaque table renommée garde ses lignes, rien d\'autre ne bouge', $apres == $traduire($avant, $paires),
    json_encode(array_diff_assoc($traduire($avant, $paires), $apres) + array_diff_assoc($apres, $traduire($avant, $paires))));
$dire('aller : plus aucun ancien nom en base', !array_intersect_key($paires, $apres));

$b = migrationDefaire($nom);
$retour = $compter();
$dire('retour : la base est exactement celle d\'avant', $retour == $avant, json_encode(array_diff_assoc($avant, $retour) + array_diff_assoc($retour, $avant)));

$r = migrationsAppliquer();
$encore = $compter();
$dire('aller à nouveau : identique au premier aller', $r['erreur'] === null && $encore == $apres);

$dire('le journal a gardé toutes ses lignes et noté les passages', migrationLignes('ceo_journal_entry') >= $journal0 + 3);

// Un conflit : l'ancien nom recréé à côté du nouveau → la migration refuse, rien ne bouge, rien n'est supprimé.
$a1 = array_key_first($presentes); $n1 = $paires[$a1];
Db::exec("CREATE TABLE `$a1` LIKE `$n1`");
try { migrationRenommer([$a1 => $n1]); $dire('conflit : refusé', false); }
catch (Throwable $e) { $dire('conflit (ancien et nouveau nom) : refusé, sans rien supprimer', str_contains($e->getMessage(), 'existent tous les deux') && in_array($a1, migrationTables(), true) && migrationLignes($n1) === $apres[$n1]); }
Db::exec("DROP TABLE `$a1`");   // la table vide créée par ce banc, et elle seule

echo "\n" . ($ko === 0 ? 'tout est vert' : "$ko KO") . "\n";
exit($ko === 0 ? 0 : 1);
