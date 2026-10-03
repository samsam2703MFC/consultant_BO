<?php
declare(strict_types=1);

/*
 * LES OBJECTIFS DE LA TABLETTE — l'accueil des vendeuses : le chiffre
 * d'affaires et la vente additionnelle de la semaine et du mois, face à leur
 * objectif, en jauges. GET /tablette/objectifs ; contrat : docs/contrat-api.md.
 *
 * LE CA n'est pas recalculé ici : ce sont les chiffres de Résultat › Semaine et
 * › Mois (ep_exploitation_periode, resultat.php), ceux du dashboard magasin.
 *  · réalisé : le CA TTC encaissé (ventes caisse après remise — `ca` du
 *    margin-heatmap du panel, = `income` du daily-summary, taxe comprise),
 *    du lundi ou du 1er jusqu'à `date` incluse ;
 *  · objectif : le budget validé du mois (ceo_shop_month_perf.revenue_budget),
 *    à défaut le CA théorique de l'étude, réparti jour par jour par la
 *    pondération réseau sur les jours où le magasin ouvre — une semaine à
 *    cheval sur deux mois prend chaque jour dans le budget de son mois ;
 *  · attendu : la part de l'objectif des jours écoulés, `date` comprise.
 * Pour une `date` passée, réalisé et attendu s'arrêtent à elle : ils se
 * relisent jour par jour dans les lignes `jours` de Résultat.
 *
 * LA VENTE ADDITIONNELLE — décision du 02/10/2026 : « articles par ticket »,
 * la mesure de Ventes › primes. Elle se lit dans la moisson horaire des
 * tickets du panel (pvLignesJour, panel_ventes.php : ceo_app_setting
 * `pvL<magasin>:<jour>`, lignes et tickets par vendeuse, gravés une fois le
 * jour clos) ; aucun appel au panel ici.
 *  · une ligne = un produit encaissé sur le ticket, quelle que soit sa
 *    quantité (trois croissants sur une ligne comptent 1) ; tous les tickets
 *    du magasin, clients pro compris ;
 *  · parTicket = total des lignes ÷ total des tickets du lundi ou du 1er
 *    jusqu'à `date` — pas une moyenne de moyennes. La moisson s'arrête à la
 *    veille : aujourd'hui n'y est jamais, et un jour pas encore moissonné
 *    manque — `sources.venteAdd` dit combien de jours sont lus ;
 *  · cible = la target cross-selling du magasin (`venteCrossTargets`, en
 *    lignes par ticket, la dernière posée au plus tard le mois de `date`) ;
 *    le réseau n'a pas de cible : les targets sont posées par magasin.
 *
 * LE COÛT. Résultat lit tout le réseau au panel (~5 s par vue, mesuré) : les
 * deux vues et la moisson se calculent une fois pour TOUS les magasins et se gardent dix
 * minutes (ceo_app_setting `tabletteObjectifs:<date>`), une minute seulement
 * si une source a manqué ; un verrou MySQL évite que quatre tablettes qui
 * s'allument ensemble relancent quatre calculs. Sous PHP-FPM, un calcul complet
 * de moins de deux heures part tout de suite et le recalcul se fait APRÈS la
 * réponse (fastcgi_finish_request) : la tablette n'attend jamais les ~12 s ;
 * ailleurs (mod_php, serveur de dev), le calcul périmé se refait pendant l'appel.
 */

const TOB_SCHEMA = 1;
const TOB_CACHE_S = 600;                   // un calcul complet : dix minutes
const TOB_CACHE_TROUE_S = 60;              // une source a manqué : on réessaie vite
const TOB_VERROU_S = 30;                   // attente au plus du calcul d'un autre appel
const TOB_PERIME_S = 7200;                 // sous PHP-FPM : servi périmé jusqu'à deux heures, recalculé après la réponse

const TOB_MOISSON_DEBUT = '2026-08-01';    // premier jour moissonné (pvLignesDebut) : la caisse locale s'arrête au 14/07/2026

