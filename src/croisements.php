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
function croisCalcul(array $idsA, array $idsB, string $mois): ?array
{
    [$du, $au] = venteBornes($mois);
    $in = static fn (array $ids) => implode(',', array_map('intval', $ids));
    $ticketsA = []; $avecB = []; $caB = 0.0; $qB = 0.0;
    try {
        foreach (Db::rows('SELECT DISTINCT t.id, t.id_shop
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                              AND l.id_product IN (' . $in($idsA) . ')', [$du, $au]) as $r) {
            $ticketsA[(int) $r['id']] = (string) $r['id_shop'];
        }
        foreach (Db::rows('SELECT t.id, SUM(l.total_gross_value_after_discount) ca, SUM(l.quantity) q
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
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
function croisMoisServi(string $aSel, string $bSel, array $idsA, array $idsB, string $mois, array $nomDe): ?array
{
    croisTables();
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
    $c = croisCalcul($idsA, $idsB, $mois);
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
                'surnom' => (string) ($r['surnom'] ?? '')];
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
        'mois' => [], 'reseau' => [], 'magasins' => [], 'prixB' => null, 'motif' => null];

    $parMois = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $m = date('Y-m', $t);
        $out['mois'][] = ['cle' => $m, 'lib' => strftime_fr($t, 'M'), 'encours' => $m === date('Y-m')];
        $parMois[$m] = croisMoisServi($aSel, $bSel, $a['ids'], $b['ids'], $m, $nomDe);
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
    $in = static fn (array $ids) => implode(',', array_map('intval', $ids));
    $emp = venteEmployes();
    $tickets = []; $avecB = [];
    try {
        foreach (Db::rows('SELECT DISTINCT t.id, t.id_employee
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                              AND t.id_shop = ? AND l.id_product IN (' . $in($a['ids']) . ')', [$du, $au, $shop]) as $r) {
            $tickets[(int) $r['id']] = $r['id_employee'] !== null ? (int) $r['id_employee'] : null;
        }
        foreach (Db::rows('SELECT DISTINCT t.id
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
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
    $dej = Db::row('SELECT id FROM ceo_combo WHERE a_sel = ? AND b_sel = ?', [$aSel, $bSel]);
    if ($dej !== null) { http_response_code(409); return ['error' => 'ce combo est déjà enregistré']; }
    Db::exec('INSERT INTO ceo_combo (a_sel, a_lib, b_sel, b_lib, surnom, cree_le) VALUES (?,?,?,?,?,?)',
        [$aSel, mb_substr(trim((string) ($b['aLib'] ?? $aSel)), 0, 160),
         $bSel, mb_substr(trim((string) ($b['bLib'] ?? $bSel)), 0, 160),
         mb_substr(trim((string) ($b['surnom'] ?? '')), 0, 120) ?: null, date('Y-m-d')]);
    journalAdd('CEO', 'Croisement', trim((string) ($b['aLib'] ?? $aSel)) . ' × ' . trim((string) ($b['bLib'] ?? $bSel)), 'Combo enregistré');
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
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    $c = croisMoisServi($aSel, $bSel, $a['ids'], $b['ids'], $m, $nomDe);
    if ($c === null) { http_response_code(503); return ['error' => 'lecture des tickets impossible']; }
    $prixB = $c['qB'] > 0 ? $c['caB'] / $c['qB'] : 0.0;
    $libMois = strftime_fr(strtotime($m . '-01'), 'M Y');
    $logo = rapLogoDataUri();

    // Les vendeuses, tous magasins.
    $vend = [];
    foreach ($nomDe as $sid => $nomShop) {
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
        . '<div class="serif" style="font-size:18pt;margin:4mm 0 1mm">' . $e($a['lib']) . ' × ' . $e($b['lib']) . '</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Sur les tickets contenant ' . $e($a['lib'])
        . ' : la part contenant aussi ' . $e($b['lib']) . '. Prix moyen de B encaissé ce mois-ci : '
        . number_format($prixB, 2, ',', ' ') . ' €.</div>'
        . '<table width="100%" cellpadding="0" cellspacing="4" style="margin:0 -1mm 4mm"><tr>';
    $mags = [];
    foreach ($nomDe as $sid => $nomShop) {
        $x = $c['shops'][(string) $sid] ?? ['ff' => 0, 'avec' => 0];
        $mags[] = ['nom' => $nomShop, 'ff' => $x['ff'], 'avec' => $x['avec'],
            'taux' => $x['ff'] > 0 ? 100 * $x['avec'] / $x['ff'] : null];
    }
    usort($mags, static fn ($x, $y) => ($x['taux'] ?? 999) <=> ($y['taux'] ?? 999));
    foreach ($mags as $mg) {
        $h .= '<td width="' . (int) (100 / max(1, count($mags))) . '%" valign="top" class="tile">'
            . '<div class="k">' . $e($court($mg['nom'])) . '</div>'
            . '<div class="serif" style="font-size:14pt;margin-top:1mm;color:' . (($mg['taux'] ?? 0) >= 25 ? '#2d7a3e' : '#8D1D2C') . '">'
            . ($mg['taux'] !== null ? number_format($mg['taux'], 1, ',', ' ') . ' %' : '—') . '</div>'
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
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => $a['lib'] . ' × ' . $b['lib'] . ' — ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="croisement-' . mktSlug($a['lib'] . '-' . $b['lib']) . '-' . $m . '.pdf"');
    echo $pdf;
    exit;
}
