<?php
declare(strict_types=1);

/*
 * Structure de gamme — Catalogue › Structure de gamme (décidée le 11/11/2026).
 *
 * Par catégorie du panel, sur les ventes réseau de douze mois :
 *   - le STANDARD : les références vendues toute l'année qui font 80 % du CA (ou de la marge
 *     nette) de leur catégorie, 6 au plus ;
 *   - les SAISONS (hiver, printemps, été, automne) et les PÉRIODES (Noël, Nouvel An, Pâques) :
 *     la même règle sur les mois de la saison, 4 au plus ;
 *   - À DÉSACTIVER : moins de 3 % du CA de la catégorie, sur les mois où la référence se vend.
 *
 * Le calcul des statuts vit côté écran (public/assets/js/structure-regles.js) : on règle les
 * variables et l'écran se recalcule sans attendre le serveur. Ce module fournit :
 *   GET  /produits/structure             les données (le profil mensuel de chaque référence, son
 *                                        CA, sa marge, ses changements d'identifiant) et le réglage ;
 *   PUT  /produits/structure             les règles du réseau et les décisions référence par référence ;
 *   POST /produits/structure/desactiver  le retrait du catalogue des références sous le seuil — le
 *                                        garde-fou du retrait (« jamais une référence vendue ») est
 *                                        levé pour ce seul motif, et le serveur le vérifie lui-même ;
 *   POST /produits/structure/appliquer   l'assortiment obligatoire aligné sur la structure.
 *
 * Lectures réutilisées, aucune nouvelle source : /analyse/produits?mois=12 (ventes par mois, tranches
 * gravées une fois closes), le catalogue actif, le scoring (coût des références retirées, volume du
 * dernier mois). La maquette docs/maquettes/structure-gamme en tire la même chose hors ligne.
 */

const SG_CLE = 'structureGamme';
/** Les règles par défaut, en pourcentages et en nombres — celles de l'écran. */
const SG_REGLES = ['base' => 'ca', 'std' => 80, 'max' => 6, 'sai' => 4, 'seuil' => 3];
const SG_BORNES = ['std' => [50, 100], 'max' => [1, 20], 'sai' => [1, 10], 'seuil' => [0, 20]];
/** Ce qui n'est pas un produit à structurer : B2B, bundles et paniers, extras facturés. */
const SG_HORS_GRP = ['B. 2 B.', 'Bundle & Promotion'];
/** Les décisions par référence : garder en standard, hors standard, ou dans une saison ou une période choisie. */
const SG_DECISION_RE = '/^(standard|garder|saison:(hiver|printemps|ete|automne)|periode:(noel|nouvelan|paques))$/';
const SG_NOMS = ['hiver' => 'Hiver', 'printemps' => 'Printemps', 'ete' => 'Été', 'automne' => 'Automne',
    'noel' => 'Noël', 'nouvelan' => 'Nouvel An', 'paques' => 'Pâques'];

function sgDecisionValide(mixed $d): bool { return is_string($d) && preg_match(SG_DECISION_RE, $d) === 1; }

/** Une décision dite en mots, pour le journal. */
function sgDecisionMot(string $d): string
{
    if ($d === 'standard') { return 'gardée en standard'; }
    if ($d === 'garder') { return 'gardée hors standard'; }
    [$t, $k] = explode(':', $d) + [1 => ''];
    return ($t === 'saison' ? 'gardée en saison ' : 'gardée pour ') . (SG_NOMS[$k] ?? $k);
}

/** Une règle ramenée dans ses bornes. */
function sgBorne(string $k, mixed $v): int|float
{
    $x = is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : (float) SG_REGLES[$k];
    [$a, $b] = SG_BORNES[$k];
    $x = min($b, max($a, $x));
    return in_array($k, ['max', 'sai'], true) ? (int) round($x) : round($x, 1);
}

