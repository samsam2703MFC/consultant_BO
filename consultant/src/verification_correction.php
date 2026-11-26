<?php
declare(strict_types=1);

/**
 * Vérification › Recettes à corriger : corriger d'ici, ou ouvrir le bon écran du panel (demande du 13/11/2026 :
 * « dans les problèmes de recette, qu'on puisse en direct si possible corriger, ou un lien direct » — « tout, CEO
 * seul »). Maquette : docs/maquettes/recettes-correction ; plan : docs/plan-verification.md § 3.5.
 *
 * Le cockpit ÉCRIT au panel, avec son compte consultant, comme il écrit déjà les tâches et les réclamations. Depuis le
 * swagger du 26/11/2026, les routes d'écriture ont un contrat (docs/audit-api-ecriture-recettes.md), et il ne fusionne
 * pas, il REMPLACE :
 *   - PATCH /recipes/{id} avec des lignes exige les DEUX listes (`materials`, `subrecipes`), remplace aussi les
 *     préparations, et ignore les champs simples : le rendement part dans un second PATCH, sans liste ;
 *   - PATCH /subrecipes/{id} avec des lignes exige aussi name, yield_quantity, id_unit, id_storage, id_category ; une
 *     liste omise y est vidée et les préparations sont remises à zéro ;
 *   - une portion n'a pas de fraction libre : seulement `portion_type` (ONE_QUARTER, ONE_HALF…) ;
 *   - jamais de champ photo dans un PATCH de recette (bug documenté : les fichiers sont effacés).
 * D'où une seule façon d'écrire, prudente :
 *
 *   1. lire l'objet tel que le panel le rend (GET /recipes/{id}, /subrecipes/{id}, /products/{id}, /product-portions/{id}) ;
 *      GET /recipes/{id} ne rend pas le rendement d'une recette de produit : il se lit dans /recipes/{id}/cost ;
 *   2. ne changer QUE la clé visée, puis envoyer le corps COMPLET qu'exige le contrat (vrcCorpsContrat) : la liste non
 *      touchée et les préparations telles que lues, les champs simples d'une sous-recette ;
 *   3. relire l'objet et CONSTATER que le changement y est. Un 2xx sans changement visible est « sans effet » ;
 *   4. tout noter au registre `con_verif_correction` : l'avant (le corps inverse, pour « Annuler »), l'après, la route,
 *      le code HTTP, le résultat.
 *
 * Rien ne part sans un geste du CEO : chaque correction se montre d'abord (`apercu`), puis s'applique. Le registre
 * permet d'annuler : le corps inverse repart par la même route, et se constate de la même façon.
 *
 * Les gestes (POST /verification/recettes/corriger, { geste, apercu?, cle?, pid?, … }) :
 *   quantite   — une ligne de recette ou de sous-recette : nouvelle quantité, ou ×1000 / ÷1000 (unité suspecte) ;
 *   recette    — plusieurs changements d'un coup, depuis le volet (quantités, lignes retirées ou ajoutées, rendement) ;
 *   rendement  — le rendement de la recette ;
 *   copier     — remplir une recette vide avec les lignes d'une recette proche ;
 *   rattacher  — rattacher une recette existante à un produit vendu sans recette (PATCH /products/{id}) ;
 *   portion    — la fraction d'une portion (PATCH /product-portions/{id}) ;
 *   prix       — le prix d'une matière dans un magasin (route réglable : `vrcFormatPrix`), puis le food cost relu au
 *                panel dans les recettes qui la portent, avant et après (vrcEffet) : on voit si le prix a pris ;
 *   recalculer — recalculer le coût des recettes d'un magasin (POST /shops/{id}/recipes/recalculate), sans retour ;
 *   horsRecette — marquer une matière « hors recette » : rien n'est écrit au panel, elle ne remonte plus.
 *
 * Après une écriture réussie, la recette est relue (/cost) et l'analyse recalculée depuis les caches : la ligne quitte la
 * liste si elle est devenue plausible, et la réponse porte la liste à jour.
 *
 * « CEO seul » : ces routes passent par la session du cockpit (routeGardee), jamais par le jeton de service ni par
 * l'application des consultants ; le journal les note au nom du CEO.
 */

const VRC_PROFONDEUR = 4;
const VRC_CLES_QTE = ['required_quantity', 'quantity', 'recipe_quantity', 'qty', 'amount'];
const VRC_CLES_MID = ['id_material', 'material_id', 'id_raw_material'];
const VRC_CLES_SID = ['id_subrecipe', 'subrecipe_id', 'id_sub_recipe'];
const VRC_CLES_REND = ['yield_quantity', 'yield'];
const VRC_CLES_PART = ['portion_size', 'size', 'fraction', 'portion'];
const VRC_CLES_LISTES = ['materials', 'subrecipes'];
const VRC_GESTES = ['quantite', 'recette', 'rendement', 'copier', 'rattacher', 'portion', 'prix', 'recalculer', 'horsRecette'];
/** Les champs simples qu'exige le PATCH des lignes d'une sous-recette (contrat du 26/11/2026). */
const VRC_SOUS_SIMPLES = ['name', 'yield_quantity', 'id_unit', 'id_storage', 'id_category'];
/** Ce qui repart avec les lignes sans être relu : les préparations reviennent sous une autre forme, l'identité ne change pas. */
const VRC_NON_RELUS = ['preparation_types', 'preparation_steps', 'name', 'id_unit', 'id_storage', 'id_category'];
/** Les types de portion du panel et leur fraction : il n'y en a pas d'autre (contrat du 26/11/2026). */
const VRC_PORTIONS = ['ONE_EIGHTH' => 1 / 8, 'ONE_SIXTH' => 1 / 6, 'ONE_QUARTER' => 1 / 4, 'ONE_THIRD' => 1 / 3, 'ONE_HALF' => 1 / 2,
    'TWO_THIRDS' => 2 / 3, 'THREE_QUARTERS' => 3 / 4];
/** Le nombre de recettes dont le food cost est relu après un prix, par magasin (trois magasins au plus). */
const VRC_EFFET_MAX = 30;
const VRC_EFFET_MAGASINS = 3;

/* ---------------------------------------------------------------------------------------------
 * Lire ce que le panel rend — fonctions pures (bin/tests_verification_correction.php)
 * ------------------------------------------------------------------------------------------- */

/** Un objet du panel débarrassé de son enveloppe (`data`, `recipe`…) ; null pour une liste ou une réponse vide. */
function vrcDeballer(mixed $d, array $cles = ['data', 'recipe', 'subrecipe', 'product', 'portion', 'material']): ?array
{
    if (!is_array($d) || $d === []) { return null; }
    foreach ($cles as $k) { if (isset($d[$k]) && is_array($d[$k]) && $d[$k] !== [] && !array_is_list($d[$k])) { $d = $d[$k]; } }
    return array_is_list($d) ? null : $d;
}

/** Le premier nombre d'une entrée parmi des clés, à plat puis dans son `pivot` : [clé, valeur, dans le pivot]. */
function vrcCle(array $e, array $cles): array
{
    foreach ($cles as $k) { if (array_key_exists($k, $e) && (is_numeric($e[$k]) || $e[$k] === null)) { return [$k, $e[$k] === null ? null : (float) $e[$k], false]; } }
    if (isset($e['pivot']) && is_array($e['pivot'])) {
        foreach ($cles as $k) { if (array_key_exists($k, $e['pivot']) && (is_numeric($e['pivot'][$k]) || $e['pivot'][$k] === null)) { return [$k, $e['pivot'][$k] === null ? null : (float) $e['pivot'][$k], true]; } }
    }
    return [null, null, false];
}

/** L'identifiant d'une ligne : la matière (`m12`) ou la sous-recette (`s7`) qu'elle désigne ; '' si illisible. */
function vrcIdLigne(array $e, string $liste): string
{
    $cles = $liste === 'subrecipes' ? VRC_CLES_SID : VRC_CLES_MID;
    [$k, $v] = vrcCle($e, $cles);
    if ($v === null && isset($e['id']) && is_numeric($e['id'])) { $v = (float) $e['id']; }
    return $v !== null && $v > 0 ? ($liste === 'subrecipes' ? 's' : 'm') . (int) $v : '';
}

/** Le nom d'une unité, à plat ou dans un objet `unit`. */
function vrcUnite(array $e): string
{
    foreach (['unit_name', 'unit_short_name', 'base_unit_name', 'unit_symbol'] as $k) { if (isset($e[$k]) && is_string($e[$k]) && trim($e[$k]) !== '') { return trim($e[$k]); } }
    foreach (['unit', 'base_unit'] as $k) { if (isset($e[$k]) && is_array($e[$k])) { $u = vfTxt($e[$k], ['short_name', 'symbol', 'name']); if ($u !== '') { return $u; } } }
    return '';
}

/**
 * Une recette ou une sous-recette ramenée à ce que le volet affiche et que l'écriture modifie : ses lignes de
 * matière, ses sous-recettes, son rendement, et la clé de chaque quantité. `modifiable` est faux quand une ligne n'a
 * pas de quantité lisible : on ne réécrit pas ce qu'on ne sait pas relire.
 */
function vrcLire(array $o, string $type, int $id): array
{
    $lignes = []; $sous = []; $sansCle = 0;
    foreach (VRC_CLES_LISTES as $liste) {
        foreach (array_values((array) ($o[$liste] ?? [])) as $i => $e) {
            if (!is_array($e)) { continue; }
            [$cq, $q] = vrcCle($e, VRC_CLES_QTE);
            if ($cq === null) { $sansCle++; }
            $cle = vrcIdLigne($e, $liste);
            $x = ['cle' => $cle, 'i' => $i, 'nom' => vfTxt($e, ['name', 'material_name', 'subrecipe_name']) ?: ($cle !== '' ? $cle : 'ligne ' . ($i + 1)),
                'q' => $q, 'u' => vrcUnite($e), 'cleQte' => $cq];
            if ($liste === 'subrecipes') { $x['id'] = (int) substr($cle, 1); $sous[] = $x; } else { $x['mid'] = (int) substr($cle, 1); $lignes[] = $x; }
        }
    }
    [$cr, $rend] = vrcCle($o, VRC_CLES_REND);
    return ['type' => $type, 'id' => $id, 'nom' => vfTxt($o, ['name', 'recipe_name', 'label']), 'rendement' => $rend, 'cleRend' => $cr,
        'lignes' => $lignes, 'sous' => $sous, 'modifiable' => $sansCle === 0 && (isset($o['materials']) || isset($o['subrecipes'])),
        'cles' => array_slice(array_keys($o), 0, 40)];
}

/** Une quantité lisible même petite : « 0,002 kg », « 0,0008 l », « 12 », « 2,5 kg ». */
function vrcQte(?float $q, string $u = ''): string
{
    if ($q === null) { return '—'; }
    $a = abs($q);
    $d = $a >= 10 || abs($q - round($q)) < 1e-9 ? (abs($q - round($q)) < 1e-9 ? 0 : 1) : ($a >= 1 ? 2 : ($a >= 0.01 ? 3 : 6));
    $t = number_format($q, $d, ',', ' ');
    if ($d > 0) { $t = rtrim(rtrim($t, '0'), ','); }
    return $t . ($u !== '' ? ' ' . $u : '');
}

/**
 * Une ligne reprise d'une AUTRE recette, prête à entrer dans celle-ci : sans son `connection_id` (mesuré le 13/11/2026 :
 * chaque ligne de GET /recipes/{id} porte l'identifiant de son lien à SA recette ; le renvoyer ailleurs viserait ce lien-là).
 */
function vrcSansLien(array $e): array
{
    foreach (['connection_id', 'pivot_id', 'recipe_material_id', 'id_recipe', 'recipe_id', 'id_subrecipe_parent'] as $k) { unset($e[$k]); }
    if (isset($e['pivot']) && is_array($e['pivot'])) { unset($e['pivot']['id'], $e['pivot']['recipe_id'], $e['pivot']['id_recipe']); }
    return $e;
}

/**
 * Une ligne de recette dans la forme du contrat de PATCH /recipes/{id} (swagger test.tfbuddy.com, lu le 15/11/2026) :
 * {id (la matière ou la sous-recette), quantity (nombre), connection_id (la ligne existante, absente d'une ligne
 * copiée), waste_amount_perc}. Les autres clés lues (nom, unité, catégorie) ne repartent pas. Null si illisible.
 */
function vrcLigneContrat(array $e, string $liste): ?array
{
    $id = vrcIdLigne($e, $liste);
    $q = vrcCle($e, VRC_CLES_QTE)[1];
    if ($id === '' || $q === null) { return null; }
    $o = ['id' => (int) substr($id, 1), 'quantity' => (float) $q];
    $lien = $e['connection_id'] ?? ($e['pivot']['id'] ?? null);
    if (is_numeric($lien)) { $o['connection_id'] = (int) $lien; }
    // La perte ne vaut que pour une matière : le contrat exige zéro sur une sous-recette, on ne l'envoie donc pas.
    $perte = $e['waste_amount_perc'] ?? ($e['pivot']['waste_amount_perc'] ?? null);
    if (is_numeric($perte) && $liste !== 'subrecipes') { $o['waste_amount_perc'] = (float) $perte; }
    return $o;
}

