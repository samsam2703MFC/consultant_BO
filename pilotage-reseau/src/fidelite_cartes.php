<?php
declare(strict_types=1);

/**
 * Les cartes de fidélité (13/11/2026, Sam : « quel client a quelle carte de fidélité, et où on en est au niveau
 * général » ; « chaque magasin doit pouvoir vérifier les cartes en général et par client » ; maquette A1,
 * docs/maquettes/cartes-fidelite). Lu en DIRECT dans l'ERP, rien n'est gardé ici (« tout passe par l'API ») :
 *   /admin/loyalty/cards                     les cartes définies (nom, timbres, récompense, couleurs, dates) ;
 *   /shops/{id}/loyalty/statistics           participants, cartes, reconnaissances, activité sur la période ;
 *   /shops/{id}/loyalty/customers            chaque client inscrit : ses cartes (progression actuelle, tous magasins)
 *                                            et son activité dans le magasin sur la période.
 * Ces routes exigent un jeton ADMIN avec LOYALTY.MANAGE : elles passent par le compte admin ERP du cockpit (ErpApi,
 * src/erp_api.php), pas par le compte consultant (PanelApi), qui n'y a pas droit (sonde du 13/11/2026).
 * L'ERP ne rend ni contact, ni code de carte ; le nom sort en « Prénom N. ».
 *
 *   GET /fidelite/cartes?shop=&periode=mois|30|annee     la vue du magasin (entonnoir, cartes, clients, réseau)
 *   GET /fidelite/carte-client?shop=&client=             les cartes d'un client (fiche client)
 *   GET /fidelite/carte?id=                              le détail d'une carte (Sam : « en cliquant sur la carte, ses détails »)
 */

const FC_PAGES_MAX = 20;   // pages de 100 clients lues au plus par magasin

/** Une lecture de l'ERP par le compte admin : [données, erreur]. */
function fcLire(string $chemin): array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return [null, 'le compte admin ERP du cockpit n’est pas configuré']; }
    $r = ErpApi::sondeGet($chemin);
    $c = (int) ($r['code'] ?? 0);
    if ($c === 403) { return [null, 'le compte admin ERP n’a pas la permission LOYALTY.MANAGE']; }
    if ($c < 200 || $c >= 300 || !is_array($r['corps'] ?? null)) { return [null, 'ERP injoignable (' . ($r['erreur'] ?? 'HTTP ' . $c) . ')']; }
    return [$r['corps'], null];
}

/** La période demandée, en dates locales : mois (depuis le 1er), 30 jours, année (depuis le 1er janvier). */
function fcPeriode(string $p): array
{
    $au = date('Y-m-d');
    return match ($p) {
        '30' => [date('Y-m-d', strtotime('-29 days')), $au, '30'],
        'annee' => [date('Y-01-01'), $au, 'annee'],
        default => [date('Y-m-01'), $au, 'mois'],
    };
}

/** Les cartes définies dans l'ERP, par id : nom, timbres requis, récompense, couleur, dates, active. */
function fcCartes(): array
{
    [$d] = fcLire('/admin/loyalty/cards');
    $out = [];
    foreach ((array) ($d['items'] ?? $d['data'] ?? []) as $c) {
        if (!is_array($c) || !isset($c['id'])) { continue; }
        $coul = (string) ($c['color_fill'] ?? '');
        $out[(int) $c['id']] = ['id' => (int) $c['id'], 'nom' => (string) ($c['name'] ?? ''), 'timbres' => isset($c['stamps_required']) ? (int) $c['stamps_required'] : null,
            'recompense' => (string) ($c['reward_label'] ?? ''), 'couleur' => preg_match('/^#[0-9a-f]{6}$/i', $coul) ? $coul : '#8D1D2C',
            'points' => is_array($c['points_config'] ?? null), 'du' => substr((string) ($c['starts_at'] ?? ''), 0, 10), 'au' => substr((string) ($c['ends_at'] ?? ''), 0, 10) ?: null,
            'active' => (int) ($c['active'] ?? 0) === 1, 'portee' => (string) ($c['scope'] ?? '')];
    }
    return $out;
}

/** « Prénom N. » à partir du nom de l'ERP. */
function fcNom(array $c): string
{
    $p = trim((string) ($c['name'] ?? '')); $n = trim((string) ($c['surname'] ?? ''));
    $nom = trim($p . ($n !== '' ? ' ' . mb_strtoupper(mb_substr($n, 0, 1)) . '.' : ''));
    return $nom !== '' ? $nom : 'Client ' . (int) ($c['id'] ?? 0);
}

