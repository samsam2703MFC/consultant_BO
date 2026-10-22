<?php
declare(strict_types=1);

/**
 * Les promotions du magasin (demande du 17/10/2026).
 *
 * Un franchisé prépare une promotion « X achetés + Y » du panel pour son magasin, dans l'onglet Promotions du
 * dashboard magasin (un assistant en cinq étapes, calqué sur le formulaire « Buy X Get Y » du panel). PAS DE
 * VALIDATION de la marque (demande du 17/10/2026) : l'envoi l'enregistre aussitôt (`validee`), pourvu que les règles
 * tiennent ; un coefficient qu'un produit sans coût ni prix empêche de vérifier ne bloque pas, il se signale
 * (`auto: false`, `motif`). La marque la met en caisse (« Mise en caisse ✓ », en attendant l'écriture au panel) ou la
 * renvoie au magasin avec un mot. Les champs sont ceux du panel : ce que le client achète (X : quantité,
 * catégories ou produits, exclusions), ce qu'il reçoit (Y : quantité, offert / prix fixe / remise € / remise %,
 * catégories ou produits, exclusions, le moins cher d'abord), la période (du … au …, créneaux de la semaine), la
 * règle du ticket (répétable, nombre maximal par ticket). Statut, priorité, exclusivité et mode d'activation
 * restent à la marque.
 *
 * Une promotion vaut pour le magasin qui l'écrit, lui seul — celui du dashboard (?shop=), demande du 17/10/2026 :
 * `shops` vaut toujours [shop] (la forme du périmètre « magasins sélectionnés » du panel). Les règles se lisent encore
 * magasin par magasin sur `shops`, pour d'anciennes demandes à plusieurs magasins.
 *
 * Deux règles du magasin, vérifiées ici (le navigateur les montre, le serveur les impose) : une date de fin, un an au
 * plus (14 jours jusqu'au 18/10/2026 : une promotion se fait aussi à la semaine, au mois, à l'année) ; deux promotions du magasin en même temps au plus, magasin par magasin. Le COEFFICIENT DU LOT (prix payé
 * HT ÷ coût matière, le cas le plus serré des produits visés, dans chaque magasin couvert — prix et coûts de recette du
 * magasin, sinon du réseau, pmProduits) se calcule et s'affiche, ×2 en repère ; il ne bloque plus l'envoi (18/10/2026).
 *
 * LE PANEL SEUL (18/10/2026, demande de Sam : « API seule, plus de copie ») : une promotion du magasin n'existe qu'au
 * panel, le cockpit n'en garde rien (l'ancienne liste `promosMagasin` de `ceo_app_setting` n'est plus lue). Une
 * promotion du magasin = une « Buy X Get Y » du panel réservée à ce seul magasin (`SELECTED_SHOPS`, `shop_ids` =
 * [magasin]) ; active, elle est en caisse ; inactive, c'est un brouillon. Enregistrer = POST (créer) ou PUT (modifier)
 * à /admin/promotions/buy-x-get-y, garder en brouillon = la même écriture laissée INACTIVE, supprimer un brouillon =
 * DELETE. Les promotions du panel se lisent par pvRegles() (gardées 10 minutes, mises à jour à chaque écriture).
 */

const PM_COEF_MIN = 2.0;
const PM_JOURS_MAX = 366;   // un an au plus, une année bissextile comprise (18/10/2026 ; 14 jours avant)
const PM_EN_MEME_TEMPS = 2;
const PM_TYPES = ['FREE', 'FIXED_PRICE', 'FIXED_AMOUNT_DISCOUNT', 'PERCENTAGE_DISCOUNT'];
/**
 * Les variantes qu'un côté peut viser (17/10/2026) : la portion du panel (`portion_scope` de ses cibles, relu sur « 4
 * Quarts + 1 Gratuit » : {id_category: 81125, portion_scope: "ONE_QUARTER"}) et la fraction de la pièce qu'elle est.
 * Sans portion : toutes les variantes.
 */
const PM_PORTIONS = ['ONE_HALF' => 0.5, 'ONE_QUARTER' => 0.25];
const PM_VIVANTES = ['a_valider', 'validee', 'en_caisse'];

/** Les magasins qu'une demande couvre (les anciennes n'ont que `shop`). */
function pmShops(array $d): array
{
    $l = array_values(array_unique(array_filter(array_map('intval', (array) ($d['shops'] ?? [])), static fn ($s) => $s > 0)));
    return $l !== [] ? $l : [(int) ($d['shop'] ?? 0)];
}

/* --- ce que le navigateur envoie, rendu propre ------------------------------------------------ */

/** Une liste [{id, nom}] d'identifiants du panel, quarante au plus, sans doublon. */
function pmIds($l): array
{
    $out = []; $vus = [];
    foreach (is_array($l) ? $l : [] as $x) {
        $id = is_array($x) ? (int) ($x['id'] ?? 0) : (int) $x;
        if ($id <= 0 || isset($vus[$id])) { continue; }
        $vus[$id] = true;
        $out[] = ['id' => $id, 'nom' => is_array($x) ? mb_substr(trim((string) ($x['nom'] ?? '')), 0, 80) : ''];
        if (count($out) >= 40) { break; }
    }
    return $out;
}

/** Un côté de la promotion : X (ce qu'on achète) ou Y (ce qu'on reçoit, avec sa récompense). */
function pmCote($c, bool $y): array
{
    $c = is_array($c) ? $c : [];
    $out = ['q' => max(1, min(20, (int) ($c['q'] ?? 1))), 'cats' => pmIds($c['cats'] ?? []), 'prods' => pmIds($c['prods'] ?? []), 'exclus' => pmIds($c['exclus'] ?? [])];
    $por = strtoupper((string) ($c['portion'] ?? ''));
    $out['portion'] = isset(PM_PORTIONS[$por]) ? $por : null;
    if ($y) {
        $type = strtoupper((string) ($c['type'] ?? 'FREE'));
        $out['type'] = in_array($type, PM_TYPES, true) ? $type : 'FREE';
        $v = $c['valeur'] ?? null;
        $v = is_numeric(str_replace(',', '.', (string) $v)) ? round((float) str_replace(',', '.', (string) $v), 2) : null;
        $out['valeur'] = $out['type'] === 'FREE' ? null : $v;
    }
    return $out;
}

/** Les créneaux de la semaine : [{jours: [1..7], de: "HH:MM", a: "HH:MM"}], quatorze au plus. */
function pmCreneaux($l): array
{
    $out = [];
    $h = static fn ($v): ?string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
    foreach (is_array($l) ? $l : [] as $c) {
        if (!is_array($c)) { continue; }
        $jours = array_values(array_unique(array_filter(array_map('intval', (array) ($c['jours'] ?? [])), static fn ($j) => $j >= 1 && $j <= 7)));
        sort($jours);
        $de = $h($c['de'] ?? null); $a = $h($c['a'] ?? null);
        if ($jours === [] || $de === null || $a === null || $a <= $de) { continue; }
        $out[] = ['jours' => $jours, 'de' => $de, 'a' => $a];
        if (count($out) >= 14) { break; }
    }
    return $out;
}

/** Une date et une heure « AAAA-MM-JJ HH:MM », ou null. */
function pmQuand($v): ?string
{
    $v = str_replace('T', ' ', trim((string) $v));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $v .= ' 00:00'; }
    return preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
}

/** La demande telle qu'elle se garde, depuis le corps d'une requête. */
function pmPropre(array $b, int $shop): array
{
    $max = $b['maxParTicket'] ?? null;
    return [
        'shop' => $shop,
        // Le magasin qui l'écrit, lui seul : un `shops` envoyé par le navigateur est ignoré.
        'shops' => [$shop],
        'nom' => mb_substr(trim((string) ($b['nom'] ?? '')), 0, 80),
        'description' => mb_substr(trim((string) ($b['description'] ?? '')), 0, 500),
        'x' => pmCote($b['x'] ?? [], false),
        'y' => pmCote($b['y'] ?? [], true),
        'du' => pmQuand($b['du'] ?? ''),
        'au' => pmQuand($b['au'] ?? ''),
        'creneaux' => pmCreneaux($b['creneaux'] ?? []),
        'repetable' => !empty($b['repetable']),
        'maxParTicket' => is_numeric($max) && (int) $max > 0 ? min(50, (int) $max) : null,
    ];
}

/* --- le coefficient du lot ---------------------------------------------------------------- */

/** Les produits d'un côté parmi ceux du magasin : catégories ou produits nommés, moins les exclus. */
function pmEligibles(array $produits, array $cote): array
{
    $cats = array_column($cote['cats'], 'id'); $ids = array_column($cote['prods'], 'id'); $ex = array_column($cote['exclus'], 'id');
    $out = [];
    foreach ($produits as $p) {
        $pid = (int) ($p['id'] ?? 0);
        if ($pid <= 0 || in_array($pid, $ex, true)) { continue; }
        if (in_array($pid, $ids, true) || in_array((int) ($p['catId'] ?? 0), $cats, true)) { $out[$pid] = $p; }
    }
    return $out;
}

/**
 * Le prix HT d'un article de Y une fois la récompense appliquée (les valeurs se saisissent TTC, comme en caisse) ;
 * $f : la fraction de la pièce quand Y vise une portion (¼ : 0,25 — l'article est le quart).
 */
function pmRecompense(array $p, string $type, ?float $valeur, float $f = 1.0): float
{
    $ht = (float) $p['prixHt'] * $f; $tva = 1 + ((float) ($p['tva'] ?? 0)) / 100;
    return match ($type) {
        'FREE' => 0.0,
        'FIXED_PRICE' => max(0.0, min($ht, (float) $valeur / $tva)),
        'FIXED_AMOUNT_DISCOUNT' => max(0.0, $ht - (float) $valeur / $tva),
        'PERCENTAGE_DISCOUNT' => max(0.0, $ht * (1 - min(100.0, max(0.0, (float) $valeur)) / 100)),
        default => $ht,
    };
}