/** Les étapes de préparation comme PATCH les attend : des textes, dans l'ordre (GET rend {step_number, step_description}). */
function vrcEtapesContrat(mixed $etapes): array
{
    $l = [];
    foreach (array_values((array) $etapes) as $i => $e) {
        if (is_string($e)) { $t = trim($e); $n = (float) $i; }
        elseif (is_array($e)) { $t = trim((string) ($e['step_description'] ?? ($e['description'] ?? ''))); $n = is_numeric($e['step_number'] ?? null) ? (float) $e['step_number'] : (float) $i; }
        else { continue; }
        if ($t !== '') { $l[] = [$n, $i, $t]; }
    }
    usort($l, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return array_column($l, 2);
}

/** Les types de préparation comme PATCH les attend : {id, duration_second} (GET y ajoute le nom). */
function vrcTypesContrat(mixed $types): array
{
    $l = [];
    foreach ((array) $types as $t) { if (is_array($t) && is_numeric($t['id'] ?? null)) { $l[] = ['id' => (int) $t['id'], 'duration_second' => (int) ($t['duration_second'] ?? 0)]; } }
    return $l;
}

/**
 * Le corps qu'exige le contrat (swagger du 26/11/2026) pour des clés changées ($corps, celles de vrcModifier), à partir
 * de l'objet lu ($o). Fonction pure. Rend :
 *   lignes  — le PATCH des lignes, ou null : les DEUX listes (la non touchée telle que lue), les préparations telles
 *             que lues et, pour une sous-recette, ses champs simples (le rendement changé y voyage) ;
 *   simples — le PATCH des champs simples (le rendement d'une recette de produit), ou null : le panel les ignore dans
 *             un PATCH de lignes ;
 *   erreurs — ce qui empêche d'écrire sans rien perdre.
 */
function vrcCorpsContrat(string $objet, array $o, array $corps): array
{
    $simples = array_diff_key($corps, array_flip(VRC_CLES_LISTES));
    $erreurs = [];
    $lignes = null;
    if (array_intersect_key($corps, array_flip(VRC_CLES_LISTES)) !== []) {
        $lignes = [];
        foreach (VRC_CLES_LISTES as $l) {
            $src = array_values(array_filter((array) (array_key_exists($l, $corps) ? $corps[$l] : ($o[$l] ?? [])), 'is_array'));
            $lignes[$l] = array_values(array_filter(array_map(static fn ($e) => vrcLigneContrat($e, $l), $src)));
            $lues = array_filter((array) ($o[$l] ?? []), 'is_array');
            $illisible = count($lignes[$l]) !== count($src) || array_filter($lues, static fn ($e) => vrcLigneContrat($e, $l) === null) !== [];
            if ($illisible) { $erreurs[] = 'Une ligne de « ' . $l . ' » n’a pas d’identifiant ou de quantité lisible : la liste repartirait sans elle, rien n’est écrit.'; }
        }
        if ($objet === 'recette' && $lignes['materials'] === [] && $lignes['subrecipes'] === []) { $erreurs[] = 'Le panel refuse une recette de produit sans aucune ligne.'; }
        // Les préparations repartent telles que lues ; absentes de la lecture, on ne les invente pas.
        if (array_key_exists('preparation_types', $o)) { $lignes['preparation_types'] = vrcTypesContrat($o['preparation_types']); }
        if (array_key_exists('preparation_steps', $o)) { $lignes['preparation_steps'] = vrcEtapesContrat($o['preparation_steps']); }
        if ($objet === 'sousRecette') {
            foreach (VRC_SOUS_SIMPLES as $k) {
                $v = array_key_exists($k, $simples) ? $simples[$k] : ($o[$k] ?? null);
                unset($simples[$k]);
                if ($v === null || $v === '') { $erreurs[] = 'La sous-recette n’a pas été lue en entier (« ' . $k . ' » manque) : le panel exige ce champ avec ses lignes.'; continue; }
                $lignes[$k] = $k === 'name' ? (string) $v : (is_numeric($v) ? $v + 0 : $v);
            }
        }
    }
    return ['lignes' => $lignes, 'simples' => $simples === [] ? null : $simples, 'erreurs' => $erreurs];
}

/** La fraction d'une portion telle que le panel la rend : son type, sa valeur de calcul, sa fraction ; null sinon. */
function vrcFractionPortion(array $p): ?float
{
    $t = (string) ($p['portion_type'] ?? '');
    if (isset(VRC_PORTIONS[$t])) { return VRC_PORTIONS[$t]; }
    if (is_numeric($p['portion_calculation_value'] ?? null)) { return (float) $p['portion_calculation_value']; }
    $f = $p['portion_fraction'] ?? null;
    if (is_array($f) && is_numeric($f['numerator'] ?? null) && (float) ($f['denominator'] ?? 0) > 0) { return (float) $f['numerator'] / (float) $f['denominator']; }
    $v = vrcCle($p, VRC_CLES_PART)[1];
    return $v !== null && $v > 0 && $v < 1 ? $v : null;
}

/** Le type de portion du panel pour une fraction (1/8, 1/6, 1/4, 1/3, 1/2, 2/3, 3/4) ; null s'il n'existe pas. */
function vrcTypePortion(float $f): ?string
{
    foreach (VRC_PORTIONS as $t => $v) { if (abs($v - $f) < 0.002) { return $t; } }
    return null;
}

/** Une fraction lisible : « 1/4 », « 2/3 ». */
function vrcFractionTexte(?float $f): string
{
    if ($f === null) { return '—'; }
    foreach ([2, 3, 4, 6, 8] as $d) { $n = $f * $d; if (abs($n - round($n)) < 0.01) { return (int) round($n) . '/' . $d; } }
    return vrcQte($f, '');
}

/** Pose une quantité dans une entrée, là où le panel la met (à plat ou dans son pivot). */
function vrcPoserQte(array $e, float $q): array
{
    [$k, , $pivot] = vrcCle($e, VRC_CLES_QTE);
    $k ??= 'quantity';
    if ($pivot) { $e['pivot'][$k] = $q; } else { $e[$k] = $q; }
    return $e;
}

/**
 * Applique des changements à une recette ou sous-recette telle que le panel la rend. Fonction pure.
 * Changements : {type: qte, cle: 'm12'|'s7', q} · {type: facteur, cle, f} · {type: retirer, cle} · {type: rendement, n}
 *               · {type: ajouter, entree, q} · {type: remplacer, materials, subrecipes}.
 * Rend [corps (les seules clés changées), inverse (les mêmes clés, valeurs d'avant), lisible (phrases), erreurs].
 */
function vrcModifier(array $o, array $changements): array
{
    $corps = []; $lisible = []; $erreurs = [];
    $listes = [];
    foreach (VRC_CLES_LISTES as $l) { $listes[$l] = array_values(array_filter((array) ($o[$l] ?? []), 'is_array')); }
    $touche = [];
    $trouver = static function (string $cle) use (&$listes): ?array {
        $liste = str_starts_with($cle, 's') ? 'subrecipes' : 'materials';
        foreach ($listes[$liste] as $i => $e) { if (vrcIdLigne($e, $liste) === $cle) { return [$liste, $i]; } }
        return null;
    };
    foreach ($changements as $c) {
        $t = (string) ($c['type'] ?? '');
        if ($t === 'rendement') {
            $n = (float) ($c['n'] ?? 0);
            if ($n <= 0) { $erreurs[] = 'Le rendement doit être positif.'; continue; }
            [$cr, $avant] = vrcCle($o, VRC_CLES_REND);
            // Mesuré le 13/11 et le 26/11/2026 : GET /recipes/{id} ne rend pas le rendement d'une recette de produit ;
            // vrcObjet le reprend de /recipes/{id}/cost. Sans clé lue, on n'écrit pas une clé devinée.
            if ($cr === null) { $erreurs[] = 'Le rendement de cette recette n’a été lu ni par GET /recipes/{id} ni par /recipes/{id}/cost : il se pose au panel.'; continue; }
            $corps[$cr] = $n;
            $lisible[] = 'Rendement ' . ($avant === null ? '—' : vrcQte($avant, '')) . ' → ' . vrcQte($n, '');
            continue;
        }
        if ($t === 'remplacer') {
            foreach (VRC_CLES_LISTES as $l) { if (isset($c[$l]) && is_array($c[$l])) { $listes[$l] = array_map('vrcSansLien', array_values(array_filter($c[$l], 'is_array'))); $touche[$l] = true; } }
            $lisible[] = 'Lignes copiées : ' . count($listes['materials']) . ' matière(s), ' . count($listes['subrecipes']) . ' sous-recette(s)';
            continue;
        }
        if ($t === 'ajouter') {
            $e = is_array($c['entree'] ?? null) ? $c['entree'] : null;
            $q = (float) ($c['q'] ?? 0);
            if ($e === null || $q <= 0) { $erreurs[] = 'Ligne à ajouter illisible.'; continue; }
            $liste = isset($c['liste']) && $c['liste'] === 'subrecipes' ? 'subrecipes' : 'materials';
            $cle = vrcIdLigne($e, $liste);
            if ($cle === '' || $trouver($cle) !== null) { $erreurs[] = 'La ligne « ' . (vfTxt($e, ['name']) ?: $cle) . ' » est déjà dans la recette.'; continue; }
            $listes[$liste][] = vrcPoserQte(vrcSansLien($e), $q); $touche[$liste] = true;
            $lisible[] = '+ ' . (vfTxt($e, ['name']) ?: $cle) . ' : ' . vrcQte($q, vrcUnite($e));
            continue;
        }
        $cle = (string) ($c['cle'] ?? '');
        $pos = $cle !== '' ? $trouver($cle) : null;
        if ($pos === null) { $erreurs[] = 'Ligne ' . $cle . ' introuvable dans la recette lue au panel.'; continue; }
        [$liste, $i] = $pos;
        $e = $listes[$liste][$i];
        $nom = vfTxt($e, ['name', 'material_name']) ?: $cle;
        if ($t === 'retirer') {
            array_splice($listes[$liste], $i, 1); $touche[$liste] = true;
            $lisible[] = '− ' . $nom;
            continue;
        }
        [$cq, $qAvant] = vrcCle($e, VRC_CLES_QTE);
        if ($cq === null) { $erreurs[] = 'La quantité de « ' . $nom . ' » n’a pas de clé lisible.'; continue; }
        $q = $t === 'facteur' ? ($qAvant ?? 0) * (float) ($c['f'] ?? 1) : (float) ($c['q'] ?? -1);
        if (!($q > 0)) { $erreurs[] = 'Quantité invalide pour « ' . $nom . ' ».'; continue; }
        $q = round($q, 6);
        $listes[$liste][$i] = vrcPoserQte($e, $q); $touche[$liste] = true;
        $lisible[] = $nom . ' : ' . vrcQte($qAvant, vrcUnite($e)) . ' → ' . vrcQte($q, vrcUnite($e));
    }
    // La forme du contrat (swagger test.tfbuddy.com, 15/11/2026) : {id, quantity, connection_id?, waste_amount_perc?} par ligne.
    foreach ($touche as $l => $_) { $corps[$l] = array_values(array_filter(array_map(static fn ($e) => vrcLigneContrat($e, $l), $listes[$l]))); }
    $inverse = [];
    foreach ($corps as $k => $_) { $inverse[$k] = $o[$k] ?? (in_array($k, VRC_CLES_LISTES, true) ? [] : null); }
    return ['corps' => $corps, 'inverse' => $inverse, 'lisible' => $lisible, 'erreurs' => $erreurs];
}

/** Ce qu'une valeur veut dire, pour comparer l'envoyé et le relu : une liste devient {ligne => quantité}. */
function vrcProjection(string $cle, mixed $v): mixed
{
    if (in_array($cle, VRC_CLES_LISTES, true)) {
        $o = [];
        foreach ((array) $v as $e) { if (is_array($e)) { $id = vrcIdLigne($e, $cle); if ($id !== '') { $o[$id] = vrcCle($e, VRC_CLES_QTE)[1]; } } }
        ksort($o);
        return $o;
    }
    // Les allergènes d'une matière (14/11/2026) : leurs codes, quelle que soit la forme (objets, identifiants, texte).
    if ($cle === 'allergens' && function_exists('vmxAllergenesDe')) { return vmxAllergenesDe($v, vmxAllergenesRef()); }
    if (is_bool($v)) { return $v ? 1.0 : 0.0; }
    return is_numeric($v) ? (float) $v : $v;
}

/** Deux valeurs projetées sont-elles les mêmes ? (nombres à 1e-6 près, en relatif). */
function vrcPareil(mixed $a, mixed $b): bool
{
    if (is_array($a) || is_array($b)) {
        if (!is_array($a) || !is_array($b) || array_keys($a) !== array_keys($b)) { return false; }
        foreach ($a as $k => $x) { if (!vrcPareil($x, $b[$k])) { return false; } }
        return true;
    }
    if (is_float($a) || is_float($b) || is_int($a) || is_int($b)) {
        if (!is_numeric($a) || !is_numeric($b)) { return false; }
        return abs((float) $a - (float) $b) <= max(1e-6, 1e-6 * abs((float) $a));
    }
    return (string) $a === (string) $b;
}

/** Les clés du corps que l'objet relu ne porte pas comme envoyées : [] quand tout y est. */
function vrcConstater(array $corps, ?array $relu): array
{
    if ($relu === null) { return array_keys($corps); }
    $ecarts = [];
    foreach ($corps as $k => $v) {
        $lu = array_key_exists($k, $relu) ? $relu[$k] : (vrcCle($relu, [$k])[1] ?? null);
        if (!vrcPareil(vrcProjection($k, $v), vrcProjection($k, $lu))) { $ecarts[] = $k; }
    }
    return $ecarts;
}

/** Une proposition de recette pour un produit sans recette : les recettes de nom proche, la plus proche d'abord. */
function vrcProches(string $nom, array $recettes, array $prises, int $n = 5): array
{
    $norme = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => "'"]);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    };
    $mots = static fn (string $s): array => array_values(array_filter(explode(' ', $s), static fn ($m) => strlen($m) > 2));
    $a = $norme($nom); $ma = $mots($a);
    $out = [];
    foreach ($recettes as $id => $r) {
        $b = $norme((string) $r); if ($b === '') { continue; }
        $mb = $mots($b);
        $commun = count(array_intersect($ma, $mb));
        $jac = $ma === [] && $mb === [] ? 0 : $commun / max(1, count(array_unique(array_merge($ma, $mb))));
        similar_text($a, $b, $pc);
        $score = $a === $b ? 1.0 : max($jac, $pc / 100 * 0.9);
        if ($score < 0.5) { continue; }
        $out[] = ['rid' => (int) $id, 'nom' => (string) $r, 'score' => round($score, 2), 'libre' => !isset($prises[(int) $id]), 'pareil' => $a === $b];
    }
    usort($out, static fn ($x, $y) => [$y['libre'], $y['score']] <=> [$x['libre'], $x['score']]);
    return array_slice($out, 0, $n);
}

