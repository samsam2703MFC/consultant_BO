#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de GET /exploitation/canaux-mois — sans base, sans réseau, sans token.
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
    public static function get(string $p): mixed { self::$appels++; return null; }
    public static function getParallele(array $chemins, int $n = 6): array { self::$appels += count($chemins); return []; }
}

require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/panel_ventes.php';
require __DIR__ . '/../src/ventes_pro.php';
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

echo "Bornes\n";
$_GET = ['shop' => '0']; $r = @ep_exploitation_canaux_mois();   // http_response_code() sans en-têtes en ligne de commande
verifie('sans magasin : erreur', isset($r['error']));
$_GET = ['shop' => (string) $sid, 'mois' => '2099-01', 'n' => '99'];
$r = ep_exploitation_canaux_mois();
verifie('un mois futur ramené au mois en cours, n plafonné à 12', $r['mois'] === $cour && count($r['historique']) === 12);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
