<?php
declare(strict_types=1);

/**
 * Vérification — lot 4 : matières ↔ recettes (31/10/2026). Plan : docs/plan-verification.md § 3.3 ; maquettes c et
 * g-matieres. Le moteur est celui de l'écran Inventaires (src/inventaires.php, invEcart) : entre deux inventaires
 * consécutifs d'un magasin, matière par matière,
 *
 *   consommé réel = compté au premier inventaire + entré (commandes validées à la réception, lues dans le stock du
 *                   panel au moment où elles passent) − compté au second ;
 *   expliqué      = ventes de caisse × recette aplatie (portions comprises) + poubelle déclarée × recette ;
 *   écart         = consommé réel − expliqué (quantité, % du consommé, € au prix d'achat moyen HTVA).
 *
 * Ce que ce lot ajoute : la CAUSE probable de chaque écart (règles du cockpit, à confirmer en visite), les
 * tolérances réglables, le tableau par catégorie de matière (même structure pour chaque magasin), le cumul de
 * plusieurs semaines, la vue réseau, et l'historique : chaque semaine calculée entre dans `ceo_verif_inventaire`, que
 * l'écran Évolution lit (« matière expliquée »).
 *
 * Les causes, dans l'ordre :
 *  · entree      — vendu ou jeté plus que sorti du stock : une commande reçue sans être validée à la réception
 *                  (le stock du panel n'a pas monté), ou une recette surdosée ;
 *  · recette     — sorti sans qu'aucune vente l'emploie (recette manquante ou incomplète), un ordre de grandeur
 *                  d'écart (unité, quantité de la recette), ou un produit qui l'emploie est dans « Recettes à corriger » ;
 *  · tolerance   — sous le seuil de la matière (pièces 3 %, poids et volumes 8 % : réglable, `verifTolerances`) ;
 *  · poubelle    — manque, et rien de jeté déclaré sur les produits qui l'emploient : poubelle non déclarée probable ;
 *  · horsCaisse  — manque alors que la poubelle est déclarée : ni vendu ni jeté — vente hors caisse, offert,
 *                  consommation de l'équipe, à voir en visite.
 *
 * GET /verification/matieres[?shop=&de=&a=&semaines=1|4] — sans magasin : le réseau (la dernière semaine de chaque
 * magasin) ; avec : les semaines du magasin, la semaine choisie (ou le cumul des 4 dernières), la lecture complète.
 */

const VM_V = 1;                   // version de la forme gardée : une semaine gardée sous une autre se recalcule
const VM_SEC_DEMANDE = 25.0;      // secondes de calcul au plus à la demande ; le cron fait le reste
const VM_SEC_CRON = 45.0;
const VM_SEMAINES_MAX = 12;       // semaines proposées par magasin
const VM_RAPPORT = 6.0;           // vendu et sorti à plus de six fois l'un de l'autre : unité ou recette
const VM_TOL_DEFAUT = ['pce' => 3.0, 'g' => 8.0, 'ml' => 8.0];
const VM_BRUIT_EUR = 5.0;        // un écart de moins de 5 € au coût, ou d'une pièce au plus : du bruit de comptage
const VM_SEUILS = [3.0, 6.0];     // écart matière (% du consommé) : vert ≤ 3 %, orange ≤ 6 % — comme le non expliqué
const VM_AGE_INVENTAIRE = 8;      // jours : au-delà, l'inventaire hebdomadaire manque
const VM_CAUSES = [
    'entree'     => 'Entrées non validées',
    'recette'    => 'Recettes incomplètes ou fausses',
    'poubelle'   => 'Poubelle non déclarée (probable)',
    'horsCaisse' => 'Ni vendu ni jeté (hors caisse probable)',
    'tolerance'  => 'Tolérance de fabrication',
];

/** Les tolérances : par unité (pce, g, ml), et par catégorie de matière si le CEO en règle une. */
function vmTolerances(): array
{
    $t = ['unite' => VM_TOL_DEFAUT, 'categorie' => []];
    try { $s = setting('verifTolerances'); } catch (Throwable $e) { $s = null; }
    if (is_array($s)) {
        foreach ((array) ($s['unite'] ?? []) as $u => $v) { if (is_numeric($v)) { $t['unite'][(string) $u] = (float) $v; } }
        foreach ((array) ($s['categorie'] ?? []) as $c => $v) { if (is_numeric($v)) { $t['categorie'][(string) $c] = (float) $v; } }
    }
    return $t;
}