/* ---------------------------------------------------------------------------------------------
 * Le registre
 * ------------------------------------------------------------------------------------------- */

function vrcTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS con_verif_correction ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'fait_le DATETIME NOT NULL,'
        . 'geste VARCHAR(20) NOT NULL,'
        . 'cle VARCHAR(120) NULL,'
        . 'product_id INT NULL, nom VARCHAR(200) NULL,'
        . 'objet VARCHAR(20) NULL, objet_id INT NULL, shop_id INT NULL,'
        . 'methode VARCHAR(8) NULL, route VARCHAR(250) NULL,'
        . 'resume VARCHAR(600) NULL,'
        . 'corps MEDIUMTEXT NULL, inverse MEDIUMTEXT NULL,'
        . 'http INT NULL, resultat VARCHAR(12) NOT NULL, message VARCHAR(600) NULL,'
        . 'cout_avant DECIMAL(12,4) NULL, cout_apres DECIMAL(12,4) NULL,'
        . 'annule_de INT NULL, annule_par INT NULL,'
        . 'KEY (fait_le), KEY (product_id)'
        . ') DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Note une correction ; rend son numéro. */
function vrcNoter(array $r): int
{
    // Le compte qui a écrit : celui du cockpit, ou un compte administrateur du panel connecté par la personne.
    if (property_exists(PanelApi::class, 'jetonImpose') && PanelApi::$jetonImpose !== null) { $r['message'] = trim((string) ($r['message'] ?? '') . ' [compte administrateur du panel]'); }
    try {
        vrcTable();
        $j = static fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        Db::exec('INSERT INTO con_verif_correction (fait_le, geste, cle, product_id, nom, objet, objet_id, shop_id, methode, route, resume, corps, inverse, http, resultat, message, cout_avant, cout_apres, annule_de)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), $r['geste'], $r['cle'] ?? null, $r['pid'] ?? null, mb_substr((string) ($r['nom'] ?? ''), 0, 200), $r['objet'] ?? null, $r['objetId'] ?? null,
                $r['shop'] ?? null, $r['methode'] ?? null, $r['route'] ?? null, mb_substr((string) ($r['resume'] ?? ''), 0, 600), $j($r['corps'] ?? null), $j($r['inverse'] ?? null),
                $r['http'] ?? null, $r['resultat'], mb_substr((string) ($r['message'] ?? ''), 0, 600), $r['coutAvant'] ?? null, $r['coutApres'] ?? null, $r['annuleDe'] ?? null]);
        $id = (int) (Db::row('SELECT MAX(id) id FROM con_verif_correction')['id'] ?? 0);
        if (function_exists('journalAdd')) {
            journalAdd('CEO', 'Recette', (string) ($r['nom'] ?? ''), 'Correction au panel (' . $r['geste'] . ') : ' . ($r['resume'] ?? '') . ' — ' . vrcResultatNom((string) $r['resultat']));
        }
        return $id;
    } catch (Throwable $e) { return 0; }
}

function vrcResultatNom(string $r): string
{
    return ['ok' => 'relue au panel', 'sans_effet' => 'acceptée, mais sans effet à la relecture', 'refuse' => 'refusée par le panel', 'local' => 'notée au cockpit', 'annule' => 'annulée', 'envoye' => 'envoyée'][$r] ?? $r;
}

/* ---------------------------------------------------------------------------------------------
 * Écrire, relire, constater
 * ------------------------------------------------------------------------------------------- */

/** Le message d'une réponse d'erreur du panel. */
function vrcMessage(mixed $res): string
{
    if (!is_array($res)) { return (string) (PanelApi::$lastError ?? 'pas de réponse'); }
    $msg = '';
    foreach (['description', 'message', 'detail', 'error'] as $k) { if (!empty($res[$k]) && is_string($res[$k])) { $msg = $res[$k]; break; } }
    // Mesuré le 14/11 : un refus « MISSING_REQUIRED_FIELDS » ne dit rien seul ; le reste de la réponse (champs attendus,
    // erreurs par champ) est gardé au registre, pour régler le corps sans deviner.
    $reste = array_diff_key($res, array_flip(['description', 'message', 'status', 'code']));
    if ($reste !== [] || (isset($res['code']) && $msg !== (string) $res['code'])) {
        $j = json_encode(['code' => $res['code'] ?? null] + $reste, JSON_UNESCAPED_UNICODE);
        if ($j !== false && $j !== '{"code":null}') { $msg .= ($msg !== '' ? ' — ' : '') . mb_substr($j, 0, 480); }
    }
    return $msg !== '' ? $msg : (string) (PanelApi::$lastError ?? '');
}

/** Le chemin de lecture d'un objet du panel. */
function vrcChemin(string $objet, int $id): string
{
    return match ($objet) {
        'recette' => '/recipes/' . $id,
        'sousRecette' => '/subrecipes/' . $id,
        'produit' => '/products/' . $id,
        'portion' => '/product-portions/' . $id,
        'matiere' => '/materials/' . $id,
        default => '',
    };
}

/** Lit un objet du panel, déballé. Une recette de produit reprend son rendement de /recipes/{id}/cost (GET ne le rend pas). */
function vrcObjet(string $objet, int $id): ?array
{
    $c = vrcChemin($objet, $id);
    $o = $c === '' || $id <= 0 ? null : vrcDeballer(PanelApi::get($c));
    if ($o !== null && $objet === 'recette' && vrcCle($o, VRC_CLES_REND)[0] === null) {
        $y = vrcRendementLu($id);
        if ($y !== null) { $o['yield_quantity'] = $y; }
    }
    return $o;
}

/** Le rendement d'une recette lu par /recipes/{id}/cost ; null s'il ne s'y lit pas. */
function vrcRendementLu(int $rid): ?float
{
    $c = PanelApi::get('/recipes/' . $rid . '/cost');
    $c = is_array($c) && isset($c['data']) && is_array($c['data']) ? $c['data'] : $c;
    return is_array($c) ? vfNum($c, ['yield_quantity']) : null;
}

/**
 * Écrit un corps à un objet (PATCH sur son chemin de lecture), relit, constate, note. $e : geste, objet, id, corps,
 * inverse, resume, cle, pid, nom, shop, coutAvant, coutApres, annuleDe. Rend la ligne du registre.
 */
function vrcEcrireObjet(array $e): array
{
    $route = vrcChemin($e['objet'], (int) $e['id']);
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi('PATCH', $route, $e['corps']);
    $http = $ok ? 200 : (preg_match('/HTTP (\d{3})/', (string) PanelApi::$lastError, $m) ? (int) $m[1] : 0);
    $r = $e + ['methode' => 'PATCH', 'route' => $route, 'http' => $http];
    if (!$ok) {
        $r['resultat'] = 'refuse'; $r['message'] = vrcMessage($res);
    } else {
        // Une recette repart en entier (contrat) : on ne relit que ce qui devait changer, pas les préparations (rendues
        // sous une autre forme) ni l'identité de la sous-recette.
        $verifier = $e['verifier'] ?? (in_array($e['objet'], ['recette', 'sousRecette'], true) ? array_values(array_diff(array_keys($e['corps']), VRC_NON_RELUS)) : array_keys($e['corps']));
        $ecarts = vrcConstater(array_intersect_key($e['corps'], array_flip($verifier)), vrcObjet($e['objet'], (int) $e['id']));
        $r['resultat'] = $ecarts === [] ? 'ok' : 'sans_effet';
        $r['message'] = $ecarts === [] ? '' : 'Le panel a répondu 2xx, mais à la relecture ' . implode(', ', $ecarts) . ' n’a pas changé : le format attendu par le panel diffère.';
    }
    $r['id'] = vrcNoter($r);
    return $r;
}

/** Une ligne du registre telle que l'écran la lit. */
function vrcLigneRegistre(array $r): array
{
    if (property_exists(PanelApi::class, 'jetonImpose') && PanelApi::$jetonImpose !== null && !isset($r['fait_le']) && !str_contains((string) ($r['message'] ?? ''), '[compte administrateur du panel]')) {
        $r['message'] = trim((string) ($r['message'] ?? '') . ' [compte administrateur du panel]');
    }
    return ['id' => (int) ($r['id'] ?? 0), 'le' => (string) ($r['fait_le'] ?? date('Y-m-d H:i:s')), 'geste' => (string) $r['geste'], 'pid' => isset($r['product_id']) ? (int) $r['product_id'] : ($r['pid'] ?? null),
        'nom' => (string) ($r['nom'] ?? ''), 'objet' => $r['objet'] ?? null, 'objetId' => isset($r['objet_id']) ? (int) $r['objet_id'] : ($r['objetId'] ?? null),
        'methode' => $r['methode'] ?? null, 'route' => $r['route'] ?? null, 'resume' => (string) ($r['resume'] ?? ''), 'http' => isset($r['http']) ? (int) $r['http'] : null,
        'resultat' => (string) $r['resultat'], 'resultatNom' => vrcResultatNom((string) $r['resultat']), 'message' => (string) ($r['message'] ?? ''),
        'coutAvant' => isset($r['cout_avant']) ? (float) $r['cout_avant'] : ($r['coutAvant'] ?? null), 'coutApres' => isset($r['cout_apres']) ? (float) $r['cout_apres'] : ($r['coutApres'] ?? null),
        'annulable' => ($r['resultat'] ?? '') === 'ok' || ($r['resultat'] ?? '') === 'local' ? (($r['inverse'] ?? null) !== null && ($r['annule_par'] ?? null) === null && ($r['annule_de'] ?? null) === null) : false,
        'annuleDe' => isset($r['annule_de']) ? (int) $r['annule_de'] : null, 'annulePar' => isset($r['annule_par']) ? (int) $r['annule_par'] : null,
        'reponse' => $r['reponse'] ?? null, 'httpPanel' => $r['httpPanel'] ?? null, 'corps' => isset($r['corps']) && is_array($r['corps']) ? $r['corps'] : null,
        'effet' => isset($r['effet']) && is_array($r['effet']) ? $r['effet'] : null];
}

/* ---------------------------------------------------------------------------------------------
 * L'arbre d'une recette : la recette et ses sous-recettes, telles que le panel les rend
 * ------------------------------------------------------------------------------------------- */