/**
 * Le coefficient du lot (prix payé HT ÷ coût matière) dans le cas le plus serré : x pièces d'un produit de X et
 * y pièces d'un produit de Y, la récompense sur la pièce la moins chère (la règle de la caisse) — une paire où
 * le produit de X est aussi dans Y et moins cher que celui de Y ne se présente pas en caisse, elle est écartée.
 * Rend {lot, sans, x, y, paires, sansCout: [noms], sansPrix: [noms], coutReseau: [noms]} ; lot = null quand rien ne se
 * chiffre. `coutReseau` : les produits visés chiffrés au coût du réseau, faute de coût calculé pour le magasin.
 */
function pmCoef(array $produits, array $x, array $y): array
{
    $chiffre = static fn ($p) => isset($p['prixHt'], $p['cout']) && $p['prixHt'] !== null && $p['cout'] !== null && (float) $p['prixHt'] > 0 && (float) $p['cout'] > 0;
    $X = pmEligibles($produits, $x); $Y = pmEligibles($produits, $y);
    $sansCout = []; $sansPrix = []; $reseau = [];
    foreach ($X + $Y as $p) {
        $nom = (string) ($p['nom'] ?? '');
        if (($p['prixHt'] ?? null) === null || (float) $p['prixHt'] <= 0) { $sansPrix[] = $nom; }
        elseif (($p['cout'] ?? null) === null || (float) $p['cout'] <= 0) { $sansCout[] = $nom; }
        elseif (($p['source'] ?? '') === 'reseau') { $reseau[] = $nom; }
    }
    $pire = null; $n = 0;
    // Une portion visée (¼, ½) : l'article est cette part de la pièce — prix et coût à la fraction (proportionnels).
    $fx = PM_PORTIONS[$x['portion'] ?? ''] ?? 1.0; $fy = PM_PORTIONS[$y['portion'] ?? ''] ?? 1.0;
    foreach ($X as $px) {
        if (!$chiffre($px)) { continue; }
        foreach ($Y as $py) {
            if (!$chiffre($py)) { continue; }
            if ($px['id'] !== $py['id'] && isset($Y[$px['id']]) && $fx * (float) $px['prixHt'] < $fy * (float) $py['prixHt']) { continue; }
            $n++;
            $cout = $x['q'] * $fx * (float) $px['cout'] + $y['q'] * $fy * (float) $py['cout'];
            $paye = $x['q'] * $fx * (float) $px['prixHt'] + $y['q'] * pmRecompense($py, $y['type'], $y['valeur'], $fy);
            $sans = $x['q'] * $fx * (float) $px['prixHt'] + $y['q'] * $fy * (float) $py['prixHt'];
            $c = $paye / $cout;
            if ($pire === null || $c < $pire['lot']) {
                $pire = ['lot' => round($c, 2), 'sans' => round($sans / $cout, 2), 'x' => (string) $px['nom'], 'y' => (string) $py['nom'], 'paye' => round($paye, 2), 'cout' => round($cout, 2)];
            }
        }
    }
    return ($pire ?? ['lot' => null, 'sans' => null, 'x' => null, 'y' => null, 'paye' => null, 'cout' => null])
        + ['paires' => $n, 'nX' => count($X), 'nY' => count($Y), 'sansCout' => array_slice(array_values(array_unique($sansCout)), 0, 12),
            'sansPrix' => array_slice(array_values(array_unique($sansPrix)), 0, 12), 'coutReseau' => array_slice(array_values(array_unique($reseau)), 0, 12)];
}

/**
 * Les produits du magasin pour le coefficient du lot : prix pratiqué HT et TTC, TVA, catégorie et coût de recette,
 * lus par /analyse/produits/coefficients. Le panel rend 0 quand il n'a pas encore calculé le coût d'une recette
 * POUR CE MAGASIN (17/10/2026 : un magasin entier sans coût, « Aucun produit visé n'a de coût de recette ») : le
 * coût du réseau prend alors la place (catalogueCouts : la moyenne des magasins du compte, sinon la recette de la
 * copie locale), marqué `source: reseau`. Rend null quand le panel ne rend rien.
 */
function pmProduits(int $shop): ?array
{
    static $memo = [];
    if (array_key_exists($shop, $memo)) { return $memo[$shop]; }
    return $memo[$shop] = pmProduitsLus($shop);
}

/** La lecture elle-même (pmProduits la garde pour la requête : l'exclusion et le coefficient la relisent). */
function pmProduitsLus(int $shop): ?array
{
    if (!function_exists('ep_analyse_produits_coefficients')) { return null; }
    $g = $_GET; $code = http_response_code();
    $_GET = ['shop' => (string) $shop];
    try { $r = ep_analyse_produits_coefficients(); } catch (Throwable $e) { $r = null; }
    finally { $_GET = $g; if (is_int($code)) { http_response_code($code); } }
    if (!is_array($r) || !isset($r['produits']) || !is_array($r['produits'])) { return null; }
    $reseau = [];
    if (function_exists('catalogueCouts')) { try { $reseau = catalogueCouts(); } catch (Throwable $e) { $reseau = []; } }
    $out = [];
    foreach ($r['produits'] as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) { continue; }
        $cout = isset($p['cout']) && is_numeric($p['cout']) && (float) $p['cout'] > 0 ? (float) $p['cout'] : null;
        $src = $cout !== null ? 'magasin' : null;
        $mat = $reseau[$id]['mat'] ?? null;
        if ($cout === null && is_numeric($mat) && (float) $mat > 0) { $cout = round((float) $mat, 4); $src = 'reseau'; }
        $out[] = ['id' => $id, 'nom' => (string) ($p['nom'] ?? ''), 'catId' => (int) ($p['catId'] ?? 0), 'cat' => (string) ($p['cat'] ?? ''),
            'prixTtc' => $p['prixTtc'] ?? null, 'prixHt' => $p['prixHt'] ?? null, 'tva' => $p['tva'] ?? null, 'cout' => $cout, 'source' => $src];
    }
    return $out;
}

/**
 * GET /exploitation/promos-magasin/produits?shop=4 — ce que l'assistant chiffre : les produits du magasin, leur prix
 * et leur coût de recette (du magasin, sinon du réseau). Le navigateur calcule le coefficient avec la même liste que
 * le serveur à l'envoi.
 */
function ep_promos_magasin_produits(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $l = pmProduits($shop);
    if ($l === null) { http_response_code(502); return ['error' => 'les produits du magasin ne se lisent pas au panel']; }
    $n = static fn (string $src) => count(array_filter($l, static fn ($p) => $p['source'] === $src));
    return ['shop' => (string) $shop, 'produits' => $l, 'nMagasin' => $n('magasin'), 'nReseau' => $n('reseau'),
        'nSansCout' => count(array_filter($l, static fn ($p) => $p['cout'] === null))];
}

/* --- les promotions par canal (18/10/2026, maquette C) ------------------------------------------------------------ */

/**
 * GET /exploitation/promos-magasin/canaux?shop=4&jours=30 (7 | 30) — où les promotions s'appliquent : au comptoir, en
 * click & collect, en livraison, promotion par promotion et jour par jour. Les tickets à remise gravés (`svP…`, `r`)
 * passent par la reconnaissance des promotions du panel (pvTicket) ; un ticket est du webshop quand une commande du panel
 * porte sa transaction (`id_transaction`), dans le canal de la commande (coCommandes). Une commande payée en ligne n'a
 * pas de ticket : comptée à part (`enLigne`), ses promotions ne se lisent pas encore. Lecture seule.
 */
function ep_promos_magasin_canaux(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $n = (int) ($_GET['jours'] ?? 30) === 7 ? 7 : 30;
    $jours = [];
    for ($i = $n - 1; $i >= 0; $i--) { $jours[] = date('Y-m-d', strtotime('-' . $i . ' day')); }
    $out = ['shop' => (string) $shop, 'jours' => $n, 'du' => $jours[0], 'au' => $jours[$n - 1]];
    if (!function_exists('pvRegles') || !function_exists('pvTicket')) { return $out + ['erreur' => 'la reconnaissance des promotions n’est pas chargée']; }
    $R = pvRegles();
    $catId = []; $catNom = function_exists('svCategories') ? svCategories() : [];
    if (function_exists('panelCatalogue')) { foreach ((array) (panelCatalogue()['produits'] ?? []) as $pid => $x) { if (!empty($x['catId'])) { $catId[(int) $pid] = (int) $x['catId']; } } }
    $cmd = function_exists('coCommandes') ? coCommandes($shop, $jours[0]) : null;
    $tickets = [];
    foreach ($jours as $j) { $g = setting('svP' . $shop . ':' . $j); $tickets[$j] = is_array($g) && array_key_exists('r', $g) ? (array) $g['r'] : null; }
    return $out + pmCanaux($tickets, $R['regles'], $cmd, $catId, $catNom, $shop) + ['erreur' => $R['erreur']];
}

/**
 * Le compte, sans lecture : $tickets {jour: [ticket à remise {t, mn, l}] | null (jour pas encore lu)}, les règles du panel
 * normalisées (pvNormaliser), les commandes du panel (coCommandes : canal, tx, statut ; null = pas lues).
 * Rend {promos: [{id, nom, type, texte, compt, cc, liv: {n, r, t}}], parJour: [{j, compt, web: {n, r}}], total,
 * joursSans, commandes, enLigne: {cc, liv}} — n = fois appliquée, r = remise, t = tickets.
 */
