<?php
declare(strict_types=1);

/**
 * Recettes à corriger › Matières premières › Références et pertes (demande du 26/11/2026 : « mettre tous les prix
 * moyens Belgique au kg, et les % de perte ; d'abord sur la Tagliata, ensuite sur tous les autres produits »).
 * Décisions du 26/11/2026 : le prix du RÉSEAU, posé comme prix de référence de la matière, sans écraser les prix
 * d'achat des magasins (au panel, un prix local passe avant la référence) ; un prix de marché belge là où aucun
 * magasin n'en a ; les pertes proposées sont posées aussi.
 *
 * GET  /verification/recettes/references[?rid=] — chaque matière d'une recette (sous-recettes dépliées), ou de toutes
 *      les recettes déjà lues : son prix dans chaque magasin, son prix de référence et sa perte au panel, et la
 *      proposition : prix = la moyenne des magasins qui la chiffrent (prix local ou fournisseur, pas une référence),
 *      sinon le prix de marché de data/prix_reference_be.json ; perte = celle du fichier, sinon une perte courante par
 *      famille de matière (VRF_PERTES), sinon l'actuelle.
 * POST /verification/recettes/references {lignes: [{mid, prix (€ par kg, l ou pièce), perte (%)}], rid?, apercu?}
 *      — PATCH /materials/{id} {reference_unit_price_net, waste_amount_perc}, dans la forme du contrat (vmxChangements),
 *      relu et noté au registre des corrections (« Annuler » remet l'avant). Puis le food cost des recettes touchées,
 *      relu au panel avant et après dans chaque magasin (vrcEffet), et si la perte de la matière entre bien dans le
 *      coût de la recette (sinon elle se pose sur les lignes : geste `recette`, changement `perte`).
 */

const VRF_FICHIER = __DIR__ . '/data/prix_reference_be.json';
/** Les recettes relues pour l'effet : la recette demandée et d'autres qui portent les matières écrites. */
const VRF_EFFET_RECETTES = 10;

/**
 * Les pertes courantes en cuisine, par famille de matière fraîche (proposition, à valider) : [motif, perte %, raison].
 * Le premier motif qui prend l'emporte ; les produits transformés (séché, sauce, maison…) n'en reçoivent pas.
 */
const VRF_PERTES = [
    ['/persil|coriandre|basilic|aneth|ciboulette|menthe|estragon|cerfeuil/', 30, 'tiges'],
    ['/radicchio|trevise|endive|chicon/', 15, 'trognon, feuilles extérieures'],
    ['/mesclun|laitue|roquette|iceberg|feuille de chene|frisee|mache|jeunes pousses|epinard/', 10, 'tri, feuilles fanées'],
    ['/echalote|oignon/', 10, 'pelure, extrémités'],
    ['/\bail\b/', 15, 'peau, germe'],
    ['/poireau/', 20, 'vert, racines'],
    ['/brocoli|chou-fleur|chou fleur/', 25, 'tiges, trognon'],
    ['/poivron/', 15, 'pédoncule, graines'],
    ['/celeri/', 15, 'parage'],
    ['/carotte/', 10, 'épluchage'],
    ['/courgette|concombre|aubergine/', 5, 'extrémités'],
    ['/champignon/', 5, 'pied, parage'],
    ['/avocat/', 25, 'peau, noyau'],
    ['/mangue/', 30, 'peau, noyau'],
    ['/ananas/', 45, 'écorce, cœur'],
    ['/grenaille/', 3, 'tri'],
    ['/pomme de terre|pommes de terre/', 15, 'épluchage'],
    ['/tomate/', 3, 'pédoncules, fruits abîmés'],
    ['/parmesan|grana padano|pecorino/', 8, 'croûte'],
    ['/fourme|roquefort|gorgonzola|brie|camembert/', 3, 'croûte'],
    ['/steak|entrecote|rumsteak|faux-filet|faux filet|bavette|filet de b/', 5, 'parage'],
];
/** Les mots d'un produit transformé : pas de perte courante proposée. */
const VRF_TRANSFORME = '/seche|sechee|sauce|tapenade|concasse|coulis|puree|confit|pelee|maison|marine|pesto|huile|vinaigre|surgel|cuit|grille|emince|soupe|quiche|rotolo|tarte/';

