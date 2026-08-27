<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les croisements : l'attache entre deux familles, au choix.
 *
 * La feuille Flip & Flap × boissons a montré le principe ; cet écran le rend
 * GÉNÉRAL : deux familles A et B — groupe, catégorie ou produit, librement
 * mélangés — et la lecture « sur les tickets qui contiennent A, la part qui
 * contient aussi B », mois par mois, réseau et magasin par magasin, puis
 * vendeuse par vendeuse au clic.
 *
 * Le croisement est ASYMÉTRIQUE et c'est voulu : « tickets sandwich avec
 * dessert » n'est pas « tickets dessert avec sandwich ». L'écran écrit l'ordre
 * en toutes lettres plutôt que de le laisser deviner.
 *
 * Les combos qui servent s'ENREGISTRENT (table `ceo_combo`) : ils reviennent
 * chaque mois sans être reconstruits. Les mois révolus se mettent en CACHE
 * (`ceo_crois_cache`) : douze mois × cinq périmètres ne relisent pas douze
 * fois la caisse — seul le mois en cours se recalcule à chaque lecture,
 * parce que lui seul change encore.
 */

/**
 * Les dayparts : un ticket appartient à un moment de la journée par son heure
 * d'encaissement. Les bornes suivent le métier — le rush du matin, le service
 * du midi, le creux de l'après-midi — et sont écrites ici une fois : l'écran,
 * le détail et la feuille PDF lisent la même définition.
 */
const CROIS_DAYPARTS = [
    'matin' => ['lib' => 'Matin (avant 11 h)', 'sql' => 'HOUR(t.insert_timestamp) < 11'],
    'midi' => ['lib' => 'Midi (11 – 14 h)', 'sql' => 'HOUR(t.insert_timestamp) >= 11 AND HOUR(t.insert_timestamp) < 14'],
    'apresmidi' => ['lib' => 'Après-midi (14 h et plus)', 'sql' => 'HOUR(t.insert_timestamp) >= 14'],
];

/** La condition SQL du daypart demandé — vide : toute la journée. */
function croisDaypart(string $dp): array
{
    $d = CROIS_DAYPARTS[$dp] ?? null;
    return ['cle' => $d !== null ? $dp : '',
        'lib' => $d !== null ? $d['lib'] : '',
        'sql' => $d !== null ? ' AND ' . $d['sql'] : ''];
}

function croisTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    try {
        Db::exec('CREATE TABLE IF NOT EXISTS ceo_combo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            a_sel VARCHAR(140) NOT NULL, a_lib VARCHAR(160) NOT NULL,
            b_sel VARCHAR(140) NOT NULL, b_lib VARCHAR(160) NOT NULL,
            surnom VARCHAR(120) NULL,
            cree_le DATE NOT NULL
        ) DEFAULT CHARSET=utf8mb4');
        Db::exec('CREATE TABLE IF NOT EXISTS ceo_crois_cache (
            a_sel VARCHAR(120) NOT NULL, b_sel VARCHAR(120) NOT NULL,
            mois CHAR(7) NOT NULL, shop VARCHAR(24) NOT NULL,
            ff INT NOT NULL, avec INT NOT NULL,
            ca_b DOUBLE NOT NULL DEFAULT 0, q_b DOUBLE NOT NULL DEFAULT 0,
            PRIMARY KEY (a_sel, b_sel, mois, shop)
        ) DEFAULT CHARSET=utf8mb4');
        try { Db::exec('ALTER TABLE ceo_combo ADD COLUMN target DOUBLE NULL'); }
        catch (Throwable $e) { /* déjà ajoutée */ }
        // Le daypart fait PARTIE du combo : « Flip & Flap × Boissons le midi »
        // et « …toute la journée » sont deux engagements différents, avec
        // chacun sa target et son historique.
        try { Db::exec("ALTER TABLE ceo_combo ADD COLUMN dp VARCHAR(12) NOT NULL DEFAULT ''"); }
        catch (Throwable $e) { /* déjà ajoutée */ }
    } catch (Throwable $e) { /* l'écran dira ce qui manque */ }
}

/**
 * Une sélection — `g:Boissons`, `c:Flip & Flap`, `p:1150034` — vers ses
 * identifiants de caisse et son libellé.
 *
 * @return array{ids:list<int>,lib:string}|null
 */
