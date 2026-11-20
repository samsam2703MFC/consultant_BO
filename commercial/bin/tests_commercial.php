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
require __DIR__ . '/../src/commercial_meta.php';
require __DIR__ . '/../src/commercial_photos.php';
require __DIR__ . '/../src/commercial_commande.php';

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
verifie('par défaut : 20 % le 1er mois, 10 % jusqu’au 3e, 5 % jusqu’au 12e', $d['taux1'] === 20.0 && $d['mois1'] === 1 && $d['taux2'] === 10.0 && $d['mois2'] === 3 && $d['taux3'] === 5.0 && $d['duree'] === 12);
$r = smRegleGains(['taux1' => '150', 'mois1' => '-2', 'taux2' => 'x', 'min' => '25'], $d);
verifie('une règle saisie est bornée (taux ≤ 100, mois ≥ 0) et complétée par la base', $r['taux1'] === 100.0 && $r['mois1'] === 0 && $r['taux2'] === 10.0 && $r['taux3'] === 5.0 && $r['min'] === 25.0);
$gains = ['defaut' => $d, 'secteurs' => ['fetes' => smRegleGains(['taux1' => 10, 'taux2' => 3, 'duree' => 6], $d)], 'plancher' => 0.0, 'tva' => 6.0];
verifie('un secteur sans règle prend la règle par défaut', smRegleSecteur($gains, 'bureau') === $d);
verifie('un secteur réglé garde la sienne', smRegleSecteur($gains, 'fetes')['taux1'] === 10.0);

echo "Les phases d'une commande\n";
verifie('le jour de la 1re commande : départ', smGainsPhase('2026-10-10', '2026-10-10', $d) === [20.0, 'depart']);
verifie('la veille du mois écoulé : départ', smGainsPhase('2026-11-09', '2026-10-10', $d) === [20.0, 'depart']);
verifie('un mois après : le palier à 10 %', smGainsPhase('2026-11-10', '2026-10-10', $d) === [10.0, 'palier']);
verifie('trois mois après : 5 %', smGainsPhase('2027-01-10', '2026-10-10', $d) === [5.0, 'suite']);
verifie('après 12 mois : plus rien', smGainsPhase('2027-10-10', '2026-10-10', $d) === [0.0, 'termine']);
verifie('sans fin (durée 0) : encore 5 % deux ans après', smGainsPhase('2028-10-10', '2026-10-10', smRegleGains(['duree' => 0], $d)) === [5.0, 'suite']);
verifie('un palier ne finit pas avant le départ', smRegleGains(['mois1' => 4, 'mois2' => 2], $d)['mois2'] === 4);
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
verifie('client 1 : 40 € au départ + 5 € au palier (10 % de 50)', $proche($par[1]['gain'], 45.0), (string) $par[1]['gain']);
verifie('client 1 : 1re commande le 20/10, départ jusqu’au 20/11', $par[1]['premiere'] === '2026-10-20' && $par[1]['finDepart'] === '2026-11-20');
verifie('le HT se déduit à 6 %', $proche($par[1]['commandes'][0]['ht'], 200.0));
verifie('fêtes : la règle du secteur (10 %)', $proche($par[2]['gain'], 30.0), (string) $par[2]['gain']);
verifie('un ancien client ne compte qu’à partir de son entrée au portail', $par[3]['n'] === 1 && $par[3]['premiere'] === '2026-11-20' && $proche($par[3]['gain'], 20.0));
verifie('un client pro hors portail ne rapporte rien', $proche($x['gain'], 95.0), (string) $x['gain']);
verifie('par secteur : bureau 65 €, fêtes 30 €', $proche(array_column($x['parSecteur'], null, 'secteur')['bureau']['gain'], 65.0) && $proche(array_column($x['parSecteur'], null, 'secteur')['fetes']['gain'], 30.0));
verifie('les clients sont triés du plus gros gain au plus petit', $x['clients'][0]['id'] === 1);
$oct = smGainsCalcul($clients, $tickets, $gains, '2026-10');
verifie('octobre : la 1re commande du client 1 au départ (20 % de 100)', $proche($oct['gain'], 20.0), (string) $oct['gain']);