/* ---------------------------------------------------------------------------------------------
 * Les pures (bin/tests_verification_correction.php)
 * ------------------------------------------------------------------------------------------- */

/** Un nom en minuscules sans accents, pour les motifs. */
function vrfNorme(string $s): string
{
    $s = mb_strtolower(trim($s));
    return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => "'", "\u{00A0}" => ' ']);
}

/** La perte courante d'une matière fraîche d'après son nom : [perte, raison], ou null (transformée, emballage, inconnue). */
function vrfPerteCourante(string $nom, string $type = ''): ?array
{
    if (strtoupper($type) === 'PACKAGING') { return null; }
    $n = vrfNorme($nom);
    if ($n === '' || preg_match(VRF_TRANSFORME, $n)) { return null; }
    foreach (VRF_PERTES as [$motif, $p, $raison]) { if (preg_match($motif, $n)) { return [$p, $raison]; } }
    return null;
}

/** La moyenne des prix des magasins qui chiffrent la matière par leur propre prix (local ou fournisseur, pas la référence). */
function vrfMoyenneReseau(array $prix): array
{
    $v = [];
    foreach ($prix as $p) {
        if (!is_array($p) || !isset($p['prix']) || !is_numeric($p['prix']) || (float) $p['prix'] <= 0) { continue; }
        if (in_array(strtoupper((string) ($p['src'] ?? '')), ['REFERENCE', 'MISSING'], true)) { continue; }
        $v[] = (float) $p['prix'];
    }
    return ['prix' => $v === [] ? null : array_sum($v) / count($v), 'n' => count($v), 'min' => $v === [] ? null : min($v), 'max' => $v === [] ? null : max($v)];
}

/**
 * La proposition pour une matière. Fonction pure. $l : [mid, nom, type, facteur, prix (par magasin : [prix, src], par
 * unité de base), reference (unité de base), perte] ; $f : le fichier (prix de marché, pertes). Prix rendus par unité de
 * base. `change` dit si la proposition diffère du panel.
 */
function vrfProposition(array $l, array $f): array
{
    $mid = (string) (int) $l['mid'];
    $fac = (float) ($l['facteur'] ?? 1) ?: 1.0;
    $r = vrfMoyenneReseau((array) ($l['prix'] ?? []));
    if ($r['prix'] !== null) { $prix = $r['prix']; $origine = 'reseau'; $source = 'moyenne de ' . $r['n'] . ' magasin(s)' . ($r['min'] !== null && abs($r['max'] - $r['min']) > 1e-9 ? ' (de ' . vfEur($r['min'] * $fac, 2) . ' à ' . vfEur($r['max'] * $fac, 2) . ')' : ''); }
    elseif (isset($f['prix'][$mid]['prix']) && is_numeric($f['prix'][$mid]['prix'])) { $prix = (float) $f['prix'][$mid]['prix'] / $fac; $origine = 'marche'; $source = (string) ($f['prix'][$mid]['source'] ?? 'prix de marché'); }
    else { $prix = null; $origine = null; $source = 'aucun magasin ne la chiffre : prix à saisir'; }
    if (isset($f['pertes'][$mid]['perte']) && is_numeric($f['pertes'][$mid]['perte'])) { $perte = (int) $f['pertes'][$mid]['perte']; $pOrig = 'fichier'; $raison = (string) ($f['pertes'][$mid]['raison'] ?? ''); }
    elseif (($c = vrfPerteCourante((string) ($l['nom'] ?? ''), (string) ($l['type'] ?? ''))) !== null) { [$perte, $raison] = $c; $pOrig = 'courante'; }
    else { $perte = isset($l['perte']) && is_numeric($l['perte']) ? (int) round((float) $l['perte']) : null; $pOrig = 'actuelle'; $raison = ''; }
    $ref = isset($l['reference']) && is_numeric($l['reference']) ? (float) $l['reference'] : null;
    $perteAvant = isset($l['perte']) && is_numeric($l['perte']) ? (int) round((float) $l['perte']) : null;
    $changePrix = $prix !== null && ($ref === null || abs($ref - $prix) > 1e-9 * max(1.0, $prix) + 1e-12);
    $changePerte = $perte !== null && $perte !== $perteAvant;
    return ['prix' => $prix, 'origine' => $origine, 'source' => $source, 'n' => $r['n'], 'perte' => $perte, 'perteOrigine' => $pOrig, 'raison' => $raison,
        'changePrix' => $changePrix, 'changePerte' => $changePerte, 'change' => $changePrix || $changePerte];
}