/** [clé 'recette:12' | 'sousRecette:7' => objet déballé] — la recette puis ses sous-recettes, niveau par niveau. */
function vrcArbre(int $rid): array
{
    $out = [];
    $r = vrcObjet('recette', $rid);
    if ($r === null) { return $out; }
    $out['recette:' . $rid] = $r;
    $niveau = [$r]; $vus = [];
    for ($p = 0; $p < VRC_PROFONDEUR && $niveau !== []; $p++) {
        $ids = [];
        foreach ($niveau as $o) {
            foreach ((array) ($o['subrecipes'] ?? []) as $e) {
                if (!is_array($e)) { continue; }
                $c = vrcIdLigne($e, 'subrecipes');
                if ($c !== '' && !isset($vus[$c])) { $vus[$c] = true; $ids[(int) substr($c, 1)] = '/subrecipes/' . (int) substr($c, 1); }
            }
        }
        if ($ids === []) { break; }
        $niveau = [];
        foreach (PanelApi::getParallele($ids, 4, 20) as $sid => $d) {
            $o = vrcDeballer($d);
            if ($o !== null) { $out['sousRecette:' . (int) $sid] = $o; $niveau[] = $o; }
        }
    }
    return $out;
}

/** Où se trouve une matière dans l'arbre : [objet, id] — la sous-recette nommée d'abord, sinon la première qui la porte. */
function vrcOuEst(array $arbre, int $mid, ?string $sous = null): ?array
{
    $trouve = null;
    foreach ($arbre as $k => $o) {
        foreach ((array) ($o['materials'] ?? []) as $e) {
            if (is_array($e) && vrcIdLigne($e, 'materials') === 'm' . $mid) {
                [$objet, $id] = explode(':', (string) $k);
                if ($sous !== null && $sous !== '' && $objet === 'sousRecette' && mb_strtolower(vfTxt($o, ['name'])) === mb_strtolower($sous)) { return [$objet, (int) $id, $o]; }
                if ($sous === null && $objet === 'recette') { return [$objet, (int) $id, $o]; }
                $trouve ??= [$objet, (int) $id, $o];
            }
        }
    }
    return $trouve;
}

/** L'entrée d'une matière telle qu'une autre recette la porte (pour l'ajouter dans la même forme). */
function vrcEntreeDe(int $ridSource, int $mid): ?array
{
    foreach (vrcArbre($ridSource) as $o) {
        foreach ((array) ($o['materials'] ?? []) as $e) { if (is_array($e) && vrcIdLigne($e, 'materials') === 'm' . $mid) { return $e; } }
    }
    return null;
}

/* ---------------------------------------------------------------------------------------------
 * Après une écriture : relire la recette, recalculer la liste
 * ------------------------------------------------------------------------------------------- */

function vrcOublier(string $cle): void
{
    try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$cle]); } catch (Throwable $e) { /* la garde expirera seule */ }
}

/** La recette relue tout de suite (son coût), puis l'analyse recalculée depuis les caches : la liste à jour. */
function vrcApres(array $rids, int $sid, array $shopsMatieres = []): ?array
{
    vrcOublierDispo();
    foreach ($rids as $rid) { if ((int) $rid > 0) { vrcOublier('vfRec:' . (int) $rid); } }
    foreach ($shopsMatieres as $s) { vrcOublier('matieresApi:' . (int) $s); }
    vrcOublier('vfAnalyse');
    $sid = $sid > 0 ? $sid : (int) (array_key_first(vfMagasins()) ?? 0);
    if ($sid > 0 && $rids !== []) { vfLireRecettes(array_fill_keys(array_map('intval', array_filter($rids)), $sid), count($rids)); }
    try { return vfAnalyse(0) + ['cache' => null]; } catch (Throwable $e) { return null; }
}

/** Le coût d'une pièce d'un produit dans un magasin, relu au panel (products/available). */
function vrcCoutProduit(int $sid, int $pid): ?float
{
    foreach (PanelApi::produitsDisponibles($sid) as $l) {
        if (is_array($l) && (int) (vfNum($l, ['id', 'product_id', 'id_product']) ?? 0) === $pid) { return vfNum($l, ['recipe_cost_net', 'recipe_cost_gross']); }
    }
    return null;
}

/* ---------------------------------------------------------------------------------------------
 * Les gestes
 * ------------------------------------------------------------------------------------------- */

/** Les changements d'une recette, regroupés par objet du panel et prêts à écrire : [objet:id => [objet, id, o, changements]]. */
function vrcPreparerRecette(int $rid, array $changements, array &$erreurs): array
{
    $arbre = vrcArbre($rid);
    if ($arbre === []) { $erreurs[] = 'La recette ' . $rid . ' n’a pas pu être lue au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').'; return []; }
    $par = [];
    foreach ($changements as $c) {
        $objet = (string) ($c['objet'] ?? ''); $id = (int) ($c['id'] ?? 0);
        if ($objet === '' && isset($c['mid']) && ($c['type'] ?? '') !== 'ajouter') {
            $ou = vrcOuEst($arbre, (int) $c['mid'], $c['sous'] ?? null);
            if ($ou === null) { $erreurs[] = 'La matière ' . (int) $c['mid'] . ' n’est plus dans la recette.'; continue; }
            [$objet, $id] = $ou;
        }
        if ($objet === '') { $objet = 'recette'; $id = $rid; }
        $k = $objet . ':' . $id;
        if (!isset($arbre[$k])) { $erreurs[] = 'Objet ' . $k . ' introuvable dans la recette.'; continue; }
        if (isset($c['mid']) && !isset($c['cle'])) { $c['cle'] = 'm' . (int) $c['mid']; }
        if (($c['type'] ?? '') === 'ajouter' && !isset($c['entree'])) {
            $src = (int) ($c['source'] ?? 0);
            $c['entree'] = $src > 0 ? vrcEntreeDe($src, (int) ($c['mid'] ?? 0)) : null;
            if ($c['entree'] === null) { $erreurs[] = 'La matière ' . (int) ($c['mid'] ?? 0) . ' n’a pas pu être reprise de la recette ' . $src . '.'; continue; }
        }
        $par[$k] ??= [$objet, $id, $arbre[$k], []];
        $par[$k][3][] = $c;
    }
    return $par;
}

