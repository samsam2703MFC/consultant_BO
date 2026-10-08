<?php
/**
 * La fiche d'une matière première, pour la modale produit du dashboard magasin (onglet « Recette & marge », clic sur
 * une ligne de la recette) — demande du 08/10/2026 : le prix conseillé par la centrale, les prix des magasins du
 * réseau, le fournisseur, le prix par unité, la date. API du panel seulement (« api only »), jamais la copie locale.
 *
 * Routes lues :
 *  - `/shops/{id}/materials`, pour chaque magasin actif : `base_unit_price_net` (le prix du magasin),
 *    `suggested_base_unit_price_net` (le prix conseillé), `reference_unit_price_net`, unité, catégorie, TVA,
 *    `source_type` CENTRAL, fournisseur intégré. Une lecture par magasin, gardée 24 h (`ceo_app_setting`) ;
 *  - `/material-suppliers` et, par fournisseur, `/connected-materials` (référence fournisseur) et `/materials` :
 *    le fournisseur de la matière. Gardés 24 h pour tout le réseau.
 * Les noms des magasins viennent du référentiel du cockpit (table `shops`).
 *
 * Lecture seule. Rien n'est écrit au panel.
 */
declare(strict_types=1);

const MF_HEURES = 24;

/** Une liste du panel : la réponse est une liste, ou l'enveloppe la porte sous `data`, `items`, `materials`… */
function mfListe(mixed $r): array
{
    if (!is_array($r)) { return []; }
    if (array_is_list($r)) { return $r; }
    foreach (['data', 'items', 'materials', 'suppliers', 'results'] as $k) {
        if (isset($r[$k]) && is_array($r[$k]) && array_is_list($r[$k])) { return $r[$k]; }
    }
    return [];
}

/** Un nombre du panel ("1.73583300", "NULL", null) → float ou null. */
function mfNombre(mixed $v): ?float
{
    return is_numeric($v) ? (float) $v : null;
}

/** Une valeur gardée 24 h dans `ceo_app_setting` : null si absente, périmée ou illisible. */
function mfCache(string $cle, bool $frais): ?array
{
    if ($frais) { return null; }
    try { $c = setting($cle); } catch (Throwable $e) { return null; }
    if (!is_array($c) || !isset($c['le'], $c['v']) || !is_array($c['v'])) { return null; }
    if (time() - (int) $c['le'] >= MF_HEURES * 3600) { return null; }
    return $c['v'];
}

function mfGarder(string $cle, array $v): void
{
    try {
        $j = json_encode(['le' => time(), 'v' => $v], JSON_UNESCAPED_UNICODE);
        if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
    } catch (Throwable $e) { /* sans cache */ }
}

/** Les magasins actifs du cockpit : id → nom. */
function mfMagasins(): array
{
    $out = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) { $out[(int) $s['id']] = (string) $s['name']; } }
    catch (Throwable $e) { /* sans noms : l'identifiant fera foi */ }
    return $out;
}

/** Une matière de `/shops/{id}/materials`, réduite à ce que la fiche garde. */
function mfMatiere(array $x): array
{
    return ['nom' => (string) ($x['name'] ?? ''), 'prix' => mfNombre($x['base_unit_price_net'] ?? null), 'conseille' => mfNombre($x['suggested_base_unit_price_net'] ?? null),
        'reference' => mfNombre($x['reference_unit_price_net'] ?? null), 'brut' => mfNombre($x['base_unit_price_gross'] ?? null), 'conseilleBrut' => mfNombre($x['suggested_base_unit_price_gross'] ?? null),
        'tva' => mfNombre($x['vat_rate'] ?? null), 'unite' => (string) ($x['unit_name'] ?? ''), 'cat' => (string) ($x['category_name'] ?? ''),
        'perte' => mfNombre($x['waste_amount_perc'] ?? null), 'source' => strtoupper((string) ($x['source_type'] ?? '')),
        'integre' => (int) ($x['integrated_supplier'] ?? 0) === 1, 'type' => (string) ($x['material_type'] ?? ''), 'kind' => (string) ($x['kind'] ?? '')];
}

