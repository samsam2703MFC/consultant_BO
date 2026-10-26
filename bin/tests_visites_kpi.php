#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de « KPI et santé du magasin » (tablette du consultant, GET /visites/kpi/{shop}) — sans base, sans panel.
 *
 *   php bin/tests_visites_kpi.php
 *
 * Ce qui doit rester vrai : le CA par ETP ne devine jamais un ETP et situe le magasin dans le réseau ; la structure des
 * ventes compare le magasin au RESTE du réseau, sans les entrées techniques, et ne regrave pas un mois déjà lu.
 *
 * Le module lit le panel, la base et les autres modules par des fonctions : des doublures les remplacent ici.
 */

// La route pose un code HTTP : la sortie reste en mémoire jusqu'à la fin, sinon PHP dit les en-têtes déjà partis.
ob_start();

// --- doublures -----------------------------------------------------------------------
$ECRITS = []; $LIGNES = [];
final class Db
{
    public static function rows(string $sql, array $a = []): array { global $LIGNES; foreach ($LIGNES as $k => $l) { if (str_contains($sql, $k)) { return $l; } } return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void { global $ECRITS; $ECRITS[] = $a; }
}
$REGLAGES = ['heuresEtpMois' => 168];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }
function viMagasins(): array
{
    return ['2' => ['id' => '2', 'court' => 'Corbais'], '3' => ['id' => '3', 'court' => 'Gosselies'], '4' => ['id' => '4', 'court' => 'Halle']];
}
function nombreOuNull(array $d, array $cles): ?float
{
    foreach ($cles as $c) { if (isset($d[$c]) && is_numeric($d[$c])) { return (float) $d[$c]; } }
    return null;
}
function kpiSeuil(string $code, float $defaut): float { return 13000.0; }
$TTL = null;
function rcServi(string $cle, callable $calc, callable $ttl, bool $forcer = false, ?string $route = null, int $perime = 0): array
{
    global $TTL; $r = $calc(); $TTL = $ttl($r); return $r;
}

$MOIS = null; // les trois mois clos, posés après le chargement du module
// Le CA du mois : Corbais et Gosselies tous les mois, Halle seulement le dernier.
function pvKpisMoisLot(array $paires): void {}
function pvKpisMois(int $sid, string $m): ?array
{
    global $MOIS;
    $ca = [2 => 120000.0, 3 => 47000.0, 4 => 44000.0][$sid] ?? null;
    if ($sid === 4 && $m !== $MOIS[2]) { return null; }
    return $ca === null ? null : ['ca' => $ca, 'tickets' => 1000, 'panier' => null];
}
// Le planning : Corbais et Gosselies planifiés, Halle jamais ; le premier mois, personne.
$GET_ETP = null;
function ep_stores_etp(): array
{
    global $MOIS, $GET_ETP; $GET_ETP = $_GET;
    $out = [];
    foreach ([1, 2] as $i) {
        [$a, $m] = array_map('intval', explode('-', $MOIS[$i]));
        $out[] = ['storeId' => '2', 'annee' => $a, 'mois' => $m, 'heures' => 1600, 'etp' => 10.0];
        $out[] = ['storeId' => '3', 'annee' => $a, 'mois' => $m, 'heures' => 840, 'etp' => 5.0];
    }
    return $out;
}
// Les ventes par catégorie : le mois du milieu reste muet ; une entrée technique (99) vend aussi.
$APPELS = [];
final class PanelApi
{
    public static function configured(): bool { return true; }
    public static function getParallele(array $paths, int $n = 4): array
    {
        global $APPELS, $MOIS; $APPELS = array_merge($APPELS, array_keys($paths));
        $mag = static fn (int $id, array $cats): array => ['shop_id' => $id, 'categories' => array_map(static fn ($k, $v) => ['name' => $k, 'ca' => $v], array_keys($cats), $cats)];
        $out = [];
        foreach ($paths as $k => $p) {
            $out[$k] = $k === $MOIS[1] ? null : ['shops' => [
                $mag(2, ['Traiteur' => 5000, 'Viennoiserie' => 2000, 'Tartes' => 3000]),
                $mag(3, ['Traiteur' => 3000, 'Viennoiserie' => 4000, 'Tartes' => 1000]),
                $mag(4, ['Traiteur' => 1000, 'Viennoiserie' => 1000, 'Boulangerie' => 2000]),
                $mag(99, ['Traiteur' => 90000]),
            ]];
        }
        return $out;
    }
}