/** Un programme d'un client, ramené à ce que l'écran montre. */
function fcProgramme(array $p): array
{
    $st = $p['global_progress']['stamps'] ?? null;
    $pt = $p['global_progress']['points'] ?? null;
    return ['id' => (int) ($p['id'] ?? 0), 'nom' => (string) ($p['name'] ?? ''), 'active' => !empty($p['active']),
        'timbres' => is_array($st) ? ['progres' => (int) $st['progress'], 'requis' => (int) $st['required'], 'reste' => (int) $st['remaining'], 'pretes' => (int) $st['ready_rewards'], 'dette' => (int) ($st['debt'] ?? 0)] : null,
        'points' => is_array($pt) ? ['solde' => (int) $pt['balance'], 'gagnes' => (int) ($pt['earned_total'] ?? 0), 'depenses' => (int) ($pt['spent_total'] ?? 0),
            'paliers' => array_map(static fn ($t) => ['points' => (int) $t['points'], 'euros' => round((int) $t['voucher_cents'] / 100, 2), 'atteint' => !empty($t['reached']), 'reste' => (int) $t['remaining']], (array) ($pt['tiers'] ?? []))] : null,
        'gagnesIci' => ['timbres' => (int) ($p['shop_activity']['stamps_earned'] ?? 0), 'points' => (int) ($p['shop_activity']['points_earned'] ?? 0)]];
}

/** Tous les clients inscrits d'un magasin sur la période (pages de 100). */
function fcClients(int $sid, string $du, string $au): array
{
    $L = []; $page = 1; $tot = 0; $err = null;
    do {
        [$d, $err] = fcLire('/shops/' . $sid . '/loyalty/customers?' . http_build_query(['from' => $du, 'to' => $au, 'page' => $page, 'limit' => 100]));
        if ($d === null) { break; }
        $tot = (int) ($d['pagination']['total'] ?? 0);
        foreach ((array) ($d['items'] ?? []) as $c) { if (is_array($c)) { $L[] = $c; } }
        $page++;
    } while (count($L) < $tot && $page <= FC_PAGES_MAX);
    return [$L, $err, $tot];
}

