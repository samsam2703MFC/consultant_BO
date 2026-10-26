#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de « KPI et santé du magasin » (tablette du consultant, GET /visites/kpi/{shop}) — sans base, sans panel.
 *
 *   php bin/tests_visites_kpi.php
 *
 * Ce qui doit rester vrai : le CA par ETP ne devine jamais un ETP et situe le magasin dans le réseau ; l'année face à
 * l'an passé ne cumule que les mois comparables (et les pièces lues des deux côtés) ; la structure des ventes compare
 * le magasin au RESTE du réseau, sans les entrées techniques, et ne regrave pas un mois déjà lu.
 *
 * Le module lit le panel, la base et les autres modules par des fonctions : des doublures les remplacent ici.
 */

// La route pose un code HTTP : la sortie reste en mémoire jusqu'à la fin, sinon PHP dit les en-têtes déjà partis.
ob_start();

// --- doublures -----------------------------------------------------------------------
$ECRITS = [];
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
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
// L'année : janvier et février clos, mars en cours, avril à venir. Corbais a son N-1 ; Halle n'en a pas.
$GET_ANNEE = null;
function ep_ventes_reseau(): array
{
    global $GET_ANNEE; $GET_ANNEE = $_GET;
    $per = static fn (string $lab, bool $enCours, bool $futur): array => ['lab' => $lab, 'enCours' => $enCours, 'futur' => $futur];
    $l = static fn (int $t, float $ca, int $j, ?float $art, ?array $n1): array => ['ca' => $ca, 'tickets' => $t, 'jours' => $j, 'source' => 'panel', 'n1' => $n1, 'articles' => $art];
    $n1 = static fn (int $t, float $ca, int $j, ?float $art): array => ['ca' => $ca, 'tickets' => $t, 'jours' => $j, 'source' => 'panel', 'articles' => $art];
    $fut = ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'source' => null, 'n1' => null];
    return ['periodes' => [$per('janv.', false, false), $per('févr.', false, false), $per('mars', true, false), $per('avr.', false, true)],
        'magasins' => [
            ['id' => '2', 'periodes' => [$l(1100, 13200.0, 31, 2420.0, $n1(1000, 11000.0, 31, 2000.0)),
                $l(900, 11700.0, 28, 2160.0, $n1(1000, 12000.0, 28, null)),
                $l(500, 6500.0, 15, 1100.0, $n1(400, 5000.0, 15, 840.0)), $fut], 'n1Motif' => null],
            ['id' => '3', 'periodes' => [$l(600, 6000.0, 31, null, $n1(600, 6600.0, 31, null)), $l(600, 6000.0, 28, null, $n1(500, 5000.0, 28, null)),
                $l(300, 3000.0, 15, null, null), $fut], 'n1Motif' => null],
            ['id' => '4', 'periodes' => [$l(700, 7000.0, 31, 1400.0, null), $l(700, 7000.0, 28, 1400.0, null), $l(300, 3000.0, 15, 600.0, null), $fut],
                'n1Motif' => 'pas de N-1 : la caisse a sonné pour la première fois le 01/02/2025'],
        ], 'source' => 'panel (margin-heatmap, par jour)'];
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

// --- 3. l'année face à l'an passé -----------------------------------------------------------
echo "L’année face à l’an passé\n";
$A = vkAnnee(2, $MOIS);
$C = $A['cumul'];
verifie('Résultat › Année est lu en vue année, avec les pièces', $GET_ANNEE['vue'] === 'annee' && $GET_ANNEE['articles'] === '1');
verifie('les mois à venir ne sont pas dans la série', count($A['mois']) === 3 && $A['mois'][2]['enCours'] === true);
verifie('les clients de l’année face aux mêmes mois de l’an passé', $C['clients']['n'] === 2500 && $C['clients']['n1'] === 2400 && $C['clients']['ecart'] === 4.2, json_encode($C['clients']));
verifie('le panier : CA total ÷ clients totaux', $C['panier']['n'] === 12.56 && $C['panier']['n1'] === 11.67 && $C['panier']['ecart'] === 7.7, json_encode($C['panier']));
verifie('les articles par ticket ne comptent que les mois lus des deux côtés (février sorti)', $C['articles']['n'] === 2.2 && $C['articles']['n1'] === 2.03 && $C['articles']['ecart'] === 8.5, json_encode($C['articles']));
verifie('les jours ouverts et les mois comparables', $C['jours']['n'] === 74 && $C['jours']['n1'] === 74 && $C['mois'] === 3);
verifie('le mois par mois : clients, panier, articles et leur N-1', $A['mois'][1]['clients'] === 900 && $A['mois'][1]['panierN1'] === 12.0 && $A['mois'][1]['articles'] === 2.4 && $A['mois'][1]['articlesN1'] === null, json_encode($A['mois'][1]));
$R = $A['reseau'];
verifie('le réseau à périmètre constant : Gosselies sans mars N-1, Halle sans N-1', $R['magasins'] === 2 && $R['clients']['n'] === 3700 && $R['clients']['n1'] === 3500, json_encode($R['clients']));
$A4 = vkAnnee(4, $MOIS);
verifie('Halle n’a pas de N-1 : pas de cumul, le motif dit pourquoi', $A4['cumul'] === null && str_contains((string) $A4['n1Motif'], 'première fois'));
verifie('Halle garde sa série de l’année', $A4['mois'][0]['clients'] === 700 && $A4['mois'][0]['clientsN1'] === null);
$A9 = vkAnnee(9, $MOIS);
verifie('un magasin absent de l’année le dit', $A9['cumul'] === null && $A9['n1Motif'] === 'aucune vente lue cette année');

// --- 4. la structure des ventes face au reste du réseau ---------------------------------------
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

// --- 5. la route --------------------------------------------------------------------------
echo "La route\n";
$r = ep_visites_kpi('77');
verifie('un magasin inconnu : 404', ($r['error'] ?? '') === 'magasin inconnu');
$r = ep_visites_kpi('2');
verifie('les trois blocs', is_array($r['caEtp']) && is_array($r['annee']) && is_array($r['structure']) && $r['shop'] === '2');
verifie('un mois muet : relu dans cinq minutes, pas dans une heure', $r['manques'] === ['structure'] && $TTL === 300, json_encode([$r['manques'], $TTL]));

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
