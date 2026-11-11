#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du paiement différé des clients pro (09/11/2026) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_differes.php
 *
 * Le cas de Halle, lundi 09/11/2026 : une précommande d'un client pro (08:30, 135,40 €, remise personnelle comprise)
 * est emportée et passée en caisse en paiement différé (ticket de 160,00 €, sans la remise, que le panel déduit à la
 * facture). Le panel ne pose pas le ticket sur la commande : elle restait « pas retirée », et la jauge B2B montrait
 * 160 €. On vérifie le rapprochement commande ↔ ticket différé (coApparierDifferes, la feuille « Commandes du jour »,
 * la ligne du dashboard) et le B2B remise déduite (vpFiltreTva, vpSplit, exHeuresPro) — le comptoir, lui, inchangé.
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
    /** @var array<string, mixed> chemin => réponse */
    public static array $rep = [];
    public static array $lus = [];
    public static function configured(): bool { return true; }
    public static function consultantShops(): ?array { return []; }
    public static function produitsDisponibles(int $sid): array { return []; }
    public static function sondeGet(string $p, int $t = 10): array { self::$lus[] = $p; $r = self::cherche($p); return ['code' => $r === null ? 404 : 200, 'corps' => $r]; }
    public static function get(string $p): mixed { self::$lus[] = $p; return self::cherche($p); }
    public static function getParallele(array $chemins, int $n = 6): array
    {
        $out = [];
        foreach ($chemins as $k => $p) { self::$lus[] = $p; $r = self::cherche($p); if ($r !== null) { $out[$k] = $r; } }
        return $out;
    }
    private static function cherche(string $p): mixed
    {
        foreach (self::$rep as $debut => $r) { if (str_starts_with($p, $debut)) { return $r; } }
        return null;
    }
}

require __DIR__ . '/../src/stats_ventes.php';
require __DIR__ . '/../src/ventes_pro.php';
require __DIR__ . '/../src/canaux_offres.php';
// exHeuresPro, isolée du reste de endpoints.php (comme bin/tests_jauges.php).
$src = file_get_contents(__DIR__ . '/../src/endpoints.php');
$a = strpos($src, 'function exHeuresPro('); $b = strpos($src, '/**', $a);
eval(substr($src, $a, $b - $a));

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "Rapprochement commande ↔ ticket différé\n";
$m = coApparierDifferes(
    [0 => ['cli' => 77, 'po' => '', 'heure' => '08:30']],
    [501 => ['cli' => 12, 'po' => '', 'heure' => '08:31'], 502 => ['cli' => 77, 'po' => '', 'heure' => '08:41']]);
verifie('le ticket différé du même client', $m === [0 => 502], json_encode($m));
$m = coApparierDifferes(
    [0 => ['cli' => 77, 'po' => '', 'heure' => '08:30'], 1 => ['cli' => 77, 'po' => '', 'heure' => '15:00']],
    [7 => ['cli' => 77, 'po' => '', 'heure' => '14:52'], 8 => ['cli' => 77, 'po' => '', 'heure' => '08:35']]);
verifie('deux commandes du même client : chacune le ticket le plus proche de son retrait', $m === [0 => 8, 1 => 7], json_encode($m));
$m = coApparierDifferes(
    [0 => ['cli' => 77, 'po' => 'PO-B', 'heure' => '08:30'], 1 => ['cli' => 77, 'po' => 'PO-A', 'heure' => '15:00']],
    [7 => ['cli' => 77, 'po' => 'PO-A', 'heure' => '08:35'], 8 => ['cli' => 77, 'po' => 'PO-B', 'heure' => '14:52']]);
verifie('le bon de commande (po_hash) passe avant l’heure', $m === [0 => 8, 1 => 7], json_encode($m));
$m = coApparierDifferes([0 => ['cli' => 77, 'po' => '', 'heure' => '08:30'], 1 => ['cli' => 77, 'po' => '', 'heure' => '09:00']], [9 => ['cli' => 77, 'po' => '', 'heure' => '08:40']]);
verifie('un ticket ne règle qu’une commande', $m === [0 => 9], json_encode($m));
verifie('sans client, rien', coApparierDifferes([0 => ['cli' => 0, 'po' => '', 'heure' => '08:30']], [9 => ['cli' => 0, 'po' => '', 'heure' => '08:30']]) === []);
verifie('un autre client, rien', coApparierDifferes([0 => ['cli' => 77, 'po' => '', 'heure' => '08:30']], [9 => ['cli' => 78, 'po' => '', 'heure' => '08:30']]) === []);