function pmCanaux(array $tickets, array $regles, ?array $cmd, array $catId, array $catNom, int $sid): array
{
    $tx = []; $enLigne = ['cc' => 0, 'liv' => 0];
    foreach ((array) $cmd as $c) {
        if (!is_array($c) || !in_array($c['canal'] ?? '', ['cc', 'liv'], true)) { continue; }
        if ((int) ($c['tx'] ?? 0) > 0) { $tx[(int) $c['tx']] = $c['canal']; } else { $enLigne[$c['canal']]++; }
    }
    $parId = [];
    foreach ($regles as $r) { $parId[$r['id']] = $r; }
    $zero = ['n' => 0, 'r' => 0.0, 't' => 0];
    $agg = []; $parJour = []; $sans = [];
    foreach ($tickets as $j => $L) {
        $J = ['j' => (string) $j, 'compt' => ['n' => 0, 'r' => 0.0], 'web' => ['n' => 0, 'r' => 0.0]];
        if ($L === null) { $sans[] = (string) $j; $parJour[] = $J + ['lu' => false]; continue; }
        $regJ = array_values(array_filter($regles, static fn ($r) => pvApplicable($r, $sid, (string) $j)));
        foreach ($L as $t) {
            if (!is_array($t) || !is_array($t['l'] ?? null)) { continue; }
            $res = pvTicket($t['l'], $regJ, $catId, $catNom);
            if ($res === []) { continue; }
            $canal = $tx[(int) ($t['t'] ?? 0)] ?? 'compt';
            foreach ($res as $rid => $x) {
                $rem = 0.0;
                foreach ((array) ($x['l'] ?? []) as $l) { $rem += (float) $l[2]; }
                $n = max(1, (int) ($x['fois'] ?? 0));
                $a =& $agg[(string) $rid];
                $a = $a ?? ['compt' => $zero, 'cc' => $zero, 'liv' => $zero];
                $a[$canal]['n'] += $n; $a[$canal]['r'] += $rem; $a[$canal]['t']++;
                unset($a);
                $w = $canal === 'compt' ? 'compt' : 'web';
                $J[$w]['n'] += $n; $J[$w]['r'] += $rem;
            }
        }
        $J['compt']['r'] = round($J['compt']['r'], 2); $J['web']['r'] = round($J['web']['r'], 2);
        $parJour[] = $J + ['lu' => true];
    }
    $promos = []; $total = ['compt' => $zero, 'cc' => $zero, 'liv' => $zero];
    foreach ($agg as $rid => $a) {
        $r = $parId[$rid] ?? $parId[(int) $rid] ?? null;
        foreach (['compt', 'cc', 'liv'] as $c) {
            $a[$c]['r'] = round($a[$c]['r'], 2);
            $total[$c]['n'] += $a[$c]['n']; $total[$c]['r'] += $a[$c]['r']; $total[$c]['t'] += $a[$c]['t'];
        }
        $promos[] = ['id' => $rid === 'x' ? 'x' : (int) $rid, 'nom' => $r['nom'] ?? 'Remise non reconnue', 'type' => $r['type'] ?? 'remise',
            'texte' => $r ? pvTexte($r) : 'remise sur la ligne qu’aucune promotion du panel n’explique'] + $a;
    }
    $somme = static fn ($p) => $p['compt']['n'] + $p['cc']['n'] + $p['liv']['n'];
    usort($promos, static fn ($a, $b) => (($a['id'] === 'x') <=> ($b['id'] === 'x')) ?: $somme($b) <=> $somme($a));
    foreach ($total as $c => $v) { $total[$c]['r'] = round($v['r'], 2); }
    return ['promos' => $promos, 'parJour' => $parJour, 'total' => $total, 'joursSans' => $sans, 'commandes' => $cmd !== null, 'enLigne' => $enLigne];
}

/** Le nom court d'un magasin (« Halle ») pour les messages. */
function pmNomShop(int $s): string
{
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$s]); } catch (Throwable $e) { $r = null; }
    return $r !== null ? (string) preg_replace('/^.* - /', '', (string) $r['name']) : 'magasin ' . $s;
}

/**
 * Les produits visés qui ne se chiffrent pas — sans coût de recette (ni du magasin ni du réseau) ou sans prix au panel —
 * sortent de la promotion d'office (17/10/2026) : le coefficient ×2 ne se vérifie pas pour eux, et rien ne part en caisse
 * sans l'avoir été. Choisis par catégorie, ils passent dans les exclusions ; choisis un à un, ils quittent la liste.
 * Rend [la promotion, les noms sortis].
 */
function pmExclureNonChiffres(array $d, array $produits): array
{
    $chiffre = static fn ($p) => ($p['prixHt'] ?? null) !== null && (float) $p['prixHt'] > 0 && ($p['cout'] ?? null) !== null && (float) $p['cout'] > 0;
    $noms = [];
    foreach (['x', 'y'] as $k) {
        $c = $d[$k];
        foreach (pmEligibles($produits, $c) as $pid => $p) {
            if ($chiffre($p)) { continue; }
            $noms[] = (string) ($p['nom'] ?? '');
            if (in_array($pid, array_map('intval', array_column($c['prods'], 'id')), true)) {
                $c['prods'] = array_values(array_filter($c['prods'], static fn ($o) => (int) $o['id'] !== $pid));
            }
            // Visé aussi par sa catégorie : il passe dans les exclusions.
            if (in_array((int) ($p['catId'] ?? 0), array_map('intval', array_column($c['cats'], 'id')), true)) {
                $c['exclus'][] = ['id' => $pid, 'nom' => (string) ($p['nom'] ?? '')];
            }
        }
        $d[$k] = $c;
    }
    return [$d, array_values(array_unique($noms))];
}

/**
 * Le coefficient du lot dans chaque magasin couvert, le plus serré gardé (avec `magasin`).
 * Rend [coef|null, magasins illisibles].
 */
function pmCoefMagasins(array $d): array
{
    $pire = null; $illisibles = []; $listes = ['sansCout' => [], 'sansPrix' => [], 'coutReseau' => []];
    foreach (pmShops($d) as $s) {
        $produits = pmProduits($s);
        if ($produits === null) { $illisibles[] = $s; continue; }
        $c = pmCoef($produits, $d['x'], $d['y']) + ['magasin' => $s];
        foreach ($listes as $k => $v) { $listes[$k] = array_merge($v, $c[$k]); }
        if ($pire === null || ($c['lot'] !== null && ($pire['lot'] === null || $c['lot'] < $pire['lot']))) { $pire = $c; }
    }
    // Un produit qui ne se chiffre pas dans UN des magasins compte : la demande ira à la marque.
    if ($pire !== null) { foreach ($listes as $k => $v) { $pire[$k] = array_slice(array_values(array_unique($v)), 0, 12); } }
    return [$pire, $illisibles];
}

/* --- les règles du magasin -------------------------------------------------------------------- */

/**
 * Ce qui manque ou dépasse pour envoyer une promotion : une liste de phrases (vide = prête). Un coefficient qui ne se
 * calcule pas n'y est pas : la promotion part alors à la marque (pmDOffice).
 */
function pmErreurs(array $d, array $autres, ?array $coef, string $maintenant): array
{
    $e = [];
    if ($d['nom'] === '') { $e[] = 'Donnez un nom à la promotion.'; }
    if (!$d['x']['cats'] && !$d['x']['prods']) { $e[] = 'Choisissez ce que le client achète (une catégorie ou des produits).'; }
    if (!$d['y']['cats'] && !$d['y']['prods']) { $e[] = 'Choisissez ce que le client reçoit (une catégorie ou des produits).'; }
    if ($d['y']['type'] !== 'FREE') {
        $v = $d['y']['valeur'];
        if ($v === null || $v <= 0) { $e[] = 'Donnez la valeur de l’avantage.'; }
        elseif ($d['y']['type'] === 'PERCENTAGE_DISCOUNT' && $v > 100) { $e[] = 'Une remise ne dépasse pas 100 %.'; }
    }
    if ($d['du'] === null) { $e[] = 'Donnez la date de début.'; }
    if ($d['au'] === null) { $e[] = 'Donnez la date de fin : au magasin, une promotion a toujours une fin.'; }
    if ($d['du'] !== null && $d['au'] !== null) {
        if ($d['au'] <= $d['du']) { $e[] = 'La fin vient après le début.'; }
        elseif (($nj = (int) round((strtotime(substr($d['au'], 0, 10)) - strtotime(substr($d['du'], 0, 10))) / 86400) + 1) > PM_JOURS_MAX) { $e[] = 'Période de ' . $nj . ' jours : ' . (PM_JOURS_MAX >= 365 ? 'un an' : PM_JOURS_MAX . ' jours') . ' au plus, raccourcissez-la.'; }
        if ($d['au'] <= $maintenant) { $e[] = 'La période est déjà passée.'; }
        foreach (pmShops($d) as $s) {
            $chev = array_filter($autres, static fn ($o) => in_array($s, pmShops($o), true) && in_array($o['statut'] ?? '', PM_VIVANTES, true) && ($o['du'] ?? '') < $d['au'] && ($o['au'] ?? '') > $d['du']);
            if (count($chev) >= PM_EN_MEME_TEMPS) {
                $e[] = PM_EN_MEME_TEMPS . ' promotions du magasin en même temps au plus' . (count(pmShops($d)) > 1 ? ' (' . pmNomShop($s) . ')' : '') . ' : « ' . implode(' », « ', array_map(static fn ($o) => (string) $o['nom'], $chev)) . ' » couvrent déjà ces dates.';
            }
        }
    }
    // Le coefficient du lot ne bloque plus (18/10/2026) : il se calcule, s'affiche (en rouge sous ×2), et la promotion part.
    return $e;
}

/**
 * Le coefficient est-il vérifié ? Oui quand il se calcule et que chaque produit visé est chiffré dans chaque magasin
 * couvert. Depuis le 18/10/2026, rien n'en dépend pour l'envoi : `auto` le dit, la promotion part au panel de toute façon.
 */
function pmDOffice(?array $coef): bool
{
    return $coef !== null && $coef['lot'] !== null && ($coef['sansCout'] ?? []) === [] && ($coef['sansPrix'] ?? []) === [];
}

