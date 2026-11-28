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
 *   GET /fidelite/cartes/bingo?shop=                     le bingo de la saison, client par client (28/11/2026)
 *
 * Particuliers et entreprises (28/11/2026, Sam : « réorganiser la vision des cartes des points et bingo par rapport aux
 * clients et aux firmes », maquette C retenue, panel/docs/maquettes/fidelite-particuliers-entreprises) : chaque client
 * inscrit est un particulier, une société (client B2B de TF Buddy) ou une personne d'une société (rattachée par
 * `company_client_id`, ou par son bureau quand le compte TF Buddy du magasin est connecté : `office_id` → la société du
 * bureau). Le bingo suit les règles du module (shared/src/data/fidelite_bingo.json) : la case de la semaine se coche dès
 * `seuilEuros` € d'achats du lundi au dimanche, tous magasins ; il se calcule ici, en lecture seule, sans émettre de bon.
 */

const FC_PAGES_MAX = 20;   // pages de 100 clients lues au plus par magasin
const FC_TTL_CLOSE = 86400; // une semaine de bingo close se relit au plus une fois par jour
const FC_TTL_COURS = 600;   // la semaine en cours, toutes les dix minutes

/** Une lecture de l'ERP par le compte admin : [données, erreur]. `$GLOBALS['FC_ERP']` (callable) remplace l'ERP dans les tests. */
function fcLire(string $chemin): array
{
    if (isset($GLOBALS['FC_ERP']) && is_callable($GLOBALS['FC_ERP'])) { $r = ($GLOBALS['FC_ERP'])($chemin); }
    elseif (!class_exists('ErpApi') || !ErpApi::disponible()) { return [null, 'le compte admin ERP du cockpit n’est pas configuré']; }
    else { $r = ErpApi::sondeGet($chemin); }
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
    // Particuliers, sociétés et personnes des sociétés (28/11/2026) : les clients de TF Buddy du magasin disent qui est qui.
    [$clients, $entreprises, $liens] = fcRanger($clients, fcLiensMagasin($sid));
    // Le haut de l'entonnoir : les clients reconnus en caisse sur 90 jours (cache des tickets de la fidélité).
    $reconnus = null;
    try { $reconnus = (int) (Db::row('SELECT COUNT(DISTINCT client_id) n FROM mar_fid_ticket WHERE shop_id = ? AND jour >= ?', [$sid, date('Y-m-d', strtotime('-89 days'))])['n'] ?? 0); }
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
        'cartes' => array_values($parCarte), 'definitions' => array_values($cartes), 'clients' => $clients, 'clientsTotal' => $tot, 'erreurClients' => $errC, 'reseau' => $reseau,
        'entreprises' => $entreprises, 'liens' => $liens];
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

/* ---------------------------------------------------------------------------
 * Particuliers, sociétés et personnes des sociétés (28/11/2026, maquette C)
 * ------------------------------------------------------------------------- */

/**
 * Qui est qui, à partir des clients de TF Buddy du magasin. `$b2b` : [id => {nom, ville}], les sociétés ; `$b2c` :
 * [id => {societeId, bureauId}] ; `$bureaux` : [id du bureau => {societe, nom, facturation}] (vide sans le compte du
 * magasin). → ['type' => [client => 'societe'|'personne'], 'societe' => [client => id de sa société], 'societes' =>
 * [id => {id, nom, ville, bureaux[], facturation[], personnes}]] ; `personnes` : les clients de TF Buddy rattachés à la
 * société, qu'ils aient une carte ou non. Une personne dont la société n'est pas un client du magasin reste un particulier.
 */