function vmTolerance(array $T, string $unite, string $cat): float
{
    return (float) ($T['categorie'][$cat] ?? $T['unite'][$unite] ?? 5.0);
}

/**
 * Les semaines d'un magasin : les paires d'inventaires CONSÉCUTIFS relevés en direct (le panel ne rend pas ses
 * inventaires passés ; la copie locale s'arrête à la mi-juillet), du plus récent au plus ancien. Un inventaire
 * partiel (moins de la moitié des matières d'un comptage habituel) ne borne pas de semaine.
 */
function vmSemaines(int $sid): array
{
    $l = array_values(array_filter(function_exists('invListe') ? invListe($sid) : [], static fn ($x) => ($x['source'] ?? '') === 'releve'));
    $ns = array_map(static fn ($x) => (int) $x['n'], $l);
    sort($ns);
    $med = $ns === [] ? 0 : $ns[intdiv(count($ns), 2)];
    $pleins = array_values(array_filter($l, static fn ($x) => (int) $x['n'] >= 0.5 * $med));
    $out = [];
    for ($i = 0; $i + 1 < count($pleins) && count($out) < VM_SEMAINES_MAX; $i++) {
        $B = $pleins[$i]; $A = $pleins[$i + 1];
        $out[] = ['de' => $A['id'], 'a' => $B['id'], 'deAt' => $A['at'], 'aAt' => $B['at'],
            'jours' => round((strtotime($B['at']) - strtotime($A['at'])) / 86400, 1)];
    }
    return ['semaines' => $out, 'dernier' => $pleins[0]['at'] ?? ($l[0]['at'] ?? null), 'inventaires' => count($l)];
}

/** La forme gardée d'une semaine : ce que l'écran lit, sans les mouvements ni la suite des jours. */
function vmCompacte(array $r): array
{
    $m = [];
    foreach ($r['matieres'] as $l) {
        $m[] = ['id' => (int) $l['id'], 'nom' => (string) $l['nom'], 'cat' => (string) ($l['categorie'] ?? ''), 'u' => (string) ($l['unite'] ?? 'pce'),
            'p' => $l['prix'], 'd' => (float) $l['debut'], 'e' => (float) $l['entrees'], 'f' => (float) $l['fin'], 's' => (float) $l['sorties'],
            'v' => (float) $l['ventes'], 'w' => (float) $l['poubelle'],
            'pr' => array_map(static fn ($p) => [(int) $p['id'], (string) $p['nom']], array_slice((array) ($l['produits'] ?? []), 0, 4))];
    }
    return ['v' => VM_V, 'de' => $r['de'], 'a' => $r['a'], 'jours' => (float) $r['jours'], 'm' => $m,
        'sansRecette' => array_slice((array) ($r['sansRecette'] ?? []), 0, 12), 'caSansRecette' => (float) ($r['totaux']['caSansRecette'] ?? 0),
        'ca' => (float) ($r['totaux']['ca'] ?? 0), 'horsInventaire' => (float) ($r['totaux']['horsInventaire'] ?? 0),
        'complet' => (bool) $r['ventesCompletes'], 'notes' => (array) ($r['notes'] ?? []), 'le' => time()];
}

/** Une semaine d'un magasin, gardée ; `$calculer` faux : seulement ce qui est déjà gardé. */
function vmSemaine(int $sid, string $de, string $a, bool $calculer): ?array
{
    $cle = 'vmP:' . $sid . ':' . $de . ':' . $a;
    try { $g = setting($cle); } catch (Throwable $e) { $g = null; }
    if (is_array($g) && (int) ($g['v'] ?? 0) === VM_V) { return $g; }
    if (!$calculer || !function_exists('invEcart')) { return null; }
    try { $r = invEcart($sid, $de, $a); } catch (Throwable $e) { return null; }
    if ($r === null) { return null; }
    $c = vmCompacte($r);
    if ($c['complet']) {
        try { svGrave($cle, $c); } catch (Throwable $e) { /* sans base : recalculé la prochaine fois */ }
        vmEnregistre($sid, $c);
    }
    return $c;
}

