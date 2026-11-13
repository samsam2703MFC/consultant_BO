<?php
declare(strict_types=1);

/**
 * PAGE DE TEST À PART (13/11/2026, Sam : « c'est une page à part, ne touche pas au dashboard, c'est un test externe
 * au dashboard »). Ce module n'est chargé QUE par public/fidelite-test/api.php : ni l'API du cockpit
 * (public/api/index.php) ni l'écran Fidélité du dashboard ne le lisent. Il reprend le module de la PR
 * samsam2703MFC/consultant_BO#103 (src/fidelite.php de la branche claude/fidelity-program-mockups-mbilso), à
 * l'identique, sous un autre nom de fichier pour ne pas croiser l'ancien src/fidelite.php encore en production.
 */

/**
 * Fidélité — ERP franchisé › Fidélité (#/fidelity). Réécrit le 13/11/2026, à la demande de Sam :
 *   « ça ne marche qu'avec l'API : pas depuis une table, uniquement depuis l'API » ;
 *   « on ne garderait que la carte à points, fusionnée avec le bingo » ;
 *   « nous faisons un mix entre le bingo et les points ».
 * Maquettes : docs/maquettes/fidelite-bingo.
 *
 * La structure (Sam, 13/11/2026) : UNE carte de fidélité à points, permanente, dans l'ERP (TF Buddy : `points_config`,
 * tranches, ×2, coefficients par magasin, paliers d'échange, validités), et QUATRE cartes de bingo par an, une par
 * saison de 13 semaines (src/data/fidelite_bingo.json). Le handoff de Sam (docs/maquettes/fidelite-bingo/handoff) fait
 * foi : le bingo fait revenir — la case de la semaine se coche dès `seuilEuros` € d'achats du lundi au dimanche (tous
 * magasins, une case par semaine) ; toutes les `tousLes` cases, un bon (un code de la campagne de la saison, au nom du
 * client) ; 13 / 13, le super bon. Les points font consommer : la carte de l'ERP, ×`multiplicateurCase` une fois la
 * case cochée — ce ×2 par client, comme l'échange automatique au palier, n'existe pas dans l'API : l'écran le dit.
 * Les cartes à tampons s'arrêtent.
 *
 * Le cockpit ne garde RIEN du programme ni des clients : aucune table. Tout se lit et s'écrit par l'API de l'ERP
 * (client ErpApi, compte admin) :
 *  - GET  /fidelite             la carte (GET /admin/loyalty/cards), les magasins (GET /admin/loyalty/options), les
 *                               cartes à arrêter, le bingo de la saison et ses campagnes (GET /admin/voucher-campaigns) ;
 *  - GET  /fidelite/clients     la récurrence : GET /shops/{id}/loyalty/statistics, et GET /shops/{id}/loyalty/customers
 *                               semaine par semaine (les points gagnés sur la carte font les cases) ;
 *  - POST /fidelite/simuler     POST /admin/loyalty/simulate — rien n'est écrit ;
 *  - POST /fidelite/publier     PATCH /admin/loyalty/cards/{id} avec sa revision ; carte figée (`terms_locked`) : POST de
 *                               la version suivante et PATCH de la fin de l'ancienne ; fin des cartes à arrêter ;
 *  - POST /fidelite/bingo/bons  les bons dus par le bingo, en simulation par défaut ; `ecrire: true` les crée
 *                               (POST /admin/voucher-campaigns/{id}/codes) ; GET /fidelite/cron?jeton= le fait chaque
 *                               lundi pour les campagnes ACTIVES dans l'ERP.
 *
 * Seuls restent ici : le calendrier de la saison (src/data/fidelite_bingo.json : semaines, thèmes, dessins, valeurs
 * des bons) et un cache des réponses de l'API (fichiers temporaires, jamais une source) pour ne pas relire les semaines
 * closes à chaque ouverture.
 */

const FID_TTL_CLOSE = 86400;     // une semaine close se relit au plus une fois par jour
const FID_TTL_COURS = 600;       // la semaine en cours, toutes les dix minutes
const FID_PAGE = 100;            // clients par page (le plafond de l'API)
const FID_FRONT = 6;             // requêtes de front

/* ---------------------------------------------------------------------------
 * L'accès à l'ERP (un point d'entrée : les tests y branchent un faux ERP)
 * ------------------------------------------------------------------------- */
/** Une requête : {code, corps, erreur}. `$GLOBALS['FID_ERP']` (callable) remplace l'ERP dans les tests et les captures. */
function fidErp(string $method, string $path, ?array $body = null): array
{
    if (isset($GLOBALS['FID_ERP']) && is_callable($GLOBALS['FID_ERP'])) { return ($GLOBALS['FID_ERP'])($method, $path, $body); }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return ['code' => 0, 'corps' => null, 'erreur' => 'compte admin ERP non configuré (Paramètres)']; }
    return $method === 'GET' ? ErpApi::sondeGet($path) : ErpApi::ecrire($method, $path, $body);
}

/** Plusieurs GET de front : [clé => corps | null]. */
function fidErpTous(array $paths): array
{
    if (isset($GLOBALS['FID_ERP']) && is_callable($GLOBALS['FID_ERP'])) {
        $out = [];
        foreach ($paths as $k => $p) { $r = ($GLOBALS['FID_ERP'])('GET', $p, null); $out[$k] = $r['code'] >= 200 && $r['code'] < 300 ? $r['corps'] : null; }
        return $out;
    }
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { return array_fill_keys(array_keys($paths), null); }
    return ErpApi::getParallele($paths, FID_FRONT);
}

/** La liste d'une réponse : `items`, `data.items`, `data`, ou le corps. */
function fidItems(mixed $corps): array
{
    if (!is_array($corps)) { return []; }
    foreach ([$corps['items'] ?? null, $corps['data']['items'] ?? null, $corps['data'] ?? null, $corps] as $n) {
        if (is_array($n) && array_is_list($n)) { return $n; }
    }
    return [];
}
/** L'objet d'une réponse : `data` s'il est un objet, sinon le corps. */
function fidObjet(mixed $corps): array
{
    if (!is_array($corps)) { return []; }
    return is_array($corps['data'] ?? null) && !array_is_list($corps['data']) ? $corps['data'] : $corps;
}

/* Le cache des réponses (fichiers temporaires). Il ne fait jamais foi : vide, tout se relit dans l'ERP. */
function fidCacheDossier(): string
{
    $d = sys_get_temp_dir() . '/cockpit-fidelite';
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d;
}
function fidCacheLire(string $cle, int $ttl): mixed
{
    if (!empty($GLOBALS['FID_SANS_CACHE'])) { return null; }
    $f = fidCacheDossier() . '/' . sha1($cle) . '.json';
    if (!is_file($f) || filemtime($f) < time() - $ttl) { return null; }
    $v = json_decode((string) file_get_contents($f), true);
    return is_array($v) ? $v : null;
}
function fidCacheEcrire(string $cle, mixed $val): void
{
    if (!empty($GLOBALS['FID_SANS_CACHE']) || !is_array($val)) { return; }
    @file_put_contents(fidCacheDossier() . '/' . sha1($cle) . '.json', json_encode($val), LOCK_EX);
}

/* ---------------------------------------------------------------------------
 * Le temps (heure du magasin, Europe/Bruxelles)
 * ------------------------------------------------------------------------- */