// Les tâches non conformes d'une fenêtre (ep_pwa_tasks_nc) : on retient la fenêtre demandée.
$GET_NC = null;
function ep_pwa_tasks_nc(): array
{
    global $GET_NC; $GET_NC = $_GET;
    return ['nc' => [['taskId' => '101', 'jour' => '2026-10-03', 'tache' => 'Vitrine salée', 'note' => 2, 'comment' => 'Vitrine à moitié vide', 'consultant' => 'Pierre B.',
        'releveeLe' => '2026-10-03 14:20', 'recidive' => null, 'suite' => ['jour' => '2026-10-10', 'note' => 4, 'conforme' => true]]], 'indispo' => false];
}

// Le CA face au budget : le seuil des visites, et Résultat (semaine, mois) — chaque jour vaut 1 000 € d'objectif ;
// Corbais vend 900 €/jour (−10 %), Gosselies 800 € (−20 %), Halle 1 100 € (+10 %).
function viSeuils(): array { return ['caOrange' => 10]; }
$GET_RES = [];
function ep_exploitation_periode(): array
{
    global $GET_RES; $GET_RES[] = $_GET;
    $vue = (string) $_GET['vue']; $b = tobBornes((string) $_GET['date'])[$vue]; $jusqua = min($b[1], date('Y-m-d'));
    $mag = [];
    foreach (['2' => 900.0, '3' => 800.0, '4' => 1100.0] as $id => $ca) {
        $jours = [];
        foreach (tobJours($b[0], $b[1]) as $j) { $jours[] = ['date' => $j, 'objectif' => 1000.0] + ($j <= $jusqua ? ['ca' => $ca] : []); }
        $n = count(tobJours($b[0], $jusqua));
        $r = $ca * $n;
        $mag[] = ['shopId' => (string) $id, 'ouvert' => true, 'jours' => $jours, 'objectif' => 1000.0 * count($jours), 'realise' => $r, 'attendu' => 1000.0 * $n, 'objectifSource' => 'budget',
            'coutMatiere' => 0.3 * $r, 'coutMatierePct' => 30.0, 'margeBrute' => 0.7 * $r, 'margeBrutePct' => 70.0, 'invendus' => 0.0, 'invendusPct' => 0.0,
            'labour' => 0.25 * $r, 'labourPct' => 25.0, 'overhead' => 0.1 * $r, 'overheadPct' => 10.0, 'net' => 0.35 * $r, 'netPct' => 35.0];
    }
    return ['du' => $b[0], 'jusqua' => $jusqua, 'ponderation' => ['ok' => true], 'seuils' => ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5], 'magasins' => $mag];
}
// Résultat du jour (la veille) : Corbais 900 € pour 1 000 € d'objectif, Gosselies 700 €, Halle fermé.
function ep_exploitation_jour(): array
{
    $l = static fn (string $id, float $ca): array => ['shopId' => $id, 'ouvert' => true, 'ca' => $ca, 'objectifJour' => 1000.0, 'objectifSource' => 'budget',
        'coutMatiere' => 300.0, 'coutMatierePct' => 33.3, 'margeBrute' => $ca - 300.0, 'margeBrutePct' => round(($ca - 300) / $ca * 100, 1), 'invendus' => 20.0, 'invendusPct' => 2.2,
        'labour' => 250.0, 'labourPct' => 27.8, 'overhead' => 100.0, 'overheadPct' => 11.1, 'net' => $ca - 670.0, 'netPct' => round(($ca - 670) / $ca * 100, 1)];
    return ['date' => $_GET['date'] ?? null, 'seuils' => ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5], 'magasins' => [$l('2', 900.0), $l('3', 700.0), ['shopId' => '4', 'ouvert' => false]]];
}
// Le P&L mensuel (ep_perf) : 120 k€ par mois, matière 30 %, personnel 25 %, frais généraux 10 % ; mai sans coût matière à Gosselies.
function ep_perf(): array
{
    $a = (int) ($_GET['annees'] ?? date('Y')); $out = [];
    foreach (['2', '3'] as $sid) { for ($m = 1; $m <= 12; $m++) { $out[] = ['storeId' => $sid, 'annee' => $a, 'mois' => $m, 'ca' => 120000.0, 'foodCostPct' => $sid === '3' && $m === 5 ? null : 30.0, 'labourCostPct' => 25.0, 'overheadPct' => 10.0]; } }
    return $out;
}