/** Pourquoi le coefficient ne se calcule pas, en mots : les produits sans coût de recette, sans prix. */
function pmManqueTxt(array $c): string
{
    $l = static fn (array $n) => implode(', ', array_slice($n, 0, 4)) . (count($n) > 4 ? '…' : '');
    $m = [];
    if (($c['sansCout'] ?? []) !== []) { $m[] = 'sans coût de recette au panel, ni pour le magasin ni pour le réseau — ' . $l($c['sansCout']); }
    if (($c['sansPrix'] ?? []) !== []) { $m[] = 'sans prix au panel — ' . $l($c['sansPrix']); }
    return $m !== [] ? implode(' ; ', $m) : 'aucune combinaison de X et de Y ne se présente en caisse';
}

/* --- les promotions du panel qui touchent le magasin --------------------------------------------- */

/** L'état d'une promotion du panel aujourd'hui : active, a_venir, terminee, inactive. */
function pmEtatPanel(array $r, string $auj): string
{
    if (($r['au'] ?? null) !== null && $r['au'] < $auj) { return 'terminee'; }
    if (strtoupper((string) ($r['statut'] ?? '')) !== 'ACTIVE') { return 'inactive'; }
    return ($r['du'] ?? null) !== null && $r['du'] > $auj ? 'a_venir' : 'active';
}

/* --- les portions d'un produit (21/10/2026) --------------------------------------------------------------------- */

/**
 * La sorte d'une portion du panel (½ ou ¼), d'après son type (`ONE_QUARTER`…), sa fraction (0,25 ou 25) ou son libellé
 * (« ¼ », « 1/4 », « quart ») ; null pour une autre part. Les noms des champs ne sont pas documentés par le panel : on
 * lit les plus probables.
 */
function pmPortionType(array $x): ?string
{
    foreach (['type', 'portion_type', 'product_portion_type', 'portion_scope', 'scope', 'code', 'key'] as $k) {
        $v = strtoupper(trim((string) (is_scalar($x[$k] ?? null) ? $x[$k] : '')));
        if (isset(PM_PORTIONS[$v])) { return $v; }
        if ($v === 'HALF') { return 'ONE_HALF'; }
        if ($v === 'QUARTER') { return 'ONE_QUARTER'; }
    }
    foreach (['fraction', 'portion_fraction', 'ratio', 'share', 'part'] as $k) {
        if (isset($x[$k]) && is_numeric($x[$k]) && (float) $x[$k] > 0) {
            $f = (float) $x[$k]; if ($f > 1) { $f /= 100; }
            foreach (PM_PORTIONS as $t => $ff) { if (abs($f - $ff) < 0.01) { return $t; } }
        }
    }
    foreach (['label', 'name', 'display_name', 'product_portion_label'] as $k) {
        $v = mb_strtolower(trim((string) (is_scalar($x[$k] ?? null) ? $x[$k] : '')));
        if ($v === '') { continue; }
        if (preg_match('/½|\b1\s*\/\s*2\b|demi|half/u', $v)) { return 'ONE_HALF'; }
        if (preg_match('/¼|\b1\s*\/\s*4\b|quart|quarter/u', $v)) { return 'ONE_QUARTER'; }
    }
    return null;
}

/**
 * Les portions ½ et ¼ des produits, lues au panel : [pid => [type => n° de la portion]] — un produit sans ces portions
 * rend []. L'API d'abord (/products/{id}/portions, compte admin, sinon consultant), la table `product_portion` sinon.
 * Gardées un jour (`pmPortions`) ; un produit qu'aucune source ne rend n'est pas gardé.
 */
function pmPortionsDe(array $pids): array
{
    static $memo = [];
    $pids = array_values(array_unique(array_filter(array_map('intval', $pids), static fn ($p) => $p > 0)));
    $c = setting('pmPortions');
    $c = is_array($c) ? $c : [];
    $manque = [];
    foreach ($pids as $p) {
        if (isset($memo[$p])) { continue; }
        $g = $c[(string) $p] ?? null;
        if (is_array($g) && (int) ($g['ts'] ?? 0) > time() - 86400) { $memo[$p] = (array) ($g['t'] ?? []); } else { $manque[] = $p; }
    }
    if ($manque !== []) {
        $lus = pmPortionsLues($manque);
        foreach ($manque as $p) {
            $memo[$p] = $lus[$p] ?? [];
            if (isset($lus[$p])) { $c[(string) $p] = ['ts' => time(), 't' => (object) $lus[$p]]; }
        }
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['pmPortions', json_encode($c)]); }
        catch (Throwable $e) { /* relu la prochaine fois */ }
    }
    $out = [];
    foreach ($pids as $p) { $out[$p] = (array) ($memo[$p] ?? []); }
    return $out;
}

/** La lecture elle-même : [pid => [type => n°]] pour les produits qu'une source a rendus. */
function pmPortionsLues(array $pids): array
{
    $out = [];
    $prendre = static function (int $p, $corps) use (&$out): void {
        $l = is_array($corps) && isset($corps['portions']) && is_array($corps['portions']) ? $corps['portions'] : $corps;
        $l = function_exists('pvItems') ? pvItems($l) : (is_array($l) && array_is_list($l) ? $l : []);
        $t = [];
        foreach ($l as $x) {
            if (!is_array($x)) { continue; }
            if (($x['is_active'] ?? $x['active'] ?? true) === false || (string) ($x['is_active'] ?? $x['active'] ?? '1') === '0' || !empty($x['deleted_at'])) { continue; }
            $id = (int) ($x['id_product_portion'] ?? $x['product_portion_id'] ?? $x['id'] ?? 0);
            $ty = pmPortionType($x);
            if ($id > 0 && $ty !== null && !isset($t[$ty])) { $t[$ty] = $id; }
        }
        $out[$p] = $t;
    };
    $ch = [];
    foreach ($pids as $p) { $ch[$p] = '/products/' . $p . '/portions'; }
    $r = class_exists('ErpApi') && ErpApi::disponible() ? ErpApi::getParallele($ch, 4) : [];
    foreach ($pids as $p) { if (is_array($r[$p] ?? null)) { $prendre($p, $r[$p]); } }
    $reste = array_values(array_filter($pids, static fn ($p) => !isset($out[$p])));
    if ($reste !== [] && class_exists('PanelApi') && PanelApi::configured()) {
        $r = PanelApi::getParallele(array_intersect_key($ch, array_flip($reste)), 4);
        foreach ($reste as $p) { if (is_array($r[$p] ?? null)) { $prendre($p, $r[$p]); } }
        $reste = array_values(array_filter($reste, static fn ($p) => !isset($out[$p])));
    }
    if ($reste !== []) {
        try {
            $cols = array_column(Db::rows('SHOW COLUMNS FROM product_portion'), 'Field');
            $col = in_array('id_product', $cols, true) ? 'id_product' : (in_array('product_id', $cols, true) ? 'product_id' : null);
            if ($col !== null) {
                $par = [];
                foreach (Db::rows('SELECT * FROM product_portion WHERE ' . $col . ' IN (' . implode(',', array_map('intval', $reste)) . ')') as $x) { $par[(int) $x[$col]][] = $x; }
                foreach ($reste as $p) { $prendre($p, $par[$p] ?? []); }
            }
        } catch (Throwable $e) { /* pas de table : le produit reste sans portion connue */ }
    }
    return $out;
}

/** La sorte (½, ¼) de la portion n° $por du produit $pid, ou null. */
function pmPortionTypeDe(int $pid, int $por): ?string
{
    if ($pid <= 0 || $por <= 0) { return null; }
    $t = array_search($por, pmPortionsDe([$pid])[$pid] ?? [], true);
    return is_string($t) ? $t : null;
}

/**
 * Les cibles d'un côté qui vise une portion (21/10/2026) : chaque produit choisi, et chaque produit du magasin dans
 * les catégories choisies (hors exclusions), avec le n° de sa portion au panel. Un produit choisi sans cette portion
 * se dit ; un produit d'une catégorie qui ne l'a pas est passé. Rend [demande, erreurs].
 */
function pmCiblesPortions(array $d, int $shop): array
{
    $err = [];
    $produits = null;
    foreach (['x' => 'ce que le client achète', 'y' => 'ce que le client reçoit'] as $k => $mot) {
        $c = $d[$k] ?? [];
        $P = (string) ($c['portion'] ?? '');
        if ($P === '' || !isset(PM_PORTIONS[$P])) { continue; }
        $lib = $P === 'ONE_HALF' ? '½' : '¼';
        $exclus = array_flip(array_map(static fn ($o) => (int) $o['id'], (array) ($c['exclus'] ?? [])));
        $choisis = array_map(static fn ($o) => (int) $o['id'], (array) ($c['prods'] ?? []));
        $cats = array_flip(array_map(static fn ($o) => (int) $o['id'], (array) ($c['cats'] ?? [])));
        $deCat = [];
        if ($cats !== []) {
            $produits = $produits ?? (pmProduits($shop) ?? []);
            foreach ($produits as $p) { if (isset($cats[(int) $p['catId']]) && !isset($exclus[(int) $p['id']])) { $deCat[] = (int) $p['id']; } }
        }
        $tous = array_values(array_unique(array_merge($choisis, $deCat)));
        $por = pmPortionsDe($tous);
        $cibles = []; $sans = [];
        foreach ($tous as $pid) {
            $n = (int) ($por[$pid][$P] ?? 0);
            if ($n > 0) { $cibles[] = [$pid, $n]; } elseif (in_array($pid, $choisis, true)) { $sans[] = $pid; }
        }
        if ($sans !== []) {
            $noms = [];
            foreach ((array) ($c['prods'] ?? []) as $o) { if (in_array((int) $o['id'], $sans, true)) { $noms[] = trim((string) ($o['nom'] ?? '')) ?: ('produit ' . $o['id']); } }
            $err[] = 'Pas de portion ' . $lib . ' au panel pour ' . implode(', ', $noms) . ' (' . $mot . ') : retirez-les, ou visez la pièce entière.';
        } elseif ($cibles === []) {
            $err[] = 'Aucun produit de ' . $mot . ' n’a de portion ' . $lib . ' au panel.';
        }
        $d[$k]['cibles'] = $cibles;
    }
    return [$d, $err];
}