/**
 * GET /tablette/objectifs?shop=<shops.id>[&date=YYYY-MM-DD][&rafraichir=1]
 *
 * Sans magasin (ou magasin inconnu) : le réseau, `shop` null. `date` : le jour
 * de référence, aujourd'hui par défaut (une date future ou illisible → aujourd'hui).
 */
function ep_tablette_objectifs(): array
{
    @set_time_limit(120);
    try {
        $date = tobDate($_GET['date'] ?? null, date('Y-m-d'));
        $shop = tbMagasin(tbIdMagasin($_GET['shop'] ?? null));
        return tobReponse(tobBase($date, !empty($_GET['rafraichir'])), $shop, $date);
    } catch (Throwable $e) {
        http_response_code(500);
        return ['erreur' => 'objectifs indisponibles : ' . $e->getMessage()];
    }
}

/* --- Les bornes (pures) ------------------------------------------------------------------------ */

/** Le jour de référence : `YYYY-MM-DD` valide et pas dans le futur, sinon aujourd'hui. */
function tobDate(mixed $v, string $auj): string
{
    $s = is_scalar($v) ? trim((string) $v) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return $auj; }
    return min($s, $auj);
}

/**
 * La semaine (lundi → dimanche) et le mois calendaire qui contiennent `date`.
 *
 * @return array{semaine: array{0: string, 1: string}, mois: array{0: string, 1: string}}
 */
function tobBornes(string $date): array
{
    $d = new DateTimeImmutable($date . ' 12:00:00');
    $lundi = $d->modify('-' . ((int) $d->format('N') - 1) . ' days');
    return ['semaine' => [$lundi->format('Y-m-d'), $lundi->modify('+6 days')->format('Y-m-d')],
        'mois' => [$d->format('Y-m-01'), $d->format('Y-m-t')]];
}

/* --- Le calcul : Résultat › Semaine et › Mois, gardé dix minutes ------------------------------- */

/**
 * Les deux vues pour tous les magasins : du cache s'il est frais, sinon
 * recalculées sous verrou. `$perimeOk` : sous PHP-FPM, un calcul complet
 * périmé (moins de deux heures) est servi et le recalcul part après la réponse.
 */
function tobBase(string $date, bool $forcer, bool $perimeOk = true): array
{
    $cle = 'tabletteObjectifs:' . $date;
    $c = $forcer ? null : tobCacheLu($cle);
    if ($c !== null && $c['frais']) { return $c['base']; }
    if ($c !== null && $perimeOk && function_exists('fastcgi_finish_request')) {
        register_shutdown_function(static function () use ($date): void {
            try { fastcgi_finish_request(); @set_time_limit(120); tobBase($date, false, false); }
            catch (Throwable $e) { /* le prochain appel recalculera */ }
        });
        return $c['base'];
    }
    $verrou = false;
    try {
        $r = Db::row('SELECT GET_LOCK(?, ?) AS l', [$cle, TOB_VERROU_S]);
        $verrou = $r !== null && (int) $r['l'] === 1;
    } catch (Throwable $e) { /* sans verrou : on calcule quand même */ }
    try {
        // Un autre appel a pu finir le calcul pendant qu'on attendait le verrou.
        if (!$forcer && $verrou && ($c = tobCacheLu($cle)) !== null && $c['frais']) { return $c['base']; }
        $base = tobConstruire($date);
        $j = json_encode(['le' => time(), 'base' => $base], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($j)) {
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, $j]); }
            catch (Throwable $e) { /* sans cache : la réponse part quand même, recalculée au prochain appel */ }
        }
        return $base;
    } finally {
        if ($verrou) { try { Db::exec('DO RELEASE_LOCK(?)', [$cle]); } catch (Throwable $e) { /* verrou déjà rendu */ } }
    }
}

/**
 * Le calcul gardé : `frais` s'il a moins de dix minutes (une minute s'il est
 * troué) ; un calcul complet de moins de deux heures revient aussi, non frais ;
 * sinon null.
 *
 * @return array{base: array, frais: bool}|null
 */
