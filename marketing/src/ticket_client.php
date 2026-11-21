<?php
declare(strict_types=1);

/**
 * La fiche produits du ticket de caisse (21/11/2026, Sam : « une page que le client scanne sur son ticket de caisse,
 * adresse serveur + ticket : allergènes, composition, comment le cuire, comment le finir… tous les produits du ticket » ;
 * « le format du QR : le numéro de transaction, il est dans l'API ; tout le texte est dans l'API »).
 * Maquette et correspondance bloc → champ : marketing/docs/maquettes/ticket-fiche-produits/LISEZMOI.md.
 *
 * Routes (branchées dans shared/public/api/index.php) :
 *   GET /marketing/ticket/client?t={boutique}-{transaction}-{clé}[&lang=fr|nl]
 *       PUBLIQUE : le client n'a pas de session ; la clé (HMAC de la boutique et de la transaction, secret du cockpit)
 *       ouvre ce ticket et lui seul — changer le numéro ne mène à rien. Rend la boutique, sa langue, la transaction et,
 *       par produit, les champs que la page affiche, et eux seuls : jamais les coûts, marges ou stocks que les routes du
 *       panel rendent aussi.
 *   GET /marketing/ticket/essai/boutiques        (siège) les boutiques du panel, pour l'écran d'essai
 *   GET /marketing/ticket/essai/jour?shop=&date= (siège) les transactions d'un jour, chacune avec son lien signé
 *   GET /marketing/ticket/essai/lien?shop=&transaction= (siège) le lien signé d'une transaction
 *
 * Sources, toutes lues avec le compte du cockpit (PanelApi) :
 *   - la transaction : GET /shops/{s}/transactions/{t} (repli : /transactions/{t}?include=products) ;
 *   - la fiche technique de chaque produit : GET /products/{id}/technical-sheet/raw (comme le manuel Formation) ;
 *   - le catalogue de la boutique, en français et en néerlandais : GET /shops/{s}/products/available?include=webshop
 *     (noms et ingrédients traduits par les alias du panel, prix, photo, allergènes) ;
 *   - la boutique : GET /shops (nom, adresse, téléphone ; la langue se déduit du code postal, réglage ticketLangues).
 * Fiches et catalogues sont gardés TCL_DUREE dans le dossier privé du cockpit.
 */

const TCL_DUREE = 6 * 3600;
const TCL_ALLERGENES = ['cereals_gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk', 'nuts', 'celery',
    'mustard', 'sesame_seeds', 'sulphites', 'lupin', 'molluscs'];
/** Les noms d'allergènes de la fiche technique (en français) → les codes du webshop. */
const TCL_NOMS_ALLERGENES = ['céréales contenant du gluten' => 'cereals_gluten', 'gluten' => 'cereals_gluten', 'œufs' => 'eggs',
    'oeufs' => 'eggs', 'lait' => 'milk', 'graines de sésame' => 'sesame_seeds', 'sésame' => 'sesame_seeds',
    'fruits à coque' => 'nuts', 'moutarde' => 'mustard', 'arachides' => 'peanuts', 'poisson' => 'fish', 'soja' => 'soybeans',
    'crustacés' => 'crustaceans', 'céleri' => 'celery', 'sulfites' => 'sulphites', 'anhydride sulfureux et sulfites' => 'sulphites',
    'lupin' => 'lupin', 'mollusques' => 'molluscs'];

function tclRoutePublique(string $method, string $path): ?array
{
    if ($method === 'GET' && $path === '/marketing/ticket/client') {
        return ep_tcl_client((string) ($_GET['t'] ?? ''), (string) ($_GET['lang'] ?? ''));
    }
    return null;
}

function tclRoute(string $method, string $path): ?array
{
    if ($method !== 'GET' || !str_starts_with($path, '/marketing/ticket/essai/')) { return null; }
    return match ($path) {
        '/marketing/ticket/essai/boutiques' => ep_tcl_essai_boutiques(),
        '/marketing/ticket/essai/jour'      => ep_tcl_essai_jour((int) ($_GET['shop'] ?? 0), (string) ($_GET['date'] ?? '')),
        '/marketing/ticket/essai/lien'      => ep_tcl_essai_lien((int) ($_GET['shop'] ?? 0), (int) ($_GET['transaction'] ?? 0)),
        default => (static function (): array { http_response_code(404); return ['error' => 'route inconnue']; })(),
    };
}

// ---------------------------------------------------------------------------------------------------------------------
// La clé du QR
// ---------------------------------------------------------------------------------------------------------------------