/** Le réglage du réseau : règles, décisions, dernière écriture. */
function sgReglage(): array
{
    $v = setting(SG_CLE, []);
    $v = is_array($v) ? $v : [];
    $r = array_merge(SG_REGLES, is_array($v['regles'] ?? null) ? $v['regles'] : []);
    foreach (array_keys(SG_BORNES) as $k) { $r[$k] = sgBorne($k, $r[$k]); }
    $r['base'] = ($r['base'] ?? 'ca') === 'mg' ? 'mg' : 'ca';
    $dec = [];
    foreach ((array) ($v['decisions'] ?? []) as $id => $d) {
        if (sgDecisionValide($d) && preg_match('/^\d{1,12}$/', (string) $id)) { $dec[(string) $id] = $d; }
    }
    return ['regles' => $r, 'decisions' => $dec, 'le' => $v['le'] ?? null];
}

/** Le nom ramené à ses mots : sans accents, sans pluriel, dans l'ordre alphabétique. */
function sgNorme(string $nom): string
{
    $s = mb_strtolower($nom, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y']);
    preg_match_all('/[a-z0-9]+/', $s, $m);
    $mots = [];
    foreach ($m[0] as $w) {
        if (in_array($w, ['de', 'du', 'la', 'le', 'les', 'au', 'aux', 'et'], true)) { continue; }
        $mots[] = strlen($w) > 3 && str_ends_with($w, 's') ? substr($w, 0, -1) : $w;
    }
    sort($mots);
    return implode(' ', $mots);
}

/** Proximité de deux noms normalisés, de 0 à 1 (comme difflib.SequenceMatcher.ratio). */
function sgProche(string $a, string $b): float
{
    if ($a === '' && $b === '') { return 1.0; }
    similar_text($a, $b, $pct);
    return $pct / 100;
}

/**
 * Les données de la structure, assemblées depuis les trois lectures.
 *
 * - le profil : les pièces de chaque référence mois par mois, tous magasins confondus ;
 * - la marge nette sur 12 mois : CA − commission − pièces × coût matière (null sans coût fiable) ;
 * - les changements d'identifiant : une référence retirée du catalogue dont une référence active du
 *   même groupe porte le même nom (au pluriel et aux accents près) lui transmet son historique —
 *   en octobre 2026, les tartes de « Tartes » sont passées sous « Tartes – Ø 28 cm » ;
 * - les doublons : deux références ACTIVES du même groupe au même nom sont signalées, pas fusionnées.
 *
 * @param array $ap  réponse de /analyse/produits?mois=12
 * @param array $cat le catalogue actif (ep_prod_catalogue)
 * @param array $sco le scoring (ep_products_revue)
 */
function sgAssembler(array $ap, array $cat, array $sco): array
{
    $mois = array_values($ap['tranches'] ?? []);
    $n = count($mois);
    $ventes = [];
    $total = array_fill(0, $n, 0.0);
    $plancher = null;
    foreach ((array) ($ap['produits'] ?? []) as $p) {
        $u = array_fill(0, $n, 0.0);
        foreach ((array) ($p['parShop'] ?? []) as $serie) {
            foreach ((array) $serie as $i => $v) { if ($i < $n) { $u[$i] += (float) $v; } }
        }
        for ($i = 0; $i < $n; $i++) { $total[$i] += $u[$i]; }
        $ventes[(int) $p['pid']] = ['u' => $u, 'ca' => (float) $p['ca'], 'nom' => (string) $p['nom'], 'cat' => (string) $p['cat']];
        $plancher = $plancher === null ? (float) $p['total'] : min($plancher, (float) $p['total']);
    }
    $sc = [];
    foreach ($sco as $s) { if (isset($s['id']) && is_numeric($s['id'])) { $sc[(int) $s['id']] = $s; } }
    $actifs = [];
    $grpCat = [];
    foreach ($cat as $p) {
        $pid = isset($p['pwaId']) && is_numeric($p['pwaId']) ? (int) $p['pwaId'] : (ctype_digit((string) ($p['ref'] ?? '')) ? (int) $p['ref'] : null);
        if ($pid === null) { continue; }
        $actifs[$pid] = $p;
        $grpCat[(string) $p['categorie']] ??= (string) ($p['groupe'] ?? '—');
    }

    // Les changements d'identifiant, au nom près.
    $parNom = [];
    foreach ($actifs as $pid => $p) { $parNom[($p['groupe'] ?? '') . '|' . sgNorme((string) $p['nom'])][] = $pid; }
    $heritage = [];
    $orphelins = [];
    foreach ($ventes as $pid => $v) {
        if (isset($actifs[$pid])) { continue; }
        $cible = $parNom[($grpCat[$v['cat']] ?? '') . '|' . sgNorme($v['nom'])] ?? [];
        if (count($cible) === 1) { $heritage[$cible[0]][] = $pid; } else { $orphelins[] = $pid; }
    }
    // Deuxième passe, à l'orthographe près (« Flan Brézillien » → « Flan Brésilienne ») : seulement vers une
    // référence active du même groupe, sans historique avant juin, qui n'a pas déjà hérité.
    $recente = static function (int $pid) use ($ventes, $n): bool {
        $u = $ventes[$pid]['u'] ?? null;
        if ($u === null) { return true; }
        foreach ($u as $i => $x) { if ($x > 0) { return $i >= 6; } }
        return true;
    };
    foreach ($orphelins as $pid) {
        $v = $ventes[$pid];
        $g = $grpCat[$v['cat']] ?? null;
        $cands = [];
        foreach ($actifs as $q => $p) {
            if (($p['groupe'] ?? null) !== $g || isset($heritage[$q]) || !$recente($q)) { continue; }
            $cands[] = [sgProche(sgNorme($v['nom']), sgNorme((string) $p['nom'])), $q];
        }
        usort($cands, static fn ($a, $b) => $b[0] <=> $a[0]);
        if ($cands && $cands[0][0] >= 0.8 && (count($cands) === 1 || $cands[1][0] < $cands[0][0] - 0.05)) {
            $heritage[$cands[0][1]][] = $pid;
        }
    }

    $marge = static function (?float $ca, float $pieces, ?float $prix, ?float $mat, ?float $com): ?float {
        if ($mat === null || $ca === null) { return null; }
        $taux = ($com && $prix) ? $com / $prix : 0.04;
        return round($ca * (1 - $taux) - $pieces * $mat, 0);
    };
    $arr = static fn (array $u): array => array_map(static fn ($x) => (int) round($x), $u);
    $gammes = static fn (array $l): array => array_values(array_map(static fn ($g) => trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) $g)), $l));

    $produits = [];
    $fusionnes = [];
    foreach ($actifs as $pid => $p) {
        $u = null; $ca = null; $anciens = [];
        if (isset($ventes[$pid])) { $u = $ventes[$pid]['u']; $ca = $ventes[$pid]['ca']; }
        foreach ($heritage[$pid] ?? [] as $vieux) {
            $v = $ventes[$vieux];
            $u = $u ?? array_fill(0, $n, 0.0);
            foreach ($v['u'] as $i => $x) { $u[$i] += $x; }
            $ca = ($ca ?? 0) + $v['ca'];
            $anciens[] = ['id' => $vieux, 'cat' => $v['cat']];
            $fusionnes[$vieux] = true;
        }
        $mat = !empty($p['matFiable']) && isset($p['mat']) ? (float) $p['mat'] : null;
        $prix = isset($p['prix']) ? (float) $p['prix'] : null;
        $produits[] = [
            'id' => $pid, 'ref' => (string) ($p['ref'] ?? $pid), 'nom' => trim((string) $p['nom']),
            'cat' => trim((string) $p['categorie']), 'grp' => (string) ($p['groupe'] ?? '—'), 'actif' => 1,
            'prix' => $prix, 'mat' => $mat,
            'u' => $u !== null ? $arr($u) : null, 'ca' => $ca !== null ? (int) round($ca) : null,
            'mg' => $u !== null ? $marge($ca, array_sum($u), $prix, $mat, isset($p['commission']) ? (float) $p['commission'] : null) : null,
            'gammes' => $gammes((array) ($p['periods'] ?? [])), 'must' => !empty($p['must']), 'saison' => $p['saison'] ?? null,
            'anciens' => $anciens,
            'v1' => $u === null && isset($sc[$pid]['volume']) ? (float) $sc[$pid]['volume'] : null,
        ];
    }
    // Les références retirées (non reprises) : la structure décide si elles reviennent pour une
    // période (œufs de Pâques, galette) ou si elles sont arrêtées.
    foreach ($ventes as $pid => $v) {
        if (isset($actifs[$pid]) || isset($fusionnes[$pid])) { continue; }
        $s = $sc[$pid] ?? [];
        $mat = isset($s['coutUnit']) && is_numeric($s['coutUnit']) ? (float) $s['coutUnit'] : null;
        $prix = isset($s['prix']) && is_numeric($s['prix']) ? (float) $s['prix'] : null;
        $produits[] = [
            'id' => $pid, 'ref' => (string) $pid, 'nom' => trim($v['nom']), 'cat' => trim($v['cat']), 'grp' => $grpCat[$v['cat']] ?? '—',
            'actif' => 0, 'prix' => $prix, 'mat' => $mat, 'u' => $arr($v['u']), 'ca' => (int) round($v['ca']),
            'mg' => $marge($v['ca'], array_sum($v['u']), $prix, $mat, null), 'gammes' => [], 'must' => false, 'saison' => null,
            'anciens' => [], 'v1' => null,
        ];
    }
    // Les doublons : même groupe, même nom, deux références actives.
    $vus = [];
    foreach ($produits as $i => $p) { if ($p['actif']) { $vus[$p['grp'] . '|' . sgNorme($p['nom'])][] = $i; } }
    foreach ($vus as $l) {
        if (count($l) < 2) { continue; }
        foreach ($l as $i) {
            $produits[$i]['doublon'] = array_values(array_map(static fn ($j) => ['id' => $produits[$j]['id'], 'cat' => $produits[$j]['cat']],
                array_filter($l, static fn ($j) => $j !== $i)));
        }
    }
    usort($produits, static fn ($a, $b) => [$a['grp'], $a['cat'], -($a['ca'] ?? 0)] <=> [$b['grp'], $b['cat'], -($b['ca'] ?? 0)]);

    return [
        'lu' => date('Y-m-d'), 'mois' => $mois, 'bornes' => $ap['bornes'] ?? [], 'jours' => $ap['jours'] ?? [],
        'magasins' => array_map(static fn ($m) => $m['nom'], (array) ($ap['magasins'] ?? [])),
        'total' => $arr($total), 'plancher' => (int) round((float) $plancher),
        'heritages' => count($fusionnes), 'produits' => $produits,
    ];
}

