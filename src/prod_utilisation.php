<?php
declare(strict_types=1);

/**
 * Cockpit CEO — l'usage du catalogue, magasin par magasin.
 *
 * Le réseau tient 710 références. Un magasin qui n'en vend que la moitié ne le
 * sait pas : sa caisse lui dit ce qu'il a vendu, jamais ce qu'il n'a pas
 * proposé. Le score produit, lui, note les références sur leur performance
 * RÉSEAU — une référence excellente ailleurs et absente ici n'y apparaît pas.
 *
 * Cet écran répond donc à une question simple : sur les références du
 * catalogue, combien ce magasin en a-t-il vendu ce mois-ci ? Et, ce qui est
 * actionnable : lesquelles vendent les autres et pas lui ?
 *
 * La lecture se fait sur les LIGNES DE TICKET (`transaction_product`), la seule
 * source qui relie une référence à un magasin et à une date. Les noms de
 * tables et de colonnes sont RÉSOLUS, jamais supposés : la caisse a changé de
 * schéma une fois déjà.
 */

/** Les colonnes candidates, comme le module marketing les connaît. */
const UTIL_COLS_TICKET = [
    'id'   => ['id', 'id_transaction', 'transaction_id'],
    'shop' => ['id_shop', 'shop_id', 'id_franchisee_shop'],
    'date' => ['insert_timestamp', 'created_at', 'sale_date', 'sold_at', 'timestamp'],
];
const UTIL_COLS_LIGNE = [
    'ticket'   => ['id_transaction', 'transaction_id'],
    'produit'  => ['id_product', 'product_id'],
    'quantite' => ['quantity', 'qty'],
];

/**
 * Les colonnes réellement présentes, par notion.
 *
 * La clé du résultat est lue SANS la nommer : MySQL rend `COLUMN_NAME` ou
 * `column_name` selon la configuration, et nommer la mauvaise rend une liste
 * vide — donc « aucune colonne », donc un écran muet sans erreur.
 *
 * @return array<string,string>|null
 */
function utilColonnes(string $table, array $candidats): ?array
{
    $presentes = [];
    try {
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]) as $r) {
            $v = is_array($r) ? (string) reset($r) : (string) $r;
            if ($v !== '') { $presentes[strtolower($v)] = $v; }
        }
    } catch (Throwable $e) { return null; }
    if ($presentes === []) { return null; }

    $out = [];
    foreach ($candidats as $notion => $noms) {
        foreach ($noms as $nom) {
            if (isset($presentes[strtolower($nom)])) { $out[$notion] = $presentes[strtolower($nom)]; break; }
        }
        if (!isset($out[$notion])) { return null; }
    }
    return $out;
}

/** Les N derniers mois, du plus ancien au plus récent. */
function utilMois(int $n): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $out[] = ['cle' => date('Y-m', $t), 'lib' => strftime_fr($t, 'M Y'),
            'du' => date('Y-m-01', $t), 'au' => date('Y-m-t', $t),
            'encours' => date('Y-m', $t) === date('Y-m')];
    }
    return $out;
}

/**
 * GET /produits/utilisation?mois=6[&groupe=][&categorie=]
 *
 * Combien de références du catalogue chaque magasin a-t-il vendues, mois par
 * mois ? Et lesquelles lui manquent que les autres vendent ?
 */