/** Six caractères d'un HMAC de la boutique et de la transaction, avec le secret du cockpit. */
function tclCle(int $shop, int $transaction, string $secret): string
{
    return strtoupper(substr(hash_hmac('sha256', 'ticket-client:' . $shop . ':' . $transaction, $secret), 0, 6));
}

/** Le code imprimé dans le QR : {boutique}-{transaction}-{clé}. */
function tclCode(int $shop, int $transaction, string $secret): string
{
    return $shop . '-' . $transaction . '-' . tclCle($shop, $transaction, $secret);
}

/** Le code lu dans l'adresse → [boutique, transaction], ou null si le format ou la clé ne vont pas. */
function tclLireCode(string $code, string $secret): ?array
{
    if ($secret === '' || preg_match('/^(\d{1,6})-(\d{1,12})-([A-Za-z0-9]{6})$/', trim($code), $m) !== 1) { return null; }
    [$s, $t] = [(int) $m[1], (int) $m[2]];
    return hash_equals(tclCle($s, $t, $secret), strtoupper($m[3])) ? [$s, $t] : null;
}

// ---------------------------------------------------------------------------------------------------------------------
// Fonctions pures (testées par marketing/bin/tests_ticket_client.php)
// ---------------------------------------------------------------------------------------------------------------------

/** La langue d'une boutique belge par son code postal : la Flandre (1500–3999, 8000–9999) en néerlandais. */
function tclLangueCp(string $cp): string
{
    $n = (int) preg_replace('/\D/', '', $cp);
    return ($n >= 1500 && $n <= 3999) || ($n >= 8000 && $n <= 9999) ? 'nl' : 'fr';
}

/** « Ingrédients: A, B. » → « A, B » (l'étiquette du panel commence par le mot, dans sa langue). */
function tclSansPrefixe(string $t): string
{
    $t = trim((string) preg_replace('/^\s*(Ingrédients|Ingrediënten|Ingredients)\s*:\s*/iu', '', $t));
    return str_ends_with($t, '.') ? rtrim(substr($t, 0, -1)) : $t;
}

/** Les ingrédients d'une étiquette, séparés aux virgules hors parenthèses. */
function tclMorceaux(string $t): array
{
    $out = []; $prof = 0; $cur = '';
    foreach (preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch === '(') { $prof++; } elseif ($ch === ')') { $prof--; }
        if ($ch === ',' && $prof <= 0) { $out[] = trim($cur); $cur = ''; } else { $cur .= $ch; }
    }
    $out[] = trim($cur);
    return array_values(array_filter($out, static fn ($x) => $x !== ''));
}

/** Les codes d'allergènes d'une liste de noms (fiche technique, en français). */
function tclCodesAllergenes(array $noms): array
{
    $c = [];
    foreach ($noms as $n) { $k = TCL_NOMS_ALLERGENES[mb_strtolower(trim((string) $n))] ?? null; if ($k !== null) { $c[$k] = true; } }
    return array_values(array_filter(TCL_ALLERGENES, static fn ($a) => isset($c[$a])));
}

/**
 * La composition dite au client, dans chaque langue : [[ingrédient, [codes]], …] et `traduit`.
 * Source : l'étiquette du catalogue (label_ingredients, traduite par les alias du panel) ; les allergènes de chaque
 * ingrédient se lisent sur la recette de la fiche technique (nom français) puis se reportent par position, l'ordre étant
 * le même d'une langue à l'autre. Sans étiquette, les ingrédients de la recette.
 *
 * @param array $recette [['nom' => …, 'allergenes' => [codes]], …]
 * @param array $etiquettes ['fr' => label_ingredients, 'nl' => …]
 */
function tclComposition(array $recette, array $etiquettes): array
{
    $allerg = [];
    foreach ($recette as $e) { $allerg[mb_strtolower(trim((string) $e['nom']))] = $e['allergenes']; }
    $fr = tclMorceaux(tclSansPrefixe((string) ($etiquettes['fr'] ?? '')));
    $out = [];
    if ($fr === []) {
        $items = array_map(static fn ($e) => [trim((string) $e['nom']), $e['allergenes']], $recette);
        foreach (['fr', 'nl'] as $lg) { $out[$lg] = ['items' => $items, 'traduit' => $lg === 'fr']; }
        return $out;
    }
    $codes = array_map(static fn ($x) => $allerg[mb_strtolower($x)] ?? [], $fr);
    $out['fr'] = ['items' => array_map(null, $fr, $codes), 'traduit' => true];
    $nl = tclMorceaux(tclSansPrefixe((string) ($etiquettes['nl'] ?? '')));
    $out['nl'] = count($nl) === count($fr)
        ? ['items' => array_map(null, $nl, $codes), 'traduit' => true]
        : ['items' => $out['fr']['items'], 'traduit' => false];
    return $out;
}