// La caisse gravée par jour (vpLu : CA de tous les tickets, tickets des clients pro) et le webshop (coCommandes) :
// Corbais 1 000 € par jour dont 450 € de pro, une commande click & collect de 500 € encaissée en caisse et une
// livraison de 200 € le 1er de chaque mois ; Gosselies 1 000 € dont 100 € de pro, sans webshop ; Halle, webshop muet.
function vpLu(int $sid, string $j): ?array
{
    $pro = [2 => 450.0, 3 => 100.0, 4 => 200.0][$sid] ?? null;
    return $pro === null ? null : ['ca' => 1000.0, 't' => [['10:00', 'c1', $pro, 1, 0]], 'sansTva' => ['tickets' => 1, 'ca' => $sid === 3 ? 50.0 : 0.0]];
}
function coCommandes(int $sid, string $depuis): ?array
{
    global $MOIS;
    if ($sid === 4) { return null; }
    if ($sid === 3) { return []; }
    $out = [];
    foreach ($MOIS as $m) {
        $out[] = ['quand' => $m . '-01 11:00', 'canal' => 'cc', 'montant' => 500.0, 'encaissee' => true];
        $out[] = ['quand' => $m . '-01 12:00', 'canal' => 'liv', 'montant' => 200.0, 'encaissee' => false];
        $out[] = ['quand' => $m . '-02 09:00', 'canal' => 'compt', 'montant' => 80.0, 'encaissee' => true];
    }
    $out[] = ['quand' => date('Y-m-d') . ' 10:00', 'canal' => 'cc', 'montant' => 999.0, 'encaissee' => false];
    return $out;
}

require __DIR__ . '/../src/tablette_objectifs.php';
require __DIR__ . '/../src/visites_kpi.php';
$MOIS = vkMoisClos(3);

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- 1. les mois clos ------------------------------------------------------------------
echo "Les mois clos\n";
verifie('trois mois, le dernier est le mois passé', count($MOIS) === 3 && $MOIS[2] === date('Y-m', strtotime('first day of last month')), implode(',', $MOIS));
verifie('du plus ancien au plus récent', $MOIS[0] < $MOIS[1] && $MOIS[1] < $MOIS[2]);
verifie('le libellé du mois', vkMoisLab('2026-09') === 'sept. 2026');

// --- 2. le CA par ETP -------------------------------------------------------------------
echo "CA par ETP\n";
$E = vkCaEtp(2, $MOIS);
$d = $E['mois'][2];
verifie('CA du mois ÷ ETP planifiés', $d['caEtp'] === 12000 && $d['etp'] === 10.0 && $d['ca'] === 120000.0, json_encode($d));
verifie('le réseau : CA total ÷ ETP totaux des magasins qui ont les deux', $d['reseau'] === (int) round(167000 / 15), (string) $d['reseau']);
verifie('Halle, sans planning, sort du réseau du mois', $d['sur'] === 2 && $d['rang'] === 1, json_encode($d));
verifie('un mois sans planning n’a pas de CA par ETP, et le dit sans le deviner', $E['mois'][0]['caEtp'] === null && $E['mois'][0]['reseau'] === null && $E['mois'][0]['ca'] === 120000.0);
verifie('le seuil vient de la Table KPI', $E['seuil'] === 13000.0);
verifie('le planning est demandé pour les années des trois mois', $GET_ETP['annees'] === implode(',', array_unique(array_map(static fn ($m) => (int) substr($m, 0, 4), $MOIS))));
verifie('la requête reprend ses paramètres après l’appel', !isset($_GET['annees']));
$E3 = vkCaEtp(3, $MOIS);
verifie('Gosselies : 9 400 € par ETP, 2e sur 2', $E3['mois'][2]['caEtp'] === 9400 && $E3['mois'][2]['rang'] === 2);
$E4 = vkCaEtp(4, $MOIS);
verifie('Halle : CA lu mais pas d’ETP — ni CA par ETP ni rang', $E4['mois'][2]['caEtp'] === null && $E4['mois'][2]['rang'] === null && $E4['mois'][2]['ca'] === 44000.0);