/** Les trois lectures, puis l'assemblage. */
function sgDonnees(): array
{
    if (class_exists('PanelApi') && !PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'compte panel non configuré (Mon compte)'];
    }
    @set_time_limit(180);
    $get = $_GET;
    $_GET = ['mois' => '12'];
    try { $ap = ep_analyse_produits(); } finally { $_GET = $get; }
    if (!empty($ap['indispo']) || empty($ap['produits'])) {
        return ['indispo' => true, 'motif' => $ap['motif'] ?? 'ventes de douze mois illisibles'];
    }
    $cat = ep_prod_catalogue();
    if (!$cat) { return ['indispo' => true, 'motif' => 'catalogue illisible']; }
    try { $sco = ep_products_revue(); } catch (Throwable $e) { $sco = []; }
    return sgAssembler($ap, $cat, $sco);
}

/** GET /produits/structure — les données et le réglage. */
function ep_produits_structure(): array
{
    $d = sgDonnees();
    return $d + ['reglage' => sgReglage()];
}

/**
 * PUT /produits/structure — { regles?: {base, std, max, sai, seuil}, decisions?: {id: 'standard'|'garder'|'saison:ete'|'periode:noel'|null} }
 * Les règles valent pour tout le réseau ; une décision, pour une référence. Chaque changement au journal.
 */