/* --- l'écriture au panel ----------------------------------------------------------------------------------- */

/**
 * Les créneaux de la semaine au format du panel (`schedule`) : une ligne par jour et par créneau, `day_of_week`,
 * `time_from`, `time_to` — les noms que le panel exige (18/10/2026, son refus : « schedule.0.time_from is required » ;
 * `start_time`/`end_time`, déduits du formulaire, étaient ignorés). L'heure avec secondes (« 15:00:00 ») ou sans
 * (« 15:00 ») : pmPanelEnvoyer essaie l'autre quand le panel refuse le format. Vérifiés à la relecture : un panel qui ne
 * reprend pas les créneaux laisse la promotion inactive.
 */
function pmPanelHoraire(array $creneaux, bool $secondes = true): array
{
    $h = static fn (string $v): string => $secondes ? $v . ':00' : $v;
    $out = [];
    foreach ($creneaux as $c) {
        foreach ($c['jours'] as $j) { $out[] = ['day_of_week' => (int) $j, 'time_from' => $h($c['de']), 'time_to' => $h($c['a'])]; }
    }
    return $out;
}

/**
 * Le corps d'une promotion « Buy X Get Y » du panel, au format de sa fiche (GET /admin/promotions/buy-x-get-y/{id},
 * relue le 17/10/2026 sur « 10 + 2 ») : le magasin seul (SELECTED_SHOPS), priorité 100, non exclusive, activation
 * automatique, la pièce la moins chère récompensée (CHEAPEST). Les valeurs de l'avantage sont celles de la caisse (TTC).
 */
function pmPanelCorps(array $d, string $statut, bool $secondes = true): array
{
    $ids = static fn (array $l): array => array_values(array_map(static fn ($o) => (int) $o['id'], $l));
    $cote = static function (array $c) use ($ids): array {
        $out = ['quantity' => (int) $c['q'], 'product_ids' => $ids($c['prods']), 'category_ids' => $ids($c['cats']), 'excluded_product_ids' => $ids($c['exclus'])];
        // Une portion visée (21/10/2026, l'API du panel) : la portion de chaque produit, par son identifiant —
        // `product_ids: [{id_product, id_product_portion}]`. Une catégorie ne porte pas de portion : ses produits la
        // remplacent (pmCiblesPortions, qui a mis les cibles dans `cibles`).
        if (!empty($c['portion']) && isset($c['cibles'])) {
            $out['product_ids'] = array_map(static fn ($t) => ['id_product' => (int) $t[0], 'id_product_portion' => (int) $t[1]], $c['cibles']);
            $out['category_ids'] = [];
        }
        return $out;
    };
    $y = $d['y'];
    return [
        'name' => $d['nom'], 'description' => ($d['description'] ?? '') !== '' ? $d['description'] : null,
        'promotion_type' => 'BUY_X_GET_Y', 'status' => $statut, 'priority' => 100, 'is_exclusive' => false,
        'valid_from' => $d['du'] !== null ? $d['du'] . ':00' : null, 'valid_to' => $d['au'] !== null ? $d['au'] . ':00' : null,
        'is_repeatable' => (bool) $d['repetable'], 'max_applications_per_transaction' => $d['repetable'] ? $d['maxParTicket'] : null,
        'shop_scope_type' => 'SELECTED_SHOPS', 'activation_mode' => 'AUTOMATIC', 'shop_ids' => [(int) $d['shop']],
        'schedule' => pmPanelHoraire($d['creneaux'] ?? [], $secondes),
        'trigger' => $cote($d['x']),
        'reward' => $cote($y) + ['type' => $y['type'], 'value' => $y['type'] === 'FREE' ? null : $y['valeur'], 'selection_strategy' => 'CHEAPEST'],
    ];
}

/** Le produit d'une cible du panel : un entier, ou {id_product | product_id | id, id_product_portion…}. */
function pmCibleProduit($x): int
{
    return is_array($x) ? (int) ($x['id_product'] ?? $x['product_id'] ?? $x['id'] ?? 0) : (int) $x;
}

/** La clé d'une cible produit : « 12 », ou « 12:77 » quand elle vise la portion n° 77 du produit 12. */
function pmCibleCle($x): string
{
    $por = is_array($x) ? (int) ($x['id_product_portion'] ?? $x['product_portion_id'] ?? 0) : 0;
    return pmCibleProduit($x) . ($por > 0 ? ':' . $por : '');
}

/** Ce que la fiche relue au panel ne reprend pas de ce qui a été envoyé : une liste de mots (vide = identique). */
function pmPanelEcarts(array $envoye, array $relu): array
{
    $e = [];
    $ens = static function ($l): array { $v = array_map('intval', (array) $l); sort($v); return $v; };
    if (trim((string) ($relu['name'] ?? '')) !== trim((string) $envoye['name'])) { $e[] = 'nom'; }
    if ($ens($relu['shop_ids'] ?? []) !== $ens($envoye['shop_ids'])) { $e[] = 'magasin'; }
    foreach (['valid_from' => 'début', 'valid_to' => 'fin'] as $k => $mot) {
        if (substr((string) ($relu[$k] ?? ''), 0, 16) !== substr((string) ($envoye[$k] ?? ''), 0, 16)) { $e[] = $mot; }
    }
    if ((bool) ($relu['is_repeatable'] ?? false) !== $envoye['is_repeatable']) { $e[] = 'répétable'; }
    foreach (['trigger' => 'ce qu’on achète', 'reward' => 'ce qu’on reçoit'] as $k => $mot) {
        $a = $envoye[$k]; $b = is_array($relu[$k] ?? null) ? $relu[$k] : [];
        if ((int) ($b['quantity'] ?? 0) !== $a['quantity']) { $e[] = $mot . ' (quantité)'; }
        foreach (['category_ids' => 'catégories', 'excluded_product_ids' => 'exclusions'] as $c => $m) {
            if ($ens(array_map('pmCibleProduit', (array) ($b[$c] ?? []))) !== $ens(array_map('pmCibleProduit', $a[$c]))) { $e[] = $mot . ' (' . $m . ')'; }
        }
        // Les produits avec leur portion (« 12:77 ») : relue sans sa portion, la cible viserait toutes les variantes.
        $cl = static function (array $l): array { $v = array_map('pmCibleCle', $l); sort($v); return $v; };
        $ra = $cl((array) $a['product_ids']); $rb = $cl((array) ($b['product_ids'] ?? []));
        if ($ra !== $rb) {
            $sans = static fn (array $l) => array_map(static fn ($x) => strtok($x, ':'), $l);
            $e[] = $mot . ($sans($ra) === $sans($rb) ? ' (portion)' : ' (produits)');
        }
    }
    $r = is_array($relu['reward'] ?? null) ? $relu['reward'] : [];
    if (strtoupper((string) ($r['type'] ?? '')) !== $envoye['reward']['type']) { $e[] = 'type d’avantage'; }
    if ($envoye['reward']['value'] !== null && abs((float) ($r['value'] ?? 0) - (float) $envoye['reward']['value']) > 0.005) { $e[] = 'valeur de l’avantage'; }
    if (count((array) ($relu['schedule'] ?? [])) !== count($envoye['schedule'])) { $e[] = 'créneaux'; }
    return $e;
}

/**
 * La promotion du magasin écrite au panel, sans que rien ne parte en caisse avant d'avoir été relu (17/10/2026) :
 * 1. créée INACTIVE (POST /admin/promotions/buy-x-get-y ; ou mise à jour, PUT …/{id}, quand un premier envoi l'a
 *    déjà créée) ; 2. relue (GET …/{id}) et comparée à ce qui a été envoyé ; 3. activée (PUT …/{id} status ACTIVE,
 *    sinon PATCH) et relue ACTIVE. Un écart, un refus : elle reste inactive au panel, et l'erreur se dit.
 * `$activer` faux (un brouillon) : écrite INACTIVE et relue, sans l'étape 3. Rend {ok, id, erreur}.
 */