/**
 * Les matières de chaque magasin demandé selon l'API (`/shops/{id}/materials`), gardées 24 h par magasin : les
 * magasins sans cache sont lus en parallèle. Rend [sid => [mid => matière]] ; un magasin illisible vaut [].
 */
function mfMatieresApi(array $sids, bool $frais): array
{
    $out = []; $manque = [];
    foreach ($sids as $sid) {
        $c = mfCache('matieresApi:' . $sid, $frais);
        if ($c !== null) { $out[$sid] = $c; } else { $manque[$sid] = '/shops/' . $sid . '/materials'; }
    }
    if ($manque !== [] && PanelApi::configured()) {
        $res = count($manque) === 1 ? [array_key_first($manque) => PanelApi::get(reset($manque))] : PanelApi::getParallele($manque);
        foreach ($manque as $sid => $chemin) {
            $m = [];
            foreach (mfListe($res[$sid] ?? null) as $x) { if (is_array($x) && isset($x['id'])) { $m[(string) (int) $x['id']] = mfMatiere($x); } }
            $out[$sid] = $m;
            if ($m !== []) { mfGarder('matieresApi:' . $sid, $m); }
        }
    }
    foreach ($sids as $sid) { $out[$sid] ??= []; }
    return $out;
}

/** Les fournisseurs matière du panel et leurs matières (référence fournisseur comprise), gardés 24 h pour tout le réseau. */
function mfFournisseurs(bool $frais): array
{
    $cle = 'fournisseursMatieres';
    $c = mfCache($cle, $frais);
    if ($c !== null && isset($c['f'], $c['m'])) { return $c; }
    $out = ['f' => [], 'm' => []];
    if (!PanelApi::configured()) { return $out; }
    foreach (mfListe(PanelApi::get('/material-suppliers')) as $f) {
        if (!is_array($f) || !isset($f['id'])) { continue; }
        $out['f'][(string) (int) $f['id']] = ['nom' => (string) ($f['name'] ?? ('Fournisseur ' . $f['id'])), 'type' => strtoupper((string) ($f['type'] ?? '')),
            'typeNom' => (string) ($f['type_name'] ?? ''), 'ville' => (string) ($f['city'] ?? ''), 'integre' => (int) ($f['integrated_supplier'] ?? 0) === 1];
    }
    if ($out['f'] === []) { return $out; }
    $chemins = [];
    foreach (array_keys($out['f']) as $fid) { $chemins['c' . $fid] = '/material-suppliers/' . $fid . '/connected-materials'; $chemins['m' . $fid] = '/material-suppliers/' . $fid . '/materials'; }
    $res = PanelApi::getParallele($chemins);
    foreach (array_keys($out['f']) as $fid) {
        $vus = [];
        foreach (mfListe($res['c' . $fid] ?? null) as $x) {
            if (!is_array($x) || !isset($x['id'])) { continue; }
            $mid = (string) (int) $x['id']; $vus[$mid] = true;
            $out['m'][$mid][] = ['fid' => (string) $fid, 'sku' => (string) ($x['supplier_sku'] ?? ''), 'pack' => isset($x['id_pack']) ? (string) $x['id_pack'] : ''];
        }
        foreach (mfListe($res['m' . $fid] ?? null) as $x) {
            if (!is_array($x) || !isset($x['id'])) { continue; }
            $mid = (string) (int) $x['id'];
            if (isset($vus[$mid])) { continue; }
            $out['m'][$mid][] = ['fid' => (string) $fid, 'sku' => '', 'pack' => ''];
        }
    }
    if ($out['m'] !== []) { mfGarder($cle, $out); }
    return $out;
}