echo "Qui paie, l'objectif, le coût et le ROI\n";
$fr = array_column($x['franchises'], 'montant', 'shopId');
verifie('le taux de départ est à la charge des franchisés : 90 €', $proche($x['aLaChargeFranchises'], 90.0), (string) $x['aLaChargeFranchises']);
verifie('magasin par magasin : Gosselies 40 €, Corbais 30 €, Halle 20 €', $proche($fr['3'], 40.0) && $proche($fr['2'], 30.0) && $proche($fr['4'], 20.0));
verifie('la marque paie le reste : 5 €', $proche($x['aLaChargeMarque'], 5.0));
verifie('chaque commande dit qui la paie', $par[1]['commandes'][0]['payeur'] === 'franchise' && $par[1]['commandes'][1]['payeur'] === 'marque');
verifie('objectif par défaut : 5 000 € HT par mois', $x['objectif'] === 5000.0);
verifie('650 € HT apportés : 13 % de l’objectif, il manque 4 350 €', $proche((float) $x['atteinte'], 13.0) && $proche($x['manque'], 4350.0) && $x['objectifAtteint'] === false);
verifie('nouveaux clients du mois : 2 (1re commande en novembre)', $x['nouveauxDuMois'] === 2 && $proche($x['htNouveauxDuMois'], 400.0));
$g4 = $gains + ['cout' => 3000.0];
$x5 = smGainsCalcul($clients, $tickets, $g4, '2026-11');
verifie('coût de la marque : 3 000 € + 5 € de commissions', $proche($x5['coutTotal'], 3005.0), (string) $x5['coutTotal']);
verifie('ROI sur le CA HT : (650 − 3 005) ÷ 3 005', $proche((float) $x5['roi'], -78.4) && $proche((float) $x5['multiple'], 0.22), $x5['roi'] . ' / ' . $x5['multiple']);
$x6 = smGainsCalcul($clients, $tickets, $g4 + ['marge' => 60.0], '2026-11');
verifie('ROI sur la marge (60 %) : le retour est 390 €', $proche($x6['retour'], 390.0));
$x7 = smGainsCalcul($clients, $tickets, $g4 + ['departFranchise' => false], '2026-11');
verifie('sans charge aux franchisés : la marque paie tout', $x7['aLaChargeFranchises'] === 0.0 && $proche($x7['aLaChargeMarque'], 95.0) && $proche($x7['coutTotal'], 3095.0));
$x8 = smGainsCalcul($clients, $tickets, $gains + ['objectif' => 600.0], '2026-11');
verifie('objectif de 600 € : atteint', $x8['objectifAtteint'] === true && $x8['manque'] === 0.0);
$x9 = smGainsCalcul($clients, $tickets, $gains, '2026-11');
verifie('sans coût réglé : pas de ROI', $x9['roi'] === null);

echo "La simulation d'un client\n";
$sim = smSimulation(1000.0, $d, true);
verifie('1 000 € HT par mois : 200 € le 1er mois', $sim['mois'][0]['gain'] === 200.0 && $sim['mois'][0]['franchise'] === 200.0);
verifie('100 € les mois 2 et 3', $sim['mois'][1]['gain'] === 100.0 && $sim['mois'][2]['gain'] === 100.0 && $sim['mois'][1]['phase'] === 'palier');
verifie('50 € du 4e au 12e mois', $sim['mois'][3]['gain'] === 50.0 && $sim['mois'][11]['gain'] === 50.0);
verifie('sur l’année : 200 + 2 × 100 + 9 × 50 = 850 €', $proche($sim['total'], 850.0), (string) $sim['total']);
verifie('dont 200 € payés par le franchisé, 650 € par la marque', $proche($sim['franchise'], 200.0) && $proche($sim['marque'], 650.0));
verifie('un client sous la commande minimum ne rapporte rien', smSimulation(100.0, smRegleGains(['min' => 150], $d), true)['total'] === 0.0);

