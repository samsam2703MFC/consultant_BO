#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du portail Commercial (tables sm_) — sans base, sans panel.
 *
 *   php commercial/bin/tests_commercial.php
 *
 * Les gains du commercial (20 % le premier mois, ensuite 5 %, par secteur, commande minimum, fin, plancher), les
 * règles réglées, les zones des magasins, les codes de bon, les modèles d'e-mail, la famille d'un lead.
 */
function body(): array { return []; }
function setting(string $k, mixed $d = null): mixed { return $d; }
function settingEcrire(string $k, mixed $v): void {}
function journalAdd(...$a): void {}
final class Db { public static function rows(string $s, array $a = []): array { return []; } public static function row(string $s, array $a = []): ?array { return null; } public static function exec(string $s, array $a = []): int { return 0; } public static function config(): array { return []; } }
require __DIR__ . '/../src/commercial.php';
require __DIR__ . '/../src/commercial_gains.php';
require __DIR__ . '/../src/commercial_semaine.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$proche = static fn (float $a, float $b): bool => abs($a - $b) < 0.006;

echo "Les règles de gains\n";
$d = smGainsDefaut();
verifie('par défaut : 20 % le premier mois, ensuite 5 %, sans fin', $d['taux1'] === 20.0 && $d['mois1'] === 1 && $d['taux2'] === 5.0 && $d['duree'] === 0);
$r = smRegleGains(['taux1' => '150', 'mois1' => '-2', 'taux2' => 'x', 'min' => '25'], $d);
verifie('une règle saisie est bornée (taux ≤ 100, mois ≥ 0) et complétée par la base', $r['taux1'] === 100.0 && $r['mois1'] === 0 && $r['taux2'] === 5.0 && $r['min'] === 25.0);
$gains = ['defaut' => $d, 'secteurs' => ['fetes' => smRegleGains(['taux1' => 10, 'taux2' => 3, 'duree' => 6], $d)], 'plancher' => 0.0, 'tva' => 6.0];
verifie('un secteur sans règle prend la règle par défaut', smRegleSecteur($gains, 'bureau') === $d);
verifie('un secteur réglé garde la sienne', smRegleSecteur($gains, 'fetes')['taux1'] === 10.0);

echo "Les phases d'une commande\n";
verifie('le jour de la 1re commande : départ', smGainsPhase('2026-10-10', '2026-10-10', $d) === [20.0, 'depart']);
verifie('la veille du mois écoulé : départ', smGainsPhase('2026-11-09', '2026-10-10', $d) === [20.0, 'depart']);
verifie('un mois après : ensuite', smGainsPhase('2026-11-10', '2026-10-10', $d) === [5.0, 'suite']);
verifie('sans fin : encore 5 % deux ans après', smGainsPhase('2028-10-10', '2026-10-10', $d) === [5.0, 'suite']);
verifie('fin à 6 mois : plus rien ensuite', smGainsPhase('2027-04-10', '2026-10-10', $gains['secteurs']['fetes']) === [0.0, 'termine']);

