<?php
declare(strict_types=1);

/**
 * Vérification › Recettes à corriger : corriger d'ici, ou ouvrir le bon écran du panel (demande du 13/11/2026 :
 * « dans les problèmes de recette, qu'on puisse en direct si possible corriger, ou un lien direct » — « tout, CEO
 * seul »). Maquette : docs/maquettes/recettes-correction ; plan : docs/plan-verification.md § 3.5.
 *
 * Le cockpit ÉCRIT au panel, avec son compte consultant, comme il écrit déjà les tâches et les réclamations. Les
 * routes d'écriture sont dans le swagger du panel mais SANS CONTRAT (« runtime routes ») : aucune forme de corps n'est
 * documentée. D'où une seule façon d'écrire, prudente :
 *
 *   1. lire l'objet tel que le panel le rend (GET /recipes/{id}, /subrecipes/{id}, /products/{id}, /product-portions/{id}) ;
 *   2. ne changer QUE la clé visée (la liste `materials` avec une quantité changée, `yield_quantity`, `id_recipe`…),
 *      dans la forme lue, et n'envoyer que les clés changées ;
 *   3. relire l'objet et CONSTATER que le changement y est. Un 2xx sans changement visible est « sans effet » ;
 *   4. tout noter au registre `ceo_verif_correction` : l'avant (le corps inverse, pour « Annuler »), l'après, la route,
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
 *   prix       — le prix d'une matière dans un magasin (route réglable : `vrcFormatPrix`) ;
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
            // Mesuré le 13/11/2026 : GET /recipes/{id} ne rend pas le rendement (seul /cost le dit). Sans clé lue, on
            // n'écrit pas une clé devinée : le rendement se pose au panel.
            if ($cr === null) { $erreurs[] = 'Le panel ne rend pas le rendement de cette recette (GET /recipes/{id}) : il se pose au panel.'; continue; }
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
    foreach ($touche as $l => $_) { $corps[$l] = $listes[$l]; }
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
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_verif_correction ('
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
    try {
        vrcTable();
        $j = static fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE);
        Db::exec('INSERT INTO ceo_verif_correction (fait_le, geste, cle, product_id, nom, objet, objet_id, shop_id, methode, route, resume, corps, inverse, http, resultat, message, cout_avant, cout_apres, annule_de)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), $r['geste'], $r['cle'] ?? null, $r['pid'] ?? null, mb_substr((string) ($r['nom'] ?? ''), 0, 200), $r['objet'] ?? null, $r['objetId'] ?? null,
                $r['shop'] ?? null, $r['methode'] ?? null, $r['route'] ?? null, mb_substr((string) ($r['resume'] ?? ''), 0, 600), $j($r['corps'] ?? null), $j($r['inverse'] ?? null),
                $r['http'] ?? null, $r['resultat'], mb_substr((string) ($r['message'] ?? ''), 0, 600), $r['coutAvant'] ?? null, $r['coutApres'] ?? null, $r['annuleDe'] ?? null]);
        $id = (int) (Db::row('SELECT MAX(id) id FROM ceo_verif_correction')['id'] ?? 0);
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
    foreach (['description', 'message', 'detail', 'error'] as $k) { if (!empty($res[$k]) && is_string($res[$k])) { return $res[$k]; } }
    if (!empty($res['errors'])) { return (string) json_encode($res['errors'], JSON_UNESCAPED_UNICODE); }
    return (string) (PanelApi::$lastError ?? '');
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

/** Lit un objet du panel, déballé. */
function vrcObjet(string $objet, int $id): ?array
{
    $c = vrcChemin($objet, $id);
    return $c === '' || $id <= 0 ? null : vrcDeballer(PanelApi::get($c));
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
        $ecarts = vrcConstater($e['corps'], vrcObjet($e['objet'], (int) $e['id']));
        $r['resultat'] = $ecarts === [] ? 'ok' : 'sans_effet';
        $r['message'] = $ecarts === [] ? '' : 'Le panel a répondu 2xx, mais à la relecture ' . implode(', ', $ecarts) . ' n’a pas changé : le format attendu par le panel diffère.';
    }
    $r['id'] = vrcNoter($r);
    return $r;
}

