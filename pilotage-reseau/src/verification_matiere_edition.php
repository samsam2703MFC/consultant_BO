<?php
declare(strict_types=1);

/**
 * Recettes à corriger › Matières premières › la fiche d'une matière, modifiable (demande du 14/11/2026 : « un outil pour
 * encoder les prix, tous les paramètres de la matière ; les matières sont reliées à des fournisseurs ou à un prix
 * général : faire la comparaison » — « tout modifiable »). Maquette : docs/maquettes/fiche-matiere.
 *
 * GET  /verification/recettes/matiere?mid=  — la matière telle que GET /materials/{id} la rend, ses champs modifiables
 *      (seulement ceux que le panel rend : on n'écrit pas une clé devinée), les listes de référence (catégories, unités,
 *      allergènes, taux de TVA), et sa ligne du tableau des matières (prix des magasins, fournisseurs liés, recettes).
 * POST /verification/recettes/matiere {mid, apercu?, champs: {clé: valeur}, magasins: {magasin: prix par unité de base}}
 *      — les champs changés partent en PATCH /materials/{id} (les seules clés changées, dans la forme lue), relus et
 *      constatés ; les prix des magasins partent par le geste `prix` (verification_correction.php). Tout au registre,
 *      « Annuler » remet l'avant. Une matière change pour TOUT le réseau (décision du 14/11/2026).
 */

const VMX_PAYS = 'BE';
const VMX_SEC_REF = 86400;

/** Les champs de la fiche, dans l'ordre de l'écran : clé du panel => [libellé, type]. */
const VMX_CHAMPS = [
    'name' => ['Nom', 'texte'],
    'id_category' => ['Catégorie', 'categorie'],
    'id_unit' => ['Unité de base', 'unite'],
    'material_type' => ['Type', 'valeurs'],
    'source_type' => ['Source', 'valeurs'],
    'kind' => ['Genre', 'valeurs'],
    'waste_amount_perc' => ['Perte (%)', 'nombre'],
    'vat_rate' => ['TVA (%)', 'tva'],
    'is_part_of_package' => ['Fait partie d’un emballage', 'oui'],
    'can_be_product_addon' => ['Peut être un supplément d’un produit', 'oui'],
    'integrated_supplier' => ['Intégrée chez le fournisseur', 'oui'],
    'allergens' => ['Allergènes', 'allergenes'],
    'reference_unit_price_net' => ['Prix général (référence du panel)', 'prix'],
    'suggested_base_unit_price_net' => ['Prix conseillé du panel', 'prix'],
];

/** Une liste de référence du panel, gardée un jour : [id => nom]. */
function vmxReference(string $cle, string $chemin, array $noms = ['name', 'label', 'code', 'symbol']): array
{
    $c = vfCache($cle, VMX_SEC_REF);
    if ($c !== null) { return $c['v']; }
    $out = [];
    foreach (analyseListe(PanelApi::get($chemin)) as $x) {
        if (!is_array($x)) { continue; }
        $id = $x['id'] ?? ($x['value'] ?? null);
        if ($id === null || !is_scalar($id)) { continue; }
        $n = vfTxt($x, $noms);
        $out[(string) $id] = $n !== '' ? $n : (string) $id;
    }
    if ($out !== []) { vfGarder($cle, $out); }
    return $out;
}

/** Les allergènes du panel : [id => code]. */
function vmxAllergenesRef(): array
{
    $c = vfCache('vmxAllergenes', VMX_SEC_REF);
    if ($c !== null) { return $c['v']; }
    $out = [];
    foreach (analyseListe(PanelApi::get('/allergens')) as $a) {
        if (is_array($a) && is_numeric($a['id'] ?? null) && is_scalar($a['code'] ?? null)) { $out[(string) (int) $a['id']] = strtolower(trim((string) $a['code'])); }
    }
    if ($out !== []) { vfGarder('vmxAllergenes', $out); }
    return $out;
}

