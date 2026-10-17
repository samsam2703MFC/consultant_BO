<?php
declare(strict_types=1);

/**
 * Les promotions du magasin (demande du 17/10/2026).
 *
 * Un franchisé prépare une promotion « X achetés + Y » du panel pour son magasin, dans l'onglet Promotions du
 * dashboard magasin (un assistant en six étapes, calqué sur le formulaire « Buy X Get Y » du panel) ; la marque la
 * valide ; elle part ensuite en caisse. Les champs sont ceux du panel : ce que le client achète (X : quantité,
 * catégories ou produits, exclusions), ce qu'il reçoit (Y : quantité, offert / prix fixe / remise € / remise %,
 * catégories ou produits, exclusions, le moins cher d'abord), la période (du … au …, créneaux de la semaine), la
 * règle du ticket (répétable, nombre maximal par ticket). Statut, priorité, exclusivité et mode d'activation
 * restent à la marque.
 *
 * Une demande vaut pour un ou plusieurs magasins (quatre au plus, le périmètre « magasins sélectionnés » du panel) :
 * `shop` est le magasin qui l'a écrite, `shops` tous ceux qu'elle couvre.
 *
 * Trois règles du magasin, vérifiées ici (le navigateur les montre, le serveur les impose) : une date de fin, 14 jours
 * au plus ; deux promotions du magasin en même temps au plus, magasin par magasin ; le COEFFICIENT DU LOT (prix payé
 * HT ÷ coût matière) à ×2 au moins, pour le cas le plus serré des produits visés, dans chaque magasin couvert (les prix
 * et coûts de recette du magasin, lus par /analyse/produits/coefficients). Un produit sans coût de recette ne se
 * chiffre pas : la marque le voit.
 *
 * Les demandes vivent dans `ceo_app_setting` (clé `promosMagasin`). Rien n'est encore écrit au panel : la mise en
 * caisse attend le format d'écriture de l'API, relu sur une promotion test (GET /ventes/promos/sonde-fiche).
 * Les promotions du panel qui touchent le magasin (toutes, marque comprise) se lisent par pvRegles().
 */

const PM_CLE = 'promosMagasin';
const PM_COEF_MIN = 2.0;
const PM_JOURS_MAX = 14;
const PM_EN_MEME_TEMPS = 2;
const PM_MAGASINS_MAX = 4;
const PM_TYPES = ['FREE', 'FIXED_PRICE', 'FIXED_AMOUNT_DISCOUNT', 'PERCENTAGE_DISCOUNT'];
const PM_VIVANTES = ['a_valider', 'validee', 'en_caisse'];

/* --- le stockage ------------------------------------------------------------------------ */

function pmLire(): array
{
    $c = setting(PM_CLE);
    if (is_string($c)) { $c = json_decode($c, true); }
    return is_array($c) ? array_values(array_filter($c, 'is_array')) : [];
}

function pmEcrire(array $l): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [PM_CLE, json_encode(array_values($l), JSON_UNESCAPED_UNICODE)]);
}

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
        'shops' => array_slice(array_values(array_unique(array_merge([$shop], array_filter(array_map('intval', (array) ($b['shops'] ?? [])), static fn ($s) => $s > 0)))), 0, PM_MAGASINS_MAX),
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