/* ---------------------------------------------------------------------------------------------
 * Lectures
 * ------------------------------------------------------------------------------------------- */

/** Le fichier des prix de marché et des pertes. */
function vrfFichier(): array
{
    static $f = null;
    if ($f === null) { $j = is_file(VRF_FICHIER) ? json_decode((string) file_get_contents(VRF_FICHIER), true) : null; $f = is_array($j) ? $j : []; }
    return $f;
}

/** Les matières des recettes déjà lues (une recette, ou toutes) : [mid => [nom, u, q (par pièce, recette demandée), recettes]]. */
function vrfMatieres(int $rid): array
{
    $out = [];
    $ajoute = static function (int $r, array $c) use (&$out, $rid): void {
        foreach ((array) ($c['lignes'] ?? []) as $l) {
            $mid = (int) ($l['mid'] ?? 0);
            if ($mid <= 0) { continue; }
            $out[$mid] ??= ['nom' => (string) ($l['nom'] ?? ''), 'u' => (string) ($l['u'] ?? ''), 'q' => null, 'sous' => null, 'recettes' => []];
            $out[$mid]['recettes'][$r] = (string) ($c['nom'] ?? '');
            if ($r === $rid) { $out[$mid]['q'] = ($out[$mid]['q'] ?? 0) + (float) ($l['q'] ?? 0); $out[$mid]['sous'] ??= $l['sous'] ?? null; }
        }
    };
    if ($rid > 0) {
        $c = vfRecettesEnCache([$rid])[$rid] ?? null;
        if ($c === null) { $s = (int) (array_key_first(vfMagasins()) ?? 0); if ($s > 0) { vfLireRecettes([$rid => $s], 1); } $c = vfRecettesEnCache([$rid])[$rid] ?? null; }
        if ($c !== null) { $ajoute($rid, $c); }
        return $out;
    }
    try {
        foreach (Db::rows("SELECT `key`, value FROM ceo_app_setting WHERE `key` LIKE 'vfRec:%' LIMIT 1500") as $row) {
            $v = json_decode((string) $row['value'], true);
            if (is_array($v['v'] ?? null)) { $ajoute((int) substr((string) $row['key'], 6), $v['v']); }
        }
    } catch (Throwable $e) { /* sans cache : rien à proposer */ }
    return $out;
}