// --- 3. la structure des ventes face au reste du réseau ---------------------------------------
echo "Structure des ventes\n";
$ECRITS = []; $APPELS = [];
$S = vkStructure(2, $MOIS);
$parCat = array_column($S['categories'], null, 'categorie');
verifie('deux mois lus sur trois : le mois muet le dit', $S['moisLus'] === 2 && $S['moisDemandes'] === 3);
verifie('le CA du magasin et du reste du réseau (sans l’entrée technique)', $S['ca'] === 20000.0 && $S['caReste'] === 24000.0 && $S['autres'] === 2, json_encode([$S['ca'], $S['caReste'], $S['autres']]));
verifie('Traiteur : 50 % ici, 33,3 % dans le reste, +16,7 pts', $parCat['Traiteur']['part'] === 50.0 && $parCat['Traiteur']['partReste'] === 33.3 && $parCat['Traiteur']['ecart'] === 16.7, json_encode($parCat['Traiteur']));
verifie('une catégorie que le magasin ne vend pas : 0 %, l’écart négatif', $parCat['Boulangerie']['part'] === 0.0 && $parCat['Boulangerie']['ecart'] === -16.7, json_encode($parCat['Boulangerie']));
verifie('rangées par CA du magasin', array_column($S['categories'], 'categorie') === ['Traiteur', 'Tartes', 'Viennoiserie', 'Boulangerie'], json_encode(array_column($S['categories'], 'categorie')));
verifie('les mois lus se gravent, pas le mois muet', count($ECRITS) === 2 && !in_array('vkCat:' . $MOIS[1], array_column($ECRITS, 0), true));
$REGLAGES['vkCat:' . $MOIS[0]] = [2 => ['Traiteur' => 1.0]];
$APPELS = [];
vkStructure(2, $MOIS);
verifie('un mois gravé ne se redemande pas au panel', !in_array($MOIS[0], $APPELS, true) && in_array($MOIS[1], $APPELS, true));
unset($REGLAGES['vkCat:' . $MOIS[0]]);

// --- 4. la route --------------------------------------------------------------------------
echo "La route\n";
$r = ep_visites_kpi('77');
verifie('un magasin inconnu : 404', ($r['error'] ?? '') === 'magasin inconnu');
$r = ep_visites_kpi('2');
verifie('les deux blocs', is_array($r['caEtp']) && is_array($r['structure']) && !isset($r['annee']) && $r['shop'] === '2');
verifie('un mois muet se dit dans manques (sans cache : la lecture suivante le relit)', $r['manques'] === ['structure'] && $TTL === null, json_encode([$r['manques'], $TTL]));