function tobCacheLu(string $cle): ?array
{
    try { $c = setting($cle); } catch (Throwable $e) { return null; }
    if (!is_array($c) || !isset($c['le'], $c['base']['semaine'], $c['base']['mois'])) { return null; }
    $age = time() - (int) $c['le'];
    $complet = !empty($c['base']['complet']);
    if ($age < 0 || $age >= ($complet ? TOB_PERIME_S : TOB_CACHE_TROUE_S)) { return null; }
    return ['base' => $c['base'], 'frais' => $age < ($complet ? TOB_CACHE_S : TOB_CACHE_TROUE_S)];
}

/** Les deux vues de Résultat, réduites à ce que la tablette lit ; une vue en panne le dit sans arrêter l'autre. */
function tobConstruire(string $date): array
{
    $bornes = tobBornes($date);
    $base = ['genereLe' => date('c'), 'date' => $date, 'complet' => true];
    foreach (['semaine', 'mois'] as $vue) {
        try {
            $x = tobExtraire(tobPeriode($vue, $date), $date);
        } catch (Throwable $e) {
            $x = tobExtraire(['indispo' => true, 'motif' => 'Résultat › ' . ucfirst($vue) . ' illisible (' . $e->getMessage() . ')'], $date);
        }
        $x['du'] = $bornes[$vue][0];
        $x['au'] = $bornes[$vue][1];
        if (!$x['ok'] || $x['troue']) { $base['complet'] = false; }
        $base[$vue] = $x;
    }
    try {
        $base['venteAdd'] = tobVenteAdd($date, date('Y-m-d'), $bornes);
    } catch (Throwable $e) {
        $base['venteAdd'] = ['ok' => false, 'motif' => 'moisson des tickets illisible (' . $e->getMessage() . ')'];
        $base['complet'] = false;
    }
    return $base;
}

/**
 * Les articles par ticket de la semaine et du mois, pour chaque magasin actif
 * et le réseau, depuis la moisson des tickets (une seule requête groupée), et
 * la cible de chaque magasin. La moisson s'arrête à la veille.
 */