/** Plusieurs semaines bout à bout (du plus ancien au plus récent) : début du premier, fin du dernier, le reste s'additionne. */
function vmCumul(array $ws): ?array
{
    if ($ws === []) { return null; }
    if (count($ws) === 1) { return $ws[0]; }
    $acc = []; $ordre = [];
    foreach ($ws as $k => $w) {
        foreach ($w['m'] as $l) {
            $id = $l['id'];
            if (!isset($acc[$id])) { $acc[$id] = $l + ['_k' => $k]; $ordre[] = $id; continue; }
            $x = &$acc[$id];
            foreach (['e', 's', 'v', 'w'] as $c) { $x[$c] += $l[$c]; }
            $x['f'] = $l['f'];
            if ($l['p'] !== null) { $x['p'] = $l['p']; }
            $x['pr'] = $x['pr'] ?: $l['pr'];
            unset($x);
        }
    }
    $sr = [];
    foreach ($ws as $w) { foreach ($w['sansRecette'] as $p) { $k = (int) $p['id']; $sr[$k] = isset($sr[$k]) ? ['q' => $sr[$k]['q'] + $p['q'], 'ca' => $sr[$k]['ca'] + $p['ca']] + $p : $p; } }
    usort($sr, static fn ($x, $y) => $y['ca'] <=> $x['ca']);
    $somme = static fn (string $k) => array_sum(array_map(static fn ($w) => (float) $w[$k], $ws));
    return ['v' => VM_V, 'de' => $ws[0]['de'], 'a' => $ws[count($ws) - 1]['a'], 'jours' => $somme('jours'),
        'm' => array_map(static fn ($id) => array_diff_key($acc[$id], ['_k' => 1]), $ordre), 'sansRecette' => array_slice($sr, 0, 12),
        'caSansRecette' => $somme('caSansRecette'), 'ca' => $somme('ca'), 'horsInventaire' => $somme('horsInventaire'),
        'complet' => !in_array(false, array_column($ws, 'complet'), true), 'notes' => array_merge(...array_map(static fn ($w) => $w['notes'], $ws)), 'semaines' => count($ws)];
}

/**
 * La cause probable de l'écart d'une matière — fonction pure (bin/tests_verification.php).
 * $l : une ligne compacte ; $tol : la tolérance (%) ; $signales : [pid => 'nom de la règle'] des produits du magasin
 * dans « Recettes à corriger ». Rend [code|null, texte].
 */
function vmCause(array $l, float $tol, array $signales): array
{
    $s = (float) $l['s']; $e = (float) $l['v'] + (float) $l['w']; $x = $s - $e;
    $noms = implode(', ', array_slice(array_map(static fn ($p) => $p[1], (array) $l['pr']), 0, 3));
    if ($s <= 0 && $e <= 0) { return [null, '']; }
    $pct = $s > 0 ? 100 * $x / $s : null;
    if ($s > 0 && $e > 0 && max($s / $e, $e / $s) >= VM_RAPPORT) {
        return ['recette', 'Un ordre de grandeur entre le sorti et le vendu : l’unité de la matière ou la quantité de la recette' . ($noms !== '' ? ' (' . $noms . ')' : '') . ' est à vérifier.'];
    }
    if ($pct !== null && abs($pct) <= $tol) { return ['tolerance', 'Dans la tolérance (' . vmNb($tol) . ' %).']; }
    if (($l['u'] === 'pce' && abs($x) <= 1.0) || ($l['p'] !== null && abs($x * (float) $l['p']) < VM_BRUIT_EUR)) {
        return ['tolerance', 'Un écart d’une pièce au plus, ou de moins de ' . vmNb(VM_BRUIT_EUR) . ' € : du bruit de comptage.'];
    }
    if ($s <= 0 || $x < 0) {
        return ['entree', $s <= 0
            ? 'Le stock a monté sans entrée validée : une commande reçue n’a pas été validée à la réception (le panel ne l’a pas vue).'
            : 'Vendu ou jeté plus que sorti du stock : une commande reçue sans validation à la réception, ou une recette surdosée' . ($noms !== '' ? ' (' . $noms . ')' : '') . '.'];
    }
    if ($e <= 0) { return ['recette', 'Sorti du stock, aucune vente ne l’emploie : une recette manquante ou incomplète (ou de la poubelle non déclarée).']; }
    foreach ((array) $l['pr'] as [$pid, $nom]) {
        if (isset($signales[(int) $pid])) { return ['recette', 'Recette signalée dans « Recettes à corriger » : ' . $nom . ' (' . $signales[(int) $pid] . ').']; }
    }
    if ((float) $l['w'] <= 0) { return ['poubelle', 'Rien de jeté déclaré' . ($noms !== '' ? ' sur ' . $noms : '') . ' : de la poubelle non déclarée, probablement.']; }
    return ['horsCaisse', 'Ni vendu ni jeté, alors que la poubelle est déclarée : vente hors caisse, offert ou consommation de l’équipe — à voir en visite.'];
}

