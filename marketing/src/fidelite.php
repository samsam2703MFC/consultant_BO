<?php
declare(strict_types=1);

/**
 * Fidélité — ERP franchisé › Fidélité (adresse #/fidelity), demande du 24/10/2026.
 * Maquettes : docs/maquettes/fidelite (retenu : le Programme de C, les Clients de B).
 *
 * Deux lectures, une écriture :
 *  - GET  /fidelite          le programme : les règles (on gagne, ça compte double, on reçoit,
 *                            garde-fous), ce que chacune a donné sur 30 jours, le budget estimé,
 *                            trois clients types pour le simulateur ;
 *  - GET  /fidelite/clients  la récurrence : récence × fréquence, segments, d'un mois à l'autre,
 *                            ce qu'achète chaque segment (six types d'achat) ;
 *  - POST /fidelite/regles   publie les règles modifiées, ajoutées ou retirées, en un envoi.
 *
 * Les règles vivent ici (ceo_fid_regle) : c'est le paramétrage de la marque. La PWA du client
 * doit les lire pour les appliquer (cartes à tampons et jauge : pwa_loyalty_card / _config) —
 * ce raccord reste à faire, l'écran ne prétend pas le contraire.
 *
 * Les visites carte arrivent dans ceo_fid_visite (client, magasin, date-heure, montant, type
 * d'achat). Une base qui a des visites compte pour de vrai ; sans visite, l'analyse tourne sur
 * un JEU D'ESSAI généré (mêmes calculs) et la réponse le dit (`test`), comme la newsletter.
 */

const FID_MISSIONS = [
    ['k' => 'pain', 'nom' => 'Pain quotidien', 'def' => 'pain seul ou avec un article'],
    ['k' => 'petitdej', 'nom' => 'Petit-déjeuner', 'def' => 'viennoiserie et boisson chaude'],
    ['k' => 'lunch', 'nom' => 'Lunch', 'def' => 'sandwich, quiche ou salade, boisson'],
    ['k' => 'gouter', 'nom' => 'Goûter', 'def' => 'pâtisserie individuelle, cookies'],
    ['k' => 'occasion', 'nom' => 'Occasion', 'def' => 'tarte entière, entremets, panier de 25 € et plus'],
    ['k' => 'bureau', 'nom' => 'Commande & bureau', 'def' => 'click & collect, livraison au bureau'],
];
const FID_SEGMENTS = [
    'H' => ['Habitués', '8 visites et plus par mois, vu il y a moins de 7 jours'],
    'R' => ['Réguliers', '2 à 7 visites par mois, vu dans les 3 semaines'],
    'O' => ['Occasionnels', 'moins de 2 visites par mois, vu dans les 45 jours'],
    'N' => ['Nouveaux', '1re visite il y a moins de 30 jours'],
    'AR' => ['À risque', 'au moins 2 visites par mois, plus vu depuis 21 à 45 jours'],
    'E' => ['Endormis', 'plus vu depuis plus de 45 jours'],
];
const FID_RF_LIGNES = ['8 et +', '4 – 7', '2 – 3', '1', 'moins d’1'];
const FID_RF_COLONNES = ['< 7 j', '7 – 20 j', '21 – 45 j', '46 – 60 j', '> 60 j'];
const FID_TYPES = ['base' => 'gain', 'panier' => 'gain', 'creneau' => 'multi', 'gamme' => 'multi', 'anniversaire' => 'multi',
    'jauge' => 'recoit', 'tampons' => 'recoit', 'declencheur' => 'recoit',
    'plafond' => 'garde', 'cumul' => 'garde', 'unbon' => 'garde', 'expiration' => 'garde', 'exclus' => 'garde', 'budget' => 'garde'];
const FID_UNIQUES = ['base', 'jauge', 'plafond', 'cumul', 'unbon', 'expiration', 'exclus', 'budget'];
const FID_EVENEMENTS = ['inscription', 'visite2', 'anniversaire', 'visiteN', 'panier', 'parrainage', 'absence'];
const FID_TAUX_BON = 0.65;        // hypothèse : part des bons émis qui sont utilisés
const FID_TAUX_PRODUIT = 0.85;    // hypothèse : part des produits offerts qui sont pris
const FID_PRIX_PRODUIT = 2.5;     // hypothèse : prix de vente moyen d'un produit offert

/* ---------------------------------------------------------------------------
 * Tables
 * ------------------------------------------------------------------------- */
function ensureFidelite(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_regle ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'bloc VARCHAR(8) NOT NULL,'                    // gain | multi | recoit | garde
        . 'type VARCHAR(16) NOT NULL,'
        . 'params_json TEXT NOT NULL,'
        . 'shop_id VARCHAR(16) NOT NULL DEFAULT "",'     // "" = tout le réseau
        . 'actif TINYINT NOT NULL DEFAULT 1,'
        . 'sort_order INT NOT NULL DEFAULT 0,'
        . 'updated_at DATETIME NULL,'
        . 'updated_by VARCHAR(60) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les visites carte : le flux de la PWA (ou du panel) s'y dépose. Vide → jeu d'essai.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_visite ('
        . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'client_ref VARCHAR(40) NOT NULL,'
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'visited_at DATETIME NOT NULL,'
        . 'montant DECIMAL(10,2) NOT NULL DEFAULT 0,'
        . 'mission VARCHAR(12) NULL,'                    // pain | petitdej | lunch | gouter | occasion | bureau
        . 'KEY idx_quand (visited_at), KEY idx_client (client_ref, visited_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ((int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_regle')['n'] ?? 0) === 0) { fidSemer(); }
}