function fcLiens(array $b2b, array $b2c, array $bureaux): array
{
    $type = []; $soc = []; $S = [];
    foreach ($b2b as $id => $c) {
        $id = (int) $id;
        if ($id <= 0) { continue; }
        $type[$id] = 'societe';
        $S[$id] = ['id' => $id, 'nom' => (string) ($c['nom'] ?? ''), 'ville' => (string) ($c['ville'] ?? ''), 'bureaux' => [], 'facturation' => [], 'personnes' => 0];
    }
    foreach ($bureaux as $b) {
        $s = (int) ($b['societe'] ?? 0);
        if (!isset($S[$s])) { continue; }
        foreach (['nom' => 'bureaux', 'facturation' => 'facturation'] as $k => $o) {
            $v = trim((string) ($b[$k] ?? ''));
            if ($v !== '' && !in_array($v, $S[$s][$o], true)) { $S[$s][$o][] = $v; }
        }
    }
    foreach ($b2c as $id => $c) {
        $id = (int) $id;
        $s = (int) ($c['societeId'] ?? 0);
        if ($s <= 0 && (int) ($c['bureauId'] ?? 0) > 0) { $s = (int) ($bureaux[(int) $c['bureauId']]['societe'] ?? 0); }
        if ($id <= 0 || $s <= 0 || !isset($S[$s]) || isset($type[$id])) { continue; }
        $type[$id] = 'personne'; $soc[$id] = $s; $S[$s]['personnes']++;
    }
    return ['type' => $type, 'societe' => $soc, 'societes' => $S];
}

/**
 * Les liens du magasin, lus dans TF Buddy : ses clients (panel/src/clients_panel.php, la liste gardée un quart d'heure)
 * et, si le compte TF Buddy du magasin est connecté (lui seul y a droit), ses bureaux. `$GLOBALS['FC_LIENS']` (callable)
 * les remplace dans les tests.
 */
function fcLiensMagasin(int $sid): array
{
    if (isset($GLOBALS['FC_LIENS']) && is_callable($GLOBALS['FC_LIENS'])) { return ($GLOBALS['FC_LIENS'])($sid); }
    if (!function_exists('pclListe')) { return fcLiens([], [], []) + ['lu' => false, 'bureauxLus' => false]; }
    $b2b = pclListe($sid, 'b2b');
    $b2c = pclListe($sid, 'b2c');
    $bur = []; $lus = false;
    if (function_exists('pclTf') && function_exists('tfbmJeton') && tfbmJeton() !== null) {
        $r = pclTf('GET', '/panel/shops/' . $sid . '/offices');
        if ($r['code'] >= 200 && $r['code'] < 300) {
            $lus = true;
            foreach (pclItems($r['corps']) as $o) {
                if (!is_array($o) || (int) ($o['id'] ?? 0) <= 0) { continue; }
                $f = (string) ($o['billing_mode'] ?? '');
                $bur[(int) $o['id']] = ['societe' => (int) ($o['id_company_client'] ?? 0), 'nom' => trim((string) ($o['name'] ?? '')),
                    'facturation' => $f === 'company' ? 'facturé à la société' : ($f === 'person' ? 'chaque personne paie' : '')];
            }
        }
    }
    return fcLiens((array) $b2b, (array) $b2c, $bur) + ['lu' => $b2b !== null && $b2c !== null, 'bureauxLus' => $lus];
}

/**
 * Range les clients inscrits : chacun reçoit `type` (particulier, societe, personne) et `societe` (l'id de sa société) ;
 * une société prend le nom que TF Buddy lui donne. Les entreprises montrées sont celles dont le compte a une carte ou qui
 * ont au moins une personne inscrite, la plus suivie d'abord. → [clients, entreprises, liens].
 */