/** La médiane d'une liste de nombres. */
function mfMediane(array $v): ?float
{
    $v = array_values(array_filter($v, 'is_numeric')); if ($v === []) { return null; }
    sort($v); $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/**
 * GET /analyse/matieres/fiche?mid=63&shop=4[&rafraichir=1][&sonde=1] — la fiche d'une matière pour un magasin,
 * face aux autres magasins du réseau. `&sonde=1` (diagnostic, lecture seule) joint la ligne brute de l'API, le
 * compte des prix conseillés renseignés et l'essai des routes qui pourraient dater les prix.
 */
function ep_analyse_matiere_fiche(): array
{
    $mid = (int) ($_GET['mid'] ?? 0); $sid = (int) ($_GET['shop'] ?? 0);
    if ($mid <= 0 || $sid <= 0) { http_response_code(400); return ['error' => 'mid et shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'API panel non configurée (Mon compte)']; }
    $frais = !empty($_GET['rafraichir']);
    $noms = mfMagasins();
    if (!isset($noms[$sid])) { $noms[$sid] = 'Magasin ' . $sid; }
    $sids = array_keys($noms); sort($sids);
    $parMagasin = mfMatieresApi($sids, $frais);
    $a = $parMagasin[$sid][(string) $mid] ?? null;
    // La matière absente du magasin demandé : son identité vient d'un autre magasin qui la porte.
    $ident = $a;
    if ($ident === null) { foreach ($parMagasin as $s => $m) { if (isset($m[(string) $mid])) { $ident = $m[(string) $mid]; break; } } }
    if ($ident === null) {
        if (($parMagasin[$sid] ?? []) === []) { http_response_code(502); return ['error' => 'matières du magasin illisibles sur l’API du panel', 'detail' => PanelApi::$lastError, 'mid' => $mid]; }
        http_response_code(404); return ['error' => 'matière inconnue de l’API du panel', 'mid' => $mid];
    }
    $fourn = mfFournisseurs($frais);

    $conseille = $a['conseille'] ?? $ident['conseille']; $reference = $a['reference'] ?? $ident['reference'];
    $valeurs = [];
    foreach ($parMagasin as $s => $m) { $p = $m[(string) $mid]['prix'] ?? null; if ($p !== null && $p > 0) { $valeurs[] = $p; } }
    $med = mfMediane($valeurs);
    // Le repère de comparaison : le prix conseillé, sinon le prix de référence, sinon la médiane du réseau.
    $repere = $conseille ?? $reference ?? $med;
    $repereNom = $conseille !== null ? 'conseillé' : ($reference !== null ? 'référence' : ($med !== null ? 'médiane du réseau' : null));
    $reseau = [];
    foreach ($noms as $s => $n) {
        $m = $parMagasin[$s][(string) $mid] ?? null; $p = $m['prix'] ?? null;
        $reseau[] = ['id' => (string) $s, 'nom' => $n, 'court' => preg_replace('/^.* - /', '', $n), 'ceMagasin' => $s === $sid,
            'prix' => $p, 'brut' => $m['brut'] ?? null, 'conseille' => $m['conseille'] ?? null, 'lu' => ($parMagasin[$s] ?? []) !== [],
            'ecart' => $p !== null && $repere > 0 ? round(100 * ($p - $repere) / $repere, 1) : null];
    }
    usort($reseau, static fn ($x, $y) => ($x['prix'] === null) <=> ($y['prix'] === null) ?: ($x['prix'] <=> $y['prix']) ?: strcmp($x['nom'], $y['nom']));
    $fournisseurs = [];
    foreach ($fourn['m'][(string) $mid] ?? [] as $x) {
        $f = $fourn['f'][$x['fid']] ?? ['nom' => 'Fournisseur ' . $x['fid'], 'type' => '', 'typeNom' => '', 'ville' => '', 'integre' => false];
        $fournisseurs[] = ['id' => $x['fid'], 'nom' => $f['nom'], 'type' => $f['type'], 'typeNom' => $f['typeNom'], 'ville' => $f['ville'], 'integre' => $f['integre'],
            'sku' => $x['sku'], 'pack' => $x['pack'], 'centrale' => $f['type'] === 'CENTRAL'];
    }
    usort($fournisseurs, static fn ($x, $y) => ($y['centrale'] <=> $x['centrale']) ?: strcmp($x['nom'], $y['nom']));
    $unite = $ident['unite'];
    $out = ['mid' => $mid, 'nom' => $ident['nom'], 'cat' => $ident['cat'], 'unite' => $unite, 'parKg' => in_array(strtolower($unite), ['kg', 'l'], true), 'perte' => $ident['perte'],
        'typeMatiere' => $ident['type'], 'sourceCentrale' => $ident['source'] === 'CENTRAL', 'integre' => $ident['integre'], 'tva' => $a['tva'] ?? $ident['tva'],
        'conseille' => $conseille, 'conseilleBrut' => $a['conseilleBrut'] ?? $ident['conseilleBrut'], 'reference' => $reference, 'repere' => $repere, 'repereNom' => $repereNom,
        'magasin' => ['id' => (string) $sid, 'nom' => $noms[$sid], 'court' => preg_replace('/^.* - /', '', $noms[$sid]), 'prix' => $a['prix'] ?? null, 'brut' => $a['brut'] ?? null,
            'ecart' => ($a['prix'] ?? null) !== null && $repere > 0 ? round(100 * ($a['prix'] - $repere) / $repere, 1) : null, 'absente' => $a === null],
        'reseau' => $reseau, 'stats' => ['n' => count($valeurs), 'min' => $valeurs !== [] ? min($valeurs) : null, 'max' => $valeurs !== [] ? max($valeurs) : null, 'med' => $med],
        'fournisseurs' => $fournisseurs, 'date' => null,
        'source' => 'API du panel : /shops/{id}/materials de chaque magasin (prix de base net, prix conseillé, prix de référence, TVA) et /material-suppliers (fournisseur, référence), gardés 24 h · l’API ne date pas ses prix',
        'fournisseursLus' => $fourn['f'] !== []];
    if (!empty($_GET['sonde'])) {
        $out['sonde'] = ['conseilles' => 0, 'references' => 0, 'matieres' => count($parMagasin[$sid] ?? []), 'apiBrut' => null, 'fournisseursListe' => $fourn['f'], 'liensMatiere' => $fourn['m'][(string) $mid] ?? [], 'routes' => []];
        foreach ($parMagasin[$sid] ?? [] as $x) { if ($x['conseille'] !== null) { $out['sonde']['conseilles']++; } if ($x['reference'] !== null) { $out['sonde']['references']++; } }
        try {
            foreach (mfListe(PanelApi::get('/shops/' . $sid . '/materials')) as $x) { if (is_array($x) && (int) ($x['id'] ?? 0) === $mid) { $out['sonde']['apiBrut'] = $x; break; } }
            // Les routes du swagger du panel (/swagger/openapi.json, 933 routes) qui parlent de prix de matière : essayées
            // une fois, en lecture, avec leur code HTTP — le swagger ne décrit pas leurs réponses.
            $centrale = null; foreach ($fourn['f'] as $fid => $f) { if ($f['type'] === 'CENTRAL') { $centrale = $fid; break; } }
            $fid1 = $fourn['m'][(string) $mid][0]['fid'] ?? $centrale;
            $essais = ['materiel' => '/materials/' . $mid, 'pricing' => '/shops/' . $sid . '/materials/pricing', 'avgPrice' => '/shops/' . $sid . '/materials/avg-price',
                'magasinFournisseurs' => '/shops/' . $sid . '/suppliers', 'magasinMaterialSuppliers' => '/shops/' . $sid . '/material-suppliers',
                'paysFournisseurs' => '/countries/BE/materials/' . $mid . '/material-suppliers', 'packs' => '/shops/' . $sid . '/materials/' . $mid . '/packs',
                'ouvert' => '/materials/' . $mid . '/open-params', 'packagings' => '/materials/' . $mid . '/packagings'];
            if ($fid1 !== null) {
                $essais += ['listePrixFourn' => '/shops/' . $sid . '/suppliers/' . $fid1 . '/price-list', 'prixMatFourn' => '/shops/' . $sid . '/suppliers/' . $fid1 . '/materials/price',
                    'orderable' => '/shops/' . $sid . '/suppliers/' . $fid1 . '/orderable-items', 'listeLatest' => '/material-suppliers/' . $fid1 . '/shops/' . $sid . '/price-lists/latest',
                    'listeCurrent' => '/material-suppliers/' . $fid1 . '/shops/' . $sid . '/price-lists/current', 'changements' => '/material-suppliers/' . $fid1 . '/shops/' . $sid . '/price-change-notifications',
                    'catalogue' => '/material-suppliers/' . $fid1 . '/catalog/products', 'mappings' => '/material-suppliers/' . $fid1 . '/catalog-mappings',
                    'fournMatiere' => '/material-suppliers/' . $fid1 . '/materials/' . $mid, 'fournPackagings' => '/material-suppliers/' . $fid1 . '/materials/' . $mid . '/packagings',
                    'rawMaterials' => '/material-suppliers/' . $fid1 . '/raw-materials', 'termes' => '/shops/' . $sid . '/material-suppliers/' . $fid1 . '/delivery-terms'];
            }
            $apercu = static function ($b) {
                if (!is_array($b)) { return is_string($b) ? mb_substr($b, 0, 200) : $b; }
                $l = mfListe($b);
                if ($l !== []) { return ['liste' => count($l), 'cles' => is_array($l[0]) ? array_keys($l[0]) : null, 'premier' => $l[0], 'enveloppe' => array_is_list($b) ? null : array_keys($b)]; }
                return ['cles' => array_slice(array_keys($b), 0, 60), 'extrait' => array_map(static fn ($v) => is_array($v) ? (array_is_list($v) ? ['liste' => count($v)] : array_slice($v, 0, 20, true)) : $v, array_slice($b, 0, 20, true))];
            };
            foreach ($essais as $k => $chemin) {
                $r = PanelApi::sondeGet($chemin, 12);
                $out['sonde']['routes'][$k] = ['chemin' => $chemin, 'code' => $r['code'] ?? null, 'erreur' => isset($r['erreur']) && $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 160) : null, 'apercu' => $apercu($r['corps'] ?? null)];
            }
            // L'historique des prix d'achat de la centrale : ses matières premières à elle, cherchées par le nom de la nôtre.
            $raw = mfListe(PanelApi::get('/material-suppliers/' . ($centrale ?? $fid1 ?? '0') . '/raw-materials'));
            $cible = mb_strtolower(trim($ident['nom'])); $trouve = null;
            foreach ($raw as $x) { if (is_array($x) && mb_strtolower(trim((string) ($x['name'] ?? ''))) === $cible) { $trouve = $x; break; } }
            if ($trouve === null) { foreach ($raw as $x) { if (is_array($x) && $cible !== '' && str_contains(mb_strtolower((string) ($x['name'] ?? '')), mb_substr($cible, 0, 8))) { $trouve = $x; break; } } }
            $out['sonde']['rawMaterial'] = ['n' => count($raw), 'cles' => $raw !== [] && is_array($raw[0]) ? array_keys($raw[0]) : null, 'trouve' => $trouve];
            if ($trouve !== null && isset($trouve['id'])) {
                $r = PanelApi::sondeGet('/material-suppliers/' . ($centrale ?? $fid1) . '/raw-materials/' . (int) $trouve['id'] . '/price-history', 12);
                $out['sonde']['rawHistorique'] = ['code' => $r['code'] ?? null, 'apercu' => $apercu($r['corps'] ?? null)];
            }
        } catch (Throwable $e) { $out['sonde']['erreur'] = $e->getMessage(); }
    }
    return $out;
}
