<?php
/**
 * La fiche d'une matière première, pour la modale produit du dashboard magasin (onglet « Recette & marge », clic sur
 * une ligne de la recette) — demande du 08/10/2026 : le prix de la centrale, les prix des magasins du réseau, le
 * fournisseur, le prix par kg, la date. API du panel seulement (« api only, from swagger »), jamais la copie locale.
 *
 * Routes lues (swagger du panel, /swagger/openapi.json — mesurées le 08/10/2026) :
 *  - `/shops/{id}/materials`, pour chaque magasin actif : `base_unit_price_net` (le prix du magasin, par unité de
 *    base : g, pcs, kg…), `suggested_base_unit_price_net` (prix conseillé, rempli pour 1 matière sur 631),
 *    `reference_unit_price_net`, unité, catégorie, TVA, `source_type`. Une lecture par magasin, gardée 24 h ;
 *  - `/material-suppliers` (les fournisseurs matière : la centrale est de type CENTRAL), puis par fournisseur
 *    `/catalog-mappings` (matière ↔ produit du catalogue fournisseur : référence, colis, unités par colis),
 *    `/connected-materials` et `/materials` (les matières liées, leur référence). Gardés 24 h pour le réseau ;
 *  - `/material-suppliers/{fid}/shops/{sid}/price-lists/current` et `/latest` : la liste de prix du fournisseur pour
 *    le magasin, DATÉE (`valid_from`), par colis (`price_net`, `package_size`, `package_unit`). Gardées 24 h par
 *    fournisseur et magasin, lues seulement pour les fournisseurs qui portent la matière.
 * Les noms des magasins viennent du référentiel du cockpit (table `shops`).
 *
 * Lecture seule. Rien n'est écrit au panel.
 */
declare(strict_types=1);

const MF_HEURES = 24;            // le référentiel des fournisseurs et leurs correspondances (rarement changés ; « Relire » les relit)
const MF_SEC_PRIX = 60;          // les prix (matières de chaque magasin, listes de prix) : relus à chaque ouverture passée la minute,
                                 // pour qu'une édition de prix au panel se voie tout de suite (demande du 08/10/2026)

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