function fidAujourdhui(): string { return (string) ($GLOBALS['FID_AUJOURDHUI'] ?? date('Y-m-d')); }
function fidHeureLocale(): string { return (string) ($GLOBALS['FID_HEURE'] ?? date('H:i')); }
function fidPlus(string $d, int $jours): string { return gmdate('Y-m-d', (int) strtotime($d . ' 12:00:00 UTC') + $jours * 86400); }
function fidEcart(string $a, string $b): int { return (int) round(((int) strtotime($b . ' 12:00:00 UTC') - (int) strtotime($a . ' 12:00:00 UTC')) / 86400); }
/** Minuit (heure de Bruxelles) d'un jour, en UTC au format de l'API. */
function fidUtc(string $jour, string $heure = '00:00:00'): string
{
    $d = new DateTimeImmutable($jour . ' ' . $heure, new DateTimeZone('Europe/Brussels'));
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/* ---------------------------------------------------------------------------
 * Le bingo : la saison (calendrier) et ses règles
 * ------------------------------------------------------------------------- */
function fidBingoConf(): array
{
    static $c = null;
    if ($c === null) {
        $c = json_decode((string) @file_get_contents(__DIR__ . '/data/fidelite_bingo.json'), true);
        if (!is_array($c)) { $c = ['seuilEuros' => 10, 'tousLes' => 3, 'validite' => 30, 'bons' => [], 'superBon' => null, 'saisons' => []]; }
    }
    return $c;
}

/** Une saison du calendrier, vue un jour donné : ses semaines, chacune passée, en cours ou à venir. */
function fidSaisonVue(array $s, string $jour): array
{
    $n = count($s['semaines']);
    $sem = []; $cours = 0;
    foreach ($s['semaines'] as $i => $w) {
        $du = fidPlus((string) $s['du'], 7 * $i); $au = fidPlus($du, 6);
        $etat = $au < $jour ? 'passee' : ($du <= $jour ? 'cours' : 'avenir');
        if ($etat === 'cours') { $cours = $i + 1; }
        $sem[] = ['n' => $i + 1, 'du' => $du, 'au' => $au, 'titre' => (string) $w['titre'], 'sous' => (string) ($w['sous'] ?? ''),
            'fete' => isset($w['fete']) ? (string) $w['fete'] : null, 'ill' => (string) ($w['ill'] ?? ''), 'etat' => $etat];
    }
    $closes = count(array_filter($sem, static fn ($w) => $w['etat'] === 'passee'));
    $fin = fidPlus((string) $s['du'], 7 * $n - 1);
    return ['id' => (string) $s['id'], 'nom' => (string) $s['nom'], 'du' => (string) $s['du'], 'au' => $fin,
        'ill' => (string) ($s['ill'] ?? ''), 'tirage' => $s['tirage'] ?? null, 'nomsBons' => $s['bons'] ?? [], 'semaines' => $sem,
        'campagne' => (string) ($s['campagne'] ?? (fidBingoConf()['prefixeCampagne'] ?? 'Bingo')), 'aValider' => !empty($s['aValider']),
        'etat' => $fin < $jour ? 'passee' : ((string) $s['du'] <= $jour ? 'cours' : 'avenir'), 'enCours' => $cours, 'closes' => $closes];
}

/** La saison du jour (ou la prochaine, ou la dernière) ; `$id` en choisit une autre. */
function fidSaison(?string $jour = null, ?string $id = null): ?array
{
    $jour ??= fidAujourdhui();
    $S = fidBingoConf()['saisons'] ?? [];
    if (!$S) { return null; }
    if ($id !== null && $id !== '') {
        foreach ($S as $s) { if ((string) $s['id'] === $id) { return fidSaisonVue($s, $jour); } }
    }
    foreach ($S as $s) {
        if ($jour <= fidPlus((string) $s['du'], 7 * count($s['semaines']) - 1)) { return fidSaisonVue($s, $jour); }
    }
    return fidSaisonVue($S[count($S) - 1], $jour);
}

/** Les quatre cartes de bingo de l'année : la saison du jour et ses voisines du calendrier (quatre au plus). */
function fidAnnee(?string $jour = null): array
{
    $jour ??= fidAujourdhui();
    $S = array_map(static fn ($s) => fidSaisonVue($s, $jour), fidBingoConf()['saisons'] ?? []);
    $i = 0;
    foreach ($S as $k => $s) { if ($s['etat'] !== 'passee') { $i = $k; break; } $i = $k; }
    $debut = max(0, min($i, count($S) - 4));
    return array_map(static fn ($s) => ['id' => $s['id'], 'nom' => $s['nom'], 'du' => $s['du'], 'au' => $s['au'], 'ill' => $s['ill'], 'etat' => $s['etat'],
        'aValider' => $s['aValider'], 'campagne' => $s['campagne'], 'enCours' => $s['enCours']], array_slice($S, $debut, 4));
}

/** Les bons de la saison : [{k (case), valeur, nom, motif}] — toutes les x cases ; au-delà de la liste, la dernière marche se répète. */
function fidBonsSaison(array $saison): array
{
    $c = fidBingoConf(); $x = max(1, (int) $c['tousLes']); $V = array_values($c['bons'] ?? []);
    $n = intdiv(count($saison['semaines']), $x); $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $nV = count($V);
        $v = $i <= $nV ? (float) $V[$i - 1] : (float) $V[$nV - 1] + ($i - $nV) * ($nV > 1 ? (float) $V[$nV - 1] - (float) $V[$nV - 2] : (float) $V[0]);
        $nm = $saison['nomsBons'][$i - 1] ?? ['nom' => 'Bon ' . $i, 'motif' => 'cake'];
        $out[] = ['k' => $i * $x, 'valeur' => round($v, 2), 'nom' => (string) $nm['nom'], 'motif' => (string) ($nm['motif'] ?? 'cake')];
    }
    return $out;
}

/** « 4,90 € » */
function fidEur(float $v): string { return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' €'; }

/** Les campagnes d'une saison dans l'ERP : [valeur (en centimes) => {id, nom, statut}]. Une campagne par valeur, nommée « Bingo hiver · 4,90 € ». */
function fidCampagnes(?string $pre = null): array
{
    $pre ??= (string) (fidBingoConf()['prefixeCampagne'] ?? 'Bingo');
    $r = fidErp('GET', '/admin/voucher-campaigns?limit=100&name=' . rawurlencode($pre));
    $out = [];
    foreach (fidItems($r['corps']) as $c) {
        if (!is_array($c)) { continue; }
        $c = is_array($c['campaign'] ?? null) ? $c['campaign'] : $c;
        $nom = (string) ($c['name'] ?? '');
        if (mb_stripos($nom, $pre) !== 0 || !preg_match('/(\d+)(?:[,.](\d{1,2}))?\s*€/u', $nom, $m)) { continue; }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        $out[$cents] = ['id' => (int) ($c['id'] ?? 0), 'nom' => $nom, 'statut' => strtoupper((string) ($c['status'] ?? ''))];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * La carte
 * ------------------------------------------------------------------------- */
/** Les cartes de l'ERP, toutes pages. */
function fidCartesErp(?string &$erreur = null): array
{
    $out = [];
    for ($p = 1; $p <= 5; $p++) {
        $r = fidErp('GET', '/admin/loyalty/cards?limit=100&page=' . $p);
        if ($r['code'] < 200 || $r['code'] >= 300) { $erreur = $r['erreur'] ?? ('HTTP ' . $r['code']); break; }
        $L = fidItems($r['corps']);
        foreach ($L as $c) { if (is_array($c)) { $out[] = $c; } }
        $pg = is_array($r['corps']) ? ($r['corps']['pagination'] ?? null) : null;
        if (count($L) < 100 || !is_array($pg) || $p >= (int) ($pg['pages'] ?? $pg['last_page'] ?? 1)) { break; }
    }
    return $out;
}

/** Le barème de points ramené à une forme sûre (celle que l'API accepte). */
function fidPointsConfig(mixed $p): ?array
{
    if (!is_array($p) || empty($p['points_enabled'])) { return null; }
    $bon = [];
    foreach ((array) ($p['bonuses'] ?? []) as $b) {
        if (!is_array($b)) { continue; }
        $j = array_values(array_unique(array_filter(array_map('intval', (array) ($b['days'] ?? [])), static fn ($x) => $x >= 0 && $x <= 6)));
        sort($j);
        $de = (string) ($b['from'] ?? ''); $a = (string) ($b['to'] ?? '');
        if (!$j || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $de) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $a) || $a <= $de) { continue; }
        $bon[] = ['name' => mb_substr(trim((string) ($b['name'] ?? 'Bonus')) ?: 'Bonus', 0, 120), 'days' => $j, 'from' => $de, 'to' => $a,
            'coefficient' => max(1.0, min(100.0, round((float) ($b['coefficient'] ?? 1), 4)))];
    }
    $coef = [];
    foreach ((array) ($p['shop_coefficients'] ?? []) as $id => $v) {
        if ((int) $id > 0 && is_numeric($v)) { $coef[(string) (int) $id] = max(0.0, min(100.0, round((float) $v, 4))); }
    }
    $paliers = [];
    foreach ((array) ($p['tiers'] ?? []) as $t) {
        if (is_array($t) && (int) ($t['points'] ?? 0) > 0 && (int) ($t['voucher_cents'] ?? 0) > 0) { $paliers[(int) $t['points']] = (int) $t['voucher_cents']; }
    }
    ksort($paliers);
    return ['stamps_enabled' => !empty($p['stamps_enabled']), 'points_enabled' => true,
        'points_per_slice' => max(1, (int) ($p['points_per_slice'] ?? 1)), 'slice_cents' => max(1, (int) ($p['slice_cents'] ?? 100)),
        'voucher_validity_days' => max(0, (int) ($p['voucher_validity_days'] ?? 0)), 'points_validity_days' => max(0, (int) ($p['points_validity_days'] ?? 0)),
        'shop_coefficients' => $coef, 'bonuses' => $bon,
        'tiers' => array_map(static fn ($pts, $c) => ['points' => $pts, 'voucher_cents' => $c], array_keys($paliers), array_values($paliers))];
}

/** Une carte de l'ERP, dans la forme de l'écran. */
function fidCarteVue(array $c): array
{
    return ['id' => (int) ($c['id'] ?? 0), 'code' => (string) ($c['code'] ?? ''), 'nom' => (string) ($c['name'] ?? ''), 'version' => (int) ($c['version'] ?? 1),
        'revision' => (int) ($c['revision'] ?? 0), 'verrou' => !empty($c['terms_locked']), 'actif' => (int) ($c['active'] ?? 0) === 1,
        'portee' => (string) ($c['scope'] ?? ''), 'magasins' => array_map('intval', (array) ($c['shop_ids'] ?? [])),
        'debut' => $c['starts_at'] ?? null, 'fin' => $c['ends_at'] ?? null, 'couleur' => (string) ($c['color_fill'] ?? ''),
        'tampons' => (int) ($c['stamps_required'] ?? 0), 'recompense' => (string) ($c['reward_label'] ?? ''),
        'points' => fidPointsConfig($c['points_config'] ?? null)];
}

/** La carte du programme : celle du code réglé (la version en cours la plus haute), sinon la première carte à points active. */
function fidCarteProgramme(array $cartes): ?array
{
    $code = (string) (fidBingoConf()['carte'] ?? '');
    $auj = fidUtc(fidAujourdhui(), '12:00:00');
    $enCours = static fn ($c) => (int) ($c['active'] ?? 0) === 1 && fidPointsConfig($c['points_config'] ?? null) !== null
        && (empty($c['starts_at']) || (string) $c['starts_at'] <= $auj) && (empty($c['ends_at']) || (string) $c['ends_at'] >= $auj);
    $L = array_values(array_filter($cartes, $enCours));
    usort($L, static fn ($a, $b) => [(string) ($b['code'] ?? '') === $code, (int) ($b['version'] ?? 1)] <=> [(string) ($a['code'] ?? '') === $code, (int) ($a['version'] ?? 1)]);
    return $L[0] ?? null;
}

/** Les magasins : ceux de l'ERP (GET /admin/loyalty/options), sinon ceux du réseau. */
function fidMagasins(): array
{
    $r = fidErp('GET', '/admin/loyalty/options');
    $out = [];
    foreach ((array) (fidObjet($r['corps'])['shops'] ?? []) as $s) {
        if (!is_array($s) || (int) ($s['id'] ?? 0) <= 0) { continue; }
        $nom = (string) ($s['name'] ?? $s['nom'] ?? $s['label'] ?? ('Magasin ' . $s['id']));
        $out[] = ['id' => (string) (int) $s['id'], 'nom' => trim((string) preg_replace('/^.*\s[-–]\s/u', '', $nom))];
    }
    if (!$out && function_exists('ep_stores')) {
        try { foreach (ep_stores() as $s) { if (!isset($s['status']) || preg_match('/ouvert/i', (string) $s['status'])) { $out[] = ['id' => (string) $s['id'], 'nom' => (string) $s['nom']]; } } } catch (Throwable $e) { /* rien */ }
    }
    return $out;
}