function fcRanger(array $clients, array $L): array
{
    $vues = [];
    foreach ($clients as &$c) {
        $id = (int) $c['id'];
        $t = $L['type'][$id] ?? 'particulier';
        $s = $t === 'societe' ? $id : ($t === 'personne' ? (int) ($L['societe'][$id] ?? 0) : 0);
        $c['type'] = $t;
        $c['societe'] = $s ?: null;
        if ($t === 'societe' && ($L['societes'][$id]['nom'] ?? '') !== '') { $c['nom'] = (string) $L['societes'][$id]['nom']; }
        if ($s) { $vues[$s] = true; }
    }
    unset($c);
    $E = [];
    foreach (array_keys($vues) as $s) {
        $x = $L['societes'][$s] ?? null;
        if ($x === null) { continue; }
        $x['compte'] = false; $x['inscrites'] = 0;
        foreach ($clients as $c) {
            if ($c['id'] === $s) { $x['compte'] = true; }
            if ($c['type'] === 'personne' && $c['societe'] === $s) { $x['inscrites']++; }
        }
        $E[] = $x;
    }
    usort($E, static fn ($a, $b) => [$b['inscrites'], $b['compte'], $a['nom']] <=> [$a['inscrites'], $a['compte'], $b['nom']]);
    $nS = count($L['societes'] ?? []);
    return [$clients, $E, ['lu' => !empty($L['lu']), 'bureaux' => !empty($L['bureauxLus']), 'societes' => $nS, 'sansCarte' => max(0, $nS - count($E))]];
}

/* ---------------------------------------------------------------------------
 * Le bingo de la saison (28/11/2026), en lecture seule : les règles et le calendrier du module
 * (shared/src/data/fidelite_bingo.json), les achats de chaque semaine lus dans l'ERP. Aucun bon n'est émis ici.
 * ------------------------------------------------------------------------- */

function fcAujourdhui(): string { return (string) ($GLOBALS['FC_AUJOURDHUI'] ?? date('Y-m-d')); }
function fcPlus(string $d, int $jours): string { return gmdate('Y-m-d', (int) strtotime($d . ' 12:00:00 UTC') + $jours * 86400); }

/** Les règles et les saisons du bingo ; `$GLOBALS['FC_BINGO']` les remplace dans les tests. */
function fcBingoConf(): array
{
    if (isset($GLOBALS['FC_BINGO']) && is_array($GLOBALS['FC_BINGO'])) { return $GLOBALS['FC_BINGO']; }
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents(__DIR__ . '/../../shared/src/data/fidelite_bingo.json'), true);
        if (!is_array($c)) { $c = []; }
    }
    return $c;
}

/** La saison du jour (ou la prochaine, ou la dernière) : ses semaines (dates, thème, état), la semaine en cours. */
function fcSaison(string $jour): ?array
{
    $S = array_values(array_filter((array) (fcBingoConf()['saisons'] ?? []), static fn ($s) => is_array($s) && !empty($s['du']) && !empty($s['semaines'])));
    if (!$S) { return null; }
    $s = $S[count($S) - 1];
    foreach ($S as $x) { if ($jour <= fcPlus((string) $x['du'], 7 * count($x['semaines']) - 1)) { $s = $x; break; } }
    $sem = []; $cours = 0; $closes = 0;
    foreach (array_values((array) $s['semaines']) as $i => $w) {
        $du = fcPlus((string) $s['du'], 7 * $i); $au = fcPlus($du, 6);
        $etat = $au < $jour ? 'passee' : ($du <= $jour ? 'cours' : 'avenir');
        if ($etat === 'cours') { $cours = $i + 1; } elseif ($etat === 'passee') { $closes++; }
        $sem[] = ['n' => $i + 1, 'du' => $du, 'au' => $au, 'titre' => (string) ($w['titre'] ?? ''), 'sous' => (string) ($w['sous'] ?? ''), 'etat' => $etat];
    }
    $fin = fcPlus((string) $s['du'], 7 * count($sem) - 1);
    return ['id' => (string) ($s['id'] ?? ''), 'nom' => (string) ($s['nom'] ?? ''), 'du' => (string) $s['du'], 'au' => $fin, 'tirage' => $s['tirage'] ?? null,
        'etat' => $fin < $jour ? 'passee' : ((string) $s['du'] <= $jour ? 'cours' : 'avenir'), 'enCours' => $cours, 'closes' => $closes, 'semaines' => $sem];
}