function ep_prod_utilisation(): array
{
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $groupe = trim((string) ($_GET['groupe'] ?? ''));
    $categorie = trim((string) ($_GET['categorie'] ?? ''));

    $mois = utilMois($n);
    $du = $mois[0]['du'];
    $au = $mois[count($mois) - 1]['au'];

    // --- Le catalogue : ce qu'on compte comme « vendable ».
    $cat = ep_prod_catalogue();
    $refs = [];       // id caisse → ['ref','nom','categorie','groupe']
    $categories = []; $groupes = [];
    foreach ($cat as $p) {
        $g = (string) ($p['groupe'] ?? '');
        $c = (string) ($p['categorie'] ?? '');
        if ($g !== '') { $groupes[$g] = true; }
        if ($c !== '') { $categories[$c] = true; }
        if ($groupe !== '' && $g !== $groupe) { continue; }
        if ($categorie !== '' && $c !== $categorie) { continue; }
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        $refs[(int) $pid] = ['ref' => (string) $p['ref'], 'nom' => (string) $p['nom'],
            'categorie' => $c, 'groupe' => $g];
    }
    ksort($categories); ksort($groupes);

    $out = [
        'mois' => $mois, 'catalogue' => count($refs),
        'catalogueTotal' => count($cat),
        'groupes' => array_keys($groupes), 'categories' => array_keys($categories),
        'groupe' => $groupe, 'categorie' => $categorie,
        'magasins' => [], 'reseau' => null, 'manquantes' => [], 'motif' => null,
    ];
    if ($refs === []) {
        $out['motif'] = 'aucune référence du catalogue ne porte d’identifiant de caisse : rien à rapprocher';
        return $out;
    }

    // --- Les lignes de ticket.
    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) {
        $out['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base : l’usage du catalogue ne peut pas être lu';
        return $out;
    }

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    // Une seule requête : magasin × mois × référence. Les références hors
    // périmètre sont écartées en PHP — filtrer en SQL sur sept cents
    // identifiants allongerait la requête sans rien accélérer.
    $vendu = [];      // shop => mois => [pid => qte]
    $vendeurs = [];   // pid => [shop => true]
    try {
        $sql = sprintf(
            'SELECT t.`%s` AS shop, DATE_FORMAT(t.`%s`, \'%%Y-%%m\') AS mois,
                    l.`%s` AS produit, SUM(l.`%s`) AS q
               FROM `transaction_product` l
               JOIN `transaction` t ON t.`%s` = l.`%s`
              WHERE t.`%s` >= ? AND t.`%s` < ?
              GROUP BY shop, mois, produit',
            $ct['shop'], $ct['date'], $cl['produit'], $cl['quantite'],
            $ct['id'], $cl['ticket'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']) as $r) {
            $pid = (int) $r['produit'];
            if (!isset($refs[$pid])) { continue; }
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $vendu[$sid][(string) $r['mois']][$pid] = (float) $r['q'];
            $vendeurs[$pid][$sid] = true;
        }
    } catch (PDOException $e) {
        $out['motif'] = 'lecture des lignes de ticket impossible : ' . $e->getMessage();
        return $out;
    }

    // --- Le tableau : une ligne par magasin, une colonne par mois.
    $total = count($refs);
    $lignes = [];
    foreach ($nomDe as $sid => $nom) {
        $parMois = [];
        $vuesToutes = [];
        foreach ($mois as $m) {
            $v = $vendu[$sid][$m['cle']] ?? [];
            foreach ($v as $pid => $q) { $vuesToutes[$pid] = true; }
            $parMois[] = ['cle' => $m['cle'], 'refs' => count($v),
                'taux' => $total > 0 ? round(100 * count($v) / $total, 1) : null,
                'encours' => $m['encours']];
        }
        // Sur la période entière : un magasin peut tourner ses références d'un
        // mois à l'autre sans jamais en vendre beaucoup le même mois.
        $lignes[] = [
            'id' => $sid, 'nom' => $nom,
            'mois' => $parMois,
            'refsPeriode' => count($vuesToutes),
            'tauxPeriode' => $total > 0 ? round(100 * count($vuesToutes) / $total, 1) : null,
            'vues' => array_keys($vuesToutes),
        ];
    }

    // Le réseau : ce que l'ENSEMBLE des magasins a vendu — le plafond réel.
    $vuesReseau = [];
    foreach ($lignes as $l) { foreach ($l['vues'] as $pid) { $vuesReseau[$pid] = true; } }
    $out['reseau'] = ['refs' => count($vuesReseau),
        'taux' => $total > 0 ? round(100 * count($vuesReseau) / $total, 1) : null,
        'jamais' => $total - count($vuesReseau)];

    // --- Ce qui est ACTIONNABLE : les références que les autres vendent et pas
    // lui. Une référence vendue par un seul magasin n'est pas un manque, c'est
    // une spécialité ; on ne retient que celles vendues par la majorité.
    $seuil = max(2, (int) ceil(count($nomDe) / 2));
    foreach ($lignes as $i => $l) {
        $vues = array_flip($l['vues']);
        $manque = [];
        foreach ($vendeurs as $pid => $par) {
            if (isset($vues[$pid]) || count($par) < $seuil) { continue; }
            $manque[] = ['ref' => $refs[$pid]['ref'], 'nom' => $refs[$pid]['nom'],
                'categorie' => $refs[$pid]['categorie'], 'magasins' => count($par)];
        }
        usort($manque, static fn ($a, $b) => $b['magasins'] <=> $a['magasins'] ?: strcmp($a['nom'], $b['nom']));
        $lignes[$i]['manquantes'] = count($manque);
        $lignes[$i]['manquantesTop'] = array_slice($manque, 0, 12);
    }

    // --- Par catégorie : c'est là que l'écart se lit. « 30 % du catalogue »
    // ne dit pas quoi faire ; « aucune tarte sur douze » si.
    $parCat = [];
    foreach ($refs as $pid => $r) {
        $c = $r['categorie'] !== '' ? $r['categorie'] : '— sans catégorie';
        if (!isset($parCat[$c])) { $parCat[$c] = ['nom' => $c, 'groupe' => $r['groupe'], 'catalogue' => 0, 'reseau' => 0, 'magasins' => []]; }
        $parCat[$c]['catalogue']++;
        if (isset($vuesReseau[$pid])) { $parCat[$c]['reseau']++; }
    }
    foreach ($lignes as $l) {
        $vues = array_flip($l['vues'] ?? []);
        foreach ($parCat as $c => $x) { $parCat[$c]['magasins'][$l['id']] = 0; }
        foreach ($refs as $pid => $r) {
            if (!isset($vues[$pid])) { continue; }
            $c = $r['categorie'] !== '' ? $r['categorie'] : '— sans catégorie';
            $parCat[$c]['magasins'][$l['id']]++;
        }
    }
    // Les catégories les plus DÉLAISSÉES d'abord : celles où le réseau tient
    // beaucoup de références et où les magasins en vendent peu.
    $cats = array_values($parCat);
    foreach ($cats as $i => $c) {
        $moy = $c['catalogue'] > 0 && $c['magasins'] !== []
            ? array_sum($c['magasins']) / count($c['magasins']) / $c['catalogue'] : 0;
        $cats[$i]['tauxMoyen'] = round(100 * $moy, 1);
        $cats[$i]['manque'] = $c['catalogue'] - $c['reseau'];
    }
    usort($cats, static fn ($a, $b) => ($b['catalogue'] - $b['reseau'] * 0) <=> ($a['catalogue'])
        ?: $a['tauxMoyen'] <=> $b['tauxMoyen']);
    $out['parCategorie'] = $cats;

    foreach ($lignes as $i => $l) { unset($lignes[$i]['vues']); }
    $out['magasins'] = $lignes;
    $out['seuilManque'] = $seuil;
    $out['source'] = 'lignes de ticket de la caisse (transaction_product), '
        . mktBriefJour($du) . ' → ' . mktBriefJour($au);
    return $out;
}

/**
 * GET /produits/utilisation/magasin?shop=4[&mois=6][&groupe=][&categorie=]
 *
 * Le détail d'UN magasin, sur les TROIS niveaux de l'arbre produit : le
 * groupe, la catégorie qu'il contient, puis les références elles-mêmes. Le
 * catalogue en compte 710 réparties sur 80 catégories — une liste à plat de 80
 * lignes ne se lit pas, alors que treize groupes tiennent dans un écran et
 * disent tout de suite où le magasin décroche.
 *
 * Chaque niveau porte la même mesure : combien de références vendues sur
 * combien au catalogue. Un groupe n'est donc pas un intertitre, c'est le
 * cumul de ses catégories — ouvrir ne change jamais le chiffre du dessus.
 *
 * Pour chaque référence absente, le nombre d'autres magasins qui la vendent.
 * Une référence que personne ne vend n'est pas un manque de ce magasin :
 * c'est une question de catalogue, et les deux ne se traitent pas au même
 * endroit.
 *
 * Route séparée du tableau : le détail pèse sept cents lignes par magasin, et
 * l'écran d'entrée n'en a pas besoin pour s'afficher.
 */
function ep_prod_utilisation_magasin(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $groupe = trim((string) ($_GET['groupe'] ?? ''));
    $categorie = trim((string) ($_GET['categorie'] ?? ''));

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    if (!isset($nomDe[$shop])) {
        http_response_code(404);
        return ['error' => 'magasin inconnu'];
    }

    $mois = utilMois($n);
    $du = $mois[0]['du'];
    $au = $mois[count($mois) - 1]['au'];

    $cat = ep_prod_catalogue();
    $refs = [];
    foreach ($cat as $p) {
        $g = (string) ($p['groupe'] ?? '');
        $c = (string) ($p['categorie'] ?? '');
        if ($groupe !== '' && $g !== $groupe) { continue; }
        if ($categorie !== '' && $c !== $categorie) { continue; }
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        $refs[(int) $pid] = ['ref' => (string) $p['ref'], 'nom' => (string) $p['nom'],
            'categorie' => $c !== '' ? $c : '— sans catégorie', 'groupe' => $g,
            'prix' => $p['prix'] ?? null];
    }

    $out = ['shop' => $shop, 'nom' => $nomDe[$shop], 'mois' => $mois,
        'catalogue' => count($refs), 'groupes' => [], 'motif' => null];
    if ($refs === []) { $out['motif'] = 'aucune référence à rapprocher'; return $out; }

    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) {
        $out['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base';
        return $out;
    }

    // Qui vend quoi, et combien ce magasin en a vendu.
    $quantite = []; $vendeurs = [];
    try {
        $sql = sprintf(
            'SELECT t.`%s` AS shop, l.`%s` AS produit, SUM(l.`%s`) AS q
               FROM `transaction_product` l
               JOIN `transaction` t ON t.`%s` = l.`%s`
              WHERE t.`%s` >= ? AND t.`%s` < ?
              GROUP BY shop, produit',
            $ct['shop'], $cl['produit'], $cl['quantite'],
            $ct['id'], $cl['ticket'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']) as $r) {
            $pid = (int) $r['produit'];
            if (!isset($refs[$pid])) { continue; }
            $sid = (string) $r['shop'];
            if (!isset($nomDe[$sid])) { continue; }
            $vendeurs[$pid][$sid] = true;
            if ($sid === $shop) { $quantite[$pid] = (float) $r['q']; }
        }
    } catch (PDOException $e) {
        $out['motif'] = 'lecture des lignes de ticket impossible';
        return $out;
    }

    $parCat = [];
    foreach ($refs as $pid => $r) {
        $c = $r['categorie'];
        if (!isset($parCat[$c])) {
            $parCat[$c] = ['nom' => $c, 'groupe' => $r['groupe'],
                'catalogue' => 0, 'vendues' => [], 'nonVendues' => [], 'orphelines' => 0];
        }
        $parCat[$c]['catalogue']++;
        $ailleurs = count($vendeurs[$pid] ?? []) - (isset($quantite[$pid]) ? 1 : 0);
        if (isset($quantite[$pid])) {
            $parCat[$c]['vendues'][] = ['ref' => $r['ref'], 'nom' => $r['nom'],
                'quantite' => (int) round($quantite[$pid]), 'ailleurs' => $ailleurs];
        } else {
            $parCat[$c]['nonVendues'][] = ['ref' => $r['ref'], 'nom' => $r['nom'],
                'ailleurs' => $ailleurs];
            // Personne ne la vend : ce n'est pas un manque du magasin.
            if ($ailleurs === 0) { $parCat[$c]['orphelines']++; }
        }
    }

    $cats = [];
    foreach ($parCat as $c) {
        // Les plus vendues d'abord dans la colonne « vendues », les plus
        // répandues ailleurs en tête des absentes : c'est l'ordre dans lequel
        // on veut les lire.
        usort($c['vendues'], static fn ($a, $b) => $b['quantite'] <=> $a['quantite']);
        usort($c['nonVendues'], static fn ($a, $b) => $b['ailleurs'] <=> $a['ailleurs'] ?: strcmp($a['nom'], $b['nom']));
        $c['nVendues'] = count($c['vendues']);
        $c['nNonVendues'] = count($c['nonVendues']);
        // Le manque RÉEL : les absentes que d'autres vendent.
        $c['aRattraper'] = $c['nNonVendues'] - $c['orphelines'];
        $c['taux'] = $c['catalogue'] > 0 ? round(100 * $c['nVendues'] / $c['catalogue'], 1) : null;
        $cats[] = $c;
    }
    usort($cats, static fn ($a, $b) => $b['aRattraper'] <=> $a['aRattraper'] ?: $b['catalogue'] <=> $a['catalogue']);

    // --- Le niveau du dessus : le groupe, cumul de ses catégories.
    $parGrp = [];
    foreach ($cats as $c) {
        $g = $c['groupe'] !== '' ? $c['groupe'] : '— hors groupe';
        if (!isset($parGrp[$g])) {
            $parGrp[$g] = ['nom' => $g, 'catalogue' => 0, 'nVendues' => 0,
                'nNonVendues' => 0, 'orphelines' => 0, 'categories' => []];
        }
        $parGrp[$g]['catalogue'] += $c['catalogue'];
        $parGrp[$g]['nVendues'] += $c['nVendues'];
        $parGrp[$g]['nNonVendues'] += $c['nNonVendues'];
        $parGrp[$g]['orphelines'] += $c['orphelines'];
        $parGrp[$g]['categories'][] = $c;
    }
    $grps = [];
    foreach ($parGrp as $g) {
        $g['aRattraper'] = $g['nNonVendues'] - $g['orphelines'];
        $g['taux'] = $g['catalogue'] > 0 ? round(100 * $g['nVendues'] / $g['catalogue'], 1) : null;
        $g['nCategories'] = count($g['categories']);
        $grps[] = $g;
    }
    usort($grps, static fn ($a, $b) => $b['aRattraper'] <=> $a['aRattraper'] ?: $b['catalogue'] <=> $a['catalogue']);

    $out['groupes'] = $grps;
    $out['vendues'] = array_sum(array_column($cats, 'nVendues'));
    $out['aRattraper'] = array_sum(array_column($cats, 'aRattraper'));
    $out['orphelines'] = array_sum(array_column($cats, 'orphelines'));
    $out['taux'] = count($refs) > 0 ? round(100 * $out['vendues'] / count($refs), 1) : null;
    $out['source'] = 'lignes de ticket de la caisse, ' . mktBriefJour($du) . ' → ' . mktBriefJour($au);
    return $out;
}

/**
 * Les références que PERSONNE n'a vendues sur la période.
 *
 * L'écran en donne le compte — « 251 jamais vendues » — mais un compte ne se
 * traite pas : ce qui s'emporte en réunion catalogue, c'est la liste, groupe
 * par groupe, avec le prix de vente en face. Une référence que personne ne
 * vend est soit à relancer, soit à sortir ; dans les deux cas il faut la
 * nommer.
 *
 * @return array{lignes:list<array<string,mixed>>,catalogue:int,jamais:int,du:string,au:string,motif:?string}
 */
function utilJamaisVendues(int $n, string $groupe = ''): array
{
    $mois = utilMois($n);
    $du = $mois[0]['du'];
    $au = $mois[count($mois) - 1]['au'];
    $out = ['lignes' => [], 'catalogue' => 0, 'jamais' => 0, 'horsGroupe' => 0,
        'du' => $du, 'au' => $au, 'groupe' => $groupe, 'motif' => null];

    $refs = [];
    foreach (ep_prod_catalogue() as $p) {
        $g = (string) ($p['groupe'] ?? '');
        if ($groupe !== '' && $g !== $groupe) { continue; }
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        $refs[(int) $pid] = ['ref' => (string) $p['ref'], 'nom' => (string) $p['nom'],
            'groupe' => $g !== '' ? $g : '— hors groupe',
            'categorie' => ((string) ($p['categorie'] ?? '')) !== '' ? (string) $p['categorie'] : '— sans catégorie',
            'prix' => $p['prix'] ?? null];
    }
    $out['catalogue'] = count($refs);
    if ($refs === []) { $out['motif'] = 'aucune référence du catalogue ne porte d’identifiant de caisse'; return $out; }

    $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
    $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
    if ($ct === null || $cl === null) {
        $out['motif'] = 'la caisse n’expose pas ses lignes de ticket sur cette base';
        return $out;
    }

    // Les magasins ACTIFS seulement : une référence vendue par un magasin fermé
    // n'est plus vendue par personne, et la sortir de la liste la ferait
    // disparaître de la réunion.
    $actifs = [];
    foreach (Db::rows('SELECT id FROM shops WHERE active = 1') as $s) { $actifs[(string) $s['id']] = true; }

    $vues = [];
    try {
        $sql = sprintf(
            'SELECT DISTINCT t.`%s` AS shop, l.`%s` AS produit
               FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
              WHERE t.`%s` >= ? AND t.`%s` < ?',
            $ct['shop'], $cl['produit'], $ct['id'], $cl['ticket'], $ct['date'], $ct['date']);
        foreach (Db::rows($sql, [$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00']) as $r) {
            if (!isset($actifs[(string) $r['shop']])) { continue; }
            $vues[(int) $r['produit']] = true;
        }
    } catch (PDOException $e) {
        $out['motif'] = 'lecture des lignes de ticket impossible';
        return $out;
    }

    $par = [];
    foreach ($refs as $pid => $r) {
        if (isset($vues[$pid])) { continue; }
        $par[$r['groupe']][$r['categorie']][] = $r;
        $out['jamais']++;
    }
    ksort($par);
    foreach ($par as $g => $cats) {
        ksort($cats);
        $n2 = 0;
        $blocs = [];
        foreach ($cats as $c => $lignes) {
            usort($lignes, static fn ($a, $b) => strcmp($a['nom'], $b['nom']));
            $n2 += count($lignes);
            $blocs[] = ['nom' => $c, 'refs' => $lignes];
        }
        $out['lignes'][] = ['groupe' => $g, 'total' => $n2, 'categories' => $blocs];
    }
    // Les articles SANS GROUPE en dernier, quel que soit leur nombre : mesuré
    // en ligne, ce sont pour l'essentiel des articles d'achat — matières
    // premières, emballages — qui ne passent pas en caisse. Ils sont les plus
    // nombreux, donc ils ouvraient le document et repoussaient en page 3 les
    // vraies références à décider.
    $horsGroupe = '— hors groupe';
    usort($out['lignes'], static fn ($a, $b) =>
        ($a['groupe'] === $horsGroupe ? 1 : 0) <=> ($b['groupe'] === $horsGroupe ? 1 : 0)
        ?: $b['total'] <=> $a['total']);
    foreach ($out['lignes'] as $l) {
        if ($l['groupe'] === $horsGroupe) { $out['horsGroupe'] = $l['total']; }
    }
    return $out;
}

/** La page A4 de la liste — même grammaire typographique que les autres PDF. */
function utilJamaisPdfHtml(array $d, int $n): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = 'font-family:Helvetica,Arial,sans-serif';
    $h = '<div style="' . $F . ';color:#222">'
        . '<div style="font-size:17pt;font-weight:700;margin-bottom:2mm">Références jamais vendues</div>'
        . '<div style="font-size:9pt;color:#6b6259;line-height:1.5;margin-bottom:6mm">'
        . 'Aucun magasin du réseau n’en a vendu une seule unité entre le ' . $e(mktBriefJour($d['du']))
        . ' et le ' . $e(mktBriefJour($d['au'])) . ' (' . $n . ' mois'
        . ($d['groupe'] !== '' ? ', groupe ' . $e($d['groupe']) : '') . ').<br>'
        . '<strong>' . $d['jamais'] . ' références</strong> sur les ' . $d['catalogue']
        . ' du catalogue actif — soit '
        . number_format($d['catalogue'] > 0 ? 100 * $d['jamais'] / $d['catalogue'] : 0, 1, ',', ' ') . ' %. '
        . 'Chacune est à relancer ou à sortir : tant qu’elle reste au catalogue, elle occupe une ligne de commande, '
        . 'une fiche de production et une place au comptoir.'
        . (($d['horsGroupe'] ?? 0) > 0
            ? '<br><em>' . (int) $d['horsGroupe'] . ' d’entre elles n’ont ni groupe ni catégorie au référentiel — '
              . 'matières premières, emballages : des articles d’achat qui ne passent pas en caisse. '
              . 'Elles sont regroupées en fin de document, à vérifier avant d’en conclure quoi que ce soit.</em>'
            : '')
        . '</div>';

    if ($d['motif'] !== null) {
        return $h . '<div style="font-size:10pt;color:#8D1D2C">' . $e($d['motif']) . '</div></div>';
    }
    if ($d['lignes'] === []) {
        return $h . '<div style="font-size:10pt;color:#2d7a3e">Aucune : chaque référence du catalogue a trouvé preneur au moins une fois.</div></div>';
    }

    foreach ($d['lignes'] as $g) {
        $h .= '<div style="page-break-inside:avoid">'
            . '<div style="' . $F . ';font-size:11pt;font-weight:700;border-bottom:1pt solid #222;padding-bottom:1.5mm;margin:6mm 0 2mm">'
            . $e($g['groupe']) . ' <span style="font-weight:400;color:#6b6259">— ' . $g['total'] . ' référence'
            . ($g['total'] > 1 ? 's' : '') . '</span></div>';
        foreach ($g['categories'] as $c) {
            $h .= '<div style="font-size:8.5pt;text-transform:uppercase;letter-spacing:0.06em;color:#6b6259;margin:3mm 0 1mm">'
                . $e($c['nom']) . ' · ' . count($c['refs']) . '</div>'
                . '<table style="width:100%;border-collapse:collapse;' . $F . ';font-size:9pt">';
            foreach ($c['refs'] as $r) {
                $h .= '<tr>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #E7E0D6;width:22mm;color:#6b6259;font-size:8pt">' . $e($r['ref']) . '</td>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #E7E0D6">' . $e($r['nom']) . '</td>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #E7E0D6;text-align:right;width:20mm;color:#6b6259">'
                    . ($r['prix'] === null ? '—' : $e(number_format((float) $r['prix'], 2, ',', ' ') . ' €')) . '</td>'
                    . '</tr>';
            }
            $h .= '</table>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/**
 * GET /produits/utilisation/jamais.pdf?mois=6[&groupe=]
 *
 * Le même chiffre que la tuile « Jamais vendues », mais nommé : la liste part
 * en réunion catalogue, pas l'écran.
 */
function ep_prod_utilisation_jamais_pdf(): array
{
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $groupe = trim((string) ($_GET['groupe'] ?? ''));
    $d = utilJamaisVendues($n, $groupe);

    $pdf = rapPdfRendu(utilJamaisPdfHtml($d, $n), [
        'magasin' => 'Réseau',
        'rapport' => 'Références jamais vendues — ' . $n . ' mois',
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
    if ($pdf === null) {
        http_response_code(501);
        return ['error' => 'aucun moteur PDF sur ce serveur'];
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="references-jamais-vendues-'
        . ($groupe !== '' ? mktSlug($groupe) . '-' : '') . $n . 'mois-' . date('Y-m-d') . '.pdf"');
    echo $pdf;
    exit;
}

/**
 * POST /produits/actif — retirer (ou remettre) des références du catalogue.
 *
 * ÉCRITURE SUR UNE TABLE DU PANEL. C'est la seule du cockpit, et elle est
 * volontairement étroite : un seul champ, `product.is_active`, celui-là même
 * que le catalogue lit déjà (`WHERE is_active = 1`). Jamais de DELETE — les
 * lignes de ticket pointent sur `product.id`, et supprimer la référence
 * rendrait six mois d'historique illisibles, en changeant après coup des
 * chiffres déjà lus.
 *
 * Trois garde-fous :
 *  - une référence VENDUE sur les douze derniers mois est refusée, pas
 *    désactivée : la liste vient d'une analyse, une analyse peut se tromper de
 *    ligne, et une référence qui tourne ne se retire pas par mégarde ;
 *  - chaque référence passe au journal, nommée, avec son motif ;
 *  - le geste s'annule par le même appel (`actif: true`).
 *
 * Body : {refs: ["6700048", …], actif: false, motif: "…"}
 */
function wr_prod_actif(): array
{
    $b = body();
    $refs = $b['refs'] ?? [];
    if (!is_array($refs) || $refs === []) {
        http_response_code(422); return ['error' => 'aucune référence fournie'];
    }
    if (count($refs) > 400) {
        http_response_code(422); return ['error' => 'trop de références en une fois (400 au plus)'];
    }
    $actif = !empty($b['actif']);
    $motif = mb_substr(trim((string) ($b['motif'] ?? '')), 0, 200);

    // ref (code cockpit) → id de caisse, et le nom pour le journal.
    $parRef = [];
    foreach (ep_prod_catalogue() as $p) {
        $parRef[(string) $p['ref']] = ['pid' => $p['pwaId'] ?? null, 'nom' => (string) $p['nom']];
    }

    // Ce qui s'est vendu sur douze mois : le garde-fou.
    $vendus = [];
    if (!$actif) {
        $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
        $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
        if ($ct === null || $cl === null) {
            http_response_code(503);
            return ['error' => 'impossible de vérifier les ventes récentes : la caisse n’expose pas ses lignes de ticket. Rien n’a été modifié.'];
        }
        try {
            $sql = sprintf(
                'SELECT l.`%s` AS produit, SUM(l.`%s`) AS q
                   FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
                  WHERE t.`%s` >= ? GROUP BY produit',
                $cl['produit'], $cl['quantite'], $ct['id'], $cl['ticket'], $ct['date']);
            foreach (Db::rows($sql, [date('Y-m-d', strtotime('-12 months')) . ' 00:00:00']) as $r) {
                if ((float) $r['q'] > 0) { $vendus[(int) $r['produit']] = (float) $r['q']; }
            }
        } catch (PDOException $e) {
            http_response_code(503);
            return ['error' => 'lecture des ventes impossible — rien n’a été modifié'];
        }
    }

    $faits = []; $refuses = []; $inconnues = []; $inchangees = [];
    foreach ($refs as $r) {
        $ref = trim((string) $r);
        if ($ref === '') { continue; }
        $c = $parRef[$ref] ?? null;
        if ($c === null || $c['pid'] === null) { $inconnues[] = $ref; continue; }
        $pid = (int) $c['pid'];
        if (!$actif && isset($vendus[$pid])) {
            $refuses[] = ['ref' => $ref, 'nom' => $c['nom'], 'unites' => round($vendus[$pid], 1)];
            continue;
        }
        try {
            // `rowCount()` de l'UPDATE lui-même : MySQL rend 0 quand la valeur
            // était déjà celle-là — c'est exactement « rien à faire ici ».
            if (Db::exec('UPDATE product SET is_active = ? WHERE id = ?', [$actif ? 1 : 0, $pid]) === 0) {
                $inchangees[] = $ref; continue;
            }
        } catch (PDOException $e) {
            http_response_code(503);
            return ['error' => 'écriture refusée par la base : ' . $e->getMessage(),
                'faits' => count($faits), 'refuses' => $refuses];
        }
        journalAdd('CEO', 'Produit', $c['nom'],
            ($actif ? 'Remise au catalogue' : 'Retirée du catalogue (is_active = 0)')
            . ' — réf. ' . $ref . ($motif !== '' ? ' — ' . $motif : ''));
        $faits[] = ['ref' => $ref, 'nom' => $c['nom']];
    }

    return ['ok' => true, 'actif' => $actif, 'motif' => $motif,
        'faits' => $faits, 'refuses' => $refuses,
        'inconnues' => $inconnues, 'inchangees' => $inchangees,
        'resume' => count($faits) . ($actif ? ' remise(s) au catalogue' : ' retirée(s) du catalogue')
            . (count($refuses) ? ', ' . count($refuses) . ' refusée(s) car vendue(s) sur 12 mois' : '')
            . (count($inconnues) ? ', ' . count($inconnues) . ' référence(s) inconnue(s)' : '')
            . (count($inchangees) ? ', ' . count($inchangees) . ' déjà dans cet état' : '')];
}