echo "Le calcul d'un mois\n";
$clients = [
    ['id' => 1, 'entreprise' => 'Fiduciaire', 'secteur' => 'bureau', 'magasin' => 'Gosselies', 'shopId' => '3', 'cle' => 'c10', 'debut' => '2026-10-01'],
    ['id' => 2, 'entreprise' => 'Salle Saint-Joseph', 'secteur' => 'fetes', 'magasin' => 'Corbais', 'shopId' => '2', 'cle' => 'c20', 'debut' => '2026-11-01'],
    ['id' => 3, 'entreprise' => 'Ancien client', 'secteur' => 'bureau', 'magasin' => 'Halle', 'shopId' => '4', 'cle' => 'c30', 'debut' => '2026-11-15'],
];
$tickets = [
    ['3', 'c10', '2026-10-20', '09:00', 106.0],   // 1re commande du client 1 : départ jusqu'au 20/11
    ['3', 'c10', '2026-11-05', '09:00', 212.0],   // départ : 20 % de 200 HT = 40
    ['3', 'c10', '2026-11-25', '09:00', 53.0],    // ensuite : 5 % de 50 HT = 2,50
    ['2', 'c20', '2026-11-12', '18:00', 318.0],   // fêtes : 10 % de 300 HT = 30
    ['4', 'c30', '2026-11-10', '08:00', 530.0],   // avant son entrée au portail : ne compte pas
    ['4', 'c30', '2026-11-20', '08:00', 106.0],   // 1re commande comptée : 20 % de 100 = 20
    ['3', 'c99', '2026-11-05', '09:00', 1000.0],  // un client pro qui n'est pas au portail
];
$x = smGainsCalcul($clients, $tickets, $gains, '2026-11');
$par = array_column($x['clients'], null, 'id');
verifie('client 1 : 2 commandes en novembre', $par[1]['n'] === 2, (string) $par[1]['n']);
verifie('client 1 : 40 € au départ + 2,50 € ensuite', $proche($par[1]['gain'], 42.5), (string) $par[1]['gain']);
verifie('client 1 : 1re commande le 20/10, départ jusqu’au 20/11', $par[1]['premiere'] === '2026-10-20' && $par[1]['finDepart'] === '2026-11-20');
verifie('le HT se déduit à 6 %', $proche($par[1]['commandes'][0]['ht'], 200.0));
verifie('fêtes : la règle du secteur (10 %)', $proche($par[2]['gain'], 30.0), (string) $par[2]['gain']);
verifie('un ancien client ne compte qu’à partir de son entrée au portail', $par[3]['n'] === 1 && $par[3]['premiere'] === '2026-11-20' && $proche($par[3]['gain'], 20.0));
verifie('un client pro hors portail ne rapporte rien', $proche($x['gain'], 92.5), (string) $x['gain']);
verifie('par secteur : bureau 62,50 €, fêtes 30 €', $proche(array_column($x['parSecteur'], null, 'secteur')['bureau']['gain'], 62.5) && $proche(array_column($x['parSecteur'], null, 'secteur')['fetes']['gain'], 30.0));
verifie('les clients sont triés du plus gros gain au plus petit', $x['clients'][0]['id'] === 1);
$oct = smGainsCalcul($clients, $tickets, $gains, '2026-10');
verifie('octobre : la 1re commande du client 1 au départ (20 % de 100)', $proche($oct['gain'], 20.0), (string) $oct['gain']);

echo "La commande minimum\n";
$g2 = $gains; $g2['defaut'] = smRegleGains(['min' => 150], $d);
$x2 = smGainsCalcul([$clients[0]], $tickets, $g2, '2026-11');
$cmd = $x2['clients'][0]['commandes'];
verifie('une commande sous le minimum ne rapporte rien, et le dit', $cmd[1]['phase'] === 'min' && $cmd[1]['gain'] === 0.0 && $proche($x2['gain'], 40.0));
verifie('la 1re commande compte même sous le minimum pour ouvrir le compteur', $x2['clients'][0]['premiere'] === '2026-10-20');

echo "Le plancher\n";
$g3 = $gains; $g3['plancher'] = 500.0;
$x3 = smGainsCalcul($clients, $tickets, $g3, '2026-11');
verifie('sous le plancher : le complément est dit', $proche($x3['complement'], 407.5) && $proche($x3['du'], 500.0), $x3['complement'] . ' / ' . $x3['du']);
$g3['plancher'] = 50.0;
$x4 = smGainsCalcul($clients, $tickets, $g3, '2026-11');
verifie('au-dessus du plancher : pas de complément', $x4['complement'] === 0.0 && $proche($x4['du'], 92.5));
verifie('sans plancher : rien à compléter', $x['complement'] === 0.0 && $proche($x['du'], $x['gain']));
$vide = smGainsCalcul($clients, [], $g3, '2026-11');
verifie('un mois sans commande : le plancher entier', $proche($vide['du'], 50.0) && $vide['commandes'] === 0);