/** Une ligne du registre telle que l'écran la lit. */
function vrcLigneRegistre(array $r): array
{
    return ['id' => (int) ($r['id'] ?? 0), 'le' => (string) ($r['fait_le'] ?? date('Y-m-d H:i:s')), 'geste' => (string) $r['geste'], 'pid' => isset($r['product_id']) ? (int) $r['product_id'] : ($r['pid'] ?? null),
        'nom' => (string) ($r['nom'] ?? ''), 'objet' => $r['objet'] ?? null, 'objetId' => isset($r['objet_id']) ? (int) $r['objet_id'] : ($r['objetId'] ?? null),
        'methode' => $r['methode'] ?? null, 'route' => $r['route'] ?? null, 'resume' => (string) ($r['resume'] ?? ''), 'http' => isset($r['http']) ? (int) $r['http'] : null,
        'resultat' => (string) $r['resultat'], 'resultatNom' => vrcResultatNom((string) $r['resultat']), 'message' => (string) ($r['message'] ?? ''),
        'coutAvant' => isset($r['cout_avant']) ? (float) $r['cout_avant'] : ($r['coutAvant'] ?? null), 'coutApres' => isset($r['cout_apres']) ? (float) $r['cout_apres'] : ($r['coutApres'] ?? null),
        'annulable' => ($r['resultat'] ?? '') === 'ok' || ($r['resultat'] ?? '') === 'local' ? (($r['inverse'] ?? null) !== null && ($r['annule_par'] ?? null) === null && ($r['annule_de'] ?? null) === null) : false,
        'annuleDe' => isset($r['annule_de']) ? (int) $r['annule_de'] : null, 'annulePar' => isset($r['annule_par']) ? (int) $r['annule_par'] : null];
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
        $avants = mfMatieresApi($shops, true);
        if ($apercu) {
            $ec = [];
            foreach ($shops as $s) { $ec[] = vrcPrixPlan($s, $mid, $prix) + ['annulable' => ($avants[$s][(string) $mid]['prix'] ?? null) > 0, 'resume' => vrcPrixResume($matiere, $s, $prix, $avants[$s][(string) $mid]['prix'] ?? null)]; }
            return ['apercu' => true, 'resume' => $matiere . ' : ' . count($shops) . ' magasin(s), ' . vfEur($prix, 4) . ' l’unité de base', 'ecritures' => $ec];
        }
        $faites = [];
        foreach ($shops as $s) {
            $faites[] = vrcLigneRegistre(vrcEcrirePrix(['shop' => $s] + $base, $s, $mid, $prix, $matiere, $avants[$s][(string) $mid]['prix'] ?? null));
        }
        $okN = count(array_filter($faites, static fn ($f) => $f['resultat'] === 'ok'));
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
        $portions = array_values(array_filter(array_map(static fn ($x) => vrcDeballer($x, ['data', 'portion']), analyseListe(PanelApi::get('/products/' . $pid . '/portions'))), 'is_array'));
        $cible = null;
        foreach ($portions as $x) { [, $v] = vrcCle($x, VRC_CLES_PART); if ($v === null || $v <= 0 || $v >= 1) { $cible = $x; break; } }
        if ($cible === null && count($portions) === 1) { $cible = $portions[0]; }
        $poid = $cible !== null ? (int) ($cible['id'] ?? 0) : (int) ($b['portion'] ?? 0);
        if ($poid <= 0) { return ['ok' => false, 'error' => 'La portion du produit n’a pas été trouvée au panel (/products/' . $pid . '/portions) : à régler au panel.']; }
        $o = vrcObjet('portion', $poid) ?? $cible ?? [];
        [$cleP, $avant] = vrcCle($o, VRC_CLES_PART);
        $cleP ??= 'portion_size';
        $resume = 'Portion ' . $poid . ' : part ' . ($avant === null ? '—' : vrcQte($avant, '')) . ' → ' . vrcQte($fr, '');
        $e = $base + ['objet' => 'portion', 'id' => $poid, 'objetId' => $poid, 'corps' => [$cleP => $fr], 'inverse' => [$cleP => $avant], 'resume' => $resume];
        if ($apercu) { return ['apercu' => true, 'resume' => $resume, 'ecritures' => [['methode' => 'PATCH', 'route' => vrcChemin('portion', $poid), 'corps' => $e['corps'], 'annulable' => true]]]; }
        $r = vrcEcrireObjet($e);
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
        $plans[] = $base + ['objet' => $objet, 'id' => $id, 'objetId' => $id, 'corps' => $m['corps'], 'inverse' => $m['inverse'],
            'resume' => ($objet === 'sousRecette' ? 'Sous-recette « ' . vfTxt($o, ['name']) . ' » : ' : '') . implode(' · ', $m['lisible'])];
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

/** La route et le corps du prix d'une matière dans un magasin, d'après le réglage `vrcFormatPrix`. */
function vrcPrixPlan(int $sid, int $mid, float $prix): array
{
    $f = vrcFormatPrix();
    $subst = ['{sid}' => (string) $sid, '{mid}' => (string) $mid];
    $corps = [];
    foreach ($f['corps'] as $k => $v) { $corps[$k] = $v === '{prix}' ? $prix : (is_string($v) && isset($subst[$v]) ? (int) $subst[$v] : $v); }
    return ['methode' => $f['methode'], 'route' => strtr($f['route'], $subst), 'corps' => $corps];
}

function vrcPrixResume(string $matiere, int $sid, float $prix, ?float $avant): string
{
    return $matiere . ' à ' . (vfMagasins()[$sid] ?? ('magasin ' . $sid)) . ' : ' . ($avant > 0 ? vfEur((float) $avant, 4) . ' → ' : '') . vfEur($prix, 4) . ' l’unité de base';
}

/** Écrit le prix d'une matière dans un magasin, le relit, le note. L'inverse (l'ancien prix) n'existe que s'il y avait un prix. */
function vrcEcrirePrix(array $base, int $sid, int $mid, float $prix, string $matiere, ?float $avant): array
{
    $pl = vrcPrixPlan($sid, $mid, $prix);
    PanelApi::$lastError = null;
    [$ok, $res] = PanelApi::envoi($pl['methode'], $pl['route'], $pl['corps']);
    $r = $base + ['objet' => 'prixMagasin', 'objetId' => $mid, 'shop' => $sid, 'methode' => $pl['methode'], 'route' => $pl['route'], 'corps' => $pl['corps'],
        'resume' => vrcPrixResume($matiere, $sid, $prix, $avant), 'http' => $ok ? 200 : 0,
        'inverse' => $avant > 0 ? ['prix' => (float) $avant, 'mid' => $mid, 'sid' => $sid, 'matiere' => $matiere] : null];
    if (!$ok) { $r['resultat'] = 'refuse'; $r['message'] = vrcMessage($res); $r['inverse'] = null; }
    else {
        vrcOublier('matieresApi:' . $sid);
        $lu = (mfMatieresApi([$sid], true)[$sid][(string) $mid]['prix'] ?? null);
        $r['resultat'] = $lu !== null && abs((float) $lu - $prix) <= 0.0001 + 1e-6 * $prix ? 'ok' : 'sans_effet';
        $r['message'] = $r['resultat'] === 'ok' ? '' : 'Le panel a accepté, mais le prix du magasin relu vaut ' . ($lu === null ? 'toujours rien' : vfEur((float) $lu, 4)) . ' : la route ou le corps sont à régler (réglage vrcFormatPrix, dans le registre).';
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
    $corps = isset($f['corps']) && is_array($f['corps']) && $f['corps'] !== [] ? $f['corps'] : ['id_shop' => '{sid}', 'price' => '{prix}'];
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
    try { vrcTable(); $r = Db::row('SELECT * FROM ceo_verif_correction WHERE id = ?', [$id]); } catch (Throwable $e) { $r = null; }
    if ($r === null) { http_response_code(404); return ['ok' => false, 'error' => 'correction inconnue']; }
    $l = vrcLigneRegistre($r);
    if (!$l['annulable']) { return ['ok' => false, 'error' => 'Cette correction ne s’annule pas (déjà annulée, refusée, ou sans retour possible).']; }
    $inv = json_decode((string) $r['inverse'], true);
    $base = ['geste' => (string) $r['geste'], 'cle' => $r['cle'], 'pid' => $r['product_id'] !== null ? (int) $r['product_id'] : null, 'nom' => (string) $r['nom'],
        'shop' => $r['shop_id'] !== null ? (int) $r['shop_id'] : null, 'annuleDe' => $id, 'coutAvant' => $r['cout_apres'], 'coutApres' => $r['cout_avant']];
    if ((string) $r['objet'] === 'prixMagasin') {
        if (!is_array($inv) || !isset($inv['prix'])) { return ['ok' => false, 'error' => 'L’ancien prix n’a pas été gardé.']; }
        $n = vrcEcrirePrix($base + ['objetId' => (int) $inv['mid']], (int) $inv['sid'], (int) $inv['mid'], (float) $inv['prix'], (string) ($inv['matiere'] ?? ''), null);
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
    try { Db::exec('UPDATE ceo_verif_correction SET annule_par = ? WHERE id = ?', [$n['id'], $id]); } catch (Throwable $e) { /* le registre garde les deux lignes */ }
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
            ? Db::rows('SELECT * FROM ceo_verif_correction WHERE product_id = ? ORDER BY id DESC LIMIT 50', [$pid])
            : Db::rows('SELECT * FROM ceo_verif_correction ORDER BY id DESC LIMIT 150');
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