/** Le code d'une carte : lettres et chiffres en capitales (l'ERP a refusé « FID-POINTS » : INVALID_CODE, 13/11/2026). */
function fidCodeCarte(string $c): string
{
    $c = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c));
    return substr($c !== '' ? $c : 'FIDPOINTS', 0, 32);
}

/** Les produits de l'ERP (GET /admin/loyalty/options) : [{id, nom}] — l'ERP exige un produit de récompense, même pour une carte à points. */
function fidProduits(): array
{
    $r = fidErp('GET', '/admin/loyalty/options');
    $out = [];
    foreach ((array) (fidObjet($r['corps'])['products'] ?? []) as $p) {
        if (is_array($p) && (int) ($p['id'] ?? $p['id_product'] ?? 0) > 0) {
            $out[] = ['id' => (int) ($p['id'] ?? $p['id_product']), 'nom' => trim((string) ($p['name'] ?? $p['nom'] ?? $p['label'] ?? ('Produit ' . ($p['id'] ?? ''))))];
        }
    }
    return $out;
}

/** Les identifiants des catégories de l'ERP (GET /admin/loyalty/options) : ce qui rapporte des points, tout le catalogue. */
function fidCategories(): array
{
    $r = fidErp('GET', '/admin/loyalty/options');
    $o = fidObjet($r['corps']);
    foreach (['categories' => 'earning_category_ids', 'category_groups' => 'earning_category_group_ids'] as $k => $champ) {
        $ids = [];
        foreach ((array) ($o[$k] ?? []) as $c) { if (is_array($c) && (int) ($c['id'] ?? $c['id_category'] ?? 0) > 0) { $ids[] = (int) ($c['id'] ?? $c['id_category']); } }
        if ($ids) { return [$champ => array_values(array_unique($ids))]; }
    }
    return [];
}

/** GET /fidelite?saison= — la carte à points, ses magasins, les cartes à arrêter, les quatre cartes de bingo de l'année, la saison choisie (celle du jour par défaut). */
function ep_fidelite(): array
{
    $err = null;
    $cartes = fidCartesErp($err);
    $prog = fidCarteProgramme($cartes);
    $saison = fidSaison(null, preg_match('/^[\w-]{1,12}$/', (string) ($_GET['saison'] ?? '')) ? (string) $_GET['saison'] : null);
    $conf = fidBingoConf();
    $camp = $err === null && $saison ? fidCampagnes($saison['campagne']) : [];
    $bons = $saison ? array_map(static function ($b) use ($camp) { $b['campagne'] = $camp[(int) round($b['valeur'] * 100)] ?? null; return $b; }, fidBonsSaison($saison)) : [];
    $super = !empty($conf['superBon']) ? ['valeur' => (float) $conf['superBon']['valeur'], 'provisoire' => !empty($conf['superBon']['provisoire']),
        'campagne' => $camp[(int) round((float) $conf['superBon']['valeur'] * 100)] ?? null] : null;
    $autres = [];
    foreach ($cartes as $c) {
        if ($prog !== null && (int) ($c['id'] ?? 0) === (int) $prog['id']) { continue; }
        $v = fidCarteVue($c);
        $finie = $v['fin'] !== null && (string) $v['fin'] < fidUtc(fidAujourdhui(), '12:00:00');
        if ($v['actif'] && !$finie) { $autres[] = $v; }
    }
    return ['source' => 'api', 'erp' => ['ok' => $err === null, 'erreur' => $err], 'aujourdhui' => fidAujourdhui(), 'heure' => fidHeureLocale(),
        'magasins' => $err === null ? fidMagasins() : [], 'carte' => $prog ? fidCarteVue($prog) : null, 'autres' => $autres,
        'annee' => fidAnnee(),
        'bingo' => ['saison' => $saison, 'seuil' => (float) $conf['seuilEuros'], 'multiplicateur' => (float) ($conf['multiplicateurCase'] ?? 1), 'palier' => $conf['palier'] ?? null, 'coefficients' => array_diff_key((array) ($conf['coefficients'] ?? []), ['_' => 1]), 'tousLes' => (int) $conf['tousLes'], 'validite' => (int) $conf['validite'],
            'bons' => $bons, 'superBon' => $super, 'tirage' => $conf['tirage'] ?? null, 'prefixe' => $saison ? $saison['campagne'] : 'Bingo']];
}

/* ---------------------------------------------------------------------------
 * Les clients : les points de chaque semaine, lus magasin par magasin
 * ------------------------------------------------------------------------- */
/** Le programme de la carte dans la fiche d'un client (programs[]). */
function fidProgrammeDe(array $client, int $carte): ?array
{
    foreach ((array) ($client['programs'] ?? []) as $p) { if (is_array($p) && (int) ($p['id'] ?? 0) === $carte) { return $p; } }
    return null;
}

/**
 * Les fiches clients d'une période, pour chaque magasin, toutes pages : [shop => [fiche…]]. Une période close se garde
 * en cache un jour ; la période qui touche aujourd'hui, dix minutes. `$appels` compte les requêtes envoyées à l'ERP.
 */
function fidClientsPeriode(array $shops, int $carte, string $du, string $au, int &$appels): array
{
    $ttl = $au < fidAujourdhui() ? FID_TTL_CLOSE : FID_TTL_COURS;
    $chemin = static fn (string $s, int $p) => '/shops/' . $s . '/loyalty/customers?card_id=' . $carte . '&from=' . $du . '&to=' . $au . '&page=' . $p . '&limit=' . FID_PAGE;
    $out = []; $manque = [];
    foreach ($shops as $s) {
        $c = fidCacheLire('cust|' . $s . '|' . $carte . '|' . $du . '|' . $au, $ttl);
        if ($c !== null) { $out[$s] = $c; } else { $manque[] = $s; }
    }
    if (!$manque) { return $out; }
    $p1 = fidErpTous(array_combine($manque, array_map(static fn ($s) => $chemin($s, 1), $manque)));
    $appels += count($manque);
    $suite = [];
    foreach ($manque as $s) {
        $out[$s] = fidItems($p1[$s]);
        $tot = (int) ((is_array($p1[$s]) ? ($p1[$s]['pagination']['total'] ?? 0) : 0));
        for ($p = 2; $p <= (int) ceil($tot / FID_PAGE) && $p <= 60; $p++) { $suite[$s . '|' . $p] = $chemin($s, $p); }
    }
    if ($suite) {
        foreach (fidErpTous($suite) as $k => $corps) { $out[explode('|', $k)[0]] = array_merge($out[explode('|', $k)[0]], fidItems($corps)); }
        $appels += count($suite);
    }
    foreach ($manque as $s) { if ($p1[$s] !== null) { fidCacheEcrire('cust|' . $s . '|' . $carte . '|' . $du . '|' . $au, $out[$s]); } }
    return $out;
}

/**
 * Le bingo de chaque client sur la saison : [id => {nom, shops, points[semaine] (points gagnés, tous magasins), cases,
 * euros[semaine] (achats, tous magasins : la case), cases, solde, paliers, dernier}]. `$decalage` : les mêmes semaines
 * plus tôt (364 : l'hiver passé).
 */
function fidBingoClients(array $saison, int $carte, array $shops, float $seuil, int &$appels, int $decalage = 0): array
{
    $C = [];
    $n = $decalage ? $saison['closes'] : ($saison['enCours'] ?: $saison['closes']);
    for ($w = 1; $w <= $n; $w++) {
        $sem = $saison['semaines'][$w - 1];
        $du = fidPlus($sem['du'], -$decalage);
        $au = min(fidPlus($sem['au'], -$decalage), fidAujourdhui());
        foreach (fidClientsPeriode($shops, $carte, $du, $au, $appels) as $s => $L) {
            foreach ($L as $f) {
                if (!is_array($f) || (int) ($f['id'] ?? 0) <= 0) { continue; }
                $prog = fidProgrammeDe($f, $carte);
                if ($prog === null || !is_array($prog['global_progress']['points'] ?? null)) { continue; }
                $id = (int) $f['id'];
                $C[$id] ??= ['id' => $id, 'nom' => trim((string) ($f['name'] ?? '') . ' ' . mb_substr(trim((string) ($f['surname'] ?? '')), 0, 1) . (trim((string) ($f['surname'] ?? '')) !== '' ? '.' : '')),
                    'shops' => [], 'points' => array_fill(1, $n, 0), 'euros' => array_fill(1, $n, 0.0), 'solde' => 0, 'paliers' => [], 'dernier' => null];
                $x = &$C[$id];
                $x['shops'][$s] = true;
                $x['points'][$w] += (int) ($prog['shop_activity']['points_earned'] ?? 0);
                $x['euros'][$w] += (float) ($f['shop_activity']['gross_sales_before_corrections'] ?? 0);
                $gp = $prog['global_progress']['points'];
                if ($w === $n) { $x['solde'] = (int) ($gp['balance'] ?? 0); $x['paliers'] = (array) ($gp['tiers'] ?? []); }
                $der = $f['shop_activity']['last_transaction_at'] ?? null;
                if (is_string($der) && ($x['dernier'] === null || $der > $x['dernier'])) { $x['dernier'] = $der; }
                unset($x);
            }
        }
    }
    foreach ($C as &$x) {
        $x['coches'] = []; $x['cases'] = 0;
        for ($w = 1; $w <= $n; $w++) { $ok = $x['euros'][$w] >= $seuil - 0.001; $x['coches'][$w] = $ok; if ($ok) { $x['cases']++; } }
    }
    unset($x);
    return $C;
}