/** Le prix HT d'une pièce de Y une fois la récompense appliquée (les valeurs se saisissent TTC, comme en caisse). */
function pmRecompense(array $p, string $type, ?float $valeur): float
{
    $ht = (float) $p['prixHt']; $tva = 1 + ((float) ($p['tva'] ?? 0)) / 100;
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
    foreach ($X as $px) {
        if (!$chiffre($px)) { continue; }
        foreach ($Y as $py) {
            if (!$chiffre($py)) { continue; }
            if ($px['id'] !== $py['id'] && isset($Y[$px['id']]) && (float) $px['prixHt'] < (float) $py['prixHt']) { continue; }
            $n++;
            $cout = $x['q'] * (float) $px['cout'] + $y['q'] * (float) $py['cout'];
            $paye = $x['q'] * (float) $px['prixHt'] + $y['q'] * pmRecompense($py, $y['type'], $y['valeur']);
            $sans = $x['q'] * (float) $px['prixHt'] + $y['q'] * (float) $py['prixHt'];
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

/** Le nom court d'un magasin (« Halle ») pour les messages. */
function pmNomShop(int $s): string
{
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$s]); } catch (Throwable $e) { $r = null; }
    return $r !== null ? (string) preg_replace('/^.* - /', '', (string) $r['name']) : 'magasin ' . $s;
}

/**
 * Le coefficient du lot dans chaque magasin couvert, le plus serré gardé (avec `magasin`).
 * Rend [coef|null, magasins illisibles].
 */
function pmCoefMagasins(array $d): array
{
    $pire = null; $illisibles = [];
    foreach (pmShops($d) as $s) {
        $produits = pmProduits($s);
        if ($produits === null) { $illisibles[] = $s; continue; }
        $c = pmCoef($produits, $d['x'], $d['y']) + ['magasin' => $s];
        if ($pire === null || ($c['lot'] !== null && ($pire['lot'] === null || $c['lot'] < $pire['lot']))) { $pire = $c; }
    }
    return [$pire, $illisibles];
}

/* --- les règles du magasin -------------------------------------------------------------------- */

/** Ce qui manque ou dépasse pour envoyer une demande à la marque : une liste de phrases (vide = prête). */
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
        elseif ((strtotime(substr($d['au'], 0, 10)) - strtotime(substr($d['du'], 0, 10))) / 86400 + 1 > PM_JOURS_MAX) { $e[] = PM_JOURS_MAX . ' jours au plus.'; }
        if ($d['au'] <= $maintenant) { $e[] = 'La période est déjà passée.'; }
        foreach (pmShops($d) as $s) {
            $chev = array_filter($autres, static fn ($o) => in_array($s, pmShops($o), true) && in_array($o['statut'] ?? '', PM_VIVANTES, true) && ($o['du'] ?? '') < $d['au'] && ($o['au'] ?? '') > $d['du']);
            if (count($chev) >= PM_EN_MEME_TEMPS) {
                $e[] = PM_EN_MEME_TEMPS . ' promotions du magasin en même temps au plus' . (count(pmShops($d)) > 1 ? ' (' . pmNomShop($s) . ')' : '') . ' : « ' . implode(' », « ', array_map(static fn ($o) => (string) $o['nom'], $chev)) . ' » couvrent déjà ces dates.';
            }
        }
    }
    if ($coef !== null && $coef['lot'] !== null && $coef['lot'] < PM_COEF_MIN) {
        $e[] = 'Coefficient du lot ×' . number_format($coef['lot'], 2, ',', '') . ' (' . $coef['x'] . ' + ' . $coef['y'] . (isset($coef['magasin']) && count(pmShops($d)) > 1 ? ', ' . pmNomShop((int) $coef['magasin']) : '') . ') : ×' . number_format(PM_COEF_MIN, 0) . ' au moins.';
    }
    if ($coef !== null && $coef['lot'] === null && ($coef['nX'] + $coef['nY']) > 0 && $coef['paires'] === 0) {
        $e[] = 'Le coefficient ne se calcule pas : ' . pmManqueTxt($coef) . '.';
    }
    return $e;
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

/* --- les routes --------------------------------------------------------------------------------- */