/** Ce qu'un geste ferait, ou ce qu'il a fait : la même préparation, l'envoi en plus. */
function vrcGeste(array $b): array
{
    $geste = (string) ($b['geste'] ?? '');
    $apercu = !empty($b['apercu']);
    $pid = (int) ($b['pid'] ?? 0); $sid = (int) ($b['shop'] ?? 0);
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 200);
    $base = ['geste' => $geste, 'cle' => isset($b['cle']) ? mb_substr((string) $b['cle'], 0, 120) : null, 'pid' => $pid ?: null, 'nom' => $nom, 'shop' => $sid ?: null,
        'coutAvant' => isset($b['coutAvant']) && is_numeric($b['coutAvant']) ? (float) $b['coutAvant'] : null, 'coutApres' => isset($b['coutApres']) && is_numeric($b['coutApres']) ? (float) $b['coutApres'] : null];
    if (!in_array($geste, VRC_GESTES, true)) { http_response_code(400); return ['error' => 'geste inconnu']; }

    // Hors recette : rien au panel, une liste au cockpit.
    if ($geste === 'horsRecette') {
        $cle = (string) ($b['cle'] ?? '');
        if (!str_starts_with($cle, 'orph:')) { http_response_code(400); return ['error' => 'clé de matière requise']; }
        $resume = 'Marquée « hors recette » : ne remonte plus dans la liste';
        if ($apercu) { return ['apercu' => true, 'resume' => $resume, 'ecritures' => []]; }
        $h = setting('vfHorsRecette', []); $h = is_array($h) ? $h : [];
        $h[$cle] = ['nom' => $nom, 'le' => date('Y-m-d')];
        vfGarderBrut('vfHorsRecette', $h);
        $r = $base + ['objet' => 'cockpit', 'resume' => $resume, 'inverse' => ['horsRecette' => $cle], 'resultat' => 'local'];
        $r['id'] = vrcNoter($r);
        return ['ok' => true, 'ecritures' => [vrcLigneRegistre($r)], 'liste' => vrcApres([], 0)];
    }

    // Recalculer les recettes d'un magasin : une action, sans retour possible.
    if ($geste === 'recalculer') {
        if ($sid <= 0) { http_response_code(400); return ['error' => 'magasin requis']; }
        $route = '/shops/' . $sid . '/recipes/recalculate';
        $resume = 'Coûts des recettes recalculés à ' . (vfMagasins()[$sid] ?? ('magasin ' . $sid));
        if ($apercu) { return ['apercu' => true, 'resume' => $resume, 'ecritures' => [['methode' => 'POST', 'route' => $route, 'corps' => new stdClass(), 'annulable' => false]]]; }
        $avant = $pid > 0 ? vrcCoutProduit($sid, $pid) : null;
        PanelApi::$lastError = null;
        [$ok, $res] = PanelApi::envoi('POST', $route, []);
        $r = $base + ['objet' => 'magasin', 'objetId' => $sid, 'methode' => 'POST', 'route' => $route, 'resume' => $resume, 'http' => $ok ? 200 : 0, 'coutAvant' => $avant];
        if (!$ok) { $r['resultat'] = 'refuse'; $r['message'] = vrcMessage($res); }
        else {
            vrcOublierDispo();
            $apres = $pid > 0 ? vrcCoutProduit($sid, $pid) : null;
            $r['coutApres'] = $apres;
            $bouge = $avant === null || $apres === null ? null : abs($apres - $avant) > 1e-6;
            $r['resultat'] = $bouge === false ? 'sans_effet' : 'ok';
            $r['message'] = $bouge === false ? 'Le coût du produit n’a pas bougé : il est saisi à la main au panel (coût « custom »), à retirer sur la fiche du produit.' : '';
        }
        $r['id'] = vrcNoter($r);
        return ['ok' => $r['resultat'] === 'ok', 'ecritures' => [vrcLigneRegistre($r)], 'liste' => $r['resultat'] === 'refuse' ? null : vrcApres((int) ($b['rid'] ?? 0) > 0 ? [(int) $b['rid']] : [], $sid)];
    }

    // Le prix d'une matière dans un ou plusieurs magasins (`shop` ou `shops`) : la route se règle (aucun contrat), la
    // relecture tranche. Un prix qui remplace un prix existant s'annule (l'ancien prix repart) ; un premier prix, non.
    if ($geste === 'prix') {
        $mid = (int) ($b['mid'] ?? 0); $prix = (float) ($b['prix'] ?? 0);
        $shops = array_values(array_unique(array_filter(array_map('intval', (array) ($b['shops'] ?? ($sid > 0 ? [$sid] : []))))));
        if ($shops === [] || $mid <= 0 || $prix <= 0) { http_response_code(400); return ['error' => 'magasin(s), matière et prix requis']; }
        $matiere = mb_substr((string) ($b['matiere'] ?? ('matière ' . $mid)), 0, 120);
        $format = vrcFormatSaisi($b['format'] ?? null); $fid = (int) ($b['fid'] ?? 0);
        if (isset($b['format']) && $format === null) { return ['ok' => false, 'error' => 'Le format saisi n’est pas valable (méthode POST, PATCH ou PUT ; route qui commence par / ; champs nom → valeur).']; }
        $avants = mfMatieresApi($shops, true);
        if ($apercu) {
            $ec = [];
            foreach ($shops as $s) { $ec[] = vrcPrixPlan($s, $mid, $prix, $format, $fid) + ['annulable' => $format === null && ($avants[$s][(string) $mid]['prix'] ?? null) > 0, 'resume' => vrcPrixResume($matiere, $s, $prix, $avants[$s][(string) $mid]['prix'] ?? null)]; }
            $nRec = count(vrcRecettesDeMatiere($mid, [(int) ($b['rid'] ?? 0)]));
            return ['apercu' => true, 'resume' => $matiere . ' : ' . count($shops) . ' magasin(s), ' . vfEur($prix, 4) . ' l’unité de base'
                . ($nRec > 0 ? ' ; puis le food cost de ' . $nRec . ' recette(s) relu au panel, avant et après' . (count($shops) > VRC_EFFET_MAGASINS ? ' (dans les ' . VRC_EFFET_MAGASINS . ' premiers magasins)' : '') : ''), 'ecritures' => $ec];
        }
        $faites = [];
        foreach ($shops as $i => $s) {
            // Le food cost relu avant / après dans les recettes qui portent la matière : les premiers magasins seulement.
            $faites[] = vrcLigneRegistre(vrcEcrirePrix(['shop' => $s] + $base, $s, $mid, $prix, $matiere, $avants[$s][(string) $mid]['prix'] ?? null, $format, $fid,
                $i < VRC_EFFET_MAGASINS, (int) ($b['rid'] ?? 0)));
        }
        $okN = count(array_filter($faites, static fn ($f) => $f['resultat'] === 'ok'));
        // Le format qui a pris devient celui de tous les prix, si on le demande (« Garder ce format »).
        if ($format !== null && $okN > 0 && !empty($b['garder'])) { vfGarderBrut('vrcFormatPrix', $format); }
        vrcOublier('vmpTable');
        return ['ok' => $okN === count($faites), 'ecritures' => $faites, 'liste' => $okN > 0 ? vrcApres((int) ($b['rid'] ?? 0) > 0 ? [(int) $b['rid']] : [], $shops[0], $shops) : null];
    }

    // Rattacher une recette à un produit.
    if ($geste === 'rattacher') {
        $rid = (int) ($b['rid'] ?? 0);
        if ($pid <= 0 || $rid <= 0) { http_response_code(400); return ['error' => 'produit et recette requis']; }
        $p = vrcObjet('produit', $pid);
        if ($p === null) { return ['ok' => false, 'error' => 'Le produit ' . $pid . ' n’a pas pu être lu au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
        $r0 = vrcObjet('recette', $rid);
        if ($r0 === null) { return ['ok' => false, 'error' => 'La recette ' . $rid . ' n’existe pas au panel (ou n’est pas lisible).']; }
        [$cleR, $avant] = vrcCle($p, ['id_recipe', 'recipe_id']);
        $cleR ??= 'id_recipe';
        $resume = 'Recette ' . $rid . ' « ' . vfTxt($r0, ['name']) . ' » rattachée' . ($avant ? ' (avant : ' . (int) $avant . ')' : '');
        $e = $base + ['objet' => 'produit', 'id' => $pid, 'objetId' => $pid, 'corps' => [$cleR => $rid], 'inverse' => [$cleR => $avant === null ? null : (int) $avant], 'resume' => $resume];
        if ($apercu) { return ['apercu' => true, 'resume' => $resume, 'ecritures' => [['methode' => 'PATCH', 'route' => vrcChemin('produit', $pid), 'corps' => $e['corps'], 'annulable' => true]]]; }
        $r = vrcEcrireObjet($e);
        return ['ok' => $r['resultat'] === 'ok', 'ecritures' => [vrcLigneRegistre($r)], 'liste' => $r['resultat'] === 'ok' ? vrcApres([$rid], $sid) : null];
    }

    // La fraction d'une portion.
    if ($geste === 'portion') {
        $fr = (float) ($b['fraction'] ?? 0);
        if ($pid <= 0 || !($fr > 0 && $fr < 1)) { http_response_code(400); return ['error' => 'produit et fraction (entre 0 et 1) requis']; }
        // Le contrat (26/11/2026) : pas de fraction libre, seulement un type (ONE_QUARTER…).
        $type = vrcTypePortion($fr);
        if ($type === null) { return ['ok' => false, 'error' => 'Le panel ne connaît que les parts 1/8, 1/6, 1/4, 1/3, 1/2, 2/3 et 3/4 : une part de ' . vrcQte($fr, '') . ' ne se pose pas.']; }
        $portions = array_values(array_filter(array_map(static fn ($x) => vrcDeballer($x, ['data', 'portion']), analyseListe(PanelApi::get('/products/' . $pid . '/portions'))), 'is_array'));
        $actives = array_values(array_filter($portions, static fn ($x) => !array_key_exists('is_active', $x) || (int) $x['is_active'] === 1));
        $cible = null;
        foreach ($portions as $x) { if ((int) ($x['id'] ?? 0) === (int) ($b['portion'] ?? 0)) { $cible = $x; } }
        if ($cible === null) { foreach ($actives as $x) { if (vrcFractionPortion($x) === null) { $cible = $x; break; } } }
        if ($cible === null && count($actives) === 1) { $cible = $actives[0]; }
        $poid = $cible !== null ? (int) ($cible['id'] ?? 0) : 0;
        if ($poid <= 0) {
            return ['ok' => false, 'error' => $portions === [] ? 'Le produit n’a pas de portion au panel (/products/' . $pid . '/portions) : à créer au panel.'
                : 'Le produit a plusieurs portions au panel (' . implode(', ', array_map(static fn ($x) => vrcFractionTexte(vrcFractionPortion($x)), $actives)) . ') : précisez laquelle, ou réglez-la au panel.'];
        }
        $o = vrcObjet('portion', $poid) ?? $cible;
        $avantType = isset(VRC_PORTIONS[(string) ($o['portion_type'] ?? '')]) ? (string) $o['portion_type'] : null;
        if ($avantType === $type) {
            return ['ok' => false, 'error' => 'La portion ' . $poid . ' est déjà de ' . vrcFractionTexte($fr) . ' au panel : c’est la fiche du produit qui ne la rend pas (products/available), à vérifier au panel.'];
        }
        $resume = 'Portion ' . $poid . ' : part ' . vrcFractionTexte(vrcFractionPortion($o)) . ' → ' . vrcFractionTexte($fr);
        $e = $base + ['objet' => 'portion', 'id' => $poid, 'objetId' => $poid, 'corps' => ['portion_type' => $type], 'inverse' => $avantType === null ? null : ['portion_type' => $avantType], 'resume' => $resume];
        if ($apercu) { return ['apercu' => true, 'resume' => $resume, 'ecritures' => [['methode' => 'PATCH', 'route' => vrcChemin('portion', $poid), 'corps' => $e['corps'], 'annulable' => $avantType !== null]]]; }
        $r = vrcEcrireObjet($e);
        if ($r['resultat'] === 'refuse' && (int) ($r['http'] ?? 0) === 409) {
            $r['message'] .= ' — le type d’une portion déjà vendue ne change plus : créez la portion voulue au panel, puis désactivez l’ancienne.';
        }
        if ($r['resultat'] === 'ok') { vrcOublierDispo(); }
        return ['ok' => $r['resultat'] === 'ok', 'ecritures' => [vrcLigneRegistre($r)], 'liste' => $r['resultat'] === 'ok' ? vrcApres([], $sid) : null];
    }

    // Les gestes sur la recette elle-même : quantité, rendement, copie, plusieurs changements.
    $rid = (int) ($b['rid'] ?? 0);
    if ($rid <= 0) { http_response_code(400); return ['error' => 'recette requise']; }
    $erreurs = [];
    if ($geste === 'quantite') {
        $c = ['mid' => (int) ($b['mid'] ?? 0), 'sous' => $b['sous'] ?? null];
        if (isset($b['facteur'])) { $c += ['type' => 'facteur', 'f' => (float) $b['facteur']]; } else { $c += ['type' => 'qte', 'q' => (float) ($b['q'] ?? 0)]; }
        $par = vrcPreparerRecette($rid, [$c], $erreurs);
    } elseif ($geste === 'rendement') {
        $par = vrcPreparerRecette($rid, [['type' => 'rendement', 'n' => (float) ($b['n'] ?? 0), 'objet' => 'recette', 'id' => $rid]], $erreurs);
    } elseif ($geste === 'copier') {
        $src = (int) ($b['source'] ?? 0);
        $s = $src > 0 ? vrcObjet('recette', $src) : null;
        if ($s === null) { return ['ok' => false, 'error' => 'La recette modèle ' . $src . ' n’a pas pu être lue au panel.']; }
        $par = vrcPreparerRecette($rid, [['type' => 'remplacer', 'objet' => 'recette', 'id' => $rid, 'materials' => (array) ($s['materials'] ?? []), 'subrecipes' => (array) ($s['subrecipes'] ?? [])]], $erreurs);
    } else {
        $par = vrcPreparerRecette($rid, array_values(array_filter((array) ($b['changements'] ?? []), 'is_array')), $erreurs);
    }
    $plans = [];
    foreach ($par as [$objet, $id, $o, $chs]) {
        $m = vrcModifier($o, $chs);
        foreach ($m['erreurs'] as $x) { $erreurs[] = $x; }
        if ($m['corps'] === []) { continue; }
        // Le corps complet du contrat : les lignes d'un côté, le rendement d'une recette de produit de l'autre.
        $c = vrcCorpsContrat($objet, $o, $m['corps']);
        $inv = vrcCorpsContrat($objet, $o, $m['inverse']);
        foreach ($c['erreurs'] as $x) { $erreurs[] = $x; }
        $prefixe = $objet === 'sousRecette' ? 'Sous-recette « ' . vfTxt($o, ['name']) . ' » : ' : '';
        $rend = array_values(array_filter($m['lisible'], static fn ($t) => str_starts_with($t, 'Rendement ')));
        $autres = array_values(array_diff($m['lisible'], $rend));
        if ($c['lignes'] !== null) {
            $plans[] = $base + ['objet' => $objet, 'id' => $id, 'objetId' => $id, 'corps' => $c['lignes'], 'inverse' => $inv['lignes'],
                'resume' => $prefixe . implode(' · ', $objet === 'sousRecette' ? $m['lisible'] : $autres)];
        }
        if ($c['simples'] !== null) {
            $plans[] = $base + ['objet' => $objet, 'id' => $id, 'objetId' => $id, 'corps' => $c['simples'], 'inverse' => $inv['simples'],
                'resume' => $prefixe . implode(' · ', $rend !== [] ? $rend : $m['lisible'])];
        }
    }
    if ($erreurs !== [] || $plans === []) { return ['ok' => false, 'error' => $erreurs !== [] ? implode(' ', $erreurs) : 'Rien à changer.']; }
    if ($apercu) {
        return ['apercu' => true, 'resume' => implode(' ; ', array_column($plans, 'resume')),
            'ecritures' => array_map(static fn ($p) => ['methode' => 'PATCH', 'route' => vrcChemin($p['objet'], (int) $p['id']), 'corps' => $p['corps'], 'annulable' => true, 'resume' => $p['resume']], $plans)];
    }
    $faites = [];
    foreach ($plans as $p) {
        $r = vrcEcrireObjet($p);
        $faites[] = vrcLigneRegistre($r);
        if ($r['resultat'] !== 'ok') { break; }   // une écriture qui ne prend pas arrête les suivantes
    }
    $tout = count($faites) === count($plans) && array_reduce($faites, static fn ($a, $f) => $a && $f['resultat'] === 'ok', true);
    $liste = array_filter($faites, static fn ($f) => $f['resultat'] === 'ok') !== [] ? vrcApres([$rid], $sid) : null;
    return ['ok' => $tout, 'ecritures' => $faites, 'liste' => $liste];
}

/** Les routes de prix que le formulaire peut viser (swagger du panel) : rien d'autre ne part d'ici. */
// Le 14/11/2026 : le prix d'une matière de centrale est « géré par le fournisseur » (403 SUPPLIER_MANAGED_PRICE au
// magasin) ; on repasse alors le tarif du fournisseur pour le magasin, par la route de la centrale.
const VRC_ROUTES_PRIX = ['#^/materials/\d+/manual-price$#', '#^/shops/\d+/materials/avg-price$#', '#^/shops/\d+/suppliers/\d+/materials/price$#',
    '#^/material-suppliers/\d+/shops/\d+/products/\d+/price$#'];

/**
 * Un format de prix saisi dans le formulaire (14/11/2026 : « je dois pouvoir avoir le formulaire avec le prix et toutes les
 * données à poster ») : méthode, route et champs, avec {sid}, {mid}, {prix}, {fid}, {date}. Null s'il n'est pas valable.
 */
function vrcFormatSaisi(mixed $f): ?array
{
    if (!is_array($f)) { return null; }
    $m = strtoupper((string) ($f['methode'] ?? ''));
    $r = (string) ($f['route'] ?? '');
    if (!in_array($m, ['POST', 'PATCH', 'PUT'], true) || !str_starts_with($r, '/') || !is_array($f['corps'] ?? null)) { return null; }
    $corps = [];
    foreach ($f['corps'] as $k => $v) { $k = trim((string) $k); if ($k !== '' && preg_match('/^[A-Za-z_][\w.]{0,60}$/', $k) && (is_scalar($v) || $v === null)) { $corps[$k] = $v; } }
    return ['methode' => $m, 'route' => mb_substr($r, 0, 200), 'corps' => $corps];
}

/** La route et le corps du prix d'une matière dans un magasin : le format saisi, sinon le réglage `vrcFormatPrix`. */
function vrcPrixPlan(int $sid, int $mid, float $prix, ?array $format = null, int $fid = 0): array
{
    $f = $format ?? vrcFormatPrix();
    $subst = ['{sid}' => (string) $sid, '{mid}' => (string) $mid, '{fid}' => (string) $fid, '{date}' => date('Y-m-d')];
    $corps = [];
    foreach ($f['corps'] as $k => $v) {
        if ($v === '{prix}') { $corps[$k] = $prix; }
        elseif ($v === '{shops}') { $corps[$k] = [$sid]; }
        elseif (is_string($v) && isset($subst[$v])) { $corps[$k] = in_array($v, ['{sid}', '{mid}', '{fid}'], true) ? (int) $subst[$v] : $subst[$v]; }
        elseif (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v)) { $corps[$k] = $v + 0; }
        else { $corps[$k] = $v; }
    }
    $route = strtr($f['route'], $subst);
    $permise = false; foreach (VRC_ROUTES_PRIX as $re) { if (preg_match($re, $route)) { $permise = true; } }
    return ['methode' => $f['methode'], 'route' => $route, 'corps' => $corps, 'permise' => $permise];
}

