<?php
declare(strict_types=1);

/**
 * La vue « app client » de la fidélité, EN DIRECT (18/11/2026, Sam : « faire un test en live (Halle, client Vincent)
 * sur le serveur, une page indépendante »). Chargé QUE par public/fidelite-app/api.php : ni le cockpit, ni le
 * dashboard, ni la page de test public/fidelite-test ne le lisent.
 *
 * LECTURE SEULE : des GET à l'API de l'ERP, par le client du module bingo (fidErp / fidErpTous de
 * src/fidelite_bingo.php, compte admin de l'ERP). Aucune table, aucune écriture, aucun bon émis.
 *
 * Les règles sont celles du module : la case de la semaine se coche dès `seuilEuros` € d'achats du lundi au dimanche,
 * tous magasins (`shop_activity.gross_sales_before_corrections` de GET /shops/{id}/loyalty/customers) ; un bon toutes
 * les `tousLes` cases ; 13 / 13, le super bon. Le calendrier vient de src/data/fidelite_bingo.json.
 *
 * Avant le début de la saison (l'hiver commence le 23/11/2026), l'ERP refuse une période qui commence après
 * aujourd'hui (HTTP 422). La page REJOUE alors la grille de la saison sur les 13 dernières semaines : la semaine en
 * cours est la 13e. Les achats sont réels, les thèmes sont ceux de la saison à venir.
 *
 * Données personnelles : le prénom et l'initiale du nom, comme le module ; jamais d'e-mail ni de téléphone ; les codes
 * de bons sont masqués (trois premiers caractères).
 */

const FAP_SEMAINES = 13;

/** La saison affichée : la vraie si elle a commencé, sinon le rejeu sur les 13 dernières semaines. */
function fapFenetre(): array
{
    $jour = fidAujourdhui();
    $vraie = fidSaison($jour);
    if ($vraie !== null && $vraie['enCours'] > 0) { return ['saison' => $vraie, 'rejeu' => false]; }
    $brut = null;
    foreach ((array) (fidBingoConf()['saisons'] ?? []) as $s) { if ($vraie !== null && (string) $s['id'] === $vraie['id']) { $brut = $s; } }
    if ($brut === null) { return ['saison' => $vraie, 'rejeu' => false]; }
    $dow = (int) gmdate('N', (int) strtotime($jour . ' 12:00:00 UTC'));     // 1 = lundi
    $lundi = fidPlus($jour, 1 - $dow);
    $brut['du'] = fidPlus($lundi, -7 * (count($brut['semaines']) - 1));
    $vue = fidSaisonVue($brut, $jour);
    return ['saison' => $vue, 'rejeu' => true, 'vraie' => ['nom' => $vraie['nom'], 'du' => $vraie['du'], 'au' => $vraie['au']]];
}

/** Le nom affiché : prénom et initiale. */
function fapNom(array $f): string
{
    $n = trim((string) ($f['name'] ?? '')); $s = trim((string) ($f['surname'] ?? ''));
    return trim($n . ' ' . ($s !== '' ? mb_substr($s, 0, 1) . '.' : '')) ?: 'client n° ' . (int) ($f['id'] ?? 0);
}

/** Un code masqué : ses trois premiers caractères. */
function fapMasque(string $c): string { return $c === '' ? '' : mb_substr($c, 0, 3) . str_repeat('•', max(0, mb_strlen($c) - 3)); }

/** Le texte cherché : lettres, chiffres, espaces, tirets ; 60 caractères au plus. */
function fapRecherche(): string
{
    $q = trim((string) preg_replace('/[^\p{L}\p{N} \'’-]/u', '', (string) ($_GET['q'] ?? '')));
    return mb_substr($q, 0, 60);
}

/** Le programme (carte à points) et le journal des appels. */
function fapCarte(array &$journal): ?array
{
    $t = microtime(true); $err = null;
    $cartes = fidCartesErp($err);
    $journal[] = ['route' => 'GET /admin/loyalty/cards', 'ok' => $err === null, 'note' => $err ?? (count($cartes) . ' carte(s)'), 'ms' => (int) round(1000 * (microtime(true) - $t))];
    $p = fidCarteProgramme($cartes);
    return $p ? fidCarteVue($p) : null;
}

/** GET /etat — la carte de l'ERP, les magasins, la fenêtre (vraie saison ou rejeu), les règles. */
function ep_fap_etat(): array
{
    $journal = [];
    $carte = fapCarte($journal);
    $t = microtime(true); $mags = fidMagasins();
    $journal[] = ['route' => 'GET /admin/loyalty/options', 'ok' => (bool) $mags, 'note' => count($mags) . ' magasin(s)', 'ms' => (int) round(1000 * (microtime(true) - $t))];
    $f = fapFenetre(); $c = fidBingoConf();
    return ['aujourdhui' => fidAujourdhui(), 'carte' => $carte, 'magasins' => $mags, 'rejeu' => $f['rejeu'], 'vraie' => $f['vraie'] ?? null,
        'saison' => $f['saison'] ? ['id' => $f['saison']['id'], 'nom' => $f['saison']['nom'], 'du' => $f['saison']['du'], 'au' => $f['saison']['au'], 'enCours' => $f['saison']['enCours']] : null,
        'regles' => ['seuil' => (float) $c['seuilEuros'], 'tousLes' => (int) $c['tousLes'], 'validite' => (int) $c['validite'], 'superBon' => $c['superBon']['valeur'] ?? null],
        'journal' => $journal];
}

