<?php
declare(strict_types=1);

/**
 * Fidélité › Profils clients (12/11/2026, Sam ; maquettes docs/maquettes/fidelite-v3).
 *
 * Chaque client particulier reconnu en caisse (carte scannée ou commande : le ticket porte `id_client`) se range dans
 * un profil selon le RYTHME de ses passages : 3 fois par semaine (Fanbase), 2 fois par semaine (Fidèle), 1 fois par
 * semaine (Hebdo), 2 à 3 fois par mois (Régulier), 1 fois par mois (Occasionnel), moins (Découverte).
 *
 *  - Les conditions se règlent au cockpit, pour tout le réseau (ERP franchisé › Fidélité › Profils clients) :
 *      GET  /fidelite/profils            les profils, la fenêtre, et l'aperçu d'un magasin (?shop=) ou du réseau ;
 *      POST /fidelite/profils            publie les profils modifiés, ajoutés ou retirés, et la fenêtre.
 *  - Le dashboard du magasin les lit :
 *      GET  /fidelite/profils/magasin?shop=   les profils du magasin, leurs radars, les clients qui reviennent ;
 *      GET  /fidelite/client?shop=&client=    l'historique d'un client.
 *
 * Comment on compte (réglable) : le rythme se lit sur les FENETRE derniers jours (30), ramené à un mois de 30 jours ;
 * les clients comptés sont ceux passés au moins une fois sur les MEMOIRE derniers jours (90) — un client de la mémoire
 * qui n'est pas revenu dans la fenêtre tombe dans le profil de base. Un passage = un jour avec au moins un ticket.
 * « 1 fois par semaine » vaut 4 passages par mois. Les tickets pro (`is_client_b2b`) ne comptent pas.
 *
 * Les données viennent de l'API du panel, jamais de la copie locale de sa base : les listes de tickets du jour
 * (`/shops/{id}/transactions?date=`) et les lignes d'un ticket (`/transactions/{id}?include=products`). Une journée
 * close ne change plus : ses tickets identifiés se gardent dans ceo_fid_ticket (identifiants, montant, familles —
 * aucun nom) pour ne pas relire 90 listes à chaque ouverture. Le jour en cours et la veille se relisent toujours.
 * Les noms des clients se lisent à la volée sur leur fiche (`/shops/{id}/clients/{id}`) et ne sont pas gardés.
 */

const FP_FAMILLES = ['pain' => 'Pain', 'vienno' => 'Viennoiserie', 'antigaspi' => 'Anti-gaspi', 'tartes' => 'Tartes & fêtes', 'gouter' => 'Goûter', 'sale' => 'Salé'];
const FP_AXES = ['frequence' => 'Fréquence', 'recence' => 'Récence', 'anciennete' => 'Ancienneté', 'panier' => 'Panier', 'diversite' => 'Diversité', 'comptoir' => 'Comptoir'];
const FP_DEFAUTS = [
    ['Fanbase', 3, 'semaine', '#8D1D2C'], ['Fidèle', 2, 'semaine', '#B03048'], ['Hebdo', 1, 'semaine', '#B0792A'],
    ['Régulier', 2, 'mois', '#3f7a52'], ['Occasionnel', 1, 'mois', '#1B6FA8'], ['Découverte', 0, 'base', '#9a948b'],
];
const FP_LECTURES_MAX = 200;      // listes de tickets lues au plus par requête
const FP_DETAILS_MAX = 160;       // tickets dont on lit les lignes au plus par requête
const FP_PANIER_100 = 60.0;       // € par ticket qui valent 100 sur l'axe « Panier »

/* ---------------------------------------------------------------------------
 * Tables et réglages
 * ------------------------------------------------------------------------- */
function fpSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_profil ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'nom VARCHAR(40) NOT NULL,'
        . 'n TINYINT UNSIGNED NOT NULL DEFAULT 0,'          // passages par unité
        . 'unite VARCHAR(8) NOT NULL,'                       // semaine | mois | base (le profil du bas, sans condition)
        . 'couleur CHAR(7) NOT NULL DEFAULT "#9a948b",'
        . 'actif TINYINT NOT NULL DEFAULT 1,'
        . 'updated_at DATETIME NULL, updated_by VARCHAR(60) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Le cache des journées closes : les tickets particuliers identifiés, sans nom.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_jour ('
        . 'shop_id INT NOT NULL, jour DATE NOT NULL, tickets INT NOT NULL DEFAULT 0, identifies INT NOT NULL DEFAULT 0,'
        . 'lu_le DATETIME NOT NULL, PRIMARY KEY (shop_id, jour)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_fid_ticket ('
        . 'id BIGINT NOT NULL PRIMARY KEY,'                  // l'identifiant du ticket au panel
        . 'shop_id INT NOT NULL, jour DATE NOT NULL, client_id BIGINT NOT NULL,'
        . 'quand DATETIME NOT NULL, montant DECIMAL(10,2) NOT NULL DEFAULT 0, commande TINYINT NOT NULL DEFAULT 0,'
        . 'familles VARCHAR(255) NULL,'                      // {"pain":2,…} une fois les lignes lues
        . 'KEY k_shop_jour (shop_id, jour), KEY k_client (shop_id, client_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ((int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_profil')['n'] ?? 0) === 0) {
        foreach (FP_DEFAUTS as [$nom, $n, $u, $c]) {
            Db::exec('INSERT INTO ceo_fid_profil (nom, n, unite, couleur, actif, updated_at, updated_by) VALUES (?,?,?,?,1,NOW(),?)', [$nom, $n, $u, $c, 'profils de départ']);
        }
    }
    $fait = true;
}