/** GET /fidelite/cartes?shop=&periode= : la vue du magasin. */
function ep_fidelite_cartes(): array
{
    $sid = ctype_digit((string) ($_GET['shop'] ?? '')) ? (int) $_GET['shop'] : 0;
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant (?shop=)']; }
    [$du, $au, $per] = fcPeriode((string) ($_GET['periode'] ?? 'mois'));
    [$st, $err] = fcLire('/shops/' . $sid . '/loyalty/statistics?' . http_build_query(['from' => $du, 'to' => $au]));
    if ($st === null) { return ['error' => $err]; }
    $cartes = fcCartes();
    [$C, $errC, $tot] = fcClients($sid, $du, $au);
    // Par carte : où en sont les clients (timbres de 0 à requis − 1), récompenses prêtes, presque au cadeau, actifs ici.
    $parCarte = [];
    $clients = [];
    foreach ($C as $c) {
        $P = array_map('fcProgramme', (array) ($c['programs'] ?? []));
        foreach ($P as $p) {
            $k = $p['id'];
            $x = &$parCarte[$k];
            $x ??= ['id' => $k, 'nom' => $p['nom'], 'clients' => 0, 'actifsIci' => 0, 'pretes' => 0, 'presque' => 0, 'repartition' => [], 'pointsSoldes' => [], 'timbresIci' => 0, 'pointsIci' => 0];
            $x['clients']++;
            if ($p['gagnesIci']['timbres'] + $p['gagnesIci']['points'] > 0) { $x['actifsIci']++; }
            $x['timbresIci'] += $p['gagnesIci']['timbres']; $x['pointsIci'] += $p['gagnesIci']['points'];
            if ($p['timbres']) {
                $x['pretes'] += $p['timbres']['pretes'];
                if (in_array($p['timbres']['reste'], [1, 2], true)) { $x['presque']++; }
                $x['requis'] = $p['timbres']['requis'];
                $x['repartition'][$p['timbres']['progres']] = ($x['repartition'][$p['timbres']['progres']] ?? 0) + 1;
            }
            if ($p['points']) { $x['pointsSoldes'][] = $p['points']['solde']; }
            unset($x);
        }
        $a = (array) ($c['shop_activity'] ?? []);
        $clients[] = ['id' => (int) ($c['id'] ?? 0), 'nom' => fcNom($c), 'actif' => !empty($c['active']), 'bloque' => !empty($c['blocked']), 'cartes' => $P,
            'achats' => (int) ($a['transactions'] ?? 0), 'montant' => round((float) ($a['gross_sales_before_corrections'] ?? 0), 2),
            'dernierAchat' => $a['last_transaction_at'] ?? null, 'reconnaissances' => (int) ($a['recognitions'] ?? 0), 'derniereReconnaissance' => $a['last_recognition_at'] ?? null,
            'pretes' => array_sum(array_map(static fn ($p) => $p['timbres']['pretes'] ?? 0, $P))];
    }
    foreach ($parCarte as $k => &$x) {
        $def = $cartes[$k] ?? null;
        $x += ['definition' => $def];
        $req = (int) ($x['requis'] ?? ($def['timbres'] ?? 0));
        $x['repartition'] = $req > 0 ? array_map(static fn ($i) => $x['repartition'][$i] ?? 0, range(0, $req - 1)) : [];
        sort($x['pointsSoldes']);
    }
    unset($x);
    usort($clients, static fn ($a, $b) => [$b['pretes'], $b['achats']] <=> [$a['pretes'], $a['achats']]);
    // Le haut de l'entonnoir : les clients reconnus en caisse sur 90 jours (cache des tickets de la fidélité).
    $reconnus = null;
    try { $reconnus = (int) (Db::row('SELECT COUNT(DISTINCT client_id) n FROM ceo_fid_ticket WHERE shop_id = ? AND jour >= ?', [$sid, date('Y-m-d', strtotime('-89 days'))])['n'] ?? 0); }
    catch (Throwable $e) { /* sans cache : l'entonnoir commence aux inscrits */ }
    // Le réseau : les mêmes statistiques pour chaque magasin.
    $reseau = [];
    foreach (fidMagasins() as $m) {
        $mid = (int) $m['id'];
        [$s2] = $mid === $sid ? [$st] : fcLire('/shops/' . $mid . '/loyalty/statistics?' . http_build_query(['from' => $du, 'to' => $au]));
        if (!is_array($s2)) { continue; }
        $reseau[] = ['id' => (string) $mid, 'nom' => (string) $m['nom'], 'inscrits' => (int) ($s2['participants']['unique_customers'] ?? 0), 'actifs' => (int) ($s2['participants']['active_in_period'] ?? 0),
            'timbres' => (int) ($s2['shop_activity']['stamps_earned'] ?? 0), 'points' => (int) ($s2['shop_activity']['points_earned'] ?? 0),
            'ventes' => round((float) ($s2['shop_activity']['gross_sales_before_corrections'] ?? 0), 2), 'reconnaissances' => (int) ($s2['recognitions']['count'] ?? 0)];
    }
    return ['shop' => (string) $sid, 'periode' => $per, 'du' => $du, 'au' => $au, 'lu' => date('Y-m-d H:i'),
        'participants' => $st['participants'] ?? null, 'reconnaissances' => array_diff_key((array) ($st['recognitions'] ?? []), ['by_device' => 1]),
        'activite' => $st['shop_activity'] ?? null, 'reconnusCaisse90' => $reconnus,
        'cartes' => array_values($parCarte), 'definitions' => array_values($cartes), 'clients' => $clients, 'clientsTotal' => $tot, 'erreurClients' => $errC, 'reseau' => $reseau];
}

/** GET /fidelite/carte-client?shop=&client= : les cartes d'un client, et son activité dans le magasin (depuis janvier). */
function ep_fidelite_carte_client(): array
{
    $sid = ctype_digit((string) ($_GET['shop'] ?? '')) ? (int) $_GET['shop'] : 0;
    $cid = ctype_digit((string) ($_GET['client'] ?? '')) ? (int) $_GET['client'] : 0;
    if ($sid <= 0 || $cid <= 0) { http_response_code(422); return ['error' => 'magasin ou client manquant (?shop=&client=)']; }
    [$C, $err] = fcClients($sid, date('Y-01-01'), date('Y-m-d'));
    if ($err !== null && $C === []) { return ['error' => $err]; }
    $cartes = fcCartes();
    foreach ($C as $c) {
        if ((int) ($c['id'] ?? 0) !== $cid) { continue; }
        $a = (array) ($c['shop_activity'] ?? []);
        $P = array_map(static fn ($p) => fcProgramme($p) + ['definition' => $cartes[(int) ($p['id'] ?? 0)] ?? null], (array) ($c['programs'] ?? []));
        return ['inscrit' => true, 'cartes' => $P, 'achats' => (int) ($a['transactions'] ?? 0), 'montant' => round((float) ($a['gross_sales_before_corrections'] ?? 0), 2),
            'dernierAchat' => $a['last_transaction_at'] ?? null, 'reconnaissances' => (int) ($a['recognitions'] ?? 0), 'derniereReconnaissance' => $a['last_recognition_at'] ?? null,
            'disponibles' => array_values(array_filter($cartes, static fn ($k) => $k['active']))];
    }
    return ['inscrit' => false, 'cartes' => [], 'disponibles' => array_values(array_filter($cartes, static fn ($k) => $k['active']))];
}

