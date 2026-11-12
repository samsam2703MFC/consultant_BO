<?php
declare(strict_types=1);

/*
 * SONDE — les chiffres du tableau de bord franchisé de Halle, pour une maquette « tout sur une page » (12/11/2026,
 * demande de Sam : « fais-moi une maquette avec tout sur une page »). La maquette ne montre que des chiffres réels :
 * cette sonde les lit, route par route, par l'API du panel — aucune table locale.
 *
 * Hier (la dernière journée complète), la semaine et le mois jusqu'à hier : ventes, objectifs, P&L, marge, catégories,
 * pertes, commandes clients, contrôles du jour, planning.
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-dashboard-halle.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_dashboard_halle.php
 *
 * LECTURE SEULE : des GET au panel. Rien de nominatif : les champs qui peuvent porter un nom, un téléphone, une
 * adresse ou un e-mail sont masqués ; les commandes et le planning ne sont que comptés.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require_once "$dir/src/$f";
}

const SD_SHOP = 4;
const SD_PRIVE = '/(^|_)(name|surname|nom|prenom|first_?name|last_?name|email|mail|phone|tel|address|adresse|street|city|zip|postal|iban|vat|tax_number|password|token|hash|comment|remark|note|client_name|employee_name|full_?name)($|_)/i';

$auj = date('Y-m-d');
$hier = date('Y-m-d', strtotime('-1 day'));
$lundi = date('Y-m-d', strtotime('monday this week'));
$mois = date('Y-m-01', strtotime($hier));

function sdTitre(string $t): void { echo "\n=== $t ===\n"; }

/** Une valeur à montrer, masquée si son champ peut être nominatif ; les listes coupées à $n éléments. */
function sdNettoie(mixed $v, string $cle = '', int $n = 4, int $prof = 0): mixed
{
    if ($cle !== '' && preg_match(SD_PRIVE, $cle) && !is_array($v)) { return $v === null || $v === '' ? $v : '•••'; }
    if (!is_array($v)) { return is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v; }
    if ($prof >= 5) { return '[…]'; }
    if (array_is_list($v)) {
        $out = [];
        foreach (array_slice($v, 0, $n) as $x) { $out[] = sdNettoie($x, '', $n, $prof + 1); }
        if (count($v) > $n) { $out[] = '… ' . count($v) . ' éléments en tout'; }
        return $out;
    }
    $out = [];
    foreach ($v as $k => $x) { $out[$k] = sdNettoie($x, (string) $k, $n, $prof + 1); }
    return $out;
}