function vrcPrixResume(string $matiere, int $sid, float $prix, ?float $avant): string
{
    return $matiere . ' à ' . (vfMagasins()[$sid] ?? ('magasin ' . $sid)) . ' : ' . ($avant > 0 ? vfEur((float) $avant, 4) . ' → ' : '') . vfEur($prix, 4) . ' l’unité de base';
}

/**
 * Les recettes qui portent une matière, d'après les recettes déjà lues (cache `vfRec:*`, sous-recettes dépliées) :
 * [rid => nom], celles de $plus d'abord, VRC_EFFET_MAX au plus.
 */
function vrcRecettesDeMatiere(int $mid, array $plus = []): array
{
    $out = [];
    foreach ($plus as $r) { if ((int) $r > 0) { $out[(int) $r] = ''; } }
    if ($mid <= 0) { return $out; }
    try {
        foreach (Db::rows("SELECT `key`, value FROM ceo_app_setting WHERE `key` LIKE 'vfRec:%' LIMIT 1500") as $row) {
            if (count($out) >= VRC_EFFET_MAX) { break; }
            $v = json_decode((string) $row['value'], true);
            foreach ((array) ($v['v']['lignes'] ?? []) as $l) {
                if ((int) ($l['mid'] ?? 0) === $mid) { $out[(int) substr((string) $row['key'], 6)] = (string) ($v['v']['nom'] ?? ''); break; }
            }
        }
    } catch (Throwable $e) { /* sans cache : seules les recettes demandées sont relues */ }
    return array_slice($out, 0, VRC_EFFET_MAX, true);
}

/**
 * Le food cost (coût matière d'une pièce, rendement compris) de recettes dans un magasin, relu au panel
 * (/shops/{id}/recipes/{rid}/cost), avec le prix de la matière $mid dans chacune : [rid => [nom, cout, complet, prix]].
 */
function vrcCoutsRecettes(int $sid, array $rids, int $mid = 0): array
{
    $chemins = [];
    foreach ($rids as $rid) { if ((int) $rid > 0) { $chemins[(int) $rid] = '/shops/' . $sid . '/recipes/' . (int) $rid . '/cost'; } }
    if ($sid <= 0 || $chemins === []) { return []; }
    $out = [];
    foreach (PanelApi::getParallele($chemins, 4, 20) as $rid => $r) {
        if (is_array($r) && isset($r['data']['elements']) && is_array($r['data'])) { $r = $r['data']; }
        if (!is_array($r) || !isset($r['elements']) || !is_array($r['elements'])) { continue; }
        $c = vfRecetteCompacte($r, $sid);
        $px = null;
        foreach ($c['lignes'] as $l) { if ($mid > 0 && (int) $l['mid'] === $mid) { $px = $l['prix']; break; } }
        $out[(int) $rid] = ['nom' => (string) $c['nom'], 'cout' => $c['coutNet'], 'complet' => (bool) $c['complet'], 'prix' => $px];
    }
    return $out;
}

/** Le food cost avant / après, recette par recette, ce qui a le plus bougé d'abord. Fonction pure. */
function vrcEffet(array $avant, array $apres): array
{
    $l = [];
    foreach ($apres as $rid => $a) {
        $b = $avant[$rid] ?? [];
        $ca = isset($b['cout']) ? (float) $b['cout'] : null; $cp = isset($a['cout']) ? (float) $a['cout'] : null;
        $ecart = $ca !== null && $cp !== null ? round($cp - $ca, 6) : null;
        $l[] = ['rid' => (int) $rid, 'nom' => (string) ($a['nom'] ?? '') !== '' ? (string) $a['nom'] : (string) ($b['nom'] ?? ''), 'avant' => $ca, 'apres' => $cp, 'ecart' => $ecart,
            'pct' => $ecart !== null && $ca > 0 ? round($ecart / $ca * 100, 2) : null, 'complet' => (bool) ($a['complet'] ?? false),
            'prixAvant' => $b['prix'] ?? null, 'prixApres' => $a['prix'] ?? null];
    }
    usort($l, static fn ($x, $y) => [abs((float) ($y['ecart'] ?? 0)), $x['nom']] <=> [abs((float) ($x['ecart'] ?? 0)), $y['nom']]);
    return $l;
}

/** Une phrase sur l'effet : combien de food costs ont bougé, les trois plus forts, et un drapeau si rien n'a bougé. */
function vrcEffetResume(array $effet, string $matiere = ''): string
{
    if ($effet === []) { return ''; }
    $bouge = array_values(array_filter($effet, static fn ($x) => $x['ecart'] !== null && abs($x['ecart']) > 1e-6));
    $n = count($effet);
    if ($bouge === []) {
        $prixVu = array_filter($effet, static fn ($x) => $x['prixApres'] !== null && $x['prixAvant'] !== null && abs((float) $x['prixApres'] - (float) $x['prixAvant']) > 1e-9) !== [];
        return 'Food cost relu au panel sur ' . $n . ' recette(s) : aucun n’a bougé' . ($prixVu ? ', alors que le prix de la matière y a changé.' : ' : la recette ne lit pas ce prix (prix du fournisseur, ou coût saisi à la main).');
    }
    $fmt = static fn ($x) => $x['nom'] . ' ' . vfEur($x['avant'], 3) . ' → ' . vfEur($x['apres'], 3) . ($x['pct'] !== null ? ' (' . ($x['pct'] > 0 ? '+' : '') . number_format($x['pct'], 1, ',', ' ') . ' %)' : '');
    return 'Food cost relu au panel : ' . count($bouge) . ' recette(s) sur ' . $n . ' ont bougé — ' . implode(' ; ', array_map($fmt, array_slice($bouge, 0, 3)))
        . (count($bouge) > 3 ? ' ; …' : '') . (count($bouge) < $n ? ' ; ' . ($n - count($bouge)) . ' sans changement.' : '.');
}

/**
 * Écrit le prix d'une matière dans un magasin, le relit, le note. L'inverse (l'ancien prix) n'existe que s'il y avait un
 * prix. Avec $effet, le food cost des recettes qui portent la matière est relu avant et après (vrcEffet) : la réponse
 * le porte (`effet`), le message le résume, et le coût de la recette $rid va au registre (coût avant → après).
 */
