#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de GET /exploitation/canaux-mois, et du jour de GET /exploitation/canaux (le même jour la semaine d'avant,
 * les commandes des clients pro) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_canaux.php
 *
 * Le panel est une doublure : les commandes du webshop, la caisse heure par heure
 * gravée, la caisse du mois gardée, les tickets pro gravés. On vérifie ce que la
 * carte « Commandes et canaux » du mois en tire : chaque canal mois par mois, le
 * mois choisi et celui d'avant jour par jour, ce qui est à venir laissé de côté.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void {}
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : (is_array($r) && isset($r['data']) ? $r['data'] : []); }
final class PanelApi
{
    public static array $commandes = [];
    public static int $appels = 0;
    public static function configured(): bool { return true; }
    public static function sondeGet(string $p, int $t = 10): array { self::$appels++; return ['code' => 200, 'corps' => self::$commandes]; }
    /** Les tickets de chaque jour (`/shops/4/transactions?date=…` => liste) ; un chemin absent : le panel se tait. */
    public static array $tickets = [];
    public static array $lus = [];
    public static function get(string $p): mixed { self::$appels++; return null; }
    public static function getParallele(array $chemins, int $n = 6): array
    {
        self::$appels += count($chemins);
        $out = [];
        foreach ($chemins as $k => $c) { self::$lus[] = $c; $out[$k] = self::$tickets[$c] ?? null; }
        return $out;
    }
}