/** GET /exploitation/promos-magasin?shop=4 — les demandes du magasin, les promotions du panel qui le touchent, les règles. */
function ep_promos_magasin_demandes(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $auj = date('Y-m-d');
    $demandes = array_values(array_filter(pmLire(), static fn ($d) => in_array($shop, pmShops($d), true)));
    usort($demandes, static fn ($a, $b) => strcmp((string) ($b['maj'] ?? ''), (string) ($a['maj'] ?? '')));
    $panel = []; $err = null;
    if (function_exists('pvRegles')) {
        $R = pvRegles(); $err = $R['erreur'];
        foreach ($R['regles'] as $r) {
            if (($r['shops'] ?? null) !== null && !in_array($shop, (array) $r['shops'], true)) { continue; }
            $panel[] = ['id' => $r['id'], 'nom' => $r['nom'], 'type' => $r['type'], 'texte' => function_exists('pvTexte') ? pvTexte($r) : '', 'statut' => $r['statut'],
                'etat' => pmEtatPanel($r, $auj), 'du' => $r['du'], 'au' => $r['au'], 'reseau' => ($r['shops'] ?? null) === null, 'magasins' => $r['shops'] === null ? null : count($r['shops']),
                // De quoi la dupliquer en promotion du magasin : ce qu'on achète, ce qu'on reçoit, la récompense.
                'x' => $r['declenche'], 'y' => $r['recoit'], 'recompense' => $r['recompense']];
        }
    }
    return ['shop' => (string) $shop, 'demandes' => $demandes, 'panel' => $panel, 'erreurPanel' => $err,
        'regles' => ['coefMin' => PM_COEF_MIN, 'joursMax' => PM_JOURS_MAX, 'enMemeTemps' => PM_EN_MEME_TEMPS, 'magasinsMax' => PM_MAGASINS_MAX]];
}

/**
 * POST /exploitation/promos-magasin {shop, id?, action: brouillon|envoyer, nom, description, x, y, du, au, creneaux,
 * repetable, maxParTicket} — un brouillon s'enregistre tel quel ; « envoyer » vérifie les règles (422 sinon) et
 * passe la demande « à valider » par la marque. Une demande validée ou en caisse ne se modifie plus d'ici.
 */