/** La fenêtre du rythme et la mémoire des clients, en jours. */
function fpReglages(): array
{
    $r = setting('fidProfilsReglages', []);
    $r = is_array($r) ? $r : [];
    return ['fenetre' => max(7, min(120, (int) ($r['fenetre'] ?? 30))), 'memoire' => max(30, min(365, (int) ($r['memoire'] ?? 90)))];
}

/** Le seuil d'un profil en passages par mois de 30 jours : « 1 fois par semaine » = 4. */
function fpSeuil(array $p): int
{
    return $p['unite'] === 'semaine' ? 4 * (int) $p['n'] : ($p['unite'] === 'mois' ? (int) $p['n'] : 0);
}

/** Les profils, du plus fidèle au profil de base ; chacun avec sa condition en clair et son équivalent mensuel. */
function fpProfils(bool $actifsSeuls = false): array
{
    fpSchema();
    $L = [];
    foreach (Db::rows('SELECT * FROM ceo_fid_profil') as $r) {
        $p = ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'n' => (int) $r['n'], 'unite' => (string) $r['unite'], 'couleur' => (string) $r['couleur'],
            'actif' => (int) $r['actif'] === 1 || $r['unite'] === 'base', 'majLe' => $r['updated_at'], 'majPar' => $r['updated_by']];
        $p['seuil'] = fpSeuil($p);
        if ($actifsSeuls && !$p['actif']) { continue; }
        $L[] = $p;
    }
    usort($L, static fn ($a, $b) => [$b['seuil'], $a['id']] <=> [$a['seuil'], $b['id']]);
    $actifs = array_values(array_filter($L, static fn ($p) => $p['actif']));
    foreach ($L as &$p) {
        // La borne haute : le seuil du profil actif juste au-dessus.
        $haut = null;
        foreach ($actifs as $q) { if ($q['seuil'] > $p['seuil']) { $haut = $q['seuil']; } }
        if ($p['unite'] === 'base') {
            $bas = min(array_map(static fn ($q) => $q['seuil'], array_filter($actifs, static fn ($q) => $q['unite'] !== 'base')) ?: [1]);
            $p['condition'] = 'moins d’' . ($bas === 1 ? '1 fois par mois' : $bas . ' passages par mois');
            $p['equivalent'] = $bas === 1 ? 'moins d’1 passage par mois' : 'moins de ' . $bas . ' passages par mois';
        } elseif ($p['unite'] === 'semaine') {
            $p['condition'] = $p['n'] . ' fois par semaine' . ($haut === null ? ' ou plus' : '');
            $p['equivalent'] = $haut === null ? $p['seuil'] . ' passages par mois ou plus' : ($haut - 1 > $p['seuil'] ? $p['seuil'] . ' à ' . ($haut - 1) : $p['seuil']) . ' passages par mois';
        } else {
            $p['condition'] = ($haut !== null && $haut - 1 > $p['n'] ? $p['n'] . ' à ' . ($haut - 1) : $p['n']) . ' fois par mois' . ($haut === null ? ' ou plus' : '');
            $p['equivalent'] = ($haut !== null && $haut - 1 > $p['seuil'] ? $p['seuil'] . ' à ' . ($haut - 1) . ' passages' : $p['seuil'] . ' passage' . ($p['seuil'] > 1 ? 's' : '')) . ' par mois';
        }
    }
    unset($p);
    return $L;
}

/* ---------------------------------------------------------------------------
 * Les tickets identifiés, par l'API
 * ------------------------------------------------------------------------- */
/** La famille d'achat d'un article, par le libellé « catégorie » du catalogue. */
function fpFamilleDe(string $lib): string
{
    if (preg_match('/réduction/iu', $lib)) { return 'antigaspi'; }
    if (preg_match('/quiche|soupe|boisson|sandwich|traiteur|salade/iu', $lib)) { return 'sale'; }
    if (preg_match('/viennoiserie/iu', $lib)) { return 'vienno'; }
    if (preg_match('/pâtisserie individuelle|cookie|cake|individuel|brownie|brook|merveilleux/iu', $lib)) { return 'gouter'; }
    if (preg_match('/pain|boulangerie|pistolet|baguette/iu', $lib)) { return 'pain'; }
    return 'tartes';
}