function pmPanelEnvoyer(array $d, ?int $panelId, bool $activer = true): array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return ['ok' => false, 'id' => $panelId, 'erreur' => 'compte admin du panel indisponible']; }
    $base = '/admin/promotions/buy-x-get-y';
    $sec = true;
    // Modifier = PATCH …/{id} (21/10/2026 : la documentation de l'API ne connaît que PATCH sur une promotion), PUT en
    // repli pour un panel qui ne le prendrait pas.
    $maj = static function (int $id, array $corps) use ($base): array {
        $w = ErpApi::ecrire('PATCH', $base . '/' . $id, $corps);
        return $w['erreur'] !== null && in_array($w['code'], [404, 405], true) ? ErpApi::ecrire('PUT', $base . '/' . $id, $corps) : $w;
    };
    $corps = pmPanelCorps($d, 'INACTIVE', $sec);
    $w = $panelId !== null ? $maj($panelId, $corps) : ErpApi::ecrire('POST', $base, $corps);
    // L'heure des créneaux refusée pour son format (pas pour son absence) : une fois l'autre, avec ou sans secondes.
    if ($w['erreur'] !== null && ($d['creneaux'] ?? []) !== [] && preg_match('/time_(from|to)/', (string) $w['erreur']) === 1 && preg_match('/time_(from|to) is required/', (string) $w['erreur']) !== 1) {
        $sec = false;
        $corps = pmPanelCorps($d, 'INACTIVE', $sec);
        $w = $panelId !== null ? $maj($panelId, $corps) : ErpApi::ecrire('POST', $base, $corps);
    }
    if ($w['erreur'] !== null) { return ['ok' => false, 'id' => $panelId, 'erreur' => 'le panel refuse la promotion : ' . $w['erreur']]; }
    $c = is_array($w['corps']) ? $w['corps'] : [];
    $id = $panelId ?? (int) ($c['id'] ?? $c['data']['id'] ?? $c['promotion']['id'] ?? $c['data']['promotion']['id'] ?? 0);
    if ($id <= 0) { return ['ok' => false, 'id' => null, 'erreur' => 'le panel n’a pas rendu le numéro de la promotion créée']; }
    $lire = static function () use ($base, $id): ?array {
        $r = ErpApi::get($base . '/' . $id);
        return is_array($r) ? $r : null;
    };
    $relu = $lire();
    if ($relu === null) { return ['ok' => false, 'id' => $id, 'erreur' => 'créée inactive au panel (n° ' . $id . '), relecture impossible : ' . (ErpApi::$lastError ?? '')]; }
    $ecarts = pmPanelEcarts($corps, $relu);
    if ($ecarts !== []) { return ['ok' => false, 'id' => $id, 'erreur' => 'créée inactive au panel (n° ' . $id . ') : le panel ne reprend pas ' . implode(', ', $ecarts)]; }
    // Un brouillon : écrit et relu, il reste inactif.
    if (!$activer) { return ['ok' => true, 'id' => $id, 'erreur' => null]; }
    $a = $maj($id, pmPanelCorps($d, 'ACTIVE', $sec));
    if ($a['erreur'] !== null && in_array($a['code'], [404, 405], true)) { $a = ErpApi::ecrire('PATCH', $base . '/' . $id, ['status' => 'ACTIVE']); }
    if ($a['erreur'] !== null) { return ['ok' => false, 'id' => $id, 'erreur' => 'créée inactive au panel (n° ' . $id . '), activation refusée : ' . $a['erreur']]; }
    $relu = $lire();
    if ($relu === null || strtoupper((string) ($relu['status'] ?? '')) !== 'ACTIVE') { return ['ok' => false, 'id' => $id, 'erreur' => 'au panel (n° ' . $id . '), mais pas relue active']; }
    return ['ok' => true, 'id' => $id, 'erreur' => null];
}

/* --- les promotions du magasin, lues au panel -------------------------------------------------------------- */

/** Le numéro au panel d'une promotion du magasin, tel que le navigateur l'envoie (« p21 », « 21 », 21), ou null. */
function pmNumero($v): ?int
{
    $v = is_int($v) ? (string) $v : trim((string) $v);
    return preg_match('/^p?([1-9]\d{0,9})$/', $v, $m) ? (int) $m[1] : null;
}

/** Une règle du panel est-elle une promotion du magasin : « X achetés + Y » réservée à lui seul ? */
function pmEstDuMagasin(array $r, int $shop): bool
{
    return ($r['type'] ?? '') === 'buyxgety' && is_array($r['shops'] ?? null) && array_map('intval', $r['shops']) === [$shop];
}

/**
 * Une promotion du magasin relue au panel (une règle de pvRegles), dans la forme de l'assistant : {id: "p21", panelId,
 * shop, nom, description, x, y, du, au, creneaux, repetable, maxParTicket, statut: en_caisse (active au panel) |
 * brouillon (inactive)}. Les noms des produits et des catégories : ceux que le panel rend, sinon vides (le dashboard les
 * complète avec la liste du magasin).
 */
function pmDemande(array $r): array
{
    $cote = static function ($c): array {
        $c = is_array($c) ? $c : [];
        $prods = []; foreach ((array) ($c['p'] ?? []) as $k => $n) { $pid = (int) $k; if ($pid > 0) { $prods[$pid] = ['id' => $pid, 'nom' => (string) $n]; } }
        $cats = []; foreach ((array) ($c['c'] ?? []) as $k => $n) { if ((int) $k > 0) { $cats[] = ['id' => (int) $k, 'nom' => (string) $n]; } }
        return ['q' => max(1, (int) round((float) ($c['q'] ?? 1))), 'cats' => $cats, 'prods' => array_values($prods),
            'exclus' => array_map(static fn ($id) => ['id' => (int) $id, 'nom' => ''], array_values((array) ($c['x'] ?? []))),
            'portion' => isset(PM_PORTIONS[(string) ($c['portion'] ?? '')]) ? (string) $c['portion'] : null];
    };
    $rc = (array) ($r['recompense'] ?? []);
    $shop = (int) (((array) ($r['shops'] ?? []))[0] ?? 0);
    $type = in_array($rc['type'] ?? '', PM_TYPES, true) ? (string) $rc['type'] : 'FREE';
    return ['id' => 'p' . (int) $r['id'], 'panelId' => (int) $r['id'], 'shop' => $shop, 'shops' => [$shop],
        'nom' => (string) ($r['nom'] ?? ''), 'description' => (string) ($r['description'] ?? ''),
        'x' => $cote($r['declenche'] ?? []), 'y' => $cote($r['recoit'] ?? []) + ['type' => $type, 'valeur' => $type === 'FREE' ? null : ($rc['valeur'] ?? null)],
        'du' => $r['duH'] ?? (($r['du'] ?? null) !== null ? $r['du'] . ' 00:00' : null),
        'au' => $r['auH'] ?? (($r['au'] ?? null) !== null ? $r['au'] . ' 23:59' : null),
        'creneaux' => (array) ($r['creneaux'] ?? []), 'repetable' => !empty($r['repetable']), 'maxParTicket' => $r['maxParTicket'] ?? null,
        'statut' => strtoupper((string) ($r['statut'] ?? '')) === 'ACTIVE' ? 'en_caisse' : 'brouillon'];
}

/** Les promotions du magasin au panel (les plus récentes d'abord), et l'erreur de lecture du panel. */
function pmMiennes(int $shop): array
{
    $R = function_exists('pvRegles') ? pvRegles() : ['regles' => [], 'erreur' => 'lecture des promotions du panel indisponible'];
    $l = [];
    foreach ($R['regles'] as $r) { if (pmEstDuMagasin($r, $shop)) { $l[] = pmDemande($r); } }
    usort($l, static fn ($a, $b) => $b['panelId'] <=> $a['panelId']);
    return [$l, $R['erreur']];
}

/**
 * La promotion du magasin n° $id : dans les règles gardées, sinon relue au panel (créée il y a un instant ailleurs).
 * Null quand elle n'existe pas ou n'est pas celle de ce magasin.
 */
function pmMienne(int $shop, int $id): ?array
{
    [$l] = pmMiennes($shop);
    foreach ($l as $d) { if ($d['panelId'] === $id) { return $d; } }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return null; }
    $f = ErpApi::get('/admin/promotions/buy-x-get-y/' . $id);
    if (!is_array($f) || (int) ($f['id'] ?? 0) !== $id || !function_exists('pvNormaliser')) { return null; }
    $r = pvNormaliser($f);
    return pmEstDuMagasin($r, $shop) ? pmDemande($r) : null;
}

/** Relit au panel la promotion n° $id après une écriture, la garde à jour dans les règles, et la rend (forme de l'assistant). */
function pmRelue(int $id): ?array
{
    $f = ErpApi::get('/admin/promotions/buy-x-get-y/' . $id);
    if (!is_array($f) || (int) ($f['id'] ?? 0) !== $id) { return null; }
    if (function_exists('pvRetenir')) { pvRetenir($id, $f); }
    return function_exists('pvNormaliser') ? pmDemande(pvNormaliser($f)) : null;
}

/* --- les portions (½, ¼ d'une tarte) : la sonde -------------------------------------------------------------- */

/**
 * GET /ventes/promos/sonde-portions?shop=4[&q=tarte] — lecture seule (17/10/2026) : où le panel décrit les portions d'un
 * produit, avant de les proposer dans l'assistant (une promotion « sur une part », son coefficient à son prix et à son
 * coût). Trois endroits relus d'un coup : les lignes de /shops/{id}/products/available (les clés « portion »), les
 * chemins plausibles d'une fiche produit à l'API (consultant et admin), la table `product_portion` de la base.
 */
function ep_promos_sonde_portions(): array
{
    $shop = max(1, (int) ($_GET['shop'] ?? 4));
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? 'tarte')));
    $nom = static fn (array $l): string => (string) ($l['base_name'] ?? $l['name'] ?? $l['product_name'] ?? '');
    $out = ['shop' => $shop, 'q' => $q];
    $lignes = class_exists('PanelApi') && PanelApi::configured() ? PanelApi::produitsDisponibles($shop) : [];
    $vises = array_values(array_filter($lignes, static fn ($l) => $q !== '' ? str_contains(mb_strtolower($nom($l)), $q) : (int) ($l['is_divisible'] ?? 0) === 1));
    $cles = [];
    foreach ($lignes as $l) { foreach (array_keys($l) as $k) { if (preg_match('/portion|divisib|variant|size|unit|piece/i', (string) $k)) { $cles[$k] = true; } } }
    $out['available'] = ['n' => count($lignes), 'nVises' => count($vises), 'clesPortion' => array_keys($cles),
        'vises' => array_map(static function ($l) use ($nom) {
            $garde = ['id' => $l['id'] ?? $l['id_product'] ?? null, 'nom' => $nom($l)];
            foreach ($l as $k => $v) { if (preg_match('/portion|divisib|variant|size|unit|piece|price|recipe_cost/i', (string) $k)) { $garde[$k] = $v; } }
            return $garde;
        }, array_slice($vises, 0, 6))];
    $pid = (int) ($vises[0]['id'] ?? $vises[0]['id_product'] ?? 0);
    $out['pid'] = $pid;
    $extrait = static fn ($c) => is_array($c) ? mb_substr((string) json_encode($c, JSON_UNESCAPED_UNICODE), 0, 2500) : null;
    if ($pid > 0) {
        $api = [];
        foreach (['/products/' . $pid, '/products/' . $pid . '/portions', '/shops/' . $shop . '/products/' . $pid . '/portions/prices', '/shops/' . $shop . '/products/' . $pid, '/product-portions?product_id=' . $pid] as $c) {
            $r = PanelApi::sondeGet($c, 8);
            $api[$c] = ['code' => $r['code'] ?? null, 'extrait' => $extrait($r['corps'] ?? null)];
        }
        $out['apiConsultant'] = $api;
        if (class_exists('ErpApi') && ErpApi::disponible()) {
            $adm = [];
            foreach (['/products/' . $pid . '/portions', '/shops/' . $shop . '/products/' . $pid . '/portions/prices', '/admin/products/' . $pid, '/admin/products/' . $pid . '/portions', '/admin/product-portions?product_id=' . $pid] as $c) {
                $r = ErpApi::sondeGet($c);
                $adm[$c] = ['code' => $r['code'], 'extrait' => $extrait($r['corps'])];
            }
            $out['apiAdmin'] = $adm;
        }
    }
    try {
        $out['base'] = ['colonnes' => array_column(Db::rows('SHOW COLUMNS FROM product_portion'), 'Field'),
            'n' => (int) (Db::row('SELECT COUNT(*) n FROM product_portion')['n'] ?? 0), 'exemples' => Db::rows('SELECT * FROM product_portion LIMIT 8')];
    } catch (Throwable $e) { $out['base'] = ['erreur' => mb_substr($e->getMessage(), 0, 200)]; }
    // Ce que le cockpit en retient pour les promotions (21/10/2026) : la portion ½ et ¼ de chaque produit visé.
    $ids = array_values(array_filter(array_map(static fn ($l) => (int) ($l['id'] ?? $l['id_product'] ?? 0), array_slice($vises, 0, 6))));
    $out['retenu'] = $ids !== [] ? pmPortionsLues($ids) : [];
    return $out;
}

