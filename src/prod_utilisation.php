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
        unset($lignes[$i]['vues']);
    }

    $out['magasins'] = $lignes;
    $out['seuilManque'] = $seuil;
    $out['source'] = 'lignes de ticket de la caisse (transaction_product), '
        . mktBriefJour($du) . ' → ' . mktBriefJour($au);
    return $out;
}