function tobVenteAdd(string $date, string $auj, array $bornes): array
{
    $shops = array_map(static fn ($s) => (string) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id'));
    $jusqua = min($date, date('Y-m-d', strtotime($auj . ' -1 day')));
    $debut = function_exists('pvLignesDebut') ? pvLignesDebut() . '-01' : TOB_MOISSON_DEBUT;
    $cles = [];
    foreach ($shops as $sid) {
        foreach (tobJours(min($bornes['semaine'][0], $bornes['mois'][0]), $jusqua) as $j) { $cles['pvL' . $sid . ':' . $j] = [$sid, $j]; }
    }
    $releves = [];
    foreach (array_chunk(array_keys($cles), 200) as $lot) {
        foreach (Db::rows('SELECT `key`, value FROM ceo_app_setting WHERE `key` IN (' . implode(',', array_fill(0, count($lot), '?')) . ')', $lot) as $r) {
            $x = tobReleve(json_decode((string) $r['value'], true));
            if ($x !== null && isset($cles[(string) $r['key']])) {
                [$sid, $j] = $cles[(string) $r['key']];
                $releves[$sid][$j] = $x;
            }
        }
    }
    $cfg = setting('venteCrossTargets');
    $cibles = [];
    foreach ($shops as $sid) {
        $c = (is_array($cfg) && function_exists('venteCrossTarget')) ? venteCrossTarget($cfg, $sid, substr($date, 0, 7)) : null;
        $cibles[$sid] = $c !== null ? round($c, 2) : null;
    }
    return ['ok' => true, 'motif' => null, 'jusqua' => $jusqua, 'debut' => $debut, 'cibles' => $cibles,
        'semaine' => tobVenteAddPeriode($releves, $shops, $bornes['semaine'][0], $jusqua),
        'mois' => tobVenteAddPeriode($releves, $shops, $bornes['mois'][0], $jusqua)];
}

/** GET /exploitation/periode?vue=&date= tel que le dashboard le lit, appelé sans changer la requête en cours. */
function tobPeriode(string $vue, string $date): array
{
    $sauve = $_GET;
    try {
        $_GET = ['vue' => $vue, 'date' => $date];
        return ep_exploitation_periode();
    } finally {
        $_GET = $sauve;
    }
}

/* --- La mise en forme (pure) ------------------------------------------------------------------- */

/** Les jours de $du à $au inclus (vide si $au < $du). */
function tobJours(string $du, string $au): array
{
    $out = [];
    for ($d = new DateTimeImmutable($du . ' 12:00:00'); $d->format('Y-m-d') <= $au; $d = $d->modify('+1 day')) { $out[] = $d->format('Y-m-d'); }
    return $out;
}

/**
 * Un relevé de la moisson (`pvL<magasin>:<jour>` = {quand, e: {vendeuse: {l, t}}})
 * réduit au jour-magasin : lignes et tickets, toutes vendeuses (et tickets sans
 * vendeuse) confondues ; null s'il n'est pas lisible. Un jour sans ticket
 * (`e` vide) est un relevé : zéro ticket.
 *
 * @return array{l: int, t: int}|null
 */
function tobReleve(mixed $v): ?array
{
    if (!is_array($v) || !isset($v['e']) || !is_array($v['e'])) { return null; }
    $l = 0; $t = 0;
    foreach ($v['e'] as $x) {
        if (!is_array($x)) { continue; }
        $l += (int) ($x['l'] ?? 0); $t += (int) ($x['t'] ?? 0);
    }
    return ['l' => $l, 't' => $t];
}

/**
 * Une étendue (de $du à $jusqua) : par magasin et pour le réseau, lignes ÷
 * tickets sur les jours moissonnés, et combien de jours le sont.
 *
 * @param array<string, array<string, array{l: int, t: int}>> $releves [magasin][jour]
 */
function tobVenteAddPeriode(array $releves, array $shops, string $du, string $jusqua): array
{
    $jours = tobJours($du, $jusqua);
    $out = ['du' => $du, 'jours' => count($jours), 'magasins' => [], 'reseau' => null];
    $lR = 0; $tR = 0; $lusR = 0;
    foreach ($shops as $sid) {
        $l = 0; $t = 0; $lus = 0;
        foreach ($jours as $j) {
            $x = $releves[$sid][$j] ?? null;
            if ($x === null) { continue; }
            $lus++; $l += $x['l']; $t += $x['t'];
        }
        $out['magasins'][(string) $sid] = tobParTicket($l, $t, $lus, count($jours));
        $lR += $l; $tR += $t; $lusR += $lus;
    }
    $out['reseau'] = tobParTicket($lR, $tR, $lusR, count($jours) * count($shops)) + ['magasins' => count($shops)];
    return $out;
}

/** @return array{parTicket: ?float, tickets: ?int, lignes: ?int, lus: int, attendus: int} */
function tobParTicket(int $l, int $t, int $lus, int $attendus): array
{
    return ['parTicket' => ($lus > 0 && $t > 0) ? round($l / $t, 2) : null, 'tickets' => $lus > 0 ? $t : null,
        'lignes' => $lus > 0 ? $l : null, 'lus' => $lus, 'attendus' => $attendus];
}

/**
 * D'une réponse de /exploitation/periode : par magasin et pour le réseau, le
 * réalisé, l'objectif et l'attendu à `date` incluse, la source de l'objectif.
 * `troue` : un magasin dont les ventes n'ont pas été lues.
 */
function tobExtraire(array $p, string $date): array
{
    $pj = is_array($p['ponderation'] ?? null) ? $p['ponderation'] : [];
    $out = ['ok' => empty($p['indispo']), 'motif' => !empty($p['indispo']) ? (string) ($p['motif'] ?? 'Résultat indisponible') : null,
        'troue' => false,
        'ponderation' => ['ok' => !empty($pj['ok']), 'adopteLe' => isset($pj['adopteLe']) ? (string) $pj['adopteLe'] : null,
            'motif' => isset($pj['motif']) ? (string) $pj['motif'] : null],
        'magasins' => [], 'reseau' => null];
    if (!$out['ok']) { return $out; }
    $jusqua = (string) ($p['jusqua'] ?? $date);
    foreach ((array) ($p['magasins'] ?? []) as $l) {
        if (!is_array($l) || !isset($l['shopId'])) { continue; }
        $c = tobCumul($l, $date, $jusqua);
        if ($c['realise'] === null) { $out['troue'] = true; }
        $out['magasins'][(string) $l['shopId']] = $c + [
            'source' => isset($l['objectifSource']) ? (string) $l['objectifSource'] : null,
            'sansBudget' => array_values(array_map('strval', (array) ($l['sansBudget'] ?? []))),
            'motif' => isset($l['motif']) ? (string) $l['motif'] : null];
    }
    if (is_array($p['reseau'] ?? null)) {
        $r = $p['reseau'];
        $out['reseau'] = tobCumul($r, $date, $jusqua) + ['magasins' => (int) ($r['magasins'] ?? 0),
            'avecObjectif' => (int) ($r['magasinsAvecObjectif'] ?? 0)];
    }
    return $out;
}

/**
 * Réalisé, objectif et attendu d'une ligne de Résultat (un magasin ou le
 * réseau), `date` incluse. Les jours sans ventes lues ou fermés comptent zéro ;
 * une ligne sans `jours` (margin-heatmap muet) rend trois null. L'objectif est
 * toujours le total de Résultat ; quand `date` est le dernier jour qu'il compte
 * (aujourd'hui, ou la fin d'une étendue close), réalisé et attendu aussi —
 * sinon la somme des jours, arrondis au centime (quelques centimes d'écart).
 *
 * @return array{realise: ?float, objectif: ?float, attendu: ?float}
 */
function tobCumul(array $l, string $date, string $jusqua): array
{
    $jours = $l['jours'] ?? null;
    if (!is_array($jours) || $jours === []) { return ['realise' => null, 'objectif' => null, 'attendu' => null]; }
    $realise = 0.0; $objectif = 0.0; $attendu = 0.0; $aObjectif = false;
    foreach ($jours as $j) {
        $d = is_array($j) ? (string) ($j['date'] ?? '') : '';
        if ($d === '') { continue; }
        if (isset($j['objectif']) && is_numeric($j['objectif'])) {
            $aObjectif = true;
            $objectif += (float) $j['objectif'];
            if ($d <= $date) { $attendu += (float) $j['objectif']; }
        }
        if ($d <= $date && isset($j['ca']) && is_numeric($j['ca'])) { $realise += (float) $j['ca']; }
    }
    // L'objectif est celui de l'étendue entière : le total de Résultat, quelle que soit `date`.
    if (isset($l['objectif']) && is_numeric($l['objectif'])) { $objectif = (float) $l['objectif']; }
    if ($date >= $jusqua) {
        if (isset($l['realise']) && is_numeric($l['realise'])) { $realise = (float) $l['realise']; }
        if (isset($l['attendu']) && is_numeric($l['attendu'])) { $attendu = (float) $l['attendu']; }
    }
    return ['realise' => round($realise, 2),
        'objectif' => $aObjectif ? round($objectif, 2) : null,
        'attendu' => $aObjectif ? round($attendu, 2) : null];
}

/** La réponse du contrat, pour un magasin (ou le réseau) tiré du calcul commun. */
function tobReponse(array $base, ?array $shop, string $date): array
{
    $sid = $shop['id'] ?? null;
    $ca = [];
    foreach (['semaine', 'mois'] as $vue) {
        $x = (array) ($base[$vue] ?? []);
        $v = $sid === null ? ($x['reseau'] ?? null) : ($x['magasins'][$sid] ?? null);
        $ca[$vue] = ['du' => (string) ($x['du'] ?? ''), 'au' => (string) ($x['au'] ?? ''),
            'realise' => $v['realise'] ?? null, 'objectif' => $v['objectif'] ?? null, 'attendu' => $v['attendu'] ?? null];
    }
    $va = is_array($base['venteAdd'] ?? null) ? $base['venteAdd'] : ['ok' => false, 'motif' => 'calcul gardé sans la moisson des tickets'];
    $venteAdd = [];
    foreach (['semaine', 'mois'] as $vue) {
        $x = !empty($va['ok']) ? ($sid === null ? ($va[$vue]['reseau'] ?? null) : ($va[$vue]['magasins'][$sid] ?? null)) : null;
        $venteAdd[$vue] = ['parTicket' => $x['parTicket'] ?? null,
            'cible' => ($sid !== null && !empty($va['ok'])) ? ($va['cibles'][$sid] ?? null) : null,
            'tickets' => $x['tickets'] ?? null];
    }
    return ['schema' => TOB_SCHEMA, 'genereLe' => (string) ($base['genereLe'] ?? date('c')), 'date' => $date, 'shop' => $shop,
        'ca' => $ca,
        'venteAdd' => $venteAdd,
        'sources' => ['ca' => tobSourceCa($base, $shop), 'venteAdd' => tobSourceVenteAdd($base, $shop)]];
}

/** La phrase de `sources.venteAdd` : la mesure, les jours moissonnés de CE magasin (ou du réseau), la cible. */
function tobSourceVenteAdd(array $base, ?array $shop): string
{
    $fr = static fn (string $d) => substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
    $va = is_array($base['venteAdd'] ?? null) ? $base['venteAdd'] : ['ok' => false, 'motif' => 'calcul gardé sans la moisson des tickets'];
    if (empty($va['ok'])) {
        return 'Articles par ticket indisponibles : ' . rtrim((string) ($va['motif'] ?? 'moisson illisible'), '. ') . ' — parTicket, cible et tickets à null.';
    }
    $date = (string) ($base['date'] ?? date('Y-m-d'));
    $jusqua = (string) $va['jusqua'];
    $parts = ['Articles par ticket = lignes de caisse ÷ tickets, la mesure de Ventes › primes, lue dans la moisson horaire des tickets '
        . 'du panel (/transactions/{id}?include=products, gravée par jour et par magasin) : une ligne = un produit encaissé sur le ticket, '
        . 'quelle que soit sa quantité (trois croissants sur une ligne comptent 1) ; tous les tickets du magasin, clients pro compris ; '
        . 'total des lignes ÷ total des tickets (pas une moyenne de moyennes), du lundi ou du 1er au ' . $fr($jusqua) . ' inclus'
        . ($jusqua < $date ? ' — la moisson s’arrête à la veille : le ' . $fr($date) . ' n’y est pas' : '')];
    $sid = $shop['id'] ?? null;
    $nom = (string) ($shop['nom'] ?? '');
    foreach (['semaine', 'mois'] as $vue) {
        $p = (array) ($va[$vue] ?? []);
        if ((int) ($p['jours'] ?? 0) === 0) { $parts[] = $vue . ' : aucun jour clos à lire (moisson à la veille) — parTicket et tickets à null'; continue; }
        $x = $sid === null ? ($p['reseau'] ?? null) : ($p['magasins'][$sid] ?? null);
        if ($x === null) { $parts[] = $vue . ' : ' . $nom . ' hors moisson (magasin inactif) — parTicket et tickets à null'; continue; }
        $t = $vue . ' : ' . $x['lus'] . '/' . $x['attendus'] . ($sid === null ? ' jours-magasin moissonnés (' . (int) ($x['magasins'] ?? 0) . ' magasins actifs)' : ' jours moissonnés');
        if ($x['lus'] < $x['attendus']) {
            $t .= ' — les autres attendent la moisson horaire' . ((string) ($p['du'] ?? '') < (string) $va['debut'] ? ' (rien avant le ' . $fr((string) $va['debut']) . ')' : '')
                . ($x['lus'] === 0 ? ', parTicket et tickets à null' : ', parTicket sur les jours lus');
        }
        if ($x['lus'] > 0 && (int) $x['tickets'] === 0) { $t .= ', aucun ticket'; }
        $parts[] = $t;
    }
    if ($sid === null) {
        $parts[] = 'cible : null — les targets se posent par magasin, il n’existe pas de cible réseau';
    } else {
        $c = $va['cibles'][$sid] ?? null;
        $parts[] = !array_key_exists($sid, (array) ($va['cibles'] ?? [])) ? 'cible : null — ' . $nom . ' n’est pas un magasin actif'
            : ($c !== null
            ? 'cible = la target cross-selling de ' . $nom . ', ' . str_replace('.', ',', (string) $c) . ' lignes par ticket (venteCrossTargets, la dernière posée au plus tard en '
                . substr($date, 5, 2) . '/' . substr($date, 0, 4) . ')'
            : 'cible : aucune target cross-selling posée pour ' . $nom . ' (venteCrossTargets) — null');
    }
    return implode(' ; ', $parts) . '.';
}

/** La phrase de `sources.ca` : la définition, puis ce qui vaut pour CE magasin (ou le réseau) et ce qui manque. */
function tobSourceCa(array $base, ?array $shop): string
{
    $fr = static fn (string $d) => substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4);
    $date = (string) ($base['date'] ?? date('Y-m-d'));
    $pj = $base['mois']['ponderation'] ?? ($base['semaine']['ponderation'] ?? []);
    $adopte = !empty($pj['adopteLe']) ? ' adoptée le ' . $fr(substr((string) $pj['adopteLe'], 0, 10)) : '';
    $parts = ['Résultat › Semaine et › Mois du BO (GET /exploitation/periode, le calcul du dashboard magasin) : réalisé = CA TTC '
        . 'encaissé (ventes caisse après remise, margin-heatmap du panel) du lundi ou du 1er au ' . $fr($date) . ' inclus ; '
        . 'objectif = le budget validé du mois, à défaut le CA théorique de l’étude, réparti jour par jour par la pondération réseau'
        . $adopte . ' sur les jours où le magasin ouvre (une semaine à cheval prend chaque jour dans le budget de son mois) ; '
        . 'attendu = la part de l’objectif des jours écoulés, ' . $fr($date) . ' compris'];
    $sid = $shop['id'] ?? null;
    $nom = (string) ($shop['nom'] ?? 'le réseau');
    foreach (['semaine', 'mois'] as $vue) {
        $x = (array) ($base[$vue] ?? []);
        if (empty($x['ok'])) { $parts[] = $vue . ' : ' . rtrim((string) ($x['motif'] ?? 'Résultat indisponible'), '. ') . ' — champs à null'; continue; }
        $sansPj = empty($x['ponderation']['ok'])
            ? 'pas d’objectif — ' . rtrim((string) ($x['ponderation']['motif'] ?? 'aucune pondération des jours adoptée'), '. ') : null;
        if ($sid === null) {
            $r = $x['reseau'] ?? null;
            if ($r === null || $r['realise'] === null) { $parts[] = $vue . ' : aucune vente de magasin lue — champs à null'; continue; }
            $parts[] = $vue . ' : réseau = ' . $r['magasins'] . ' magasin(s) avec des ventes'
                . ($sansPj !== null ? ', ' . $sansPj : ', objectif et attendu sur les ' . $r['avecObjectif'] . ' qui ont un objectif');
            continue;
        }
        $m = $x['magasins'][$sid] ?? null;
        if ($m === null) { $parts[] = $vue . ' : ' . $nom . ' absent de Résultat (magasin inactif) — champs à null'; continue; }
        if ($m['realise'] === null) { $parts[] = $vue . ' : ' . rtrim((string) ($m['motif'] ?? 'ventes illisibles'), '. ') . ' — champs à null'; continue; }
        if ($sansPj !== null) { $parts[] = $vue . ' : ' . $sansPj; continue; }
        $sans = implode(', ', array_map(static fn ($ym) => substr($ym, 5, 2) . '/' . substr($ym, 0, 4), $m['sansBudget']));
        if ($m['objectif'] === null) { $parts[] = $vue . ' : pas d’objectif — ni budget ni CA théorique' . ($sans !== '' ? ' pour ' . $sans : ''); continue; }
        $parts[] = $vue . ' : objectif ' . ($m['source'] === 'theorique' ? 'au CA théorique de l’étude (pas de budget validé)' : 'au budget validé')
            . ($sans !== '' ? ', partiel : rien pour ' . $sans : '');
    }
    try { $le = (new DateTimeImmutable((string) ($base['genereLe'] ?? 'now')))->format('d/m/Y à H:i'); }
    catch (Throwable $e) { $le = date('d/m/Y à H:i'); }
    $parts[] = 'calculé le ' . $le . ', gardé 10 min';
    return implode(' ; ', $parts) . '.';
}