// --- 5. les tâches mois par mois ----------------------------------------------------------
echo "Les tâches mois par mois\n";
$ce = date('Y-m'); $prec = date('Y-m', strtotime('first day of last month'));
$LIGNES = ['FROM ceo_tache_jour' => [['m' => $prec, 't' => 40, 'f' => 30, 'j' => 20]], 'FROM mac_task_review' => [['m' => $prec, 'notees' => 12, 'sous' => 3, 'moy' => '3.75']]];
$_GET = [];
$T = ep_visites_taches('2');
verifie('douze mois, du plus récent au plus ancien', count($T['mois']) === 12 && $T['mois'][0]['mois'] === $ce && $T['mois'][0]['enCours'] === true);
$mp = $T['mois'][1];
verifie('le mois passé : 30 faites sur 40 demandées, 75 %', $mp['faites'] === 30 && $mp['demandees'] === 40 && $mp['pct'] === 75, json_encode($mp));
verifie('ses notes : 12 notées, 3 sous le seuil, moyenne 3,75', $mp['notees'] === 12 && $mp['sous'] === 3 && $mp['moyenne'] === 3.75);
verifie('un mois sans relevé n’a pas de pourcentage', $T['mois'][5]['pct'] === null && $T['mois'][5]['demandees'] === 0);
verifie('le mois en cours vide : le mois passé est choisi d’office', $T['choisi'] === $prec, $T['choisi']);
verifie('ses tâches sous 4 sont lues sur le mois entier', $GET_NC['du'] === $prec . '-01' && $GET_NC['au'] === date('Y-m-t', strtotime($prec . '-01')) && $GET_NC['shop'] === '2');
verifie('chaque tâche porte son identifiant (pour sa photo), la note, le commentaire et le contrôleur', $T['taches'][0]['taskId'] === '101' && $T['taches'][0]['note'] === 2 && $T['taches'][0]['commentaire'] === 'Vitrine à moitié vide' && $T['taches'][0]['controleur'] === 'Pierre B.');
$_GET = ['mois' => $ce, 'n' => '6'];
$T = ep_visites_taches('2');
verifie('le mois demandé, arrêté à aujourd’hui ; six mois demandés', $T['choisi'] === $ce && $GET_NC['au'] === date('Y-m-d') && count($T['mois']) === 6);
$_GET = ['mois' => '2019-01'];
verifie('un mois hors de la liste revient au choix d’office', ep_visites_taches('2')['choisi'] === $prec);
$_GET = [];
verifie('un magasin inconnu : 404', (ep_visites_taches('77')['error'] ?? '') === 'magasin inconnu');

// --- 4bis. la structure du CA par canal -----------------------------------------------------------
echo "Structure du CA par canal\n";
$C = vkCanaux(2, $MOIS);
$N = $C['jours'];
verifie('les trois mois clos, tous les jours lus', $C['du'] === $MOIS[0] . '-01' && $C['joursLus'] === $N && $N >= 89 && $C['webshop'] === true);
$tot = 1000.0 * $N + 600.0;
verifie('le total : la caisse, plus le webshop qu’elle n’a pas encaissé', $C['magasin']['total'] === round($tot), json_encode($C['magasin']));
verifie('le comptoir : la caisse moins le pro et les commandes encaissées en caisse', $C['magasin']['ca']['comptoir'] === round(550.0 * $N - 1500.0) && $C['magasin']['ca']['cc'] === 1500.0 && $C['magasin']['ca']['liv'] === 600.0);
verifie('une précommande comptoir et une commande d’aujourd’hui ne comptent pas au webshop', $C['magasin']['ca']['cc'] === 1500.0);
verifie('la part B2B : 45 %, au-dessus du plafond de 40 %', $C['magasin']['parts']['b2b'] === round(450.0 * $N / $tot * 100, 1) && $C['b2bStatut'] === 'au-dessus' && $C['b2bMax'] === 40.0, json_encode([$C['magasin']['parts'], $C['b2bStatut']]));
verifie('le reste du réseau : Gosselies seul (Halle, webshop muet, en sort)', $C['reste']['magasins'] === 1 && $C['reste']['parts']['b2b'] === 10.0 && $C['reste']['parts']['comptoir'] === 90.0, json_encode($C['reste']));
$C4 = vkCanaux(4, $MOIS);
verifie('les clients pro sans TVA valide restent au comptoir, chiffrés à part', $C['reste']['proSansTva'] === round(50.0 * $N) && $C['reste']['b2bAvecSansTva'] === 15.0 && $C['reste']['parts']['comptoir'] === 90.0, json_encode($C['reste']));
verifie('Halle, webshop muet : pas de structure plutôt qu’une structure fausse', $C4['magasin'] === null && $C4['webshop'] === false && str_contains((string) $C4['motif'], 'webshop'));
$p = vkCanauxParts(['caisse' => 1000.0, 'b2b' => 380.0, 'cc' => 0.0, 'liv' => 0.0, 'enc' => 0.0]);
verifie('les parts d’un cumul : 62 % comptoir, 38 % B2B', $p['parts']['comptoir'] === 62.0 && $p['parts']['b2b'] === 38.0);
verifie('un cumul sans CA : null', vkCanauxParts(['caisse' => 0.0, 'b2b' => 0.0, 'cc' => 0.0, 'liv' => 0.0, 'enc' => 0.0]) === null);