/** Produit → famille, par le catalogue de l'API (/products, /product-categories), gardé une journée. */
function fpCatalogue(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $m = setting('fidProfilsCatalogue', null);
    if (is_array($m) && ($m['le'] ?? 0) > time() - 86400 && is_array($m['v'] ?? null)) { return $memo = $m['v']; }
    $nomCat = [];
    $rc = PanelApi::sondeGet('/product-categories', 30);
    foreach (PanelApi::liste(is_array($rc['corps']) ? $rc['corps'] : []) as $c) { $nomCat[(int) ($c['id'] ?? 0)] = (string) ($c['name'] ?? ''); }
    $rp = PanelApi::sondeGet('/products', 60);
    $v = [];
    foreach (PanelApi::liste(is_array($rp['corps']) ? $rp['corps'] : []) as $p) {
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) { continue; }
        $lib = (string) ($p['category_name'] ?? ($nomCat[(int) ($p['id_category'] ?? 0)] ?? '')) . ' ' . (string) ($p['name'] ?? '');
        $v[$id] = fpFamilleDe($lib);
    }
    if ($v !== []) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['fidProfilsCatalogue', json_encode(['le' => time(), 'v' => $v])]);
    }
    return $memo = $v;
}

/** Les familles d'un ticket lu en détail : {famille: articles}, et ses articles (nom × quantité) pour une fiche. */
function fpLignes(mixed $corps): array
{
    $d = is_array($corps) && isset($corps['data']) && is_array($corps['data']) && !array_is_list($corps['data']) ? $corps['data'] : $corps;
    $cat = fpCatalogue();
    $f = []; $art = [];
    foreach ((array) (is_array($d) ? ($d['products'] ?? []) : []) as $p) {
        if (!is_array($p)) { continue; }
        $q = max(1, (float) ($p['quantity'] ?? 1));
        $nom = trim((string) ($p['product_display_name'] ?? $p['product_name'] ?? ''));
        $fam = $cat[(int) ($p['id_product'] ?? 0)] ?? fpFamilleDe($nom);
        $f[$fam] = ($f[$fam] ?? 0) + $q;
        $art[] = ['nom' => $nom, 'q' => round($q, 1)];
    }
    return [$f, $art];
}

/**
 * Relit les journées manquantes de [$du, $au] pour un magasin : les tickets particuliers identifiés vont dans
 * ceo_fid_ticket. Une journée lue après sa fin est close et ne se relit plus ; le jour en cours et la veille, oui.
 * Rend [journées lues, journées encore à lire].
 */
function fpLireJours(int $sid, string $du, string $au): array
{
    fpSchema();
    $auj = date('Y-m-d');
    if ($au > $auj) { $au = $auj; }
    $lus = [];
    foreach (Db::rows('SELECT jour, lu_le FROM ceo_fid_jour WHERE shop_id = ? AND jour BETWEEN ? AND ?', [$sid, $du, $au]) as $r) {
        $lus[$r['jour']] = substr((string) $r['lu_le'], 0, 10) > $r['jour'];
    }
    $chemins = [];
    for ($j = $au; $j >= $du; $j = date('Y-m-d', strtotime($j . ' -1 day'))) {
        if (!empty($lus[$j])) { continue; }
        $chemins[$j] = '/shops/' . $sid . '/transactions?date=' . $j;
    }
    $reste = max(0, count($chemins) - FP_LECTURES_MAX);
    $chemins = array_slice($chemins, 0, FP_LECTURES_MAX, true);
    if ($chemins === [] || !PanelApi::configured()) { return [0, $reste + count($chemins)]; }
    $res = PanelApi::getParalleleDetail($chemins, 4, 40);
    $n = 0;
    foreach ($chemins as $j => $ch) {
        $r = $res[$j] ?? null;
        if (!is_array($r) || (int) ($r['code'] ?? 0) < 200 || (int) ($r['code'] ?? 0) >= 300 || !is_array($r['data'] ?? null)) { $reste++; continue; }
        $tk = 0; $id = 0;
        $garder = [];
        foreach (analyseListe($r['data']) as $t) {
            if (!is_array($t)) { continue; }
            $tk++;
            $cli = (int) ($t['id_client'] ?? 0);
            if (!empty($t['is_client_b2b']) || $cli <= 0 || (int) ($t['id'] ?? 0) <= 0) { continue; }
            $id++;
            $garder[(int) $t['id']] = $t;
        }
        // Les tickets déjà gardés gardent leurs familles ; les nouveaux s'ajoutent.
        $deja = array_column(Db::rows('SELECT id FROM ceo_fid_ticket WHERE shop_id = ? AND jour = ?', [$sid, $j]), 'id', 'id');
        foreach ($garder as $tid => $t) {
            if (isset($deja[$tid])) { continue; }
            $cmd = strtolower((string) ($t['source'] ?? '')) === 'webshop' || (int) ($t['order_ref'] ?? 0) > 0 || trim((string) ($t['po_hash'] ?? '')) !== '';
            Db::exec('INSERT IGNORE INTO ceo_fid_ticket (id, shop_id, jour, client_id, quand, montant, commande) VALUES (?,?,?,?,?,?,?)',
                [$tid, $sid, $j, (int) $t['id_client'], substr((string) ($t['insert_timestamp'] ?? $j . ' 12:00:00'), 0, 19),
                    round((float) ($t['total_gross_amount_after_discount'] ?? $t['total_value_after_discount'] ?? 0), 2), $cmd ? 1 : 0]);
        }
        // Un ticket annulé ou corrigé disparaît de la liste : il disparaît aussi d'ici.
        foreach ($deja as $tid) { if (!isset($garder[(int) $tid])) { Db::exec('DELETE FROM ceo_fid_ticket WHERE id = ?', [(int) $tid]); } }
        Db::exec('INSERT INTO ceo_fid_jour (shop_id, jour, tickets, identifies, lu_le) VALUES (?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE tickets = VALUES(tickets), identifies = VALUES(identifies), lu_le = VALUES(lu_le)', [$sid, $j, $tk, $id]);
        $n++;
    }
    return [$n, $reste];
}

