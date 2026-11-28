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
 * Demande du 26/11/2026 au soir : « tous les magasins doivent avoir le même food cost » et « les pertes sur toute la
 * carte ». D'où l'alignement : un magasin dont le prix propre s'écarte de la référence la reprend (prix du magasin,
 * POST /materials/{id}/manual-price, geste `prix` des corrections, au registre) ; et la perte de chaque matière posée
 * sur ses lignes, dans toutes les recettes et sous-recettes (le coût d'une recette ne lit que la perte de la ligne :
 * mesuré au panel le 26/11/2026 sur la Salade Tagliata).
 *
 * POST /verification/recettes/references {lignes: [{mid, prix (€ par kg, l ou pièce), perte (%), aligner?}], rid?, apercu?, sansEffet?}
 *      — PATCH /materials/{id} {reference_unit_price_net, waste_amount_perc}, dans la forme du contrat (vmxChangements),
 *      relu et noté au registre des corrections (« Annuler » remet l'avant). Puis le food cost des recettes touchées,
 *      relu au panel avant et après dans chaque magasin (vrcEffet), et si la perte de la matière entre bien dans le
 *      coût de la recette (sinon elle se pose sur les lignes : geste `recette`, changement `perte`). `aligner` : les
 *      magasins dont le prix propre diffère prennent le prix de référence. `sansEffet` : sans relire les coûts (les lots
 *      de toute la carte).
 * POST /verification/recettes/pertes-lignes {depuis, lot, apercu?} — une tranche des recettes de la carte (GET /recipes) :
 *      dans chaque recette et sous-recette, la ligne d'une matière qui porte une perte au panel prend cette perte.
 *      Rend la suite (`suivant`) : l'écran enchaîne les tranches jusqu'au bout.
 */

const VRF_FICHIER = __DIR__ . '/data/prix_reference_be.json';
/** Les recettes relues pour l'effet : la recette demandée et d'autres qui portent les matières écrites. */
const VRF_EFFET_RECETTES = 10;
/** L'écart (en relatif) au-delà duquel le prix propre d'un magasin s'aligne sur la référence. */
const VRF_ECART_ALIGNER = 0.001;
/** Les recettes d'une tranche de « pertes sur les lignes ». */
const VRF_LOT_PERTES = 15;
// Les prix de magasin tenus par un fournisseur intégré (la centrale) : le panel refuse de les écrire (403
// SUPPLIER_MANAGED_PRICE, règle integrated-supplier-manual-write-block, mesuré le 14/11 et le 27/11/2026). Appris au
// premier refus, gardés ici : [sid => [mid => date]]. Le même food cost se fait alors en alignant les autres magasins
// sur ce tarif.
const VRF_VERROUS = 'vrfVerrous';

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
    $fv = vrfMoyenneReseau(array_filter((array) ($l['prix'] ?? []), static fn ($p) => is_array($p) && !empty($p['verrou'])));
    if ($fv['prix'] !== null) {
        // Un magasin paie le tarif d'un fournisseur intégré, que seul ce fournisseur change : la référence le reprend, et les
        // autres magasins s'y alignent (le même food cost partout).
        $prix = $fv['prix']; $origine = 'fournisseur'; $r['n'] = $fv['n'];
        $source = 'tarif du fournisseur intégré (' . $fv['n'] . ' magasin(s)), que seul le fournisseur change : les autres magasins s’y alignent';
    }
    elseif ($r['prix'] !== null) { $prix = $r['prix']; $origine = 'reseau'; $source = 'moyenne de ' . $r['n'] . ' magasin(s)' . ($r['min'] !== null && abs($r['max'] - $r['min']) > 1e-9 ? ' (de ' . vfEur($r['min'] * $fac, 2) . ' à ' . vfEur($r['max'] * $fac, 2) . ')' : ''); }
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

/**
 * Les magasins dont le prix propre (local ou fournisseur) s'écarte d'un prix (par unité de base) : [sid, …]. Un prix tenu
 * par un fournisseur intégré (`verrou`) n'y est pas : le panel ne laisse pas l'écrire.
 */
function vrfAAligner(array $prix, ?float $ref): array
{
    if ($ref === null || $ref <= 0) { return []; }
    $out = [];
    foreach ($prix as $sid => $p) {
        if (!is_array($p) || !is_numeric($p['prix'] ?? null) || in_array(strtoupper((string) ($p['src'] ?? '')), ['REFERENCE', 'MISSING'], true)) { continue; }
        if (!empty($p['verrou'])) { continue; }
        if (abs((float) $p['prix'] - $ref) > VRF_ECART_ALIGNER * $ref) { $out[] = (int) $sid; }
    }
    return $out;
}

/** Les prix de magasin tenus par un fournisseur intégré, appris aux refus du panel : [sid => [mid => date]]. */
function vrfVerrous(): array
{
    try { $v = setting(VRF_VERROUS, []); } catch (Throwable $e) { $v = []; }
    return is_array($v) ? $v : [];
}

function vrfEstVerrou(array $v, int $sid, int $mid): bool
{
    return isset($v[(string) $sid][(string) $mid]) || isset($v[$sid][$mid]);
}

function vrfVerrouiller(int $sid, int $mid): void
{
    $v = vrfVerrous();
    $v[(string) $sid][(string) $mid] = date('Y-m-d');
    try { vfGarderBrut(VRF_VERROUS, $v); } catch (Throwable $e) { /* sans base : appris à nouveau au prochain refus */ }
}

/** Le refus d'un prix tenu par un fournisseur intégré (403 SUPPLIER_MANAGED_PRICE). */
function vrfRefusFournisseur(array $r): bool
{
    return ($r['resultat'] ?? '') === 'refuse' && str_contains((string) ($r['message'] ?? '') . ' ' . (string) ($r['reponse'] ?? ''), 'SUPPLIER_MANAGED_PRICE');
}

/** La ligne du registre d'un prix laissé au fournisseur : pas une erreur, une information. */
function vrfLigneFournisseur(array $r, string $magasin): array
{
    $l = vrcLigneRegistre($r);
    $l['resultat'] = 'fournisseur'; $l['resultatNom'] = 'tarif du fournisseur, inchangé';
    $l['message'] = 'À ' . $magasin . ', ce prix vient du tarif d’un fournisseur intégré (la centrale) : le panel ne laisse que ce fournisseur le changer. Les autres magasins s’alignent sur lui.';
    return $l;
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
    $V = vrfVerrous();
    $lignes = []; $cpt = ['matieres' => 0, 'aChanger' => 0, 'marche' => 0, 'sansPrix' => 0, 'pertes' => 0, 'aAligner' => 0, 'fournisseur' => 0];
    foreach ($mat as $mid => $m) {
        $ident = []; $prix = []; $mags_ = [];
        foreach ($mags as $sid => $nomM) {
            $x = $M[$sid][(string) $mid] ?? null;
            $vr = vrfEstVerrou($V, (int) $sid, (int) $mid);
            if (is_array($x)) { $ident = $ident ?: $x; $prix[$sid] = ['prix' => $x['prix'] ?? null, 'src' => $x['src'] ?? '', 'verrou' => $vr]; }
            $mags_[] = ['sid' => (int) $sid, 'prix' => is_array($x) ? ($x['prix'] ?? null) : null, 'src' => is_array($x) ? (string) ($x['src'] ?? '') : '', 'lu' => is_array($x), 'verrou' => $vr];
        }
        $u = (string) (($ident['unite'] ?? '') ?: $m['u']);
        $U = mfUnite($u);
        $l = ['mid' => $mid, 'nom' => (string) (($ident['nom'] ?? '') ?: $m['nom']), 'type' => (string) ($ident['type'] ?? ''), 'facteur' => $U['facteur'],
            'prix' => $prix, 'reference' => $ident['reference'] ?? null, 'perte' => $ident['perte'] ?? null];
        $p = vrfProposition($l, $f);
        $cpt['matieres']++;
        if ($p['change']) { $cpt['aChanger']++; }
        if ($p['origine'] === 'marche') { $cpt['marche']++; }
        if ($p['origine'] === 'fournisseur') { $cpt['fournisseur']++; }
        if ($p['prix'] === null) { $cpt['sansPrix']++; }
        if ($p['changePerte']) { $cpt['pertes']++; }
        $aligner = vrfAAligner($prix, $p['prix']);
        if ($aligner !== []) { $cpt['aAligner']++; }
        $lignes[] = ['mid' => $mid, 'nom' => $l['nom'], 'cat' => (string) ($ident['cat'] ?? ''), 'source' => (string) ($ident['source'] ?? ''), 'type' => $l['type'],
            'unite' => $u, 'par' => $U['label'], 'facteur' => $U['facteur'], 'q' => $m['q'], 'sous' => $m['sous'], 'nRecettes' => count($m['recettes']),
            'recettes' => array_slice(array_values($m['recettes']), 0, 4), 'magasins' => $mags_,
            'reference' => $l['reference'], 'perte' => $l['perte'], 'propose' => $p, 'aligner' => $aligner];
    }
    usort($lignes, static fn ($a, $b) => [$b['propose']['change'] || $b['aligner'] !== [], $b['nRecettes'], $a['nom']] <=> [$a['propose']['change'] || $a['aligner'] !== [], $a['nRecettes'], $b['nom']]);
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
    $apercu = !empty($b['apercu']); $rid = (int) ($b['rid'] ?? 0); $sansEffet = !empty($b['sansEffet']);
    $al = vmxAllergenesRef();
    $sidsTous = array_map('intval', array_keys(vfMagasins()));
    $M = null;   // les prix des magasins, lus une fois (frais) s'il faut aligner
    $V = vrfVerrous();
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
        // L'alignement : les magasins dont le prix propre s'écarte du prix de référence (envoyé, sinon celui du panel).
        $aligner = [];
        $refBase = isset($champs['reference_unit_price_net']) ? (float) $champs['reference_unit_price_net'] : (is_numeric($o['reference_unit_price_net'] ?? null) ? (float) $o['reference_unit_price_net'] : null);
        if (!empty($x['aligner']) && $refBase !== null && $refBase > 0) {
            $M ??= mfMatieresApi($sidsTous, true);
            $px = [];
            $px = vrfPrixMagasins($M, $sidsTous, $mid, $V);
            foreach (vrfAAligner($px, $refBase) as $s) { $aligner[$s] = $px[$s]['prix']; }
        }
        if ($m['corps'] === [] && $aligner === []) { continue; }
        $lisible = array_map(static fn ($t) => str_replace(['Prix général (référence du panel)', 'Perte (%)'], ['Prix de référence', 'Perte'], $t), $m['lisible']);
        if (isset($champs['reference_unit_price_net'])) { $lisible[] = vfEur((float) $x['prix'], 2) . ' / ' . $U['label']; }
        $plans[] = ['mid' => $mid, 'nom' => $nom, 'corps' => $m['corps'], 'inverse' => $m['inverse'], 'resume' => $nom . ' : ' . implode(' · ', $lisible), 'perte' => $champs['waste_amount_perc'] ?? null,
            'aligner' => $aligner, 'refBase' => $refBase, 'facteur' => $U['facteur'], 'par' => $U['label']];
    }
    if ($plans === []) { return ['ok' => false, 'error' => $erreurs !== [] ? implode(' ', $erreurs) : 'Rien à changer : le panel porte déjà ces prix et ces pertes.']; }
    // Les recettes relues pour l'effet : la recette demandée d'abord, puis d'autres qui portent ces matières.
    $rids = $rid > 0 ? [$rid] : [];
    foreach ($plans as $p) { foreach (array_keys(vrcRecettesDeMatiere($p['mid'])) as $r) { if (count($rids) >= VRF_EFFET_RECETTES) { break 2; } if (!in_array($r, $rids, true)) { $rids[] = $r; } } }
    $sids = array_map('intval', array_keys(vfMagasins()));
    if ($rid <= 0) { $sids = array_slice($sids, 0, VRC_EFFET_MAGASINS); }
    if ($sansEffet) { $rids = []; }
    $nAl = array_sum(array_map(static fn ($p) => count($p['aligner']), $plans));
    if ($apercu) {
        $ec = [];
        foreach ($plans as $p) {
            if ($p['corps'] !== []) { $ec[] = ['methode' => 'PATCH', 'route' => vrcChemin('matiere', $p['mid']), 'corps' => $p['corps'], 'annulable' => true, 'resume' => $p['resume']]; }
            foreach ($p['aligner'] as $s => $avantPx) {
                $ec[] = vrcPrixPlan($s, $p['mid'], $p['refBase']) + ['annulable' => true, 'resume' => vrcPrixResume($p['nom'], $s, $p['refBase'], (float) $avantPx) . ' (aligné sur la référence)'];
            }
        }
        return ['apercu' => true, 'resume' => count(array_filter($plans, static fn ($p) => $p['corps'] !== [])) . ' matière(s) au panel, pour tout le réseau'
            . ($nAl > 0 ? ' ; ' . $nAl . ' prix de magasin aligné(s) sur la référence (même food cost partout)' : '')
            . ($rids !== [] ? ' ; puis le food cost de ' . count($rids) . ' recette(s) relu dans ' . count($sids) . ' magasin(s), avant et après' : ''),
            'ecritures' => $ec, 'erreurs' => $erreurs];
    }
    $avant = $rids !== [] ? vrfCouts($sids, $rids) : [];
    $faites = [];
    foreach ($plans as $p) {
        $okMat = true;
        if ($p['corps'] !== []) {
            $r = vrcEcrireObjet(['geste' => 'matiere', 'cle' => 'ref:' . $p['mid'], 'pid' => null, 'nom' => $p['nom'], 'shop' => null, 'objet' => 'matiere', 'id' => $p['mid'], 'objetId' => $p['mid'],
                'corps' => $p['corps'], 'inverse' => $p['inverse'], 'resume' => $p['resume']]);
            $faites[] = vrcLigneRegistre($r);
            $okMat = $r['resultat'] === 'ok';
        }
        // Le même prix dans tous les magasins : chaque prix propre qui s'écarte reprend la référence (au registre, « Annuler »).
        if ($okMat) {
            $fixes = vrfAligner($p, $p['aligner'], (float) $p['refBase'], $faites);
            // Un magasin tient le tarif d'un fournisseur intégré : la référence le reprend, et les autres magasins s'y alignent.
            if ($fixes !== []) {
                $ref2 = array_sum($fixes) / count($fixes);
                if (abs($ref2 - (float) $p['refBase']) > VRF_ECART_ALIGNER * (float) $p['refBase']) {
                    $o = vrcObjet('matiere', $p['mid']);
                    $m2 = $o !== null ? vmxChangements($o, ['reference_unit_price_net' => $ref2], $al) : ['corps' => [], 'inverse' => null, 'lisible' => []];
                    $ok2 = true;
                    if ($m2['corps'] !== []) {
                        $r = vrcEcrireObjet(['geste' => 'matiere', 'cle' => 'ref:' . $p['mid'], 'pid' => null, 'nom' => $p['nom'], 'shop' => null, 'objet' => 'matiere', 'id' => $p['mid'], 'objetId' => $p['mid'],
                            'corps' => $m2['corps'], 'inverse' => $m2['inverse'], 'resume' => $p['nom'] . ' : prix de référence ' . vfEur($ref2 * $p['facteur'], 2) . ' / ' . $p['par'] . ', le tarif du fournisseur intégré']);
                        $faites[] = vrcLigneRegistre($r);
                        $ok2 = $r['resultat'] === 'ok';
                    }
                    if ($ok2) {
                        foreach ($sidsTous as $s) { vrcOublier('matieresApi:' . $s); }
                        $px2 = vrfPrixMagasins(mfMatieresApi($sidsTous, true), $sidsTous, $p['mid'], vrfVerrous());
                        $al2 = [];
                        foreach (vrfAAligner($px2, $ref2) as $s) { $al2[$s] = $px2[$s]['prix']; }
                        vrfAligner($p, $al2, $ref2, $faites);
                    }
                }
            }
        }
    }
    $okN = count(array_filter($faites, static fn ($f) => $f['resultat'] === 'ok'));
    $nInfo = count(array_filter($faites, static fn ($f) => $f['resultat'] === 'fournisseur'));
    $effet = [];
    if ($okN > 0) {
        vrcOublier('vmpTable'); vrcOublier('vfAnalyse');
        foreach ($sids as $s) { vrcOublier('matieresApi:' . $s); }
        foreach ($rids as $r) { vrcOublier('vfRec:' . $r); }
        $apres = $rids !== [] ? vrfCouts($sids, $rids) : [];
        // Les recettes relues rentrent au cache tout de suite : la liste, la recherche et « toute la carte » les gardent.
        if ($sids !== [] && $rids !== []) { vfLireRecettes(array_fill_keys($rids, $sids[0]), count($rids)); }
        foreach ($sids as $s) {
            $e = vrcEffet($avant[$s] ?? [], $apres[$s] ?? []);
            if ($e !== []) { $effet[] = ['sid' => $s, 'magasin' => vfMagasins()[$s] ?? ('magasin ' . $s), 'resume' => vrcEffetResume($e), 'lignes' => $e]; }
        }
    }
    // La perte de la matière entre-t-elle dans le coût de la recette ? Sinon, elle se pose sur les lignes de la recette.
    $pertes = null;
    $avecPerte = array_values(array_filter($plans, static fn ($p) => $p['perte'] !== null && $p['perte'] > 0));
    if ($okN > 0 && $rid > 0 && !$sansEffet && $avecPerte !== [] && $sids !== []) {
        $lues = vrfPertesLues($sids[0], $rid, array_map(static fn ($p) => $p['mid'], $avecPerte));
        $non = array_values(array_filter($avecPerte, static fn ($p) => isset($lues[$p['mid']]) && !$lues[$p['mid']]));
        $pertes = ['lues' => $lues, 'aPoser' => array_map(static fn ($p) => ['mid' => $p['mid'], 'nom' => $p['nom'], 'p' => $p['perte']], $non),
            'message' => $non === [] ? 'La perte posée sur les matières entre dans le coût de la recette.'
                : 'La perte de ' . count($non) . ' matière(s) n’entre pas dans le coût de la recette : le panel lit la perte de la ligne. « Poser les pertes sur les lignes » la met dans la recette.'];
    }
    return ['ok' => $okN + $nInfo === count($faites), 'ecritures' => $faites, 'effet' => $effet, 'pertes' => $pertes, 'erreurs' => $erreurs];
}

