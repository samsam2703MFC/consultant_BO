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
// `name` seul reste lisible (nom d'une checklist, d'un produit, d'un magasin) ; les noms de personne sont masqués.
const SD_PRIVE = '/^(surname|display_name|first_?name|last_?name|full_?name|client_name|employee_name|completed_by|review_by_name|admin_validated_by_name|email|email_norm|phone|phone_e164|address|street|street_number|city|zip|iban|tax_number|password_hash|note|remark|comment|review_comment)$/i';

$auj = date('Y-m-d');
$hier = date('Y-m-d', strtotime('-1 day'));
$lundi = date('Y-m-d', strtotime('monday this week'));
$mois = date('Y-m-01', strtotime($hier));

function sdTitre(string $t): void { echo "\n=== $t ===\n"; }

/** Une valeur à montrer, masquée si son champ peut être nominatif ; les listes coupées à $n éléments. */
function sdNettoie(mixed $v, string $cle = '', int $n = 4, int $prof = 0): mixed
{
    // Tout ce qui ressemble à un secret ou à une coordonnée bancaire est masqué, quel que soit le nom exact du champ.
    if ($cle !== '' && preg_match('/pass|secret|token|bank|iban|swift|bic|account_number|tax|peppol|billing|vat/i', $cle) && !is_array($v)) { return $v === null || $v === '' ? $v : '•••'; }
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
    if ($r['corps'] !== null) { echo json_encode(sdNettoie($r['corps'], '', $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; }
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
    // L'enveloppe porte aussi la fiche de la société (`shop`) : jamais affichée, seul le résumé de la période l'est.
    echo '  résumé : ', json_encode(sdNettoie($w['corps']['period_summary'] ?? null, '', 3), JSON_UNESCAPED_UNICODE), "\n";
    echo '  clés de l\'enveloppe : ', implode(', ', array_keys($w['corps'])), "\n";
    $prods = is_array($w['corps']['products'] ?? null) ? $w['corps']['products'] : PanelApi::liste($w['corps']);
    echo '  références : ', count($prods), "\n";
    if ($prods) { echo '  champs : ', implode(', ', array_keys((array) $prods[0])), "\n"; }
    usort($prods, static fn ($a, $b) => (float) ($b['waste_cost'] ?? $b['cost'] ?? $b['value'] ?? 0) <=> (float) ($a['waste_cost'] ?? $a['cost'] ?? $a['value'] ?? 0));
    foreach (array_slice($prods, 0, 8) as $x) { echo '  ', json_encode(sdNettoie($x, '', 3), JSON_UNESCAPED_UNICODE), "\n"; }
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

sdTitre('6. Contrôles (tâches) — hier et aujourd\'hui, une ligne par tâche');
foreach ([$hier, $auj] as $j) {
    $r = PanelApi::sondeGet('/consultant/shops/' . SD_SHOP . '/tasks?date=' . $j, 30);
    $c = is_array($r['corps']) ? $r['corps'] : [];
    echo "\n-- $j : HTTP ", $r['code'], ' · résumé ', json_encode($c['summary'] ?? null), "\n";
    foreach ($c as $k => $v) {
        if (in_array($k, ['summary', 'tasks'], true)) { continue; }
        echo "  $k : ", json_encode(sdNettoie($v, (string) $k, 10), JSON_UNESCAPED_UNICODE), "\n";
    }
    $parCl = [];
    foreach ((array) ($c['tasks'] ?? []) as $t) {
        $cl = (string) ($t['checklist_name'] ?? '?');
        $parCl[$cl]['h'] = substr((string) ($t['execution_time'] ?? ''), 0, 5);
        $parCl[$cl]['n'] = ($parCl[$cl]['n'] ?? 0) + 1;
        $parCl[$cl]['ok'] = ($parCl[$cl]['ok'] ?? 0) + (strtoupper((string) ($t['status'] ?? '')) === 'DONE' ? 1 : 0);
        $parCl[$cl]['photo'] = ($parCl[$cl]['photo'] ?? 0) + (!empty($t['attachment_id']) ? 1 : 0);
        $parCl[$cl]['statuts'][(string) ($t['status'] ?? '?')] = ($parCl[$cl]['statuts'][(string) ($t['status'] ?? '?')] ?? 0) + 1;
        $parCl[$cl]['notes'][] = $t['review_rating'] ?? null;
    }
    uasort($parCl, static fn ($a, $b) => strcmp($a['h'], $b['h']));
    foreach ($parCl as $cl => $x) {
        $notes = array_values(array_filter($x['notes'], static fn ($v) => $v !== null));
        printf("  %s  %-40s %2d / %2d faites · %2d photos · %s%s\n", $x['h'], mb_substr($cl, 0, 40), $x['ok'], $x['n'], $x['photo'], json_encode($x['statuts']),
            $notes ? ' · notes ' . json_encode($notes) : '');
    }
}

sdTitre('7. Planning (compté, sans nom)');
$p = PanelApi::sondeGet('/shops/' . SD_SHOP . '/schedule', 30);
$pl = is_array($p['corps']) ? PanelApi::liste($p['corps']) : [];
echo '  HTTP ', $p['code'], ' · ', count($pl), " postes en tout\n";
$parJour = []; $max = '';
foreach ($pl as $x) {
    $j = (string) ($x['work_date'] ?? '');
    if ($j > $max) { $max = $j; }
    if ($j >= date('Y-m-d', strtotime($lundi . ' -7 days')) && $j <= date('Y-m-d', strtotime($lundi . ' +13 days'))) {
        $h = (strtotime($j . ' ' . ($x['end_hour'] ?? '00:00')) - strtotime($j . ' ' . ($x['start_hour'] ?? '00:00'))) / 3600;
        $parJour[$j]['postes'] = ($parJour[$j]['postes'] ?? 0) + 1;
        $parJour[$j]['heures'] = round(($parJour[$j]['heures'] ?? 0) + max(0, $h), 1);
        $parJour[$j]['personnes'][(int) ($x['id_employee'] ?? 0)] = 1;
    }
}
ksort($parJour);
echo "  dernier jour planifié : $max\n";
foreach ($parJour as $j => $v) { printf("  %s : %d postes · %d personnes · %.1f h\n", $j, $v['postes'], count($v['personnes']), $v['heures']); }

echo "\nfin de la sonde\n";