/** Lit les lignes des tickets sans familles (au plus FP_DETAILS_MAX) ; rend le nombre restant. */
function fpLireFamilles(int $sid, string $du, string $au): int
{
    $L = Db::rows('SELECT id FROM ceo_fid_ticket WHERE shop_id = ? AND jour BETWEEN ? AND ? AND familles IS NULL ORDER BY jour DESC LIMIT ' . (FP_DETAILS_MAX + 1), [$sid, $du, $au]);
    $L = array_slice($L, 0, FP_DETAILS_MAX);
    if ($L === [] || !PanelApi::configured()) { return 0; }
    $ch = [];
    foreach ($L as $r) { $ch[(int) $r['id']] = '/transactions/' . (int) $r['id'] . '?include=products'; }
    $res = PanelApi::getParalleleDetail($ch, 4, 30);
    foreach ($ch as $tid => $p) {
        $r = $res[$tid] ?? null;
        if (!is_array($r) || (int) ($r['code'] ?? 0) !== 200) { continue; }
        [$f] = fpLignes($r['data'] ?? null);
        Db::exec('UPDATE ceo_fid_ticket SET familles = ? WHERE id = ?', [json_encode(array_map(static fn ($q) => round($q, 1), $f)), $tid]);
    }
    return (int) (Db::row('SELECT COUNT(*) n FROM ceo_fid_ticket WHERE shop_id = ? AND jour BETWEEN ? AND ? AND familles IS NULL', [$sid, $du, $au])['n'] ?? 0);
}

/* ---------------------------------------------------------------------------
 * Le calcul
 * ------------------------------------------------------------------------- */
/** Les clients d'un magasin sur la mémoire : passages, tickets, montant, commandes, familles, premier et dernier jour. */
function fpClients(int $sid, string $du, string $au): array
{
    $C = [];
    foreach (Db::rows('SELECT client_id, jour, quand, montant, commande, familles FROM ceo_fid_ticket WHERE shop_id = ? AND jour BETWEEN ? AND ? ORDER BY quand', [$sid, $du, $au]) as $t) {
        $c = &$C[(int) $t['client_id']];
        $c ??= ['jours' => [], 'tk' => 0, 'eur' => 0.0, 'cmd' => 0, 'fam' => [], 'famLu' => 0];
        $c['jours'][(string) $t['jour']] = true;
        $c['tk']++; $c['eur'] += (float) $t['montant']; $c['cmd'] += (int) $t['commande'];
        if ($t['familles'] !== null) {
            $c['famLu']++;
            foreach ((array) json_decode((string) $t['familles'], true) as $k => $q) { $c['fam'][$k] = ($c['fam'][$k] ?? 0) + (float) $q; }
        }
        unset($c);
    }
    return $C;
}