function vrcEcrirePrix(array $base, int $sid, int $mid, float $prix, string $matiere, ?float $avant, ?array $format = null, int $fid = 0, bool $effet = false, int $rid = 0): array
{
    $pl = vrcPrixPlan($sid, $mid, $prix, $format, $fid);
    $rids = $effet && $pl['permise'] ? array_keys(vrcRecettesDeMatiere($mid, [$rid])) : [];
    $coutsAvant = $rids !== [] ? vrcCoutsRecettes($sid, $rids, $mid) : [];
    if (!$pl['permise']) {
        $r = $base + ['objet' => 'prixMagasin', 'objetId' => $mid, 'shop' => $sid, 'methode' => $pl['methode'], 'route' => $pl['route'], 'corps' => $pl['corps'], 'http' => 0,
            'resume' => vrcPrixResume($matiere, $sid, $prix, $avant), 'resultat' => 'refuse', 'message' => 'Route refusée par le cockpit : seules les routes de prix du panel sont permises.'];
        $r['id'] = vrcNoter($r);
        return $r;
    }
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi($pl['methode'], $pl['route'], $pl['corps']);
    $r = $base + ['objet' => 'prixMagasin', 'objetId' => $mid, 'shop' => $sid, 'methode' => $pl['methode'], 'route' => $pl['route'], 'corps' => $pl['corps'],
        'resume' => vrcPrixResume($matiere, $sid, $prix, $avant), 'http' => $ok ? 200 : 0,
        'inverse' => $avant > 0 ? ['prix' => (float) $avant, 'mid' => $mid, 'sid' => $sid, 'matiere' => $matiere] : null];
    // La réponse brute du panel, telle quelle, pour le formulaire : c'est elle qui dit ce qu'il attend.
    $r['reponse'] = mb_substr((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3000);
    $r['httpPanel'] = preg_match('/HTTP (\d{3})/', (string) PanelApi::$lastError, $mm) ? (int) $mm[1] : ($ok ? 200 : 0);
    if ($format !== null) { $r['inverse'] = null; }   // un format d'essai ne sert pas à annuler
    if (!$ok) { $r['resultat'] = 'refuse'; $r['message'] = vrcMessage($res); $r['inverse'] = null; }
    else {
        vrcOublier('matieresApi:' . $sid);
        $lu = (mfMatieresApi([$sid], true)[$sid][(string) $mid]['prix'] ?? null);
        $r['resultat'] = $lu !== null && abs((float) $lu - $prix) <= 0.0001 + 1e-6 * $prix ? 'ok' : 'sans_effet';
        $r['message'] = $r['resultat'] === 'ok' ? '' : 'Le panel a accepté, mais le prix du magasin relu vaut ' . ($lu === null ? 'toujours rien' : vfEur((float) $lu, 4)) . ' : la route ou le corps sont à régler (réglage vrcFormatPrix, dans le registre).';
        // L'effet sur les food costs, relu au panel (le prix recalcule les recettes du magasin, selon le contrat).
        if ($rids !== []) {
            $r['effet'] = vrcEffet($coutsAvant, vrcCoutsRecettes($sid, $rids, $mid));
            $phrase = vrcEffetResume($r['effet'], $matiere);
            $r['message'] = trim($r['message'] . ($r['message'] !== '' && $phrase !== '' ? ' ' : '') . $phrase);
            foreach ($r['effet'] as $x) {
                if ($x['rid'] === $rid || ($rid <= 0 && $x['ecart'] !== null)) { $r['coutAvant'] ??= $x['avant']; $r['coutApres'] ??= $x['apres']; break; }
            }
        }
    }
    $r['id'] = vrcNoter($r);
    return $r;
}

/** Le stock des produits disponibles d'un magasin est relu à la prochaine demande. */
function vrcOublierDispo(): void
{
    try { $r = new ReflectionProperty(PanelApi::class, 'dispo'); $r->setAccessible(true); $r->setValue(null, null); } catch (Throwable $e) { /* doublure ou autre forme */ }
}

/** Une valeur gardée telle quelle (sans horodatage), comme setting() la relit. */
function vfGarderBrut(string $cle, array $v): void
{
    $j = json_encode($v, JSON_UNESCAPED_UNICODE);
    if ($j !== false) { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
}

/**
 * La route et le corps du prix d'une matière (réglage `vrcFormatPrix`) : le swagger ne dit pas lequel des trois gestes
 * du panel pose le prix d'un magasin. Par défaut : POST /materials/{mid}/manual-price {id_shop, price}. Une première
 * écriture « sans effet » au registre dit qu'il faut le régler.
 */
function vrcFormatPrix(): array
{
    $f = []; try { $f = setting('vrcFormatPrix', []); } catch (Throwable $e) { $f = []; }
    $f = is_array($f) ? $f : [];
    // Le contrat de POST /materials/{id}/manual-price (swagger test.tfbuddy.com, 15/11/2026) : {shop_ids, pack_price, package_size}.
    $corps = isset($f['corps']) && is_array($f['corps']) && $f['corps'] !== [] && !isset($f['corps']['id_shop']) ? $f['corps'] : ['shop_ids' => '{shops}', 'pack_price' => '{prix}', 'package_size' => '1'];
    return ['methode' => in_array(strtoupper((string) ($f['methode'] ?? 'POST')), ['POST', 'PATCH', 'PUT'], true) ? strtoupper((string) ($f['methode'] ?? 'POST')) : 'POST',
        'route' => is_string($f['route'] ?? null) && str_starts_with($f['route'], '/') ? $f['route'] : '/materials/{mid}/manual-price', 'corps' => $corps];
}

/* ---------------------------------------------------------------------------------------------
 * Routes
 * ------------------------------------------------------------------------------------------- */

/** POST /verification/recettes/corriger — un geste ; `apercu: true` montre sans écrire. */
function wr_verification_corriger(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(120);
    return vrcGeste(body());
}

/** POST /verification/recettes/annuler {id} — réécrit l'avant d'une correction, par la même route, et le constate. */
function wr_verification_annuler(): array
{
    $id = (int) (body()['id'] ?? 0);
    try { vrcTable(); $r = Db::row('SELECT * FROM con_verif_correction WHERE id = ?', [$id]); } catch (Throwable $e) { $r = null; }
    if ($r === null) { http_response_code(404); return ['ok' => false, 'error' => 'correction inconnue']; }
    $l = vrcLigneRegistre($r);
    if (!$l['annulable']) { return ['ok' => false, 'error' => 'Cette correction ne s’annule pas (déjà annulée, refusée, ou sans retour possible).']; }
    $inv = json_decode((string) $r['inverse'], true);
    $base = ['geste' => (string) $r['geste'], 'cle' => $r['cle'], 'pid' => $r['product_id'] !== null ? (int) $r['product_id'] : null, 'nom' => (string) $r['nom'],
        'shop' => $r['shop_id'] !== null ? (int) $r['shop_id'] : null, 'annuleDe' => $id, 'coutAvant' => $r['cout_apres'], 'coutApres' => $r['cout_avant']];
    if ((string) $r['objet'] === 'tvaMatiere' && function_exists('vmxEcrireTva')) {
        if (!is_array($inv) || !isset($inv['taux'])) { return ['ok' => false, 'error' => 'L’ancienne TVA n’a pas été gardée.']; }
        [, $tauxLu, $ligneLue] = vmxTvaLue(PanelApi::get('/materials/' . (int) $inv['mid'] . '/vat-rates'));
        $n = vmxEcrireTva($base + ['objetId' => (int) $inv['mid']], (int) $inv['mid'], (float) $inv['taux'], $tauxLu, $ligneLue);
        $n['resume'] = 'Annulation de la correction n° ' . $id . ' : ' . $n['resume'];
        if ($n['resultat'] !== 'ok') { return ['ok' => false, 'ecritures' => [vrcLigneRegistre($n)], 'error' => 'L’annulation n’a pas pris : ' . ($n['message'] ?? '')]; }
    } elseif ((string) $r['objet'] === 'prixMagasin') {
        if (!is_array($inv) || !isset($inv['prix'])) { return ['ok' => false, 'error' => 'L’ancien prix n’a pas été gardé.']; }
        $n = vrcEcrirePrix($base + ['objetId' => (int) $inv['mid']], (int) $inv['sid'], (int) $inv['mid'], (float) $inv['prix'], (string) ($inv['matiere'] ?? ''), null, null, 0, true);
        $n['resume'] = 'Annulation de la correction n° ' . $id . ' : ' . $n['resume'];
        if ($n['resultat'] !== 'ok') { return ['ok' => false, 'ecritures' => [vrcLigneRegistre($n)], 'error' => 'L’annulation n’a pas pris : ' . ($n['message'] ?? '')]; }
        vrcOublier('vmpTable');
    } elseif ($r['resultat'] === 'local') {
        $h = setting('vfHorsRecette', []); $h = is_array($h) ? $h : [];
        unset($h[(string) ($inv['horsRecette'] ?? '')]);
        vfGarderBrut('vfHorsRecette', $h);
        $n = $base + ['objet' => 'cockpit', 'resume' => 'Annulation : la matière remonte de nouveau', 'resultat' => 'annule'];
        $n['id'] = vrcNoter($n);
    } else {
        if (!is_array($inv) || $inv === []) { return ['ok' => false, 'error' => 'L’avant de cette correction n’a pas été gardé.']; }
        // Une clé qui valait null avant (un produit sans recette) repart à null.
        $n = vrcEcrireObjet($base + ['objet' => (string) $r['objet'], 'id' => (int) $r['objet_id'], 'objetId' => (int) $r['objet_id'], 'corps' => $inv, 'inverse' => null,
            'resume' => 'Annulation de la correction n° ' . $id . ' : ' . $r['resume']]);
        if ($n['resultat'] !== 'ok') { return ['ok' => false, 'ecritures' => [vrcLigneRegistre($n)], 'error' => 'L’annulation n’a pas pris : ' . ($n['message'] ?? '')]; }
    }
    try { Db::exec('UPDATE con_verif_correction SET annule_par = ? WHERE id = ?', [$n['id'], $id]); } catch (Throwable $e) { /* le registre garde les deux lignes */ }
    $rid = (string) $r['objet'] === 'recette' ? (int) $r['objet_id'] : 0;
    return ['ok' => true, 'ecritures' => [vrcLigneRegistre($n)], 'liste' => vrcApres($rid > 0 ? [$rid] : [], (int) ($r['shop_id'] ?? 0))];
}

/** GET /verification/recettes/registre[?pid=] — les corrections faites d'ici, les plus récentes d'abord. */
function ep_verification_registre(): array
{
    $pid = (int) ($_GET['pid'] ?? 0);
    try {
        vrcTable();
        $rows = $pid > 0
            ? Db::rows('SELECT * FROM con_verif_correction WHERE product_id = ? ORDER BY id DESC LIMIT 50', [$pid])
            : Db::rows('SELECT * FROM con_verif_correction ORDER BY id DESC LIMIT 150');
    } catch (Throwable $e) { $rows = []; }
    return ['lignes' => array_map('vrcLigneRegistre', $rows), 'liens' => vrcLiens(), 'formatPrix' => vrcFormatPrix()];
}

/**
 * GET /verification/recettes/edition?pid=&rid= — la recette telle que le panel la rend, pour le volet en correction :
 * la recette et ses sous-recettes (lignes, quantités, unités, rendement), le coût de chaque ligne pour une pièce (lu
 * par /cost dans le magasin de référence), les matières qu'on peut ajouter (celles des recettes déjà lues) et, pour
 * une recette vide, les recettes de la même catégorie qui peuvent servir de modèle.
 */
function ep_verification_edition(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $rid = (int) ($_GET['rid'] ?? 0); $pid = (int) ($_GET['pid'] ?? 0);
    if ($rid <= 0) { http_response_code(400); return ['error' => 'rid requis']; }
    $arbre = vrcArbre($rid);
    if ($arbre === []) { return ['ok' => false, 'motif' => 'La recette ' . $rid . ' n’a pas pu être lue au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $R = vfRecettesEnCache([$rid])[$rid] ?? null;
    // Le coût d'une pièce, ligne par ligne : [sous-recette (nom, '' pour la recette) => [mid => coût]].
    $cp = [];
    foreach ((array) ($R['lignes'] ?? []) as $l) { $k = mb_strtolower((string) ($l['sous'] ?? '')); $cp[$k][(int) $l['mid']] = ($cp[$k][(int) $l['mid']] ?? 0) + (float) ($l['cout'] ?? 0); }
    $objets = [];
    foreach ($arbre as $k => $o) {
        [$type, $id] = explode(':', (string) $k);
        $x = vrcLire($o, $type, (int) $id);
        $cleSous = $type === 'recette' ? '' : mb_strtolower($x['nom']);
        foreach ($x['lignes'] as &$l) { $l['coutPiece'] = isset($cp[$cleSous][$l['mid']]) ? round($cp[$cleSous][$l['mid']], 6) : null; }
        unset($l);
        $objets[] = $x;
    }
    // Les matières à ajouter : celles des recettes déjà lues, avec une recette où les reprendre.
    $matieres = [];
    try {
        foreach (Db::rows("SELECT `key`, value FROM ceo_app_setting WHERE `key` LIKE 'vfRec:%' LIMIT 1500") as $row) {
            $v = json_decode((string) $row['value'], true);
            $src = (int) substr((string) $row['key'], 6);
            foreach ((array) ($v['v']['lignes'] ?? []) as $l) {
                $mid = (int) ($l['mid'] ?? 0);
                if ($mid > 0 && !isset($matieres[$mid]) && trim((string) ($l['nom'] ?? '')) !== '') { $matieres[$mid] = ['mid' => $mid, 'nom' => (string) $l['nom'], 'u' => (string) ($l['u'] ?? ''), 'source' => $src]; }
            }
        }
    } catch (Throwable $e) { /* sans liste : on ne propose pas d'ajout */ }
    usort($matieres, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])));
    // Les modèles : les recettes lues des produits de la même catégorie, qui ont des lignes.
    $modeles = [];
    $vide = ($objets[0]['lignes'] ?? []) === [] && ($objets[0]['sous'] ?? []) === [];
    if ($vide && $pid > 0) {
        $D = vfDispo(array_keys(vfMagasins()));
        $cat = ''; foreach ($D as $ps) { if (isset($ps[$pid])) { $cat = (string) $ps[$pid]['cat']; break; } }
        $vus = [];
        foreach ($D as $ps) {
            foreach ($ps as $p) {
                $r2 = (int) ($p['recette'] ?? 0);
                if ($r2 <= 0 || $r2 === $rid || isset($vus[$r2]) || ($cat !== '' && $p['cat'] !== $cat)) { continue; }
                $vus[$r2] = true;
                $c = vfRecettesEnCache([$r2])[$r2] ?? null;
                if ($c !== null && ($c['lignes'] ?? []) !== []) { $modeles[] = ['rid' => $r2, 'nom' => $p['nom'], 'recette' => (string) ($c['nom'] ?? ''), 'lignes' => count($c['lignes'])]; }
            }
        }
        $modeles = array_slice($modeles, 0, 12);
    }
    return ['ok' => true, 'rid' => $rid, 'pid' => $pid, 'objets' => $objets, 'matieres' => array_values($matieres), 'modeles' => $modeles,
        'coutPiece' => $R['coutNet'] ?? null, 'luDans' => vfMagasins()[(int) ($R['sid'] ?? 0)] ?? null, 'liens' => vrcLiensDe(['pid' => $pid, 'rid' => $rid])];
}

/** GET /verification/recettes/candidats?pid=&nom= — les recettes de nom proche, libres d'abord, pour un produit sans recette. */
function ep_verification_candidats(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'candidats' => []]; }
    $nom = trim((string) ($_GET['nom'] ?? ''));
    $liste = vfCache('vrcRecettesListe', 86400);
    if ($liste === null || !empty($_GET['rafraichir'])) {
        $toutes = [];
        foreach (analyseListe(PanelApi::get('/recipes')) as $r) {
            if (is_array($r) && (int) ($r['id'] ?? 0) > 0) { $toutes[(int) $r['id']] = vfTxt($r, ['name', 'recipe_name', 'label']); }
        }
        if ($toutes !== []) { vfGarder('vrcRecettesListe', $toutes); }
        $liste = ['v' => $toutes];
    }
    $prises = [];
    foreach (vfDispo(array_keys(vfMagasins())) as $ps) { foreach ($ps as $p) { if (($p['recette'] ?? null) !== null) { $prises[(int) $p['recette']] = true; } } }
    return ['candidats' => vrcProches($nom, (array) $liste['v'], $prises), 'recettes' => count((array) $liste['v']),
        'motif' => (array) $liste['v'] === [] ? 'La liste des recettes du panel n’a pas pu être lue (' . (PanelApi::$lastError ?? 'pas de réponse') . ') : saisissez le numéro de la recette.' : ''];
}

/* ---------------------------------------------------------------------------------------------
 * Les liens directs vers le panel
 * ------------------------------------------------------------------------------------------- */

/**
 * Les modèles d'adresse des écrans du panel (réglage `verifLiens`) : {pid}, {rid}, {sid}, {mid}, {sous}. La fiche
 * produit est mesurée (/panel/products/{id}) ; les pages de détail d'une recette, d'une sous-recette ou d'une matière
 * ne sont pas dans le swagger : par défaut, la liste correspondante (mesurée le 13/11/2026 : /panel/recipes,
 * /panel/subrecipes et /panel/materials renvoient à la connexion, donc existent), jusqu'à ce que l'adresse exacte soit
 * réglée dans la page.
 */
function vrcLiens(): array
{
    $base = rtrim((string) preg_replace('#/api/v\d+$#', '', (string) (PanelApi::config()['base'] ?? '')), '/');
    $def = ['produit' => vfLienPanelModele(), 'recette' => $base !== '' ? $base . '/panel/recipes' : '', 'sousRecette' => $base !== '' ? $base . '/panel/subrecipes' : '',
        'matiere' => $base !== '' ? $base . '/panel/materials' : '', 'portion' => vfLienPanelModele()];
    $r = []; try { $r = setting('verifLiens', []); } catch (Throwable $e) { $r = []; }
    $out = [];
    foreach ($def as $k => $d) { $v = is_array($r) && is_string($r[$k] ?? null) && trim($r[$k]) !== '' ? trim($r[$k]) : $d; $out[$k] = $v; }
    $out['regles'] = is_array($r) ? array_keys(array_filter($r, static fn ($x) => is_string($x) && trim($x) !== '')) : [];
    return $out;
}

/** Les liens d'un produit et de sa recette, adresses remplies. */
function vrcLiensDe(array $x): array
{
    $L = vrcLiens(); $out = [];
    $sub = ['{pid}' => (string) ($x['pid'] ?? ''), '{id}' => (string) ($x['pid'] ?? ''), '{rid}' => (string) ($x['rid'] ?? ''), '{sid}' => (string) ($x['sid'] ?? ''),
        '{mid}' => (string) ($x['mid'] ?? ''), '{sous}' => (string) ($x['sous'] ?? '')];
    foreach (['produit', 'recette', 'sousRecette', 'matiere', 'portion'] as $k) {
        $m = (string) ($L[$k] ?? '');
        if ($m === '') { continue; }
        // Un modèle qui attend un identifiant absent ne donne pas de lien.
        if (preg_match_all('/\{(\w+)\}/', $m, $mm)) { foreach ($mm[0] as $t) { if (($sub[$t] ?? '') === '') { continue 2; } } }
        $out[$k] = strtr($m, $sub);
    }
    return $out;
}