// --- 5. le CA face au budget ------------------------------------------------------------------
echo "CA face au budget\n";
$e = vbEtendue(['2' => ['realise' => 8800.0, 'attendu' => 10000.0, 'objectif' => 30000.0, 'source' => 'budget'],
    '3' => ['realise' => 9500.0, 'attendu' => 10000.0], '4' => ['realise' => 11000.0, 'attendu' => 10000.0], '5' => null, '6' => ['realise' => 500.0, 'attendu' => null]], '2', 10.0);
verifie('réalisé ÷ attendu − 1 : −12 %, il manque 1 200 €', $e['ecartPct'] === -12.0 && $e['manque'] === 1200.0 && $e['atteintePct'] === 29.3, json_encode($e));
verifie('plus de 10 % sous l’attendu : à justifier', $e['statut'] === 'sous');
verifie('le rang parmi les magasins qui ont un écart : 3e sur 3', $e['rang'] === 3 && $e['sur'] === 3, json_encode([$e['rang'], $e['sur']]));
verifie('le réseau : somme des réalisés ÷ somme des attendus', $e['reseau'] === -2.3, (string) $e['reseau']);
$e10 = vbEtendue(['2' => ['realise' => 9000.0, 'attendu' => 10000.0]], '2', 10.0);
verifie('à −10 % pile : sous l’attendu, pas encore à justifier (« plus de 10 % »)', $e10['statut'] === 'proche' && $e10['ecartPct'] === -10.0);
verifie('au-dessus de l’attendu : ok', vbEtendue(['2' => ['realise' => 10000.0, 'attendu' => 10000.0]], '2', 10.0)['statut'] === 'ok');
$e0 = vbEtendue(['2' => ['realise' => 9000.0, 'attendu' => null]], '2', 10.0);
verifie('sans attendu : pas d’écart, pas de rang', $e0['statut'] === 'sans' && $e0['ecartPct'] === null && $e0['rang'] === null && $e0['realise'] === 9000.0);

$bud = [7 => ['v' => 30000.0, 'src' => 'budget'], 8 => ['v' => 30000.0, 'src' => 'theorique'], 9 => ['v' => 40000.0, 'src' => 'budget'],
    10 => ['v' => 40000.0, 'src' => 'budget'], 11 => ['v' => 40000.0, 'src' => 'budget'], 12 => ['v' => 50000.0, 'src' => 'budget']];