/**
 * Semaine après semaine, la part des membres déjà venus dans la saison (jusqu'à cette semaine) qui ont coché :
 * [semaine => [coches, venus, part]]. La même mesure sert l'hiver passé (les mêmes semaines, 364 jours plus tôt).
 */
function fidParts(array $C, int $n): array
{
    $out = [];
    for ($w = 1; $w <= $n; $w++) {
        $venus = 0; $coch = 0;
        foreach ($C as $c) {
            $deja = false;
            for ($k = 1; $k <= $w; $k++) { if (($c['euros'][$k] ?? 0) > 0) { $deja = true; break; } }
            if (!$deja) { continue; }
            $venus++; if (!empty($c['coches'][$w])) { $coch++; }
        }
        $out[$w] = [$coch, $venus, $venus ? (int) round(100 * $coch / $venus) : null];
    }
    return $out;
}

/** GET /fidelite/clients?shop=&q= — la récurrence que le bingo produit, lue dans l'ERP. */
function ep_fidelite_clients(): array
{
    $conf = fidBingoConf(); $seuil = (float) $conf['seuilEuros']; $x = max(1, (int) $conf['tousLes']);
    $err = null;
    $prog = fidCarteProgramme(fidCartesErp($err));
    $saison = fidSaison();
    if ($err !== null || $prog === null || $saison === null) {
        return ['erp' => ['ok' => false, 'erreur' => $err ?? ($prog === null ? 'aucune carte à points active dans l’ERP' : 'aucune saison de bingo')], 'saison' => $saison];
    }
    $carte = (int) $prog['id'];
    $mags = fidMagasins();
    $tous = array_column($mags, 'id');
    $shop = preg_match('/^\d{1,10}$/', (string) ($_GET['shop'] ?? '')) ? (string) $_GET['shop'] : '';
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $appels = 0;
    $C = fidBingoClients($saison, $carte, $tous, $seuil, $appels);
    $A = fidBingoClients($saison, $carte, $tous, $seuil, $appels, 364);
    $vus = $shop === '' ? $C : array_filter($C, static fn ($c) => isset($c['shops'][$shop]));
    $avant = fidParts($shop === '' ? $A : array_filter($A, static fn ($c) => isset($c['shops'][$shop])), $saison['closes']);
    $n = $saison['enCours'] ?: $saison['closes'];
    $closes = $saison['closes'];

    // Semaine après semaine : la part des membres venus dans la saison (jusque-là) qui ont coché, face à l'hiver passé.
    $parts = fidParts($vus, $n);
    $semaines = [];
    for ($w = 1; $w <= $n; $w++) {
        $s = $saison['semaines'][$w - 1];
        $semaines[] = ['n' => $w, 'titre' => $s['titre'], 'ill' => $s['ill'], 'fete' => $s['fete'], 'etat' => $s['etat'], 'coches' => $parts[$w][0], 'venus' => $parts[$w][1],
            'part' => $parts[$w][2], 'avant' => $avant[$w][2] ?? null];
    }
    // Où en sont les membres : les cases des semaines closes.
    $rep = array_fill(0, $closes + 1, 0);
    $casesCloses = static function (array $c) use ($closes): int { $k = 0; for ($w = 1; $w <= $closes; $w++) { if ($c['coches'][$w]) { $k++; } } return $k; };
    foreach ($vus as $c) { $rep[$casesCloses($c)]++; }
    // Les relances.
    $cours = $saison['enCours'];
    $palier1 = 0;
    foreach ($prog['points_config']['tiers'] ?? [] as $t) { $palier1 = $palier1 ?: (int) $t['points']; }
    $R = ['bon' => [], 'palier' => [], 'pleine' => [], 'decroche' => [], 'pasjoue' => []];
    foreach ($vus as $id => $c) {
        $k = $casesCloses($c);
        $sem = $cours ? $c['euros'][$cours] : 0.0;
        if ($cours && ($k + 1) % $x === 0 && $sem < $seuil) { $R['bon'][] = $seuil - $sem; }
        if ($palier1 && $c['solde'] >= $palier1) { $R['palier'][] = $c['solde']; }
        if ($closes && $k === $closes) { $R['pleine'][] = 1; }
        if ($k >= $x && $closes >= 2 && !$c['coches'][$closes] && !$c['coches'][$closes - 1] && !($cours && $sem >= $seuil)) { $R['decroche'][] = 1; }
        if ($k === 0 && ($cours === 0 || $sem < $seuil)) { $R['pasjoue'][] = 1; }
    }
    $moy = static fn (array $l) => $l ? round(array_sum($l) / count($l), 2) : 0;
    $relances = [
        ['cle' => 'bon', 'n' => count($R['bon']), 'titre' => 'La case de cette semaine leur donne un bon', 'detail' => 'il leur manque ' . fidEur($moy($R['bon'])) . ' en moyenne avant dimanche'],
        ['cle' => 'palier', 'n' => count($R['palier']), 'titre' => 'Assez de points pour un bon, pas échangés', 'detail' => 'l’ERP n’échange jamais tout seul : le client le fait dans l’app'],
        ['cle' => 'pleine', 'n' => count($R['pleine']), 'titre' => 'Encore en course pour la carte pleine', 'detail' => 'aucune semaine manquée jusqu’ici'],
        ['cle' => 'decroche', 'n' => count($R['decroche']), 'titre' => 'Ont décroché', 'detail' => $x . ' cases ou plus, puis deux semaines sans case'],
        ['cle' => 'pasjoue', 'n' => count($R['pasjoue']), 'titre' => 'Pas encore de case', 'detail' => 'membres venus sans atteindre ' . fidEur($seuil) . ' une semaine'],
    ];
    // Les membres : ce qu'il y a à faire pour chacun ; sans recherche, quelques-uns de chaque cas, le plus utile d'abord.
    $bonDe = []; foreach (fidBonsSaison($saison) as $bb) { $bonDe[$bb['k']] = $bb['valeur']; }
    $fiche = static function (array $c) use ($cours, $seuil, $x, $closes, $palier1, $casesCloses, $bonDe): array {
        $prochain = null; $atteint = null;
        foreach ($c['paliers'] as $t) {
            if (!is_array($t)) { continue; }
            if (!empty($t['reached'])) { $atteint = (int) $t['points']; } elseif ($prochain === null) { $prochain = ['points' => (int) $t['points'], 'reste' => (int) ($t['remaining'] ?? 0)]; }
        }
        $k = $casesCloses($c); $sem = $cours ? $c['euros'][$cours] : 0.0;
        [$cas, $faire] = match (true) {
            $cours > 0 && ($k + 1) % $x === 0 && $sem < $seuil && isset($bonDe[$k + 1]) => [0, fidEur(round($seuil - $sem, 2)) . ' pour son bon de ' . fidEur($bonDe[$k + 1])],
            $k >= $x && $closes >= 2 && !$c['coches'][$closes] && !$c['coches'][$closes - 1] && $sem < $seuil => [1, 'Décroché : deux semaines sans case'],
            $atteint !== null => [2, 'Échanger ' . $atteint . ' points'],
            $closes > 0 && $k === $closes => [3, 'En course pour la carte pleine'],
            $k === 0 => [4, 'Pas encore de case'],
            default => [5, ''],
        };
        return ['id' => $c['id'], 'nom' => $c['nom'] !== '' ? $c['nom'] : 'client n° ' . $c['id'], 'solde' => $c['solde'], 'atteint' => $atteint, 'prochain' => $prochain,
            'cases' => $c['cases'], 'coches' => array_values($c['coches']), 'semaine' => round($sem, 2), 'pointsSemaine' => $cours ? $c['points'][$cours] : 0, 'dernier' => $c['dernier'], 'afaire' => $faire, 'cas' => $cas];
    };
    $L = array_map($fiche, array_values(array_filter($vus, static fn ($c) => $q === '' || str_contains(mb_strtolower($c['nom']), $q))));
    usort($L, static fn ($a, $b) => [$a['cas'], $b['cases'], $b['solde']] <=> [$b['cas'], $a['cases'], $a['solde']]);
    if (!empty($_GET['tous'])) {
        // Tous les membres (200 au plus), par cases puis par points : la liste d'un magasin.
        usort($L, static fn ($a, $b) => [$b['cases'], $b['solde']] <=> [$a['cases'], $a['solde']]);
        $membres = array_slice($L, 0, 200);
    } elseif ($q === '') {
        $parCas = [];
        foreach ($L as $m) { $parCas[$m['cas']][] = $m; }
        $membres = [];
        for ($i = 0; count($membres) < 12 && $i < 12; $i++) { foreach ($parCas as $l) { if (isset($l[$i]) && count($membres) < 12) { $membres[] = $l[$i]; } } }
        usort($membres, static fn ($a, $b) => [$a['cas'], $b['cases']] <=> [$b['cas'], $a['cases']]);
    } else {
        $membres = array_slice($L, 0, 40);
    }

    // Les chiffres du magasin (ou du réseau) : GET /shops/{id}/loyalty/statistics sur la saison.
    $stShops = $shop === '' ? $tous : [$shop];
    $st = fidErpTous(array_combine($stShops, array_map(static fn ($s) => '/shops/' . $s . '/loyalty/statistics?card_id=' . $carte . '&from=' . $saison['du'] . '&to=' . min($saison['au'], fidAujourdhui()), $stShops)));
    $appels += count($stShops);
    $k = ['transactions' => 0, 'points' => 0, 'reconnues' => 0, 'appareils' => 0, 'ventes' => 0.0];
    foreach ($st as $r) {
        $o = fidObjet($r);
        $k['transactions'] += (int) ($o['shop_activity']['transactions'] ?? 0);
        $k['points'] += (int) ($o['shop_activity']['points_earned'] ?? 0);
        $k['ventes'] += (float) ($o['shop_activity']['gross_sales_before_corrections'] ?? 0);
        $k['reconnues'] += (int) ($o['recognitions']['count'] ?? 0);
        $k['appareils'] += (int) ($o['recognitions']['devices'] ?? 0);
    }
    $joueurs = count(array_filter($vus, static fn ($c) => $c['cases'] > 0));
    $venus = count(array_filter($vus, static fn ($c) => array_sum($c['euros']) > 0));
    $dern = end($semaines) ?: null;
    return ['erp' => ['ok' => true, 'erreur' => null], 'aujourdhui' => fidAujourdhui(), 'heure' => fidHeureLocale(), 'shop' => $shop, 'magasins' => $mags,
        'carte' => ['id' => $carte, 'nom' => (string) ($prog['name'] ?? '')], 'saison' => $saison, 'seuil' => $seuil, 'tousLes' => $x,
        'kpis' => ['membres' => count($vus), 'venus' => $venus, 'joueurs' => $joueurs, 'partJoueurs' => count($vus) ? (int) round(100 * $joueurs / count($vus)) : null,
            'semaine' => $dern ? $dern['part'] : null, 'semaineFinie' => $dern ? $dern['etat'] === 'passee' : true,
            'points' => $k['points'], 'transactions' => $k['transactions'], 'ventes' => round($k['ventes']), 'reconnues' => $k['reconnues'], 'appareils' => $k['appareils']],
        'semaines' => $semaines, 'repartition' => $rep, 'relances' => $relances, 'membres' => $membres, 'nbMembres' => count($L), 'appels' => $appels];
}