function vmNb(float $v): string { return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ','); }

/** Un compteur en euros (au coût). */
function vmZero(): array
{
    return ['debut' => 0.0, 'entrees' => 0.0, 'fin' => 0.0, 'sorties' => 0.0, 'ventes' => 0.0, 'poubelle' => 0.0, 'explique' => 0.0, 'ecart' => 0.0,
        'sortiPos' => 0.0, 'couvert' => 0.0, 'manquant' => 0.0, 'excedent' => 0.0, 'n' => 0, 'sansPrix' => 0];
}

function vmAjoute(array &$a, array $l): void
{
    $a['n']++;
    if ($l['p'] === null) { $a['sansPrix']++; return; }
    $p = (float) $l['p']; $e = $l['v'] + $l['w']; $x = $l['s'] - $e;
    $a['debut'] += $l['d'] * $p; $a['entrees'] += $l['e'] * $p; $a['fin'] += $l['f'] * $p; $a['sorties'] += $l['s'] * $p;
    $a['ventes'] += $l['v'] * $p; $a['poubelle'] += $l['w'] * $p; $a['explique'] += $e * $p; $a['ecart'] += $x * $p;
    if ($l['s'] > 0) { $a['sortiPos'] += $l['s'] * $p; $a['couvert'] += min(max($e, 0.0), $l['s']) * $p; }
    if ($x > 0) { $a['manquant'] += $x * $p; } else { $a['excedent'] += -$x * $p; }
}

/** Taux et ton d'un compteur : la matière expliquée (part du consommé que ventes et poubelle expliquent) et l'écart. */
function vmTaux(array $a): array
{
    $o = [];
    foreach ($a as $k => $v) { $o[$k] = is_float($v) ? round($v, 2) : $v; }
    $o['explication'] = $a['sortiPos'] > 0 ? round(100 * $a['couvert'] / $a['sortiPos'], 1) : null;
    $o['ecartPct'] = $o['explication'] === null ? null : round(100 - $o['explication'], 1);
    $o['ton'] = $o['ecartPct'] === null ? 'mu' : ($o['ecartPct'] <= VM_SEUILS[0] ? 'ok' : ($o['ecartPct'] <= VM_SEUILS[1] ? 'att' : 'ko'));
    return $o;
}

/**
 * La lecture d'une semaine (ou d'un cumul) : chaque matière avec sa cause, les catégories, l'attribution de l'écart,
 * les totaux et le verdict — fonction pure (bin/tests_verification.php).
 */
