<?php
declare(strict_types=1);

/**
 * Recettes à corriger › Matières premières (demande du 14/11/2026 : « en bascule, un tableau avec toutes les matières
 * premières utilisées dans les recettes, où je peux adapter avec le prix de référence fournisseur, en liste
 * déroulante »). Maquette : docs/maquettes/matieres-reference.
 *
 * GET /verification/recettes/matieres[?rafraichir=1] — les matières des recettes des produits vendus ces 30 jours
 * (recettes déjà lues par la liste, `vfRec:*`), et pour chacune :
 *   · les recettes qui l'emploient (produit, CA sur 30 jours, quantité par pièce, coût de la pièce par magasin) ;
 *   · son prix dans chaque magasin (/shops/{id}/materials, ramené au kg, au l ou à la pièce) ;
 *   · les prix de référence proposés dans la liste déroulante : la liste de prix EN VIGUEUR de chaque fournisseur lié à
 *     la matière (/material-suppliers + catalog-mappings, /material-suppliers/{f}/shops/{s}/price-lists/current : prix
 *     du colis ÷ taille), le prix conseillé ou de référence du panel s'il existe, la médiane des magasins ;
 *   · ce qui cloche : sans prix dans un magasin, écart de plus de 10 % à la référence, unité à vérifier (colis en
 *     pièces pour une matière en grammes, ou un emballage compté en grammes).
 * Lecture seule, gardée dix minutes (`vmpTable`). L'écriture d'un prix passe par le geste `prix` des corrections
 * (verification_correction.php) : aperçu, écriture, relecture, registre, « Annuler ».
 */

const VMP_SEC = 600;
const VMP_ECART = 10.0;   // % d'écart entre le prix d'un magasin et la référence

/** Les unités qui se comptent en poids ou en volume, face à celles qui se comptent en pièces. */
function vmpFamille(string $u): string
{
    $u = strtolower(trim($u));
    if (in_array($u, ['g', 'gr', 'gramme', 'grammes', 'kg', 'kilogramme'], true)) { return 'poids'; }
    if (in_array($u, ['ml', 'cl', 'l', 'litre'], true)) { return 'volume'; }
    if (in_array($u, ['pcs', 'pce', 'pc', 'piece', 'pièce', 'szt', 'unit', 'st', 'stuk'], true)) { return 'piece'; }
    return $u;
}

/**
 * Les prix de référence d'une matière, pour un magasin : les fournisseurs liés (liste de prix en vigueur), le prix
 * conseillé ou de référence du panel, la médiane des autres magasins. Fonction pure. Les prix sont par unité de base
 * (g, ml, pièce) ; `conversion` dit quand le colis ne se compte pas dans l'unité de la matière.
 *
 * @param array $liens   [[fid, nom, type, sku, parColis, taille, tailleUnite], …]
 * @param array $listes  [fid => ['c' => [sku => ligne], 'lu' => bool]] — la liste de prix de CE magasin
 */
