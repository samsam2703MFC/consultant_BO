#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du catalogue des types d'offre de la prospection B2B (GET /prospection/offres) — sans base, sans Overpass.
 *
 *   php marketing/bin/tests_prospection_offres.php
 *
 * L'offre qu'un lieu reçoit par son genre (prOffreDe, la même règle que offreDe dans prospection.js), et le genre que
 * le démarchage OpenStreetMap donne aux salles des fêtes (19/11/2026 : elles recevaient le petit-déjeuner d'hôtel).
 */
function body(): array { return []; }
function setting(string $k, mixed $d = null): mixed { return $d; }
function journalAdd(...$a): void {}
final class Db { public static function rows(string $s, array $a = []): array { return []; } public static function row(string $s, array $a = []): ?array { return null; } public static function exec(string $s, array $a = []): void {} }
require __DIR__ . '/../src/prospection.php';
require __DIR__ . '/../src/scouting_osm.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Le catalogue\n";
$O = prOffresDefaut();
$ids = array_column($O, 'id');
verifie('onze offres, sans doublon', count($ids) === 11 && count(array_unique($ids)) === 11, implode(', ', $ids));
verifie('l’offre des salles des fêtes existe', in_array('fetes', $ids, true));
foreach ($O as $o) {
    verifie("$o[id] : argument, contenu et hypothèses", trim($o['pitch']) !== '' && trim($o['contenu']) !== '' && $o['depense'] > 0 && $o['commandes'] > 0 && $o['part'] > 0);
    verifie("$o[id] : l’expression des genres se compile", @preg_match('/' . $o['genres'] . '/iu', '') !== false);
}
verifie('le catalogue en vigueur reprend l’offre (sans réglage de la marque)', in_array('fetes', array_column(prOffres(), 'id'), true));

echo "L'offre d'un lieu par son genre\n";
$cas = [
    ['salle d’événements', 'evenements', 'fetes'],
    ['salle des fêtes', 'evenements', 'fetes'],
    ['hôtel', 'evenements', 'hotel'],
    ['auberge', 'evenements', 'hotel'],
    ['centre de conférences', 'evenements', 'formation'],
    ['centre communautaire', 'administration', 'commune'],
    ['maison communale', 'administration', 'commune'],
    ['salle de fitness', 'sport', 'sport'],
    ['école', 'ecoles', 'ecole'],
    ['maison de repos', 'sante', 'soins'],
    ['entreprise', 'bureaux', 'bureau'],
    ['genre inconnu', 'evenements', 'hotel'],
    ['genre inconnu', 'aucune', 'personnel'],
];
foreach ($cas as [$genre, $famille, $attendu]) {
    $eu = prOffreDe($genre, $famille, $O);
    verifie("$genre ($famille) → $attendu", $eu === $attendu, "reçu $eu");
}

echo "Le genre des salles des fêtes dans OpenStreetMap\n";
$el = static fn (int $id, array $tags) => ['type' => 'node', 'id' => $id, 'lat' => 50.6, 'lon' => 4.6, 'tags' => $tags];
$d = ScoutingOsm::analyserDemarchage(['elements' => [
    $el(1, ['amenity' => 'community_centre', 'community_centre' => 'village_hall', 'name' => 'Foyer de Blanmont']),
    $el(2, ['amenity' => 'community_centre', 'name' => 'Salle Saint-Joseph']),
    $el(3, ['amenity' => 'community_centre', 'name' => 'Maison de quartier']),
    $el(4, ['amenity' => 'events_venue', 'name' => 'Le Domaine']),
    $el(5, ['amenity' => 'community_centre', 'name' => 'Feestzaal De Linde']),
]], 50.6, 4.6);
$parId = array_column($d['lieux'], null, 'id');
foreach ([['n1', 'salle de village'], ['n2', '« salle » dans le nom'], ['n5', '« feestzaal » dans le nom']] as [$id, $quoi]) {
    $l = $parId[$id] ?? null;
    verifie("$quoi → salle des fêtes, famille événements, offre fêtes", $l !== null && $l['genre'] === 'salle des fêtes' && $l['famille'] === 'evenements'
        && prOffreDe($l['genre'], $l['famille'], $O) === 'fetes', $l === null ? 'absent' : "$l[genre] / $l[famille]");
}
$l = $parId['n3'] ?? null;
verifie('un centre communautaire ordinaire reste à l’administration (réceptions)', $l !== null && $l['famille'] === 'administration' && prOffreDe($l['genre'], $l['famille'], $O) === 'commune');
$l = $parId['n4'] ?? null;
verifie('une salle d’événements reçoit l’offre des fêtes', $l !== null && prOffreDe($l['genre'], $l['famille'], $O) === 'fetes');

echo "\n$ok ok, $ko en échec\n";
exit($ko === 0 ? 0 : 1);
