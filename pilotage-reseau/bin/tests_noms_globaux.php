#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Aucune fonction ni constante globale déclarée deux fois dans src/ et public/api/ (16/11/2026 : `auReglage`, déclarée
 * par src/audits.php puis par src/franchise/automatisations.php, a arrêté toute l'API du cockpit — PHP refuse de
 * charger un fichier qui redéclare une fonction). Les fichiers à espace de noms (src/hr) ne comptent pas.
 *
 *   php bin/tests_noms_globaux.php        (0 : rien en double ; 1 : la liste des doublons)
 *
 * Les doublons connus avant ce test, de fichiers qui ne se chargent pas ensemble, sont tolérés (DEJA) ; tout nouveau
 * doublon échoue.
 */

const DEJA = [
    'fidmagasins' => ['src/fidelite.php', 'src/fidelite_bingo.php'],
    'ep_fidelite' => ['src/fidelite.php', 'src/fidelite_bingo.php'],
    'ep_fidelite_clients' => ['src/fidelite.php', 'src/fidelite_bingo.php'],
    'ep_fidelite_client' => ['src/fidelite_bingo.php', 'src/fidelite_profils.php'],
];

$racine = dirname(__DIR__);
$fichiers = [];
foreach (['src', 'public/api'] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $fichiers[] = $f->getPathname(); } }
}
sort($fichiers);
$fonctions = []; $constantes = [];
foreach ($fichiers as $f) {
    $t = (string) file_get_contents($f);
    if (preg_match('/^\s*namespace\s+[A-Za-z\\\\]/m', $t)) { continue; }
    $rel = substr($f, strlen($racine) + 1);
    if (preg_match_all('/^function\s+&?\s*([A-Za-z_]\w*)\s*\(/m', $t, $m)) { foreach ($m[1] as $n) { $fonctions[strtolower($n)][] = $rel; } }
    if (preg_match_all('/^const\s+([A-Za-z_]\w*)\s*=/m', $t, $m)) { foreach ($m[1] as $n) { $constantes[$n][] = $rel; } }
}
$ko = [];
foreach ($fonctions as $n => $l) {
    if (count($l) < 2) { continue; }
    $l = array_values(array_unique($l)); sort($l);
    if (isset(DEJA[$n]) && $l === DEJA[$n]) { continue; }
    $ko[] = "fonction $n() : " . implode(', ', $l);
}
foreach ($constantes as $n => $l) { if (count($l) > 1) { $ko[] = "constante $n : " . implode(', ', $l); } }
echo count($fichiers) . ' fichiers, ' . count($fonctions) . ' fonctions, ' . count($constantes) . " constantes globales\n";
foreach ($ko as $x) { echo "  KO  $x\n"; }
echo $ko ? count($ko) . " en double\n" : "aucune en double\n";
exit($ko ? 1 : 0);