// --- le décor de Halle, aujourd'hui -------------------------------------------------------------
$sid = 4; $auj = date('Y-m-d'); $hier = date('Y-m-d', strtotime('-2 day'));
$GLOBALS['reglages'] = [];
PanelApi::$rep = [
    // Les commandes du jour : la remise au comptoir encaissée (ticket 900), la payée avant (ticket 901, un autre jour),
    // la précommande du client pro 77 réglée en différé (pas de ticket lié, « en préparation »).
    '/shops/4/client-orders' => [
        ['pick_up_datetime' => $auj . ' 06:30:00', 'is_webshop' => 0, 'total_value' => 38.0, 'order_status' => 'picked_up', 'id_transaction' => 900],
        ['pick_up_datetime' => $auj . ' 08:30:00', 'is_webshop' => 0, 'total_value' => 135.4, 'order_status' => 'in_production', 'accepting_timestamp' => $auj . ' 07:00:00', 'id_client' => 77, 'po_hash' => ''],
        ['pick_up_datetime' => $auj . ' 08:30:00', 'is_webshop' => 0, 'total_value' => 115.5, 'order_status' => 'picked_up', 'id_transaction' => 901],
    ],
    // Les tickets du jour : le différé du client 77 (160 €, sans sa remise), un ticket comptoir quelconque.
    '/shops/4/transactions?date=' => [
        ['id' => 900, 'insert_timestamp' => $auj . ' 06:30:00', 'total_gross_amount_after_discount' => 38.0],
        ['id' => 950, 'insert_timestamp' => $auj . ' 08:41:00', 'total_gross_amount_after_discount' => 160.0, 'id_client' => 77, 'is_client_b2b' => 1, 'deferral_payment' => 1, 'will_be_invoiced' => 0],
        ['id' => 951, 'insert_timestamp' => $auj . ' 09:02:00', 'total_gross_amount_after_discount' => 12.4],
    ],
    '/transactions/900' => ['id' => 900, 'insert_timestamp' => $auj . ' 06:30:00', 'products' => [['id_product' => 1, 'product_name' => 'Croissant', 'quantity' => 20, 'unit_gross_price' => 1.9, 'total_gross_value_after_discount' => 38.0]]],
    '/transactions/901' => ['id' => 901, 'insert_timestamp' => $hier . ' 14:31:00', 'products' => [['id_product' => 2, 'product_name' => 'Pain', 'quantity' => 41, 'unit_gross_price' => 2.82, 'total_gross_value_after_discount' => 115.5]]],
    '/transactions/950' => ['id' => 950, 'insert_timestamp' => $auj . ' 08:41:00', 'products' => [['id_product' => 3, 'product_name' => 'Mini viennoiserie', 'quantity' => 100, 'unit_gross_price' => 1.6, 'total_gross_value_after_discount' => 160.0]]],
    // La fiche du client 77 : TVA belge valide, remise personnelle de 15 %.
    '/shops/4/clients/77' => ['data' => ['client' => ['company_name' => 'Bureau SA', 'tax_number' => 'BE0123456749', 'personal_discount_percent' => 15]]],
];

echo "La feuille « Commandes du jour »\n";
$_GET = ['shop' => (string) $sid, 'date' => $auj];
$r = ep_exploitation_commandes_detail();
$C = $r['commandes'];
$pre = array_values(array_filter($C, static fn ($c) => abs($c['montant'] - 135.4) < 0.001))[0] ?? null;
verifie('la précommande réglée en différé n’est plus « pas retirée »', $pre !== null && $pre['etat'] === 'differe', json_encode($pre));
verifie('elle garde son montant (remise comprise), la caisse à côté', $pre['montant'] === 135.4 && ($pre['montantCaisse'] ?? null) === 160.0, json_encode($pre));
verifie('son ticket différé, ses articles lus dans le ticket', ($pre['ticket']['heure'] ?? '') === '08:41' && !empty($pre['ticket']['differe']) && $pre['articles'] === 100.0 && count((array) $pre['lignes']) === 1, json_encode($pre));
verifie('plus rien « pas retirée »', $r['etats']['attente']['n'] === 0 && $r['etats']['differe']['n'] === 1, json_encode($r['etats']));
verifie('les autres commandes inchangées : en caisse, payée avant', $r['etats']['caisse']['n'] === 1 && $r['etats']['avant']['n'] === 1, json_encode($r['etats']));
verifie('dans le CA du jour : en caisse + différé', abs($r['dansCa'] - (38.0 + 135.4)) < 0.001, (string) $r['dansCa']);