/** GET /verification/recettes/references[?rid=] — les matières, leurs prix, leur référence, leur perte, la proposition. */
function ep_verification_references(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $rid = (int) ($_GET['rid'] ?? 0);
    // Une recette cherchée par son nom (« tagliata ») parmi les recettes déjà lues : une seule, on l'ouvre ; sinon, le choix.
    $q = vrfNorme((string) ($_GET['q'] ?? ''));
    if ($rid <= 0 && $q !== '') {
        $trouve = [];
        try {
            foreach (Db::rows("SELECT `key`, value FROM ceo_app_setting WHERE `key` LIKE 'vfRec:%' LIMIT 1500") as $row) {
                $v = json_decode((string) $row['value'], true);
                $nom = (string) ($v['v']['nom'] ?? '');
                if ($nom !== '' && str_contains(vrfNorme($nom), $q)) { $trouve[(int) substr((string) $row['key'], 6)] = $nom; }
            }
        } catch (Throwable $e) { /* sans cache : rien trouvé */ }
        if ($trouve === []) { return ['ok' => false, 'motif' => 'Aucune recette lue ne s’appelle « ' . (string) $_GET['q'] . ' » : saisissez son numéro.']; }
        if (count($trouve) > 1) { asort($trouve); return ['ok' => false, 'choix' => array_map(static fn ($r, $n) => ['rid' => $r, 'nom' => $n], array_keys(array_slice($trouve, 0, 20, true)), array_values(array_slice($trouve, 0, 20, true))), 'motif' => count($trouve) . ' recettes correspondent : choisissez.']; }
        $rid = (int) array_key_first($trouve);
    }
    $mat = vrfMatieres($rid);
    if ($mat === []) {
        return ['ok' => false, 'motif' => $rid > 0 ? 'La recette ' . $rid . ' n’a pas pu être lue au panel (/shops/{id}/recipes/' . $rid . '/cost).' : 'Aucune recette n’a encore été lue : ouvrez d’abord « Recettes à corriger ».'];
    }
    $mags = vfMagasins();
    $M = mfMatieresApi(array_keys($mags), !empty($_GET['rafraichir']));
    $f = vrfFichier();
    $lignes = []; $cpt = ['matieres' => 0, 'aChanger' => 0, 'marche' => 0, 'sansPrix' => 0, 'pertes' => 0];
    foreach ($mat as $mid => $m) {
        $ident = []; $prix = []; $mags_ = [];
        foreach ($mags as $sid => $nomM) {
            $x = $M[$sid][(string) $mid] ?? null;
            if (is_array($x)) { $ident = $ident ?: $x; $prix[$sid] = ['prix' => $x['prix'] ?? null, 'src' => $x['src'] ?? '']; }
            $mags_[] = ['sid' => (int) $sid, 'prix' => is_array($x) ? ($x['prix'] ?? null) : null, 'src' => is_array($x) ? (string) ($x['src'] ?? '') : '', 'lu' => is_array($x)];
        }
        $u = (string) (($ident['unite'] ?? '') ?: $m['u']);
        $U = mfUnite($u);
        $l = ['mid' => $mid, 'nom' => (string) (($ident['nom'] ?? '') ?: $m['nom']), 'type' => (string) ($ident['type'] ?? ''), 'facteur' => $U['facteur'],
            'prix' => $prix, 'reference' => $ident['reference'] ?? null, 'perte' => $ident['perte'] ?? null];
        $p = vrfProposition($l, $f);
        $cpt['matieres']++;
        if ($p['change']) { $cpt['aChanger']++; }
        if ($p['origine'] === 'marche') { $cpt['marche']++; }
        if ($p['prix'] === null) { $cpt['sansPrix']++; }
        if ($p['changePerte']) { $cpt['pertes']++; }
        $lignes[] = ['mid' => $mid, 'nom' => $l['nom'], 'cat' => (string) ($ident['cat'] ?? ''), 'source' => (string) ($ident['source'] ?? ''), 'type' => $l['type'],
            'unite' => $u, 'par' => $U['label'], 'facteur' => $U['facteur'], 'q' => $m['q'], 'sous' => $m['sous'], 'nRecettes' => count($m['recettes']),
            'recettes' => array_slice(array_values($m['recettes']), 0, 4), 'magasins' => $mags_,
            'reference' => $l['reference'], 'perte' => $l['perte'], 'propose' => $p];
    }
    usort($lignes, static fn ($a, $b) => [$b['propose']['change'], $b['nRecettes'], $a['nom']] <=> [$a['propose']['change'], $a['nRecettes'], $b['nom']]);
    return ['ok' => true, 'rid' => $rid, 'nom' => $rid > 0 ? (string) (reset($mat)['recettes'][$rid] ?? '') : '', 'lignes' => $lignes, 'compteurs' => $cpt,
        'magasins' => array_map(static fn ($id, $n) => ['id' => (int) $id, 'nom' => $n], array_keys($mags), array_values($mags)), 'maj' => (string) ($f['maj'] ?? ''),
        'source' => 'prix des magasins : /shops/{id}/materials (prix local ou fournisseur) · référence et perte : la matière, pour tout le réseau · prix de marché et pertes : consultant/src/data/prix_reference_be.json'];
}

/* ---------------------------------------------------------------------------------------------
 * L'écriture
 * ------------------------------------------------------------------------------------------- */