/* ---------------------------------------------------------------------------
 * Simuler, publier
 * ------------------------------------------------------------------------- */
/** POST /fidelite/simuler {shop, montant (€), quand (AAAA-MM-JJTHH:MM), points?} — POST /admin/loyalty/simulate. */
function wr_fidelite_simuler(): array
{
    $b = body();
    $cfg = fidPointsConfig($b['points'] ?? null);
    if ($cfg === null) {
        $err = null; $p = fidCarteProgramme(fidCartesErp($err));
        $cfg = $p ? fidPointsConfig($p['points_config'] ?? null) : null;
    }
    if ($cfg === null) { http_response_code(422); return ['error' => 'aucun barème de points à simuler']; }
    $shop = (int) ($b['shop'] ?? 0);
    $quand = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) ($b['quand'] ?? '')) ? (string) $b['quand'] : fidAujourdhui() . 'T' . fidHeureLocale();
    $cents = (int) round(100 * max(0, (float) str_replace(',', '.', (string) ($b['montant'] ?? 0))));
    if ($shop <= 0) { http_response_code(422); return ['error' => 'magasin manquant']; }
    $r = fidErp('POST', '/admin/loyalty/simulate', ['config' => $cfg, 'shop_id' => $shop, 'amount_cents' => $cents, 'local_datetime' => $quand]);
    if ($r['erreur'] !== null) { http_response_code($r['code'] >= 400 ? $r['code'] : 502); return ['error' => 'simulation refusée par l’ERP : ' . $r['erreur']]; }
    $o = fidObjet($r['corps']);
    $pts = (int) ($o['points'] ?? 0); $seuil = (float) fidBingoConf()['seuilEuros'];
    return ['points' => $pts, 'tranches' => (int) ($o['slices'] ?? 0), 'coefMagasin' => (string) ($o['shop_coefficient'] ?? '1'),
        'coefBonus' => (string) ($o['bonus_coefficient'] ?? '1'), 'heure' => (string) ($o['local_time'] ?? ''), 'seuil' => $seuil, 'montant' => $cents / 100, 'coche' => $cents / 100 >= $seuil];
}

/** Les champs d'une carte à recopier dans sa version suivante (les conditions qu'une carte utilisée ne peut plus changer). */
function fidCorpsVersion(array $c, array $points, string $debutUtc): array
{
    $garde = ['code', 'name', 'image_path', 'color_tint', 'color_fill', 'color_accent', 'reward_label', 'stamps_required', 'reward_validity_days', 'scope',
        'sort_order', 'keywords', 'shop_ids', 'reward_product_ids', 'reward_portion_ids', 'earning_product_ids', 'earning_category_ids',
        'earning_category_group_ids', 'excluded_product_ids'];
    $out = [];
    foreach ($garde as $k) { if (array_key_exists($k, $c) && $c[$k] !== null && $c[$k] !== []) { $out[$k] = $c[$k]; } }
    // Identifiants ou mots-clés, jamais les deux (règle de l'API).
    if (!empty($out['keywords'])) { unset($out['earning_product_ids'], $out['earning_category_ids'], $out['earning_category_group_ids']); }
    return $out + ['version' => (int) ($c['version'] ?? 1) + 1, 'starts_at' => $debutUtc, 'ends_at' => null, 'active' => 1, 'points_config' => $points];
}

/**
 * POST /fidelite/publier {carte: {id, revision, points}, debut?: AAAA-MM-JJ, arreter?: [{id, le?}], qui}.
 * Carte libre : PATCH avec sa revision. Carte figée (déjà utilisée) : POST de la version suivante dès `debut`, puis PATCH
 * de la fin de l'ancienne la veille à minuit. Puis la fin des cartes à arrêter. L'ERP n'a pas de transaction : les étapes
 * s'arrêtent à la première refusée et la réponse dit ce qui est fait.
 */