/** Les prix des magasins pour une matière : [sid => [prix, src, verrou]]. */
function vrfPrixMagasins(array $M, array $sids, int $mid, array $V): array
{
    $px = [];
    foreach ($sids as $s) {
        $y = $M[$s][(string) $mid] ?? null;
        if (is_array($y)) { $px[$s] = ['prix' => $y['prix'] ?? null, 'src' => $y['src'] ?? '', 'verrou' => vrfEstVerrou($V, (int) $s, $mid)]; }
    }
    return $px;
}

/**
 * Aligne des magasins sur un prix (unité de base) ; les lignes vont dans $faites. Un prix que le panel laisse au
 * fournisseur intégré n'est pas une erreur : il est appris (vrfVerrous) et rendu, [sid => son prix].
 */
function vrfAligner(array $p, array $aligner, float $ref, array &$faites): array
{
    $fixes = [];
    foreach ($aligner as $s => $avantPx) {
        $r = vrcEcrirePrix(['geste' => 'prix', 'cle' => 'aligner:' . $p['mid'], 'pid' => null, 'nom' => $p['nom'], 'shop' => $s, 'coutAvant' => null, 'coutApres' => null],
            (int) $s, (int) $p['mid'], $ref, $p['nom'], (float) $avantPx);
        if (vrfRefusFournisseur($r)) {
            vrfVerrouiller((int) $s, (int) $p['mid']);
            $fixes[(int) $s] = (float) $avantPx;
            $faites[] = vrfLigneFournisseur($r, vfMagasins()[$s] ?? ('magasin ' . $s));
            continue;
        }
        $faites[] = vrcLigneRegistre($r);
    }
    return $fixes;
}