function wr_produits_structure(): array
{
    $b = body();
    $cur = sgReglage();
    $regles = $cur['regles'];
    $msgs = [];
    if (is_array($b['regles'] ?? null)) {
        $av = $regles;
        foreach (array_keys(SG_BORNES) as $k) { if (array_key_exists($k, $b['regles'])) { $regles[$k] = sgBorne($k, $b['regles'][$k]); } }
        if (isset($b['regles']['base'])) { $regles['base'] = $b['regles']['base'] === 'mg' ? 'mg' : 'ca'; }
        if ($regles != $av) {
            $msgs[] = 'Règles : standard ' . $regles['std'] . ' % ' . ($regles['base'] === 'mg' ? 'de la marge' : 'du CA') . ', ' . $regles['max']
                . ' max · saisons et périodes ' . $regles['sai'] . ' max · désactiver sous ' . str_replace('.', ',', (string) $regles['seuil']) . ' %';
        }
    }
    $dec = $cur['decisions'];
    $noms = is_array($b['noms'] ?? null) ? $b['noms'] : [];
    foreach ((array) ($b['decisions'] ?? []) as $id => $d) {
        $id = (string) $id;
        if (!preg_match('/^\d{1,12}$/', $id)) { continue; }
        $nom = mb_substr(trim((string) ($noms[$id] ?? ('réf. ' . $id))), 0, 120);
        if ($d === null || $d === '' || $d === 'auto') {
            if (isset($dec[$id])) { unset($dec[$id]); $msgs[] = $nom . ' : retour à la proposition'; }
        } elseif (sgDecisionValide($d) && ($dec[$id] ?? null) !== $d) {
            $dec[$id] = $d;
            $msgs[] = $nom . ' : ' . sgDecisionMot($d);
        }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [SG_CLE, json_encode(['regles' => $regles, 'decisions' => (object) $dec, 'le' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);
    foreach ($msgs as $m) { journalAdd('CEO', 'Structure de gamme', null, $m); }
    return ['ok' => true, 'reglage' => sgReglage()];
}

/**
 * La part de chaque référence active dans le CA de sa catégorie, sur douze mois — le contrôle du
 * serveur avant un retrait. Une référence que l'écran propose de désactiver pèse moins que le seuil
 * sur ses mois de vente, donc aussi sur l'année : cette part est une condition nécessaire, pas le
 * calcul de l'écran. Sous le plancher de lecture, la part est un plafond (plancher × prix).
 *
 * @return array<int,array{part:?float,max:?float,recente:bool,nom:string,cat:string,ref:string,must:bool}>
 */
function sgParts(array $d): array
{
    $caCat = [];
    foreach ($d['produits'] as $p) {
        if (in_array($p['grp'], SG_HORS_GRP, true) || preg_match('/^Extra\b/u', $p['cat'])) { continue; }
        $caCat[$p['cat']] = ($caCat[$p['cat']] ?? 0) + (float) ($p['ca'] ?? 0);
    }
    $out = [];
    foreach ($d['produits'] as $p) {
        if (!$p['actif']) { continue; }
        $tot = $caCat[$p['cat']] ?? 0;
        $out[(int) $p['id']] = [
            'part' => $p['u'] !== null && $tot > 0 ? (float) $p['ca'] / $tot : null,
            'max' => $p['u'] === null ? ($tot > 0 ? $d['plancher'] * (float) ($p['prix'] ?? 0) / $tot : null) : null,
            'recente' => $p['u'] === null && (float) ($p['v1'] ?? 0) >= $d['plancher'] / 3,
            'hors' => in_array($p['grp'], SG_HORS_GRP, true) || (bool) preg_match('/^Extra\b/u', $p['cat']),
            'nom' => $p['nom'], 'cat' => $p['cat'], 'ref' => $p['ref'], 'must' => (bool) $p['must'],
        ];
    }
    return $out;
}

/**
 * POST /produits/structure/desactiver — { ids: [pid…], confirmer: true }
 *
 * Le retrait du catalogue refuse d'ordinaire toute référence vendue sur douze mois. Ici, pour ce seul
 * motif, il l'accepte — mais le serveur recalcule la part de chaque référence et refuse celles qui
 * pèsent le seuil ou plus, les nouveautés (lancées le mois dernier) et ce qui n'est pas un produit.
 * Une obligatoire retirée du catalogue sort aussi de l'assortiment obligatoire. Une ligne de journal
 * par référence ; « Remettre au catalogue » (POST /produits/actif) défait le geste.
 */
function wr_produits_structure_desactiver(): array
{
    $b = body();
    if (empty($b['confirmer'])) { http_response_code(422); return ['error' => 'confirmation requise']; }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($b['ids'] ?? [])), static fn ($x) => $x > 0)));
    if (!$ids) { http_response_code(422); return ['error' => 'aucune référence fournie']; }
    if (count($ids) > 400) { http_response_code(422); return ['error' => 'trop de références en une fois (400 au plus)']; }
    $d = sgDonnees();
    if (!empty($d['indispo'])) { http_response_code(503); return ['error' => 'ventes illisibles — rien n’a été modifié : ' . ($d['motif'] ?? '')]; }
    $seuil = sgReglage()['regles']['seuil'] / 100;
    $parts = sgParts($d);
    $faits = []; $refuses = []; $inchangees = [];
    if (function_exists('aoEnsure')) { aoEnsure(); }
    foreach ($ids as $id) {
        $p = $parts[$id] ?? null;
        $refus = $p === null ? 'référence inconnue ou déjà inactive'
            : ($p['hors'] ? 'hors structure (B2B, bundle, extra)'
            : ($p['recente'] ? 'lancée le mois dernier — jugée à six mois'
            : ($p['part'] !== null && $p['part'] >= $seuil ? 'pèse ' . str_replace('.', ',', (string) round($p['part'] * 100, 1)) . ' % de sa catégorie'
            : ($p['part'] === null && ($p['max'] === null || $p['max'] >= $seuil) ? 'volume trop faible pour conclure' : null))));
        if ($refus !== null) { $refuses[] = ['id' => $id, 'nom' => $p['nom'] ?? (string) $id, 'motif' => $refus]; continue; }
        try {
            if (prodActifPoser($id, false) === 0) { $inchangees[] = $id; continue; }
        } catch (PDOException $e) {
            http_response_code(503);
            return ['error' => 'écriture refusée par la base : ' . $e->getMessage(), 'faits' => $faits, 'refuses' => $refuses];
        }
        $pct = $p['part'] !== null ? str_replace('.', ',', (string) round($p['part'] * 100, 1)) . ' %' : 'moins de ' . $d['plancher'] . ' pièces en 12 mois';
        journalAdd('CEO', 'Produit', $p['nom'], 'Retirée du catalogue (is_active = 0) — réf. ' . $p['ref']
            . ' — structure de gamme : ' . $pct . ' de « ' . $p['cat'] . ' », seuil ' . str_replace('.', ',', (string) ($seuil * 100)) . ' %');
        if ($p['must']) {
            // Hors du catalogue, elle ne peut plus être exigée : même écriture que PUT /production/obligatoire/{ref}.
            aoEcrireObligatoire($p['ref'], false, null, null, $p['nom'], ' — retirée du catalogue (structure de gamme)');
        }
        $faits[] = ['id' => $id, 'nom' => $p['nom']];
    }
    return ['ok' => true, 'faits' => $faits, 'refuses' => $refuses, 'inchangees' => $inchangees,
        'resume' => count($faits) . ' retirée(s) du catalogue'
            . ($refuses ? ', ' . count($refuses) . ' refusée(s)' : '') . ($inchangees ? ', ' . count($inchangees) . ' déjà inactive(s)' : '')];
}

