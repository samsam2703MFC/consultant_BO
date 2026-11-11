<?php
declare(strict_types=1);

require_once __DIR__ . '/doc.php';

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

/**
 * Les gammes saisonnières, par référence de caisse.
 *
 * Le catalogue n'est pas une liste plate : 441 références portent une gamme —
 * Noël, Pascale, Estivale, Saint-Nicolas. Une bûche non vendue entre mars et
 * août n'est pas une bûche délaissée : sa gamme n'était pas ouverte. Les
 * compter avec les autres fait deux dégâts d'un coup — le taux d'usage du
 * magasin baisse sans qu'il y soit pour rien, et la liste des « jamais
 * vendues » se remplit de références qu'il était normal de ne pas vendre.
 *
 * Les gammes sont RÉCURRENTES (mesuré : les 14 le sont) : l'année portée par
 * `start_date` ne veut rien dire, seuls comptent le jour et le mois. On
 * travaille donc en MOIS, ce qui suffit à décider et se raconte en une phrase.
 *
 * @return array{parProduit:array<int,list<string>>,mois:array<string,list<int>>}
 */
function utilGammes(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = ['parProduit' => [], 'mois' => []];

    $periodes = [];
    try {
        foreach (Db::rows('SELECT id, name, start_date, end_date
                             FROM product_availability_period WHERE is_active = 1') as $p) {
            $du = (string) ($p['start_date'] ?? '');
            $au = (string) ($p['end_date'] ?? '');
            $nom = (string) $p['name'];
            $mois = [];
            $t1 = $du !== '' ? strtotime($du) : false;
            $t2 = $au !== '' ? strtotime($au) : false;
            if ($t1 === false || $t2 === false || $t2 - $t1 >= 364 * 86400) {
                // Une gamme qui couvre l'année (la « Gamme Standard » va du
                // 1er janvier au 31 décembre) n'est pas une saison : elle est
                // ouverte tout le temps.
                $mois = range(1, 12);
            } else {
                $m1 = (int) date('n', $t1); $m2 = (int) date('n', $t2);
                $m = $m1;
                for ($i = 0; $i < 12; $i++) {
                    $mois[] = $m;
                    if ($m === $m2) { break; }
                    $m = $m === 12 ? 1 : $m + 1;   // les gammes passent l'hiver
                }
            }
            $periodes[(int) $p['id']] = ['nom' => $nom, 'mois' => $mois];
            $cache['mois'][$nom] = $mois;
        }
        foreach (Db::rows('SELECT id_product, id_period FROM product_availability_period_connection') as $k) {
            $pid = (int) $k['id_product'];
            $per = $periodes[(int) $k['id_period']] ?? null;
            if ($per === null) { continue; }
            $cache['parProduit'][$pid][] = $per['nom'];
        }
    } catch (PDOException $e) { /* pas de gammes lisibles : tout est permanent */ }
    return $cache;
}

/** Les mois (1..12) que couvre la fenêtre d'analyse. */
function utilMoisCouverts(array $mois): array
{
    $out = [];
    foreach ($mois as $m) { $out[(int) substr($m['cle'], 5, 2)] = true; }
    return array_keys($out);
}

/**
 * Cette référence pouvait-elle se vendre sur la fenêtre lue ?
 *
 * Sans gamme, elle est permanente : oui. Avec des gammes, il suffit qu'une
 * seule ouvre sur un des mois lus — une référence peut appartenir à la gamme
 * standard ET à une gamme de fête.
 */
function utilOuverteSur(array $gammesDuProduit, array $moisGamme, array $moisFenetre): bool
{
    if ($gammesDuProduit === []) { return true; }
    foreach ($gammesDuProduit as $nom) {
        $m = $moisGamme[$nom] ?? null;
        if ($m === null) { return true; }   // gamme illisible : on n'écarte pas
        if (array_intersect($m, $moisFenetre) !== []) { return true; }
    }
    return false;
}

/**
 * Retire de $refs ce qu'une gamme fermée rendait invendable sur la fenêtre.
 *
 * Passe par référence : les trois lectures — le tableau, le détail d'un
 * magasin, la liste des jamais vendues — doivent écarter EXACTEMENT le même
 * ensemble, sinon trois écrans annoncent trois catalogues.
 *
 * @param array<int,array<string,mixed>> $refs
 * @return array{n:int,gammes:array<string,int>}
 */
function utilEcarterHorsGamme(array &$refs, array $mois, bool $tout): array
{
    if ($tout) { return ['n' => 0, 'gammes' => []]; }
    $g = utilGammes();
    if ($g['parProduit'] === []) { return ['n' => 0, 'gammes' => []]; }
    $fenetre = utilMoisCouverts($mois);
    $n = 0; $par = [];
    foreach ($refs as $pid => $r) {
        $sien = $g['parProduit'][$pid] ?? [];
        if (utilOuverteSur($sien, $g['mois'], $fenetre)) { continue; }
        foreach ($sien as $nom) { $par[$nom] = ($par[$nom] ?? 0) + 1; }
        unset($refs[$pid]);
        $n++;
    }
    arsort($par);
    return ['n' => $n, 'gammes' => $par];
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

    // Les gammes saisonnières fermées sur la fenêtre : écartées du
    // dénominateur, jamais du décompte — l'écran dit combien et lesquelles.
    $gamme = trim((string) ($_GET['gamme'] ?? ''));
    $ecartees = utilEcarterHorsGamme($refs, $mois, $gamme === 'tout');

    $out = [
        'mois' => $mois, 'catalogue' => count($refs),
        'gamme' => $gamme === 'tout' ? 'tout' : 'ouverte',
        'horsGamme' => $ecartees['n'], 'gammesEcartees' => $ecartees['gammes'],
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

    // Même écart qu'au tableau : sinon le détail d'un magasin annoncerait un
    // catalogue plus large que la ligne d'où on vient de cliquer.
    $ecartees = utilEcarterHorsGamme($refs, $mois, trim((string) ($_GET['gamme'] ?? '')) === 'tout');

    $out = ['shop' => $shop, 'nom' => $nomDe[$shop], 'mois' => $mois,
        'catalogue' => count($refs), 'horsGamme' => $ecartees['n'],
        'groupes' => [], 'motif' => null];
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
    $ecartees = utilEcarterHorsGamme($refs, $mois, false);
    $out['catalogue'] = count($refs);
    $out['horsGamme'] = $ecartees['n'];
    $out['gammesEcartees'] = $ecartees['gammes'];
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
    $F = 'font-family:Gotham,Helvetica,Arial,sans-serif';
    // En-tête du gabarit A4 (design system §13.4, version A).
    $h = '<div style="' . $F . ';color:#1c1a17">'
        . docEntete('Références jamais vendues', 'Réseau · ' . mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']))
        . '<div style="font-size:9pt;color:#7a736a;line-height:1.5;margin-bottom:6mm">'
        . 'Aucun magasin du réseau n’en a vendu une seule unité entre le ' . $e(mktBriefJour($d['du']))
        . ' et le ' . $e(mktBriefJour($d['au'])) . ' (' . $n . ' mois'
        . ($d['groupe'] !== '' ? ', groupe ' . $e($d['groupe']) : '') . ').<br>'
        . '<strong>' . $d['jamais'] . ' références</strong> sur les ' . $d['catalogue']
        . ' du catalogue actif — soit '
        . number_format($d['catalogue'] > 0 ? 100 * $d['jamais'] / $d['catalogue'] : 0, 1, ',', ' ') . ' %. '
        . 'Chacune est à relancer ou à sortir : tant qu’elle reste au catalogue, elle occupe une ligne de commande, '
        . 'une fiche de production et une place au comptoir.'
        . (($d['horsGamme'] ?? 0) > 0
            ? '<br>' . (int) $d['horsGamme'] . ' références de gammes saisonnières fermées sur cette fenêtre '
              . 'en sont écartées (' . $e(implode(', ', array_slice(array_keys($d['gammesEcartees'] ?? []), 0, 5)))
              . ') : ne pas les avoir vendues n’est pas un manque, leur saison n’était pas ouverte.'
            : '')
        . (($d['horsGroupe'] ?? 0) > 0
            ? '<br><em>' . (int) $d['horsGroupe'] . ' d’entre elles n’ont ni groupe ni catégorie au référentiel — '
              . 'matières premières, emballages : des articles d’achat qui ne passent pas en caisse. '
              . 'Elles sont regroupées en fin de document, à vérifier avant d’en conclure quoi que ce soit.</em>'
            : '')
        . '</div>';

    if ($d['motif'] !== null) {
        return $h . '<div style="font-size:10pt;color:#b3261e">' . $e($d['motif']) . '</div></div>';
    }
    if ($d['lignes'] === []) {
        return $h . '<div style="font-size:10pt;color:#3f7a52">Aucune : chaque référence du catalogue a trouvé preneur au moins une fois.</div></div>';
    }

    foreach ($d['lignes'] as $g) {
        $h .= '<div style="page-break-inside:avoid">'
            . '<div style="' . $F . ';font-size:11pt;font-weight:400;border-bottom:1pt solid #1c1a17;padding-bottom:1.5mm;margin:6mm 0 2mm">'
            . $e($g['groupe']) . ' <span style="font-weight:400;color:#7a736a">— ' . $g['total'] . ' référence'
            . ($g['total'] > 1 ? 's' : '') . '</span></div>';
        foreach ($g['categories'] as $c) {
            $h .= '<div style="font-size:8.5pt;text-transform:uppercase;letter-spacing:0.06em;color:#7a736a;margin:3mm 0 1mm">'
                . $e($c['nom']) . ' · ' . count($c['refs']) . '</div>'
                . '<table style="width:100%;border-collapse:collapse;' . $F . ';font-size:9pt">';
            foreach ($c['refs'] as $r) {
                $h .= '<tr>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #e6e0d8;width:22mm;color:#7a736a;font-size:8pt">' . $e($r['ref']) . '</td>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #e6e0d8">' . $e($r['nom']) . '</td>'
                    . '<td style="padding:1.2mm 0;border-bottom:0.5pt solid #e6e0d8;text-align:right;width:20mm;color:#7a736a">'
                    . ($r['prix'] === null ? '' : $e(number_format((float) $r['prix'], 2, ',', ' ') . ' €')) . '</td>'
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

    // Document complet, pour la même raison que l'analyse magasin : un
    // fragment fait naître une page fantôme en fin de fichier.
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Références jamais vendues</title></head><body>'
        . utilJamaisPdfHtml($d, $n) . '</body></html>';
    $pdf = rapPdfRendu($doc, [
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
 * Les références vendues entre deux dates, d'après le PANEL : la ligne
 * `sold_qty` de /shops/{id}/products/waste, magasin par magasin. Sert de
 * garde-fou depuis le gel de la caisse locale (14/07/2026) — sans lui, une
 * référence vendue depuis juillet passait pour dormante.
 *
 * `sold_qty` a été mesuré RÉSEAU (la même valeur dans chaque magasin) : peu
 * importe ici, on ne retient que « vendue quelque part ». Tous les magasins
 * actifs doivent répondre : une vérification partielle n'en est pas une.
 *
 * @return array{ok: bool, vendus: array<int, float>, motif: ?string}
 */
function prodVendusPanel(string $du, string $au): array
{
    try { $shops = array_map(static fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'vendus' => [], 'motif' => 'liste des magasins illisible']; }
    if ($shops === []) { return ['ok' => false, 'vendus' => [], 'motif' => 'aucun magasin actif']; }
    $q = http_build_query(['from' => $du, 'date_from' => $du, 'to' => $au, 'date_to' => $au]);
    $chemins = [];
    foreach ($shops as $sid) { $chemins[$sid] = '/shops/' . $sid . '/products/waste?' . $q; }
    $vendus = []; $muets = [];
    // Trois de front : douze appels concurrents sur cette route en laissaient neuf sans réponse.
    foreach (PanelApi::getParallele($chemins, 3) as $sid => $r) {
        if (!is_array($r)) { $muets[] = (int) $sid; continue; }
        $lignes = isset($r['products']) && is_array($r['products']) ? $r['products'] : PanelApi::liste($r);
        foreach ($lignes as $l) {
            $pid = (int) ($l['id_product'] ?? $l['product_id'] ?? 0);
            $qv = (float) ($l['sold_qty'] ?? 0);
            if ($pid > 0 && $qv > 0) { $vendus[$pid] = max($vendus[$pid] ?? 0.0, $qv); }
        }
    }
    $muets = array_values(array_unique($muets));
    if ($muets !== []) {
        return ['ok' => false, 'vendus' => $vendus,
            'motif' => 'ventes illisibles au panel pour ' . count($muets) . ' magasin(s) (#' . implode(', #', $muets) . ')'];
    }
    return ['ok' => true, 'vendus' => $vendus, 'motif' => null];
}

/**
 * Retirer ou remettre UNE référence au catalogue TFBuddy :
 * PATCH /products/{id} { is_active }. Le compte admin de l'ERP d'abord (le
 * catalogue est l'affaire du franchiseur), le compte consultant ensuite.
 *
 * Le contrat de la route n'est pas documenté au swagger (09/11/2026). D'où
 * une écriture encadrée : la fiche est LUE avant (état et nom), le corps ne
 * porte QUE `is_active`, et la fiche est RELUE après. Le geste ne compte que
 * si la relecture montre le nouvel état ; un nom qui aurait changé entre les
 * deux lectures est signalé en clair — c'est le signe d'une route qui
 * remplace la fiche au lieu de la compléter.
 *
 * @return array{ok: bool, deja?: bool, canal?: string, motif?: string, alerte?: string}
 */
function prodActifTfb(int $pid, bool $actif): array
{
    $canaux = [];
    if (ErpApi::disponible()) { $canaux['ERP admin'] = 'erp'; }
    if (PanelApi::configured()) { $canaux['panel consultant'] = 'panel'; }
    $motifs = [];
    foreach ($canaux as $nom => $c) {
        $lire = static fn () => $c === 'erp' ? ErpApi::get('/products/' . $pid) : PanelApi::get('/products/' . $pid);
        $avant = $lire();
        if (!is_array($avant)) { $motifs[] = $nom . ' : fiche illisible (' . (($c === 'erp' ? ErpApi::$lastError : PanelApi::$lastError) ?? 'sans réponse') . ')'; continue; }
        $avant = $avant['product'] ?? $avant;
        if (array_key_exists('is_active', $avant) && (bool) (int) $avant['is_active'] === $actif) {
            return ['ok' => true, 'deja' => true, 'canal' => $nom];
        }
        $corps = ['is_active' => $actif ? 1 : 0];
        if ($c === 'erp') {
            $w = ErpApi::ecrire('PATCH', '/products/' . $pid, $corps);
            $ok = $w['erreur'] === null; $err = $w['erreur'];
        } else {
            [$ok] = PanelApi::envoi('PATCH', '/products/' . $pid, $corps);
            $err = PanelApi::$lastError;
        }
        if (!$ok) { $motifs[] = $nom . ' : ' . ($err ?? 'écriture refusée'); continue; }
        $apres = $lire();
        $apres = is_array($apres) ? ($apres['product'] ?? $apres) : null;
        if (!is_array($apres) || !array_key_exists('is_active', $apres)) {
            return ['ok' => false, 'canal' => $nom, 'motif' => 'écriture acceptée, mais la relecture ne dit pas l’état — à vérifier dans TFBuddy'];
        }
        if ((bool) (int) $apres['is_active'] !== $actif) {
            return ['ok' => false, 'canal' => $nom, 'motif' => 'écriture acceptée, mais la fiche relue garde l’ancien état'];
        }
        $alerte = null;
        if (isset($avant['name']) && (string) ($apres['name'] ?? '') !== (string) $avant['name']) {
            $alerte = 'le nom de la fiche a changé pendant l’écriture (« ' . $avant['name'] . ' » → « ' . ($apres['name'] ?? '') . ' ») : vérifier la fiche dans TFBuddy';
        }
        return ['ok' => true, 'canal' => $nom] + ($alerte !== null ? ['alerte' => $alerte] : []);
    }
    return ['ok' => false, 'motif' => $motifs !== [] ? implode(' ; ', $motifs) : 'aucun compte API configuré'];
}

/**
 * Activer ou retirer UNE référence : le seul geste du cockpit sur la fiche produit, un booléen, jamais
 * une suppression. Avec un compte API, la source est TFBuddy (prodActifTfb : écrit, relit, vérifie) et la
 * copie locale suit ; sans compte, la copie seule. Partagé par « Retirer du catalogue » (POST
 * /produits/actif) et la structure de gamme (POST /produits/structure/desactiver).
 *
 * @return array{ok:bool, deja?:bool, ou?:string, motif?:string, alerte?:?string, erreurBase?:string}
 */
function prodActifEcrire(int $pid, bool $actif, bool $api): array
{
    if ($api) {
        $tfb = prodActifTfb($pid, $actif);
        if (!$tfb['ok']) { return ['ok' => false, 'motif' => $tfb['motif'] ?? 'refusé']; }
        // La copie suit la source ; elle peut manquer, la source a déjà la main.
        try { Db::exec('UPDATE product SET is_active = ? WHERE id = ?', [$actif ? 1 : 0, $pid]); } catch (PDOException $e) { /* sans copie */ }
        return ['ok' => true, 'deja' => !empty($tfb['deja']), 'ou' => 'TFBuddy (' . $tfb['canal'] . ')', 'alerte' => $tfb['alerte'] ?? null];
    }
    try {
        // `rowCount()` de l'UPDATE lui-même : MySQL rend 0 quand la valeur
        // était déjà celle-là — c'est exactement « rien à faire ici ».
        $n = Db::exec('UPDATE product SET is_active = ? WHERE id = ?', [$actif ? 1 : 0, $pid]);
    } catch (PDOException $e) {
        return ['ok' => false, 'erreurBase' => $e->getMessage()];
    }
    return ['ok' => true, 'deja' => $n === 0, 'ou' => 'copie locale seulement (aucun compte API)'];
}

/**
 * POST /produits/actif — retirer (ou remettre) des références du catalogue.
 *
 * LE CATALOGUE VIT CHEZ TFBUDDY. L'écriture part donc à la source
 * (`prodActifTfb()`, PATCH /products/{id}) ; la copie locale (`product`) ne
 * suit qu'une fois la source relue. Auparavant le cockpit n'écrivait que dans
 * sa copie : le catalogue TFBuddy ne bougeait pas. Sans aucun compte API
 * (installation autonome), la copie reste la seule table, et c'est elle qu'on
 * écrit — en le disant.
 *
 * Un seul champ, `is_active`. Jamais de DELETE — les lignes de ticket
 * pointent sur `product.id`, et supprimer la référence rendrait l'historique
 * illisible.
 *
 * Trois garde-fous :
 *  - une référence VENDUE sur les douze derniers mois est refusée, pas
 *    désactivée. Les ventes se lisent dans la copie de la caisse jusqu'à son
 *    gel, puis au PANEL depuis le gel (`prodVendusPanel()`) : sans ce second
 *    temps, une référence vendue depuis juillet passait pour dormante. Si le
 *    panel ne répond pas pour tous les magasins, rien n'est modifié ;
 *  - chaque référence passe au journal, nommée, avec son motif et l'endroit
 *    où elle a été écrite ;
 *  - le geste s'annule par le même appel (`actif: true`).
 *
 * Avec un compte API, 40 références au plus par appel : chacune coûte trois
 * appels (lecture, écriture, relecture).
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
    $api = ErpApi::disponible() || PanelApi::configured();
    $plafond = $api ? 40 : 400;
    if (count($refs) > $plafond) {
        http_response_code(422); return ['error' => 'trop de références en une fois (' . $plafond . ' au plus)'];
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
        $douze = date('Y-m-d', strtotime('-12 months'));
        // 1) la copie de la caisse, jusqu'à son gel
        $copieLue = false; $gel = null;
        $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
        $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
        if ($ct !== null && $cl !== null) {
            try {
                $sql = sprintf(
                    'SELECT l.`%s` AS produit, SUM(l.`%s`) AS q
                       FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
                      WHERE t.`%s` >= ? GROUP BY produit',
                    $cl['produit'], $cl['quantite'], $ct['id'], $cl['ticket'], $ct['date']);
                foreach (Db::rows($sql, [$douze . ' 00:00:00']) as $r) {
                    if ((float) $r['q'] > 0) { $vendus[(int) $r['produit']] = (float) $r['q']; }
                }
                $gel = Db::rows(sprintf('SELECT MAX(`%s`) AS d FROM `transaction`', $ct['date']))[0]['d'] ?? null;
                $copieLue = true;
            } catch (PDOException $e) { /* copie illisible : le panel seul, s'il est là */ }
        }
        // 2) le panel, depuis le gel (ou sur douze mois sans copie)
        if (PanelApi::configured()) {
            $du = $gel !== null ? max($douze, substr((string) $gel, 0, 10)) : $douze;
            $pv = prodVendusPanel($du, date('Y-m-d'));
            if (!$pv['ok']) {
                http_response_code(503);
                return ['error' => 'impossible de vérifier les ventes depuis le ' . date('d/m/Y', strtotime($du))
                    . ' : ' . $pv['motif'] . '. Rien n’a été modifié.'];
            }
            foreach ($pv['vendus'] as $pid => $q) { $vendus[$pid] = max($vendus[$pid] ?? 0.0, $q); }
        } elseif ($api) {
            // Le catalogue sera écrit chez TFBuddy, mais les ventes depuis le
            // gel de la caisse locale ne se lisent qu'au panel : la copie seule
            // laisserait passer une référence vendue depuis juillet.
            http_response_code(503);
            return ['error' => 'les ventes récentes se lisent au panel, dont le compte consultant n’est pas configuré. Rien n’a été modifié.'];
        } elseif (!$copieLue) {
            http_response_code(503);
            return ['error' => 'impossible de vérifier les ventes récentes : ni la caisse locale ni le panel ne répondent. Rien n’a été modifié.'];
        }
    }

    $faits = []; $refuses = []; $inconnues = []; $inchangees = []; $echecs = []; $alertes = [];
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
        // Le même chemin pour tout retrait : TFBuddy (ERP puis panel) relu après écriture, puis la copie locale ;
        // sans compte API, la copie seule (prodActifEcrire).
        $e = prodActifEcrire($pid, $actif, $api);
        if (isset($e['erreurBase'])) {
            http_response_code(503);
            return ['error' => 'écriture refusée par la base : ' . $e['erreurBase'],
                'faits' => count($faits), 'refuses' => $refuses];
        }
        if (!$e['ok']) { $echecs[] = ['ref' => $ref, 'nom' => $c['nom'], 'motif' => $e['motif'] ?? 'refusé']; continue; }
        if (!empty($e['alerte'])) { $alertes[] = ['ref' => $ref, 'nom' => $c['nom'], 'alerte' => $e['alerte']]; }
        if (!empty($e['deja'])) { $inchangees[] = $ref; continue; }
        $ou = $e['ou'];
        journalAdd('CEO', 'Produit', $c['nom'],
            ($actif ? 'Remise au catalogue' : 'Retirée du catalogue (is_active = 0)')
            . ' — réf. ' . $ref . ' — ' . $ou . ($motif !== '' ? ' — ' . $motif : ''));
        $faits[] = ['ref' => $ref, 'nom' => $c['nom'], 'ou' => $ou];
    }

    return ['ok' => true, 'actif' => $actif, 'motif' => $motif,
        'faits' => $faits, 'refuses' => $refuses,
        'inconnues' => $inconnues, 'inchangees' => $inchangees,
        'echecs' => $echecs, 'alertes' => $alertes,
        'resume' => count($faits) . ($actif ? ' remise(s) au catalogue' : ' retirée(s) du catalogue')
            . ($api ? ' TFBuddy' : ' (copie locale : aucun compte API)')
            . (count($refuses) ? ', ' . count($refuses) . ' refusée(s) car vendue(s) sur 12 mois' : '')
            . (count($echecs) ? ', ' . count($echecs) . ' refusée(s) par TFBuddy' : '')
            . (count($inconnues) ? ', ' . count($inconnues) . ' référence(s) inconnue(s)' : '')
            . (count($inchangees) ? ', ' . count($inchangees) . ' déjà dans cet état' : '')
            . (count($alertes) ? ' — ' . count($alertes) . ' alerte(s) à vérifier' : '')];
}

/**
 * GET /produits/utilisation/jamais?mois=6[&groupe=]
 *
 * La même liste que le PDF, en JSON. Elle sert à AGIR dessus — retirer un
 * bloc du catalogue — sans recopier des codes à la main : une liste retapée
 * depuis un PDF est une liste où il manque une ligne.
 */
function ep_prod_utilisation_jamais(): array
{
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 1 || $n > 12) { $n = 6; }
    $d = utilJamaisVendues($n, trim((string) ($_GET['groupe'] ?? '')));
    // Les codes à plat, groupe par groupe : c'est ce qu'on passe ensuite à
    // /produits/actif.
    foreach ($d['lignes'] as $i => $g) {
        $codes = [];
        foreach ($g['categories'] as $c) {
            foreach ($c['refs'] as $r) { $codes[] = $r['ref']; }
        }
        $d['lignes'][$i]['refs'] = $codes;
    }
    $d['mois'] = $n;
    return $d;
}