/** Le food cost des recettes dans chaque magasin : [sid => [rid => coût]] (vrcCoutsRecettes). */
function vrfCouts(array $sids, array $rids): array
{
    $o = [];
    foreach ($sids as $s) { $o[(int) $s] = vrcCoutsRecettes((int) $s, $rids); }
    return $o;
}

/** Pour une recette dans un magasin, les matières dont la perte posée entre dans le coût : [mid => vrai|faux]. */
function vrfPertesLues(int $sid, int $rid, array $mids): array
{
    $c = PanelApi::get('/shops/' . $sid . '/recipes/' . $rid . '/cost');
    $c = is_array($c) && isset($c['data']['elements']) ? $c['data'] : $c;
    $out = [];
    $marche = static function (array $els) use (&$marche, &$out, $mids): void {
        foreach ($els as $e) {
            if (!is_array($e)) { continue; }
            if (isset($e['elements']) && is_array($e['elements'])) { $marche($e['elements']); continue; }
            $mid = (int) (vfNum($e, ['material_id', 'ingredient_id']) ?? 0);
            if (in_array($mid, $mids, true)) { $out[$mid] = ($out[$mid] ?? false) || (float) (vfNum($e, ['waste_percent', 'quantity_waste', 'waste_cost_net']) ?? 0) > 0; }
        }
    };
    if (is_array($c)) { $marche((array) ($c['elements'] ?? [])); }
    return $out;
}