/** Le profil d'un client, et ses deux radars (0 à 100). */
function fpProfilClient(array $c, array $profils, array $R, string $au): array
{
    $jours = array_keys($c['jours']);
    sort($jours);
    $debutFenetre = date('Y-m-d', strtotime($au . ' -' . ($R['fenetre'] - 1) . ' days'));
    $dansFenetre = count(array_filter($jours, static fn ($j) => $j >= $debutFenetre));
    $rythme = $dansFenetre * 30 / $R['fenetre'];
    $choisi = null;
    foreach ($profils as $p) { if ($p['unite'] !== 'base' && $rythme >= $p['seuil']) { $choisi = $p; break; } }
    $choisi ??= end($profils);
    $haut = max(1, max(array_map(static fn ($p) => $p['seuil'], $profils)));
    $dernier = end($jours);
    $ecart = static fn (string $a, string $b): int => (int) round((strtotime($b) - strtotime($a)) / 86400);
    $totFam = array_sum($c['fam']) ?: 0;
    $fam = [];
    foreach (FP_FAMILLES as $k => $lib) { $fam[$k] = $totFam > 0 ? (int) round(100 * ($c['fam'][$k] ?? 0) / $totFam) : 0; }
    return [
        'profil' => $choisi['id'], 'rythme' => round($rythme, 1), 'passagesFenetre' => $dansFenetre, 'passages' => count($jours),
        'premier' => $jours[0], 'dernier' => $dernier, 'depuis' => $ecart($dernier, $au),
        'radar' => [
            'frequence' => (int) min(100, round(100 * $rythme / $haut)),
            'recence' => (int) max(0, round(100 * (1 - $ecart($dernier, $au) / $R['memoire']))),
            'anciennete' => (int) round(100 * $ecart($jours[0], $dernier) / max(1, $R['memoire'] - 1)),
            'panier' => (int) min(100, round(100 * ($c['eur'] / max(1, $c['tk'])) / FP_PANIER_100)),
            'diversite' => (int) round(100 * count(array_filter($c['fam'])) / count(FP_FAMILLES)),
            'comptoir' => (int) round(100 * ($c['tk'] - $c['cmd']) / max(1, $c['tk'])),
        ],
        'familles' => $fam, 'famLues' => $totFam > 0,
    ];
}

/** Le calcul d'un magasin : par profil, les clients, leur part, leur CA, leurs radars moyens ; et les clients. */
function fpMagasin(int $sid, bool $lire = true, bool $detail = true): array
{
    $R = fpReglages();
    $profils = fpProfils(true);
    $au = date('Y-m-d');
    $du = date('Y-m-d', strtotime($au . ' -' . ($R['memoire'] - 1) . ' days'));
    [$lus, $reste] = $lire ? fpLireJours($sid, $du, $au) : [0, 0];
    $sansFam = $lire && $detail ? fpLireFamilles($sid, $du, $au) : 0;
    $C = fpClients($sid, $du, $au);
    $parP = [];
    foreach ($profils as $p) { $parP[$p['id']] = ['n' => 0, 'tk' => 0, 'eur' => 0.0, 'cmd' => 0, 'radar' => array_fill_keys(array_keys(FP_AXES), 0), 'fam' => array_fill_keys(array_keys(FP_FAMILLES), 0), 'famN' => 0, 'depuis' => []]; }
    $moy = ['radar' => array_fill_keys(array_keys(FP_AXES), 0), 'fam' => array_fill_keys(array_keys(FP_FAMILLES), 0), 'famN' => 0];
    $liste = [];
    foreach ($C as $cid => $c) {
        $x = fpProfilClient($c, $profils, $R, $au);
        $s = &$parP[$x['profil']];
        $s['n']++; $s['tk'] += $c['tk']; $s['eur'] += $c['eur']; $s['cmd'] += $c['cmd']; $s['depuis'][] = $x['depuis'];
        foreach ($x['radar'] as $k => $v) { $s['radar'][$k] += $v; $moy['radar'][$k] += $v; }
        if ($x['famLues']) { $s['famN']++; $moy['famN']++; foreach ($x['familles'] as $k => $v) { $s['fam'][$k] += $v; $moy['fam'][$k] += $v; } }
        unset($s);
        $liste[] = ['client' => $cid, 'tk' => $c['tk'], 'eur' => round($c['eur'], 2), 'cmd' => $c['cmd']] + $x;
    }
    $N = count($C);
    $caTot = array_sum(array_column($liste, 'eur')) ?: 1;
    $sortie = [];
    foreach ($profils as $p) {
        $s = $parP[$p['id']];
        sort($s['depuis']);
        $sortie[] = $p + ['clients' => $s['n'], 'part' => $N ? round(100 * $s['n'] / $N, 1) : 0, 'partCa' => round(100 * $s['eur'] / $caTot, 1),
            'panier' => $s['tk'] ? round($s['eur'] / $s['tk'], 2) : null, 'commande' => $s['tk'] ? (int) round(100 * $s['cmd'] / $s['tk']) : null,
            'dernierPassage' => $s['depuis'] ? $s['depuis'][intdiv(count($s['depuis']), 2)] : null,
            'radar' => $s['n'] ? array_map(static fn ($v) => (int) round($v / $s['n']), $s['radar']) : null,
            'familles' => $s['famN'] ? array_map(static fn ($v) => (int) round($v / $s['famN']), $s['fam']) : null];
    }
    // Les clients qui reviennent : tous ceux au-dessus du profil de base, les plus réguliers d'abord.
    $base = end($profils)['id'];
    $rev = array_values(array_filter($liste, static fn ($x) => $x['profil'] !== $base));
    usort($rev, static fn ($a, $b) => [$b['rythme'], $b['passages'], $b['eur']] <=> [$a['rythme'], $a['passages'], $a['eur']]);
    $rev = array_slice($rev, 0, 60);
    $noms = $detail ? fpNoms($sid, array_column($rev, 'client')) : [];
    foreach ($rev as &$x) { $x['nom'] = $noms[$x['client']] ?? ('Client ' . $x['client']); }
    unset($x);
    $jours = Db::row('SELECT COALESCE(SUM(tickets),0) t, COALESCE(SUM(identifies),0) i, COUNT(*) j FROM ceo_fid_jour WHERE shop_id = ? AND jour BETWEEN ? AND ?', [$sid, $du, $au]);
    return ['shop' => (string) $sid, 'du' => $du, 'au' => $au, 'fenetre' => $R['fenetre'], 'memoire' => $R['memoire'], 'axes' => FP_AXES, 'familles' => FP_FAMILLES,
        'scannes' => $N, 'tickets' => (int) $jours['t'], 'identifies' => (int) $jours['i'], 'joursLus' => (int) $jours['j'], 'aLire' => $reste, 'sansFamilles' => $sansFam,
        'profils' => $sortie, 'moyenne' => $N ? ['radar' => array_map(static fn ($v) => (int) round($v / $N), $moy['radar']),
            'familles' => $moy['famN'] ? array_map(static fn ($v) => (int) round($v / $moy['famN']), $moy['fam']) : null] : null,
        'clients' => $rev];
}