function croisIds(string $sel): ?array
{
    $type = substr($sel, 0, 2);
    $val = (string) substr($sel, 2);
    if ($val === '' || !in_array($type, ['g:', 'c:', 'p:'], true)) { return null; }
    $ids = []; $lib = $val;
    foreach (ep_prod_catalogue() as $p) {
        $pid = $p['pwaId'] ?? null;
        if ($pid === null) { continue; }
        if ($type === 'g:' && (string) ($p['groupe'] ?? '') === $val) { $ids[] = (int) $pid; }
        elseif ($type === 'c:' && (string) ($p['categorie'] ?? '') === $val) { $ids[] = (int) $pid; }
        elseif ($type === 'p:' && (string) $pid === $val) { $ids[] = (int) $pid; $lib = (string) $p['nom']; }
    }
    if ($type === 'g:') { $lib = $val . ' (groupe)'; }
    return $ids === [] ? null : ['ids' => $ids, 'lib' => $lib];
}

/**
 * Un mois de croisement, calculé sur la caisse : par magasin, les tickets A
 * et ceux qui portent aussi B — plus le prix moyen de B réellement encaissé.
 *
 * @return array{shops:array<string,array{ff:int,avec:int}>,caB:float,qB:float}|null
 */
function croisCalcul(array $idsA, array $idsB, string $mois, string $dpSql = ''): ?array
{
    [$du, $au] = venteBornes($mois);
    $in = static fn (array $ids) => implode(',', array_map('intval', $ids));
    $ticketsA = []; $avecB = []; $caB = 0.0; $qB = 0.0;
    try {
        foreach (Db::rows('SELECT DISTINCT t.id, t.id_shop
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?' . $dpSql . '
                              AND l.id_product IN (' . $in($idsA) . ')', [$du, $au]) as $r) {
            $ticketsA[(int) $r['id']] = (string) $r['id_shop'];
        }
        foreach (Db::rows('SELECT t.id, SUM(l.total_gross_value_after_discount) ca, SUM(l.quantity) q
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?' . $dpSql . '
                              AND l.id_product IN (' . $in($idsB) . ')
                            GROUP BY t.id', [$du, $au]) as $r) {
            $avecB[(int) $r['id']] = true;
            $caB += (float) $r['ca']; $qB += (float) $r['q'];
        }
    } catch (PDOException $e) { return null; }
    $shops = [];
    foreach ($ticketsA as $tid => $sid) {
        $shops[$sid] = $shops[$sid] ?? ['ff' => 0, 'avec' => 0];
        $shops[$sid]['ff']++;
        if (isset($avecB[$tid])) { $shops[$sid]['avec']++; }
    }
    return ['shops' => $shops, 'caB' => $caB, 'qB' => $qB];
}

/** Le mois, servi du cache s'il y est — et mis en cache s'il est révolu. */
function croisMoisServi(string $aSel, string $bSel, array $idsA, array $idsB, string $mois, array $nomDe, string $dp = ''): ?array
{
    croisTables();
    // Le daypart entre dans la CLÉ de cache, pas dans le schéma : un même
    // combo porte quatre historiques indépendants (journée, matin, midi,
    // après-midi) sans toucher la table.
    $dpDef = croisDaypart($dp);
    if ($dpDef['cle'] !== '') { $aSel .= '|dp:' . $dpDef['cle']; }
    $encours = $mois >= date('Y-m');
    if (!$encours) {
        try {
            $rows = Db::rows('SELECT shop, ff, avec, ca_b, q_b FROM ceo_crois_cache
                               WHERE a_sel = ? AND b_sel = ? AND mois = ?', [$aSel, $bSel, $mois]);
            if ($rows !== []) {
                $out = ['shops' => [], 'caB' => 0.0, 'qB' => 0.0];
                foreach ($rows as $r) {
                    if ((string) $r['shop'] === '*') { $out['caB'] = (float) $r['ca_b']; $out['qB'] = (float) $r['q_b']; continue; }
                    $out['shops'][(string) $r['shop']] = ['ff' => (int) $r['ff'], 'avec' => (int) $r['avec']];
                }
                return $out;
            }
        } catch (Throwable $e) { /* cache muet : on calcule */ }
    }
    $c = croisCalcul($idsA, $idsB, $mois, $dpDef['sql']);
    if ($c === null) { return null; }
    if (!$encours) {
        try {
            Db::exec('INSERT INTO ceo_crois_cache (a_sel,b_sel,mois,shop,ff,avec,ca_b,q_b) VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE ff = VALUES(ff)', [$aSel, $bSel, $mois, '*', 0, 0, $c['caB'], $c['qB']]);
            foreach ($nomDe as $sid => $n) {
                $x = $c['shops'][$sid] ?? $c['shops'][(string) $sid] ?? ['ff' => 0, 'avec' => 0];
                Db::exec('INSERT INTO ceo_crois_cache (a_sel,b_sel,mois,shop,ff,avec) VALUES (?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE ff = VALUES(ff), avec = VALUES(avec)',
                    [$aSel, $bSel, $mois, (string) $sid, $x['ff'], $x['avec']]);
            }
        } catch (Throwable $e) { /* pas de cache : plus lent, jamais faux */ }
    }
    return $c;
}

/** GET /croisements/options — les familles proposées aux deux sélecteurs. */
function ep_croisements_options(): array
{
    croisTables();
    $groupes = []; $categories = []; $produits = [];
    foreach (ep_prod_catalogue() as $p) {
        if ($p['pwaId'] === null) { continue; }
        $g = (string) ($p['groupe'] ?? ''); $c = (string) ($p['categorie'] ?? '');
        if ($g !== '') { $groupes[$g] = true; }
        if ($c !== '') { $categories[$c] = true; }
        $produits[] = ['id' => (int) $p['pwaId'], 'nom' => (string) $p['nom']];
    }
    ksort($groupes); ksort($categories);
    usort($produits, static fn ($a, $b) => strcmp($a['nom'], $b['nom']));
    $combos = [];
    try {
        foreach (Db::rows('SELECT * FROM ceo_combo ORDER BY id') as $r) {
            $combos[] = ['id' => (int) $r['id'], 'aSel' => (string) $r['a_sel'], 'aLib' => (string) $r['a_lib'],
                'bSel' => (string) $r['b_sel'], 'bLib' => (string) $r['b_lib'],
                'surnom' => (string) ($r['surnom'] ?? ''),
                'dp' => (string) ($r['dp'] ?? ''),
                'dpLib' => croisDaypart((string) ($r['dp'] ?? ''))['lib'],
                'target' => isset($r['target']) && $r['target'] !== null ? (float) $r['target'] : null];
        }
    } catch (Throwable $e) { /* table absente */ }
    return ['groupes' => array_keys($groupes), 'categories' => array_keys($categories),
        'produits' => $produits, 'combos' => $combos];
}

/** GET /croisements?a=c:Flip & Flap&b=g:Boissons[&mois=6] */
function ep_croisements(): array
{
    $aSel = trim((string) ($_GET['a'] ?? ''));
    $bSel = trim((string) ($_GET['b'] ?? ''));
    $n = (int) ($_GET['mois'] ?? 6);
    if ($n < 2 || $n > 12) { $n = 6; }
    $a = croisIds($aSel); $b = croisIds($bSel);
    $dp = croisDaypart(trim((string) ($_GET['dp'] ?? '')));
    if ($a === null || $b === null) {
        http_response_code(422);
        return ['error' => 'choisissez deux familles — la sélection ne correspond à rien au catalogue'];
    }
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    $out = ['a' => ['sel' => $aSel, 'lib' => $a['lib'], 'refs' => count($a['ids'])],
        'b' => ['sel' => $bSel, 'lib' => $b['lib'], 'refs' => count($b['ids'])],
        'daypart' => $dp['cle'], 'daypartLib' => $dp['lib'],
        'mois' => [], 'reseau' => [], 'magasins' => [], 'prixB' => null, 'motif' => null];

    $parMois = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $m = date('Y-m', $t);
        $out['mois'][] = ['cle' => $m, 'lib' => strftime_fr($t, 'M'), 'encours' => $m === date('Y-m')];
        $parMois[$m] = croisMoisServi($aSel, $bSel, $a['ids'], $b['ids'], $m, $nomDe, $dp['cle']);
    }
    $dernierRevolu = null;
    foreach (array_reverse(array_keys($parMois)) as $m) {
        if ($m < date('Y-m') && $parMois[$m] !== null) { $dernierRevolu = $m; break; }
    }
    $prixB = 0.0;
    if ($dernierRevolu !== null && $parMois[$dernierRevolu]['qB'] > 0) {
        $prixB = $parMois[$dernierRevolu]['caB'] / $parMois[$dernierRevolu]['qB'];
    }
    $out['prixB'] = round($prixB, 2);
    $out['dernierRevolu'] = $dernierRevolu;

    $ligne = static function (?string $sid) use ($parMois, $dernierRevolu, $prixB): array {
        $cases = []; $ffDer = 0; $avecDer = 0;
        foreach ($parMois as $m => $c) {
            if ($c === null) { $cases[] = ['taux' => null, 'ff' => null]; continue; }
            $ff = 0; $avec = 0;
            foreach ($c['shops'] as $s2 => $x) {
                // Les clés numériques d'un tableau PHP redeviennent des
                // entiers : on compare des chaînes, ou on ne compare rien.
                if ($sid !== null && (string) $s2 !== $sid) { continue; }
                $ff += $x['ff']; $avec += $x['avec'];
            }
            $cases[] = ['taux' => $ff > 0 ? round(100 * $avec / $ff, 1) : null, 'ff' => $ff];
            if ($m === $dernierRevolu) { $ffDer = $ff; $avecDer = $avec; }
        }
        return ['cases' => $cases, 'ffDernier' => $ffDer,
            'tauxDernier' => $ffDer > 0 ? round(100 * $avecDer / $ffDer, 1) : null,
            'manquesDernier' => $ffDer - $avecDer,
            'eurDernier' => (int) round(($ffDer - $avecDer) * $prixB)];
    };
    try {
        $cb = Db::row('SELECT target FROM ceo_combo WHERE a_sel = ? AND b_sel = ? AND dp = ?', [$aSel, $bSel, $dp['cle']]);
        $out['target'] = $cb !== null && $cb['target'] !== null ? (float) $cb['target'] : null;
    } catch (Throwable $e) { $out['target'] = null; }
    $out['reseau'] = $ligne(null);
    foreach ($nomDe as $sid => $nom) {
        $out['magasins'][] = ['id' => (string) $sid, 'nom' => $nom] + $ligne((string) $sid);
    }
    usort($out['magasins'], static fn ($x, $y) => ($x['tauxDernier'] ?? 999) <=> ($y['tauxDernier'] ?? 999));
    return $out;
}

/** GET /croisements/detail?a=&b=&m=2026-07&shop=4 — vendeuse par vendeuse. */
function ep_croisements_detail(): array
{
    $aSel = trim((string) ($_GET['a'] ?? '')); $bSel = trim((string) ($_GET['b'] ?? ''));
    $m = trim((string) ($_GET['m'] ?? ''));
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $a = croisIds($aSel); $b = croisIds($bSel);
    if ($a === null || $b === null || !preg_match('/^\d{4}-\d{2}$/', $m)) {
        http_response_code(422); return ['error' => 'sélection ou mois invalide'];
    }
    [$du, $au] = venteBornes($m);
    $dp = croisDaypart(trim((string) ($_GET['dp'] ?? '')));
    $in = static fn (array $ids) => implode(',', array_map('intval', $ids));
    $emp = venteEmployes();
    $tickets = []; $avecB = [];
    try {
        foreach (Db::rows('SELECT DISTINCT t.id, t.id_employee
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?' . $dp['sql'] . '
                              AND t.id_shop = ? AND l.id_product IN (' . $in($a['ids']) . ')', [$du, $au, $shop]) as $r) {
            $tickets[(int) $r['id']] = $r['id_employee'] !== null ? (int) $r['id_employee'] : null;
        }
        foreach (Db::rows('SELECT DISTINCT t.id
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?' . $dp['sql'] . '
                              AND t.id_shop = ? AND l.id_product IN (' . $in($b['ids']) . ')', [$du, $au, $shop]) as $r) {
            $avecB[(int) $r['id']] = true;
        }
    } catch (PDOException $e) { http_response_code(503); return ['error' => 'lecture des tickets impossible']; }

    $par = []; $sans = ['ff' => 0, 'avec' => 0];
    foreach ($tickets as $tid => $eid) {
        $ok = isset($avecB[$tid]) ? 1 : 0;
        if ($eid === null || !isset($emp[$eid])) { $sans['ff']++; $sans['avec'] += $ok; continue; }
        $par[$eid] = $par[$eid] ?? ['ff' => 0, 'avec' => 0];
        $par[$eid]['ff']++; $par[$eid]['avec'] += $ok;
    }
    $lignes = [];
    foreach ($par as $eid => $x) {
        $lignes[] = ['nom' => $emp[$eid]['nom'], 'ff' => $x['ff'], 'avec' => $x['avec'],
            'taux' => $x['ff'] > 0 ? round(100 * $x['avec'] / $x['ff'], 1) : null,
            'manques' => $x['ff'] - $x['avec']];
    }
    usort($lignes, static fn ($x, $y) => $y['ff'] <=> $x['ff']);
    return ['m' => $m, 'shop' => $shop, 'lignes' => $lignes, 'sansVendeur' => $sans];
}

/** POST /croisements/combo — enregistrer ; DELETE /croisements/combo/{id}. */
function wr_croisement_combo(): array
{
    croisTables();
    $b = body();
    $aSel = mb_substr(trim((string) ($b['aSel'] ?? '')), 0, 120);
    $bSel = mb_substr(trim((string) ($b['bSel'] ?? '')), 0, 120);
    if (croisIds($aSel) === null || croisIds($bSel) === null) {
        http_response_code(422); return ['error' => 'le combo ne correspond à rien au catalogue'];
    }
    $dp = croisDaypart(trim((string) ($b['dp'] ?? '')));
    $dej = Db::row('SELECT id FROM ceo_combo WHERE a_sel = ? AND b_sel = ? AND dp = ?', [$aSel, $bSel, $dp['cle']]);
    if ($dej !== null) { http_response_code(409); return ['error' => 'ce combo est déjà enregistré sur ce daypart']; }
    // La target : un taux d'attache visé, en pour cent. Bornée à [1, 100] —
    // une target à 0 ne demande rien, au-delà de 100 ne veut rien dire.
    $target = null;
    if (isset($b['target']) && $b['target'] !== '' && is_numeric($b['target'])) {
        $target = max(1.0, min(100.0, (float) $b['target']));
    }
    Db::exec('INSERT INTO ceo_combo (a_sel, a_lib, b_sel, b_lib, surnom, target, dp, cree_le) VALUES (?,?,?,?,?,?,?,?)',
        [$aSel, mb_substr(trim((string) ($b['aLib'] ?? $aSel)), 0, 160),
         $bSel, mb_substr(trim((string) ($b['bLib'] ?? $bSel)), 0, 160),
         mb_substr(trim((string) ($b['surnom'] ?? '')), 0, 120) ?: null, $target, $dp['cle'], date('Y-m-d')]);
    journalAdd('CEO', 'Croisement', trim((string) ($b['aLib'] ?? $aSel)) . ' × ' . trim((string) ($b['bLib'] ?? $bSel)), 'Combo enregistré');
    return ['ok' => true] + ep_croisements_options();
}

/** PATCH /croisements/combo/{id} — changer la target ou le surnom. */
function wr_croisement_combo_patch(int $id): array
{
    croisTables();
    $dej = Db::row('SELECT * FROM ceo_combo WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'combo inconnu']; }
    $b = body();
    $target = $dej['target'] ?? null;
    if (array_key_exists('target', $b)) {
        $target = ($b['target'] === '' || $b['target'] === null || !is_numeric($b['target']))
            ? null : max(1.0, min(100.0, (float) $b['target']));
    }
    $surnom = array_key_exists('surnom', $b)
        ? (mb_substr(trim((string) $b['surnom']), 0, 120) ?: null) : ($dej['surnom'] ?? null);
    Db::exec('UPDATE ceo_combo SET target = ?, surnom = ? WHERE id = ?', [$target, $surnom, $id]);
    journalAdd('CEO', 'Croisement', $dej['a_lib'] . ' × ' . $dej['b_lib'],
        $target !== null ? 'Target posée à ' . $target . ' %' : 'Target retirée');
    return ['ok' => true] + ep_croisements_options();
}

function wr_croisement_combo_suppr(int $id): array
{
    croisTables();
    $dej = Db::row('SELECT * FROM ceo_combo WHERE id = ?', [$id]);
    if ($dej === null) { http_response_code(404); return ['error' => 'combo inconnu']; }
    // Le cache reste : un combo retiré puis recréé retrouve son historique
    // sans relire douze mois de caisse.
    Db::exec('DELETE FROM ceo_combo WHERE id = ?', [$id]);
    journalAdd('CEO', 'Croisement', $dej['a_lib'] . ' × ' . $dej['b_lib'], 'Combo retiré');
    return ['ok' => true] + ep_croisements_options();
}

/** GET /croisements/feuille.pdf?a=&b=[&m=] — la feuille du combo, à imprimer. */
function ep_croisements_feuille(): array
{
    $aSel = trim((string) ($_GET['a'] ?? '')); $bSel = trim((string) ($_GET['b'] ?? ''));
    $m = trim((string) ($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m', strtotime('first day of last month')); }
    $a = croisIds($aSel); $b = croisIds($bSel);
    if ($a === null || $b === null) { http_response_code(422); return ['error' => 'sélection invalide']; }
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    // Une feuille PAR MAGASIN : la même mise en page, resserrée sur lui — la
    // feuille qu'on épingle dans SA réserve, pas celle du réseau entier.
    $seulShop = trim((string) ($_GET['shop'] ?? ''));
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    if ($seulShop !== '' && !isset($nomDe[$seulShop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $dp = croisDaypart(trim((string) ($_GET['dp'] ?? '')));
    $c = croisMoisServi($aSel, $bSel, $a['ids'], $b['ids'], $m, $nomDe, $dp['cle']);
    if ($c === null) { http_response_code(503); return ['error' => 'lecture des tickets impossible']; }
    $prixB = $c['qB'] > 0 ? $c['caB'] / $c['qB'] : 0.0;
    $target = null;
    try {
        $cb = Db::row('SELECT target FROM ceo_combo WHERE a_sel = ? AND b_sel = ? AND dp = ?', [$aSel, $bSel, $dp['cle']]);
        $target = $cb !== null && $cb['target'] !== null ? (float) $cb['target'] : null;
    } catch (Throwable $e) { /* sans target */ }
    $libMois = strftime_fr(strtotime($m . '-01'), 'M Y');
    $logo = rapLogoDataUri();

    // Les vendeuses — tous magasins, ou le seul demandé.
    $vend = [];
    foreach ($nomDe as $sid => $nomShop) {
        if ($seulShop !== '' && (string) $sid !== $seulShop) { continue; }
        $_GET2 = ['a' => $aSel, 'b' => $bSel, 'm' => $m, 'shop' => (string) $sid];
        $mem = $_GET; $_GET = $_GET2;
        $det = ep_croisements_detail();
        $_GET = $mem;
        foreach ($det['lignes'] ?? [] as $l) { $vend[] = $l + ['magasin' => $nomShop]; }
    }
    usort($vend, static fn ($x, $y) => $y['ff'] <=> $x['ff']);

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif",serif}
      .mut{color:#7a736a}.acc{color:#8D1D2C}
      .k{font-size:7pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:4mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.3pt;text-align:right;padding:1.3mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .t .l{text-align:left}.gris td{color:#9a9186}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
    </style>';
    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Croisements<br>' . $e($libMois) . '</td></tr></table>'
        . '<div class="serif" style="font-size:18pt;margin:4mm 0 1mm">' . $e($a['lib']) . ' × ' . $e($b['lib'])
        . ($dp['lib'] !== '' ? ' <span style="font-size:10pt;color:#7a736a">· ' . $e($dp['lib']) . '</span>' : '') . '</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Sur les tickets contenant ' . $e($a['lib'])
        . ' : la part contenant aussi ' . $e($b['lib']) . '. Prix moyen de B encaissé ce mois-ci : '
        . number_format($prixB, 2, ',', ' ') . ' €.'
        . ($target !== null ? ' <b>Target : ' . number_format($target, 1, ',', ' ') . ' %.</b>' : '') . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="4" style="margin:0 -1mm 4mm"><tr>';
    $mags = [];
    foreach ($nomDe as $sid => $nomShop) {
        if ($seulShop !== '' && (string) $sid !== $seulShop) { continue; }
        $x = $c['shops'][$sid] ?? $c['shops'][(string) $sid] ?? ['ff' => 0, 'avec' => 0];
        $mags[] = ['nom' => $nomShop, 'ff' => $x['ff'], 'avec' => $x['avec'],
            'taux' => $x['ff'] > 0 ? 100 * $x['avec'] / $x['ff'] : null];
    }
    usort($mags, static fn ($x, $y) => ($x['taux'] ?? 999) <=> ($y['taux'] ?? 999));
    foreach ($mags as $mg) {
        $h .= '<td width="' . (int) (100 / max(1, count($mags))) . '%" valign="top" class="tile">'
            . '<div class="k">' . $e($court($mg['nom'])) . '</div>'
            . '<div class="serif" style="font-size:14pt;margin-top:1mm;color:' . (($mg['taux'] ?? 0) >= 25 ? '#2d7a3e' : '#8D1D2C') . '">'
            . ($mg['taux'] !== null ? number_format($mg['taux'], 1, ',', ' ') . ' %' : '—')
            . ($target !== null && $mg['taux'] !== null
                ? ' <span style="font-size:8pt;color:' . ($mg['taux'] >= $target ? '#2d7a3e' : '#8D1D2C') . '">'
                  . ($mg['taux'] >= $target ? '+' : '−') . number_format(abs($mg['taux'] - $target), 1, ',', ' ') . ' pt</span>'
                : '') . '</div>'
            . '<div style="font-size:7.5pt;color:#7a736a;margin-top:.8mm">' . $mg['avec'] . ' / ' . $mg['ff'] . ' tickets<br>'
            . 'laissé au comptoir : <b style="color:#8D1D2C">' . $eur(($mg['ff'] - $mg['avec']) * $prixB) . '</b></div></td>';
    }
    $h .= '</tr></table><table class="t" cellpadding="0" cellspacing="0"><tr>'
        . '<th class="l">Vendeur·se</th><th class="l">Magasin</th><th>Tickets A</th><th>Avec B</th><th>Taux</th><th>Manqués</th><th>À la clé</th></tr>';
    $petits = ['ff' => 0, 'avec' => 0, 'n' => 0];
    foreach ($vend as $v) {
        if ($v['ff'] < 10) { $petits['ff'] += $v['ff']; $petits['avec'] += $v['avec']; $petits['n']++; continue; }
        $h .= '<tr><td class="l"><b>' . $e($v['nom']) . '</b></td><td class="l mut">' . $e($court($v['magasin'])) . '</td>'
            . '<td>' . $v['ff'] . '</td><td>' . $v['avec'] . '</td>'
            . '<td style="font-weight:bold;color:' . (($v['taux'] ?? 0) >= 25 ? '#2d7a3e' : '#8D1D2C') . '">'
            . number_format((float) $v['taux'], 1, ',', ' ') . ' %</td>'
            . '<td>' . $v['manques'] . '</td><td class="acc"><b>' . $eur($v['manques'] * $prixB) . '</b></td></tr>';
    }
    if ($petits['n'] > 0) {
        $h .= '<tr class="gris"><td class="l" colspan="2">' . $petits['n'] . ' personne(s) sous 10 tickets — cumulées</td>'
            . '<td>' . $petits['ff'] . '</td><td>' . $petits['avec'] . '</td>'
            . '<td>' . ($petits['ff'] > 0 ? number_format(100 * $petits['avec'] / $petits['ff'], 1, ',', ' ') : '—') . ' %</td>'
            . '<td>' . ($petits['ff'] - $petits['avec']) . '</td><td>' . $eur(($petits['ff'] - $petits['avec']) * $prixB) . '</td></tr>';
    }
    $h .= '</table>'
        . '<div class="methode"><b style="color:#221E1A">Comment lire.</b> Taux d’attache = tickets contenant ' . $e($a['lib'])
        . ' ET ' . $e($b['lib']) . ' ÷ tickets contenant ' . $e($a['lib']) . '. Le croisement est asymétrique, et c’est voulu. '
        . '« À la clé » = manqués × prix moyen de B réellement encaissé — un plafond de geste, pas une promesse. '
        . 'Sous 10 tickets dans le mois : cumulé plutôt qu’affiché. Les tickets sans vendeur comptent au magasin, jamais aux personnes.</div></div>';

    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Croisement</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => $seulShop !== '' ? $nomDe[$seulShop] : 'Réseau',
        'rapport' => $a['lib'] . ' × ' . $b['lib'] . ' — ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="croisement-' . mktSlug($a['lib'] . '-' . $b['lib'])
        . ($dp['cle'] !== '' ? '-' . $dp['cle'] : '')
        . ($seulShop !== '' ? '-' . mktSlug($court($nomDe[$seulShop])) : '') . '-' . $m . '.pdf"');
    echo $pdf;
    exit;
}

/**
 * GET /croisements/rapport.pdf[?m=2026-07] — TOUS les combos enregistrés,
 * une page réseau puis une page par magasin.
 *
 * C'est le rapport de réunion : la bibliothèque entière sur une feuille —
 * chaque combo avec son daypart, sa target, son taux du mois, l'écart et les
 * euros laissés — puis la même lecture resserrée sur chaque magasin, la
 * feuille de SON brief. Les mois révolus viennent du cache : un combo jamais
 * ouvert paie sa première lecture ici, les suivants non.
 */
function ep_croisements_rapport(): array
{
    croisTables();
    $m = trim((string) ($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m', strtotime('first day of last month')); }
    $combos = [];
    try { $combos = Db::rows('SELECT * FROM ceo_combo ORDER BY a_lib, b_lib, dp'); } catch (Throwable $e) {}
    if ($combos === []) { http_response_code(422); return ['error' => 'aucun combo enregistré — enregistrez-en depuis l’écran Croisements']; }

    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $pcs = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', ' ') . ' %';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();
    $libMois = strftime_fr(strtotime($m . '-01'), 'M Y');

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    // Chaque combo : le mois demandé, par magasin et réseau.
    $donnees = [];
    foreach ($combos as $cb) {
        $a = croisIds((string) $cb['a_sel']); $b = croisIds((string) $cb['b_sel']);
        if ($a === null || $b === null) { continue; }
        $dp = croisDaypart((string) ($cb['dp'] ?? ''));
        $c = croisMoisServi((string) $cb['a_sel'], (string) $cb['b_sel'], $a['ids'], $b['ids'], $m, $nomDe, $dp['cle']);
        if ($c === null) { continue; }
        $prixB = $c['qB'] > 0 ? $c['caB'] / $c['qB'] : 0.0;
        $parShop = []; $ffT = 0; $avecT = 0;
        foreach ($nomDe as $sid => $n2) {
            $x = $c['shops'][$sid] ?? $c['shops'][(string) $sid] ?? ['ff' => 0, 'avec' => 0];
            $parShop[(string) $sid] = $x;
            $ffT += $x['ff']; $avecT += $x['avec'];
        }
        $donnees[] = ['lib' => $cb['a_lib'] . ' × ' . $cb['b_lib'],
            'dp' => $dp['lib'], 'surnom' => (string) ($cb['surnom'] ?? ''),
            'target' => isset($cb['target']) && $cb['target'] !== null ? (float) $cb['target'] : null,
            'prixB' => $prixB, 'parShop' => $parShop,
            'ff' => $ffT, 'avec' => $avecT,
            'taux' => $ffT > 0 ? round(100 * $avecT / $ffT, 1) : null,
            'eur' => (int) round(($ffT - $avecT) * $prixB)];
    }
    if ($donnees === []) { http_response_code(503); return ['error' => 'aucun combo n’a pu être calculé']; }

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif",serif}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}
      table.t{width:100%;border-collapse:collapse;margin-bottom:4mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.4pt;text-align:right;padding:1.5mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .t .l{text-align:left}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
    </style>';
    $entete = static fn (string $droite) =>
        '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Croisements — le rapport des combos<br>' . $droite . '</td></tr></table>';

    $tableau = static function (?string $sid) use ($donnees, $e, $eur, $pcs): string {
        $h2 = '<table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Combo</th><th class="l">Daypart</th><th>Target</th><th>Taux</th><th>Δ target</th>'
            . '<th>Tickets A</th><th>Laissé au comptoir</th></tr>';
        foreach ($donnees as $d2) {
            if ($sid === null) { $ff = $d2['ff']; $avec = $d2['avec']; }
            else { $x = $d2['parShop'][$sid] ?? ['ff' => 0, 'avec' => 0]; $ff = $x['ff']; $avec = $x['avec']; }
            $taux = $ff > 0 ? round(100 * $avec / $ff, 1) : null;
            $delta = ($d2['target'] !== null && $taux !== null) ? $taux - $d2['target'] : null;
            $h2 .= '<tr><td class="l"><b>' . $e($d2['lib']) . '</b>'
                . ($d2['surnom'] !== '' ? ' <span class="mut" style="font-size:7pt">' . $e($d2['surnom']) . '</span>' : '') . '</td>'
                . '<td class="l mut" style="font-size:7.5pt">' . ($d2['dp'] !== '' ? $e($d2['dp']) : 'toute la journée') . '</td>'
                . '<td class="mut">' . ($d2['target'] !== null ? $pcs($d2['target']) : '—') . '</td>'
                . '<td style="font-weight:bold">' . $pcs($taux) . '</td>'
                . '<td style="font-weight:bold;color:' . ($delta === null ? '#7a736a' : ($delta >= 0 ? '#2d7a3e' : '#8D1D2C')) . '">'
                . ($delta === null ? '—' : ($delta >= 0 ? '+ ' : '− ') . number_format(abs($delta), 1, ',', ' ') . ' pt') . '</td>'
                . '<td>' . number_format($ff, 0, ',', ' ') . '</td>'
                . '<td class="acc"><b>' . $eur(($ff - $avec) * $d2['prixB']) . '</b></td></tr>';
        }
        return $h2 . '</table>';
    };

    $h = $css . '<div class="doc">' . $entete($e($libMois))
        . '<div class="serif" style="font-size:19pt;margin:4mm 0 1mm">Le réseau — tous les combos</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">' . count($donnees) . ' combo(s) enregistré(s) · '
        . $e($libMois) . ' · taux = tickets A contenant aussi B ÷ tickets A.</div>'
        . $tableau(null);
    foreach ($nomDe as $sid => $nom) {
        $h .= '<div style="page-break-before:always">' . $entete($e($court($nom)) . ' · ' . $e($libMois))
            . '<div class="serif" style="font-size:19pt;margin:4mm 0 1mm">' . $e($court($nom)) . ' — ses combos</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">La même lecture, resserrée sur ce magasin — la feuille de son brief.</div>'
            . $tableau((string) $sid) . '</div>';
    }
    $h .= '<div class="methode"><b style="color:#221E1A">Comment lire.</b> Chaque ligne est un combo enregistré — le croisement est asymétrique (tickets A avec B, jamais l’inverse), '
        . 'le daypart borne les tickets à ce moment de la journée, la target est celle du combo. '
        . '« Laissé au comptoir » = tickets manqués × prix moyen de B réellement encaissé ce mois-là — un plafond de geste, pas une promesse, '
        . 'et les euros ne s’additionnent pas d’un combo à l’autre : un même ticket peut manquer deux combos.</div></div>';

    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Combos — ' . $e($libMois) . '</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => 'Croisements — tous les combos, ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="combos-' . $m . '.pdf"');
    echo $pdf;
    exit;
}