/** La gamme du panel qui porte une saison ou une période de la structure, reconnue à son nom. */
function sgGammePanel(string $saison): ?int
{
    $motifs = ['hiver' => ['/hivern/u'], 'printemps' => ['/printan/u'], 'ete' => ['/estiv/u', '/été/u'], 'automne' => ['/automn/u'],
        'noel' => ['/no[eë]l/u'], 'nouvelan' => ['/piphanie/u', '/nouvel an/u'], 'paques' => ['/pascal/u', '/p[aâ]ques/u']][$saison] ?? [];
    $gammes = function_exists('aoGammesBrutes') ? aoGammesBrutes() : [];
    foreach ($motifs as $re) {
        foreach ($gammes as $g) {
            if ($g['active'] && !$g['permanente'] && preg_match($re, mb_strtolower($g['nom'] . ' ' . $g['nomPanel'], 'UTF-8'))) { return (int) $g['id']; }
        }
    }
    return null;
}

/**
 * POST /produits/structure/appliquer — { standard: [ref…], saisons: [{ref, saison}…], retirer: [ref…], confirmer: true }
 *
 * L'assortiment obligatoire suit la structure : le standard devient obligatoire toute l'année, une
 * référence de saison ou de période l'est pendant la gamme du panel qui la porte (Hivernale,
 * Printanière, Estivale, Automnale, Noël & Nouvel An, Épiphanie, Pascale). « retirer » : les
 * obligatoires d'aujourd'hui que la structure ne retient pas — seulement si l'écran l'a demandé.
 * La quantité minimale en place est gardée. L'écriture est celle de PUT /production/obligatoire/{ref}
 * (aoEcrireObligatoire), référence par référence, avec sa ligne de journal.
 */
