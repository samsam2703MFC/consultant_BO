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
verifie('un mois muet : relu dans cinq minutes, pas dans une heure', $r['manques'] === ['structure'] && $TTL === 300, json_encode([$r['manques'], $TTL]));

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
verifie('chaque tâche porte la note, le commentaire et le contrôleur', $T['taches'][0]['note'] === 2 && $T['taches'][0]['commentaire'] === 'Vitrine à moitié vide' && $T['taches'][0]['controleur'] === 'Pierre B.');
$_GET = ['mois' => $ce, 'n' => '6'];
$T = ep_visites_taches('2');
verifie('le mois demandé, arrêté à aujourd’hui ; six mois demandés', $T['choisi'] === $ce && $GET_NC['au'] === date('Y-m-d') && count($T['mois']) === 6);
$_GET = ['mois' => '2019-01'];
verifie('un mois hors de la liste revient au choix d’office', ep_visites_taches('2')['choisi'] === $prec);
$_GET = [];
verifie('un magasin inconnu : 404', (ep_visites_taches('77')['error'] ?? '') === 'magasin inconnu');

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