/** GET /clients?shop=&q= — les membres de la carte d'un magasin, trouvés par leur nom (13 dernières semaines). */
function ep_fap_clients(): array
{
    $shop = (int) ($_GET['shop'] ?? 0); $q = fapRecherche();
    if ($shop <= 0 || $q === '') { http_response_code(400); return ['error' => 'magasin et nom requis']; }
    $journal = [];
    $carte = fapCarte($journal);
    if ($carte === null) { return ['clients' => [], 'journal' => $journal, 'erreur' => 'aucune carte à points active dans l’ERP']; }
    $f = fapFenetre(); $du = $f['saison']['du']; $au = fidAujourdhui();
    $route = '/shops/' . $shop . '/loyalty/customers?card_id=' . $carte['id'] . '&search=' . rawurlencode($q) . '&from=' . $du . '&to=' . $au . '&limit=20';
    $t = microtime(true); $r = fidErp('GET', $route);
    $journal[] = ['route' => 'GET ' . $route, 'ok' => $r['code'] >= 200 && $r['code'] < 300, 'note' => 'HTTP ' . $r['code'] . ($r['erreur'] ? ' · ' . $r['erreur'] : ''), 'ms' => (int) round(1000 * (microtime(true) - $t))];
    $out = [];
    foreach (fidItems($r['corps']) as $c) {
        if (!is_array($c) || (int) ($c['id'] ?? 0) <= 0) { continue; }
        $prog = fidProgrammeDe($c, (int) $carte['id']);
        $out[] = ['id' => (int) $c['id'], 'nom' => fapNom($c), 'solde' => (int) ($prog['global_progress']['points']['balance'] ?? 0),
            'achats' => round((float) ($c['shop_activity']['gross_sales_before_corrections'] ?? 0), 2),
            'tickets' => (int) ($c['shop_activity']['transactions'] ?? 0), 'dernier' => $c['shop_activity']['last_transaction_at'] ?? null];
    }
    return ['clients' => $out, 'journal' => $journal];
}