function vmLecture(array $w, array $T, array $signales): array
{
    $lignes = []; $cats = []; $tot = vmZero();
    $attr = ['manque' => [], 'depasse' => []];
    foreach (array_keys(VM_CAUSES) as $c) { $attr['manque'][$c] = ['eur' => 0.0, 'n' => 0, 'noms' => []]; $attr['depasse'][$c] = ['eur' => 0.0, 'n' => 0, 'noms' => []]; }
    foreach ($w['m'] as $l) {
        $tol = vmTolerance($T, $l['u'], $l['cat']);
        [$code, $txt] = vmCause($l, $tol, $signales);
        $cat = $l['cat'] !== '' ? $l['cat'] : 'Sans catégorie';
        if (!isset($cats[$cat])) { $cats[$cat] = ['nom' => $cat, 'c' => vmZero(), 'm' => []]; }
        vmAjoute($cats[$cat]['c'], $l); vmAjoute($tot, $l);
        $p = $l['p'] === null ? null : (float) $l['p'];
        $e = $l['v'] + $l['w']; $x = $l['s'] - $e;
        $L = ['id' => $l['id'], 'nom' => $l['nom'], 'cat' => $cat, 'unite' => $l['u'], 'prix' => $p,
            'debut' => round($l['d'], 3), 'entrees' => round($l['e'], 3), 'fin' => round($l['f'], 3), 'sorties' => round($l['s'], 3),
            'ventes' => round($l['v'], 3), 'poubelle' => round($l['w'], 3), 'explique' => round($e, 3), 'ecart' => round($x, 3),
            'ecartPct' => $l['s'] > 0 ? round(100 * $x / $l['s'], 1) : null, 'ecartEur' => $p === null ? null : round($x * $p, 2),
            'tolerance' => $tol, 'cause' => $code, 'pourquoi' => $txt, 'produits' => array_map(static fn ($q) => $q[1], (array) $l['pr'])];
        $L['ton'] = $code === null ? 'mu' : ($code === 'tolerance' ? 'ok' : (in_array($code, ['entree', 'recette'], true) ? 'att' : 'ko'));
        $lignes[] = $L;
        $cats[$cat]['m'][] = $L;
        if ($code !== null && $p !== null && abs($x) > 1e-9) {
            $cote = $x > 0 ? 'manque' : 'depasse';
            $attr[$cote][$code]['eur'] += abs($x) * $p; $attr[$cote][$code]['n']++;
            $attr[$cote][$code]['noms'][] = [$l['nom'], abs($x) * $p];
        }
    }
    // Les catégories : dans l'ordre alphabétique (le même pour chaque magasin), leurs matières par écart en euros.
    ksort($cats, SORT_NATURAL | SORT_FLAG_CASE);
    $parEcart = static fn ($a, $b) => (abs($b['ecartEur'] ?? 0) <=> abs($a['ecartEur'] ?? 0)) ?: strcmp($a['nom'], $b['nom']);
    $categories = [];
    foreach ($cats as $c) { usort($c['m'], $parEcart); $categories[] = ['nom' => $c['nom'], 'matieres' => $c['m']] + vmTaux($c['c']); }
    usort($lignes, $parEcart);
    $A = ['manque' => [], 'depasse' => []];
    foreach ($attr as $cote => $cs) {
        foreach ($cs as $code => $x) {
            if ($x['n'] === 0) { continue; }
            usort($x['noms'], static fn ($a, $b) => $b[1] <=> $a[1]);
            $A[$cote][] = ['code' => $code, 'nom' => VM_CAUSES[$code], 'eur' => round($x['eur'], 2), 'n' => $x['n'], 'exemples' => array_map(static fn ($q) => $q[0], array_slice($x['noms'], 0, 4))];
        }
        usort($A[$cote], static fn ($a, $b) => $b['eur'] <=> $a['eur']);
    }
    $t = vmTaux($tot);
    $nEntree = count(array_filter($lignes, static fn ($l) => $l['cause'] === 'entree'));
    $sansEntree = $t['entrees'] <= 0 && $t['n'] > 0;
    $verdict = $t['n'] === 0 ? ['ton' => 'mu', 'nom' => 'Pas de données', 'pourquoi' => '']
        : ($sansEntree ? ['ton' => 'att', 'nom' => 'Entrées non validées', 'pourquoi' => 'Aucune commande validée à la réception entre les deux inventaires : le stock a monté sans entrée, le consommé réel est faux par défaut.']
        : ($t['ton'] === 'ok' ? ['ton' => 'ok', 'nom' => 'La matière s’explique', 'pourquoi' => 'Ventes et poubelle expliquent ' . vmNb((float) $t['explication']) . ' % du consommé.']
        : ['ton' => $t['ton'], 'nom' => $t['ton'] === 'att' ? 'À suivre' : 'À regarder', 'pourquoi' => vmNb((float) $t['ecartPct']) . ' % du consommé sans explication ('
            . number_format($t['manquant'], 0, ',', ' ') . ' € au coût).']));
    return ['totaux' => $t, 'verdict' => $verdict, 'attribution' => $A, 'categories' => $categories, 'matieres' => $lignes,
        'nEntreeManquante' => $nEntree, 'sansEntree' => $sansEntree];
}