/** Le premier nombre trouvé sous une des clés. */
function tclNombre(array $a, array $cles): ?float
{
    foreach ($cles as $k) { if (isset($a[$k]) && is_numeric($a[$k])) { return (float) $a[$k]; } }
    return null;
}

/** La date d'une transaction, sous la première clé qui en porte une. */
function tclDateTransaction(array $t): ?string
{
    foreach (['created_at', 'date', 'transaction_date', 'datetime', 'sold_at', 'paid_at', 'closed_at', 'timestamp'] as $k) {
        if (!empty($t[$k]) && is_string($t[$k]) && strtotime($t[$k]) !== false) { return date('Y-m-d\TH:i:s', (int) strtotime($t[$k])); }
    }
    return null;
}

/**
 * Les lignes d'une transaction : [['id' => produit, 'qte' => …, 'prix' => prix unitaire TTC ou null, 'nom' => …], …].
 * La forme exacte de la réponse du panel n'est pas décrite au swagger : on lit les clés usuelles (id_product,
 * quantity, prix TTC) et l'écran d'essai montre les clés reçues.
 */
function tclLignesTransaction(array $t): array
{
    $lignes = [];
    foreach (['products', 'lines', 'items', 'transaction_products'] as $k) { if (isset($t[$k]) && is_array($t[$k])) { $lignes = $t[$k]; break; } }
    $out = [];
    foreach ($lignes as $l) {
        if (!is_array($l)) { continue; }
        $id = (int) ($l['id_product'] ?? $l['product_id'] ?? ($l['product']['id'] ?? 0));
        if ($id <= 0) { continue; }
        $qte = tclNombre($l, ['quantity', 'qty', 'amount']) ?? 1.0;
        $total = tclNombre($l, ['total_gross', 'total_price_gross', 'value_gross', 'total', 'value']);
        $unit = tclNombre($l, ['price_gross', 'portion_price_gross', 'unit_price_gross', 'price', 'unit_price', 'portion_price']);
        if ($unit === null && $total !== null && $qte > 0) { $unit = $total / $qte; }
        $nom = (string) ($l['name'] ?? $l['product_name'] ?? ($l['product']['name'] ?? ''));
        // Deux lignes du même produit (deux passages en caisse) ne font qu'une fiche.
        if (isset($out[$id])) { $out[$id]['qte'] += $qte; continue; }
        $out[$id] = ['id' => $id, 'qte' => $qte, 'prix' => $unit, 'nom' => trim($nom)];
    }
    return array_values($out);
}

/** Les champs de la fiche technique que la page affiche, et eux seuls. */
function tclResumeFiche(array $d): array
{
    $o = is_array($d['object'] ?? null) ? $d['object'] : [];
    $r = is_array($d['recipe'] ?? null) ? $d['recipe'] : [];
    $recette = [];
    foreach (is_array($r['elements'] ?? null) ? $r['elements'] : [] as $e) {
        if (!is_array($e) || !empty($e['is_part_of_package'])) { continue; }
        $recette[] = ['nom' => trim((string) ($e['name'] ?? '')), 'allergenes' => tclCodesAllergenes(is_array($e['allergens'] ?? null) ? $e['allergens'] : [])];
    }
    $etapes = [];
    foreach (is_array($d['preparation']['steps'] ?? null) ? $d['preparation']['steps'] : [] as $s) {
        $x = trim((string) (is_array($s) ? ($s['step_description'] ?? '') : $s));
        if ($x !== '') { $etapes[] = $x; }
    }
    $cuisson = [];
    foreach (is_array($d['competences'] ?? null) ? $d['competences'] : (is_array($o['required_competences'] ?? null) ? $o['required_competences'] : []) as $c) {
        $n = (string) ($c['name'] ?? ''); $x = trim((string) ($c['description'] ?? ''));
        if ($x !== '' && str_contains(mb_strtolower($n), 'cuisson')) { $cuisson[] = $x; }
    }
    $photo = '';
    foreach (['main_photo_path', 'shop_photo_path', 'photo_1_path'] as $k) { if (!empty($d['photos'][$k])) { $photo = (string) $d['photos'][$k]; break; } }
    $num = static fn ($v) => is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    return [
        'nom' => trim((string) ($o['name'] ?? '')),
        'categorie' => trim((string) ($o['category_name'] ?? '')),
        'poids' => $num($o['single_weight'] ?? null),
        'vegetarien' => !empty($o['is_vegetarian']),
        'vieMinutes' => $num($o['shelf_life_minutes'] ?? null),
        'stockage' => trim((string) ($o['storage_description'] ?? '')),
        'rechauffe' => $num($o['reheating_time_minutes'] ?? null) !== null || $num($o['reheating_temperature_celsius'] ?? null) !== null
            ? ['minutes' => $num($o['reheating_time_minutes'] ?? null), 'degres' => $num($o['reheating_temperature_celsius'] ?? null)] : null,
        'webshop' => !empty($o['webshop_active']),
        'prixConseille' => $num($o['suggested_sale_price'] ?? null),
        'recette' => $recette,
        'etapes' => $etapes,
        'cuisson' => $cuisson,
        'photo' => $photo,
    ];
}