function wr_promos_magasin(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $action = ($b['action'] ?? 'brouillon') === 'envoyer' ? 'envoyer' : 'brouillon';
    $d = pmPropre($b, $shop);
    $tout = pmLire();
    $id = preg_match('/^pm[0-9a-z]{6,20}$/', (string) ($b['id'] ?? '')) ? (string) $b['id'] : null;
    $i = null;
    if ($id !== null) {
        foreach ($tout as $k => $o) { if (($o['id'] ?? '') === $id) { $i = $k; break; } }
        if ($i === null || !in_array($shop, pmShops($tout[$i]), true)) { http_response_code(404); return ['error' => 'promotion introuvable']; }
        if (in_array($tout[$i]['statut'] ?? '', ['validee', 'en_caisse'], true)) { http_response_code(409); return ['error' => 'déjà validée par la marque : elle ne se modifie plus d’ici']; }
    }
    if ($i !== null) { $d['shop'] = (int) $tout[$i]['shop']; $d['shops'] = array_slice(array_values(array_unique(array_merge([$d['shop']], $d['shops']))), 0, PM_MAGASINS_MAX); }
    $autres = array_values(array_filter($tout, static fn ($o) => ($o['id'] ?? '') !== $id));
    [$coef, $illisibles] = pmCoefMagasins($d);
    if ($action === 'envoyer') {
        $err = pmErreurs($d, $autres, $coef, date('Y-m-d H:i'));
        foreach ($illisibles as $s) { $err[] = 'Les prix et coûts de ' . pmNomShop($s) . ' ne se lisent pas au panel : réessayez dans un instant.'; }
        if ($err !== []) { http_response_code(422); return ['error' => 'à compléter', 'erreurs' => $err, 'coef' => $coef]; }
    }
    $now = date('Y-m-d H:i:s');
    $d += ['id' => $id ?? ('pm' . base_convert((string) (int) (microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(2))),
        'statut' => $action === 'envoyer' ? 'a_valider' : 'brouillon', 'coef' => $coef, 'maj' => $now,
        'cree' => $i !== null ? ($tout[$i]['cree'] ?? $now) : $now, 'envoyee' => $action === 'envoyer' ? $now : ($i !== null ? ($tout[$i]['envoyee'] ?? null) : null),
        'message' => $i !== null && $action === 'brouillon' ? ($tout[$i]['message'] ?? null) : null, 'panelId' => null];
    if ($i !== null) { $tout[$i] = $d; } else { $tout[] = $d; }
    pmEcrire($tout);
    return ['ok' => true, 'demande' => $d];
}

/** DELETE /exploitation/promos-magasin?shop=4&id=pm… — un brouillon, une demande refusée ou encore à valider se retire. */
function wr_promos_magasin_retirer(): array
{
    $shop = (int) ($_GET['shop'] ?? 0); $id = (string) ($_GET['id'] ?? '');
    $tout = pmLire();
    foreach ($tout as $k => $o) {
        if (($o['id'] ?? '') !== $id || !in_array($shop, pmShops($o), true)) { continue; }
        if (!in_array($o['statut'] ?? '', ['brouillon', 'refusee', 'a_valider'], true)) { http_response_code(409); return ['error' => 'validée par la marque : elle ne se retire plus d’ici']; }
        array_splice($tout, $k, 1);
        pmEcrire($tout);
        return ['ok' => true];
    }
    http_response_code(404);
    return ['error' => 'promotion introuvable'];
}

/** GET /promos-magasin[?statut=a_valider] — côté marque (console › Analyse magasin › Promotions) : les demandes de tous les magasins. */
function ep_promos_magasin_marque(): array
{
    $st = (string) ($_GET['statut'] ?? '');
    $l = array_values(array_filter(pmLire(), static fn ($d) => $st === '' || ($d['statut'] ?? '') === $st));
    usort($l, static fn ($a, $b) => strcmp((string) ($a['envoyee'] ?? $a['maj'] ?? ''), (string) ($b['envoyee'] ?? $b['maj'] ?? '')));
    // Les noms courts des magasins cités, pour la console.
    $noms = [];
    foreach ($l as $d) { foreach (pmShops($d) as $sid) { $noms[(string) $sid] ??= pmNomShop($sid); } }
    return ['demandes' => $l, 'noms' => $noms, 'regles' => ['coefMin' => PM_COEF_MIN, 'joursMax' => PM_JOURS_MAX, 'enMemeTemps' => PM_EN_MEME_TEMPS]];
}

/** PATCH /promos-magasin/{id} {decision: valider|refuser, message} — côté marque. Une refusée revient au magasin avec le mot. */
function wr_promos_magasin_decision(string $id): array
{
    $b = body();
    $dec = (string) ($b['decision'] ?? '');
    if (!in_array($dec, ['valider', 'refuser'], true)) { http_response_code(422); return ['error' => 'decision : valider ou refuser']; }
    $msg = mb_substr(trim((string) ($b['message'] ?? '')), 0, 500);
    if ($dec === 'refuser' && $msg === '') { http_response_code(422); return ['error' => 'un refus dit pourquoi']; }
    $tout = pmLire();
    foreach ($tout as $k => $o) {
        if (($o['id'] ?? '') !== $id) { continue; }
        if (($o['statut'] ?? '') !== 'a_valider') { http_response_code(409); return ['error' => 'cette demande n’attend pas de décision']; }
        $tout[$k]['statut'] = $dec === 'valider' ? 'validee' : 'refusee';
        $tout[$k]['message'] = $msg !== '' ? $msg : null;
        $tout[$k]['decidee'] = date('Y-m-d H:i:s');
        $tout[$k]['maj'] = $tout[$k]['decidee'];
        pmEcrire($tout);
        return ['ok' => true, 'demande' => $tout[$k]];
    }
    http_response_code(404);
    return ['error' => 'demande introuvable'];
}