/** GET /carte?id=&q= — la carte d'un client : ses 13 semaines (tous magasins), ses points, ses bons. */
function ep_fap_carte(): array
{
    $id = (int) ($_GET['id'] ?? 0); $q = fapRecherche();
    if ($id <= 0) { http_response_code(400); return ['error' => 'id du client manquant']; }
    $journal = [];
    $carte = fapCarte($journal);
    if ($carte === null) { return ['erreur' => 'aucune carte à points active dans l’ERP', 'journal' => $journal]; }
    $f = fapFenetre(); $S = $f['saison']; $conf = fidBingoConf(); $seuil = (float) $conf['seuilEuros']; $x = max(1, (int) $conf['tousLes']);
    $shops = array_column(fidMagasins(), 'id');
    $jour = fidAujourdhui();
    $n = $S['enCours'] ?: $S['closes'];
    $paths = [];
    for ($w = 1; $w <= $n; $w++) {
        $sem = $S['semaines'][$w - 1]; $au = min($sem['au'], $jour);
        foreach ($shops as $s) {
            $paths[$w . '|' . $s] = '/shops/' . $s . '/loyalty/customers?card_id=' . $carte['id'] . ($q !== '' ? '&search=' . rawurlencode($q) : '') . '&from=' . $sem['du'] . '&to=' . $au . '&limit=100';
        }
    }
    $t = microtime(true); $R = $paths ? fidErpTous($paths) : [];
    $vides = count(array_filter($R, static fn ($c) => $c === null));
    $journal[] = ['route' => 'GET /shops/{id}/loyalty/customers?card_id=' . $carte['id'] . '&search=…&from=&to= × ' . count($paths), 'ok' => $vides === 0,
        'note' => count($paths) . ' appels (' . $n . ' semaines × ' . count($shops) . ' magasins)' . ($vides ? ', ' . $vides . ' sans réponse' : ''), 'ms' => (int) round(1000 * (microtime(true) - $t))];
    $sem = array_fill(1, $n, ['euros' => 0.0, 'points' => 0, 'tickets' => 0, 'magasins' => []]);
    $nom = null; $gp = null; $gpW = 0; $dernier = null; $mags = [];
    foreach ($R as $k => $corps) {
        [$w, $s] = array_map('intval', explode('|', $k));
        foreach (fidItems($corps) as $c) {
            if (!is_array($c) || (int) ($c['id'] ?? 0) !== $id) { continue; }
            $nom ??= fapNom($c);
            $e = (float) ($c['shop_activity']['gross_sales_before_corrections'] ?? 0);
            $prog = fidProgrammeDe($c, (int) $carte['id']);
            $sem[$w]['euros'] += $e; $sem[$w]['tickets'] += (int) ($c['shop_activity']['transactions'] ?? 0);
            $sem[$w]['points'] += (int) ($prog['shop_activity']['points_earned'] ?? 0);
            if ($e > 0) { $sem[$w]['magasins'][$s] = true; $mags[$s] = true; }
            if ($w >= $gpW && is_array($prog['global_progress']['points'] ?? null)) { $gp = $prog['global_progress']['points']; $gpW = $w; }
            $d = $c['shop_activity']['last_transaction_at'] ?? null;
            if (is_string($d) && ($dernier === null || $d > $dernier)) { $dernier = $d; }
        }
    }
    if ($nom === null) { http_response_code(404); return ['error' => 'client ' . $id . ' introuvable sur la carte ' . $carte['code'] . ' pendant ces 13 semaines', 'journal' => $journal]; }
    $semaines = []; $cases = 0; $manquees = 0;
    foreach ($S['semaines'] as $v) {
        $w = $v['n']; $d = $sem[$w] ?? null;
        $coche = $d !== null && $d['euros'] >= $seuil - 0.001; if ($coche) { $cases++; }
        $manquee = $v['etat'] === 'passee' && !$coche; if ($manquee) { $manquees++; }
        $semaines[] = ['n' => $w, 'du' => $v['du'], 'au' => $v['au'], 'etat' => $v['etat'], 'titre' => $v['titre'], 'sous' => $v['sous'], 'fete' => $v['fete'], 'ill' => $v['ill'],
            'euros' => $d ? round($d['euros'], 2) : null, 'points' => $d['points'] ?? null, 'tickets' => $d['tickets'] ?? null, 'coche' => $coche, 'manquee' => $manquee];
    }
    $dus = fidBonsSaison($S);
    if (!empty($conf['superBon'])) { $dus[] = ['k' => count($S['semaines']), 'valeur' => (float) $conf['superBon']['valeur'], 'nom' => 'Super bon cadeau', 'motif' => 'cake', 'super' => true]; }
    $t = microtime(true); $camp = fidCampagnes($S['campagne']);
    $journal[] = ['route' => 'GET /admin/voucher-campaigns?name=' . $S['campagne'], 'ok' => true, 'note' => count($camp) ? count($camp) . ' campagne(s)' : 'aucune campagne « ' . $S['campagne'] . ' » : les bons restent en simulation', 'ms' => (int) round(1000 * (microtime(true) - $t))];
    $codes = [];
    foreach ($camp as $cp) {
        $r = fidErp('GET', '/admin/voucher-campaigns/' . $cp['id'] . '/codes?limit=50&id_customer=' . $id);
        foreach (fidItems($r['corps']) as $k) { if (is_array($k) && (int) ($k['id_customer'] ?? 0) === $id) { $codes[] = ['code' => fapMasque((string) ($k['code'] ?? '')), 'statut' => (string) ($k['status'] ?? ''), 'jusquau' => $k['valid_to'] ?? null, 'campagne' => $cp['nom'] ?? '']; } }
    }
    $bons = array_map(static fn ($b) => $b + ['gagne' => $cases >= $b['k'], 'reste' => max(0, $b['k'] - $cases), 'possible' => count($S['semaines']) - $manquees >= $b['k']], $dus);
    $cours = $S['enCours'];
    $paliers = [];
    foreach ((array) ($gp['tiers'] ?? []) as $p) { if (is_array($p)) { $paliers[] = ['points' => (int) ($p['points'] ?? 0), 'valeur' => (int) ($p['voucher_cents'] ?? 0) / 100, 'atteint' => !empty($p['reached']), 'reste' => (int) ($p['remaining'] ?? 0)]; } }
    return ['aujourdhui' => $jour, 'rejeu' => $f['rejeu'], 'vraie' => $f['vraie'] ?? null,
        'saison' => ['id' => $S['id'], 'nom' => $S['nom'], 'du' => $S['du'], 'au' => $S['au'], 'enCours' => $cours, 'campagne' => $S['campagne']],
        'regles' => ['seuil' => $seuil, 'tousLes' => $x, 'validite' => (int) $conf['validite']],
        'carte' => ['id' => $carte['id'], 'code' => $carte['code'], 'nom' => $carte['nom'], 'points' => $carte['points']],
        'client' => ['id' => $id, 'nom' => $nom, 'magasins' => array_keys($mags), 'dernier' => $dernier, 'cases' => $cases, 'manquees' => $manquees,
            'points' => ['solde' => (int) ($gp['balance'] ?? 0), 'gagnes' => (int) ($gp['earned_total'] ?? 0), 'echanges' => (int) ($gp['spent_total'] ?? 0), 'expires' => (int) ($gp['expired_remaining'] ?? 0), 'paliers' => $paliers],
            'semaine' => $cours ? ['n' => $cours, 'euros' => round($sem[$cours]['euros'] ?? 0, 2), 'reste' => max(0, round($seuil - ($sem[$cours]['euros'] ?? 0), 2))] : null],
        'semaines' => $semaines, 'bons' => $bons, 'codes' => $codes, 'journal' => $journal];
}