/* ---------------------------------------------------------------------------------------------
 * Les pertes sur les lignes, toute la carte
 * ------------------------------------------------------------------------------------------- */

/** Les recettes de la carte (GET /recipes : celles rattachées à un produit), gardées dix minutes ; sinon celles déjà lues. */
function vrfRecettesCarte(): array
{
    $c = vfCache('vrfCarte', 600);
    if ($c !== null) { return array_map('intval', $c['v']); }
    $ids = [];
    foreach (analyseListe(PanelApi::get('/recipes')) as $r) { if (is_array($r) && (int) ($r['id'] ?? 0) > 0) { $ids[(int) $r['id']] = true; } }
    if ($ids === []) {
        try { foreach (Db::rows("SELECT `key` FROM ceo_app_setting WHERE `key` LIKE 'vfRec:%' LIMIT 1500") as $row) { $ids[(int) substr((string) $row['key'], 6)] = true; } }
        catch (Throwable $e) { /* rien à parcourir */ }
    }
    $ids = array_keys($ids); sort($ids);
    if ($ids !== []) { vfGarder('vrfCarte', $ids); }
    return $ids;
}

/**
 * Les changements de perte d'un objet (recette ou sous-recette) : chaque ligne de matière prend la perte que la matière
 * porte au panel ($pertes : [mid => perte]), quand elle en porte une. Fonction pure.
 */