function vmpOptions(string $uniteBase, array $liens, array $listes, ?float $conseille, ?float $reference, ?float $mediane, string $nomMatiere): array
{
    $out = [];
    $fam = vmpFamille($uniteBase);
    foreach ($liens as $l) {
        $pl = $listes[$l['fid']] ?? ['c' => [], 'lu' => false];
        $ligne = $l['sku'] !== '' ? ($pl['c'][$l['sku']] ?? null) : null;
        if ($ligne === null) {
            foreach ((array) $pl['c'] as $x) { if (($x['nomMatiere'] ?? '') !== '' && mb_strtolower($x['nomMatiere']) === mb_strtolower($nomMatiere)) { $ligne = $x; break; } }
        }
        // Une option par référence (fournisseur + SKU) : un même fournisseur peut lier deux colis à la matière (mesuré le 14/11 :
        // Quiche saumon & épinard, SLFood, 70,92 € et 102,86 € les 8) ; les fondre mêlait le colis de l'une au prix de l'autre.
        $o = ['type' => 'fournisseur', 'id' => 'f' . $l['fid'] . ($l['sku'] !== '' ? '-' . $l['sku'] : ''), 'nom' => $l['nom'] . (count(array_filter($liens, static fn ($x) => $x['fid'] === $l['fid'])) > 1 && $l['sku'] !== '' ? ' · réf. ' . $l['sku'] : ''), 'centrale' => $l['type'] === 'CENTRAL', 'listeLue' => (bool) ($pl['lu'] ?? false), 'enVigueur' => $ligne !== null];
        if ($ligne === null || !($ligne['prix'] > 0)) { $out[] = $o + ['prix' => null]; continue; }
        $taille = $ligne['taille'] ?? $l['taille']; $tu = ($ligne['tailleUnite'] ?? '') !== '' ? $ligne['tailleUnite'] : $l['tailleUnite'];
        $parColisU = $l['parColis'] !== null && $l['parColis'] > 0 ? $l['parColis'] : 1.0;
        $parPaquet = mfParUnite($ligne['prix'], $taille, $l['parColis']);   // prix par unité du colis (pièce, g, kg…)
        $famColis = vmpFamille($tu);
        $o += ['colis' => ['prix' => $ligne['prix'], 'taille' => $taille, 'unite' => $tu, 'parColis' => $parColisU], 'depuis' => $ligne['depuis'] ?? null];
        if ($famColis === 'piece' && ($fam === 'poids' || $fam === 'volume')) {
            // Un colis de pièces pour une matière en grammes : le poids d'une pièce, à poser, fait la conversion.
            // Le poids d'une pièce se saisit toujours en g (ou ml) ; l'écran le ramène à l'unité de la matière.
            $out[] = $o + ['prix' => null, 'parPiece' => $parPaquet !== null ? round($parPaquet, 6) : null, 'conversion' => $fam === 'poids' ? 'g' : 'ml'];
            continue;
        }
        // Même famille : le colis peut être en kg pour une matière en g (× 1/1000), ou l'inverse.
        $fac = 1.0;
        $ub = strtolower($uniteBase); $uc = strtolower($tu);
        if (in_array($uc, ['kg', 'kilogramme', 'l', 'litre'], true) && in_array($ub, ['g', 'gr', 'gramme', 'grammes', 'ml'], true)) { $fac = 0.001; }
        if (in_array($uc, ['g', 'gr', 'ml'], true) && in_array($ub, ['kg', 'l'], true)) { $fac = 1000.0; }
        $out[] = $o + ['prix' => $parPaquet !== null ? round($parPaquet * $fac, 8) : null];
    }
    if ($conseille !== null && $conseille > 0) { $out[] = ['type' => 'conseille', 'id' => 'conseille', 'nom' => 'Prix conseillé du panel', 'prix' => $conseille]; }
    if ($reference !== null && $reference > 0) { $out[] = ['type' => 'reference', 'id' => 'reference', 'nom' => 'Prix de référence du panel', 'prix' => $reference]; }
    if ($mediane !== null && $mediane > 0) { $out[] = ['type' => 'mediane', 'id' => 'mediane', 'nom' => 'Médiane des magasins', 'prix' => $mediane]; }
    return $out;
}

/** La référence d'une matière : la première liste de prix en vigueur chiffrée, sinon le prix conseillé, de référence, la médiane. */
function vmpReference(array $options): ?array
{
    foreach ($options as $o) { if (($o['prix'] ?? null) !== null && $o['prix'] > 0) { return $o; } }
    return null;
}