/** Les allergènes d'une matière, quelle que soit leur forme (liste d'objets, d'identifiants, de codes, ou texte « a, b ») : [codes]. */
function vmxAllergenesDe(mixed $v, array $ref): array
{
    $out = [];
    $items = is_string($v) ? array_map('trim', explode(',', $v)) : (array) $v;
    foreach ($items as $x) {
        if (is_array($x)) { $code = is_scalar($x['code'] ?? null) ? strtolower(trim((string) $x['code'])) : ($ref[(string) (int) ($x['id'] ?? 0)] ?? ''); }
        elseif (is_numeric($x)) { $code = $ref[(string) (int) $x] ?? ''; }
        else { $code = strtolower(trim((string) $x)); }
        if ($code !== '') { $out[$code] = true; }
    }
    $out = array_keys($out); sort($out);
    return $out;
}

/** Les allergènes choisis (codes), dans la forme où le panel les rend. Fonction pure. */
function vmxAllergenesPour(mixed $forme, array $codes, array $ref): mixed
{
    $ids = array_flip($ref);   // code => id
    if (is_string($forme)) { return implode(', ', $codes); }
    $l = (array) $forme;
    $premier = $l[0] ?? null;
    if (is_array($premier) || $l === []) {
        return array_values(array_map(static fn ($c) => ['id' => isset($ids[$c]) ? (int) $ids[$c] : null, 'code' => $c], $codes));
    }
    if (is_numeric($premier)) { return array_values(array_filter(array_map(static fn ($c) => isset($ids[$c]) ? (int) $ids[$c] : null, $codes), static fn ($x) => $x !== null)); }
    return $codes;
}

/**
 * Les changements d'une matière : seules les clés que le panel rend, et qui changent. Fonction pure.
 * Rend [corps, inverse, lisible, erreurs].
 */
function vmxChangements(array $o, array $champs, array $refAllergenes): array
{
    $corps = []; $inverse = []; $lisible = []; $erreurs = [];
    foreach ($champs as $k => $v) {
        if (!isset(VMX_CHAMPS[$k])) { $erreurs[] = 'Champ inconnu : ' . $k . '.'; continue; }
        if (!array_key_exists($k, $o)) { $erreurs[] = '« ' . VMX_CHAMPS[$k][0] . ' » : le panel ne le rend pas pour cette matière, il ne s’écrit pas d’ici.'; continue; }
        [$lib, $type] = VMX_CHAMPS[$k];
        $avant = $o[$k];
        if ($type === 'allergenes') {
            $codes = vmxAllergenesDe($v, $refAllergenes);
            if ($codes === vmxAllergenesDe($avant, $refAllergenes)) { continue; }
            $corps[$k] = vmxAllergenesPour($avant, $codes, $refAllergenes);
            $lisible[] = $lib . ' : ' . ($codes === [] ? 'aucun' : implode(', ', $codes));
        } elseif ($type === 'oui') {
            $b = $v === true || $v === 1 || $v === '1' || $v === 'true';
            if ((bool) (int) $avant === $b) { continue; }
            $corps[$k] = is_bool($avant) ? $b : ($b ? 1 : 0);
            $lisible[] = $lib . ' : ' . ($b ? 'oui' : 'non');
        } elseif (in_array($type, ['nombre', 'tva', 'prix'], true)) {
            if ($v === null || $v === '') {
                if ($avant === null) { continue; }
                if ($type !== 'prix') { $erreurs[] = '« ' . $lib . ' » ne peut pas être vide.'; continue; }
                $corps[$k] = null; $lisible[] = $lib . ' : vidé';
            } else {
                if (!is_numeric($v) || (float) $v < 0 || ($type === 'nombre' && (float) $v > 100)) { $erreurs[] = '« ' . $lib . ' » : valeur invalide.'; continue; }
                if ($avant !== null && is_numeric($avant) && abs((float) $avant - (float) $v) <= 1e-9 * max(1.0, abs((float) $v))) { continue; }
                $corps[$k] = (float) $v;
                $lisible[] = $lib . ' : ' . ($avant === null || $avant === '' ? '—' : vrcQte((float) $avant)) . ' → ' . vrcQte((float) $v);
            }
        } elseif (in_array($type, ['categorie', 'unite'], true)) {
            if (!is_numeric($v) || (int) $v <= 0) { $erreurs[] = '« ' . $lib . ' » : valeur invalide.'; continue; }
            if ((int) $avant === (int) $v) { continue; }
            $corps[$k] = (int) $v;
            $lisible[] = $lib . ' : ' . $avant . ' → ' . (int) $v;
        } else {
            $t = trim((string) $v);
            if ($t === '' && $k === 'name') { $erreurs[] = 'Le nom ne peut pas être vide.'; continue; }
            if ((string) $avant === $t) { continue; }
            $corps[$k] = mb_substr($t, 0, 200);
            $lisible[] = $lib . ' : « ' . $avant . ' » → « ' . $corps[$k] . ' »';
        }
        $inverse[$k] = $avant;
    }
    return ['corps' => $corps, 'inverse' => $inverse, 'lisible' => $lisible, 'erreurs' => $erreurs];
}