/** Prénom et initiale du nom des clients, lus sur leur fiche (`/shops/{id}/clients/{id}`), jamais gardés. */
function fpNoms(int $sid, array $ids): array
{
    if ($ids === [] || !PanelApi::configured()) { return []; }
    $ch = [];
    foreach ($ids as $id) { $ch[(int) $id] = '/shops/' . $sid . '/clients/' . (int) $id; }
    $res = PanelApi::getParalleleDetail($ch, 4, 20);
    $out = [];
    foreach ($ch as $id => $p) {
        $d = $res[$id]['data'] ?? null;
        $c = is_array($d) ? ($d['client'] ?? $d['data']['client'] ?? $d['data'] ?? $d) : [];
        $prenom = trim((string) ($c['name'] ?? ''));
        $nom = trim((string) ($c['surname'] ?? ''));
        $out[$id] = $prenom !== '' || $nom !== '' ? trim($prenom . ' ' . ($nom !== '' ? mb_strtoupper(mb_substr($nom, 0, 1)) . '.' : '')) : null;
    }
    return array_filter($out);
}

/* ---------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------- */
function fpShop(): int
{
    $s = (string) ($_GET['shop'] ?? '');
    return ctype_digit($s) ? (int) $s : 0;
}

/** GET /fidelite/profils : les profils et la fenêtre ; l'aperçu d'un magasin (?shop=) ou du réseau (sans shop). */
function ep_fidelite_profils(): array
{
    $R = fpReglages();
    $out = ['profils' => fpProfils(), 'fenetre' => $R['fenetre'], 'memoire' => $R['memoire'], 'magasins' => fidMagasins()];
    $sid = fpShop();
    $ids = $sid ? [$sid] : array_map('intval', array_column($out['magasins'], 'id'));
    $apercu = []; $N = 0; $aLire = 0;
    foreach ($ids as $s) {
        $m = fpMagasin($s, true, false);
        $N += $m['scannes']; $aLire += $m['aLire'];
        foreach ($m['profils'] as $p) { $apercu[$p['id']] = ($apercu[$p['id']] ?? 0) + $p['clients']; }
    }
    $out['apercu'] = ['shop' => $sid ? (string) $sid : '', 'scannes' => $N, 'parProfil' => $apercu, 'aLire' => $aLire];
    return $out;
}

/** GET /fidelite/profils/magasin?shop= : le dashboard du magasin. */
function ep_fidelite_profils_magasin(): array
{
    $sid = fpShop();
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant (?shop=)']; }
    return fpMagasin($sid, true);
}