/** Le programme de départ : celui de la maquette C. Posé une fois, sur une table vide. */
function fidSemer(): void
{
    $id = [];
    foreach (fidMagasins() as $m) { $id[mb_strtolower($m['nom'])] = $m['id']; }
    $chez = static fn (string $nom): string => $id[$nom] ?? '';
    $R = [
        ['base', ['euros' => 1, 'points' => 1], '', 1],
        ['panier', ['min' => 30, 'points' => 50], '', 1],
        ['creneau', ['mult' => 2, 'jours' => [1, 2, 3], 'de' => '14:00', 'a' => '17:00', 'famille' => '', 'pourquoi' => 'jours creux : remplir l’après-midi'], '', 1],
        ['creneau', ['mult' => 2, 'jours' => [0, 1, 2, 3, 4, 5], 'de' => '17:30', 'a' => '19:00', 'famille' => 'pain', 'pourquoi' => 'écouler : moins de pain jeté'], '', 1],
        ['creneau', ['mult' => 2, 'jours' => [0], 'de' => '07:00', 'a' => '19:00', 'famille' => '', 'pourquoi' => 'le jour le plus creux'], $chez('sombreffe'), 1],
        ['gamme', ['mult' => 2, 'gamme' => 'Automnale', 'du' => '2026-10-01', 'au' => '2026-11-15'], '', 1],
        ['anniversaire', ['mult' => 3, 'jours' => 3], '', 1],
        ['creneau', ['mult' => 2, 'jours' => [5], 'de' => '07:00', 'a' => '08:00', 'famille' => '', 'pourquoi' => 'brouillon : ouvrir plus tôt ?'], $chez('corbais'), 0],
        ['jauge', ['points' => 150, 'valeur' => 5, 'validite' => 60], '', 1],
        ['tampons', ['nom' => 'Carte Pain', 'unite' => 'pains achetés', 'n' => 10, 'recompense' => '1 pain offert', 'couleur' => '#9a5b16'], '', 1],
        ['tampons', ['nom' => 'Carte Viennoiserie', 'unite' => 'viennoiseries achetées', 'n' => 10, 'recompense' => '1 croissant offert', 'couleur' => '#eb6834'], '', 1],
        ['tampons', ['nom' => 'Carte Café', 'unite' => 'cafés achetés', 'n' => 8, 'recompense' => '1 café offert', 'couleur' => '#5c4033'], '', 1],
        ['tampons', ['nom' => 'Carte Tarte', 'unite' => 'parts de tarte achetées', 'n' => 6, 'recompense' => '1 part de tarte offerte', 'couleur' => '#c2336a'], '', 1],
        ['declencheur', ['evenement' => 'inscription', 'n' => 8, 'recompense' => ['type' => 'bon', 'valeur' => 2, 'libelle' => ''], 'validite' => 30], '', 1],
        ['declencheur', ['evenement' => 'visite2', 'n' => 14, 'recompense' => ['type' => 'points', 'valeur' => 30, 'libelle' => ''], 'validite' => 0], '', 1],
        ['declencheur', ['evenement' => 'anniversaire', 'n' => 0, 'recompense' => ['type' => 'produit', 'valeur' => 0, 'libelle' => 'une viennoiserie offerte'], 'validite' => 7], '', 1],
        ['declencheur', ['evenement' => 'visiteN', 'n' => 10, 'recompense' => ['type' => 'produit', 'valeur' => 0, 'libelle' => 'une tartelette surprise'], 'validite' => 14], '', 1],
        ['declencheur', ['evenement' => 'parrainage', 'n' => 0, 'recompense' => ['type' => 'points', 'valeur' => 100, 'libelle' => ''], 'validite' => 30], '', 1],
        ['declencheur', ['evenement' => 'absence', 'n' => 45, 'recompense' => ['type' => 'bon', 'valeur' => 3, 'libelle' => ''], 'validite' => 21], '', 1],
        ['plafond', ['points' => 300], '', 1],
        ['cumul', [], '', 1],
        ['unbon', [], '', 1],
        ['expiration', ['mois' => 12, 'preavis' => 30], '', 1],
        ['exclus', ['dates' => '24/12, 31/12'], '', 1],
        ['budget', ['pct' => 5], '', 1],
    ];
    foreach ($R as $i => [$type, $p, $shop, $actif]) {
        Db::exec('INSERT INTO ceo_fid_regle (bloc, type, params_json, shop_id, actif, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,NOW(),?)',
            [FID_TYPES[$type], $type, json_encode(fidNormalise($type, $p), JSON_UNESCAPED_UNICODE), $shop, $actif, ($i + 1) * 10, 'programme de départ']);
    }
}

/** Les magasins ouverts du réseau (ceux de ep_stores). */
function fidMagasins(): array
{
    try { $shops = ep_stores(); } catch (Throwable $e) { $shops = []; }
    $out = [];
    foreach ($shops as $s) {
        if (isset($s['status']) && !preg_match('/ouvert/i', (string) $s['status'])) { continue; }
        $out[] = ['id' => (string) $s['id'], 'nom' => (string) $s['nom']];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Règles : validation
 * ------------------------------------------------------------------------- */
function fidEntier(mixed $v, int $min, int $max, int $defaut): int
{
    return is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : $defaut;
}
function fidNombre(mixed $v, float $min, float $max, float $defaut): float
{
    return is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : $defaut;
}
function fidTexte(mixed $v, int $max, string $defaut = ''): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
    return $t === '' ? $defaut : mb_substr($t, 0, $max);
}
function fidHeure(mixed $v, string $defaut): string
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) ? $v : $defaut;
}
function fidDate(mixed $v): string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : '';
}
function fidRecompense(mixed $r): array
{
    $r = is_array($r) ? $r : [];
    $t = in_array($r['type'] ?? '', ['bon', 'points', 'produit'], true) ? $r['type'] : 'bon';
    return ['type' => $t, 'valeur' => $t === 'produit' ? 0 : ($t === 'points' ? fidEntier($r['valeur'] ?? 0, 1, 1000, 30) : fidNombre($r['valeur'] ?? 0, 0.5, 50, 2)),
        'libelle' => $t === 'produit' ? fidTexte($r['libelle'] ?? '', 80, 'un produit offert') : ''];
}