/** La ligne d'une matière dans le tableau des matières (prix des magasins, fournisseurs, recettes) ; null si elle n'y est pas. */
function vmxLigne(int $mid): ?array
{
    $t = ep_verification_matieres_prix();
    foreach ((array) ($t['lignes'] ?? []) as $l) { if ((int) $l['mid'] === $mid) { return $l + ['magasinsNoms' => $t['magasins'] ?? []]; } }
    return null;
}

/** GET /verification/recettes/matiere?mid= — la fiche. */
function ep_verification_matiere(): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    $mid = (int) ($_GET['mid'] ?? 0);
    if ($mid <= 0) { http_response_code(400); return ['error' => 'mid requis']; }
    $o = vrcObjet('matiere', $mid);
    if ($o === null) { return ['ok' => false, 'motif' => 'La matière ' . $mid . ' n’a pas pu être lue au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $al = vmxAllergenesRef();
    // Les valeurs vues pour type, source et genre : celles des matières des magasins (jamais une liste devinée).
    $vues = ['material_type' => [], 'source_type' => [], 'kind' => []];
    foreach (mfMatieresApi(array_keys(vfMagasins()), false) as $ms) { foreach ((array) $ms as $m) { if (($m['type'] ?? '') !== '') { $vues['material_type'][$m['type']] = $m['type']; } if (($m['source'] ?? '') !== '') { $vues['source_type'][$m['source']] = $m['source']; } } }
    $champs = [];
    foreach (VMX_CHAMPS as $k => [$lib, $type]) {
        $present = array_key_exists($k, $o);
        $v = $o[$k] ?? null;
        if ($type === 'allergenes') { $v = vmxAllergenesDe($v, $al); }
        if ($type === 'valeurs' && $present && is_scalar($v) && (string) $v !== '') { $vues[$k][(string) $v] = (string) $v; }
        $champs[] = ['cle' => $k, 'lib' => $lib, 'type' => $type, 'valeur' => $v, 'modifiable' => $present, 'valeurs' => $type === 'valeurs' ? array_values($vues[$k] ?? []) : null];
    }
    $unite = $o['unit_name'] ?? null;
    $unites = vmxReference('vmxUnites', '/units', ['short_name', 'symbol', 'name', 'code']);
    if ($unite === null && isset($o['id_unit'])) { $unite = $unites[(string) $o['id_unit']] ?? null; }
    $U = mfUnite((string) ($unite ?? ''));
    $tva = [];
    foreach (analyseListe(PanelApi::get('/countries/' . VMX_PAYS . '/materials/vat-rates')) as $x) { $r = is_array($x) ? vfNum($x, ['rate', 'vat_rate', 'value']) : (is_numeric($x) ? (float) $x : null); if ($r !== null) { $tva[] = $r; } }
    if ($tva === []) { $tva = [0.0, 6.0, 12.0, 21.0]; }
    return ['ok' => true, 'mid' => $mid, 'nom' => vfTxt($o, ['name']), 'champs' => $champs, 'cles' => array_slice(array_keys($o), 0, 60),
        'unite' => $unite, 'par' => $U['label'], 'facteur' => $U['facteur'],
        'refs' => ['categories' => vmxReference('vmxCategories', '/material-categories'), 'unites' => $unites, 'allergenes' => array_values($al), 'tva' => array_values(array_unique($tva))],
        'ligne' => vmxLigne($mid), 'liens' => function_exists('vrcLiensDe') ? vrcLiensDe(['mid' => $mid]) : [],
        'source' => 'GET /materials/{id} (la matière, pour tout le réseau) · /material-categories, /units, /allergens, /countries/' . VMX_PAYS . '/materials/vat-rates · prix des magasins et fournisseurs : le tableau des matières'];
}

/** POST /verification/recettes/matiere — les changements d'une matière, puis les prix des magasins. */
function wr_verification_matiere(): array
{
    if (!PanelApi::configured()) { return ['ok' => false, 'error' => 'Le compte du panel n’est pas configuré (Paramètres).']; }
    @set_time_limit(120);
    $b = body();
    $mid = (int) ($b['mid'] ?? 0); $apercu = !empty($b['apercu']);
    if ($mid <= 0) { http_response_code(400); return ['error' => 'mid requis']; }
    $o = vrcObjet('matiere', $mid);
    if ($o === null) { return ['ok' => false, 'error' => 'La matière ' . $mid . ' n’a pas pu être lue au panel (' . (PanelApi::$lastError ?? 'pas de réponse') . ').']; }
    $nom = vfTxt($o, ['name']) ?: ('matière ' . $mid);
    $m = vmxChangements($o, is_array($b['champs'] ?? null) ? $b['champs'] : [], vmxAllergenesRef());
    if ($m['erreurs'] !== []) { return ['ok' => false, 'error' => implode(' ', $m['erreurs'])]; }
    $prix = [];
    foreach ((array) ($b['magasins'] ?? []) as $sid => $p) { if ((int) $sid > 0 && is_numeric($p) && (float) $p > 0) { $prix[(int) $sid] = (float) $p; } }
    if ($m['corps'] === [] && $prix === []) { return ['ok' => false, 'error' => 'Rien à changer.']; }
    $avants = $prix !== [] ? mfMatieresApi(array_keys($prix), true) : [];
    if ($apercu) {
        $ec = [];
        if ($m['corps'] !== []) { $ec[] = ['methode' => 'PATCH', 'route' => vrcChemin('matiere', $mid), 'corps' => $m['corps'], 'annulable' => true, 'resume' => implode(' · ', $m['lisible'])]; }
        foreach ($prix as $s => $p) { $ec[] = vrcPrixPlan($s, $mid, $p) + ['annulable' => ($avants[$s][(string) $mid]['prix'] ?? null) > 0, 'resume' => vrcPrixResume($nom, $s, $p, $avants[$s][(string) $mid]['prix'] ?? null)]; }
        return ['apercu' => true, 'resume' => $nom . ' : ' . count($ec) . ' écriture(s)', 'ecritures' => $ec];
    }
    $faites = []; $okP = true;
    if ($m['corps'] !== []) {
        $r = vrcEcrireObjet(['geste' => 'matiere', 'cle' => 'mat:' . $mid, 'pid' => null, 'nom' => $nom, 'shop' => null, 'objet' => 'matiere', 'id' => $mid, 'objetId' => $mid,
            'corps' => $m['corps'], 'inverse' => $m['inverse'], 'resume' => implode(' · ', $m['lisible'])]);
        $faites[] = vrcLigneRegistre($r);
        $okP = $r['resultat'] === 'ok';
    }
    if ($okP) {
        foreach ($prix as $s => $p) { $faites[] = vrcLigneRegistre(vrcEcrirePrix(['geste' => 'prix', 'cle' => 'mat:' . $mid, 'pid' => null, 'nom' => $nom, 'shop' => $s], $s, $mid, $p, $nom, $avants[$s][(string) $mid]['prix'] ?? null)); }
    }
    $ok = $faites !== [] && array_reduce($faites, static fn ($a, $f) => $a && $f['resultat'] === 'ok', true);
    if (array_filter($faites, static fn ($f) => $f['resultat'] === 'ok') !== []) {
        vrcOublier('vmpTable'); vrcOublier('vfAnalyse');
        foreach (array_keys(vfMagasins()) as $s) { vrcOublier('matieresApi:' . $s); }
    }
    return ['ok' => $ok, 'ecritures' => $faites, 'error' => $okP ? null : 'Les paramètres n’ont pas pris : les prix des magasins n’ont pas été écrits.'];
}