/** Les champs du catalogue que la page affiche, par produit. */
function tclResumeCatalogue(array $liste): array
{
    $out = [];
    foreach ($liste as $p) {
        if (!is_array($p) || empty($p['id'])) { continue; }
        $groupes = $p['category']['groups'] ?? [];
        $out[(string) $p['id']] = [
            'nom' => trim((string) ($p['name'] ?? '')),
            'groupe' => trim((string) (is_array($groupes) && isset($groupes[0]['name']) ? $groupes[0]['name'] : '')),
            'ingredients' => (string) ($p['label_ingredients'] ?? ($p['webshop']['ingredients'] ?? '')),
            'allergenes' => array_values(array_filter(is_array($p['webshop']['allergen_codes'] ?? null) ? $p['webshop']['allergen_codes'] : [], 'is_string')),
            'prix' => is_numeric($p['portion_price_gross'] ?? null) ? (float) $p['portion_price_gross'] : null,
            'image' => (string) ($p['webshop_image']['url'] ?? ''),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------------
// Lectures du panel, gardées dans le dossier privé
// ---------------------------------------------------------------------------------------------------------------------

function tclDossier(): ?string
{
    static $d = false;
    if ($d !== false) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__, 2) . '/var/prive']) as $c) {
        $f = rtrim($c, '/') . '/ticket-client';
        if ((is_dir($f) || @mkdir($f, 0700, true)) && is_writable($f)) { return $d = $f; }
    }
    return $d = null;
}