require __DIR__ . '/../../panel/src/stats_ventes.php';
require __DIR__ . '/../../shared/src/panel_ventes.php';
require __DIR__ . '/../../consultant/src/ventes_pro.php';
require __DIR__ . '/../src/canaux_offres.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- le décor : quatre mois, le mois en cours compris -----------------------------------------
$auj = date('Y-m-d');
$M = []; for ($i = 3; $i >= 0; $i--) { $M[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month')); }
[$m0, $m1, $prec, $cour] = $M;
$sid = 4;
$R = [];
// La caisse heure par heure, gravée (quand = 0 : valable), du mois d'avant et du mois en cours : 1 000 € et 80 tickets par jour.
foreach ([$prec, $cour] as $ym) {
    $fin = date('Y-m-t', strtotime($ym . '-01'));
    for ($d = $ym . '-01'; $d <= $fin && $d <= $auj; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $R['svH' . $sid . ':' . $d] = ['quand' => 0, 'h' => ['10' => ['h' => 10, 'ca' => 600.0, 'tickets' => 50, 'mat' => 0.0], '15' => ['h' => 15, 'ca' => 400.0, 'tickets' => 30, 'mat' => 0.0]]];
        // Les tickets pro gravés : 2 tickets, 60 € et 40 €, d'un compte à TVA valide.
        $R['svP' . $sid . ':' . $d] = ['quand' => 0, 'b' => ['q' => 0, 'n' => 80, 'ca' => 1000.0, 't' => [['10:00', 'c12', 60.0, 1, 0], ['11:00', 'c12', 40.0, 0, 0]], 'h' => []]];
    }
}
$R['vpTva:' . $sid] = ['c12' => [1, time()]];
// La caisse des mois plus anciens, gardée une fois le mois clos.
$R['pvCa' . $sid . ':' . $m0] = ['quand' => time(), 'ca' => 20000.0, 'tickets' => 1800, 'panier' => 11.1];
$R['pvCa' . $sid . ':' . $m1] = ['quand' => time(), 'ca' => 25000.0, 'tickets' => 2100, 'panier' => 11.9];
$GLOBALS['reglages'] = $R;
// Le webshop : par mois, un click & collect payé en ligne, un encaissé en caisse, une livraison ;
// une précommande au comptoir (pas du webshop) ; une commande à venir dans le mois en cours.
$C = [];
foreach ($M as $ym) {
    $C[] = ['pick_up_datetime' => $ym . '-01 11:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'pickup', 'total_value' => 30.0, 'order_status' => 'delivered'];
    $C[] = ['pick_up_datetime' => $ym . '-01 12:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'pickup', 'total_value' => 20.0, 'order_status' => 'delivered', 'id_transaction' => 99];
    $C[] = ['pick_up_datetime' => $ym . '-01 13:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'delivery', 'total_value' => 50.0, 'order_status' => 'delivered'];
    $C[] = ['pick_up_datetime' => $ym . '-01 14:00:00', 'is_webshop' => 0, 'fulfilment_mode' => 'pickup', 'total_value' => 70.0, 'order_status' => 'delivered'];
}
$demain = date('Y-m-d', strtotime($auj . ' +1 day'));
if (substr($demain, 0, 7) === $cour) { $C[] = ['pick_up_datetime' => $demain . ' 10:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'pickup', 'total_value' => 500.0, 'order_status' => 'accepted']; }
PanelApi::$commandes = $C;

echo "Canaux mois par mois\n";
$_GET = ['shop' => (string) $sid, 'mois' => $cour, 'n' => '4'];
$r = ep_exploitation_canaux_mois();
$H = array_column($r['historique'], null, 'm');
verifie('quatre mois, du plus ancien au mois choisi', array_column($r['historique'], 'm') === $M, json_encode(array_column($r['historique'], 'm')));
verifie('le mois d’avant nommé', $r['precedent'] === $prec);
$nJ = (int) substr($auj, 8, 2);
verifie('mois en cours : la caisse des jours lus, moins le webshop encaissé', abs($H[$cour]['comptoir'] - (1000 * $nJ - 20)) < 0.01, json_encode($H[$cour]));
verifie('mois en cours : click & collect sans la commande à venir', $H[$cour]['cc'] == 50.0 && $H[$cour]['ccN'] === 2, json_encode($H[$cour]));
verifie('mois en cours : livraison', $H[$cour]['liv'] == 50.0 && $H[$cour]['livN'] === 1);
verifie('mois en cours : pro = 100 € par jour lu', abs($H[$cour]['pro'] - 100 * $nJ) < 0.01 && $H[$cour]['ticketsPro'] === 2 * $nJ, json_encode($H[$cour]));
verifie('mois en cours : en cours, jours écoulés = jours lus', $H[$cour]['enCours'] && $H[$cour]['joursEcoules'] === $nJ && $H[$cour]['joursLus'] === $nJ);
$nP = (int) date('t', strtotime($prec . '-01'));
verifie('mois d’avant : tout le mois, jour par jour', abs($H[$prec]['comptoir'] - (1000 * $nP - 20)) < 0.01 && $H[$prec]['joursLus'] === $nP && !$H[$prec]['enCours']);
verifie('mois ancien : la caisse du mois moins l’encaissé', abs($H[$m1]['comptoir'] - (25000 - 20)) < 0.01 && $H[$m1]['joursLus'] === (int) date('t', strtotime($m1 . '-01')), json_encode($H[$m1]));
verifie('mois ancien : le webshop compté', $H[$m0]['cc'] == 50.0 && $H[$m0]['liv'] == 50.0);
verifie('mois ancien sans tickets pro gravés : pro inconnu, pas zéro', $H[$m0]['pro'] === null && $H[$m0]['proJours'] === 0);
verifie('le total = comptoir + webshop', abs($H[$m1]['total'] - ($H[$m1]['comptoir'] + 100)) < 0.01);
$J = $r['jours'];
verifie('jour par jour : le mois en cours jusqu’à aujourd’hui', count($J['courant']) === $nJ && end($J['courant'])['j'] === $auj);
verifie('jour par jour : le mois d’avant entier', count($J['precedent']) === $nP);
$j1 = $J['courant'][0];
verifie('le 1er : comptoir, pro et webshop du jour', abs($j1['comptoir'] - 980) < 0.01 && $j1['pro'] == 100.0 && $j1['cc'] == 50.0 && $j1['liv'] == 50.0 && $j1['ccN'] === 2, json_encode($j1));
verifie('rien n’est relu au panel quand tout est gravé (une lecture des commandes)', PanelApi::$appels === 1, (string) PanelApi::$appels);

echo "Le jour et le même jour la semaine d’avant (18/11/2026)\n";
// Aujourd'hui : une précommande encaissée, un click & collect payé en ligne, une précommande du client des tickets pro
// (c12, TVA valide). J−7 : un click & collect encaissé à 09:00, une précommande à 18:00.
$j7 = date('Y-m-d', strtotime($auj . ' -7 day'));
PanelApi::$commandes = [
    ['pick_up_datetime' => $auj . ' 10:00:00', 'is_webshop' => 0, 'total_value' => 9.80, 'order_status' => 'picked_up', 'id_transaction' => 41, 'id_client' => 5],
    ['pick_up_datetime' => $auj . ' 11:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 5.80, 'order_status' => 'picked_up', 'id_client' => 6],
    ['pick_up_datetime' => $auj . ' 12:00:00', 'is_webshop' => 0, 'total_value' => 60.0, 'order_status' => 'picked_up', 'id_transaction' => 42, 'id_client' => 12],
    ['pick_up_datetime' => $j7 . ' 09:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 16.50, 'order_status' => 'picked_up', 'id_transaction' => 43, 'id_client' => 7],
    ['pick_up_datetime' => $j7 . ' 18:00:00', 'is_webshop' => 0, 'total_value' => 115.50, 'order_status' => 'picked_up', 'id_transaction' => 44, 'id_client' => 8],
];
$_GET = ['shop' => (string) $sid, 'date' => $auj];
$r = ep_exploitation_canaux();
$L = array_column($r['liste'], null, 'heure');
verifie('la liste du jour : trois commandes', count($r['liste']) === 3, json_encode($r['liste']));
verifie('la précommande du client des tickets pro est du B2B', $L['12:00']['pro'] === true && $L['10:00']['pro'] === false && $L['11:00']['pro'] === false, json_encode($L));
verifie('J−7 nommé, ses commandes avec leur heure', ($r['j7']['date'] ?? '') === $j7 && array_column($r['j7']['liste'], 'heure') === ['09:00', '18:00'], json_encode($r['j7'] ?? null));
$K = array_column($r['j7']['liste'], null, 'heure');
verifie('J−7 : canal, montant, encaissée', $K['09:00']['canal'] === 'cc' && $K['09:00']['montant'] == 16.5 && $K['09:00']['encaissee'] === true && $K['18:00']['canal'] === 'compt', json_encode($K));
verifie('J−7 : rien de nominatif', !array_key_exists('cli', $K['09:00']) && !array_key_exists('tx', $K['09:00']));

echo "Les commandes payées un autre jour que leur retrait (21/11/2026, Halle, samedi 07:59 : « Comptoir 0 € »)\n";
// Aujourd'hui, la caisse gravée fait 1 000 €. Une précommande de 07:00 et un click & collect de 15:30 payés hier, un
// click & collect de 09:30 payé il y a trois jours, un click & collect de 10:00 payé aujourd'hui à 10:05, une commande de
// demain payée ce matin à 08:00, une précommande du client des tickets pro payée aujourd'hui. Les tickets d'il y a
// cinq jours ne se lisent pas : ce jour-là garde l'ancienne règle.
$hier = date('Y-m-d', strtotime($auj . ' -1 day')); $j3 = date('Y-m-d', strtotime($auj . ' -3 day')); $j5 = date('Y-m-d', strtotime($auj . ' -5 day'));
PanelApi::$commandes = [
    ['pick_up_datetime' => $auj . ' 07:00:00', 'is_webshop' => 0, 'total_value' => 8.30, 'order_status' => 'new', 'id_transaction' => 51, 'id_client' => 5],
    ['pick_up_datetime' => $auj . ' 09:30:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 13.30, 'order_status' => 'new', 'id_transaction' => 52, 'id_client' => 6],
    ['pick_up_datetime' => $auj . ' 10:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 20.0, 'order_status' => 'new', 'id_transaction' => 54, 'id_client' => 7],
    ['pick_up_datetime' => $auj . ' 15:30:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 157.30, 'order_status' => 'new', 'id_transaction' => 53, 'id_client' => 8],
    ['pick_up_datetime' => $auj . ' 16:00:00', 'is_webshop' => 0, 'total_value' => 60.0, 'order_status' => 'new', 'id_transaction' => 56, 'id_client' => 12],
    ['pick_up_datetime' => $demain . ' 10:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 30.0, 'order_status' => 'new', 'id_transaction' => 55, 'id_client' => 9],
    ['pick_up_datetime' => $j5 . ' 11:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 40.0, 'order_status' => 'picked_up', 'id_transaction' => 57, 'id_client' => 10],
    ['pick_up_datetime' => $j7 . ' 09:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 16.50, 'order_status' => 'picked_up', 'id_transaction' => 43, 'id_client' => 7],
];
$tk = static fn (string $j, array $L): array => array_map(static fn ($x) => ['id' => $x[0], 'insert_timestamp' => $j . ' ' . $x[1] . ':00'], $L);
PanelApi::$tickets = [];
for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime($auj . ' -' . $i . ' day')); PanelApi::$tickets['/shops/4/transactions?date=' . $d] = $tk($d, [[900 + $i, '12:00']]); }
PanelApi::$tickets['/shops/4/transactions?date=' . $auj] = $tk($auj, [[54, '10:05'], [55, '08:00'], [56, '07:30'], [999, '07:10']]);
PanelApi::$tickets['/shops/4/transactions?date=' . $hier] = $tk($hier, [[51, '16:00'], [53, '17:00'], [998, '09:00']]);
PanelApi::$tickets['/shops/4/transactions?date=' . $j3] = $tk($j3, [[52, '11:00']]);
PanelApi::$tickets['/shops/4/transactions?date=' . $j7] = $tk($j7, [[43, '09:02']]);
unset(PanelApi::$tickets['/shops/4/transactions?date=' . $j5]);
PanelApi::$lus = [];
$_GET = ['shop' => (string) $sid, 'date' => $auj];
$r = ep_exploitation_canaux();
verifie('les tickets des quatorze jours lus, une liste par jour', count(PanelApi::$lus) === 14 && count(array_unique(PanelApi::$lus)) === 14, json_encode(PanelApi::$lus));
$X = $r['caisseCmd'];
verifie('la caisse du jour : les commandes qu’elle paie, à l’heure du ticket', is_array($X) && array_column($X, 'heure') === ['07:30', '08:00', '10:05'], json_encode($X));
$XH = array_column($X, null, 'heure');
verifie('… celle de demain payée ce matin comprise, avec son jour de retrait', $XH['08:00']['montant'] == 30.0 && $XH['08:00']['retrait'] === $demain && $XH['08:00']['canal'] === 'cc', json_encode($XH['08:00']));
verifie('… le client des tickets pro marqué pro', $XH['07:30']['pro'] === true && $XH['10:05']['pro'] === false && $XH['08:00']['pro'] === false);
verifie('… payées la veille ou avant : pas dans la caisse du jour', !in_array(8.3, array_column($X, 'montant')) && !in_array(157.3, array_column($X, 'montant')) && !in_array(13.3, array_column($X, 'montant')));
verifie('… rien de nominatif', !array_key_exists('cli', $X[0]) && !array_key_exists('tx', $X[0]));
verifie('J−7 : sa caisse et ses commandes payées', array_column($r['j7']['caisseCmd'], 'heure') === ['09:02'] && $r['j7']['caisseCmd'][0]['montant'] == 16.5, json_encode($r['j7']['caisseCmd']));
// Le comptoir du jour (la carte « Commandes et canaux ») : la caisse moins le webshop payé dans SA caisse (20 € + 30 €),
// et non plus le webshop encaissé à retirer ce jour-là (13,30 + 20 + 157,30 €).
verifie('le comptoir du jour : la caisse moins le webshop payé ce jour-là', abs($r['jour']['comptoir'] - 950.0) < 0.01, json_encode($r['jour']));
verifie('le webshop du jour : les commandes à retirer ce jour-là', abs($r['jour']['cc']['ca'] - 190.6) < 0.01 && $r['jour']['cc']['n'] === 3, json_encode($r['jour']['cc']));
$SE = array_column($r['serie'], null, 'j');
verifie('la série : hier perd le click & collect payé hier pour aujourd’hui', abs($SE[$hier]['comptoir'] - (1000 - 157.3)) < 0.01, json_encode($SE[$hier]));
verifie('la série : il y a trois jours aussi', abs($SE[$j3]['comptoir'] - (1000 - 13.3)) < 0.01, json_encode($SE[$j3]));
verifie('la série : un jour aux tickets muets garde l’ancienne règle (retrait ce jour-là)', abs($SE[$j5]['comptoir'] - (1000 - 40)) < 0.01, json_encode($SE[$j5]));
verifie('la série : J−7, son click & collect payé le jour même', abs($SE[$j7]['comptoir'] - (1000 - 16.5)) < 0.01, json_encode($SE[$j7]));
// Les tickets d'aujourd'hui muets : pas de caisseCmd, le dashboard garde l'ancienne règle.
unset(PanelApi::$tickets['/shops/4/transactions?date=' . $auj]);
$r = ep_exploitation_canaux();
verifie('les tickets du jour muets : caisseCmd null, le comptoir à l’ancienne règle', $r['caisseCmd'] === null && abs($r['jour']['comptoir'] - (1000 - 190.6)) < 0.01, json_encode([$r['caisseCmd'], $r['jour']['comptoir']]));
// Sans commande payée : aucun ticket à lire.
PanelApi::$commandes = [['pick_up_datetime' => $auj . ' 11:00:00', 'is_webshop' => 1, 'fulfilment_mode' => 'collect', 'total_value' => 5.80, 'order_status' => 'picked_up', 'id_client' => 6]];
PanelApi::$lus = [];
$r = ep_exploitation_canaux();
verifie('sans commande payée : aucun ticket lu, caisseCmd vide', PanelApi::$lus === [] && $r['caisseCmd'] === [] && $r['j7']['caisseCmd'] === [], json_encode([PanelApi::$lus, $r['caisseCmd']]));

echo "Bornes\n";
$_GET = ['shop' => '0']; $r = @ep_exploitation_canaux_mois();   // http_response_code() sans en-têtes en ligne de commande
verifie('sans magasin : erreur', isset($r['error']));
$_GET = ['shop' => (string) $sid, 'mois' => '2099-01', 'n' => '99'];
$r = ep_exploitation_canaux_mois();
verifie('un mois futur ramené au mois en cours, n plafonné à 12', $r['mois'] === $cour && count($r['historique']) === 12);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