$q = vbCumulMois([10, 11, 12], 10, [], $bud, ['realise' => 20000.0, 'attendu' => 25000.0, 'objectif' => 40000.0]);
verifie('trimestre au 1er mois : le mois en cours seul ; l’objectif compte les trois mois', $q['realise'] === 20000.0 && $q['attendu'] === 25000.0 && $q['objectif'] === 130000.0, json_encode($q));
$q3 = vbCumulMois([7, 8, 9], 9, [7 => 33000.0, 8 => 27000.0], $bud, ['realise' => 10000.0, 'attendu' => 20000.0, 'objectif' => 40000.0]);
verifie('les mois clos entrent en entier face à leur budget', $q3['realise'] === 70000.0 && $q3['attendu'] === 80000.0 && $q3['objectif'] === 100000.0, json_encode($q3));
verifie('budget validé et CA théorique mêlés : source mixte', $q3['source'] === 'mixte');
$qs = vbCumulMois([7, 8, 9], 9, [7 => 33000.0, 8 => 27000.0], [8 => $bud[8], 9 => $bud[9]], ['realise' => 10000.0, 'attendu' => 20000.0, 'objectif' => 40000.0]);
verifie('un mois clos sans budget sort des deux côtés', $qs['realise'] === 37000.0 && $qs['attendu'] === 50000.0 && $qs['sansBudget'] === [7], json_encode($qs));
$qz = vbCumulMois([7, 8, 9], 9, [7 => 0.0, 8 => 27000.0], $bud, ['realise' => 10000.0, 'attendu' => 20000.0, 'objectif' => 40000.0]);
verifie('un mois clos sans aucune vente (pas encore ouvert) aussi', $qz['realise'] === 37000.0 && $qz['attendu'] === 50000.0 && $qz['sansBudget'] === [7], json_encode($qz));
$qi = vbCumulMois([7, 8, 9], 9, [7 => 33000.0], $bud, ['realise' => 10000.0, 'attendu' => 20000.0, 'objectif' => 40000.0]);
verifie('un mois clos non lu : pas de chiffre plutôt qu’un chiffre faux', $qi['realise'] === null && $qi['attendu'] === null && $qi['incomplet'] === true);
verifie('les libellés', vbLab('trimestre', '2026-10-25', 10) === '4e trimestre 2026' && vbLab('trimestre', '2026-02-03', 1) === '1er trimestre 2026'
    && vbLab('mois', '2026-10-25', 10) === 'octobre 2026' && vbLab('semaine', '2026-10-25', 10) === 'semaine du 19/10');

// Le calcul complet, arrêté à la veille : Résultat pour la semaine et le mois, les mois clos au relevé des ventes.
$hier = date('Y-m-d', strtotime('-1 day')); $mRef = (int) substr($hier, 5, 2); $jRef = (int) substr($hier, 8, 2);
$LIGNES = ['FROM ceo_shop_month_perf' => array_merge(...array_map(static fn (string $s) => array_map(static fn (int $m) => ['shop_id' => $s, 'month' => $m, 'revenue_budget' => 100000, 'ca_theorique' => null], range(1, 12)), ['2', '3', '4']))];
$GET_RES = []; $TTL = null;
$B = ep_visites_budget('2');
verifie('cinq étendues, arrêtées à la veille', array_keys($B['periodes']) === ['jour', 'semaine', 'mois', 'trimestre', 'annee'] && $B['arrete'] === $hier);
verifie('le jour : Résultat du jour de la veille, 900 € pour 1 000 €', $B['periodes']['jour']['ecartPct'] === -10.0 && $B['periodes']['jour']['du'] === $hier && $B['periodes']['jour']['sur'] === 2, json_encode($B['periodes']['jour']));
verifie('le P&L du jour, tel que Résultat le calcule', $B['periodes']['jour']['pnl']['ca'] === 900.0 && $B['periodes']['jour']['pnl']['net'] === 230.0 && $B['periodes']['jour']['pnl']['invendus'] === 20.0 && $B['periodes']['jour']['pnl']['avecInvendus'] === true, json_encode($B['periodes']['jour']['pnl']));
verifie('le P&L du mois : la ligne de Résultat', $B['periodes']['mois']['pnl']['coutMatierePct'] === 30.0 && $B['periodes']['mois']['pnl']['netPct'] === 35.0, json_encode($B['periodes']['mois']['pnl']));
$caAn = 120000.0 * ($mRef - 1) + 900.0 * min($jRef + 1, (int) date('t'));
verifie('le P&L de l’année : les mois clos du P&L mensuel et le mois en cours, sans les invendus', $mRef === 1 || ($B['periodes']['annee']['pnl']['avecInvendus'] === false && $B['periodes']['annee']['pnl']['netPct'] === 35.0 && $B['periodes']['annee']['pnl']['mois'] === $mRef), json_encode($B['periodes']['annee']['pnl']));
verifie('les seuils du P&L viennent de Résultat', ($B['seuilsPnl']['food'] ?? null) === 32.0);
$qm = vbPnlCumul([], [10, 11, 12], 10, ['realise' => 1000.0, 'coutMatiere' => 400.0, 'labour' => 200.0, 'overhead' => 0.0, 'motifNet' => 'frais généraux absents du panel pour ce magasin — résultat avant frais généraux'], '2026-10-01', '2026-10-26');
verifie('le cumul répète ce que Résultat dit du mois en cours (frais généraux absents)', str_contains((string) $qm['motif'], 'le mois en cours : frais généraux absents') && $qm['net'] === 400.0, json_encode($qm));
verifie('Résultat est lu pour la semaine et le mois', array_column($GET_RES, 'vue') === ['semaine', 'mois'], json_encode($GET_RES));
verifie('la semaine : −10 %, 2e sur 3 (Halle devant, Gosselies derrière)', $B['periodes']['semaine']['ecartPct'] === -10.0 && $B['periodes']['semaine']['rang'] === 2 && $B['periodes']['semaine']['sur'] === 3, json_encode($B['periodes']['semaine']));
verifie('le mois : la même lecture coupée à la veille', $B['periodes']['mois']['realise'] === (float) round(900 * $jRef) && $B['periodes']['mois']['attendu'] === (float) round(1000 * $jRef), json_encode($B['periodes']['mois']));
$attAn = 100000.0 * ($mRef - 1) + 1000.0 * $jRef; $realAn = 120000.0 * ($mRef - 1) + 900.0 * $jRef;
verifie('l’année : les mois clos (120 k€ pour 100 k€ de budget) et le mois à la veille', $B['periodes']['annee']['realise'] === round($realAn) && $B['periodes']['annee']['attendu'] === round($attAn)
    && $B['periodes']['annee']['ecartPct'] === round(($realAn / $attAn - 1) * 100, 1), json_encode($B['periodes']['annee']));