/* --- les commandes webshop payées en ligne : la sonde ---------------------------------------------------------- */

/** Ce qui identifie une personne, dans une clé : retiré de tout ce que la sonde rend. */
const PM_SONDE_PERSO = '/e?mail|phone|mobile|gsm|address|adresse|street|city|ville|zip|postal|first.?name|last.?name|full.?name|customer|client|user|note|comment|message|instruction|vat.?number|iban|card|token|password|secret|birth|gender/i';

/**
 * Une commande du panel sans rien de nominatif : une clé personnelle garde sa place, sa valeur devient « [retiré] ».
 * Au niveau de la commande, `name`, `title`, `label` sont retirés aussi (le nom du client) ; dans les lignes (products,
 * items, lines), le nom du produit reste.
 */
function pmSondePropre($x, bool $ligne = false)
{
    if (!is_array($x)) { return $x; }
    $o = [];
    foreach ($x as $k => $v) {
        if (is_string($k) && (preg_match(PM_SONDE_PERSO, $k) === 1 || (!$ligne && in_array(strtolower($k), ['name', 'nom', 'title', 'label'], true)))) { $o[$k] = '[retiré]'; continue; }
        $o[$k] = pmSondePropre($v, $ligne || (is_string($k) && preg_match('/^(products|items|lines|order_lines|articles|order_items)$/i', $k) === 1));
    }
    return $o;
}

/** Les chemins (« products.0.discount_value ») des clés qui répondent au motif, avec leur valeur quand elle est simple. */
function pmSondeChemins($x, string $motif, string $base = '', int $max = 40): array
{
    $out = [];
    if (!is_array($x)) { return $out; }
    foreach ($x as $k => $v) {
        $ch = $base === '' ? (string) $k : $base . '.' . $k;
        if (is_string($k) && preg_match($motif, $k) === 1) { $out[$ch] = is_array($v) ? '[' . count($v) . ']' : $v; }
        if (is_array($v)) { $out += pmSondeChemins($v, $motif, $ch, $max); }
        if (count($out) >= $max) { break; }
    }
    return array_slice($out, 0, $max, true);
}

/**
 * GET /ventes/promos/sonde-commandes?shop=4[&jours=14] — lecture seule (18/10/2026) : ce que le panel dit d'une commande
 * webshop payée en ligne (sans `id_transaction`, donc sans ticket de caisse), pour en compter les promotions comme celles
 * des tickets. Rend : les comptes (commandes, webshop, payées en ligne, encaissées au magasin), les clés d'une commande et
 * de ses lignes, où sont les remises et le paiement, deux commandes payées en ligne en exemple, et ce que rendent les
 * routes de détail plausibles d'une commande (compte consultant, puis admin). Rien de nominatif : noms, e-mails,
 * téléphones, adresses, notes sont retirés (pmSondePropre).
 */
function ep_promos_sonde_commandes(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API du panel non configuré']; }
    $jours = max(1, min(60, (int) ($_GET['jours'] ?? 14)));
    $depuis = date('Y-m-d', strtotime('-' . $jours . ' day'));
    $r = PanelApi::sondeGet('/shops/' . $shop . '/client-orders?date_from=' . $depuis, 25);
    $corps = is_array($r['corps'] ?? null) ? $r['corps'] : [];
    $L = function_exists('analyseListe') ? analyseListe($corps) : PanelApi::liste($corps);
    $out = ['shop' => $shop, 'depuis' => $depuis, 'liste' => ['chemin' => '/shops/' . $shop . '/client-orders?date_from=' . $depuis, 'code' => $r['code'] ?? null, 'erreur' => $r['erreur'] ?? null,
        'clesEnveloppe' => array_keys(array_diff_key($corps, array_flip(['data', 'items', 'results'])))]];
    $web = static function (array $c): bool {
        if (function_exists('coCanal')) { return coCanal($c) !== 'compt'; }
        return !empty($c['is_webshop']) || preg_match('/deliv|livr|ship/i', (string) ($c['fulfilment_mode'] ?? '')) === 1;
    };
    $enLigne = []; $nWeb = 0; $nEnc = 0; $clesC = []; $clesL = [];
    foreach ($L as $c) {
        if (!is_array($c) || !$web($c)) { continue; }
        $nWeb++;
        if ((int) ($c['id_transaction'] ?? 0) > 0) { $nEnc++; } else { $enLigne[] = $c; }
        foreach (array_keys($c) as $k) { $clesC[$k] = true; }
        foreach ((array) ($c['products'] ?? $c['items'] ?? $c['lines'] ?? []) as $l) { if (is_array($l)) { foreach (array_keys($l) as $k) { $clesL[$k] = true; } } }
    }
    $remise = '/discount|promo|remise|reduc|coupon|voucher|offer|reward|bundle|gift/i';
    $paie = '/pay|paid|transaction|stripe|mollie|amount|total|price|value/i';
    $out['comptes'] = ['commandes' => count($L), 'webshop' => $nWeb, 'payeesEnLigne' => count($enLigne), 'encaisseesAuMagasin' => $nEnc];
    $out['clesCommande'] = array_keys($clesC);
    $out['clesLigne'] = array_keys($clesL);
    $ex = array_slice($enLigne, 0, 3);
    $out['remises'] = pmSondeChemins(pmSondePropre($ex), $remise);
    $out['paiement'] = pmSondeChemins(pmSondePropre($ex), $paie);
    // Deux exemples entiers, nettoyés ; dix lignes au plus chacun.
    $out['exemples'] = array_map(static function ($c) {
        $p = pmSondePropre($c);
        foreach (['products', 'items', 'lines'] as $k) { if (isset($p[$k]) && is_array($p[$k])) { $p[$k] = array_slice($p[$k], 0, 10); } }
        return $p;
    }, array_slice($enLigne, 0, 2));
    // Le détail d'une commande payée en ligne : ses lignes portent-elles prix et remise ?
    $id = (int) ($enLigne[0]['id'] ?? $enLigne[0]['id_client_order'] ?? 0);
    $out['id'] = $id ?: null;
    if ($id > 0) {
        $resume = static function (array $r) use ($remise): array {
            $c = is_array($r['corps'] ?? null) ? $r['corps'] : null;
            $x = is_array($c['data'] ?? null) ? $c['data'] : $c;
            $lignes = is_array($x) ? (array) ($x['products'] ?? $x['items'] ?? $x['lines'] ?? []) : [];
            return ['code' => $r['code'] ?? null, 'erreur' => $r['erreur'] ?? null, 'cles' => is_array($x) ? array_slice(array_keys($x), 0, 60) : null,
                'remises' => is_array($x) ? pmSondeChemins(pmSondePropre($x), $remise) : [], 'lignes' => array_slice(pmSondePropre($lignes, true), 0, 4)];
        };
        $det = [];
        foreach (['/client-orders/' . $id, '/client-orders/' . $id . '?include=products', '/shops/' . $shop . '/client-orders/' . $id, '/shops/' . $shop . '/client-orders/' . $id . '?include=products,discounts'] as $ch) {
            $det[$ch] = $resume(PanelApi::sondeGet($ch, 10));
        }
        $out['detailConsultant'] = $det;
        if (class_exists('ErpApi') && ErpApi::disponible()) {
            $adm = [];
            foreach (['/admin/client-orders/' . $id, '/admin/orders/' . $id, '/admin/webshop/orders/' . $id] as $ch) { $adm[$ch] = $resume(ErpApi::sondeGet($ch)); }
            $out['detailAdmin'] = $adm;
        }
    }
    return $out;
}

/* --- les routes --------------------------------------------------------------------------------- */

/**
 * GET /exploitation/promos-magasin?shop=4 — les promotions du magasin (lues au panel : celles réservées à lui seul), les
 * autres promotions du panel qui le touchent (la marque), les règles.
 */
