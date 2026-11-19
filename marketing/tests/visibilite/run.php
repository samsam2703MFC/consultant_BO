#!/usr/bin/env php
<?php
/**
 * Visibilité Google — tests des fonctions pures (aucun appel à Google, aucune base).
 *   php tests/visibilite/run.php
 * Le site : les données structurées réelles d'atelierby.be (fixtures/site.html, 09/11/2026).
 * Google : une réponse Places API (New) au format documenté, construite ici.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/google_api.php';
require __DIR__ . '/../../src/visibilite_google.php';

$ok = 0; $ko = 0;
$eq = function (string $nom, mixed $attendu, mixed $obtenu) use (&$ok, &$ko): void {
    if ($attendu === $obtenu) { $ok++; return; }
    $ko++;
    fwrite(STDERR, "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n");
};

// --- le site : quatre boutiques, leurs horaires tels qu'ils sont publiés
$b = visibiliteSiteBoutiques((string) file_get_contents(__DIR__ . '/fixtures/site.html'));
$eq('site : 4 boutiques', 4, count($b));
$parVille = array_column($b, null, 'ville');
$eq('site : Corbais 7 jours', array_fill(0, 7, '06:30–18:30'), $parVille['Corbais']['horaires']);
$eq('site : Gosselies dimanche seul', ['', '', '', '', '', '', '08:00–15:00'], $parVille['Gosselies']['horaires']);
$eq('site : Sombreffe sans horaires', null, $parVille['Sombreffe']['horaires']);
$eq('site : Halle sans horaires', null, $parVille['Halle']['horaires']);
$eq('rapprochement par ville', 'Gosselies', visibiliteBoutiqueSite($b, 'Gosselies', '')['ville']);
$eq('rapprochement par téléphone', 'Halle', visibiliteBoutiqueSite($b, 'Inconnue', '02 779 97 64')['ville']);

// --- Google : une fiche ouverte tous les jours 7:00–18:30, sauf le dimanche 7:00–13:00
$periodes = [];
foreach ([1, 2, 3, 4, 5, 6] as $d) { $periodes[] = ['open' => ['day' => $d, 'hour' => 7, 'minute' => 0], 'close' => ['day' => $d, 'hour' => 18, 'minute' => 30]]; }
$periodes[] = ['open' => ['day' => 0, 'hour' => 7, 'minute' => 0], 'close' => ['day' => 0, 'hour' => 13, 'minute' => 0]];
$g = googleFicheVisibilite([
    'id' => 'ChIJx', 'displayName' => ['text' => 'Boulangerie Max & Sandra'], 'primaryType' => 'bakery',
    'primaryTypeDisplayName' => ['text' => 'Boulangerie'], 'regularOpeningHours' => ['periods' => $periodes],
    'currentOpeningHours' => ['specialDays' => [['date' => ['year' => 2026, 'month' => 11, 'day' => 11]]]],
    'websiteUri' => 'https://webshop.atelierby.be/?shop=3', 'nationalPhoneNumber' => '071 21 80 01',
    'rating' => 4.53, 'userRatingCount' => 96, 'businessStatus' => 'OPERATIONAL', 'photos' => array_fill(0, 4, ['name' => 'p']),
    'takeout' => true, 'location' => ['latitude' => 50.47, 'longitude' => 4.43]]);
$eq('google : lundi', '07:00–18:30', $g['horaires'][0]);
$eq('google : dimanche', '07:00–13:00', $g['horaires'][6]);
$eq('google : jour exceptionnel', ['2026-11-11'], $g['speciaux']);
$eq('google : note arrondie', 4.5, $g['note']);
$eq('google : options', ['À emporter'], $g['options']);
$eq('google : photos', 4, $g['photos']);

// --- les écarts : Gosselies (le site contredit Google, nom sans enseigne, peu de photos)
$m = ['id' => '3', 'nom' => 'Gosselies', 'ville' => 'Gosselies', 'langue' => 'fr', 'placeId' => 'ChIJx'];
$e = visibiliteEcarts($m, $g, null, $parVille['Gosselies'], true);
$eq('écarts : le plus grave en tête', 'Le site contredit Google sur les horaires', $e[0]['quoi']);
$eq('écarts : la liste', ['Le site contredit Google sur les horaires', 'Nom sans l’enseigne', 'Peu de photos'], array_column($e, 'quoi'));
$eq('écarts : site sans horaires', ['Horaires absents du site', 'Nom sans l’enseigne', 'Peu de photos'], array_column(visibiliteEcarts($m, $g, null, ['horaires' => null] + $parVille['Gosselies'], true), 'quoi'));
$eq('écarts : fiche non raccordée', ['Fiche non raccordée'], array_column(visibiliteEcarts(['placeId' => ''] + $m, null, null, null, true), 'quoi'));
$eq('écarts : fiche illisible', 'HTTP 403', visibiliteEcarts($m, null, 'HTTP 403', null, true)[0]['detail']);

// --- utilitaires
$eq('téléphone +32', '027799764', visibiliteChiffres('+32 2 779 97 64'));
$eq('téléphone points', '071218001', visibiliteChiffres('071.218.001'));
$eq('lien webshop', 'webshop', visibiliteLien('https://webshop.atelierby.be/?shop=3'));
$eq('lien accueil', 'accueil', visibiliteLien('https://atelierby.be/nl/'));
$eq('lien page', 'page', visibiliteLien('https://atelierby.be/boutiques/corbais/'));
$eq('lien aucun', 'aucun', visibiliteLien(''));

echo "Visibilité Google : $ok réussi(s), $ko échec(s)\n";
exit($ko > 0 ? 1 : 0);