/** POST /verification/recettes/references — les prix de référence et les pertes, puis l'effet sur les food costs. */
function wr_verification_references(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(300);
    $b = body();
    $apercu = !empty($b['apercu']); $rid = (int) ($b['rid'] ?? 0);
    $al = vmxAllergenesRef();
    $plans = []; $erreurs = [];
    foreach (array_slice(array_values(array_filter((array) ($b['lignes'] ?? []), 'is_array')), 0, 400) as $x) {
        $mid = (int) ($x['mid'] ?? 0);
        if ($mid <= 0) { continue; }
        $o = vrcObjet('matiere', $mid);
        if ($o === null) { $erreurs[] = 'La matière ' . $mid . ' n’a pas pu être lue au panel.'; continue; }
        $nom = vfTxt($o, ['name']) ?: ('matière ' . $mid);
        $U = mfUnite((string) ($o['unit_name'] ?? ($x['unite'] ?? '')));
        $champs = [];
        if (isset($x['prix']) && $x['prix'] !== '' && $x['prix'] !== null) {
            if (!is_numeric($x['prix']) || (float) $x['prix'] <= 0) { $erreurs[] = $nom . ' : prix invalide.'; continue; }
            $champs['reference_unit_price_net'] = (float) $x['prix'] / $U['facteur'];
        }
        if (isset($x['perte']) && $x['perte'] !== '' && $x['perte'] !== null) {
            if (!is_numeric($x['perte']) || (float) $x['perte'] < 0 || (float) $x['perte'] >= 100) { $erreurs[] = $nom . ' : perte invalide (de 0 à 99 %).'; continue; }
            $champs['waste_amount_perc'] = (int) round((float) $x['perte']);
        }
        $m = vmxChangements($o, $champs, $al);
        foreach ($m['erreurs'] as $e) { $erreurs[] = $nom . ' : ' . $e; }
        if ($m['corps'] === []) { continue; }
        $lisible = array_map(static fn ($t) => str_replace(['Prix général (référence du panel)', 'Perte (%)'], ['Prix de référence', 'Perte'], $t), $m['lisible']);
        if (isset($champs['reference_unit_price_net'])) { $lisible[] = vfEur((float) $x['prix'], 2) . ' / ' . $U['label']; }
        $plans[] = ['mid' => $mid, 'nom' => $nom, 'corps' => $m['corps'], 'inverse' => $m['inverse'], 'resume' => $nom . ' : ' . implode(' · ', $lisible), 'perte' => $champs['waste_amount_perc'] ?? null];
    }
    if ($plans === []) { return ['ok' => false, 'error' => $erreurs !== [] ? implode(' ', $erreurs) : 'Rien à changer : le panel porte déjà ces prix et ces pertes.']; }
    // Les recettes relues pour l'effet : la recette demandée d'abord, puis d'autres qui portent ces matières.
    $rids = $rid > 0 ? [$rid] : [];
    foreach ($plans as $p) { foreach (array_keys(vrcRecettesDeMatiere($p['mid'])) as $r) { if (count($rids) >= VRF_EFFET_RECETTES) { break 2; } if (!in_array($r, $rids, true)) { $rids[] = $r; } } }
    $sids = array_map('intval', array_keys(vfMagasins()));
    if ($rid <= 0) { $sids = array_slice($sids, 0, VRC_EFFET_MAGASINS); }
    if ($apercu) {
        return ['apercu' => true, 'resume' => count($plans) . ' matière(s) au panel, pour tout le réseau ; puis le food cost de ' . count($rids) . ' recette(s) relu dans ' . count($sids) . ' magasin(s), avant et après',
            'ecritures' => array_map(static fn ($p) => ['methode' => 'PATCH', 'route' => vrcChemin('matiere', $p['mid']), 'corps' => $p['corps'], 'annulable' => true, 'resume' => $p['resume']], $plans),
            'erreurs' => $erreurs];
    }
    $avant = vrfCouts($sids, $rids);
    $faites = [];
    foreach ($plans as $p) {
        $r = vrcEcrireObjet(['geste' => 'matiere', 'cle' => 'ref:' . $p['mid'], 'pid' => null, 'nom' => $p['nom'], 'shop' => null, 'objet' => 'matiere', 'id' => $p['mid'], 'objetId' => $p['mid'],
            'corps' => $p['corps'], 'inverse' => $p['inverse'], 'resume' => $p['resume']]);
        $faites[] = vrcLigneRegistre($r);
    }
    $okN = count(array_filter($faites, static fn ($f) => $f['resultat'] === 'ok'));
    $effet = [];
    if ($okN > 0) {
        vrcOublier('vmpTable'); vrcOublier('vfAnalyse');
        foreach ($sids as $s) { vrcOublier('matieresApi:' . $s); }
        foreach ($rids as $r) { vrcOublier('vfRec:' . $r); }
        $apres = vrfCouts($sids, $rids);
        // Les recettes relues rentrent au cache tout de suite : la liste, la recherche et « toute la carte » les gardent.
        if ($sids !== []) { vfLireRecettes(array_fill_keys($rids, $sids[0]), count($rids)); }
        foreach ($sids as $s) {
            $e = vrcEffet($avant[$s] ?? [], $apres[$s] ?? []);
            if ($e !== []) { $effet[] = ['sid' => $s, 'magasin' => vfMagasins()[$s] ?? ('magasin ' . $s), 'resume' => vrcEffetResume($e), 'lignes' => $e]; }
        }
    }
    // La perte de la matière entre-t-elle dans le coût de la recette ? Sinon, elle se pose sur les lignes de la recette.
    $pertes = null;
    $avecPerte = array_values(array_filter($plans, static fn ($p) => $p['perte'] !== null && $p['perte'] > 0));
    if ($okN > 0 && $rid > 0 && $avecPerte !== [] && $sids !== []) {
        $lues = vrfPertesLues($sids[0], $rid, array_map(static fn ($p) => $p['mid'], $avecPerte));
        $non = array_values(array_filter($avecPerte, static fn ($p) => isset($lues[$p['mid']]) && !$lues[$p['mid']]));
        $pertes = ['lues' => $lues, 'aPoser' => array_map(static fn ($p) => ['mid' => $p['mid'], 'nom' => $p['nom'], 'p' => $p['perte']], $non),
            'message' => $non === [] ? 'La perte posée sur les matières entre dans le coût de la recette.'
                : 'La perte de ' . count($non) . ' matière(s) n’entre pas dans le coût de la recette : le panel lit la perte de la ligne. « Poser les pertes sur les lignes » la met dans la recette.'];
    }
    return ['ok' => $okN === count($faites), 'ecritures' => $faites, 'effet' => $effet, 'pertes' => $pertes, 'erreurs' => $erreurs];
}