verifie('l’année : seul Corbais a des mois clos lus, il est seul classé', $mRef === 1 || $B['periodes']['annee']['sur'] === 1);
verifie('aucune étendue à plus de 10 % sous l’attendu', $B['sous'] === [] && $B['seuil'] === 10.0);
$B3 = ep_visites_budget('3');
verifie('Gosselies, −20 % sur la semaine et le mois : à justifier', in_array('semaine', $B3['sous'], true) && in_array('mois', $B3['sous'], true) && $B3['periodes']['semaine']['statut'] === 'sous');
verifie('une journée à −30 % ne demande pas de justification', $B3['periodes']['jour']['statut'] === 'sous' && !in_array('jour', $B3['sous'], true));
verifie('un mois clos sans coût matière : le coût matière de l’année reste inconnu, et le dit', $mRef <= 5 || ($B3['periodes']['annee']['pnl']['coutMatiere'] === null && $B3['periodes']['annee']['pnl']['net'] === null && str_contains((string) $B3['periodes']['annee']['pnl']['motif'], 'coût matière')), json_encode($B3['periodes']['annee']['pnl']));
verifie('Gosselies : 47 k€ par mois clos pour 100 k€ de budget, l’année aussi à justifier', $mRef === 1 || in_array('annee', $B3['sous'], true), json_encode($B3['periodes']['annee']));
$B4 = ep_visites_budget('4');
verifie('Halle : ses mois clos non lus laissent l’année sans chiffre, et le disent ; rien n’est gardé',
    $mRef <= 2 || ($B4['periodes']['annee']['statut'] === 'sans' && in_array('annee', $B4['manques'], true) && $TTL === null && str_contains($B4['periodes']['annee']['motif'], 'pas été lu')),
    json_encode([$B4['periodes']['annee']['statut'], $B4['manques'], $TTL, $B4['periodes']['annee']['motif'] ?? null]));
verifie('Halle fermé la veille : pas d’écart du jour, et il le dit', $B4['periodes']['jour']['statut'] === 'sans' && str_contains((string) $B4['periodes']['jour']['motif'], 'fermé') && $B4['periodes']['jour']['pnl'] === null);
verifie('un magasin inconnu : 404', (ep_visites_budget('77')['error'] ?? '') === 'magasin inconnu');

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