function wr_fidelite_publier(): array
{
    $b = body();
    $qui = mb_substr(trim((string) ($b['qui'] ?? 'Cockpit')) ?: 'Cockpit', 0, 60);
    $etapes = [];
    $fait = static function (string $quoi, string $route, array $r) use (&$etapes): bool {
        $etapes[] = ['quoi' => $quoi, 'route' => $route, 'code' => $r['code'], 'ok' => $r['erreur'] === null, 'erreur' => $r['erreur']];
        return $r['erreur'] === null;
    };
    $ok = true;
    $x = is_array($b['carte'] ?? null) ? $b['carte'] : null;
    if ($x !== null) {
        $id = (int) ($x['id'] ?? 0);
        $pts = fidPointsConfig(($x['points'] ?? []) + ['points_enabled' => true]);
        if ($id <= 0 || $pts === null) { http_response_code(422); return ['error' => 'carte ou barème illisible']; }
        $lu = fidErp('GET', '/admin/loyalty/cards/' . $id);
        if ($lu['erreur'] !== null) { http_response_code(502); return ['error' => 'carte illisible dans l’ERP : ' . $lu['erreur']]; }
        $c = fidObjet($lu['corps']);
        if ((int) ($c['revision'] ?? 0) !== (int) ($x['revision'] ?? -1)) {
            http_response_code(409); return ['error' => 'la carte a changé dans l’ERP depuis votre lecture : relisez-la avant de publier', 'revision' => (int) ($c['revision'] ?? 0)];
        }
        if (empty($c['terms_locked'])) {
            $ok = $fait('Barème de la carte', 'PATCH /admin/loyalty/cards/' . $id, fidErp('PATCH', '/admin/loyalty/cards/' . $id, ['revision' => (int) $c['revision'], 'points_config' => $pts]));
        } else {
            $debut = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['debut'] ?? '')) && (string) $b['debut'] > fidAujourdhui() ? (string) $b['debut'] : fidPlus(fidAujourdhui(), 1);
            $ok = $fait('Version ' . ((int) ($c['version'] ?? 1) + 1) . ' dès le ' . $debut, 'POST /admin/loyalty/cards',
                fidErp('POST', '/admin/loyalty/cards', fidCorpsVersion($c, $pts, fidUtc($debut))));
            if ($ok) {
                $ok = $fait('Fin de la version ' . (int) ($c['version'] ?? 1), 'PATCH /admin/loyalty/cards/' . $id,
                    fidErp('PATCH', '/admin/loyalty/cards/' . $id, ['revision' => (int) $c['revision'], 'ends_at' => fidUtc(fidPlus($debut, -1), '23:59:59')]));
            }
        }
    }
    foreach ((array) ($b['arreter'] ?? []) as $a) {
        if (!$ok) { break; }
        $id = (int) (is_array($a) ? ($a['id'] ?? 0) : $a);
        if ($id <= 0) { continue; }
        $le = is_array($a) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['le'] ?? '')) ? (string) $a['le'] : fidAujourdhui();
        $lu = fidErp('GET', '/admin/loyalty/cards/' . $id);
        if ($lu['erreur'] !== null) { $ok = $fait('Carte ' . $id, 'GET /admin/loyalty/cards/' . $id, $lu); break; }
        $c = fidObjet($lu['corps']);
        $ok = $fait('Fin de « ' . (string) ($c['name'] ?? $id) . ' » le ' . $le, 'PATCH /admin/loyalty/cards/' . $id,
            fidErp('PATCH', '/admin/loyalty/cards/' . $id, ['revision' => (int) ($c['revision'] ?? 0), 'ends_at' => fidUtc($le, '23:59:59')]));
    }
    if (!$etapes) { http_response_code(422); return ['error' => 'rien à publier']; }
    $n = count(array_filter($etapes, static fn ($e) => $e['ok']));
    if (function_exists('journalAdd')) {
        try { journalAdd($qui, 'Fidélité', null, 'Carte publiée dans l’ERP : ' . implode(' ; ', array_map(static fn ($e) => $e['quoi'] . ($e['ok'] ? '' : ' (refusé)'), $etapes))); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
    if (!$ok) { http_response_code(502); }
    return ['ok' => $ok, 'faites' => $n, 'etapes' => $etapes];
}

/* ---------------------------------------------------------------------------
 * Les bons du bingo
 * ------------------------------------------------------------------------- */
/** Le code d'un bon : unique et stable (une seconde émission tombe sur le même code et l'ERP la refuse). */
function fidCodeBon(string $saison, int $k, int $client): string { return 'BGO' . strtoupper(preg_replace('/\W/', '', $saison)) . 'K' . $k . 'C' . $client; }

/** Les codes déjà émis d'une campagne, par client : [client => [code…]]. */
function fidCodesEmis(int $camp): array
{
    $out = [];
    for ($p = 1; $p <= 20; $p++) {
        $r = fidErp('GET', '/admin/voucher-campaigns/' . $camp . '/codes?limit=200&page=' . $p);
        $L = fidItems($r['corps']);
        foreach ($L as $k) { if (is_array($k) && (int) ($k['id_customer'] ?? 0) > 0) { $out[(int) $k['id_customer']][] = (string) ($k['code'] ?? ''); } }
        $pg = is_array($r['corps']) ? ($r['corps']['pagination'] ?? null) : null;
        if (count($L) < 200 || !is_array($pg) || $p >= (int) ($pg['pages'] ?? 1)) { break; }
    }
    return $out;
}

/**
 * Les bons dus sur les semaines closes, et, si `ecrire`, leur création dans l'ERP. Un bon ne part que si sa campagne
 * existe et qu'elle est ACTIVE dans l'ERP : la mettre en brouillon suffit à tout arrêter.
 */
function fidBingoBons(bool $ecrire): array
{
    $conf = fidBingoConf(); $seuil = (float) $conf['seuilEuros'];
    $err = null; $prog = fidCarteProgramme(fidCartesErp($err)); $saison = fidSaison();
    if ($prog === null || $saison === null) { return ['ok' => false, 'error' => $err ?? 'aucune carte à points active, ou aucune saison']; }
    $appels = 0;
    $C = fidBingoClients($saison, (int) $prog['id'], array_column(fidMagasins(), 'id'), $seuil, $appels);
    $camp = fidCampagnes($saison['campagne']);
    $dus = fidBonsSaison($saison);
    if (!empty($conf['superBon'])) { $dus[] = ['k' => count($saison['semaines']), 'valeur' => (float) $conf['superBon']['valeur'], 'nom' => 'Super bon cadeau', 'motif' => 'cake', 'super' => true]; }
    $debut = fidPlus(fidAujourdhui(), 1); $fin = fidPlus($debut, (int) $conf['validite']);
    $out = []; $crees = 0; $erreurs = []; $codesDe = [];
    foreach ($dus as $bon) {
        $cents = (int) round($bon['valeur'] * 100); $c = $camp[$cents] ?? null;
        $ayants = [];
        foreach ($C as $cl) {
            $k = 0; for ($w = 1; $w <= $saison['closes']; $w++) { if ($cl['coches'][$w]) { $k++; } }
            if ($k >= $bon['k']) { $ayants[] = $cl['id']; }
        }
        // Plusieurs bons de même valeur partagent leur campagne : un bon est émis si SON code (saison, case, client) y est.
        $emis = $c ? ($codesDe[$c['id']] ??= fidCodesEmis($c['id'])) : [];
        $nouveaux = array_values(array_filter($ayants, static fn ($id) => !in_array(fidCodeBon($saison['id'], $bon['k'], $id), $emis[$id] ?? [], true)));
        $ligne = ['k' => $bon['k'], 'valeur' => $bon['valeur'], 'nom' => $bon['nom'], 'campagne' => $c, 'ayants' => count($ayants), 'emis' => count($ayants) - count($nouveaux), 'aEmettre' => count($nouveaux)];
        if ($ecrire && $nouveaux && $c && $c['statut'] === 'ACTIVE') {
            foreach ($nouveaux as $id) {
                $r = fidErp('POST', '/admin/voucher-campaigns/' . $c['id'] . '/codes', ['code' => fidCodeBon($saison['id'], $bon['k'], $id), 'status' => 'ACTIVE',
                    'id_customer' => $id, 'valid_from' => $debut . ' 00:00:00', 'valid_to' => $fin . ' 23:59:59', 'usage_limit' => 1]);
                if ($r['erreur'] === null) { $crees++; $codesDe[$c['id']][$id][] = fidCodeBon($saison['id'], $bon['k'], $id); } elseif (count($erreurs) < 5) { $erreurs[] = 'client ' . $id . ' : ' . $r['erreur']; }
            }
        }
        $out[] = $ligne;
    }
    if ($ecrire && $crees && function_exists('journalAdd')) {
        try { journalAdd('Bingo', 'Fidélité', null, $crees . ' bon' . ($crees > 1 ? 's' : '') . ' du bingo émis dans l’ERP'); } catch (Throwable $e) { /* rien */ }
    }
    return ['ok' => !$erreurs, 'simulation' => !$ecrire, 'saison' => $saison['nom'], 'semainesCloses' => $saison['closes'], 'bons' => $out, 'crees' => $crees, 'erreurs' => $erreurs, 'appels' => $appels];
}

/** POST /fidelite/bingo/bons {ecrire?: false} — les bons dus ; `ecrire: true` les crée. */
function wr_fidelite_bingo_bons(): array
{
    return fidBingoBons(!empty(body()['ecrire']));
}

/**
 * GET /fidelite/client?id= — la carte d'un client, telle que l'app la montrerait : ses semaines (achats, points, case),
 * ses bons du bingo (gagnés, émis : un code à son nom dans la campagne de la saison), son solde et ses paliers. Lu dans
 * l'ERP comme le reste (les semaines de tous les magasins ; les codes par GET …/codes?id_customer=).
 */
function ep_fidelite_client(): array
{
    $id = (int) ($_GET['id'] ?? 0);
    $conf = fidBingoConf(); $seuil = (float) $conf['seuilEuros'];
    $err = null; $prog = fidCarteProgramme(fidCartesErp($err)); $saison = fidSaison();
    if ($id <= 0) { http_response_code(400); return ['error' => 'id du client manquant']; }
    if ($prog === null || $saison === null) { return ['erp' => ['ok' => false, 'erreur' => $err ?? 'aucune carte à points active']]; }
    $appels = 0;
    $C = fidBingoClients($saison, (int) $prog['id'], array_column(fidMagasins(), 'id'), $seuil, $appels);
    $c = $C[$id] ?? null;
    if ($c === null) { http_response_code(404); return ['error' => 'client ' . $id . ' introuvable sur la carte cette saison']; }
    $cours = $saison['enCours'];
    $semaines = [];
    foreach ($saison['semaines'] as $w) {
        $n = $w['n'];
        $semaines[] = ['n' => $n, 'titre' => $w['titre'], 'sous' => $w['sous'], 'fete' => $w['fete'], 'ill' => $w['ill'], 'du' => $w['du'], 'au' => $w['au'], 'etat' => $w['etat'],
            'euros' => isset($c['euros'][$n]) ? round($c['euros'][$n], 2) : null, 'points' => $c['points'][$n] ?? null,
            'coche' => !empty($c['coches'][$n]), 'manquee' => $w['etat'] === 'passee' && empty($c['coches'][$n])];
    }
    $camp = fidCampagnes($saison['campagne']);
    $codes = [];
    foreach ($camp as $cp) {
        $r = fidErp('GET', '/admin/voucher-campaigns/' . $cp['id'] . '/codes?limit=50&id_customer=' . $id);
        foreach (fidItems($r['corps']) as $k) { if (is_array($k) && (int) ($k['id_customer'] ?? 0) === $id) { $codes[(string) ($k['code'] ?? '')] = $k; } }
    }
    $dus = fidBonsSaison($saison);
    if (!empty($conf['superBon'])) { $dus[] = ['k' => count($saison['semaines']), 'valeur' => (float) $conf['superBon']['valeur'], 'nom' => 'Super bon cadeau', 'motif' => 'cake', 'super' => true]; }
    $manquees = count(array_filter($semaines, static fn ($w) => $w['manquee']));
    $bons = array_map(static function ($b) use ($c, $codes, $saison, $id, $manquees) {
        $code = fidCodeBon($saison['id'], $b['k'], $id); $k = $codes[$code] ?? null;
        $possible = count($saison['semaines']) - $manquees >= $b['k'];
        return $b + ['gagne' => $c['cases'] >= $b['k'], 'reste' => max(0, $b['k'] - $c['cases']), 'possible' => $possible,
            'code' => $k ? $code : null, 'statut' => $k ? (string) ($k['status'] ?? '') : null, 'jusquau' => $k['valid_to'] ?? null];
    }, $dus);
    $prochain = null; $atteint = null;
    foreach ($c['paliers'] as $t) {
        if (!is_array($t)) { continue; }
        if (!empty($t['reached'])) { $atteint = ['points' => (int) $t['points'], 'valeur' => (int) $t['voucher_cents'] / 100]; }
        elseif ($prochain === null) { $prochain = ['points' => (int) $t['points'], 'valeur' => (int) $t['voucher_cents'] / 100, 'reste' => (int) ($t['remaining'] ?? 0)]; }
    }
    $sem = $cours ? (float) $c['euros'][$cours] : 0.0;
    return ['erp' => ['ok' => true, 'erreur' => null], 'aujourdhui' => fidAujourdhui(), 'seuil' => $seuil, 'tousLes' => max(1, (int) $conf['tousLes']), 'multiplicateur' => (float) ($conf['multiplicateurCase'] ?? 2),
        'saison' => ['id' => $saison['id'], 'nom' => $saison['nom'], 'du' => $saison['du'], 'au' => $saison['au'], 'enCours' => $cours, 'campagne' => $saison['campagne']],
        'client' => ['id' => $id, 'nom' => $c['nom'] !== '' ? $c['nom'] : 'client n° ' . $id, 'magasins' => array_keys($c['shops']), 'dernier' => $c['dernier'],
            'solde' => $c['solde'], 'atteint' => $atteint, 'prochain' => $prochain, 'cases' => $c['cases'], 'manquees' => $manquees,
            'semaine' => ['n' => $cours, 'euros' => round($sem, 2), 'reste' => max(0, round($seuil - $sem, 2)), 'coche' => $cours ? !empty($c['coches'][$cours]) : false]],
        'semaines' => $semaines, 'bons' => $bons, 'appels' => $appels];
}

/**
 * GET /fidelite/creation?saison= — ce que l'admin enverrait à l'ERP pour CRÉER le programme, sans rien envoyer : la carte
 * à points (POST /admin/loyalty/cards, d'après la carte en place ou le handoff), et les campagnes de bons de la saison
 * (POST /admin/voucher-campaigns), avec ce qui existe déjà. La page de test l'affiche en formulaire ; l'envoi n'y est pas.
 */
function ep_fidelite_creation(): array
{
    $conf = fidBingoConf();
    $err = null; $cartes = fidCartesErp($err); $prog = fidCarteProgramme($cartes);
    $saison = fidSaison(null, preg_match('/^[\w-]{1,12}$/', (string) ($_GET['saison'] ?? '')) ? (string) $_GET['saison'] : null);
    $mags = $err === null ? fidMagasins() : [];
    $pal = (array) ($conf['palier'] ?? ['points' => 100, 'valeur' => 5]);
    $points = $prog ? fidPointsConfig($prog['points_config'] ?? null) : null;
    $points ??= ['stamps_enabled' => false, 'points_enabled' => true, 'points_per_slice' => 1, 'slice_cents' => 100, 'voucher_validity_days' => (int) ($conf['validite'] ?? 30),
        'points_validity_days' => 365, 'shop_coefficients' => (object) array_fill_keys(array_column($mags, 'id'), 1),
        'bonuses' => [['name' => 'Jours creux', 'days' => [1, 2, 3], 'from' => '14:00', 'to' => '17:00', 'coefficient' => 2]],
        'tiers' => [['points' => (int) $pal['points'], 'voucher_cents' => (int) round(100 * (float) $pal['valeur'])]]];
    // Le code : celui de la carte en place, sinon celui des réglages (ou celui que l'admin tape), en lettres et chiffres.
    $code = (string) ($_GET['code'] ?? '') !== '' ? fidCodeCarte((string) $_GET['code']) : ($prog ? (string) ($prog['code'] ?? '') : fidCodeCarte((string) ($conf['carte'] ?? 'FIDPOINTS')));
    // Une carte du même code, même arrêtée, garde sa version : la nouvelle prend la suivante (code + version identifient les conditions).
    $versionSuivante = 1;
    foreach ($cartes as $c) { if (strtoupper((string) ($c['code'] ?? '')) === strtoupper($code)) { $versionSuivante = max($versionSuivante, (int) ($c['version'] ?? 1) + 1); } }
    // Le produit de récompense, exigé par l'ERP (SELECT_AT_LEAST_ONE, 13/11/2026) : celui de la carte en place, sinon un pain, un croissant ou un café.
    $produits = $err === null ? fidProduits() : [];
    $recompense = $prog && !empty($prog['reward_product_ids']) ? (int) $prog['reward_product_ids'][0] : 0;
    if (!$recompense) { foreach ($produits as $p) { if (preg_match('/croissant|pain|caf[eé]/iu', $p['nom'])) { $recompense = $p['id']; break; } } }
    if (!$recompense && $produits) { $recompense = $produits[0]['id']; }
    $carte = ['code' => $code, 'name' => $prog ? (string) ($prog['name'] ?? 'Carte L’Atelier') : 'Carte L’Atelier',
        'version' => $versionSuivante, 'scope' => 'NETWORK', 'shop_ids' => array_map('intval', array_column($mags, 'id')),
        'starts_at' => fidUtc($prog ? fidPlus(fidAujourdhui(), 1) : fidAujourdhui()), 'ends_at' => null, 'active' => 1, 'sort_order' => 0,
        'color_tint' => '#F8E2CB', 'color_fill' => '#8D1D2C', 'color_accent' => '#F2C9A0', 'reward_label' => 'Bon de ' . fidEur((float) $pal['valeur']),
        'reward_product_ids' => $recompense ? [$recompense] : [], 'points_config' => $points] + (fidCategories() ?: ['keywords' => ['tout']]);
    $existantes = $saison && $err === null ? fidCampagnes($saison['campagne']) : [];
    $valeurs = [];
    if ($saison) { foreach (fidBonsSaison($saison) as $b) { $valeurs[(string) $b['valeur']] = $b['valeur']; } }
    if (!empty($conf['superBon'])) { $valeurs[(string) $conf['superBon']['valeur']] = (float) $conf['superBon']['valeur']; }
    $campagnes = [];
    foreach ($valeurs as $v) {
        $nom = ($saison ? $saison['campagne'] : 'Bingo') . ' · ' . fidEur($v);
        $campagnes[] = ['valeur' => $v, 'existe' => $existantes[(int) round($v * 100)] ?? null,
            'corps' => ['name' => $nom, 'description' => 'Bons du bingo de saison, un code par client (cockpit, fidélité).', 'status' => 'DRAFT', 'code_type' => 'UNIQUE',
                'requires_customer' => true, 'usage_limit_per_code' => 1, 'valid_from' => $saison ? $saison['du'] . ' 00:00:00' : null,
                'valid_to' => $saison ? fidPlus($saison['au'], (int) ($conf['validite'] ?? 30) + 1) . ' 23:59:59' : null]];
    }
    return ['erp' => ['ok' => $err === null, 'erreur' => $err], 'aujourdhui' => fidAujourdhui(), 'magasins' => $mags,
        'carteEnPlace' => $prog ? fidCarteVue($prog) : null, 'saison' => $saison ? ['id' => $saison['id'], 'nom' => $saison['nom'], 'du' => $saison['du'], 'au' => $saison['au'], 'campagne' => $saison['campagne']] : null,
        'annee' => fidAnnee(),
        'produits' => $produits, 'recompense' => $recompense,
        'codesExistants' => array_map(static fn ($c) => ['code' => (string) ($c['code'] ?? ''), 'nom' => (string) ($c['name'] ?? ''), 'version' => (int) ($c['version'] ?? 1), 'actif' => (int) ($c['active'] ?? 0) === 1,
            'points' => fidPointsConfig($c['points_config'] ?? null) !== null], $cartes),
        'regles' => ['seuil' => (float) $conf['seuilEuros'], 'tousLes' => max(1, (int) $conf['tousLes']), 'bons' => $saison ? array_map(static fn ($b) => ['k' => $b['k'], 'valeur' => $b['valeur'], 'nom' => $b['nom']], fidBonsSaison($saison)) : [],
            'superBon' => !empty($conf['superBon']) ? (float) $conf['superBon']['valeur'] : null, 'validite' => (int) ($conf['validite'] ?? 30)],
        'envois' => [
            ['quoi' => $prog ? 'Nouvelle version de la carte à points (v' . $carte['version'] . ')' : 'La carte à points (v' . $carte['version'] . ')', 'methode' => 'POST', 'route' => '/admin/loyalty/cards', 'permission' => 'LOYALTY.MANAGE', 'corps' => $carte],
        ],
        'campagnes' => $campagnes,
        'codeExemple' => ['methode' => 'POST', 'route' => '/admin/voucher-campaigns/{id}/codes', 'corps' => ['code' => fidCodeBon($saison['id'] ?? 'X', 3, 12345), 'status' => 'ACTIVE',
            'id_customer' => 12345, 'valid_from' => fidPlus(fidAujourdhui(), 1) . ' 00:00:00', 'valid_to' => fidPlus(fidAujourdhui(), 1 + (int) ($conf['validite'] ?? 30)) . ' 23:59:59', 'usage_limit' => 1]]];
}

/**
 * POST /fidelite/creer {points?, debut?: AAAA-MM-JJ, code?, recompense? (id produit), confirmer: true, qui} — CRÉE la carte à points dans l'ERP
 * (POST /admin/loyalty/cards), d'après l'aperçu de l'admin (GET /fidelite/creation) et le barème modifié dans la page.
 * Sans carte en place : la version 1, active dès `debut` (aujourd'hui par défaut). Une carte déjà en place : la version
 * suivante, même code (la publication du cockpit arrête l'ancienne). Ce qui rapporte des points : toutes les catégories
 * de l'ERP. Il faut `confirmer: true` et la permission LOYALTY.MANAGE. Journalisé.
 */
function wr_fidelite_creer(): array
{
    $b = body();
    if (empty($b['confirmer'])) { http_response_code(422); return ['error' => 'confirmation manquante : rien n’est créé']; }
    $_GET = ['code' => (string) ($b['code'] ?? '')];
    $apercu = ep_fidelite_creation();
    if (!$apercu['erp']['ok']) { http_response_code(502); return ['error' => 'ERP injoignable : ' . ($apercu['erp']['erreur'] ?? '')]; }
    $corps = $apercu['envois'][0]['corps'];
    if ((int) ($b['recompense'] ?? 0) > 0) {
        if (!in_array((int) $b['recompense'], array_column($apercu['produits'], 'id'), true)) { http_response_code(422); return ['error' => 'produit de récompense inconnu de l’ERP']; }
        $corps['reward_product_ids'] = [(int) $b['recompense']];
    }
    if (!$corps['reward_product_ids']) { http_response_code(422); return ['error' => 'aucun produit de récompense : l’ERP en exige un (GET /admin/loyalty/options ne rend aucun produit)']; }
    if (!$corps['shop_ids']) { http_response_code(422); return ['error' => 'aucun magasin lu dans l’ERP (GET /admin/loyalty/options)']; }
    if (is_array($b['points'] ?? null)) {
        $pts = fidPointsConfig($b['points'] + ['points_enabled' => true]);
        if ($pts === null) { http_response_code(422); return ['error' => 'barème illisible']; }
        $corps['points_config'] = $pts;
    }
    // Les coefficients des seuls magasins de la carte (l'ERP refuse les autres) ; les absents valent 1.
    $coef = (array) ($corps['points_config']['shop_coefficients'] ?? []);
    $corps['points_config']['shop_coefficients'] = [];
    foreach ($corps['shop_ids'] as $id) { $corps['points_config']['shop_coefficients'][(string) $id] = (float) ($coef[(string) $id] ?? 1); }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['debut'] ?? '')) && (string) $b['debut'] >= fidAujourdhui()) { $corps['starts_at'] = fidUtc((string) $b['debut']); }
    $r = fidErp('POST', '/admin/loyalty/cards', $corps);
    if ($r['erreur'] !== null) {
        http_response_code($r['code'] >= 400 ? $r['code'] : 502);
        return ['ok' => false, 'error' => 'l’ERP a refusé la carte : ' . $r['erreur'], 'code' => $r['code'], 'reponse' => $r['corps'], 'envoye' => $corps];
    }
    $carte = fidObjet($r['corps']);
    $carte = is_array($carte['data'] ?? null) ? $carte['data'] : $carte;
    if (function_exists('journalAdd')) {
        $qui = mb_substr(trim((string) ($b['qui'] ?? 'Page de test')) ?: 'Page de test', 0, 60);
        try { journalAdd($qui, 'Fidélité', null, 'Carte à points créée dans l’ERP : ' . $corps['code'] . ' v' . $corps['version'] . (isset($carte['id']) ? ' (id ' . $carte['id'] . ')' : '')); } catch (Throwable $e) { /* le journal ne bloque pas */ }
    }
    return ['ok' => true, 'code' => $r['code'], 'carte' => $carte, 'envoye' => $corps];
}