/* Un cache des réponses (fichiers temporaires) : il ne fait jamais foi, vide tout se relit dans l'ERP. */
function fcCacheFichier(string $cle): string
{
    $d = sys_get_temp_dir() . '/cockpit-fidelite-cartes';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d . '/' . sha1($cle) . '.json';
}
function fcCacheLire(string $cle, int $ttl): ?array
{
    if (isset($GLOBALS['FC_ERP'])) { return null; }
    $f = fcCacheFichier($cle);
    if (!is_file($f) || filemtime($f) < time() - $ttl) { return null; }
    $v = json_decode((string) file_get_contents($f), true);
    return is_array($v) ? $v : null;
}
function fcCacheEcrire(string $cle, array $v): void
{
    if (isset($GLOBALS['FC_ERP'])) { return; }
    @file_put_contents(fcCacheFichier($cle), json_encode($v), LOCK_EX);
}

/** Plusieurs lectures de l'ERP de front : [clé => corps | null]. */
function fcLireTous(array $chemins): array
{
    if ($chemins === []) { return []; }
    if (isset($GLOBALS['FC_ERP']) && is_callable($GLOBALS['FC_ERP'])) {
        $o = [];
        foreach ($chemins as $k => $p) { [$o[$k]] = fcLire($p); }
        return $o;
    }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return array_fill_keys(array_keys($chemins), null); }
    return ErpApi::getParallele($chemins, 6);
}

/**
 * Les achats de chaque client, magasin par magasin, semaine après semaine : [[semaine => [shop => [client => €]]], les
 * magasins illisibles]. Les fiches de `/shops/{id}/loyalty/customers` (toutes les pages) sur la semaine ; une semaine
 * close se garde un jour, la semaine en cours dix minutes.
 */
function fcSemaines(array $saison, int $n, array $shops): array
{
    $jour = fcAujourdhui();
    $chemin = static fn (int $s, string $du, string $au, int $p): string => '/shops/' . $s . '/loyalty/customers?' . http_build_query(['from' => $du, 'to' => $au, 'page' => $p, 'limit' => 100]);
    $euros = static function (mixed $corps, array &$dans): void {
        $L = is_array($corps) ? ($corps['items'] ?? $corps['data'] ?? []) : [];
        foreach ((array) $L as $f) {
            if (!is_array($f) || (int) ($f['id'] ?? 0) <= 0) { continue; }
            $dans[(int) $f['id']] = round(($dans[(int) $f['id']] ?? 0) + (float) ($f['shop_activity']['gross_sales_before_corrections'] ?? 0), 2);
        }
    };
    $out = []; $manque = []; $err = [];
    for ($w = 1; $w <= $n; $w++) {
        $x = $saison['semaines'][$w - 1];
        $au = min($x['au'], $jour);
        foreach ($shops as $s) {
            $cle = 'fcsem|' . $s . '|' . $x['du'] . '|' . $au;
            $c = fcCacheLire($cle, $x['au'] < $jour ? FC_TTL_CLOSE : FC_TTL_COURS);
            if ($c !== null) { $out[$w][$s] = $c; continue; }
            $out[$w][$s] = [];
            $manque[$w . '|' . $s] = ['w' => $w, 's' => $s, 'du' => $x['du'], 'au' => $au, 'cle' => $cle, 'ok' => true];
        }
    }
    if ($manque) {
        $p1 = fcLireTous(array_map(static fn ($m) => $chemin($m['s'], $m['du'], $m['au'], 1), $manque));
        $suite = [];
        foreach ($manque as $k => $m) {
            $corps = $p1[$k] ?? null;
            if (!is_array($corps)) { $manque[$k]['ok'] = false; $err[$m['s']] = true; continue; }
            $euros($corps, $out[$m['w']][$m['s']]);
            $tot = (int) ($corps['pagination']['total'] ?? 0);
            for ($p = 2; $p <= min(FC_PAGES_MAX, (int) ceil($tot / 100)); $p++) { $suite[$k . '|' . $p] = $chemin($m['s'], $m['du'], $m['au'], $p); }
        }
        foreach (fcLireTous($suite) as $kp => $corps) {
            $k = substr((string) $kp, 0, (int) strrpos((string) $kp, '|'));
            $m = $manque[$k];
            if (!is_array($corps)) { $manque[$k]['ok'] = false; $err[$m['s']] = true; continue; }
            $euros($corps, $out[$m['w']][$m['s']]);
        }
        foreach ($manque as $m) { if ($m['ok']) { fcCacheEcrire($m['cle'], $out[$m['w']][$m['s']]); } }
    }
    return [$out, array_map('intval', array_keys($err))];
}