function vrfChangementsPerte(array $o, array $pertes): array
{
    $ch = [];
    foreach ((array) ($o['materials'] ?? []) as $e) {
        if (!is_array($e)) { continue; }
        $cle = vrcIdLigne($e, 'materials');
        $mid = $cle === '' ? 0 : (int) substr($cle, 1);
        $w = $pertes[$mid] ?? null;
        if ($w === null || $w <= 0) { continue; }
        $wl = $e['waste_amount_perc'] ?? ($e['pivot']['waste_amount_perc'] ?? 0);
        if (abs((float) $wl - (float) $w) > 1e-9) { $ch[] = ['type' => 'perte', 'cle' => $cle, 'p' => (float) $w]; }
    }
    return $ch;
}

/** POST /verification/recettes/pertes-lignes {depuis, lot, apercu?} — une tranche de la carte ; `suivant` pour la suite. */
function wr_verification_pertes_lignes(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(300);
    $b = body();
    $apercu = !empty($b['apercu']);
    $depuis = max(0, (int) ($b['depuis'] ?? 0)); $lot = min(40, max(1, (int) ($b['lot'] ?? VRF_LOT_PERTES)));
    $rids = vrfRecettesCarte();
    if ($rids === []) { return ['ok' => false, 'error' => 'La liste des recettes du panel n’a pas pu être lue (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $tranche = array_slice($rids, $depuis, $lot);
    // Les pertes des matières, telles que le panel les porte (une matière vaut pour tout le réseau : un magasin suffit).
    $sid0 = (int) (array_key_first(vfMagasins()) ?? 0);
    $pertes = [];
    foreach ((mfMatieresApi([$sid0], $depuis === 0)[$sid0] ?? []) as $mid => $m) { if (is_array($m) && is_numeric($m['perte'] ?? null)) { $pertes[(int) $mid] = (float) $m['perte']; } }
    $ecritures = []; $erreurs = []; $vus = [];
    foreach ($tranche as $rid) {
        foreach (vrcArbre($rid) as $k => $o) {
            if (isset($vus[$k])) { continue; }
            $vus[$k] = true;
            [$objet, $id] = explode(':', (string) $k);
            $ch = vrfChangementsPerte($o, $pertes);
            if ($ch === []) { continue; }
            $m = vrcModifier($o, $ch);
            if ($m['corps'] === []) { continue; }
            $c = vrcCorpsContrat($objet, $o, $m['corps']); $inv = vrcCorpsContrat($objet, $o, $m['inverse']);
            $nom = vfTxt($o, ['name']) ?: ($objet . ' ' . $id);
            if ($c['erreurs'] !== [] || $c['lignes'] === null) { $erreurs[] = $nom . ' : ' . implode(' ', $c['erreurs']); continue; }
            $resume = ($objet === 'sousRecette' ? 'Sous-recette « ' . $nom . ' » : ' : $nom . ' : ') . implode(' · ', $m['lisible']);
            if ($apercu) { $ecritures[] = ['methode' => 'PATCH', 'route' => vrcChemin($objet, (int) $id), 'corps' => $c['lignes'], 'annulable' => true, 'resume' => $resume]; continue; }
            $r = vrcEcrireObjet(['geste' => 'recette', 'cle' => 'perte:' . $k, 'pid' => null, 'nom' => $nom, 'shop' => null, 'objet' => $objet, 'id' => (int) $id, 'objetId' => (int) $id,
                'corps' => $c['lignes'], 'inverse' => $inv['lignes'], 'resume' => $resume]);
            $ecritures[] = vrcLigneRegistre($r);
            if ($objet === 'recette') { vrcOublier('vfRec:' . (int) $id); }
        }
    }
    $fin = $depuis + count($tranche);
    if (!$apercu && $fin >= count($rids)) { vrcOublier('vfAnalyse'); vrcOublier('vmpTable'); }
    $okN = count(array_filter($ecritures, static fn ($e) => ($e['resultat'] ?? 'ok') === 'ok'));
    return ['ok' => true, 'apercu' => $apercu, 'total' => count($rids), 'depuis' => $depuis, 'fin' => $fin, 'suivant' => $fin < count($rids) ? $fin : null,
        'ecritures' => $ecritures, 'nOk' => $apercu ? 0 : $okN, 'nRefus' => $apercu ? 0 : count($ecritures) - $okN, 'erreurs' => $erreurs, 'matieresAvecPerte' => count(array_filter($pertes, static fn ($w) => $w > 0))];
}