/** Le tableau, calculé (sans cache). */
function vmpCalcul(bool $frais): array
{
    $mags = vfMagasins(); $sids = array_keys($mags);
    $V = vfVentes($sids, VF_JOURS);
    $D = vfDispo($sids);
    // Les produits vendus : leur recette, leur CA, leur coût par magasin.
    $produits = []; $ridsPid = [];
    foreach ($V['ventes'] as $sid => $ps) {
        foreach ($ps as $pid => $v) {
            if (($v['ca'] ?? 0) <= 0) { continue; }
            $p = $D[$sid][$pid] ?? null;
            $rid = $p['recette'] ?? null;
            if ($rid === null) { foreach ($D as $ps2) { if (($ps2[$pid]['recette'] ?? null) !== null) { $rid = $ps2[$pid]['recette']; break; } } }
            if ($rid === null) { continue; }
            $produits[$pid] ??= ['pid' => (int) $pid, 'nom' => (string) (($p['nom'] ?? '') ?: ($v['nom'] ?? '')), 'rid' => (int) $rid, 'ca' => 0.0, 'cout' => []];
            $produits[$pid]['ca'] += (float) $v['ca'];
            if (($p['cout'] ?? null) !== null) { $produits[$pid]['cout'][(string) $sid] = round((float) $p['cout'], 4); }
            $ridsPid[(int) $rid][(int) $pid] = true;
        }
    }
    $R = vfRecettesEnCache(array_keys($ridsPid));
    // Les matières des recettes lues : qui les emploie, combien par pièce.
    $mat = [];
    foreach ($R as $rid => $r) {
        foreach ((array) ($r['lignes'] ?? []) as $l) {
            $mid = (int) ($l['mid'] ?? 0);
            if ($mid <= 0) { continue; }
            $mat[$mid] ??= ['mid' => $mid, 'nom' => (string) ($l['nom'] ?? ''), 'u' => (string) ($l['u'] ?? ''), 'recettes' => []];
            foreach (array_keys($ridsPid[$rid] ?? []) as $pid) {
                $k = (string) $pid;
                if (!isset($mat[$mid]['recettes'][$k])) {
                    $mat[$mid]['recettes'][$k] = ['pid' => $pid, 'nom' => $produits[$pid]['nom'], 'ca' => round($produits[$pid]['ca'], 2), 'q' => 0.0, 'cout' => $produits[$pid]['cout']];
                }
                $mat[$mid]['recettes'][$k]['q'] += (float) ($l['q'] ?? 0);
            }
        }
    }
    $M = function_exists('mfMatieresApi') ? mfMatieresApi($sids, $frais) : [];
    $F = function_exists('mfFournisseurs') ? mfFournisseurs(false) : ['f' => [], 'm' => []];
    // Les listes de prix des fournisseurs liés à ces matières, magasin par magasin.
    $listes = [];
    foreach (array_keys($mat) as $mid) {
        foreach ($F['m'][(string) $mid] ?? [] as $lien) { foreach ($sids as $sid) { $listes[$sid][$lien['fid']] ??= null; } }
    }
    foreach ($listes as $sid => $fs) { foreach (array_keys($fs) as $fid) { $listes[$sid][$fid] = mfListePrix((string) $fid, (int) $sid, $frais); } }

    $lignes = []; $cpt = ['matieres' => 0, 'sansPrix' => 0, 'sansFournisseur' => 0, 'ecart' => 0, 'unite' => 0];
    foreach ($mat as $mid => $m) {
        $ident = null; foreach ($sids as $sid) { if (isset($M[$sid][(string) $mid])) { $ident = $M[$sid][(string) $mid]; break; } }
        $uniteBase = (string) ($ident['unite'] ?? $m['u']);
        $U = mfUnite($uniteBase); $fac = $U['facteur'];
        $valeurs = [];
        foreach ($sids as $sid) { $p = $M[$sid][(string) $mid]['prix'] ?? null; if ($p !== null && $p > 0) { $valeurs[$sid] = (float) $p; } }
        $med = $valeurs !== [] ? vfMediane(array_values($valeurs)) : null;
        $liens = [];
        foreach ($F['m'][(string) $mid] ?? [] as $lien) {
            $f = $F['f'][$lien['fid']] ?? ['nom' => 'Fournisseur ' . $lien['fid'], 'type' => ''];
            $liens[] = ['fid' => (string) $lien['fid'], 'nom' => $f['nom'], 'type' => $f['type'], 'sku' => (string) $lien['sku'], 'parColis' => $lien['parColis'], 'taille' => $lien['taille'], 'tailleUnite' => (string) $lien['tailleUnite']];
        }
        $mags_ = []; $sansPrix = 0; $ecart = 0; $optsParMag = []; $conversion = false;
        foreach ($sids as $sid) {
            $a = $M[$sid][(string) $mid] ?? null;
            $lu = ($M[$sid] ?? []) !== [];
            $autres = $valeurs; unset($autres[$sid]);
            $opts = vmpOptions($uniteBase, $liens, array_map(static fn ($x) => $x ?? ['c' => [], 'lu' => false], $listes[$sid] ?? []),
                $a['conseille'] ?? ($ident['conseille'] ?? null), $a['reference'] ?? ($ident['reference'] ?? null), $autres !== [] ? vfMediane(array_values($autres)) : null, $m['nom']);
            foreach ($opts as $o) { if (!empty($o['conversion'])) { $conversion = true; } }
            $ref = vmpReference($opts);
            $p = $valeurs[$sid] ?? null;
            $e = $p !== null && $ref !== null ? round(100 * ($p - $ref['prix']) / $ref['prix'], 1) : null;
            if ($lu && $p === null) { $sansPrix++; }
            if ($e !== null && abs($e) > VMP_ECART) { $ecart++; }
            $mags_[] = ['id' => (string) $sid, 'nom' => $mags[$sid], 'lu' => $lu, 'prix' => $p, 'ecart' => $e, 'ref' => $ref['id'] ?? null];
            $optsParMag[(string) $sid] = $opts;
        }
        // Les options de la liste déroulante : une par source, avec le prix de chaque magasin (les listes de prix sont par magasin).
        $options = [];
        foreach ($optsParMag as $sid => $opts) {
            foreach ($opts as $o) {
                $k = $o['id'];
                $options[$k] ??= array_diff_key($o, ['prix' => 1, 'parPiece' => 1]) + ['prixMagasins' => [], 'parPieceMagasins' => []];
                $options[$k]['prixMagasins'][$sid] = $o['prix'] ?? null;
                if (isset($o['parPiece'])) { $options[$k]['parPieceMagasins'][$sid] = $o['parPiece']; }
                if (!empty($o['enVigueur'])) { $options[$k]['enVigueur'] = true; }
            }
        }
        $emballage = preg_match(VF_HORS_RECETTE, $m['nom'] . ' ' . ($ident['cat'] ?? '')) === 1 && vmpFamille($uniteBase) === 'poids';
        $sansFourn = $liens === [] || !array_filter($options, static fn ($o) => $o['type'] === 'fournisseur' && !empty($o['enVigueur']));
        $cpt['matieres']++;
        if ($sansPrix > 0) { $cpt['sansPrix']++; }
        if ($sansFourn) { $cpt['sansFournisseur']++; }
        if ($ecart > 0) { $cpt['ecart']++; }
        if ($conversion || $emballage) { $cpt['unite']++; }
        $rec = array_values($m['recettes']);
        usort($rec, static fn ($x, $y) => $y['ca'] <=> $x['ca']);
        $lignes[] = ['mid' => $mid, 'nom' => $m['nom'] !== '' ? $m['nom'] : (string) ($ident['nom'] ?? ('matière ' . $mid)), 'cat' => (string) ($ident['cat'] ?? ''),
            'source' => (string) ($ident['source'] ?? ''), 'uniteBase' => $uniteBase, 'par' => $U['label'], 'facteur' => $fac,
            'recettes' => array_slice($rec, 0, 12), 'nRecettes' => count($rec), 'ca' => round(array_sum(array_column($rec, 'ca')), 2),
            'magasins' => $mags_, 'options' => array_values($options),
            'drapeaux' => ['sansPrix' => $sansPrix, 'ecart' => $ecart, 'sansFournisseur' => $sansFourn, 'unite' => $conversion || $emballage, 'emballage' => $emballage, 'conversion' => $conversion]];
    }
    usort($lignes, static fn ($x, $y) => [$y['drapeaux']['sansPrix'] > 0, $y['ca']] <=> [$x['drapeaux']['sansPrix'] > 0, $x['ca']] ?: strcmp($x['nom'], $y['nom']));
    $nR = count($ridsPid); $nLues = count($R);
    return ['lignes' => $lignes, 'compteurs' => $cpt, 'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n], array_keys($mags), array_values($mags)),
        'recettes' => ['vendues' => $nR, 'lues' => $nLues], 'seuilEcart' => VMP_ECART, 'formatPrix' => function_exists('vrcFormatPrix') ? vrcFormatPrix() : null,
        'liens' => function_exists('vrcLiens') ? vrcLiens() : [], 'lu' => date('c'),
        'source' => 'recettes : celles des produits vendus ces 30 jours, déjà lues par la liste (/shops/{id}/recipes/{recette}/cost) · prix des magasins : /shops/{id}/materials · fournisseurs : /material-suppliers, catalog-mappings, connected-materials, /material-suppliers/{f}/shops/{s}/price-lists/current'];
}

/** GET /verification/recettes/matieres[?rafraichir=1] — gardé dix minutes. */
function ep_verification_matieres_prix(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).', 'lignes' => []]; }
    $frais = !empty($_GET['rafraichir']);
    if (!$frais) { $c = vfCache('vmpTable', VMP_SEC); if ($c !== null) { return $c['v'] + ['cache' => ['le' => date('c', (int) $c['le'])]]; } }
    @set_time_limit(120);
    $t = vmpCalcul($frais);
    vfGarder('vmpTable', $t);
    return $t + ['cache' => null];
}
