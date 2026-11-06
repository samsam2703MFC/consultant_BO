<?php
declare(strict_types=1);

/*
 * Manuel du réseau — semis des deux guides (lot 4b de la fusion, 06/11/2026).
 *
 * Le Cockpit devient l'éditeur du Guide pré-ouverture (sections et points) et du Guide des formations et
 * compétences (11 blocs, leurs points, les critères « Réussi si… » proposés, les heures, les 5 pôles). Leur
 * contenu de départ vivait dans le CRM (dépôt atelier-espace-candidat) : ce script le recopie UNE fois dans
 * data/formation/guides/*.json, que src/referentiel_guides.php sème au premier appel. Les fichiers du CRM ne sont
 * pas lisibles depuis le Cockpit en production : seule cette copie sert.
 *
 *   php tools/formation/guides_depuis_crm.php /chemin/vers/atelier-espace-candidat/app/crm/api/lib
 *
 * Sources lues (sans rien exécuter d'autre que leurs constantes et leurs `return`) :
 *   guides.php              GD_SECTIONS_DEFAUT (sections et points du Guide pré-ouverture)
 *   formation.php           FMT_HEURES_DEFAUT, FMT_POLES_DEFAUT
 *   formation_blocs.php     les 11 blocs et leurs 45 points (GÉNÉRÉ dans le CRM depuis le référentiel v25)
 *   formation_criteres.php  les critères « Réussi si… » PROPOSÉS, le renouvellement et les profils de chaque point
 */

$lib = rtrim((string) ($argv[1] ?? ''), '/');
if ($lib === '' || !is_file($lib . '/guides.php') || !is_file($lib . '/formation_blocs.php')) {
    fwrite(STDERR, "Usage : php tools/formation/guides_depuis_crm.php <atelier-espace-candidat>/app/crm/api/lib\n");
    exit(1);
}
// guides.php et formation.php ne font que déclarer constantes et fonctions : les charger n'exécute rien.
require $lib . '/guides.php';
$blocs = require $lib . '/formation_blocs.php';
$criteres = require $lib . '/formation_criteres.php';
$sortie = dirname(__DIR__, 2) . '/data/formation/guides';
if (!is_dir($sortie) && !mkdir($sortie, 0775, true)) { fwrite(STDERR, "Impossible de créer $sortie\n"); exit(1); }
$source = 'Recopié du CRM (atelier-espace-candidat, app/crm/api/lib) le ' . date('d/m/Y') . ' par tools/formation/guides_depuis_crm.php';

$sections = [];
foreach (GD_SECTIONS_DEFAUT as [$k, $tf, $tn, $if, $in, $type, $pts]) {
    $sections[] = ['cle' => $k, 'type' => $type, 'fr' => $tf, 'nl' => $tn, 'intro_fr' => $if, 'intro_nl' => $in,
        'points' => array_map(static fn ($p) => ['cle' => $p[0], 'fr' => $p[1], 'nl' => $p[2], 'qui' => $p[3], 'j' => $p[4],
            'preuve_fr' => $p[5], 'preuve_nl' => $p[6]], $pts)];
}

$outBlocs = [];
foreach ($blocs as $b) {
    $items = [];
    foreach ($b['items'] as $it) {
        $c = $criteres[$it['id']] ?? ['', '', 0, ['gerant']];
        $items[] = ['id' => $it['id'], 'fr' => $it['fr'], 'nl' => $it['nl'], 'dfr' => $it['dfr'], 'dnl' => $it['dnl'], 'fo' => $it['fo'],
            'phase' => $it['phase'], 'bloq' => (bool) $it['bloq'], 'poles' => (bool) $it['poles'],
            'critere_prop_fr' => (string) $c[0], 'critere_prop_nl' => (string) $c[1], 'renouvellement' => (int) $c[2],
            'profils' => array_values((array) $c[3])];
    }
    $outBlocs[] = ['n' => (int) $b['n'], 'fr' => $b['fr'], 'nl' => $b['nl'], 'role' => $b['role'], 'role_nl' => $b['roleNl'], 'fo' => $b['fo'],
        'heures' => (float) (FMT_HEURES_DEFAUT[(int) $b['n']] ?? 0), 'items' => $items];
}
$poles = ['fr' => array_column(FMT_POLES_DEFAUT, 'fr'), 'nl' => array_column(FMT_POLES_DEFAUT, 'nl'), 'repere' => 4];

$json = static fn (array $d): string => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($sortie . '/preouverture.json', $json(['source' => $source, 'sections' => $sections]));
file_put_contents($sortie . '/formations.json', $json(['source' => $source, 'poles' => $poles, 'blocs' => $outBlocs]));
$nPts = array_sum(array_map(static fn ($s) => count($s['points']), $sections));
$nIt = array_sum(array_map(static fn ($b) => count($b['items']), $outBlocs));
echo count($sections) . " sections, $nPts points · " . count($outBlocs) . " blocs, $nIt points de formation → $sortie\n";
