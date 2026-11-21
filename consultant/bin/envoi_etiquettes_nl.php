#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Envoie à l'API TF Buddy les corrections du 21/11/2026 (étiquettes boutique en néerlandais), avec le compte admin du
 * cockpit (ErpApi, Paramètres › ERP) :
 *  - les allergènes manquants ou faux des matières premières des fournisseurs, relevés dans les fiches techniques :
 *    POST /material-suppliers/{fournisseur}/raw-materials/{id}/allergens {id_allergen} et
 *    DELETE /material-suppliers/{fournisseur}/raw-materials/{id}/allergens/{id_allergene} ;
 *  - les traductions FR / NL sans point à vérifier : PATCH /products/{id}/aliases (produit du panel retrouvé sans
 *    ambiguïté par les fiches techniques) et PATCH /materials/{id}/aliases (matière qui porte exactement le nom du
 *    produit de l'ERP) — {lang_code, alias, alias_type: "default"}.
 *
 *   php consultant/bin/envoi_etiquettes_nl.php                essai à blanc : lit tout, dit ce qui changerait, n'écrit rien
 *   php consultant/bin/envoi_etiquettes_nl.php --appliquer    écrit, puis relit chaque fiche modifiée
 *   options : --seulement=allergenes | --seulement=traductions
 *             --sans-matieres            traductions : les produits du panel seuls, pas les matières
 *             --plan=chemin.json         par défaut consultant/docs/etiquettes-nl/plan_envoi.json
 *             --rapport=chemin.json      écrit le détail du passage (actions, refus, introuvables)
 *
 * Prudence : un nom introuvable n'est jamais deviné (signalé, sauté) ; un retrait d'allergène sur un nom trouvé chez
 * plusieurs fournisseurs est sauté (un ajout s'applique à tous : un œuf reste un œuf) ; un allergène déjà en place n'est
 * pas renvoyé ; une écriture ne compte que si la relecture la montre. Rien n'est supprimé hors de la liste « retirer »
 * du plan. La fusion des soupes aux courgettes se fait dans l'ERP de production : elle est rappelée en fin de passage.
 * Aucun jeton ni mot de passe n'est affiché.
 *
 * Sortie : 0 tout bon ; 1 au moins un refus, un introuvable ou une relecture qui ne montre pas l'écriture ;
 *          2 compte admin indisponible, plan illisible, ou allergènes de l'API impossibles à reconnaître.
 */
require __DIR__ . '/../../shared/src/Db.php';
require __DIR__ . '/../../shared/src/endpoints.php';
require __DIR__ . '/../../shared/src/erp_api.php';

set_time_limit(0);
$args = array_slice($argv, 1);
$opt = static function (string $k) use ($args): ?string {
    foreach ($args as $a) { if (str_starts_with($a, '--' . $k . '=')) { return substr($a, strlen($k) + 3); } }
    return null;
};
$appliquer = in_array('--appliquer', $args, true);
$seulement = $opt('seulement');
$sansMatieres = in_array('--sans-matieres', $args, true);
$planFichier = $opt('plan') ?? __DIR__ . '/../docs/etiquettes-nl/plan_envoi.json';
$rapportFichier = $opt('rapport');
$dire = static function (string $m): void { fwrite(STDOUT, $m . "\n"); };
$rapport = ['mode' => $appliquer ? 'appliquer' : 'essai à blanc', 'debut' => date('c'), 'actions' => [], 'problemes' => []];
$bilan = ['ecrit' => 0, 'a_ecrire' => 0, 'deja' => 0, 'introuvable' => 0, 'ambigu' => 0, 'refus' => 0, 'non_relu' => 0];
$probleme = static function (string $type, string $m) use (&$rapport, &$bilan, $dire): void {
    $bilan[$type] = ($bilan[$type] ?? 0) + 1;
    $rapport['problemes'][] = ['type' => $type, 'message' => $m];
    $dire('  ! ' . $m);
};

$plan = json_decode((string) @file_get_contents($planFichier), true);
if (!is_array($plan) || !isset($plan['allergenes'], $plan['traductions'])) {
    fwrite(STDERR, 'plan illisible : ' . $planFichier . "\n");
    exit(2);
}
if (!ErpApi::disponible()) {
    fwrite(STDERR, "compte admin de l'ERP non configuré (cockpit, Paramètres › ERP) : rien n'est lu ni écrit.\n");
    exit(2);
}
$dire(($appliquer ? 'ÉCRITURE' : 'ESSAI À BLANC (rien n\'est écrit ; --appliquer pour écrire)') . ' — plan ' . ($plan['version'] ?? '?'));

/** Nom comparable : minuscules, sans accents, ⌀ et tirets unifiés, espaces et points réduits. */
$norm = static function (string $s): string {
    $s = strtr(trim($s), ['Ø' => '⌀', 'ø' => '⌀', '–' => '-', '—' => '-', '’' => "'"]);
    $s = mb_strtolower($s);
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($d)) { $s = preg_replace('/\p{Mn}+/u', '', $d) ?? $s; }
    }
    return trim(preg_replace('/[\s.]+/u', ' ', $s) ?? $s);
};
/** Les noms d'un objet de l'API (le contrat des réponses n'est pas documenté au swagger : on lit les clés usuelles). */
$noms = static function (array $o) use ($norm): array {
    $n = [];
    foreach (['name', 'base_name', 'label', 'title'] as $k) {
        if (is_string($o[$k] ?? null) && trim($o[$k]) !== '') { $n[] = $norm($o[$k]); }
    }
    foreach (['material', 'raw_material'] as $k) {
        if (is_array($o[$k] ?? null) && is_string($o[$k]['name'] ?? null)) { $n[] = $norm($o[$k]['name']); }
    }
    return array_values(array_unique($n));
};
/** Une liste complète : la réponse, puis ses pages suivantes si elle a l'air paginée. */
$liste = static function (string $chemin) use ($dire): array {
    $tout = analyseListe(ErpApi::get($chemin));
    if (in_array(count($tout), [10, 15, 20, 25, 30, 50, 100, 200], true)) {
        $vus = array_flip(array_map(static fn ($o) => (string) ($o['id'] ?? ''), $tout));
        for ($p = 2; $p <= 200; $p++) {
            $page = analyseListe(ErpApi::get($chemin . (str_contains($chemin, '?') ? '&' : '?') . 'page=' . $p));
            $neufs = array_filter($page, static fn ($o) => !isset($vus[(string) ($o['id'] ?? '')]));
            if ($neufs === []) { break; }
            foreach ($neufs as $o) { $vus[(string) ($o['id'] ?? '')] = true; $tout[] = $o; }
        }
    }
    if ($tout === [] && ErpApi::$lastError) { $dire('  ! ' . ErpApi::$lastError); }
    return $tout;
};
$ecrire = static function (string $methode, string $chemin, ?array $corps) use ($appliquer, &$rapport, &$bilan, $probleme, $dire): bool {
    $rapport['actions'][] = ['methode' => $methode, 'chemin' => $chemin, 'corps' => $corps];
    if (!$appliquer) { $bilan['a_ecrire']++; return true; }
    $w = ErpApi::ecrire($methode, $chemin, $corps);
    usleep(150_000);
    if ($w['erreur'] !== null) { $probleme('refus', $methode . ' ' . $chemin . ' → ' . $w['erreur']); return false; }
    $bilan['ecrit']++;
    return true;
};