/** GET /fidelite/client?shop=&client= : l'historique d'un client sur 180 jours, ticket par ticket. */
function ep_fidelite_client(): array
{
    $sid = fpShop();
    $cid = ctype_digit((string) ($_GET['client'] ?? '')) ? (int) $_GET['client'] : 0;
    if ($sid <= 0 || $cid <= 0) { http_response_code(422); return ['error' => 'magasin ou client manquant (?shop=&client=)']; }
    $R = fpReglages();
    $profils = fpProfils(true);
    $au = date('Y-m-d');
    $du = date('Y-m-d', strtotime($au . ' -179 days'));
    [, $reste] = fpLireJours($sid, $du, $au);
    $T = Db::rows('SELECT id, jour, quand, montant, commande FROM ceo_fid_ticket WHERE shop_id = ? AND client_id = ? AND jour BETWEEN ? AND ? ORDER BY quand', [$sid, $cid, $du, $au]);
    if ($T === []) { return ['error' => 'aucun ticket rattaché à ce client sur 180 jours', 'aLire' => $reste]; }
    // Les lignes de ses tickets, lues ici : les articles ne se gardent pas.
    $ch = [];
    foreach (array_slice($T, -80) as $t) { $ch[(int) $t['id']] = '/transactions/' . (int) $t['id'] . '?include=products'; }
    $res = PanelApi::getParalleleDetail($ch, 4, 30);
    $tickets = []; $art = []; $fam = []; $parJour = array_fill(0, 7, 0); $moment = ['matin' => 0, 'midi' => 0, 'après-midi' => 0, 'soir' => 0];
    $C = ['jours' => [], 'tk' => 0, 'eur' => 0.0, 'cmd' => 0, 'fam' => [], 'famLu' => 0];
    $mois = [];
    foreach ($T as $t) {
        $tid = (int) $t['id'];
        [$f, $a] = isset($res[$tid]) && (int) ($res[$tid]['code'] ?? 0) === 200 ? fpLignes($res[$tid]['data'] ?? null) : [[], []];
        if ($f !== []) { Db::exec('UPDATE ceo_fid_ticket SET familles = ? WHERE id = ?', [json_encode(array_map(static fn ($q) => round($q, 1), $f)), $tid]); }
        foreach ($a as $x) { $art[$x['nom']] = ($art[$x['nom']] ?? 0) + $x['q']; }
        foreach ($f as $k => $q) { $fam[$k] = ($fam[$k] ?? 0) + $q; $C['fam'][$k] = ($C['fam'][$k] ?? 0) + $q; }
        $j = (string) $t['jour'];
        $C['jours'][$j] = true; $C['tk']++; $C['eur'] += (float) $t['montant']; $C['cmd'] += (int) $t['commande'];
        $h = (int) substr((string) $t['quand'], 11, 2);
        $moment[$h < 11 ? 'matin' : ($h < 14 ? 'midi' : ($h < 18 ? 'après-midi' : 'soir'))]++;
        $parJour[((int) date('N', strtotime($j))) - 1]++;
        $mois[substr($j, 0, 7)][$j] = true;
        $tickets[] = ['quand' => substr((string) $t['quand'], 0, 16), 'montant' => (float) $t['montant'], 'commande' => (int) $t['commande'] === 1,
            'articles' => array_map(static fn ($x) => $x['nom'] . ' ×' . rtrim(rtrim(number_format($x['q'], 1, ',', ''), '0'), ','), $a)];
    }
    // Le profil mois par mois : les passages du mois ramenés à 30 jours (le mois en cours, au prorata des jours écoulés).
    $parMois = [];
    foreach ($mois as $ym => $js) {
        $nbJours = (int) date('t', strtotime($ym . '-01'));
        $ecoules = $ym === substr($au, 0, 7) ? (int) substr($au, 8, 2) : $nbJours;
        $premierJ = min(array_keys($js));
        if ($ym === substr(min(array_keys($C['jours'])), 0, 7)) { $ecoules = $ecoules - (int) substr($premierJ, 8, 2) + 1; }
        $rythme = count($js) * 30 / max(1, $ecoules);
        $p = null;
        foreach ($profils as $q) { if ($q['unite'] !== 'base' && $rythme >= $q['seuil']) { $p = $q; break; } }
        $p ??= end($profils);
        $parMois[] = ['mois' => $ym, 'passages' => count($js), 'jours' => $ecoules, 'rythme' => round($rythme, 1), 'profil' => $p['id']];
    }
    arsort($art);
    $x = fpProfilClient($C, $profils, $R, $au);
    $noms = fpNoms($sid, [$cid]);
    $m = fpMagasin($sid, false, false);
    return ['shop' => (string) $sid, 'client' => $cid, 'nom' => $noms[$cid] ?? ('Client ' . $cid), 'du' => $du, 'au' => $au, 'fenetre' => $R['fenetre'],
        'profils' => fpProfils(true), 'moyenne' => $m['moyenne'], 'axes' => FP_AXES, 'famillesLib' => FP_FAMILLES,
        'tk' => $C['tk'], 'eur' => round($C['eur'], 2), 'cmd' => $C['cmd'], 'parJour' => $parJour, 'moments' => $moment,
        'articles' => array_slice(array_map(static fn ($k, $v) => ['nom' => $k, 'q' => round($v, 1)], array_keys($art), $art), 0, 10),
        'parMois' => $parMois, 'tickets' => array_reverse($tickets), 'aLire' => $reste] + $x;
}