/**
 * GET /fidelite/carte?id= : le détail d'une carte, tel que l'ERP la définit (/admin/loyalty/cards) — mécanique, récompense,
 * validité, magasins, ce qui fait gagner un timbre, ce qui en est exclu, ce que la récompense donne, les points —, les
 * identifiants de produits et de catégories traduits en noms par le catalogue (/products, /product-categories).
 */
function ep_fidelite_carte(): array
{
    $id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;
    if ($id <= 0) { http_response_code(422); return ['error' => 'carte manquante (?id=)']; }
    [$d, $err] = fcLire('/admin/loyalty/cards');
    if ($d === null) { return ['error' => $err]; }
    $c = null;
    foreach ((array) ($d['items'] ?? $d['data'] ?? []) as $x) { if (is_array($x) && (int) ($x['id'] ?? 0) === $id) { $c = $x; } }
    if ($c === null) { http_response_code(404); return ['error' => 'carte introuvable dans l’ERP']; }
    // Les noms : le catalogue du panel (produits, catégories), lu une fois par appel.
    $prod = []; $cat = [];
    foreach (PanelApi::liste(is_array(($r = PanelApi::sondeGet('/products', 60))['corps']) ? $r['corps'] : []) as $p) { $prod[(int) ($p['id'] ?? 0)] = (string) ($p['name'] ?? ''); }
    foreach (PanelApi::liste(is_array(($r = PanelApi::sondeGet('/product-categories', 30))['corps']) ? $r['corps'] : []) as $k) { $cat[(int) ($k['id'] ?? 0)] = trim((string) ($k['name'] ?? '')); }
    $noms = static fn (array $ids, array $m): array => array_values(array_map(static fn ($i) => $m[(int) $i] ?? ('#' . (int) $i), $ids));
    $mag = [];
    foreach (fidMagasins() as $m) { $mag[(int) $m['id']] = (string) $m['nom']; }
    $pc = is_array($c['points_config'] ?? null) ? $c['points_config'] : null;
    $coul = (string) ($c['color_fill'] ?? '');
    return [
        'id' => $id, 'nom' => (string) ($c['name'] ?? ''), 'code' => (string) ($c['code'] ?? ''), 'version' => (int) ($c['version'] ?? 1),
        'active' => (int) ($c['active'] ?? 0) === 1, 'portee' => (string) ($c['scope'] ?? ''),
        'magasins' => $noms((array) ($c['shop_ids'] ?? []), $mag),
        'du' => (string) ($c['starts_at'] ?? ''), 'au' => $c['ends_at'] ?? null,
        'couleur' => preg_match('/^#[0-9a-f]{6}$/i', $coul) ? $coul : '#8D1D2C',
        'timbres' => isset($c['stamps_required']) ? (int) $c['stamps_required'] : null,
        'recompense' => (string) ($c['reward_label'] ?? ''), 'recompenseValideJours' => (int) ($c['reward_validity_days'] ?? 0),
        'recompenseProduits' => $noms((array) ($c['reward_product_ids'] ?? []), $prod),
        'recompensePortions' => count((array) ($c['reward_portion_ids'] ?? [])),
        'gagnerProduits' => $noms((array) ($c['earning_product_ids'] ?? []), $prod),
        'gagnerCategories' => $noms((array) ($c['earning_category_ids'] ?? []), $cat),
        'gagnerGroupes' => count((array) ($c['earning_category_group_ids'] ?? [])),
        'motsCles' => array_values(array_map('strval', (array) ($c['keywords'] ?? []))),
        'exclus' => $noms((array) ($c['excluded_product_ids'] ?? []), $prod),
        'conditionsFigees' => !empty($c['terms_locked']),
        'points' => $pc && !empty($pc['points_enabled']) ? ['parTranche' => (int) ($pc['points_per_slice'] ?? 0), 'trancheEuros' => round((int) ($pc['slice_cents'] ?? 0) / 100, 2),
            'bonValideJours' => (int) ($pc['voucher_validity_days'] ?? 0), 'pointsValidesJours' => (int) ($pc['points_validity_days'] ?? 0),
            'paliers' => array_map(static fn ($t) => ['points' => (int) ($t['points'] ?? 0), 'euros' => round((int) ($t['voucher_cents'] ?? 0) / 100, 2)], (array) ($pc['tiers'] ?? []))] : null,
    ];
}