function ep_promos_magasin_demandes(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $auj = date('Y-m-d');
    // ?frais=1 (21/10/2026, Sam) : le panel relu à l'ouverture de l'onglet — une promotion créée ou changée au panel
    // se voit tout de suite, sans attendre les dix minutes de la lecture gardée.
    $R0 = function_exists('pvRegles') ? pvRegles(!empty($_GET['frais'])) : ['ts' => 0];
    [$demandes, $err] = pmMiennes($shop);
    $panel = [];
    if (function_exists('pvRegles')) {
        foreach (pvRegles()['regles'] as $r) {
            if (pmEstDuMagasin($r, $shop)) { continue; }
            if (($r['shops'] ?? null) !== null && !in_array($shop, (array) $r['shops'], true)) { continue; }
            $panel[] = ['id' => $r['id'], 'nom' => $r['nom'], 'type' => $r['type'], 'texte' => function_exists('pvTexte') ? pvTexte($r) : '', 'statut' => $r['statut'],
                'etat' => pmEtatPanel($r, $auj), 'du' => $r['du'], 'au' => $r['au'], 'reseau' => ($r['shops'] ?? null) === null, 'magasins' => $r['shops'] === null ? null : count($r['shops']),
                // De quoi la dupliquer en promotion du magasin : ce qu'on achète, ce qu'on reçoit, la récompense.
                'x' => $r['declenche'], 'y' => $r['recoit'], 'recompense' => $r['recompense']];
        }
    }
    $ts = (int) ($R0['ts'] ?? 0);
    return ['shop' => (string) $shop, 'demandes' => $demandes, 'panel' => $panel, 'erreurPanel' => $err, 'source' => 'panel',
        'lu' => $ts > 0 ? date('H:i', $ts) : null, 'luTs' => $ts, 'luJour' => $ts > 0 ? date('Y-m-d', $ts) : null,
        'regles' => ['coefMin' => PM_COEF_MIN, 'joursMax' => PM_JOURS_MAX, 'enMemeTemps' => PM_EN_MEME_TEMPS]];
}

/**
 * POST /exploitation/promos-magasin {shop, id? (« p21 » : la promotion n° 21 du panel), action: brouillon|envoyer, nom,
 * description, x, y, du, au, creneaux, repetable, maxParTicket} — écrit au panel, et rien ailleurs (18/10/2026).
 * « envoyer » : les règles du magasin (422 sinon), ce qui ne se chiffre pas sort d'office, puis créée (POST) ou modifiée
 * (PUT) INACTIVE, relue, activée : en caisse. « brouillon » : la même écriture laissée INACTIVE (une promotion en caisse
 * ne repasse pas en brouillon : 409). Le panel refuse : 502, rien n'est créé. Créée mais pas activée : 200 avec
 * `panelErreur`, elle reste inactive au panel (un brouillon).
 */
function wr_promos_magasin(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $action = ($b['action'] ?? 'brouillon') === 'envoyer' ? 'envoyer' : 'brouillon';
    $envoi = $action === 'envoyer';
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { http_response_code(503); return ['error' => 'compte admin du panel indisponible : rien ne s’enregistre sans lui']; }
    $d = pmPropre($b, $shop);
    $panelId = pmNumero($b['id'] ?? null);
    if (($b['id'] ?? null) !== null && ($b['id'] ?? '') !== '' && $panelId === null) { http_response_code(404); return ['error' => 'promotion introuvable']; }
    if ($panelId !== null) {
        $actuelle = pmMienne($shop, $panelId);
        if ($actuelle === null) { http_response_code(404); return ['error' => 'promotion introuvable']; }
        // En caisse, elle se modifie (les changements partent au panel sur la même promotion), elle ne repasse pas en brouillon.
        if ($actuelle['statut'] === 'en_caisse' && !$envoi) { http_response_code(409); return ['error' => 'déjà en caisse : enregistrez les changements, ils partent au panel (pas de brouillon)']; }
    }
    [$miennes] = pmMiennes($shop);
    $autres = array_values(array_filter($miennes, static fn ($o) => $o['panelId'] !== $panelId));
    // À l'enregistrement, ce qui ne se chiffre pas sort de la promotion (pmExclureNonChiffres).
    $exclusAuto = []; $saisie = $d;
    if ($envoi && ($produits = pmProduits($shop)) !== null) { [$d, $exclusAuto] = pmExclureNonChiffres($d, $produits); }
    [$coef, $illisibles] = pmCoefMagasins($d);
    if ($envoi) {
        $err = pmErreurs($saisie, $autres, $coef, date('Y-m-d H:i'));
        foreach ($illisibles as $s) { $err[] = 'Les prix et coûts de ' . pmNomShop($s) . ' ne se lisent pas au panel : réessayez dans un instant.'; }
        if ($err === [] && $coef !== null && $coef['lot'] === null) {
            $sortis = $exclusAuto !== [] ? ' (sortis, sans coût de recette ou sans prix : ' . implode(', ', array_slice($exclusAuto, 0, 6)) . (count($exclusAuto) > 6 ? '…' : '') . ')' : '';
            $err[] = ($coef['nX'] === 0 ? 'Rien de ce que le client achète ne se chiffre' : ($coef['nY'] === 0 ? 'Rien de ce que le client reçoit ne se chiffre' : 'Aucune combinaison ne se chiffre')) . $sortis . ' : choisissez d’autres produits.';
        }
    } else {
        // Un brouillon est déjà une promotion du panel (inactive) : ce que le panel exige y est (nom, X, Y, dates) ; il
        // ne compte pas dans les deux à la fois, et une période passée se corrige plus tard.
        $err = pmErreurs($saisie, [], null, '0000-00-00 00:00');
    }
    if ($err !== []) { http_response_code(422); return ['error' => 'à compléter', 'erreurs' => $err, 'coef' => $coef]; }
    // Une portion visée : la portion de chaque produit, lue au panel (21/10/2026).
    [$d, $errP] = pmCiblesPortions($d, $shop);
    if ($errP !== []) { http_response_code(422); return ['error' => 'à compléter', 'erreurs' => $errP, 'coef' => $coef]; }
    // Un envoi à la fois par magasin : un double clic ne crée pas deux promotions au panel (17/10/2026 : « Test » n° 20 et 21).
    $verrou = @fopen(sys_get_temp_dir() . '/cockpit-promo-magasin-' . $shop . '.lock', 'c');
    if ($verrou !== false && !flock($verrou, LOCK_EX | LOCK_NB)) {
        fclose($verrou);
        http_response_code(409);
        return ['error' => 'un envoi au panel est déjà en cours pour ce magasin : relisez dans un instant'];
    }
    try {
        $r = pmPanelEnvoyer($d, $panelId, $envoi);
        $dem = $r['id'] !== null ? pmRelue((int) $r['id']) : null;
    } finally {
        if ($verrou !== false) { flock($verrou, LOCK_UN); fclose($verrou); }
    }
    if ($r['id'] === null) { http_response_code(502); return ['error' => $r['erreur'] ?? 'le panel refuse la promotion', 'coef' => $coef]; }
    $dem = $dem ?? ($d + ['id' => 'p' . $r['id'], 'panelId' => (int) $r['id'], 'statut' => $r['ok'] && $envoi ? 'en_caisse' : 'brouillon']);
    return ['ok' => true, 'demande' => $dem, 'coef' => $coef, 'exclusAuto' => $exclusAuto, 'panelErreur' => $r['ok'] ? null : $r['erreur']];
}

/**
 * DELETE /exploitation/promos-magasin?shop=4&id=p21 — un brouillon du magasin (inactif au panel) se supprime au panel.
 * En caisse, elle ne se retire pas d'ici (409) : on la modifie, ou on avance sa fin.
 */
function wr_promos_magasin_retirer(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $id = pmNumero($_GET['id'] ?? '');
    if ($shop <= 0 || $id === null) { http_response_code(404); return ['error' => 'promotion introuvable']; }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { http_response_code(503); return ['error' => 'compte admin du panel indisponible']; }
    $d = pmMienne($shop, $id);
    if ($d === null) { http_response_code(404); return ['error' => 'promotion introuvable']; }
    if ($d['statut'] === 'en_caisse') { http_response_code(409); return ['error' => 'déjà en caisse : elle ne se retire plus d’ici'];  }
    $w = ErpApi::ecrire('DELETE', '/admin/promotions/buy-x-get-y/' . $id);
    if ($w['erreur'] !== null && (int) $w['code'] !== 404) {
        http_response_code(in_array((int) $w['code'], [405, 501], true) ? 409 : 502);
        return ['error' => in_array((int) $w['code'], [405, 501], true) ? 'le panel ne supprime pas ses promotions : elle y reste inactive' : 'le panel refuse : ' . $w['erreur']];
    }
    if (function_exists('pvRetenir')) { pvRetenir($id, null); }
    return ['ok' => true];
}

/**
 * GET /promos-magasin[?statut=en_caisse|brouillon] — côté marque (console › Analyse magasin › Promotions) : les
 * promotions de chaque magasin, lues au panel (celles réservées à un seul magasin), avec le coefficient du lot aux prix
 * et coûts d'aujourd'hui. Lecture seule : le magasin les écrit dans son dashboard, le panel les garde.
 */
function ep_promos_magasin_marque(): array
{
    $st = (string) ($_GET['statut'] ?? '');
    $R = function_exists('pvRegles') ? pvRegles() : ['regles' => [], 'erreur' => 'lecture des promotions du panel indisponible'];
    $l = [];
    foreach ($R['regles'] as $r) {
        if (($r['type'] ?? '') !== 'buyxgety' || !is_array($r['shops'] ?? null) || count($r['shops']) !== 1) { continue; }
        $d = pmDemande($r);
        if ($st !== '' && $d['statut'] !== $st) { continue; }
        [$d['coef']] = pmCoefMagasins($d);
        $l[] = $d;
    }
    usort($l, static fn ($a, $b) => strcmp((string) $b['du'], (string) $a['du']));
    $noms = [];
    foreach ($l as $d) { $noms[(string) $d['shop']] ??= pmNomShop($d['shop']); }
    return ['demandes' => $l, 'noms' => $noms, 'erreurPanel' => $R['erreur'], 'source' => 'panel',
        'regles' => ['coefMin' => PM_COEF_MIN, 'joursMax' => PM_JOURS_MAX, 'enMemeTemps' => PM_EN_MEME_TEMPS]];
}