/** POST /fidelite/profils : {profils:[{id?, nom, n, unite, couleur, actif, supprimer?}], fenetre?, memoire?, qui}. */
function wr_fidelite_profils_post(): array
{
    fpSchema();
    $b = body();
    $qui = fidTexte($b['qui'] ?? 'Cockpit', 60, 'Cockpit');
    $L = is_array($b['profils'] ?? null) ? $b['profils'] : [];
    $ops = [];
    foreach ($L as $x) {
        if (!is_array($x)) { continue; }
        $id = (int) ($x['id'] ?? 0);
        $cur = $id ? Db::row('SELECT * FROM ceo_fid_profil WHERE id = ?', [$id]) : null;
        if ($id && $cur === null) { http_response_code(404); return ['error' => 'profil ' . $id . ' introuvable']; }
        $base = $cur && $cur['unite'] === 'base';
        if (!empty($x['supprimer'])) {
            if ($base) { http_response_code(422); return ['error' => 'le profil de base ne se retire pas']; }
            if ($cur) { $ops[] = ['del', $id]; }
            continue;
        }
        $nom = fidTexte($x['nom'] ?? ($cur['nom'] ?? ''), 40);
        if ($nom === '') { http_response_code(422); return ['error' => 'un profil doit avoir un nom']; }
        $unite = $base ? 'base' : (in_array($x['unite'] ?? ($cur['unite'] ?? ''), ['semaine', 'mois'], true) ? (string) ($x['unite'] ?? $cur['unite']) : 'mois');
        $n = $base ? 0 : fidEntier($x['n'] ?? ($cur['n'] ?? 1), 1, $unite === 'semaine' ? 7 : 30, 1);
        $c = is_string($x['couleur'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $x['couleur']) ? $x['couleur'] : (string) ($cur['couleur'] ?? '#9a948b');
        $actif = $base ? 1 : (array_key_exists('actif', $x) ? (!empty($x['actif']) ? 1 : 0) : (int) ($cur['actif'] ?? 1));
        $ops[] = [$cur ? 'upd' : 'ins', $id, $nom, $n, $unite, $c, $actif];
    }
    // Deux profils actifs ne partagent pas un seuil : le rangement serait ambigu.
    $etat = [];
    foreach (Db::rows('SELECT * FROM ceo_fid_profil') as $r) { $etat[(int) $r['id']] = $r; }
    $k = 0;
    foreach ($ops as $o) {
        if ($o[0] === 'del') { unset($etat[$o[1]]); continue; }
        $etat[$o[0] === 'upd' ? $o[1] : 'n' . (++$k)] = ['nom' => $o[2], 'n' => $o[3], 'unite' => $o[4], 'actif' => $o[6]];
    }
    $seuils = [];
    foreach ($etat as $r) {
        if ((int) $r['actif'] !== 1 || $r['unite'] === 'base') { continue; }
        $s = fpSeuil(['n' => (int) $r['n'], 'unite' => (string) $r['unite']]);
        if (isset($seuils[$s])) { http_response_code(422); return ['error' => '« ' . $r['nom'] . ' » et « ' . $seuils[$s] . ' » ont le même seuil (' . $s . ' passages par mois)']; }
        $seuils[$s] = $r['nom'];
    }
    $R = fpReglages();
    $fen = isset($b['fenetre']) ? fidEntier($b['fenetre'], 7, 120, 30) : $R['fenetre'];
    $mem = isset($b['memoire']) ? fidEntier($b['memoire'], 30, 365, 90) : $R['memoire'];
    if ($mem < $fen) { http_response_code(422); return ['error' => 'la mémoire (' . $mem . ' j) doit couvrir la fenêtre (' . $fen . ' j)']; }
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        foreach ($ops as $o) {
            if ($o[0] === 'del') { Db::exec('DELETE FROM ceo_fid_profil WHERE id = ?', [$o[1]]); continue; }
            if ($o[0] === 'upd') { Db::exec('UPDATE ceo_fid_profil SET nom = ?, n = ?, unite = ?, couleur = ?, actif = ?, updated_at = NOW(), updated_by = ? WHERE id = ?', [$o[2], $o[3], $o[4], $o[5], $o[6], $qui, $o[1]]); }
            else { Db::exec('INSERT INTO ceo_fid_profil (nom, n, unite, couleur, actif, updated_at, updated_by) VALUES (?,?,?,?,?,NOW(),?)', [$o[2], $o[3], $o[4], $o[5], $o[6], $qui]); }
        }
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['fidProfilsReglages', json_encode(['fenetre' => $fen, 'memoire' => $mem])]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $n = count($ops) + ($fen !== $R['fenetre'] || $mem !== $R['memoire'] ? 1 : 0);
    journalAdd($qui, 'Fidélité', null, 'Profils clients publiés : ' . $n . ' modification' . ($n > 1 ? 's' : '') . ' (fenêtre ' . $fen . ' j, mémoire ' . $mem . ' j)');
    return ['ok' => true, 'n' => $n, 'profils' => fpProfils(), 'fenetre' => $fen, 'memoire' => $mem];
}