echo "La commande minimum\n";
$g2 = $gains; $g2['defaut'] = smRegleGains(['min' => 150], $d);
$x2 = smGainsCalcul([$clients[0]], $tickets, $g2, '2026-11');
$cmd = $x2['clients'][0]['commandes'];
verifie('une commande sous le minimum ne rapporte rien, et le dit', $cmd[1]['phase'] === 'min' && $cmd[1]['gain'] === 0.0 && $proche($x2['gain'], 40.0));
verifie('la 1re commande compte même sous le minimum pour ouvrir le compteur', $x2['clients'][0]['premiere'] === '2026-10-20');

echo "Le plancher\n";
$g3 = $gains; $g3['plancher'] = 500.0;
$x3 = smGainsCalcul($clients, $tickets, $g3, '2026-11');
verifie('sous le plancher : le complément est dit', $proche($x3['complement'], 405.0) && $proche($x3['du'], 500.0), $x3['complement'] . ' / ' . $x3['du']);
$g3['plancher'] = 50.0;
$x4 = smGainsCalcul($clients, $tickets, $g3, '2026-11');
verifie('au-dessus du plancher : pas de complément', $x4['complement'] === 0.0 && $proche($x4['du'], 95.0));
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

echo "Le magasin d'un bureau\n";
$mags = [];
foreach (['4' => 'Atelier by - Halle', '2' => 'Atelier by Berlo - Corbais', '5' => 'Atelier by Harmonie - Sombreffe', '3' => 'Atelier by Max & Sandra - Gosselies'] as $id => $n) { $mags[$id] = ['nom' => $n, 'cps' => smCpsDe((string) $id, $n, [])]; }
verifie('1360 Orbais : Corbais', smMagasinPourCp('1360', $mags) === '2');
verifie('6041 Gosselies : Gosselies', smMagasinPourCp('6041', $mags) === '3');
verifie('1500 Halle : Halle', smMagasinPourCp('B-1500', $mags) === '4');
verifie('5030 Gembloux (partagé) : Sombreffe, comme la commune', smMagasinPourCp('5030', $mags) === '5');
verifie('1450 Chastre (partagé) : Corbais, comme la commune', smMagasinPourCp('1450', $mags) === '2');
verifie('6250 (partagé) : Gosselies, comme Aiseau-Presles', smMagasinPourCp('6250', $mags) === '3');
verifie('1000 Bruxelles : hors des zones', smMagasinPourCp('1000', $mags) === null);
verifie('un code incomplet : rien', smMagasinPourCp('136', $mags) === null);
verifie('aucun code postal dans deux zones', count(array_merge(...array_values(array_map(static fn ($m) => $m['cps'], $mags)))) === count(array_unique(array_merge(...array_values(array_map(static fn ($m) => $m['cps'], $mags))))));
verifie('des codes réglés l’emportent', smCpsDe('2', 'Corbais', ['2' => ['1300', 'x', '13']]) === ['1300']);
verifie('le code postal d’une adresse Google', smCpAdresse('Rue de la Station 4, 1360 Perwez, Belgique') === '1360');
verifie('un numéro de rue n’est pas un code postal', smCpAdresse('Chaussée de Nivelles 1360, Belgique') === '');
verifie('un formulaire de recrutement flexi-job est reconnu', smFormulaireRecrutement(['nom' => 'RECRUTEMENT B2B HALLE - FR', 'questions' => [['label' => 'Es-tu éligible au statut flexi-job ?']]]));
verifie('un formulaire de franchise est reconnu', smFormulaireRecrutement(['nom' => 'FRANCHISE DOUR', 'questions' => []]));
verifie('un formulaire « Lunch au bureau » est un formulaire de bureaux', !smFormulaireRecrutement(['nom' => 'Lunch au bureau — Gosselies', 'questions' => [['label' => 'Nom de l’entreprise'], ['label' => 'Nombre de personnes']]]));
verifie('le formulaire créé sur la Page (fonction « Job title ») est un formulaire de bureaux', !smFormulaireRecrutement(['nom' => 'LUNCH AU BUREAU - FR', 'questions' => array_map(static fn ($l) => ['label' => $l], ['Company name', 'Combien de personnes dans votre équipe ?', 'Quel est votre besoin ?', 'Full name', 'Job title', 'Email', 'Phone number', 'Post code'])]));
verifie('« un job étudiant » reste du recrutement', smFormulaireRecrutement(['nom' => 'Un job chez nous', 'questions' => []]));
// La commande d'échantillon dans le système de commande du magasin (commercial_commande.php).
$brut = [
    ['id' => 11, 'name' => 'Croissant', 'category_name' => 'Viennoiserie Ind.', 'id_category' => 7, 'portion_price_gross' => '1.30', 'recipe_cost_net' => '0.31', 'is_active' => 1, 'office_delivery' => 1],
    ['id' => 12, 'name' => 'Pain au chocolat', 'category_name' => 'Viennoiserie Ind.', 'id_category' => 7, 'portion_price_gross' => '1.50', 'recipe_cost_net' => null, 'is_active' => 1, 'office_delivery' => 1],
    ['id' => 21, 'name' => 'Plateau 12 sandwiches', 'category_name' => 'Plateau Sandwich', 'id_category' => 9, 'portion_price_gross' => '42.00', 'recipe_cost_net' => '13.5', 'is_active' => 1, 'office_delivery' => 1],
    ['id' => 99, 'name' => 'Ancien produit', 'category_name' => 'Plateau Sandwich', 'is_active' => 0],
];
$cat = smCatalogueDe($brut);
verifie('le catalogue du magasin est rangé par catégorie, les produits inactifs écartés', array_column($cat, 'nom') === ['Plateau Sandwich', 'Viennoiserie Ind.'] && count($cat[0]['produits']) === 1 && $cat[1]['produits'][0]['prix'] === 1.3);
$idx = smCatalogueIndex($cat);
[$lg, $err] = smLignesCatalogue([['id' => 11, 'q' => 6], ['id' => 21, 'q' => 1], ['id' => 12, 'q' => 0]], $idx);
verifie('les lignes reprennent le nom, la catégorie et le prix du catalogue ; une quantité nulle est écartée', $err === null && count($lg) === 2 && $lg[0]['produit'] === 'Croissant' && $lg[1]['cat'] === 'Plateau Sandwich' && $lg[0]['q'] === '6');
verifie('un produit hors du catalogue du magasin est refusé', smLignesCatalogue([['id' => 99, 'q' => 1]], $idx)[1] !== null);
verifie('rien de choisi : refusé', smLignesCatalogue([], $idx)[1] === 'choisissez au moins un produit');
verifie('le coût matière : coût de la recette × quantité', smCoutLignes($lg) === round(6 * 0.31 + 13.5, 2));
$ech = ['id' => 7, 'shopId' => '2', 'prospect' => 'Bureau Test', 'contact' => 'Anne', 'le' => '2026-11-24 10:30:00', 'mode' => 'livraison', 'adresse' => 'Rue Haute 1, 1435', 'note' => '', 'contenu' => $lg];
$corps = smCommandeCorps($ech, '2026-11-19 20:00:00');
verifie('la commande TF Buddy : magasin, date de retrait, statut « nouvelle »', $corps['id_shop'] === 2 && $corps['pick_up_datetime'] === '2026-11-24 10:30:00' && $corps['id_order_status'] === 1);
verifie('la commande est marquée « ÉCHANTILLON COMMERCIAL » et nomme le prospect', str_starts_with($corps['comment'], 'ÉCHANTILLON COMMERCIAL — Bureau Test (Anne)'));
verifie('chaque ligne est offerte : remise égale à sa valeur, total 0', $corps['products'][0]['item_discount_value'] === 7.8 && $corps['products'][0]['total_gross_value_after_discount'] === 0 && $corps['products'][1]['id_product'] === 21);
verifie('sans date, la commande est pour le lendemain 10 h', smCommandeCorps(['le' => null] + $ech, '2026-11-19 20:00:00')['pick_up_datetime'] === '2026-11-20 10:00:00');
verifie('des lignes notées à la main ne partent pas dans TF Buddy', smCommandeCorps(['contenu' => [['produit' => 'Croissant', 'q' => '6']]] + $ech, '2026-11-19 20:00:00')['products'] === []);