function wr_produits_structure_appliquer(): array
{
    $b = body();
    if (empty($b['confirmer'])) { http_response_code(422); return ['error' => 'confirmation requise']; }
    if (function_exists('aoEnsure')) { aoEnsure(); }
    $refOk = static fn ($r) => is_string($r) || is_int($r) ? (preg_match('/^[\w-]{1,24}$/', (string) $r) ? (string) $r : null) : null;
    $lignes = [];
    foreach ((array) ($b['standard'] ?? []) as $r) { if (($r = $refOk($r)) !== null) { $lignes[$r] = [true, null]; } }
    $sansGamme = [];
    foreach ((array) ($b['saisons'] ?? []) as $x) {
        $r = $refOk($x['ref'] ?? null);
        if ($r === null || isset($lignes[$r])) { continue; }
        $g = sgGammePanel((string) ($x['saison'] ?? ''));
        if ($g === null) { $sansGamme[] = $r; continue; }
        $lignes[$r] = [true, $g];
    }
    foreach ((array) ($b['retirer'] ?? []) as $r) { if (($r = $refOk($r)) !== null && !isset($lignes[$r])) { $lignes[$r] = [false, null]; } }
    if (!$lignes) { http_response_code(422); return ['error' => 'rien à appliquer']; }
    if (count($lignes) > 600) { http_response_code(422); return ['error' => 'trop de références en une fois']; }

    // Une seule écriture de l'obligatoire, celle de la route PUT /production/obligatoire/{ref} : même
    // contrôle des gammes, même fiche cockpit, même journal. Ce qui est déjà dans l'état voulu n'est pas
    // réécrit (pas de ligne de journal pour rien).
    $n = ['annee' => 0, 'saison' => 0, 'retirees' => 0, 'inchangees' => 0];
    $erreurs = [];
    foreach ($lignes as $ref => [$must, $saison]) {
        $av = null;
        try { $av = Db::row('SELECT must, saison_id FROM ceo_prod_product WHERE ref = ?', [$ref]); } catch (Throwable $e) { /* table absente */ }
        $avSaison = $av !== null && $av['saison_id'] !== null ? (int) $av['saison_id'] : null;
        if (($av === null && !$must) || ($av !== null && (bool) $av['must'] === $must && (!$must || $avSaison === $saison))) { $n['inchangees']++; continue; }
        $r = aoEcrireObligatoire((string) $ref, $must, $saison, null, '', ' — structure de gamme');
        if (empty($r['ok'])) { $erreurs[] = ['ref' => $ref, 'motif' => $r['error'] ?? 'refusée']; continue; }
        $n[!$must ? 'retirees' : ($r['saison'] !== null ? 'saison' : 'annee')]++;
    }
    return ['ok' => true] + $n + ['sansGamme' => $sansGamme, 'erreurs' => $erreurs,
        'resume' => $n['annee'] . ' obligatoire(s) toute l’année, ' . $n['saison'] . ' pendant leur saison, ' . $n['retirees'] . ' retirée(s)'
            . ($n['inchangees'] ? ', ' . $n['inchangees'] . ' déjà en place' : '')
            . ($sansGamme ? ', ' . count($sansGamme) . ' sans gamme du panel pour leur saison' : '')
            . ($erreurs ? ', ' . count($erreurs) . ' refusée(s)' : '')];
}