function tclCacheLire(string $nom): ?array
{
    $dir = tclDossier();
    $f = $dir === null ? '' : $dir . '/' . $nom . '.json';
    if ($f === '' || !is_file($f) || filemtime($f) < time() - TCL_DUREE) { return null; }
    $j = json_decode((string) file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function tclCacheEcrire(string $nom, array $v): void
{
    $dir = tclDossier();
    if ($dir === null) { return; }
    $tmp = $dir . '/' . $nom . '.json.' . getmypid();
    if (@file_put_contents($tmp, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) { @rename($tmp, $dir . '/' . $nom . '.json'); }
}

/** Le catalogue d'une boutique dans une langue (résumé), gardé six heures. */
function tclCatalogue(int $shop, string $lg): array
{
    $nom = 'catalogue-' . $shop . '-' . $lg;
    if (($c = tclCacheLire($nom)) !== null) { return $c; }
    $r = PanelApi::get('/shops/' . $shop . '/products/available?webshop_active=1&lang_code=' . $lg . '&alias_type=webshop&include=webshop');
    $c = tclResumeCatalogue(is_array($r) ? analyseListe($r) : []);
    if ($c !== []) { tclCacheEcrire($nom, $c); }
    return $c;
}

/** Les fiches techniques (résumées) des produits, gardées six heures ; une fiche illisible manque, sans plus. */
function tclFiches(array $ids): array
{
    $out = []; $aLire = [];
    foreach ($ids as $id) {
        $c = tclCacheLire('fiche-' . $id);
        if ($c !== null) { $out[$id] = $c; } else { $aLire[$id] = '/products/' . $id . '/technical-sheet/raw?lang_code=fr'; }
    }
    if ($aLire !== []) {
        foreach (PanelApi::getParallele($aLire, 6, 20) as $id => $d) {
            if (!is_array($d) || !is_array($d['object'] ?? null)) { continue; }
            // La photo du panel est une adresse signée pour une heure : gardée avec son heure de lecture.
            $f = tclResumeFiche($d) + ['photoLe' => time()];
            tclCacheEcrire('fiche-' . $id, $f);
            $out[$id] = $f;
        }
    }
    foreach ($out as $id => $f) { if (time() - (int) ($f['photoLe'] ?? 0) > 3000) { $out[$id]['photo'] = ''; } }
    return $out;
}

/** Les boutiques du panel : nom, adresse, téléphone, langue. */
function tclBoutiques(): array
{
    if (($c = tclCacheLire('boutiques')) !== null) { return $c; }
    $r = PanelApi::get('/shops');
    $langues = setting('ticketLangues', []);
    $out = [];
    foreach (is_array($r) ? analyseListe($r) : [] as $s) {
        $id = (int) ($s['id'] ?? 0);
        if ($id <= 0) { continue; }
        $cp = trim((string) ($s['zip'] ?? $s['postal_code'] ?? ''));
        $out[(string) $id] = [
            'id' => $id,
            'nom' => trim((string) ($s['name'] ?? '')),
            'rue' => trim(implode(' ', array_filter([trim((string) ($s['street'] ?? '')), trim((string) ($s['street_number'] ?? ''))]))),
            'cp' => $cp,
            'ville' => trim((string) ($s['city'] ?? '')),
            'tel' => trim((string) ($s['phone'] ?? '')),
            'langue' => is_array($langues) && in_array($langues[(string) $id] ?? '', ['fr', 'nl'], true) ? $langues[(string) $id] : tclLangueCp($cp),
        ];
    }
    if ($out !== []) { tclCacheEcrire('boutiques', $out); }
    return $out;
}

/** La transaction brute du panel (la route de la boutique d'abord), ou null. */
function tclTransaction(int $shop, int $transaction): ?array
{
    $r = PanelApi::get('/shops/' . $shop . '/transactions/' . $transaction);
    if (is_array($r) && isset($r['data']) && is_array($r['data']) && !array_is_list($r['data'])) { $r = $r['data']; }
    if (!is_array($r) || tclLignesTransaction($r) === []) {
        $r2 = PanelApi::get('/transactions/' . $transaction . '?include=products');
        if (is_array($r2) && isset($r2['data']) && is_array($r2['data']) && !array_is_list($r2['data'])) { $r2 = $r2['data']; }
        if (is_array($r2)) { $r = $r2; }
    }
    if (!is_array($r)) { return null; }
    // La transaction doit être celle de la boutique du code : sinon, rien.
    $s = $r['id_shop'] ?? $r['shop_id'] ?? null;
    return $s !== null && (int) $s !== $shop ? null : $r;
}

// ---------------------------------------------------------------------------------------------------------------------
// Les réponses
// ---------------------------------------------------------------------------------------------------------------------

function ep_tcl_client(string $code, string $lang): array
{
    $lu = tclLireCode($code, authSecret());
    if ($lu === null) { http_response_code(404); return ['error' => 'ticket introuvable']; }
    [$shop, $tx] = $lu;
    $t = tclTransaction($shop, $tx);
    if ($t === null) { http_response_code(404); return ['error' => 'ticket introuvable']; }
    $boutique = tclBoutiques()[(string) $shop] ?? ['id' => $shop, 'nom' => '', 'rue' => '', 'cp' => '', 'ville' => '', 'tel' => '', 'langue' => 'fr'];
    $lignes = tclLignesTransaction($t);
    $fiches = tclFiches(array_column($lignes, 'id'));
    $cat = ['fr' => tclCatalogue($shop, 'fr'), 'nl' => tclCatalogue($shop, 'nl')];
    $travailles = [];
    foreach ($cat['fr'] as $p) { foreach ($p['allergenes'] as $a) { $travailles[$a] = true; } }

    $out = [];
    foreach ($lignes as $l) {
        $id = (string) $l['id'];
        $f = $fiches[$l['id']] ?? null;
        $c = ['fr' => $cat['fr'][$id] ?? null, 'nl' => $cat['nl'][$id] ?? null];
        $recette = $f['recette'] ?? [];
        $codes = [];
        foreach ($recette as $e) { foreach ($e['allergenes'] as $a) { $codes[$a] = true; } }
        foreach ($c as $x) { foreach ($x['allergenes'] ?? [] as $a) { $codes[$a] = true; } }
        $prix = $l['prix'] ?? $c['fr']['prix'] ?? $c['nl']['prix'] ?? ($f['prixConseille'] ?? null);
        $nomBase = $f['nom'] ?? $l['nom'];
        $out[] = [
            'id' => $l['id'], 'qte' => $l['qte'], 'prix' => $prix,
            'nom' => ['fr' => ($c['fr']['nom'] ?? '') ?: $nomBase, 'nl' => ($c['nl']['nom'] ?? '') ?: (($c['fr']['nom'] ?? '') ?: $nomBase)],
            'famille' => ['fr' => ($c['fr']['groupe'] ?? '') ?: ($f['categorie'] ?? ''), 'nl' => ($c['nl']['groupe'] ?? '') ?: (($c['fr']['groupe'] ?? '') ?: ($f['categorie'] ?? ''))],
            'image' => ($c['fr']['image'] ?? '') ?: (($c['nl']['image'] ?? '') ?: ($f['photo'] ?? '')),
            'composition' => tclComposition($recette, ['fr' => $c['fr']['ingredients'] ?? '', 'nl' => $c['nl']['ingredients'] ?? '']),
            'allergenes' => array_values(array_filter(TCL_ALLERGENES, static fn ($a) => isset($codes[$a]))),
            'vegetarien' => $f['vegetarien'] ?? false,
            'poids' => $f['poids'] ?? null,
            'vieMinutes' => $f['vieMinutes'] ?? null,
            'stockage' => $f['stockage'] ?? '',
            'rechauffe' => $f['rechauffe'] ?? null,
            'cuisson' => $f['cuisson'] ?? [],
            'etapes' => $f['etapes'] ?? [],
            'webshop' => $f['webshop'] ?? false,
            'fiche' => $f !== null,
        ];
    }
    $avis = setting('ticketAvisUrls', []);
    return [
        'boutique' => $boutique,
        'langue' => in_array($lang, ['fr', 'nl'], true) ? $lang : $boutique['langue'],
        'transaction' => ['id' => $tx, 'date' => tclDateTransaction($t), 'total' => tclNombre($t, ['total_gross', 'total_price_gross', 'value_gross', 'total', 'value'])],
        'lignes' => $out,
        'travailles' => array_values(array_filter(TCL_ALLERGENES, static fn ($a) => isset($travailles[$a]))),
        'liens' => ['webshop' => (string) setting('ticketWebshopUrl', ''), 'avis' => is_array($avis) ? (string) ($avis[(string) $shop] ?? '') : '',
            'application' => (string) setting('ticketApplicationUrl', '')],
    ];
}

function ep_tcl_essai_boutiques(): array
{
    return ['boutiques' => array_values(tclBoutiques())];
}

function ep_tcl_essai_jour(int $shop, string $date): array
{
    if ($shop <= 0) { http_response_code(400); return ['error' => 'boutique manquante']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { $date = date('Y-m-d'); }
    $r = PanelApi::get('/shops/' . $shop . '/transactions?date=' . $date);
    if (!is_array($r)) { return ['date' => $date, 'transactions' => [], 'erreur' => 'le panel n’a pas rendu les transactions du jour']; }
    $secret = authSecret();
    $out = [];
    $liste = analyseListe($r);
    foreach ($liste as $t) {
        $id = (int) ($t['id'] ?? 0);
        if ($id <= 0) { continue; }
        $out[] = ['id' => $id, 'date' => tclDateTransaction($t), 'total' => tclNombre($t, ['total_gross', 'total_price_gross', 'value_gross', 'total', 'value']),
            'code' => tclCode($shop, $id, $secret)];
    }
    usort($out, static fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
    // Les clés d'une transaction de la liste : pour ajuster la lecture si le panel nomme autrement ses champs.
    return ['date' => $date, 'transactions' => $out, 'cles' => isset($liste[0]) && is_array($liste[0]) ? array_keys($liste[0]) : []];
}

function ep_tcl_essai_lien(int $shop, int $transaction): array
{
    if ($shop <= 0 || $transaction <= 0) { http_response_code(400); return ['error' => 'boutique et numéro de transaction requis']; }
    $t = tclTransaction($shop, $transaction);
    $cles = [];
    if (is_array($t)) {
        $cles = array_keys($t);
        foreach (['products', 'lines', 'items', 'transaction_products'] as $k) {
            if (isset($t[$k][0]) && is_array($t[$k][0])) { $cles[] = $k . '[] : ' . implode(', ', array_keys($t[$k][0])); break; }
        }
    }
    return ['code' => tclCode($shop, $transaction, authSecret()), 'trouvee' => $t !== null, 'lignes' => is_array($t) ? count(tclLignesTransaction($t)) : 0, 'cles' => $cles];
}