/** Les paramètres d'une règle, ramenés dans leurs bornes ; null si le type est inconnu. */
function fidNormalise(string $type, array $p): ?array
{
    switch ($type) {
        case 'base': return ['euros' => fidNombre($p['euros'] ?? 1, 0.5, 20, 1), 'points' => fidEntier($p['points'] ?? 1, 1, 100, 1)];
        case 'panier': return ['min' => fidNombre($p['min'] ?? 30, 5, 500, 30), 'points' => fidEntier($p['points'] ?? 50, 1, 1000, 50)];
        case 'creneau':
            $j = array_values(array_unique(array_filter(array_map('intval', is_array($p['jours'] ?? null) ? $p['jours'] : []), fn ($x) => $x >= 0 && $x <= 6)));
            sort($j);
            $de = fidHeure($p['de'] ?? '', '14:00'); $a = fidHeure($p['a'] ?? '', '17:00');
            if ($a <= $de) { $a = $de === '23:59' ? '23:59' : sprintf('%02d:%s', min(23, (int) substr($de, 0, 2) + 1), substr($de, 3, 2)); }
            return ['mult' => fidEntier($p['mult'] ?? 2, 2, 5, 2), 'jours' => $j ?: [1, 2, 3], 'de' => $de, 'a' => $a,
                'famille' => in_array($p['famille'] ?? '', ['', 'pain', 'petitdej', 'lunch', 'gouter', 'occasion'], true) ? $p['famille'] : '',
                'pourquoi' => fidTexte($p['pourquoi'] ?? '', 120)];
        case 'gamme':
            $du = fidDate($p['du'] ?? ''); $au = fidDate($p['au'] ?? '');
            if ($du !== '' && $au !== '' && $au < $du) { [$du, $au] = [$au, $du]; }
            return ['mult' => fidEntier($p['mult'] ?? 2, 2, 5, 2), 'gamme' => fidTexte($p['gamme'] ?? '', 60, 'Nouvelle gamme'), 'du' => $du, 'au' => $au];
        case 'anniversaire': return ['mult' => fidEntier($p['mult'] ?? 3, 2, 5, 3), 'jours' => fidEntier($p['jours'] ?? 3, 0, 15, 3)];
        case 'jauge': return ['points' => fidEntier($p['points'] ?? 150, 10, 5000, 150), 'valeur' => fidNombre($p['valeur'] ?? 5, 0.5, 100, 5), 'validite' => fidEntier($p['validite'] ?? 60, 0, 365, 60)];
        case 'tampons':
            $c = is_string($p['couleur'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $p['couleur']) ? $p['couleur'] : '#8D1D2C';
            return ['nom' => fidTexte($p['nom'] ?? '', 40, 'Nouvelle carte'), 'unite' => fidTexte($p['unite'] ?? '', 40, 'articles achetés'), 'n' => fidEntier($p['n'] ?? 10, 1, 50, 10),
                'recompense' => fidTexte($p['recompense'] ?? '', 60, '1 article offert'), 'couleur' => $c];
        case 'declencheur':
            $ev = in_array($p['evenement'] ?? '', FID_EVENEMENTS, true) ? $p['evenement'] : 'inscription';
            $bornes = ['inscription' => [0, 100, 8], 'visite2' => [1, 60, 14], 'visiteN' => [2, 60, 10], 'panier' => [5, 500, 30], 'absence' => [7, 180, 45]];
            $n = isset($bornes[$ev]) ? fidEntier($p['n'] ?? null, $bornes[$ev][0], $bornes[$ev][1], $bornes[$ev][2]) : 0;
            return ['evenement' => $ev, 'n' => $n, 'recompense' => fidRecompense($p['recompense'] ?? null), 'validite' => fidEntier($p['validite'] ?? 30, 0, 365, 30)];
        case 'plafond': return ['points' => fidEntier($p['points'] ?? 300, 10, 10000, 300)];
        case 'cumul':
        case 'unbon': return [];
        case 'expiration': return ['mois' => fidEntier($p['mois'] ?? 12, 1, 60, 12), 'preavis' => fidEntier($p['preavis'] ?? 30, 0, 120, 30)];
        case 'exclus': return ['dates' => fidTexte($p['dates'] ?? '', 120, '24/12, 31/12')];
        case 'budget': return ['pct' => fidNombre($p['pct'] ?? 5, 0.5, 30, 5)];
    }
    return null;
}

function fidRegles(): array
{
    return array_map(static fn ($r) => ['id' => (int) $r['id'], 'bloc' => $r['bloc'], 'type' => $r['type'], 'params' => json_decode((string) $r['params_json'], true) ?: [],
        'shop' => (string) $r['shop_id'], 'actif' => (int) $r['actif'] === 1, 'ordre' => (int) $r['sort_order'], 'majLe' => $r['updated_at'], 'majPar' => $r['updated_by']],
        Db::rows('SELECT * FROM ceo_fid_regle ORDER BY sort_order, id'));
}

/* ---------------------------------------------------------------------------
 * Les visites : réelles (ceo_fid_visite) ou jeu d'essai
 * ------------------------------------------------------------------------- */
/** Jour (entier) d'une date Y-m-d. */
function fidJour(string $d): int { return intdiv((int) strtotime($d . ' 12:00:00 UTC'), 86400); }
/** Jour de la semaine, 0 = lundi. */
function fidDow(int $j): int { return ($j + 3) % 7; }

/** Le type d'achat d'une visite sans mission : par son montant et son heure. */
function fidMission(int $minute, float $montant): int
{
    if ($montant >= 25) { return 4; }
    if ($minute < 600) { return 1; }
    if ($minute >= 690 && $minute < 810) { return 2; }
    if ($minute >= 840 && $minute < 1020) { return 3; }
    return 0;
}

/**
 * Les clients et leurs visites des 150 derniers jours, en tableaux plats par client :
 * ['shop', 'premier' (jour de la 1re visite), 'nais' (jour de l'année ou null), 'd' => jours, 'h' => minutes, 'm' => montants, 'k' => missions].
 */
function fidVisites(int $R): array
{
    $n = (int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_visite')['n'] ?? 0);
    if ($n === 0) { return ['test' => true, 'clients' => fidEssai($R)]; }
    $du = gmdate('Y-m-d', ($R - 150) * 86400);
    $prem = [];
    foreach (Db::rows('SELECT client_ref, MIN(visited_at) p FROM ceo_fid_visite GROUP BY client_ref') as $r) { $prem[$r['client_ref']] = fidJour(substr((string) $r['p'], 0, 10)); }
    $C = []; $shops = [];
    $K = array_flip(array_column(FID_MISSIONS, 'k'));
    foreach (Db::rows('SELECT client_ref, shop_id, visited_at, montant, mission FROM ceo_fid_visite WHERE visited_at >= ? ORDER BY visited_at', [$du]) as $r) {
        $c = (string) $r['client_ref'];
        $j = fidJour(substr((string) $r['visited_at'], 0, 10));
        if ($j > $R) { continue; }
        $min = (int) substr((string) $r['visited_at'], 11, 2) * 60 + (int) substr((string) $r['visited_at'], 14, 2);
        $mt = (float) $r['montant'];
        if (!isset($C[$c])) { $C[$c] = ['shop' => '', 'premier' => $prem[$c] ?? $j, 'nais' => null, 'd' => [], 'h' => [], 'm' => [], 'k' => []]; $shops[$c] = []; }
        $C[$c]['d'][] = $j; $C[$c]['h'][] = $min; $C[$c]['m'][] = $mt;
        $C[$c]['k'][] = isset($K[(string) $r['mission']]) ? $K[(string) $r['mission']] : fidMission($min, $mt);
        $shops[$c][(string) $r['shop_id']] = ($shops[$c][(string) $r['shop_id']] ?? 0) + 1;
    }
    foreach ($C as $c => &$x) { arsort($shops[$c]); $x['shop'] = (string) array_key_first($shops[$c]); }
    unset($x);
    return ['test' => false, 'clients' => array_values($C)];
}

/** Tirage pondéré : l'indice d'un poids. */
function fidTire(array $poids): int
{
    $t = array_sum($poids); $u = mt_rand() / mt_getrandmax() * $t; $acc = 0;
    foreach ($poids as $i => $p) { $acc += $p; if ($u <= $acc) { return $i; } }
    return count($poids) - 1;
}

/**
 * Le jeu d'essai : 7 700 clients sur les magasins ouverts, six comportements (habitués,
 * réguliers, occasionnels, réguliers qui décrochent, endormis, nouveaux), 150 jours de
 * visites. Tirage fixe : le même jeu à chaque appel du même jour.
 */
function fidEssai(int $R): array
{
    $mags = fidMagasins();
    $ids = $mags ? array_column($mags, 'id') : ['1'];
    $poidsMag = array_slice([45, 24, 20, 11, 8, 6, 5, 4], 0, count($ids)) + array_fill(0, count($ids), 4);
    $PAN = [5.6, 7.4, 10.4, 9.1, 34.9, 62.0];
    $HEURE = [[9, 12, 8, 4, 3, 4, 3, 2, 2, 3, 9, 13, 8], [16, 24, 18, 9, 3, 1, 0, 0, 0, 0, 0, 0, 0], [0, 0, 1, 4, 16, 30, 22, 5, 1, 0, 0, 0, 0],
        [0, 0, 1, 2, 2, 3, 5, 12, 16, 18, 13, 6, 2], [2, 8, 16, 18, 12, 6, 4, 4, 5, 6, 8, 6, 3], [3, 10, 14, 10, 12, 18, 8, 4, 3, 4, 6, 5, 3]];
    $MIX = ['H' => [52, 26, 10, 5, 2, 5], 'R' => [30, 24, 22, 13, 5, 6], 'O' => [12, 14, 16, 22, 28, 8], 'L' => [18, 14, 40, 14, 6, 8]];
    // [comportement, nombre, visites par jour, mélange]
    $TYPES = [['H', 380, 0.37, 'H'], ['R', 1650, 0.12, 'R'], ['O', 1250, 0.03, 'O'], ['X', 1500, 0.12, 'L'], ['E', 2000, 0.0, 'O'], ['N', 900, 0.0, 'R']];
    mt_srand(20261024);
    $C = [];
    $D0 = $R - 150;
    foreach ($TYPES as [$t, $nb, $taux, $mix]) {
        for ($i = 0; $i < $nb; $i++) {
            $c = ['shop' => $ids[fidTire($poidsMag)], 'premier' => $D0 - mt_rand(1, 400), 'nais' => mt_rand(0, 364), 'd' => [], 'h' => [], 'm' => [], 'k' => []];
            $debut = $D0; $fin = $R; $tx = $taux; $mixC = $MIX[$mix];
            if ($t === 'X') { $fin = $R - mt_rand(0, 140); }                       // décroche un jour des 140 derniers
            if ($t === 'E') { $debut = $D0; $fin = $R - mt_rand(50, 150); $tx = 0.04; }
            if ($t === 'N') {
                $c['premier'] = $D0 + mt_rand(0, 150); $debut = $c['premier'];
                $u = mt_rand(0, 99);
                $tx = $u < 45 ? 0.12 : ($u < 75 ? 0.03 : 0.0);
                fidAjoute($c, $debut, $mixC, $PAN, $HEURE);
            }
            if ($tx > 0) {
                $j = $debut;
                while (true) {
                    $j += max(1, (int) round(-log(max(1e-9, mt_rand(1, mt_getrandmax()) / mt_getrandmax())) / $tx));
                    if ($j > $fin) { break; }
                    fidAjoute($c, $j, $mixC, $PAN, $HEURE);
                }
            }
            if ($t === 'E' && !$c['d']) { fidAjoute($c, $fin, $mixC, $PAN, $HEURE); }
            if ($c['d']) { $C[] = $c; }
        }
    }
    mt_srand();
    return $C;
}
function fidAjoute(array &$c, int $j, array $mix, array $PAN, array $HEURE): void
{
    $k = fidTire($mix);
    $h = 7 + fidTire($HEURE[$k]);
    if (fidDow($j) === 6 && $h >= 13) { $h = 8 + mt_rand(0, 4); }       // le dimanche ferme à 13 h
    $c['d'][] = $j; $c['h'][] = $h * 60 + mt_rand(0, 59);
    $c['m'][] = round($PAN[$k] * (0.7 + 0.6 * mt_rand() / mt_getrandmax()), 2); $c['k'][] = $k;
}

/* ---------------------------------------------------------------------------
 * L'analyse
 * ------------------------------------------------------------------------- */
/** Ligne (visites par mois) et colonne (jours depuis la dernière visite) de la grille. */
function fidCase(float $f, int $rec): array
{
    $l = $f >= 8 ? 0 : ($f >= 4 ? 1 : ($f >= 2 ? 2 : ($f >= 1 ? 3 : 4)));
    $c = $rec < 7 ? 0 : ($rec <= 20 ? 1 : ($rec <= 45 ? 2 : ($rec <= 60 ? 3 : 4)));
    return [$l, $c];
}
function fidSegmentDe(int $l, int $c): string
{
    if ($c >= 3) { return 'E'; }
    if ($l === 0 && $c === 0) { return 'H'; }
    if ($l <= 2 && $c === 2) { return 'AR'; }
    return $l <= 2 ? 'R' : 'O';
}
/** L'état d'un client à la date $R : null s'il n'était pas encore client. */
function fidEtat(array $c, int $R): ?array
{
    $der = null; $n90 = 0;
    foreach ($c['d'] as $j) {
        if ($j > $R) { break; }
        $der = $j;
        if ($j > $R - 90) { $n90++; }
    }
    if ($der === null) { return null; }
    $f = $n90 / 3; $rec = $R - $der;
    [$l, $col] = fidCase($f, $rec);
    $seg = $R - $c['premier'] < 30 ? 'N' : fidSegmentDe($l, $col);
    return ['seg' => $seg, 'l' => $l, 'c' => $col, 'f' => $f, 'rec' => $rec];
}

/** Part de réguliers qui reviennent d'eux-mêmes après N jours d'absence. */
function fidRetourSpontane(array $C, int $R, array $seuils): array
{
    $vus = array_fill_keys($seuils, 0); $revenus = array_fill_keys($seuils, 0);
    foreach ($C as $c) {
        $d = $c['d']; $n = count($d);
        for ($i = 0; $i < $n; $i++) {
            if ($d[$i] > $R - 60) { break; }             // on laisse 60 jours pour voir s'il revient
            $avant = 0;
            for ($k = $i; $k >= 0 && $d[$k] > $d[$i] - 90; $k--) { $avant++; }
            if ($avant < 6) { continue; }                // un régulier : 2 visites par mois et plus
            $suiv = $i + 1 < $n ? $d[$i + 1] : null;
            $gap = $suiv === null ? $R - $d[$i] : $suiv - $d[$i];
            foreach ($seuils as $s) {
                if ($gap > $s) { $vus[$s]++; if ($suiv !== null) { $revenus[$s]++; } }
            }
        }
    }
    $out = [];
    foreach ($seuils as $s) { $out[$s] = $vus[$s] ? (int) round(100 * $revenus[$s] / $vus[$s]) : null; }
    return $out;
}

/** GET /fidelite/clients — la récurrence et le type d'achat. */
function ep_fidelite_clients(): array
{
    ensureFidelite();
    [$R, $refIso] = fidReference();
    $shop = fidTexte($_GET['shop'] ?? '', 16);
    $V = fidVisites($R);
    $C = $shop === '' ? $V['clients'] : array_values(array_filter($V['clients'], static fn ($c) => $c['shop'] === $shop));
    $Rp = $R - 30;
    $grille = array_fill(0, 5, array_fill(0, 5, 0));
    $ordre = ['H', 'R', 'O', 'AR', 'E'];
    $trans = array_fill(0, 5, array_fill(0, 5, 0));
    $seg = []; foreach (array_keys(FID_SEGMENTS) as $s) { $seg[$s] = ['n' => 0, 'ca30' => 0.0, 'tickets90' => 0, 'ca90' => 0.0, 'f' => 0.0, 'mix' => array_fill(0, 6, 0), 'rec' => 0, 'shops' => [], 'caAvant' => 0.0]; }
    $mixT = array_fill(0, 6, 0); $ca30 = 0.0; $tk30 = 0; $actifs = 0; $actifsP = 0; $nouvP = 0; $nouvPRev = 0;
    foreach ($C as $c) {
        $e = fidEtat($c, $R);
        if ($e === null) { continue; }
        $s = $e['seg'];
        if ($s !== 'N') { $grille[$e['l']][$e['c']]++; }
        $S = &$seg[$s];
        $S['n']++; $S['f'] += $e['f']; $S['rec'] += $e['rec']; $S['shops'][$c['shop']] = ($S['shops'][$c['shop']] ?? 0) + 1;
        $act = false; $actP = false;
        foreach ($c['d'] as $i => $j) {
            if ($j > $R) { break; }
            if ($j > $R - 30) { $S['ca30'] += $c['m'][$i]; $ca30 += $c['m'][$i]; $tk30++; $act = true; }
            if ($j > $Rp - 30 && $j <= $Rp) { $actP = true; }
            if ($j > $R - 90) { $S['tickets90']++; $S['ca90'] += $c['m'][$i]; $S['mix'][$c['k'][$i]]++; $mixT[$c['k'][$i]]++; }
            if ($j > $R - $e['rec'] - 90 && $j <= $R - $e['rec']) { $S['caAvant'] += $c['m'][$i]; }   // les 90 jours qui précèdent la dernière visite
        }
        unset($S);
        if ($act) { $actifs++; }
        if ($actP) { $actifsP++; }
        // Retour à 30 jours : les nouveaux du mois d'avant, revenus dans les 30 jours de leur 1re visite.
        if ($c['premier'] > $Rp - 30 && $c['premier'] <= $Rp) {
            $nouvP++;
            foreach ($c['d'] as $j) { if ($j > $c['premier'] && $j <= $c['premier'] + 30) { $nouvPRev++; break; } }
        }
        // D'un mois à l'autre : l'état 30 jours plus tôt, hors nouveaux.
        $p = fidEtat($c, $Rp);
        if ($p !== null && $p['seg'] !== 'N' && $s !== 'N') { $trans[array_search($p['seg'], $ordre, true)][array_search($s, $ordre, true)]++; }
    }
    $montes = 0; $desc = 0;
    foreach ($trans as $i => $r) { foreach ($r as $j => $v) { if ($j < $i) { $montes += $v; } elseif ($j > $i) { $desc += $v; } } }
    $totMix = array_sum($mixT) ?: 1;
    $segments = [];
    foreach (FID_SEGMENTS as $id => [$nom, $def]) {
        $S = $seg[$id]; $n = $S['n'];
        arsort($S['shops']);
        $top = $S['shops'] ? (string) array_key_first($S['shops']) : '';
        $tm = array_sum($S['mix']) ?: 1;
        $segments[] = ['id' => $id, 'nom' => $nom, 'def' => $def, 'n' => $n,
            'partCa' => $ca30 > 0 ? round(100 * $S['ca30'] / $ca30, 1) : 0, 'ca30' => round($S['ca30']),
            'panier' => $S['tickets90'] ? round($S['ca90'] / $S['tickets90'], 2) : null,
            'visitesMois' => $n ? round($S['f'] / $n, 1) : null, 'joursSans' => $n ? (int) round($S['rec'] / $n) : null,
            'caAvantMois' => round($S['caAvant'] / 3), 'mix' => array_map(static fn ($v) => round(100 * $v / $tm, 1), $S['mix']),
            'topShop' => $top !== '' ? ['id' => $top, 'n' => $S['shops'][$top]] : null];
    }
    return [
        'test' => $V['test'], 'source' => $V['test'] ? 'jeu d’essai' : 'ceo_fid_visite', 'ref' => $refIso, 'shop' => $shop,
        'magasins' => fidMagasins(), 'missions' => FID_MISSIONS,
        'kpis' => ['actifs' => $actifs, 'actifsPrec' => $actifsP, 'retour30' => $nouvP ? (int) round(100 * $nouvPRev / $nouvP) : null, 'nouveauxPrec' => $nouvP,
            'montes' => $montes, 'descendus' => $desc, 'ca30' => round($ca30), 'tickets30' => $tk30, 'panier' => $tk30 ? round($ca30 / $tk30, 2) : null],
        'rf' => ['lignes' => FID_RF_LIGNES, 'colonnes' => FID_RF_COLONNES, 'cases' => $grille,
            'segments' => array_map(static fn ($l) => array_map(static fn ($c) => fidSegmentDe($l, $c), range(0, 4)), range(0, 4))],
        'segments' => $segments, 'mixMoyen' => array_map(static fn ($v) => round(100 * $v / $totMix, 1), $mixT),
        'transitions' => ['ordre' => $ordre, 'noms' => array_map(static fn ($s) => FID_SEGMENTS[$s][0], $ordre), 'm' => $trans],
        'retourSpontane' => fidRetourSpontane($C, $R, [7, 14, 21, 30, 45, 60]),
    ];
}

/** La date de lecture : aujourd'hui, ou la fin du mois demandé s'il est passé. */
function fidReference(): array
{
    $auj = date('Y-m-d');
    $m = (string) ($_GET['mois'] ?? '');
    $ref = preg_match('/^\d{4}-\d{2}$/', $m) ? min($auj, date('Y-m-t', (int) strtotime($m . '-01'))) : $auj;
    return [fidJour($ref), $ref];
}

/* ---------------------------------------------------------------------------
 * Le programme : règles, ce qu'elles ont donné, budget
 * ------------------------------------------------------------------------- */
function fidMinute(string $hhmm): int { return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2); }
function fidN(float $n, int $d = 0): string { return number_format($n, $d, ',', ' '); }

/** Une visite tombe-t-elle dans le créneau ? */
function fidDansCreneau(array $p, int $j, int $min, int $k): bool
{
    if (!in_array(fidDow($j), $p['jours'], true)) { return false; }
    if ($min < fidMinute($p['de']) || $min >= fidMinute($p['a'])) { return false; }
    if ($p['famille'] !== '') { return FID_MISSIONS[$k]['k'] === $p['famille']; }
    return true;
}

/** Ce que chaque règle a donné sur les 30 derniers jours, et le budget estimé. */
function fidStats(array $regles, array $V, int $R, string $shop): array
{
    $C = $shop === '' ? $V['clients'] : array_values(array_filter($V['clients'], static fn ($c) => $c['shop'] === $shop));
    $byType = []; foreach ($regles as $r) { if ($r['actif']) { $byType[$r['type']][] = $r; } }
    $base = $byType['base'][0]['params'] ?? ['euros' => 1, 'points' => 1];
    $ratio = $base['points'] / max(0.5, $base['euros']);
    $jauge = $byType['jauge'][0]['params'] ?? ['points' => 150, 'valeur' => 5];
    $ca = 0.0; $tk = 0; $nouv = 0; $out = []; $cpt = []; $bonus = [];
    foreach ($regles as $r) { $cpt[$r['id']] = 0; $bonus[$r['id']] = 0.0; }
    $applique = static fn (array $r, array $c): bool => $r['shop'] === '' || $r['shop'] === $c['shop'];
    foreach ($C as $c) {
        if ($c['premier'] > $R - 30 && $c['premier'] <= $R) { $nouv++; }
        $n30 = 0;
        foreach ($c['d'] as $i => $j) {
            if ($j > $R) { break; }
            if ($j <= $R - 30) { continue; }
            $m = $c['m'][$i]; $ca += $m; $tk++; $n30++;
            $best = 1;
            foreach ($regles as $r) {
                if (!$applique($r, $c)) { continue; }
                $p = $r['params'];
                if ($r['type'] === 'panier' && $m >= $p['min']) { $cpt[$r['id']]++; if ($r['actif']) { $bonus[$r['id']] += $p['points']; } }
                if ($r['type'] === 'creneau' && fidDansCreneau($p, $j, $c['h'][$i], $c['k'][$i])) { $cpt[$r['id']]++; if ($r['actif']) { $best = max($best, $p['mult']); $bonus[$r['id']] += $m * $ratio * ($p['mult'] - 1); } }
                if ($r['type'] === 'anniversaire' && $c['nais'] !== null && abs(((int) gmdate('z', $j * 86400)) - $c['nais']) <= $p['jours']) { $cpt[$r['id']]++; if ($r['actif']) { $bonus[$r['id']] += $m * $ratio * ($p['mult'] - 1); } }
            }
        }
        foreach ($regles as $r) {
            if ($r['type'] !== 'declencheur' || !$applique($r, $c)) { continue; }
            $p = $r['params'];
            if ($p['evenement'] === 'visiteN' && $n30 >= $p['n']) { $cpt[$r['id']]++; }
            if ($p['evenement'] === 'inscription' && $c['premier'] > $R - 30 && $c['premier'] <= $R) { $cpt[$r['id']]++; }
            if ($p['evenement'] === 'anniversaire' && $c['nais'] !== null && $n30 > 0) {
                $z = (int) gmdate('z', $R * 86400); $ecart = ($z - $c['nais'] + 365) % 365;
                if ($ecart < 30) { $cpt[$r['id']]++; }
            }
            if ($p['evenement'] === 'visite2' && $c['premier'] > $R - 30 - $p['n'] && $c['premier'] <= $R - $p['n']) {
                $bonus[$r['id']] += 1;                  // nouveaux observables
                foreach ($c['d'] as $j) { if ($j > $c['premier'] && $j <= $c['premier'] + $p['n']) { $cpt[$r['id']]++; break; } }
            }
            if ($p['evenement'] === 'absence') {
                // Les réguliers qui franchissent le seuil d'absence dans les 30 jours.
                $der = null; $avant = 0;
                foreach ($c['d'] as $j) { if ($j > $R) { break; } $der = $j; }
                if ($der !== null && $der + $p['n'] > $R - 30 && $der + $p['n'] <= $R) {
                    foreach ($c['d'] as $j) { if ($j <= $der && $j > $der - 90) { $avant++; } }
                    if ($avant >= 6) { $cpt[$r['id']]++; }
                }
            }
        }
    }
    $pointsBase = $ca * $ratio;
    $pointsBonus = 0.0;
    foreach ($regles as $r) { if ($r['actif'] && in_array($r['type'], ['panier', 'creneau', 'anniversaire'], true)) { $pointsBonus += $bonus[$r['id']]; } }
    $pointsTot = $pointsBase + $pointsBonus;
    $bonsJauge = $pointsTot / max(1, $jauge['points']);
    $cout = ['Bons de la jauge' => $bonsJauge * $jauge['valeur'] * FID_TAUX_BON];
    foreach ($regles as $r) {
        $p = $r['params']; $id = $r['id']; $n = $cpt[$id];
        $txt = ''; $cls = '';
        switch ($r['type']) {
            case 'base': $txt = fidN($pointsBase) . ' points en 30 jours'; break;
            case 'panier': $txt = fidN($n) . ' paniers · ' . fidN($n * $p['points']) . ' points'; break;
            case 'creneau': $txt = fidN($n) . ' tickets' . ($r['actif'] ? ' · + ' . fidN($bonus[$id]) . ' points' : ''); break;
            case 'anniversaire': $txt = fidN($n) . ' achats' . ($r['actif'] ? ' · + ' . fidN($bonus[$id]) . ' points' : ''); break;
            case 'gamme': $txt = 'ventes par gamme à brancher'; break;
            case 'jauge': $txt = 'environ ' . fidN($bonsJauge) . ' bons sur 30 jours'; break;
            case 'tampons': $txt = 'tampons tenus par la PWA'; break;
            case 'declencheur':
                $ev = $p['evenement'];
                if ($ev === 'visite2') {
                    $obs = (int) $bonus[$id];
                    $txt = $obs ? fidN($n) . ' sur ' . fidN($obs) . ' nouveaux (' . round(100 * $n / $obs) . ' %)' : 'aucun nouveau à lire';
                } elseif ($ev === 'parrainage' || $ev === 'panier') {
                    $txt = $ev === 'parrainage' ? 'suivi des parrainages à brancher' : '';
                } else {
                    $txt = fidN($n) . ' ' . ['inscription' => 'inscrits', 'anniversaire' => 'anniversaires', 'visiteN' => 'clients l’atteignent', 'absence' => 'réguliers passent le seuil'][$ev] . ' sur 30 jours';
                }
                if ($r['actif']) {
                    $rc = $p['recompense'];
                    if ($rc['type'] === 'bon') { $cout[$r['id'] . '|' . $ev] = $n * $rc['valeur'] * FID_TAUX_BON; }
                    if ($rc['type'] === 'produit') { $cout[$r['id'] . '|' . $ev] = $n * FID_PRIX_PRODUIT * FID_TAUX_PRODUIT; }
                }
                if ($ev === 'absence') { $cls = 'alerte'; }
                break;
        }
        $out[$id] = ['txt' => $txt, 'n' => $n, 'cls' => $cls];
    }
    $total = array_sum($cout);
    $budgetPct = (float) ($byType['budget'][0]['params']['pct'] ?? 5);
    return ['regles' => $out, 'budget' => ['cout' => round($total), 'ca' => round($ca), 'pct' => $ca > 0 ? round(100 * $total / $ca, 2) : null, 'plafond' => $budgetPct,
        'tickets' => $tk, 'points' => round($pointsTot), 'nouveaux' => $nouv,
        'hypotheses' => 'bons utilisés à ' . round(100 * FID_TAUX_BON) . ' %, produits offerts pris à ' . round(100 * FID_TAUX_PRODUIT) . ' % et comptés ' . fidN(FID_PRIX_PRODUIT, 2) . ' € pièce']];
}

/** Trois clients types pour le simulateur : la médiane des habitués, des réguliers, des occasionnels. */
function fidProfils(array $C, int $R, array $regles): array
{
    $grp = ['H' => [], 'R' => [], 'O' => []];
    foreach ($C as $c) {
        $e = fidEtat($c, $R);
        if ($e === null || !isset($grp[$e['seg']])) { continue; }
        $n = 0; $ca = 0.0; $mix = array_fill(0, 6, 0); $dans = 0;
        foreach ($c['d'] as $i => $j) {
            if ($j > $R) { break; }
            if ($j <= $R - 30) { continue; }
            $n++; $ca += $c['m'][$i]; $mix[$c['k'][$i]]++;
            foreach ($regles as $r) { if ($r['actif'] && $r['type'] === 'creneau' && ($r['shop'] === '' || $r['shop'] === $c['shop']) && fidDansCreneau($r['params'], $j, $c['h'][$i], $c['k'][$i])) { $dans++; break; } }
        }
        if ($n) { $grp[$e['seg']][] = [$n, $ca / $n, array_search(max($mix), $mix, true), $dans / $n]; }
    }
    $out = [];
    foreach (['H' => 'Habitué', 'R' => 'Régulier', 'O' => 'Occasionnel'] as $s => $nom) {
        $L = $grp[$s];
        if (!$L) { continue; }
        usort($L, static fn ($a, $b) => $a[0] <=> $b[0]);
        $m = $L[intdiv(count($L), 2)];
        $mis = array_count_values(array_column($L, 2)); arsort($mis);
        $out[] = ['nom' => $nom, 'mission' => FID_MISSIONS[(int) array_key_first($mis)]['nom'], 'visites' => $m[0], 'panier' => round(array_sum(array_column($L, 1)) / count($L), 2),
            'partCreneau' => round(array_sum(array_column($L, 3)) / count($L), 3)];
    }
    return $out;
}

/** GET /fidelite — le programme. */
function ep_fidelite(): array
{
    ensureFidelite();
    [$R, $refIso] = fidReference();
    $shop = fidTexte($_GET['shop'] ?? '', 16);
    $regles = fidRegles();
    $V = fidVisites($R);
    $vis = $shop === '' ? $regles : array_values(array_filter($regles, static fn ($r) => $r['shop'] === '' || $r['shop'] === $shop));
    $C = $shop === '' ? $V['clients'] : array_values(array_filter($V['clients'], static fn ($c) => $c['shop'] === $shop));
    return ['test' => $V['test'], 'source' => $V['test'] ? 'jeu d’essai' : 'ceo_fid_visite', 'ref' => $refIso, 'shop' => $shop,
        'magasins' => fidMagasins(), 'missions' => FID_MISSIONS, 'regles' => $regles,
        'stats' => fidStats($vis, $V, $R, $shop), 'profils' => fidProfils($C, $R, $vis),
        'maintenant' => ['jour' => (int) date('N') - 1, 'heure' => date('H:i')]];
}

/* ---------------------------------------------------------------------------
 * Écriture : publier les règles
 * ------------------------------------------------------------------------- */
/**
 * POST /fidelite/regles — { regles: [{ id?, type, params, shop, actif, supprimer? }] }.
 * Tout passe ou rien : une règle invalide refuse l'envoi entier.
 */
function wr_fidelite_regles_post(): array
{
    ensureFidelite();
    $b = body();
    $L = is_array($b['regles'] ?? null) ? $b['regles'] : [];
    if (!$L) { http_response_code(422); return ['error' => 'aucune règle à publier']; }
    $shops = array_column(fidMagasins(), 'nom', 'id');
    $qui = fidTexte($b['qui'] ?? 'Cockpit', 60, 'Cockpit');
    $ops = [];
    foreach ($L as $x) {
        if (!is_array($x)) { continue; }
        $id = (int) ($x['id'] ?? 0);
        $cur = $id ? Db::row('SELECT * FROM ceo_fid_regle WHERE id = ?', [$id]) : null;
        if ($id && $cur === null) { http_response_code(404); return ['error' => 'règle ' . $id . ' introuvable']; }
        $type = $cur ? (string) $cur['type'] : (string) ($x['type'] ?? '');
        if (!isset(FID_TYPES[$type])) { http_response_code(422); return ['error' => 'type de règle inconnu : ' . $type]; }
        if (!empty($x['supprimer'])) {
            if (!$cur) { continue; }
            if (in_array($type, FID_UNIQUES, true)) { http_response_code(422); return ['error' => 'cette règle ne se retire pas : désactivez-la']; }
            $ops[] = ['del', $id, $type];
            continue;
        }
        if (!$cur && in_array($type, FID_UNIQUES, true)) { http_response_code(422); return ['error' => 'une seule règle « ' . $type . ' » par programme']; }
        $p = fidNormalise($type, is_array($x['params'] ?? null) ? $x['params'] : (json_decode((string) ($cur['params_json'] ?? '{}'), true) ?: []));
        $shop = fidTexte($x['shop'] ?? ($cur['shop_id'] ?? ''), 16);
        if ($shop !== '' && !isset($shops[$shop])) { http_response_code(422); return ['error' => 'magasin inconnu : ' . $shop]; }
        if ($type === 'base' && $shop !== '') { $shop = ''; }
        $actif = array_key_exists('actif', $x) ? (!empty($x['actif']) ? 1 : 0) : (int) ($cur['actif'] ?? 1);
        $ops[] = [$cur ? 'upd' : 'ins', $id, $type, $p, $shop, $actif];
    }
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $ordre = (int) (Db::row('SELECT COALESCE(MAX(sort_order), 0) m FROM ceo_fid_regle')['m'] ?? 0);
        foreach ($ops as $o) {
            if ($o[0] === 'del') { Db::exec('DELETE FROM ceo_fid_regle WHERE id = ?', [$o[1]]); continue; }
            $json = json_encode($o[3], JSON_UNESCAPED_UNICODE);
            if ($o[0] === 'upd') {
                Db::exec('UPDATE ceo_fid_regle SET params_json = ?, shop_id = ?, actif = ?, updated_at = NOW(), updated_by = ? WHERE id = ?', [$json, $o[4], $o[5], $qui, $o[1]]);
            } else {
                $ordre += 10;
                Db::exec('INSERT INTO ceo_fid_regle (bloc, type, params_json, shop_id, actif, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,NOW(),?)',
                    [FID_TYPES[$o[2]], $o[2], $json, $o[4], $o[5], $ordre, $qui]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $n = count($ops);
    journalAdd($qui, 'Fidélité', null, 'Règles du programme publiées : ' . $n . ' modification' . ($n > 1 ? 's' : ''));
    return ['ok' => true, 'n' => $n, 'regles' => fidRegles()];
}