/**
 * POST /fidelite/creer-campagnes {saison?, confirmer: true} — crée dans l'ERP les campagnes de bons de la saison qui
 * manquent (POST /admin/voucher-campaigns), EN BROUILLON : aucun bon ne part tant qu'une campagne n'est pas ACTIVE.
 * Permission MENU.BUNDLE.MANAGE. Journalisé.
 */
function wr_fidelite_creer_campagnes(): array
{
    $b = body();
    if (empty($b['confirmer'])) { http_response_code(422); return ['error' => 'confirmation manquante : rien n’est créé']; }
    $_GET = ['saison' => (string) ($b['saison'] ?? '')];
    $apercu = ep_fidelite_creation();
    if (!$apercu['erp']['ok']) { http_response_code(502); return ['error' => 'ERP injoignable : ' . ($apercu['erp']['erreur'] ?? '')]; }
    $etapes = [];
    foreach ($apercu['campagnes'] as $c) {
        if ($c['existe']) { $etapes[] = ['campagne' => $c['corps']['name'], 'ok' => true, 'deja' => true]; continue; }
        $r = fidErp('POST', '/admin/voucher-campaigns', $c['corps']);
        $etapes[] = ['campagne' => $c['corps']['name'], 'ok' => $r['erreur'] === null, 'code' => $r['code'], 'erreur' => $r['erreur']];
        if ($r['erreur'] !== null) { break; }
    }
    $creees = count(array_filter($etapes, static fn ($e) => $e['ok'] && empty($e['deja'])));
    if ($creees && function_exists('journalAdd')) {
        try { journalAdd((string) ($b['qui'] ?? 'Page de test'), 'Fidélité', null, $creees . ' campagne(s) du bingo créée(s) en brouillon dans l’ERP'); } catch (Throwable $e) { /* rien */ }
    }
    $ok = !array_filter($etapes, static fn ($e) => !$e['ok']);
    if (!$ok) { http_response_code(502); }
    return ['ok' => $ok, 'creees' => $creees, 'etapes' => $etapes];
}