/** PUT /verification/recettes/liens {produit, recette, sousRecette, matiere, portion} — les adresses du panel. */
function wr_verification_liens(): array
{
    $b = body(); $out = [];
    foreach (['produit', 'recette', 'sousRecette', 'matiere', 'portion'] as $k) {
        $v = trim((string) ($b[$k] ?? ''));
        if ($v !== '' && !preg_match('#^https://#i', $v)) { http_response_code(422); return ['ok' => false, 'error' => 'Une adresse commence par https://']; }
        if ($v !== '') { $out[$k] = mb_substr($v, 0, 300); }
    }
    vfGarderBrut('verifLiens', $out);
    if (isset($b['formatPrix']) && is_array($b['formatPrix'])) {
        $f = $b['formatPrix'];
        vfGarderBrut('vrcFormatPrix', ['methode' => strtoupper((string) ($f['methode'] ?? 'POST')), 'route' => (string) ($f['route'] ?? ''), 'corps' => is_array($f['corps'] ?? null) ? $f['corps'] : []]);
    }
    vrcOublier('vfAnalyse');
    return ['ok' => true, 'liens' => vrcLiens(), 'formatPrix' => vrcFormatPrix()];
}

/**
 * GET /verification/recettes/sonde-prix?mid=&fid=[&shops=2,5][&sku=] — lecture seule (14/11/2026, « d'où vient le
 * prix ? ») : le prix d'une matière vu de toutes les routes du panel qui le portent, pour choisir le format d'écriture
 * sans deviner. Par magasin : la ligne de /shops/{id}/materials, la ligne de la liste du fournisseur (courante,
 * dernière, programmée), la liste vue du magasin et les notifications de changement de prix. Les listes sont réduites
 * aux lignes de la matière (ou aux trois premières) : jamais une réponse entière.
 */
function ep_verification_sonde_prix(): array
{
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $mid = (int) ($_GET['mid'] ?? 0); $fid = (int) ($_GET['fid'] ?? 0);
    if ($mid <= 0) { http_response_code(400); return ['error' => 'mid requis']; }
    $shops = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['shops'] ?? implode(',', array_keys(vfMagasins())))))));
    $sku = (string) ($_GET['sku'] ?? '');
    $garde = static function ($r) use ($mid, $sku): mixed {
        if (!is_array($r)) { return $r; }
        $l = PanelApi::liste($r);
        if ($l === []) { return array_is_list($r) ? $r : array_map(static fn ($v) => is_array($v) && count($v) > 5 ? '[' . count($v) . ' éléments]' : $v, $r); }
        $a = array_values(array_filter($l, static function ($x) use ($mid, $sku) {
            $j = (string) json_encode($x);
            return preg_match('/"(id_material|material_id|id)":"?' . $mid . '"?[,}]/', $j) === 1 || ($sku !== '' && str_contains($j, '"' . $sku . '"'));
        }));
        return ['n' => count($l), 'lignes' => $a !== [] ? array_slice($a, 0, 5) : array_slice($l, 0, 3), 'filtre' => $a !== []];
    };
    $out = ['mid' => $mid, 'fid' => $fid, 'materiau' => vrcObjet('matiere', $mid), 'lu' => []];
    $chemins = [];
    foreach ($shops as $s) {
        $chemins[$s . ':materials'] = '/shops/' . $s . '/materials';
        if ($fid > 0) {
            foreach (['current', 'latest', 'schedule'] as $q) { $chemins[$s . ':liste-' . $q] = '/material-suppliers/' . $fid . '/shops/' . $s . '/price-lists/' . $q; }
            $chemins[$s . ':liste-magasin'] = '/shops/' . $s . '/suppliers/' . $fid . '/price-list';
            $chemins[$s . ':notifications'] = '/material-suppliers/' . $fid . '/shops/' . $s . '/price-change-notifications';
        }
    }
    foreach (PanelApi::getParalleleDetail($chemins, 4, 30, false) as $k => $d) { $out['lu'][$k] = ['code' => $d['code'] ?? null, 'route' => $chemins[$k], 'data' => $garde($d['data'] ?? null)]; }
    return $out;
}

/** Les lectures permises à la sonde du circuit des prix (14/11/2026) : GET seulement, ces routes-là seulement. */
const VRC_SONDE_CHEMINS = ['#^/price-change-batches/\d+/details$#', '#^/material-suppliers/\d+/raw-materials/\d+/price-history$#',
    '#^/material-suppliers/\d+/shops/\d+/price-change-notifications$#', '#^/material-suppliers/\d+/catalog/products$#', '#^/material-suppliers/\d+/price-lists/global/schedule$#',
    // Les commandes fournisseur et leurs livraisons (14/11/2026, « comment valider les marchandises dans le stock ») : lecture.
    '#^/shops/\d+/orders$#', '#^/shops/\d+/orders/materials$#', '#^/orders/\d+$#', '#^/orders/ORD-[\w-]{4,60}$#', '#^/deliveries/\d+$#', '#^/shops/\d+/orders/\d+/supplier-fulfillment$#',
    '#^/shops/\d+/supplement-orders$#', '#^/consultant/shops/\d+/deliveries$#', '#^/consultant/shops/\d+/material-orders(/\d+)?$#', '#^/shops/\d+/kitchen-deliveries(/\d+)?$#',
    // Le helpdesk des franchisés et leurs notifications (15/11/2026) : lecture.
    '#^/cases$#', '#^/cases/stats$#', '#^/cases/\d+$#', '#^/shops/\d+/cases(/stats)?$#', '#^/consultant/tasks/helpdesk$#',
    '#^/franchisee-notifications(/active)?$#', '#^/franchisee-notification/(types|\d+)$#', '#^/shops/\d+/notifications(/read)?$#', '#^/notifications$#', '#^/shops/\d+/materials/purchase-readiness$#', '#^/material-suppliers/\d+/upcoming-deliveries$#'];

/**
 * GET /verification/recettes/sonde-chemin?chemin=&q= — lecture seule : une route du circuit des prix fournisseur
 * (VRC_SONDE_CHEMINS), réduite aux éléments qui contiennent `q` s'il est donné, coupée à 30 éléments.
 */
function ep_verification_sonde_chemin(): array
{
    if (!PanelApi::configured()) { return ['error' => 'compte panel non configuré']; }
    $c = (string) ($_GET['chemin'] ?? ''); $q = (string) ($_GET['q'] ?? '');
    $ok = false; foreach (VRC_SONDE_CHEMINS as $re) { if (preg_match($re, $c)) { $ok = true; } }
    if (!$ok) { http_response_code(400); return ['error' => 'chemin non permis']; }
    // `compte=admin` : la même lecture avec le compte administrateur du cockpit (Paramètres › ERP).
    if (($_GET['compte'] ?? '') === 'admin' && class_exists('ErpApi')) {
        $t = ErpApi::jetonSonde();
        if ($t === null) { return ['error' => 'compte administrateur du cockpit indisponible : ' . (ErpApi::$lastError ?? '')]; }
        PanelApi::$jetonImpose = $t;
    }
    $d = PanelApi::getParalleleDetail(['x' => $c], 1, 40, false)['x'] ?? [];
    $r = $d['data'] ?? null;
    if (is_array($r)) {
        $l = PanelApi::liste($r);
        if ($l !== []) {
            $f = $q === '' ? $l : array_values(array_filter($l, static fn ($x) => str_contains(mb_strtolower((string) json_encode($x, JSON_UNESCAPED_UNICODE)), mb_strtolower($q))));
            $r = ['n' => count($l), 'nFiltre' => count($f), 'lignes' => array_slice($f, 0, 30), 'cles' => array_keys(array_diff_key($r, ['data' => 1, 'items' => 1]))];
        }
    }
    return ['chemin' => $c, 'code' => $d['code'] ?? null, 'data' => $r];
}

/** Le stock d'un magasin tel que /shops/{id}/material-inventory le rend : [mid => [nom, quantité, unité]]. */
function vrcStockMagasin(int $sid): array
{
    $out = [];
    foreach (PanelApi::liste((array) PanelApi::get('/shops/' . $sid . '/material-inventory')) as $l) {
        $mid = (int) ($l['id_material'] ?? 0);
        if ($mid > 0) { $out[$mid] = [trim((string) ($l['material_name'] ?? '')), (float) ($l['current_quantity'] ?? 0), (string) ($l['unit_name'] ?? '')]; }
    }
    return $out;
}

/**
 * POST /verification/recettes/reception {commande, corps?, apercu?, compte?} — réceptionner UNE livraison fournisseur
 * (14/11/2026, « comment valider les marchandises dans le stock des shops ? ») : POST /orders/{commande}/delivery. La route
 * n'a pas de contrat : le corps est celui donné (vide par défaut) et la réponse brute du panel revient. Avant et après :
 * l'état de la livraison (/deliveries/{id}) et le stock du magasin ; la différence est rendue, le tout noté au registre.
 * Rien n'est annulable d'ici (une réception ne se défait pas par l'API).
 */
function wr_verification_reception(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(120);
    $b = body();
    $id = (int) ($b['commande'] ?? 0);
    if ($id <= 0) { http_response_code(400); return ['error' => 'commande requise']; }
    $corps = [];
    foreach ((array) ($b['corps'] ?? []) as $k => $v) { if (preg_match('/^[A-Za-z_]\w{0,60}$/', (string) $k) && (is_scalar($v) || $v === null)) { $corps[(string) $k] = $v; } }
    $avant = vrcDeballer(PanelApi::get('/deliveries/' . $id));
    if ($avant === null) { return ['ok' => false, 'error' => 'La livraison ' . $id . ' n’a pas pu être lue (/deliveries/' . $id . ') : rien n’est envoyé.']; }
    $sid = (int) ($avant['id_shop'] ?? 0);
    // `cle: true` : la commande se désigne par sa clé (order_key, « ORD-… ») — 404 ORDER_NOT_FOUND par son numéro (mesuré).
    $cleCmd = (string) ($avant['order_key'] ?? '');
    $route = !empty($b['cle']) && preg_match('/^ORD-[\w-]{4,60}$/', $cleCmd) ? '/orders/' . $cleCmd . '/delivery' : '/orders/' . $id . '/delivery';
    $etat = static fn (?array $d): array => $d === null ? [] : array_intersect_key($d, array_flip(['status', 'delivered_on', 'supplier_fulfillment_status', 'expected_date', 'in_transit_sent_at']));
    if (!empty($b['apercu'])) { return ['apercu' => true, 'methode' => 'POST', 'route' => $route, 'corps' => $corps, 'magasin' => $sid, 'livraison' => $etat($avant)]; }
    $stockAvant = $sid > 0 ? vrcStockMagasin($sid) : [];
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi('POST', $route, $corps);
    $http = preg_match('/HTTP (\d{3})/', (string) PanelApi::$lastError, $m) ? (int) $m[1] : ($ok ? 200 : 0);
    $apres = vrcDeballer(PanelApi::get('/deliveries/' . $id));
    $stockApres = $ok && $sid > 0 ? vrcStockMagasin($sid) : $stockAvant;
    $diff = [];
    foreach ($stockApres as $mid => [$nom, $q, $u]) {
        $q0 = $stockAvant[$mid][1] ?? 0.0;
        if (abs($q - $q0) > 1e-9) { $diff[] = ['mid' => $mid, 'nom' => $nom, 'avant' => $q0, 'apres' => $q, 'unite' => $u]; }
    }
    usort($diff, static fn ($x, $y) => abs($y['apres'] - $y['avant']) <=> abs($x['apres'] - $x['avant']));
    $r = ['geste' => 'reception', 'cle' => 'cmd:' . $id, 'pid' => null, 'nom' => 'Livraison ' . ($avant['order_key'] ?? $id), 'shop' => $sid, 'objet' => 'livraison', 'objetId' => $id,
        'methode' => 'POST', 'route' => $route, 'corps' => $corps, 'http' => $http, 'inverse' => null,
        'resume' => 'Réception de la livraison ' . $id . ' : ' . ($avant['status'] ?? '?') . ' → ' . ($apres['status'] ?? '?') . ' · ' . count($diff) . ' matière(s) au stock changée(s)',
        'resultat' => !$ok ? 'refuse' : (($apres['status'] ?? '') !== ($avant['status'] ?? '') || $diff !== [] ? 'ok' : 'sans_effet'),
        'message' => !$ok ? vrcMessage($res) : ''];
    $r['id'] = vrcNoter($r);
    return ['ok' => $r['resultat'] === 'ok', 'ecritures' => [vrcLigneRegistre($r + ['reponse' => mb_substr((string) json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3000), 'httpPanel' => $http])],
        'livraison' => ['avant' => $etat($avant), 'apres' => $etat($apres)], 'stock' => array_slice($diff, 0, 80), 'nStock' => count($diff)];
}