// La photo des échantillons reçus (commercial_photos.php) : ce qui passe et ce qui est refusé.
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
[$ph, $err] = smPhotosLues([['data' => $png]]);
verifie('une photo PNG est lue (format, taille)', $err === null && count($ph) === 1 && $ph[0]['ext'] === 'png' && $ph[0]['largeur'] === 1);
verifie('aucune photo : refusé', smPhotosLues([])[1] === 'aucune photo reçue');
verifie('un texte déguisé en image est refusé', smPhotosLues([['data' => 'data:image/jpeg;base64,' . base64_encode(str_repeat('<?php echo 1; ?>', 10))]])[1] !== null);
verifie('sept photos d’un coup : refusé', smPhotosLues(array_fill(0, 7, ['data' => $png]))[1] !== null);
verifie('« reçu » est un statut d’échantillon, entre commandé et livré', array_slice(SM_ECH_STATUTS, 1, 3) === ['commande', 'recu', 'livre']);
$pub = smPubLunch('fr');
verifie('la publicité « Lunch au bureau » : texte, titre, bouton, pour le formulaire FR', $pub['formulaire'] === 'LUNCH AU BUREAU - FR' && str_contains($pub['texte'], 'dégustation offerte') && mb_strlen($pub['titre']) <= 40 && $pub['bouton'] !== '');
verifie('les deux vidéos rendues (9:16 et 4:5) sont servies avec leur affiche', array_column($pub['videos'], 'format') === ['9x16', '4x5'] && str_ends_with($pub['videos'][0]['affiche'], '.jpg'));
$pubNl = smPubLunch('nl');
verifie('la publicité en néerlandais est celle de Halle, avec ses deux vidéos', str_contains($pubNl['texte'], 'proeverij') && str_contains($pubNl['texte'], 'Halle') && $pubNl['magasin'] === 'Halle' && array_column($pubNl['videos'], 'format') === ['9x16', '4x5']);
$ap = smApercuFormulaire('fr');
verifie('l’aperçu du formulaire : accueil, huit champs (nom, e-mail, téléphone préremplis ; personnes et besoin à choix), remerciement', count($ap['questions']) === 8
    && $ap['questions'][0]['label'] === 'Nom de l’entreprise' && count($ap['questions'][1]['choix']) === 4 && $ap['questions'][5]['prerempli'] && !$ap['questions'][7]['prerempli'] && $ap['merci']['bouton'] === 'Notre site');