/** Une valeur gardée dans `ceo_app_setting` (24 h par défaut) : null si absente, périmée ou illisible. `$leLu` reçoit l'heure de lecture. */
function mfCache(string $cle, bool $frais, ?int $maxSec = null, ?int &$leLu = null): ?array
{
    $leLu = null;
    if ($frais) { return null; }
    try { $c = setting($cle); } catch (Throwable $e) { return null; }
    if (!is_array($c) || !isset($c['le'], $c['v']) || !is_array($c['v'])) { return null; }
    if (time() - (int) $c['le'] >= ($maxSec ?? MF_HEURES * 3600)) { return null; }
    $leLu = (int) $c['le'];
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

/** L'unité de base → le facteur et l'étiquette de l'unité lisible : g → × 1000 par kg, ml → × 1000 par l, pcs → par pce. */
function mfUnite(string $u): array
{
    $u = strtolower(trim($u));
    return match ($u) {
        'g', 'gr', 'gramme', 'grammes' => ['facteur' => 1000.0, 'label' => 'kg'],
        'ml' => ['facteur' => 1000.0, 'label' => 'l'],
        'kg', 'kilogramme' => ['facteur' => 1.0, 'label' => 'kg'],
        'l', 'litre' => ['facteur' => 1.0, 'label' => 'l'],
        'pcs', 'pce', 'pc', 'piece', 'pièce', 'szt', 'unit' => ['facteur' => 1.0, 'label' => 'pce'],
        default => ['facteur' => 1.0, 'label' => $u !== '' ? $u : 'unité'],
    };
}

/** Une matière de `/shops/{id}/materials`, réduite à ce que la fiche garde. */
function mfMatiere(array $x): array
{
    return ['nom' => trim((string) ($x['name'] ?? '')), 'prix' => mfNombre($x['base_unit_price_net'] ?? null), 'conseille' => mfNombre($x['suggested_base_unit_price_net'] ?? null),
        'reference' => mfNombre($x['reference_unit_price_net'] ?? null), 'brut' => mfNombre($x['base_unit_price_gross'] ?? null),
        'tva' => mfNombre($x['vat_rate'] ?? null), 'unite' => (string) ($x['unit_name'] ?? ''), 'cat' => (string) ($x['category_name'] ?? ''),
        'perte' => mfNombre($x['waste_amount_perc'] ?? null), 'source' => strtoupper((string) ($x['source_type'] ?? '')),
        'integre' => (int) ($x['integrated_supplier'] ?? 0) === 1, 'type' => (string) ($x['material_type'] ?? '')];
}

/**
 * Les matières de chaque magasin demandé selon l'API (`/shops/{id}/materials`) : relues passée la minute (MF_SEC_PRIX),
 * pour qu'une édition de prix au panel se voie tout de suite ; les magasins à relire sont lus en parallèle.
 * Rend [sid => [mid => matière]] ; un magasin illisible vaut []. `$luLe` reçoit l'heure de lecture par magasin.
 */
function mfMatieresApi(array $sids, bool $frais, int $sidCourant = 0, array &$luLe = []): array
{
    $out = []; $manque = []; $luLe = [];
    foreach ($sids as $sid) {
        $le = null;
        $c = mfCache('matieresApi:' . $sid, $frais, MF_SEC_PRIX, $le);
        if ($c !== null) { $out[$sid] = $c; $luLe[$sid] = $le; } else { $manque[$sid] = '/shops/' . $sid . '/materials'; }
    }
    if ($manque !== [] && PanelApi::configured()) {
        $res = count($manque) === 1 ? [array_key_first($manque) => PanelApi::get(reset($manque))] : PanelApi::getParallele($manque);
        foreach ($manque as $sid => $chemin) {
            $m = [];
            foreach (mfListe($res[$sid] ?? null) as $x) { if (is_array($x) && isset($x['id'])) { $m[(string) (int) $x['id']] = mfMatiere($x); } }
            $out[$sid] = $m; $luLe[$sid] = time();
            if ($m !== []) { mfGarder('matieresApi:' . $sid, $m); }
        }
    }
    foreach ($sids as $sid) { $out[$sid] ??= []; }
    return $out;
}

/**
 * Les fournisseurs matière du panel et, par matière, ses liens : la correspondance du catalogue fournisseur (référence,
 * colis, unités par colis), sinon la matière connectée (référence), sinon la matière simplement listée chez lui.
 * Gardés 24 h pour tout le réseau.
 */
function mfFournisseurs(bool $frais): array
{
    $cle = 'fournisseursMatieres2';
    $c = mfCache($cle, $frais);
    if ($c !== null && isset($c['f'], $c['m'])) { return $c; }
    $out = ['f' => [], 'm' => []];
    if (!PanelApi::configured()) { return $out; }
    foreach (mfListe(PanelApi::get('/material-suppliers')) as $f) {
        if (!is_array($f) || !isset($f['id'])) { continue; }
        $out['f'][(string) (int) $f['id']] = ['nom' => trim((string) ($f['name'] ?? ('Fournisseur ' . $f['id']))), 'type' => strtoupper((string) ($f['type'] ?? '')),
            'typeNom' => (string) ($f['type_name'] ?? ''), 'ville' => (string) ($f['city'] ?? ''), 'integre' => (int) ($f['integrated_supplier'] ?? 0) === 1];
    }
    if ($out['f'] === []) { return $out; }
    $chemins = [];
    foreach (array_keys($out['f']) as $fid) {
        $chemins['k' . $fid] = '/material-suppliers/' . $fid . '/catalog-mappings';
        $chemins['c' . $fid] = '/material-suppliers/' . $fid . '/connected-materials';
        $chemins['m' . $fid] = '/material-suppliers/' . $fid . '/materials';
    }
    $res = PanelApi::getParallele($chemins);
    foreach (array_keys($out['f']) as $fid) {
        $vus = [];
        foreach (mfListe($res['k' . $fid] ?? null) as $x) {
            if (!is_array($x) || !isset($x['material_id'])) { continue; }
            $mid = (string) (int) $x['material_id']; $vus[$mid] = true;
            $out['m'][$mid][] = ['fid' => (string) $fid, 'via' => 'catalogue', 'sku' => (string) ($x['catalog_product_sku'] ?? ''), 'produit' => (string) ($x['catalog_product_id'] ?? ''),
                'nomCatalogue' => trim((string) ($x['catalog_product_name'] ?? '')), 'taille' => mfNombre($x['package_size'] ?? null), 'tailleUnite' => (string) ($x['package_unit'] ?? ''),
                'parColis' => mfNombre($x['units_per_pack'] ?? null), 'depuis' => substr((string) ($x['created_at'] ?? ''), 0, 10)];
        }
        foreach (mfListe($res['c' . $fid] ?? null) as $x) {
            if (!is_array($x) || !isset($x['id'])) { continue; }
            $mid = (string) (int) $x['id'];
            if (isset($vus[$mid])) { continue; }
            $vus[$mid] = true;
            $out['m'][$mid][] = ['fid' => (string) $fid, 'via' => 'connexion', 'sku' => (string) ($x['supplier_sku'] ?? ''), 'produit' => '', 'nomCatalogue' => '', 'taille' => null, 'tailleUnite' => '', 'parColis' => null, 'depuis' => ''];
        }
        foreach (mfListe($res['m' . $fid] ?? null) as $x) {
            if (!is_array($x) || !isset($x['id'])) { continue; }
            $mid = (string) (int) $x['id'];
            if (isset($vus[$mid])) { continue; }
            $vus[$mid] = true;
            $out['m'][$mid][] = ['fid' => (string) $fid, 'via' => 'liste', 'sku' => '', 'produit' => '', 'nomCatalogue' => '', 'taille' => null, 'tailleUnite' => '', 'parColis' => null, 'depuis' => ''];
        }
    }
    if ($out['m'] !== []) { mfGarder($cle, $out); }
    return $out;
}

/** La liste de prix d'un fournisseur pour un magasin, en vigueur (`current`) et la plus récente (`latest`), par référence. Relue passée la minute. */
function mfListePrix(string $fid, int $sid, bool $frais): array
{
    $cle = 'listePrix:' . $fid . ':' . $sid;
    $c = mfCache($cle, $frais, MF_SEC_PRIX);
    if ($c !== null && isset($c['c'], $c['l'])) { return $c; }
    $out = ['c' => [], 'l' => [], 'lu' => false];
    if (!PanelApi::configured()) { return $out; }
    $res = PanelApi::getParallele(['c' => '/material-suppliers/' . $fid . '/shops/' . $sid . '/price-lists/current', 'l' => '/material-suppliers/' . $fid . '/shops/' . $sid . '/price-lists/latest']);
    foreach (['c', 'l'] as $k) {
        if (!is_array($res[$k] ?? null)) { continue; }
        $out['lu'] = true;
        foreach (mfListe($res[$k]) as $x) {
            if (!is_array($x)) { continue; }
            $sku = trim((string) ($x['sku'] ?? '')); if ($sku === '') { continue; }
            $out[$k][$sku] = ['nom' => trim((string) ($x['name'] ?? '')), 'prix' => mfNombre($x['price_net'] ?? null), 'taille' => mfNombre($x['package_size'] ?? null), 'tailleUnite' => (string) ($x['package_unit'] ?? ''),
                'tva' => mfNombre($x['vat_rate'] ?? null), 'depuis' => substr((string) ($x['valid_from'] ?? ''), 0, 10), 'produit' => (string) ($x['id'] ?? ''),
                'nomMatiere' => trim((string) ($x['franchisee_material_name'] ?? '')), 'actif' => isset($x['is_active']) ? (int) $x['is_active'] === 1 : null, 'mappe' => isset($x['is_mapped']) ? (int) $x['is_mapped'] === 1 : null];
        }
    }
    if ($out['lu']) { mfGarder($cle, $out); }
    return $out;
}

/** Le prix par unité de base d'une ligne de liste de prix : prix du colis ÷ (taille du colis × unités par colis). */
function mfParUnite(?float $prix, ?float $taille, ?float $parColis): ?float
{
    if ($prix === null || $prix <= 0) { return null; }
    $t = $taille !== null && $taille > 0 ? $taille : 1.0; $u = $parColis !== null && $parColis > 0 ? $parColis : 1.0;
    return $prix / ($t * $u);
}

/** La médiane d'une liste de nombres. */
function mfMediane(array $v): ?float
{
    $v = array_values(array_filter($v, 'is_numeric')); if ($v === []) { return null; }
    sort($v); $n = count($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/**
 * GET /analyse/matieres/fiche?mid=327&shop=4[&rafraichir=1][&sonde=1] — la fiche d'une matière pour un magasin :
 * son prix et ceux des autres magasins (par kg quand l'unité est le gramme), ses fournisseurs avec leur liste de prix
 * datée, le prix conseillé quand le panel le porte. `&sonde=1` (diagnostic, lecture seule) joint la ligne brute de
 * l'API, les liens bruts et la vérification de la formule « prix du colis ÷ taille » face aux prix des magasins.
 */
function ep_analyse_matiere_fiche(): array
{
    $mid = (int) ($_GET['mid'] ?? 0); $sid = (int) ($_GET['shop'] ?? 0);
    if (!empty($_GET['sondeRecette'])) { return mfSondeRecette((int) $_GET['sondeRecette'], $sid, (int) ($_GET['pid'] ?? 0)); }
    if ($mid <= 0 || $sid <= 0) { http_response_code(400); return ['error' => 'mid et shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'API panel non configurée (Mon compte)']; }
    $frais = !empty($_GET['rafraichir']);
    $noms = mfMagasins();
    if (!isset($noms[$sid])) { $noms[$sid] = 'Magasin ' . $sid; }
    $sids = array_keys($noms); sort($sids);
    $luLe = [];
    $parMagasin = mfMatieresApi($sids, $frais, $sid, $luLe);
    $a = $parMagasin[$sid][(string) $mid] ?? null;
    $ident = $a;
    if ($ident === null) { foreach ($parMagasin as $m) { if (isset($m[(string) $mid])) { $ident = $m[(string) $mid]; break; } } }
    if ($ident === null) {
        if (($parMagasin[$sid] ?? []) === []) { http_response_code(502); return ['error' => 'matières du magasin illisibles sur l’API du panel', 'detail' => PanelApi::$lastError, 'mid' => $mid]; }
        http_response_code(404); return ['error' => 'matière inconnue de l’API du panel', 'mid' => $mid];
    }
    $U = mfUnite($ident['unite']); $fac = $U['facteur'];
    $conseille = $a['conseille'] ?? $ident['conseille']; $reference = $a['reference'] ?? $ident['reference'];

    // Les magasins du réseau : le prix de base de chacun, exprimé par kg (ou par l, par pièce).
    $valeurs = [];
    foreach ($parMagasin as $s => $m) { $p = $m[(string) $mid]['prix'] ?? null; if ($p !== null && $p > 0) { $valeurs[$s] = $p; } }
    $med = mfMediane(array_values($valeurs));
    $repere = $conseille ?? $reference ?? $med;
    $repereNom = $conseille !== null ? 'prix conseillé' : ($reference !== null ? 'prix de référence' : ($med !== null ? 'médiane du réseau' : null));
    $reseau = [];
    foreach ($noms as $s => $n) {
        $p = $valeurs[$s] ?? null;
        $reseau[] = ['id' => (string) $s, 'nom' => $n, 'court' => preg_replace('/^.* - /', '', $n), 'ceMagasin' => $s === $sid, 'lu' => ($parMagasin[$s] ?? []) !== [],
            'prix' => $p !== null ? round($p * $fac, 4) : null, 'ecart' => $p !== null && $repere > 0 ? round(100 * ($p - $repere) / $repere, 1) : null];
    }
    usort($reseau, static fn ($x, $y) => ($x['prix'] === null) <=> ($y['prix'] === null) ?: ($x['prix'] <=> $y['prix']) ?: strcmp($x['nom'], $y['nom']));

    // Les fournisseurs qui portent la matière, et leur liste de prix pour ce magasin (datée).
    $fourn = mfFournisseurs($frais);
    $fournisseurs = [];
    foreach ($fourn['m'][(string) $mid] ?? [] as $lien) {
        $f = $fourn['f'][$lien['fid']] ?? ['nom' => 'Fournisseur ' . $lien['fid'], 'type' => '', 'typeNom' => '', 'ville' => '', 'integre' => false];
        $pl = mfListePrix($lien['fid'], $sid, $frais);
        $sku = $lien['sku'];
        $cur = $sku !== '' ? ($pl['c'][$sku] ?? null) : null; $lat = $sku !== '' ? ($pl['l'][$sku] ?? null) : null;
        if ($cur === null && $lat === null) {
            // Sans référence : la ligne de la liste qui nomme notre matière.
            foreach (['c', 'l'] as $k) { foreach ($pl[$k] as $s2 => $x) { if ($x['nomMatiere'] !== '' && mb_strtolower($x['nomMatiere']) === mb_strtolower($ident['nom'])) { if ($k === 'c') { $cur = $x; } else { $lat = $x; } $sku = $s2; break; } } }
        }
        $ligne = $cur ?? $lat;
        $taille = $ligne['taille'] ?? $lien['taille']; $tailleUnite = ($ligne['tailleUnite'] ?? '') !== '' ? $ligne['tailleUnite'] : $lien['tailleUnite'];
        $parU = $ligne !== null ? mfParUnite($ligne['prix'], $taille, $lien['parColis']) : null;
        $prochain = null;
        if ($lat !== null && $cur !== null && $lat['depuis'] !== '' && $lat['depuis'] > $cur['depuis'] && $lat['depuis'] > date('Y-m-d') && $lat['prix'] !== $cur['prix']) {
            $prochain = ['prix' => $lat['prix'], 'parUnite' => ($pu = mfParUnite($lat['prix'], $lat['taille'] ?? $taille, $lien['parColis'])) !== null ? round($pu * $fac, 4) : null, 'depuis' => $lat['depuis']];
        }
        $fournisseurs[] = ['id' => $lien['fid'], 'nom' => $f['nom'], 'type' => $f['type'], 'typeNom' => $f['typeNom'], 'ville' => $f['ville'], 'integre' => $f['integre'], 'centrale' => $f['type'] === 'CENTRAL',
            'via' => $lien['via'], 'sku' => $sku, 'nomCatalogue' => $ligne['nom'] ?? $lien['nomCatalogue'], 'listeLue' => $pl['lu'] ?? false,
            'colis' => $ligne !== null ? ['prix' => $ligne['prix'], 'taille' => $taille, 'unite' => $tailleUnite, 'parColis' => $lien['parColis'], 'tva' => $ligne['tva']] : null,
            'parUnite' => $parU !== null ? round($parU * $fac, 4) : null, 'depuis' => $ligne['depuis'] ?? null, 'enVigueur' => $cur !== null, 'actif' => $ligne['actif'] ?? null,
            'ecart' => $parU !== null && ($a['prix'] ?? null) > 0 ? round(100 * ($parU - $a['prix']) / $a['prix'], 1) : null, 'prochain' => $prochain];
    }
    usort($fournisseurs, static fn ($x, $y) => ($y['centrale'] <=> $x['centrale']) ?: (($x['parUnite'] === null) <=> ($y['parUnite'] === null)) ?: strcmp($x['nom'], $y['nom']));

    $prixMag = $a['prix'] ?? null;
    $out = ['mid' => $mid, 'nom' => $ident['nom'], 'cat' => $ident['cat'], 'uniteBase' => $ident['unite'], 'par' => $U['label'], 'facteur' => $fac, 'perte' => $ident['perte'],
        'typeMatiere' => $ident['type'], 'sourceType' => $ident['source'], 'sourceCentrale' => $ident['source'] === 'CENTRAL', 'integre' => $ident['integre'], 'tva' => $a['tva'] ?? $ident['tva'],
        'conseille' => $conseille !== null ? round($conseille * $fac, 4) : null, 'reference' => $reference !== null ? round($reference * $fac, 4) : null,
        'repere' => $repere !== null ? round($repere * $fac, 4) : null, 'repereNom' => $repereNom,
        'magasin' => ['id' => (string) $sid, 'nom' => $noms[$sid], 'court' => preg_replace('/^.* - /', '', $noms[$sid]), 'prix' => $prixMag !== null && $prixMag > 0 ? round($prixMag * $fac, 4) : null,
            'sansPrix' => !($prixMag > 0), 'absente' => $a === null, 'ecart' => $prixMag > 0 && $repere > 0 ? round(100 * ($prixMag - $repere) / $repere, 1) : null],
        'reseau' => $reseau, 'stats' => ['n' => count($valeurs), 'min' => $valeurs !== [] ? round(min($valeurs) * $fac, 4) : null, 'max' => $valeurs !== [] ? round(max($valeurs) * $fac, 4) : null, 'med' => $med !== null ? round($med * $fac, 4) : null],
        'fournisseurs' => $fournisseurs, 'fournisseursLus' => $fourn['f'] !== [],
        'lu' => ['magasin' => isset($luLe[$sid]) ? date('c', $luLe[$sid]) : null, 'age' => isset($luLe[$sid]) ? time() - $luLe[$sid] : null, 'secondesPrix' => MF_SEC_PRIX, 'heuresReseau' => MF_HEURES],
        'source' => 'API du panel : /shops/{id}/materials de chaque magasin (prix de base net, prix conseillé, prix de référence, TVA), /material-suppliers + catalog-mappings (fournisseur, référence, colis), /material-suppliers/{f}/shops/{s}/price-lists/current et latest (prix du colis, valable depuis) ; gardés 24 h'];
    if (!empty($_GET['sonde'])) {
        $out['sonde'] = ['conseilles' => 0, 'references' => 0, 'matieres' => count($parMagasin[$sid] ?? []), 'liens' => $fourn['m'][(string) $mid] ?? [], 'fournisseursListe' => $fourn['f'], 'formule' => []];
        foreach ($parMagasin[$sid] ?? [] as $x) { if ($x['conseille'] !== null) { $out['sonde']['conseilles']++; } if ($x['reference'] !== null) { $out['sonde']['references']++; } }
        // La formule vérifiée sur les matières liées au catalogue d'un fournisseur : colis ÷ taille (a) ou ÷ (taille × unités par colis) (b), face au prix du magasin.
        $parF = [];
        foreach ($fourn['m'] as $m2 => $liens) { foreach ($liens as $l) { if ($l['via'] === 'catalogue' && $l['sku'] !== '') { $parF[$l['fid']][] = [$m2, $l]; } } }
        foreach ($parF as $fid => $liste) {
            $pl = mfListePrix((string) $fid, $sid, $frais);
            $st = ['fournisseur' => $fid, 'lies' => count($liste), 'avecPrix' => 0, 'a' => 0, 'b' => 0, 'ni' => 0, 'exemples' => []];
            foreach ($liste as [$m2, $l]) {
                $x = $pl['c'][$l['sku']] ?? null; $pm = $parMagasin[$sid][$m2]['prix'] ?? null;
                if ($x === null || $x['prix'] === null || !($pm > 0)) { continue; }
                $st['avecPrix']++;
                $fa = mfParUnite($x['prix'], $x['taille'] ?? $l['taille'], null); $fb = mfParUnite($x['prix'], $x['taille'] ?? $l['taille'], $l['parColis']);
                $okA = $fa !== null && abs($fa - $pm) / $pm < 0.01; $okB = $fb !== null && abs($fb - $pm) / $pm < 0.01;
                if ($okA) { $st['a']++; } if ($okB) { $st['b']++; } if (!$okA && !$okB) { $st['ni']++; }
                if (count($st['exemples']) < 6) { $st['exemples'][] = ['mid' => $m2, 'nom' => $parMagasin[$sid][$m2]['nom'] ?? '', 'unite' => $parMagasin[$sid][$m2]['unite'] ?? '', 'magasin' => $pm, 'colis' => $x['prix'], 'taille' => $x['taille'], 'tailleUnite' => $x['tailleUnite'], 'parColis' => $l['parColis'], 'a' => $fa, 'b' => $fb, 'depuis' => $x['depuis']]; }
            }
            $out['sonde']['formule'][] = $st;
        }
    }
    return $out;
}

/**
 * Sonde, lecture seule : ce que les routes de recette du swagger rendent (recette, aplatie, coût par magasin, produit),
 * pour décider si la recette de la modale peut venir de l'API plutôt que de la copie locale.
 */
function mfSondeRecette(int $rid, int $sid, int $pid): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'API panel non configurée']; }
    $apercu = static function ($b) {
        if (!is_array($b)) { return is_string($b) ? mb_substr($b, 0, 300) : $b; }
        $l = mfListe($b);
        if ($l !== []) { return ['liste' => count($l), 'cles' => is_array($l[0]) ? array_keys($l[0]) : null, 'premier' => $l[0], 'second' => $l[1] ?? null, 'enveloppe' => array_is_list($b) ? null : array_keys($b)]; }
        $out = ['cles' => array_slice(array_keys($b), 0, 80)];
        foreach (array_slice($b, 0, 40, true) as $k => $v) { $out['extrait'][$k] = is_array($v) ? (array_is_list($v) ? ['liste' => count($v), 'premier' => $v[0] ?? null, 'second' => $v[1] ?? null] : array_slice($v, 0, 30, true)) : $v; }
        return $out;
    };
    $essais = ['recette' => '/recipes/' . $rid, 'aplatie' => '/recipes/' . $rid . '/flatten', 'cout' => '/recipes/' . $rid . '/cost', 'coutMagasin' => '/shops/' . $sid . '/recipes/' . $rid . '/cost',
        'texte' => '/recipes/' . $rid . '/text', 'recettesMagasin' => '/shops/' . $sid . '/recipes', 'calcul' => '/franchise/1/product-recipe/' . $rid . '/calculation',
        'sousRecettes' => '/subrecipes', 'produitsRecettes' => '/shops/' . $sid . '/products/' . $pid . '/recipe'];
    if ($pid > 0) { $essais['produit'] = '/products/' . $pid; $essais['produitMagasin'] = '/shops/' . $sid . '/products/' . $pid; }
    $out = ['rid' => $rid, 'shop' => $sid, 'routes' => []];
    // `brut=1` : les corps entiers du coût par magasin et de la recette (et d'une sous-recette `sous=`), pour lire leur forme complète.
    if (!empty($_GET['brut'])) {
        $out['brut'] = ['coutMagasin' => PanelApi::get('/shops/' . $sid . '/recipes/' . $rid . '/cost'), 'recette' => PanelApi::get('/recipes/' . $rid)];
        $sous = (int) ($_GET['sous'] ?? 0);
        if ($sous > 0) { $out['brut']['sousCout'] = PanelApi::get('/shops/' . $sid . '/recipes/' . $sous . '/cost'); $out['brut']['sousRecette'] = PanelApi::get('/recipes/' . $sous); $out['brut']['sousFiche'] = PanelApi::get('/subrecipes/' . $sous); }
        return $out;
    }
    foreach ($essais as $k => $chemin) {
        $r = PanelApi::sondeGet($chemin, 15);
        $out['routes'][$k] = ['chemin' => $chemin, 'code' => $r['code'] ?? null, 'erreur' => isset($r['erreur']) && $r['erreur'] !== null ? mb_substr((string) $r['erreur'], 0, 160) : null, 'apercu' => $apercu($r['corps'] ?? null)];
    }
    return $out;
}