/**
 * GET /fidelite/verification — l'API de l'ERP, route par route, telle que la fidélité l'utilise : {ok, compte, etapes:
 * [{etape, methode, route, ok, code, ms, note}], resume}. LECTURE SEULE : des GET, et la simulation de points (que l'ERP
 * n'écrit pas). Sert la page de test (public/fidelite-test). Un 403 dit la permission qui manque au compte admin.
 */
function ep_fidelite_verification(): array
{
    $etapes = [];
    $sonde = static function (string $etape, string $methode, string $route, ?array $corps, string $permission, ?callable $lire = null) use (&$etapes): array {
        $t = microtime(true);
        $r = fidErp($methode, $route, $corps);
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        $note = $ok ? ($lire ? (string) $lire($r['corps']) : '') : ($r['code'] === 403 ? 'refusé : il manque la permission ' . $permission . ' au compte admin ERP'
            : ($r['code'] === 401 ? 'jeton refusé : vérifiez le compte admin ERP (Paramètres)' : (string) ($r['erreur'] ?? 'sans réponse')));
        $etapes[] = ['etape' => $etape, 'methode' => $methode, 'route' => $route, 'ok' => $ok, 'code' => $r['code'], 'ms' => (int) round(1000 * (microtime(true) - $t)), 'note' => mb_substr($note, 0, 300)];
        return $r;
    };
    $compte = class_exists('ErpApi') && !isset($GLOBALS['FID_ERP']) ? ErpApi::statut() : ['configure' => true, 'base' => 'faux ERP'];
    $cartes = $sonde('Les cartes de fidélité', 'GET', '/admin/loyalty/cards?limit=100', null, 'aucune (jeton admin valide)', static function ($c) {
        $L = fidItems($c); $p = fidCarteProgramme($L);
        return count($L) . ' carte(s) ; carte à points du programme : ' . ($p ? ($p['name'] ?? '') . ' (' . ($p['code'] ?? '') . ', id ' . $p['id'] . ', v' . ($p['version'] ?? 1) . ')' : 'aucune — créez-la ou réglez « carte » dans src/data/fidelite_bingo.json');
    });
    $prog = fidCarteProgramme(fidItems($cartes['corps']));
    if ($prog) { $sonde('La carte du programme', 'GET', '/admin/loyalty/cards/' . (int) $prog['id'], null, 'LOYALTY.MANAGE', static fn ($c) => 'revision ' . (fidObjet($c)['revision'] ?? '?') . (empty(fidObjet($c)['terms_locked']) ? '' : ' · barème figé (une modification crée une nouvelle version)')); }
    $opt = $sonde('Les magasins', 'GET', '/admin/loyalty/options', null, 'LOYALTY.MANAGE', static fn ($c) => count((array) (fidObjet($c)['shops'] ?? [])) . ' magasin(s)');
    $saison = fidSaison();
    $pre = $saison ? $saison['campagne'] : 'Bingo';
    $sonde('Les campagnes du bingo (' . $pre . ')', 'GET', '/admin/voucher-campaigns?limit=100&name=' . rawurlencode($pre), null, 'MENU.BUNDLE.MANAGE', static function ($c) use ($pre) {
        $n = array_map(static fn ($x) => (string) (($x['campaign'] ?? $x)['name'] ?? '') . ' [' . (string) (($x['campaign'] ?? $x)['status'] ?? '') . ']', fidItems($c));
        return $n ? implode(' · ', $n) : 'aucune campagne « ' . $pre . ' · … € » : à créer dans l’ERP (UNIQUE, client requis) pour que les bons partent';
    });
    $shop = (int) ((array) (fidObjet($opt['corps'])['shops'] ?? [[]]))[0]['id'] ?? 0;
    if ($shop > 0 && $prog) {
        $du = $saison ? max($saison['du'], fidPlus(fidAujourdhui(), -6)) : fidPlus(fidAujourdhui(), -6);
        $q = 'card_id=' . (int) $prog['id'] . '&from=' . $du . '&to=' . fidAujourdhui();
        $sonde('Statistiques d’un magasin (' . $shop . ')', 'GET', '/shops/' . $shop . '/loyalty/statistics?' . $q, null, 'un jeton qui couvre ce magasin',
            static fn ($c) => 'participants ' . (fidObjet($c)['participants']['unique_customers'] ?? '?') . ' · points gagnés ' . (fidObjet($c)['shop_activity']['points_earned'] ?? '?') . ' · ventes ' . (fidObjet($c)['shop_activity']['gross_sales_before_corrections'] ?? '?') . ' €');
        $sonde('Clients d’un magasin (' . $shop . ')', 'GET', '/shops/' . $shop . '/loyalty/customers?' . $q . '&limit=5', null, 'un jeton qui couvre ce magasin', static function ($c) {
            $L = fidItems($c); $f = $L[0] ?? null;
            return 'total ' . ((is_array($c) ? ($c['pagination']['total'] ?? null) : null) ?? '?') . ($f ? ' · 1er : achats ' . ($f['shop_activity']['gross_sales_before_corrections'] ?? '?') . ' €, programme ' . (count((array) ($f['programs'] ?? [])) ? 'présent' : 'absent') : ' · aucun client sur la période');
        });
        $cfg = fidPointsConfig($prog['points_config'] ?? null);
        if ($cfg) {
            $sonde('Simulation de points (rien n’est écrit)', 'POST', '/admin/loyalty/simulate', ['config' => $cfg, 'shop_id' => $shop, 'amount_cents' => 2240, 'local_datetime' => fidAujourdhui() . 'T15:10'], 'LOYALTY.MANAGE',
                static fn ($c) => '22,40 € aujourd’hui à 15:10 → ' . (fidObjet($c)['points'] ?? '?') . ' points (magasin ×' . (fidObjet($c)['shop_coefficient'] ?? '?') . ', bonus ×' . (fidObjet($c)['bonus_coefficient'] ?? '?') . ')');
        }
    }
    $ko = array_values(array_filter($etapes, static fn ($e) => !$e['ok']));
    return ['ok' => !$ko && $prog !== null, 'compte' => ['base' => $compte['base'] ?? '', 'configure' => !empty($compte['configure'])], 'aujourdhui' => fidAujourdhui(),
        'saison' => $saison ? ['id' => $saison['id'], 'nom' => $saison['nom'], 'enCours' => $saison['enCours']] : null, 'etapes' => $etapes,
        'resume' => $ko ? count($ko) . ' route(s) en échec : ' . implode(' ; ', array_map(static fn ($e) => $e['etape'] . ' (HTTP ' . $e['code'] . ')', $ko)) : ($prog ? 'Toutes les routes répondent.' : 'L’API répond, mais aucune carte à points active.')];
}

/** GET /fidelite/cron?jeton= — le lundi, les bons de la semaine close (campagnes ACTIVES seulement) ; chaque nuit, le cache se réchauffe. */
function ep_fidelite_cron(): array
{
    $jeton = (string) setting('rapportsJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) { http_response_code(403); return ['error' => 'jeton absent ou invalide']; }
    $lundi = (int) date('N') === 1 && (int) date('G') >= 6 && (int) date('G') < 7;
    if (!$lundi && empty($_GET['forcer'])) { return ['ok' => true, 'rien' => 'pas lundi 6 h']; }
    return fidBingoBons(true);
}
