<?php
declare(strict_types=1);

/**
 * Assemble le site public (réorganisation du 19/11/2026) : chaque domaine garde ses pages dans son dossier
 * (marketing/public, formation/public, consultant/public, panel/public, shared/public, pilotage-reseau/public), et le
 * serveur les sert TOUTES depuis un seul dossier, aux mêmes adresses qu'avant. Ce dossier ne contient que des liens
 * symboliques vers les pages des domaines, et les fichiers déposés (uploads/, assistant/uploads/), qui ne bougent
 * jamais. PHP suit les liens (__DIR__ est le vrai chemin) : les chemins relatifs du code restent justes.
 *
 *   php shared/bin/assembler_public.php <cible> [--racine <dépôt>] [--essai]
 *
 *   sur le serveur   : php shared/bin/assembler_public.php /var/www/consulant_bo/public   (bin/deploy.sh)
 *   en développement : php shared/bin/assembler_public.php public   (puis php -S … -t public public/router.php)
 *
 * Ne supprime JAMAIS un dossier protégé ni ce qu'il contient. Refuse si deux domaines fournissent le même fichier.
 */

const PROTEGES = ['uploads', 'assistant/uploads'];
const SOURCES = ['shared', 'marketing', 'formation', 'consultant', 'panel', 'pilotage-reseau'];

$args = array_slice($argv, 1);
$essai = in_array('--essai', $args, true);
$racine = dirname(__DIR__, 2);
if (($i = array_search('--racine', $args, true)) !== false) { $racine = rtrim((string) ($args[$i + 1] ?? ''), '/'); unset($args[$i], $args[$i + 1]); }
$args = array_values(array_filter($args, static fn ($a) => $a !== '--essai'));
$cible = $args[0] ?? '';
if ($cible === '') { fwrite(STDERR, "usage : assembler_public.php <cible> [--racine <dépôt>] [--essai]\n"); exit(2); }
if ($cible[0] !== '/') { $cible = getcwd() . '/' . $cible; }
$cible = rtrim($cible, '/');

$sources = [];
foreach (SOURCES as $d) { if (is_dir("$racine/$d/public")) { $sources[$d] = "$racine/$d/public"; } }

/** Un chemin protégé, ou un dossier qui en contient un. */
function aProteger(string $rel): bool
{
    foreach (PROTEGES as $p) { if ($rel === $p || str_starts_with($p, $rel . '/') || str_starts_with($rel, $p . '/')) { return true; } }
    return false;
}

/** Le plan : chemin relatif → ['lien' => cible absolue] ou ['dossier' => true]. */
function planifier(array $sources, string $rel, array &$plan): void
{
    $noms = [];
    foreach ($sources as $d => $base) {
        $dos = $rel === '' ? $base : "$base/$rel";
        if (!is_dir($dos)) { continue; }
        foreach (scandir($dos) ?: [] as $n) {
            if ($n === '.' || $n === '..') { continue; }
            $noms[$n][$d] = "$dos/$n";
        }
    }
    ksort($noms);
    foreach ($noms as $n => $par) {
        $r = $rel === '' ? $n : "$rel/$n";
        $dossiers = array_filter($par, static fn ($p) => is_dir($p) && !is_link($p));
        if (count($par) > 1 && count($dossiers) !== count($par)) {
            throw new RuntimeException("« $r » est fourni par plusieurs domaines (" . implode(', ', array_keys($par)) . ')');
        }
        if (count($par) === 1 && !aProteger($r)) { $plan[$r] = ['lien' => reset($par)]; continue; }
        $plan[$r] = ['dossier' => true];
        planifier(array_map(static fn ($b) => $b, $sources), $r, $plan);
    }
}

$plan = [];
planifier($sources, '', $plan);
foreach (PROTEGES as $p) { $plan[$p] = ['protege' => true]; }

/** Le chemin relatif d'un lien (de son dossier vers sa cible). */
function relatif(string $de, string $vers): string
{
    $a = explode('/', trim($de, '/')); $b = explode('/', trim($vers, '/'));
    while ($a && $b && $a[0] === $b[0]) { array_shift($a); array_shift($b); }
    return str_repeat('../', count($a)) . implode('/', $b);
}

function supprimer(string $chemin): void
{
    if (is_link($chemin) || is_file($chemin)) { unlink($chemin); return; }
    if (is_dir($chemin)) {
        foreach (scandir($chemin) ?: [] as $n) { if ($n !== '.' && $n !== '..') { supprimer("$chemin/$n"); } }
        rmdir($chemin);
    }
}

/** Un dossier (et ses parents), sans erreur s'il existe déjà. */
function dossier(string $chemin, int $mode): void
{
    if (!is_dir($chemin) && !@mkdir($chemin, $mode, true) && !is_dir($chemin)) { throw new RuntimeException("dossier impossible : $chemin"); }
}

$faits = ['liens' => 0, 'dossiers' => 0, 'retires' => 0, 'inchanges' => 0];
if (!$essai && !is_dir($cible)) { mkdir($cible, 0755, true); }
// 1. Ce qui n'est plus voulu disparaît (jamais un dossier protégé, jamais ce qu'il contient).
$retirer = static function (string $rel) use (&$retirer, $cible, $plan, $essai, &$faits): void {
    $dos = $rel === '' ? $cible : "$cible/$rel";
    if (is_link($dos) || !is_dir($dos)) { return; }
    foreach (scandir($dos) ?: [] as $n) {
        if ($n === '.' || $n === '..') { continue; }
        $r = $rel === '' ? $n : "$rel/$n";
        if (aProteger($r) && in_array($r, PROTEGES, true)) { continue; }
        $voulu = $plan[$r] ?? null;
        $chemin = "$dos/$n";
        if ($voulu === null) { if (!$essai) { supprimer($chemin); } $faits['retires']++; continue; }
        if (isset($voulu['dossier'])) {
            if (is_link($chemin) || !is_dir($chemin)) { if (!$essai) { supprimer($chemin); } $faits['retires']++; continue; }
            $retirer($r);
        } elseif (isset($voulu['lien'])) {
            $attendu = relatif(dirname($chemin), $voulu['lien']);
            if (is_link($chemin) && readlink($chemin) === $attendu) { continue; }
            if (!$essai) { supprimer($chemin); } $faits['retires']++;
        }
    }
};
$retirer('');
// 2. Ce qui est voulu est posé.
foreach ($plan as $r => $v) {
    $chemin = "$cible/$r";
    if (isset($v['protege'])) { if (!$essai) { dossier($chemin, 0775); } continue; }
    if (isset($v['dossier'])) { if (!is_dir($chemin)) { if (!$essai) { dossier($chemin, 0755); } $faits['dossiers']++; } continue; }
    $attendu = relatif(dirname($chemin), $v['lien']);
    if (is_link($chemin) && readlink($chemin) === $attendu) { $faits['inchanges']++; continue; }
    if (!$essai) { symlink($attendu, $chemin); }
    $faits['liens']++;
}
printf("site assemblé dans %s : %d lien(s) posé(s), %d inchangé(s), %d dossier(s), %d retiré(s)%s ; protégés : %s\n",
    $cible, $faits['liens'], $faits['inchanges'], $faits['dossiers'], $faits['retires'], $essai ? ' (essai, rien écrit)' : '', implode(', ', PROTEGES));
