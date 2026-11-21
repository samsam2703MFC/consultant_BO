#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Envoie à l'API TF Buddy les corrections du 21/11/2026 (étiquettes boutique en néerlandais), avec le compte admin du
 * cockpit (ErpApi, Paramètres › ERP) :
 *  - les allergènes manquants ou faux des matières (les ingrédients des fiches techniques) : PATCH /materials/{id}
 *    {allergens: [ids]}, qui remplace la liste — l'actuelle est relue, seul ce que le plan ajoute ou retire change
 *    (sonde du 21/11 : les matières premières des fournisseurs sont presque vides, les allergènes se reconnaissent
 *    à leur `code`) ;
 *  - les traductions FR / NL sans point à vérifier : PATCH /products/{id}/aliases (produit du panel retrouvé sans
 *    ambiguïté par les fiches techniques) et PATCH /materials/{id}/aliases (matière qui porte exactement le nom du
 *    produit de l'ERP) — {lang_code, alias, alias_type: "default"}.
 *
 *   php consultant/bin/envoi_etiquettes_nl.php                essai à blanc : lit tout, dit ce qui changerait, n'écrit rien
 *   php consultant/bin/envoi_etiquettes_nl.php --appliquer    écrit, puis relit chaque fiche modifiée
 *   options : --seulement=allergenes | --seulement=traductions
 *             --sans-matieres            traductions : les produits du panel seuls, pas les matières
 *             --plan=chemin.json         par défaut consultant/docs/etiquettes-nl/plan_envoi.json
 *             --racine=/var/www/…        la racine du cockpit, quand le script arrive par l'entrée standard
 *             --rapport=chemin.json      écrit le détail du passage (actions, refus, introuvables)
 *
 * Prudence : un nom introuvable n'est jamais deviné (signalé, sauté) ; un retrait d'allergène sur un nom porté par
 * plusieurs matières est sauté (un ajout s'applique à toutes : un œuf reste un œuf) ; un allergène déjà en place n'est
 * pas renvoyé ; une écriture ne compte que si la relecture la montre. Rien n'est supprimé hors de la liste « retirer »
 * du plan. La fusion des soupes aux courgettes se fait dans l'ERP de production : elle est rappelée en fin de passage.
 * Aucun jeton ni mot de passe n'est affiché.
 *
 * Sortie : 0 tout bon ; 1 au moins un refus, un introuvable ou une relecture qui ne montre pas l'écriture ;
 *          2 compte admin indisponible, plan illisible, ou allergènes de l'API impossibles à reconnaître.
 */
// La racine du cockpit : celle du script, ou --racine=… quand il arrive par l'entrée standard (sonde du 21/11).
$racine = dirname(__DIR__, 2);
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--racine=')) { $racine = rtrim(substr($a, 9), '/'); } }
require $racine . '/shared/src/Db.php';
require $racine . '/shared/src/endpoints.php';
require $racine . '/shared/src/erp_api.php';

set_time_limit(0);
$args = array_slice($argv, 1);
$opt = static function (string $k) use ($args): ?string {
    foreach ($args as $a) { if (str_starts_with($a, '--' . $k . '=')) { return substr($a, strlen($k) + 3); } }
    return null;
};
$appliquer = in_array('--appliquer', $args, true);
$seulement = $opt('seulement');
$sansMatieres = in_array('--sans-matieres', $args, true);
$planFichier = $opt('plan') ?? $racine . '/consultant/docs/etiquettes-nl/plan_envoi.json';
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
/** Une réponse en liste : liste à la racine, {data: liste}, ou objet indexé par id (forme de /allergens, sonde du 21/11). */
$enListe = static function ($r): array {
    if (!is_array($r)) { return []; }
    if (array_is_list($r)) { return array_values(array_filter($r, 'is_array')); }
    foreach (['data', 'items', 'results'] as $k) { if (is_array($r[$k] ?? null)) { return array_values(array_filter($r[$k], 'is_array')); } }
    $vals = array_values(array_filter($r, 'is_array'));
    return count($vals) === count($r) ? $vals : [];
};
/** Une liste complète : la réponse, puis ses pages suivantes si elle a l'air paginée. */
$liste = static function (string $chemin) use ($dire, $enListe): array {
    $tout = $enListe(ErpApi::get($chemin));
    if (in_array(count($tout), [10, 15, 20, 25, 30, 50, 100, 200], true)) {
        $vus = array_flip(array_map(static fn ($o) => (string) ($o['id'] ?? ''), $tout));
        for ($p = 2; $p <= 200; $p++) {
            $page = $enListe(ErpApi::get($chemin . (str_contains($chemin, '?') ? '&' : '?') . 'page=' . $p));
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
    $dire("\n== Allergènes des matières (" . count($plan['allergenes']) . ' ingrédients du plan)');
    // Les 14 allergènes de l'API (sonde du 21/11 : un objet indexé par id, chaque allergène avec un `code` stable),
    // reconnus par ce code ; le nom ne sert qu'en repli.
    $CODES = ['cereals_gluten' => 'gluten', 'crustaceans' => 'crustaces', 'eggs' => 'oeufs', 'fish' => 'poisson',
        'peanuts' => 'arachides', 'soybeans' => 'soja', 'milk' => 'lait', 'nuts' => 'fruits_a_coque', 'celery' => 'celeri',
        'mustard' => 'moutarde', 'sesame_seeds' => 'sesame', 'sulphur_dioxide_sulphites' => 'sulfites', 'lupin' => 'lupin',
        'molluscs' => 'mollusques'];
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
    foreach ($enListe(ErpApi::get('/allergens')) as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) { continue; }
        $trouves = [];
        if (isset($CODES[(string) ($a['code'] ?? '')])) { $trouves[] = $CODES[(string) $a['code']]; }
        else {
            $txt = implode(' | ', $noms($a));
            foreach ($MOTS as $c => $rx) { if (preg_match($rx, $txt)) { $trouves[] = $c; } }
        }
        foreach ($trouves as $c) {
            if (isset($idAllergene[$c]) && $idAllergene[$c] !== $id) { $ambigus[$c] = true; }
            $idAllergene[$c] = $id;
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

    // Les ingrédients des fiches techniques sont des matières (/materials) : leurs allergènes s'écrivent par
    // PATCH /materials/{id} {allergens: [ids]}, qui REMPLACE la liste — on relit donc l'actuelle et on n'y change que
    // ce que le plan ajoute ou retire.
    $index = [];
    foreach ($liste('/materials') as $m) {
        $mid = (int) ($m['id'] ?? 0);
        if ($mid > 0) { foreach ($noms($m) as $n) { $index[$n][$mid] = (string) ($m['name'] ?? ''); } }
    }
    $dire('  ' . count($index) . ' noms de matières indexés');
    $lireAllergenes = static function (int $mid) use ($enListe): ?array {
        $m = ErpApi::get('/materials/' . $mid);
        if (!is_array($m)) { return null; }
        $ids = [];
        foreach ($enListe($m['allergens'] ?? []) as $a) {
            $id = is_array($a) ? ($a['id_allergen'] ?? $a['allergen_id'] ?? $a['id'] ?? null) : $a;
            if (is_numeric($id)) { $ids[(int) $id] = (int) $id; }
        }
        if (is_array($m['allergens'] ?? null) && array_is_list($m['allergens'])) {
            foreach ($m['allergens'] as $a) { if (is_numeric($a)) { $ids[(int) $a] = (int) $a; } }
        }
        ksort($ids);
        return array_values($ids);
    };
    foreach ($plan['allergenes'] as $l) {
        $cibles = $index[$norm($l['ingredient'])] ?? [];
        if ($cibles === []) { $probleme('introuvable', 'matière introuvable (sous-recette ?) : « ' . $l['ingredient'] . ' »'); continue; }
        if ($l['retirer'] !== [] && count($cibles) > 1) {
            $probleme('ambigu', '« ' . $l['ingredient'] . ' » : ' . count($cibles) . ' matières du même nom, retrait de ' . implode(', ', $l['retirer']) . ' sauté');
            $l['retirer'] = [];
        }
        foreach ($cibles as $mid => $mnom) {
            $avant = $lireAllergenes((int) $mid);
            if ($avant === null) { $probleme('refus', 'fiche illisible : matière ' . $mid . ' (' . (ErpApi::$lastError ?? '?') . ')'); continue; }
            $apresVoulu = $avant; $faits = [];
            foreach ($l['ajouter'] as $c) {
                if (in_array($idAllergene[$c], $avant, true)) { $bilan['deja']++; continue; }
                $apresVoulu[] = $idAllergene[$c]; $faits[] = '+' . $c;
            }
            foreach ($l['retirer'] as $c) {
                if (!in_array($idAllergene[$c], $avant, true)) { $bilan['deja']++; continue; }
                $apresVoulu = array_values(array_diff($apresVoulu, [$idAllergene[$c]])); $faits[] = '-' . $c;
            }
            if ($faits === []) { continue; }
            sort($apresVoulu);
            if (!$ecrire('PATCH', '/materials/' . $mid, ['allergens' => array_values(array_unique($apresVoulu))])) { continue; }
            $dire('  ' . ($appliquer ? 'écrit' : 'à écrire') . ' : « ' . $l['ingredient'] . ' » (matière ' . $mid . ') ' . implode(' ', $faits)
                . ' — allergènes ' . (implode(',', $avant) ?: 'aucun') . ' → ' . (implode(',', array_unique($apresVoulu)) ?: 'aucun'));
            if ($appliquer) {
                $relu = $lireAllergenes((int) $mid) ?? [];
                if ($relu !== array_values(array_unique($apresVoulu))) {
                    $probleme('non_relu', 'relecture : matière ' . $mid . ' « ' . $l['ingredient'] . ' » porte ' . (implode(',', $relu) ?: 'aucun') . ' au lieu de ' . implode(',', array_unique($apresVoulu)));
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
    // Les traductions déjà en place (/…/aliases : [{fk_id, lang_code, alias_value, …}]) : une valeur identique n'est pas renvoyée.
    $lireAlias = static function (string $type) use ($enListe): array {
        $vus = [];
        foreach ($enListe(ErpApi::get('/' . $type . '/aliases')) as $al) {
            $vus[(int) ($al['fk_id'] ?? $al['id'] ?? 0) . '|' . (string) ($al['lang_code'] ?? '')] = (string) ($al['alias_value'] ?? $al['alias'] ?? '');
        }
        return $vus;
    };
    $enPlace = ['products' => $lireAlias('products'), 'materials' => $sansMatieres ? [] : $lireAlias('materials')];
    foreach ($plan['traductions'] as $t) {
        $cibles = [];
        if (!empty($t['produit_panel'])) {
            $ids = $produits[$norm($t['produit_panel'])] ?? [];
            if (count($ids) === 1) { $cibles[] = ['products', reset($ids)]; }
            elseif ($ids === []) { $probleme('introuvable', 'produit du panel introuvable : « ' . $t['produit_panel'] . ' »'); }
            else { $probleme('ambigu', 'produit du panel « ' . $t['produit_panel'] . ' » : ' . count($ids) . ' fiches du même nom, sauté'); }
        } else {
            // Un produit du panel qui porte exactement le nom du produit de l'ERP (sonde du 21/11 : 9 sur 100).
            $ids = [];
            foreach ($t['noms_erp'] as $n) { $ids += $produits[$norm($n)] ?? []; }
            if (count($ids) === 1) { $cibles[] = ['products', reset($ids)]; $t['produit_panel'] = $t['noms_erp'][0]; }
            elseif (count($ids) > 1) { $probleme('ambigu', 'produit du panel « ' . $t['noms_erp'][0] . ' » : ' . count($ids) . ' fiches du même nom, sauté'); }
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
            $ok = true; $nb = 0;
            foreach (['fr' => $t['fr'], 'nl' => $t['nl']] as $langue => $alias) {
                if (($enPlace[$type][$id . '|' . $langue] ?? null) === $alias) { $bilan['deja']++; continue; }
                $nb++;
                $ok = $ecrire('PATCH', '/' . $type . '/' . $id . '/aliases', ['lang_code' => $langue, 'alias' => $alias, 'alias_type' => 'default']) && $ok;
            }
            if ($nb === 0) { continue; }
            if ($ok) { $envoyes[$type][$id] = $t; }
            $nomVu = $type === 'products' ? (string) $t['produit_panel'] : (string) $t['noms_erp'][0];
            $dire('  ' . ($appliquer ? 'écrit' : 'à écrire') . ' : ' . $type . ' ' . $id . ' « ' . $nomVu . ' » → FR « ' . $t['fr'] . ' » · NL « ' . $t['nl'] . ' »');
        }
    }
    // Relecture : chaque alias envoyé doit apparaître dans la liste des traductions relue.
    if ($appliquer) {
        foreach ($envoyes as $type => $parId) {
            if ($parId === []) { continue; }
            $vus = $lireAlias($type);
            foreach ($parId as $id => $t) {
                foreach (['fr', 'nl'] as $langue) {
                    if (($vus[$id . '|' . $langue] ?? null) !== $t[$langue]) {
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