// ---------------------------------------------------------------------------------------------- allergènes
if ($seulement === null || $seulement === 'allergenes') {
    $dire("\n== Allergènes des matières premières (" . count($plan['allergenes']) . ' ingrédients du plan)');
    // Les 14 allergènes de l'API, reconnus par leur nom (polonais, français, anglais, néerlandais).
    $MOTS = [
        'gluten' => '/gluten/u', 'crustaces' => '/skorupiak|crustac|schaaldier/u', 'oeufs' => '/\bjaj|oeuf|œuf|\begg|\beier/u',
        'poisson' => '/\bryb|poisson|\bfish|\bvis\b/u', 'arachides' => '/orzeszk|arachid|peanut|aardnot|pinda/u',
        'soja' => '/\bsoj|\bsoy/u', 'lait' => '/mlek|\blait|\bmilk|\bmelk|lactos|laktoz/u',
        'fruits_a_coque' => '/orzech|fruits a coque|\bnuts\b|\bnoten\b|tree nut/u', 'celeri' => '/seler|celeri|celery|selder/u',
        'moutarde' => '/gorczyc|moutarde|mustard|mosterd/u', 'sesame' => '/sezam|sesam/u',
        'sulfites' => '/siark|sulfit|sulphit|sulfiet|soufre|sulphur|sulfur/u', 'lupin' => '/lubin|łubin|lupin/u',
        'mollusques' => '/mieczak|mollus|weekdier/u',
    ];
    $idAllergene = [];
    $ambigus = [];
    foreach (analyseListe(ErpApi::get('/allergens')) as $a) {
        $txt = implode(' | ', $noms($a));
        foreach ((array) ($a['aliases'] ?? []) as $al) { if (is_array($al) && is_string($al['alias'] ?? null)) { $txt .= ' | ' . $norm($al['alias']); } }
        if ((int) ($a['id'] ?? 0) <= 0) { continue; }
        foreach ($MOTS as $code => $rx) {
            if (preg_match($rx, $txt)) {
                if (isset($idAllergene[$code]) && $idAllergene[$code] !== (int) $a['id']) { $ambigus[$code] = true; }
                $idAllergene[$code] = (int) $a['id'];
            }
        }
    }
    $requis = [];
    foreach ($plan['allergenes'] as $l) { foreach (array_merge($l['ajouter'], $l['retirer']) as $c) { $requis[$c] = true; } }
    $manque = array_diff(array_keys($requis), array_keys($idAllergene));
    if ($manque !== [] || array_intersect_key($ambigus, $requis) !== []) {
        fwrite(STDERR, "allergènes de l'API non reconnus avec certitude : " . implode(', ', array_merge($manque, array_keys(array_intersect_key($ambigus, $requis))))
            . ' (' . (ErpApi::$lastError ?? 'liste /allergens à relire') . ") — rien n'est envoyé.\n");
        exit(2);
    }
    $dire('  allergènes de l\'API : ' . implode(', ', array_map(static fn ($c) => $c . '=' . $idAllergene[$c], array_keys($requis))));

    // Index des matières premières de tous les fournisseurs, par nom.
    $index = [];
    $fournisseurs = $liste('/material-suppliers');
    foreach ($fournisseurs as $f) {
        $fid = (int) ($f['id'] ?? 0);
        if ($fid <= 0) { continue; }
        foreach ($liste('/material-suppliers/' . $fid . '/raw-materials') as $m) {
            $rid = (int) ($m['id'] ?? 0);
            if ($rid <= 0) { continue; }
            foreach ($noms($m) as $n) { $index[$n][$fid . '/' . $rid] = [$fid, $rid, (string) ($f['name'] ?? $fid), (string) ($m['name'] ?? '')]; }
        }
    }
    $dire('  ' . count($fournisseurs) . ' fournisseurs, ' . count($index) . ' noms de matières indexés');
    $lireAllergenes = static function (int $fid, int $rid): array {
        $ids = [];
        foreach (analyseListe(ErpApi::get('/material-suppliers/' . $fid . '/raw-materials/' . $rid . '/allergens')) as $a) {
            $id = $a['id_allergen'] ?? $a['allergen_id'] ?? (is_array($a['allergen'] ?? null) ? ($a['allergen']['id'] ?? null) : null) ?? $a['id'] ?? null;
            if ($id !== null) { $ids[(int) $id] = true; }
        }
        return array_keys($ids);
    };
    foreach ($plan['allergenes'] as $l) {
        $cibles = $index[$norm($l['ingredient'])] ?? [];
        if ($cibles === []) { $probleme('introuvable', 'matière introuvable : « ' . $l['ingredient'] . ' »'); continue; }
        if ($l['retirer'] !== [] && count($cibles) > 1) {
            $probleme('ambigu', '« ' . $l['ingredient'] . ' » existe chez ' . count($cibles) . ' fournisseurs : retrait de ' . implode(', ', $l['retirer']) . ' sauté');
            $l['retirer'] = [];
        }
        foreach ($cibles as [$fid, $rid, $fnom]) {
            $avant = $lireAllergenes($fid, $rid);
            $base = '/material-suppliers/' . $fid . '/raw-materials/' . $rid . '/allergens';
            $faits = [];
            foreach ($l['ajouter'] as $c) {
                if (in_array($idAllergene[$c], $avant, true)) { $bilan['deja']++; continue; }
                if ($ecrire('POST', $base, ['id_allergen' => $idAllergene[$c]])) { $faits[] = '+' . $c; }
            }
            foreach ($l['retirer'] as $c) {
                if (!in_array($idAllergene[$c], $avant, true)) { $bilan['deja']++; continue; }
                if ($ecrire('DELETE', $base . '/' . $idAllergene[$c], null)) { $faits[] = '-' . $c; }
            }
            if ($faits === []) { continue; }
            $dire('  ' . ($appliquer ? 'écrit' : 'à écrire') . ' : « ' . $l['ingredient'] . ' » (' . $fnom . ', matière ' . $rid . ') ' . implode(' ', $faits));
            if ($appliquer) {
                $apres = $lireAllergenes($fid, $rid);
                foreach ($faits as $f) {
                    $present = in_array($idAllergene[substr($f, 1)], $apres, true);
                    if (($f[0] === '+') !== $present) { $probleme('non_relu', 'relecture : ' . $f . ' non visible sur « ' . $l['ingredient'] . ' » (matière ' . $rid . ')'); }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------- traductions
if ($seulement === null || $seulement === 'traductions') {
    $dire("\n== Traductions FR / NL (" . count($plan['traductions']) . ' produits sans point à vérifier)');
    $parNom = static function (array $objets) use ($noms): array {
        $i = [];
        foreach ($objets as $o) { $id = (int) ($o['id'] ?? 0); if ($id > 0) { foreach ($noms($o) as $n) { $i[$n][$id] = $id; } } }
        return $i;
    };
    $produits = $parNom($liste('/products'));
    $matieres = $sansMatieres ? [] : $parNom($liste('/materials'));
    $dire('  ' . count($produits) . ' noms de produits du panel' . ($sansMatieres ? '' : ', ' . count($matieres) . ' noms de matières') . ' indexés');
    $envoyes = ['products' => [], 'materials' => []];
    foreach ($plan['traductions'] as $t) {
        $cibles = [];
        if (!empty($t['produit_panel'])) {
            $ids = $produits[$norm($t['produit_panel'])] ?? [];
            if (count($ids) === 1) { $cibles[] = ['products', reset($ids)]; }
            elseif ($ids === []) { $probleme('introuvable', 'produit du panel introuvable : « ' . $t['produit_panel'] . ' »'); }
            else { $probleme('ambigu', 'produit du panel « ' . $t['produit_panel'] . ' » : ' . count($ids) . ' fiches du même nom, sauté'); }
        }
        if (!$sansMatieres) {
            $ids = [];
            foreach ($t['noms_erp'] as $n) { $ids += $matieres[$norm($n)] ?? []; }
            if (count($ids) === 1) { $cibles[] = ['materials', reset($ids)]; }
            elseif (count($ids) > 1) { $probleme('ambigu', 'matière « ' . $t['noms_erp'][0] . ' » : ' . count($ids) . ' fiches du même nom, sautée'); }
        }
        if ($cibles === []) {
            if (empty($t['produit_panel'])) { $probleme('introuvable', 'ni produit du panel ni matière pour « ' . $t['noms_erp'][0] . ' »'); }
            continue;
        }
        foreach ($cibles as [$type, $id]) {
            $ok = true;
            foreach (['fr' => $t['fr'], 'nl' => $t['nl']] as $langue => $alias) {
                $ok = $ecrire('PATCH', '/' . $type . '/' . $id . '/aliases', ['lang_code' => $langue, 'alias' => $alias, 'alias_type' => 'default']) && $ok;
            }
            if ($ok) { $envoyes[$type][$id] = $t; }
            $nomVu = $type === 'products' ? (string) $t['produit_panel'] : (string) $t['noms_erp'][0];
            $dire('  ' . ($appliquer ? 'écrit' : 'à écrire') . ' : ' . $type . ' ' . $id . ' « ' . $nomVu . ' » → FR « ' . $t['fr'] . ' » · NL « ' . $t['nl'] . ' »');
        }
    }
    // Relecture : chaque alias envoyé doit apparaître dans la liste des traductions relue.
    if ($appliquer) {
        foreach ($envoyes as $type => $parId) {
            if ($parId === []) { continue; }
            $relu = json_encode(ErpApi::get('/' . $type . '/aliases'), JSON_UNESCAPED_UNICODE) ?: '';
            foreach ($parId as $id => $t) {
                foreach (['fr', 'nl'] as $langue) {
                    if (!str_contains($relu, json_encode($t[$langue], JSON_UNESCAPED_UNICODE) ?: "\0")) {
                        $probleme('non_relu', 'relecture : alias ' . $langue . ' « ' . $t[$langue] . ' » absent de /' . $type . '/aliases (' . $type . ' ' . $id . ')');
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------- bilan
if (!empty($plan['soupe_courgettes'])) {
    $s = $plan['soupe_courgettes'];
    $dire("\n== À faire dans l'ERP de production (pas par cette API) : soupe aux courgettes unique ("
        . $s['garde'] . ' ; ' . implode(', ', $s['fusionne']) . ' à désactiver), recette ' . $s['ingredients_fr'] . '.');
}
$dire("\n== Bilan : " . implode(' · ', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($bilan), $bilan)));
if ($rapportFichier !== null) {
    $rapport['fin'] = date('c');
    $rapport['bilan'] = $bilan;
    file_put_contents($rapportFichier, json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $dire('rapport : ' . $rapportFichier);
}
exit(($bilan['refus'] + $bilan['introuvable'] + $bilan['ambigu'] + $bilan['non_relu']) > 0 ? 1 : 0);