/**
 * Le bingo des clients du magasin `$sid` : [client => ['euros' => [€ par semaine, tous magasins], 'cases' => n]], à
 * partir de [semaine => [shop => [client => €]]]. Un client du magasin figure dans ses fiches une semaine au moins ; ses
 * achats s'additionnent d'un magasin à l'autre (« tous magasins »). Une case se coche dès `$seuil` € dans la semaine.
 */
function fcBingo(array $sem, int $sid, float $seuil, int $n): array
{
    $ici = []; $E = [];
    for ($w = 1; $w <= $n; $w++) {
        foreach ((array) ($sem[$w] ?? []) as $s => $L) {
            foreach ((array) $L as $id => $e) {
                $id = (int) $id;
                if ($id <= 0) { continue; }
                $E[$id] ??= array_fill(0, $n, 0.0);
                $E[$id][$w - 1] += (float) $e;
                if ((int) $s === $sid) { $ici[$id] = true; }
            }
        }
    }
    $out = [];
    foreach (array_keys($ici) as $id) {
        $eu = array_map(static fn ($v) => round($v, 2), $E[$id]);
        $out[$id] = ['euros' => $eu, 'cases' => count(array_filter($eu, static fn ($v) => $v >= $seuil - 0.001))];
    }
    return $out;
}

/**
 * GET /fidelite/cartes/bingo?shop= : la saison (semaines, thèmes, état) et ses règles, et pour chaque client du magasin
 * ses achats semaine par semaine (tous magasins) et ses cases cochées.
 */
function ep_fidelite_cartes_bingo(): array
{
    $sid = ctype_digit((string) ($_GET['shop'] ?? '')) ? (int) $_GET['shop'] : 0;
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant (?shop=)']; }
    $c = fcBingoConf();
    $regles = ['seuil' => (float) ($c['seuilEuros'] ?? 10), 'tousLes' => max(1, (int) ($c['tousLes'] ?? 3)),
        'bons' => array_values(array_map('floatval', (array) ($c['bons'] ?? []))), 'superBon' => (float) ($c['superBon']['valeur'] ?? 0)];
    $s = fcSaison(fcAujourdhui());
    if ($s === null) { return ['saison' => null, 'regles' => $regles, 'semaine' => 0, 'clients' => [], 'lu' => date('Y-m-d H:i')]; }
    $n = $s['enCours'] ?: $s['closes'];
    $shops = [$sid];
    foreach (function_exists('fidMagasins') ? fidMagasins() : [] as $m) { if ((int) $m['id'] > 0 && !in_array((int) $m['id'], $shops, true)) { $shops[] = (int) $m['id']; } }
    [$sem, $manques] = $n > 0 ? fcSemaines($s, $n, $shops) : [[], []];
    if (in_array($sid, $manques, true)) { return ['error' => 'les achats de la saison n’ont pas pu être lus dans l’ERP (compte admin, permission LOYALTY.MANAGE)']; }
    return ['saison' => $s, 'regles' => $regles, 'semaine' => $n, 'clients' => fcBingo($sem, $sid, $regles['seuil'], $n),
        'autresIllisibles' => count($manques), 'lu' => date('Y-m-d H:i')];
}