/** Les produits du magasin dans « Recettes à corriger » (lot 1, la dernière liste gardée) : [sid => [pid => règle]]. */
function vmSignales(): array
{
    try { $c = setting('vfAnalyse'); } catch (Throwable $e) { return []; }
    $out = [];
    foreach ((array) (($c['v'] ?? [])['lignes'] ?? []) as $l) {
        if (!is_array($l) || !isset($l['pid'])) { continue; }
        foreach ((array) ($l['shops'] ?? []) as $sid) { $out[(int) $sid][(int) $l['pid']] = (string) ($l['nomRegle'] ?? $l['regle'] ?? ''); }
    }
    return $out;
}

/* ---------------------------------------------------------------------------------------------
 * L'historique : chaque semaine calculée entre dans ceo_verif_inventaire (l'écran Évolution la lit)
 * ------------------------------------------------------------------------------------------- */

function vmTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_verif_inventaire ('
        . 'shop_id INT NOT NULL, de VARCHAR(16) NOT NULL, a VARCHAR(16) NOT NULL, de_at DATETIME NULL, a_at DATETIME NULL, jours DECIMAL(5,1) NULL,'
        . 'debut_eur DECIMAL(12,2) NULL, entrees_eur DECIMAL(12,2) NULL, fin_eur DECIMAL(12,2) NULL, sorties_eur DECIMAL(12,2) NULL,'
        . 'ventes_eur DECIMAL(12,2) NULL, poubelle_eur DECIMAL(12,2) NULL, sorti_pos_eur DECIMAL(12,2) NULL, couvert_eur DECIMAL(12,2) NULL,'
        . 'manquant_eur DECIMAL(12,2) NULL, excedent_eur DECIMAL(12,2) NULL, matieres SMALLINT NULL, calcule_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, de, a), KEY (a_at)'
        . ') DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Écrit (ou réécrit) les totaux d'une semaine calculée, sans les causes (elles dépendent des réglages du jour). */
function vmEnregistre(int $sid, array $w): bool
{
    try {
        vmTable();
        $t = vmZero();
        foreach ($w['m'] as $l) { vmAjoute($t, $l); }
        Db::exec('INSERT INTO ceo_verif_inventaire (shop_id, de, a, de_at, a_at, jours, debut_eur, entrees_eur, fin_eur, sorties_eur, ventes_eur, poubelle_eur,'
            . ' sorti_pos_eur, couvert_eur, manquant_eur, excedent_eur, matieres, calcule_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE de_at = VALUES(de_at), a_at = VALUES(a_at), jours = VALUES(jours), debut_eur = VALUES(debut_eur), entrees_eur = VALUES(entrees_eur),'
            . ' fin_eur = VALUES(fin_eur), sorties_eur = VALUES(sorties_eur), ventes_eur = VALUES(ventes_eur), poubelle_eur = VALUES(poubelle_eur),'
            . ' sorti_pos_eur = VALUES(sorti_pos_eur), couvert_eur = VALUES(couvert_eur), manquant_eur = VALUES(manquant_eur), excedent_eur = VALUES(excedent_eur),'
            . ' matieres = VALUES(matieres), calcule_le = VALUES(calcule_le)',
            [$sid, (string) $w['de']['id'], (string) $w['a']['id'], (string) $w['de']['at'], (string) $w['a']['at'], (float) $w['jours'],
                round($t['debut'], 2), round($t['entrees'], 2), round($t['fin'], 2), round($t['sorties'], 2), round($t['ventes'], 2), round($t['poubelle'], 2),
                round($t['sortiPos'], 2), round($t['couvert'], 2), round($t['manquant'], 2), round($t['excedent'], 2), $t['n'], date('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $e) { return false; }
}

/* ---------------------------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------------------------- */

/** Les semaines d'un magasin, gardées ou calculées dans le budget : [[semaine, données|null]], du plus récent au plus ancien. */
function vmCollecte(int $sid, array $sems, int $n, float $t0, float $budget): array
{
    $out = [];
    foreach (array_slice($sems, 0, $n) as $s) {
        $w = vmSemaine($sid, $s['de'], $s['a'], false);
        if ($w === null && microtime(true) - $t0 < $budget) { $w = vmSemaine($sid, $s['de'], $s['a'], true); }
        $out[] = [$s, $w];
    }
    return $out;
}

/** Le résumé d'un magasin pour le réseau. */
function vmResumeMagasin(int $sid, string $nom, array $S, ?array $w, array $T, array $signales, int $manquantes): array
{
    $age = $S['dernier'] !== null ? (int) floor((time() - strtotime((string) $S['dernier'])) / 86400) : null;
    $o = ['id' => (string) $sid, 'nom' => $nom, 'dernierInventaire' => $S['dernier'], 'ageInventaire' => $age, 'inventaireEnRetard' => $age === null || $age > VM_AGE_INVENTAIRE,
        'semaines' => count($S['semaines']), 'aCalculer' => $manquantes];
    if ($w === null) { return $o + ['lecture' => null]; }
    $L = vmLecture($w, $T, $signales[$sid] ?? []);
    return $o + ['de' => $w['de'], 'a' => $w['a'], 'jours' => $w['jours'], 'lecture' => ['totaux' => $L['totaux'], 'verdict' => $L['verdict'], 'attribution' => $L['attribution'],
        'categories' => array_map(static fn ($c) => array_diff_key($c, ['matieres' => 1]), $L['categories']), 'sansEntree' => $L['sansEntree']]];
}

/** GET /verification/matieres[?shop=&de=&a=&semaines=1|4] */
function ep_verification_matieres(): array
{
    @set_time_limit(120);
    $t0 = microtime(true);
    $mags = vfMagasins();
    $T = vmTolerances();
    $signales = vmSignales();
    $sid = (int) ($_GET['shop'] ?? 0);
    $base = ['tolerances' => $T, 'seuils' => VM_SEUILS, 'causes' => VM_CAUSES, 'ageMax' => VM_AGE_INVENTAIRE, 'lu' => date('c'),
        'source' => 'les inventaires relevés en direct dans le stock du panel (toutes les cinq minutes), les tickets et la poubelle déclarée du panel, les recettes aplaties ; moteur de l’écran Inventaires · lecture seule'];
    if ($sid <= 0) {
        $out = []; $manq = 0;
        foreach ($mags as $id => $nom) {
            $S = vmSemaines((int) $id);
            [$s0, $w] = vmCollecte((int) $id, $S['semaines'], 1, $t0, VM_SEC_DEMANDE)[0] ?? [null, null];
            $m = ($s0 !== null && $w === null) ? 1 : 0; $manq += $m;
            $out[] = vmResumeMagasin((int) $id, $nom, $S, $w, $T, $signales, $m);
        }
        // Les catégories × magasins : la même liste, dans le même ordre, pour chaque magasin.
        $noms = [];
        foreach ($out as $m) { foreach ((array) ($m['lecture']['categories'] ?? []) as $c) { $noms[$c['nom']] = true; } }
        $noms = array_keys($noms); sort($noms, SORT_NATURAL | SORT_FLAG_CASE);
        $matrice = [];
        foreach ($noms as $n) {
            $ligne = ['cat' => $n, 'cases' => []];
            foreach ($out as $m) {
                $c = null;
                foreach ((array) ($m['lecture']['categories'] ?? []) as $x) { if ($x['nom'] === $n) { $c = $x; break; } }
                $ligne['cases'][] = $c;
            }
            $matrice[] = $ligne;
        }
        // Le réseau : les magasins lisibles seulement — sans entrée validée, le consommé d'un magasin est faux par défaut.
        $res = vmZero(); $exclus = [];
        foreach ($out as $m) { if (!empty($m['lecture']['sansEntree'])) { $exclus[] = $m['nom']; continue; } $t = $m['lecture']['totaux'] ?? null; if ($t) { foreach ($res as $k => $v) { if (is_float($v)) { $res[$k] += (float) $t[$k]; } elseif ($k === 'n' || $k === 'sansPrix') { $res[$k] += (int) $t[$k]; } } } }
        return $base + ['magasins' => $out, 'reseau' => vmTaux($res), 'reseauSans' => $exclus, 'matrice' => $matrice, 'aCalculer' => $manq];
    }
    if (!isset($mags[$sid])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $S = vmSemaines($sid);
    $n = max(1, min(4, (int) ($_GET['semaines'] ?? 1)));
    $de = (string) ($_GET['de'] ?? ''); $a = (string) ($_GET['a'] ?? '');
    $choix = $S['semaines'];
    if ($de !== '' && $a !== '') {
        $i = null;
        foreach ($S['semaines'] as $k => $s) { if ($s['de'] === $de && $s['a'] === $a) { $i = $k; break; } }
        $choix = $i === null ? [] : array_slice($S['semaines'], $i);
    }
    $col = vmCollecte($sid, $choix, $n, $t0, VM_SEC_DEMANDE);
    $ws = array_values(array_filter(array_map(static fn ($x) => $x[1], $col)));
    $manquantes = count($col) - count($ws);
    $w = $manquantes === 0 ? vmCumul(array_reverse($ws)) : null;
    $L = $w === null ? null : vmLecture($w, $T, $signales[$sid] ?? []);
    $age = $S['dernier'] !== null ? (int) floor((time() - strtotime((string) $S['dernier'])) / 86400) : null;
    return $base + ['shop' => (string) $sid, 'nom' => $mags[$sid], 'semaines' => $S['semaines'], 'dernierInventaire' => $S['dernier'], 'ageInventaire' => $age,
        'inventaireEnRetard' => $age === null || $age > VM_AGE_INVENTAIRE, 'nSemaines' => $n, 'aCalculer' => $manquantes,
        'periode' => $w === null ? null : ['de' => $w['de'], 'a' => $w['a'], 'jours' => $w['jours'], 'semaines' => $w['semaines'] ?? 1],
        'lecture' => $L, 'sansRecette' => $w['sansRecette'] ?? [], 'caSansRecette' => $w['caSansRecette'] ?? 0, 'ca' => $w['ca'] ?? 0,
        'horsInventaire' => $w['horsInventaire'] ?? 0, 'notes' => $w['notes'] ?? [], 'complet' => $w['complet'] ?? null];
}

/** Le cron : les semaines de chaque magasin pas encore gardées, les plus récentes d'abord, dans le budget. */
function vmCron(): string
{
    if (!function_exists('invEcart')) { return 'module Inventaires absent'; }
    $t0 = microtime(true); $n = 0; $reste = 0; $ret = 0;
    foreach (array_keys(vfMagasins()) as $sid) {
        foreach (vmSemaines((int) $sid)['semaines'] as $s) {
            $w = vmSemaine((int) $sid, $s['de'], $s['a'], false);
            if ($w !== null) {
                // Ce qui a été gardé avant la table y entre.
                try { vmTable(); $deja = Db::row('SELECT 1 x FROM ceo_verif_inventaire WHERE shop_id = ? AND de = ? AND a = ?', [(int) $sid, $s['de'], $s['a']]); } catch (Throwable $e) { $deja = ['x' => 1]; }
                if ($deja === null && vmEnregistre((int) $sid, $w)) { $ret++; }
                continue;
            }
            if (microtime(true) - $t0 > VM_SEC_CRON) { $reste++; continue; }
            if (vmSemaine((int) $sid, $s['de'], $s['a'], true) !== null) { $n++; } else { $reste++; }
        }
    }
    return $n . ' semaine(s) d’inventaire calculée(s), ' . $reste . ' à calculer, ' . $ret . ' entrée(s) dans l’historique';
}