echo "Sans ticket différé du client\n";
$sauve = PanelApi::$rep['/shops/4/transactions?date='];
PanelApi::$rep['/shops/4/transactions?date='][1]['deferral_payment'] = 0;
$GLOBALS['reglages'] = [];
$r = ep_exploitation_commandes_detail();
verifie('un ticket pro réglé au comptoir ne règle pas la commande : elle reste pas retirée', $r['etats']['attente']['n'] === 1 && $r['etats']['differe']['n'] === 0, json_encode($r['etats']));
PanelApi::$rep['/shops/4/transactions?date='] = $sauve;

echo "La ligne « Commandes du jour » (relevé pro gravé, sans appel)\n";
$cmds = coCommandes($sid, $auj);
$GLOBALS['reglages']['svP' . $sid . ':' . $auj] = ['quand' => time(), 'b' => ['q' => time(), 'n' => 3, 'ca' => 210.4,
    't' => [['08:41', 'c77', 160.0, 0, 1]], 'h' => ['8' => [160.0, 1]]]];
$d = coDifferesDuReleve($sid, $auj, $cmds);
$iPre = array_search(135.4, array_column($cmds, 'montant'), true);
verifie('la précommande du client 77 est réglée en différé', $d === [$iPre => true], json_encode($d));
$GLOBALS['reglages']['svP' . $sid . ':' . $auj]['b']['t'][0][4] = 0;
verifie('un ticket pro non différé : rien', coDifferesDuReleve($sid, $auj, $cmds) === []);
verifie('pas de relevé gravé : rien', coDifferesDuReleve($sid, '2020-01-01', $cmds) === []);

echo "Le B2B remise personnelle déduite\n";
$GLOBALS['reglages'] = [];
$b = ['q' => time(), 'n' => 3, 'ca' => 210.4, 't' => [['08:41', 'c77', 160.0, 0, 1], ['09:02', 'c77', 12.4, 0, 0]], 'h' => ['8' => [160.0, 1], '9' => [12.4, 1]]];
$f = vpFiltreTva($sid, $b);
verifie('le ticket différé garde sa caisse en [2], la remise déduite en [5]', $f['t'][0][2] === 160.0 && $f['t'][0][5] === 136.0, json_encode($f['t']));
verifie('un ticket du même client réglé au comptoir : inchangé', !isset($f['t'][1][5]) && vpNet($f['t'][1]) === 12.4);
verifie('la remise du jour : un ticket, 24 €', $f['remise'] === ['tickets' => 1, 'ca' => 24.0], json_encode($f['remise']));
$s = vpSplit($f, 1292.4, 80);
verifie('la jauge B2B : 148,40 € remise déduite, 172,40 € en caisse', $s['caProApresRemise'] === 148.4 && $s['caPro'] === 172.4 && $s['remisePro'] === 24.0, json_encode($s));
verifie('le comptoir inchangé : la caisse moins le pro en caisse', $s['caComptoir'] === 1120.0 && $s['ticketsComptoir'] === 78, json_encode($s));
$h = exHeuresPro($f);
verifie('heure par heure : la remise sur l’heure du ticket différé', $h === [['h' => 8, 'ca' => 160.0, 'n' => 1, 'caApresRemise' => 136.0], ['h' => 9, 'ca' => 12.4, 'n' => 1]], json_encode($h));
$bj = vpBilanJour($f);
verifie('Clients pro : CA après remise, panier pro et part sur ce montant', $bj['caProApresRemise'] === 148.4 && $bj['panierPro'] === 74.2 && $bj['caPro'] === 172.4, json_encode($bj));

echo "Fiches sans remise\n";
// Un verdict de TVA gardé avant cette version (sans remise) : la fiche se relit une fois pour la remise.
$GLOBALS['reglages'] = ['vpTva:' . $sid => ['c77' => [1, time()]]];
PanelApi::$lus = [];
$f = vpFiltreTva(5, $b);   // un autre magasin : pas de mémo de requête
verifie('une fiche inconnue du magasin 5 : relue, sans remise faute de réponse', !isset($f['t'][0][5]) && in_array('/shops/5/clients/77', PanelApi::$lus, true), json_encode(PanelApi::$lus));
PanelApi::$rep['/shops/6/clients/77'] = ['data' => ['client' => ['tax_number' => 'BE0123456749']]];
$GLOBALS['reglages'] = ['vpTva:6' => ['c77' => [1, time()]]];
$f = vpFiltreTva(6, $b);
verifie('une fiche sans personal_discount_percent : rien de déduit', !isset($f['t'][0][5]) && $f['remise']['tickets'] === 0, json_encode($f));
$s = vpSplit($f, 1292.4, 80);
verifie('sans remise, le B2B après remise vaut la caisse', $s['caProApresRemise'] === $s['caPro'], json_encode($s));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