/** Un GET montré : code, durée, corps nettoyé. */
function sdMontre(string $lib, string $chemin, int $n = 4): mixed
{
    $t = microtime(true);
    $r = PanelApi::sondeGet($chemin, 30);
    printf("\n-- %s  [%s] HTTP %d · %.1f s\n", $lib, $chemin, $r['code'], microtime(true) - $t);
    if ($r['corps'] !== null) { echo json_encode(sdNettoie($r['corps'], '', $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n"; }
    elseif ($r['erreur']) { echo '   ', $r['erreur'], "\n"; }
    return $r['corps'];
}

sdTitre('compte');
if (!PanelApi::configured()) { echo "  aucun identifiant TF Buddy\n"; exit(1); }
echo "  aujourd'hui $auj · hier $hier · semaine du $lundi · mois du $mois\n";

$q = static fn (string $du, string $au): string => http_build_query(['date_from' => $du, 'date_to' => $au]);

sdTitre('1. Ventes (statistics/sales/kpis)');
sdMontre('hier', '/shops/' . SD_SHOP . '/statistics/sales/kpis?' . $q($hier, $hier));
sdMontre('semaine jusqu\'à hier', '/shops/' . SD_SHOP . '/statistics/sales/kpis?' . $q($lundi, $hier));
sdMontre('mois jusqu\'à hier', '/shops/' . SD_SHOP . '/statistics/sales/kpis?' . $q($mois, $hier));
sdMontre('réseau, hier (toutes les boutiques)', '/consultant/shops/sales-kpis?' . $q($hier, $hier), 6);

sdTitre('2. Objectifs et P&L');
sdMontre('cibles', '/consultant/shops/' . SD_SHOP . '/targets', 12);
sdMontre('P&L jour par jour, mois', '/consultant/shops/' . SD_SHOP . '/pnl/daily?' . http_build_query(['date_from' => $mois, 'date_to' => $hier, 'from' => $mois, 'to' => $hier]), 14);
sdMontre('marge (heatmap), mois', '/consultant/shops/' . SD_SHOP . '/margin-heatmap?' . http_build_query(['from' => $mois, 'to' => $hier]), 14);

sdTitre('3. Catégories, semaine');
sdMontre('category-sales', '/consultant/shops/category-sales?' . http_build_query(['shop_id' => SD_SHOP, 'date_from' => $lundi, 'date_to' => $hier]), 14);

sdTitre('4. Pertes, semaine');
$w = PanelApi::sondeGet('/shops/' . SD_SHOP . '/products/waste?' . http_build_query(['from' => $lundi, 'to' => $hier, 'date_from' => $lundi, 'date_to' => $hier]), 30);
echo '  HTTP ', $w['code'], "\n";
if (is_array($w['corps'])) {
    $hors = array_diff_key($w['corps'], ['products' => 1]);
    echo '  enveloppe : ', json_encode(sdNettoie($hors, '', 3), JSON_UNESCAPED_UNICODE), "\n";
    $prods = is_array($w['corps']['products'] ?? null) ? $w['corps']['products'] : PanelApi::liste($w['corps']);
    echo '  références : ', count($prods), "\n";
    if ($prods) { echo '  champs : ', implode(', ', array_keys((array) $prods[0])), "\n"; }
    usort($prods, static fn ($a, $b) => (float) ($b['waste_cost'] ?? $b['cost'] ?? $b['value'] ?? 0) <=> (float) ($a['waste_cost'] ?? $a['cost'] ?? $a['value'] ?? 0));
    echo json_encode(sdNettoie(array_slice($prods, 0, 6), '', 6), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}

sdTitre('5. Commandes clients (à partir d\'hier)');
$c = PanelApi::sondeGet('/shops/' . SD_SHOP . '/client-orders?date_from=' . $hier, 30);
$l = is_array($c['corps']) ? PanelApi::liste($c['corps']) : [];
echo '  HTTP ', $c['code'], ' · ', count($l), " commandes\n";
if ($l) {
    echo '  champs : ', implode(', ', array_keys($l[0])), "\n";
    $par = [];
    foreach ($l as $o) {
        $quand = substr((string) ($o['pick_up_datetime'] ?? $o['delivery_datetime'] ?? ''), 0, 10);
        $k = $quand . ' · ' . (string) ($o['order_status'] ?? '?') . ' · ' . (!empty($o['is_webshop']) ? 'web' : 'comptoir')
            . ' · ' . ((int) ($o['id_transaction'] ?? 0) > 0 ? 'encaissée' : 'pas encaissée') . (($o['non_collection_id_reason'] ?? null) !== null ? ' · annulée' : '');
        $par[$k]['n'] = ($par[$k]['n'] ?? 0) + 1;
        $par[$k]['eur'] = round(($par[$k]['eur'] ?? 0) + (float) ($o['total_value'] ?? 0), 2);
    }
    ksort($par);
    foreach ($par as $k => $v) { printf("  %-62s %3d · %8.2f €\n", $k, $v['n'], $v['eur']); }
}

sdTitre('6. Contrôles (tâches et checklists) — hier et aujourd\'hui');
foreach ([$hier, $auj] as $j) {
    sdMontre("tâches $j", '/consultant/shops/' . SD_SHOP . '/tasks?date=' . $j, 30);
    sdMontre("checklists $j", '/consultant/shops/' . SD_SHOP . '/checklists?date=' . $j, 30);
}

sdTitre('7. Planning (compté, sans nom)');
$p = PanelApi::sondeGet('/shops/' . SD_SHOP . '/schedule', 30);
$pl = is_array($p['corps']) ? PanelApi::liste($p['corps']) : [];
echo '  HTTP ', $p['code'], ' · ', count($pl), " éléments\n";
if ($pl) {
    echo '  champs : ', implode(', ', array_keys((array) $pl[0])), "\n";
    $parJour = [];
    foreach ($pl as $s) {
        $j = substr((string) ($s['date'] ?? $s['start'] ?? $s['start_datetime'] ?? $s['day'] ?? ''), 0, 10);
        if ($j >= $lundi && $j <= date('Y-m-d', strtotime($lundi . ' +6 days'))) { $parJour[$j] = ($parJour[$j] ?? 0) + 1; }
    }
    ksort($parJour);
    echo '  postes par jour, cette semaine : ', json_encode($parJour), "\n";
    echo '  exemple (nettoyé) : ', json_encode(sdNettoie($pl[0], '', 3), JSON_UNESCAPED_UNICODE), "\n";
}

echo "\nfin de la sonde\n";