echo "Les zones, les bons, les modèles, les leads\n";
verifie('la zone de Halle par son nom', in_array('Beersel', smZoneDe('4', 'Atelier by - Halle', []), true));
verifie('la zone de Corbais par son nom', in_array('Wavre', smZoneDe('2', 'Atelier by Berlo - Corbais', []), true));
verifie('une zone réglée l’emporte', smZoneDe('2', 'Corbais', ['2' => ['Wavre', '']]) === ['Wavre']);
verifie('un magasin inconnu : pas de zone', smZoneDe('9', 'Knokke', []) === []);
verifie('un code de bon : majuscules, sans accent ni espace', smCodeBon('Bienvenue Fiduciaire Delvaux & associés') === 'BIENVENUE-FIDUCIAIRE-DELVAUX-A');
verifie('les variables d’un modèle se remplissent, les inconnues disparaissent', smRemplir('Bonjour {{prenom}}, {{rien}}!', ['prenom' => 'Claire']) === 'Bonjour Claire, !');
$m = smModelesDefaut();
$manque = [];
foreach (SM_MODELES as $id => $_) { foreach (SM_LANGUES as $l) { if (!isset($m[$id][$l]) || count($m[$id][$l]) !== 3 || trim($m[$id][$l][1]) === '') { $manque[] = "$id/$l"; } } }
verifie('chaque modèle existe en français et en néerlandais', $manque === [], implode(', ', $manque));
verifie('le HT d’un montant TTC', smHt(106.0, 6.0) === 100.0);
verifie('lead « bureau_connecte » : connecté', smFamilleLead(['statut' => 'bureau_connecte']) === 'connecte');
verifie('lead sans statut : à traiter', smFamilleLead(['statut' => '']) === 'a_traiter');
verifie('lead « vu » : à traiter', smFamilleLead(['statut' => 'vu']) === 'a_traiter');

echo "La semaine et le suivi de terrain\n";
verifie('le lundi d’un mercredi', smLundi('2026-11-19') === '2026-11-16');
verifie('le lundi d’un dimanche : celui d’avant', smLundi('2026-11-22') === '2026-11-16');
verifie('client absent : rappeler', smSuiteProposee('absent', '', [], false) === 'appeler');
verifie('no show : rappeler', smSuiteProposee('no_show', '', [], false) === 'appeler');
verifie('pas le temps : rappeler', smSuiteProposee('pas_le_temps', '', [], true) === 'appeler');
verifie('mauvais contact : écrire', smSuiteProposee('mauvais_contact', '', [], false) === 'ecrire');
verifie('refus de recevoir : plus rien', smSuiteProposee('refus', '', [], false) === 'rien');
verifie('offre validée par un prospect : créer le bureau', smSuiteProposee('vu', 'validee', ['presentation'], false) === 'bureau');
verifie('offre validée par un client : rappeler après la 1re commande', smSuiteProposee('vu', 'validee', [], true) === 'rappel_commande');
verifie('à réfléchir sans dégustation : envoyer un échantillon', smSuiteProposee('vu', 'reflechir', ['presentation'], false) === 'echantillon');
verifie('à réfléchir après dégustation : rappeler', smSuiteProposee('vu', 'reflechir', ['degustation'], false) === 'appeler');
verifie('offre refusée : plus rien', smSuiteProposee('vu', 'refusee', [], false) === 'rien');
verifie('présentation sans réponse : envoyer un devis', smSuiteProposee('vu', '', ['presentation'], false) === 'devis');
verifie('joint au téléphone sans présentation : rappeler', smSuiteProposee('joint', '', [], false) === 'appeler');
verifie('appeler : dans 2 jours', smSuiteDate('appeler', '2026-11-19', null) === '2026-11-21');
verifie('une suite qui tombe un dimanche passe au lundi', smSuiteDate('appeler', '2026-11-20', null) === '2026-11-23');
verifie('rappeler après la 1re commande : dans 7 jours', smSuiteDate('rappel_commande', '2026-11-19', null) === '2026-11-26');
verifie('la date choisie l’emporte', smSuiteDate('appeler', '2026-11-19', '2026-12-01') === '2026-12-01');
verifie('plus rien : pas de date', smSuiteDate('rien', '2026-11-19', '2026-12-01') === null);
verifie('chaque suite crée un type de rendez-vous connu', array_filter(SM_SUITES, static fn ($s) => $s[0] !== null && !isset(SM_RDV_TYPES[$s[0]])) === []);
verifie('le modèle de confirmation de rendez-vous existe en FR et NL', isset(smModelesDefaut()['rdv']['fr'], smModelesDefaut()['rdv']['nl']) && isset(SM_MODELES['rdv']));

echo "\n$ok ok, $ko en échec\n";
exit($ko === 0 ? 0 : 1);