verifie('l’aperçu NL : champs en néerlandais, Halle au remerciement', smApercuFormulaire('nl')['questions'][7]['label'] === 'Postcode' && str_contains(smApercuFormulaire('nl')['merci']['titre'], 'Halle'));
$flNl = smFormulaireLunch('nl', 'https://atelierby.be/');
verifie('le formulaire NL : tranches de personnes en néerlandais, Halle dans l’accueil et le remerciement', array_column($flNl['questions'][1]['options'], 'value') === ['1 tot 9', '10 tot 24', '25 tot 49', '50 of meer']
    && str_contains($flNl['context_card']['content'][0], 'Halle') && str_contains($flNl['thank_you_page']['title'], 'Halle') && $flNl['name'] === 'LUNCH OP KANTOOR - NL');
verifie('les formulaires que le portail peut mettre en inactif : « Lunch au bureau » FR et NL, rien d’autre', smFormulairesLunchNoms() === ['LUNCH AU BUREAU - FR', 'LUNCH OP KANTOOR - NL']);
$fl = smFormulaireLunch('fr', 'https://atelierby.be/');
verifie('le formulaire « Lunch au bureau » demande le code postal (le magasin) et l’entreprise', in_array('POST_CODE', array_column($fl['questions'], 'type'), true) && in_array('COMPANY_NAME', array_column($fl['questions'], 'type'), true));

echo "\n$ok ok, $ko en échec\n";
exit($ko === 0 ? 0 : 1);
