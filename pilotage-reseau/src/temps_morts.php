<?php
declare(strict_types=1);
/**
 * TEMPS MORTS — le comptoir sans client, mesuré ticket par ticket.
 *
 * Un temps mort : un trou d'au moins N minutes (5, 10 ou 15 ; 10 par défaut) entre deux
 * tickets qui se suivent, entre le premier et le dernier ticket de la journée. On le compte
 * (nombre par jour), on le mesure (minutes sans client, part de l'ouverture) et on le range
 * heure par heure (part de chaque heure passée sans client).
 *
 * La source : la LISTE des tickets d'un jour (`/shops/{id}/transactions?date=`), un appel par
 * jour et par magasin. Chaque ticket y porte son heure (`insert_timestamp`, heure locale) ;
 * tous les tickets comptent, comptoir et pro. Mesuré le 07/11/2026 : 159 tickets dans la liste
 * de Sombreffe le 06/11, 159 dans `hourly-distribution` — la liste est complète.
 *
 * Les heures des tickets se gravent sous `tmT{shop}:{date}` : à chaque lecture de la liste par
 * le BO (le pro du jour, les produits du jour : `tmGraveListe`), à la demande du dashboard, et
 * au cron (`tmMoisson`). Une journée close ne se relit plus ; la journée en cours, toutes les
 * dix minutes.
 *
 * Deux règles de bord, parce que les horaires d'ouverture ne sont saisis nulle part :
 *  - l'attente avant le premier client et ce qui suit le dernier ne comptent pas ;
 *  - un ticket isolé en début ou en fin de journée, à TM_ISOLE minutes ou plus du suivant
 *    (un pro encodé à 5 h, une correction à 21 h), n'ouvre ni ne ferme la journée.
 */

const TM_SEUILS = [5, 10, 15];
const TM_SEUIL_DEFAUT = 10;
const TM_ISOLE = 45;          // minutes : un ticket de bord plus loin que cela du suivant est écarté
const TM_TTL = 600;           // la journée en cours : dix minutes
const TM_BUDGET = 24;         // listes lues au plus par requête du dashboard
const TM_HABITUDE = 4;        // l'habitude d'un jour : les 4 mêmes jours de semaine d'avant
const TM_BUDGET_CRON = 40;    // listes lues au plus par battement du cron
const TM_PROFONDEUR = 56;     // le cron remonte huit semaines

/** Les heures des tickets d'une liste du panel, en secondes depuis minuit, triées. */
function tmDuListe(array $liste, ?string $jour = null): array
{
    $t = []; $pro = 0;
    foreach (analyseListe($liste) as $x) {
        if (!is_array($x)) { continue; }
        $ts = (string) ($x['insert_timestamp'] ?? '');
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $ts, $m)) { continue; }
        // Un ticket d'un autre jour (passé minuit, liste mal bornée) ne compte pas dans celui-ci.
        if ($jour !== null && $m[1] !== $jour) { continue; }
        $t[] = 3600 * (int) $m[2] + 60 * (int) $m[3] + (int) ($m[4] ?? 0);
        if (!empty($x['is_client_b2b'])) { $pro++; }
    }
    sort($t);
    return ['q' => time(), 't' => $t, 'pro' => $pro];
}

/** Grave les heures des tickets d'une liste déjà lue. Appelée partout où le BO lit cette liste. */
function tmGraveListe(int $sid, string $j, array $liste): array
{
    $r = tmDuListe($liste, $j);
    try { svGrave('tmT' . $sid . ':' . $j, $r); } catch (Throwable $e) { /* le relevé se refera */ }
    return $r;
}

/** Un relevé vaut-il encore ? Clos s'il a été pris un jour plus tard ; sinon dix minutes. */
function tmValide(mixed $c, string $j): bool
{
    if (!is_array($c) || !isset($c['t']) || !is_array($c['t'])) { return false; }
    $q = (int) ($c['q'] ?? 0);
    return date('Y-m-d', $q) > $j || $q > time() - TM_TTL;
}

/**
 * Les relevés de plusieurs jours d'un magasin — [date => relevé|null], dans l'ordre demandé.
 * Les jours manquants se lisent en parallèle, dans l'ordre de la liste, $budget au plus ; un
 * relevé périmé sert de repli si le panel ne répond pas. Les jours à venir restent à null.
 */
function tmJours(int $sid, array $jours, int $budget = TM_BUDGET): array
{
    $auj = date('Y-m-d');
    $out = []; $anciens = []; $chemins = [];
    foreach ($jours as $j) {
        $out[$j] = null;
        if ($j > $auj) { continue; }
        $c = setting('tmT' . $sid . ':' . $j);
        if (tmValide($c, $j)) { $out[$j] = $c; continue; }
        if (is_array($c) && isset($c['t'])) { $anciens[$j] = $c; }
        if (count($chemins) < $budget && class_exists('PanelApi') && PanelApi::configured()) {
            $chemins[$j] = '/shops/' . $sid . '/transactions?date=' . $j;
        } elseif (isset($anciens[$j])) { $out[$j] = $anciens[$j]; }
    }
    if ($chemins !== []) {
        $res = PanelApi::getParallele($chemins, 6);
        foreach (array_keys($chemins) as $j) {
            $liste = $res[$j] ?? null;
            $out[$j] = is_array($liste) ? tmGraveListe($sid, (string) $j, $liste) : ($anciens[$j] ?? null);
        }
    }
    return $out;
}

/** « 15:07 » depuis des secondes. */
function tmHm(int $s): string
{
    return sprintf('%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
}

/**
 * La mesure d'une journée : ses tickets (secondes, triés), un seuil en minutes.
 * `$detail` ajoute les tickets et les trous un par un (le jour regardé ; pas la semaine).
 */
function tmMesure(array $t, int $seuil, bool $detail = false): array
{
    $t = array_values(array_map('intval', $t));
    sort($t);
    $n0 = count($t);
    // Les bords : un ticket isolé avant le premier client ou après le dernier ne compte pas.
    $i0 = 0; $i1 = $n0 - 1; $iso = TM_ISOLE * 60;
    while ($i1 - $i0 >= 1 && $t[$i0 + 1] - $t[$i0] >= $iso) { $i0++; }
    while ($i1 - $i0 >= 1 && $t[$i1] - $t[$i1 - 1] >= $iso) { $i1--; }
    $u = $n0 > 0 ? array_slice($t, $i0, $i1 - $i0 + 1) : [];
    $n = count($u);
    if ($n < 2) { return ['ferme' => true, 'tickets' => $n0]; }
    $deb = $u[0]; $fin = $u[$n - 1]; $S = $seuil * 60;
    $H = [];
    for ($h = intdiv($deb, 3600); $h <= intdiv($fin, 3600); $h++) {
        $H[$h] = ['ouvert' => max(0, min($fin, ($h + 1) * 3600) - max($deb, $h * 3600)), 'mort' => 0, 'nb' => 0, 'tk' => 0];
    }
    foreach ($u as $x) { $H[intdiv($x, 3600)]['tk']++; }
    $trous = []; $mort = 0; $long = [0, $deb, $deb];
    for ($i = 0; $i + 1 < $n; $i++) {
        $a = $u[$i]; $b = $u[$i + 1]; $g = $b - $a;
        if ($g > $long[0]) { $long = [$g, $a, $b]; }
        if ($g < $S) { continue; }
        $trous[] = [$a, $b]; $mort += $g;
        $H[intdiv($a, 3600)]['nb']++;
        // Les minutes du trou, réparties sur les heures qu'il traverse.
        for ($h = intdiv($a, 3600); $h * 3600 < $b; $h++) {
            $o = min($b, ($h + 1) * 3600) - max($a, $h * 3600);
            if ($o > 0) { $H[$h]['mort'] += $o; }
        }
    }
    $ouvert = $fin - $deb;
    $heures = [];
    foreach ($H as $h => $x) {
        $heures[(string) $h] = ['ouvert' => round($x['ouvert'] / 60, 1), 'mort' => round($x['mort'] / 60, 1),
            'pct' => $x['ouvert'] > 0 ? round(100 * $x['mort'] / $x['ouvert'], 1) : null, 'nb' => $x['nb'], 'tk' => $x['tk']];
    }
    $r = ['ferme' => false, 'tickets' => $n0, 'isoles' => $n0 - $n, 'debut' => tmHm($deb), 'fin' => tmHm($fin),
        'ouvert' => (int) round($ouvert / 60), 'nb' => count($trous), 'mort' => (int) round($mort / 60),
        'pct' => $ouvert > 0 ? round(100 * $mort / $ouvert, 1) : null,
        'plusLong' => ['min' => round($long[0] / 60, 1), 'de' => tmHm($long[1]), 'a' => tmHm($long[2])],
        'heures' => (object) $heures];
    if ($detail) {
        $r['ticks'] = $u;
        $r['trous'] = $trous;
        $r['ecartes'] = array_values(array_merge(array_slice($t, 0, $i0), array_slice($t, $i1 + 1)));
    }
    return $r;
}

/** La moyenne par jour ouvert de plusieurs mesures ; la part de l'ouverture sur les minutes additionnées. */
function tmMoyenne(array $mesures): array
{
    $m = array_values(array_filter($mesures, static fn ($x) => is_array($x) && empty($x['ferme'])));
    $n = count($m);
    if ($n === 0) { return ['jours' => 0]; }
    $s = static fn (string $k) => array_sum(array_map(static fn ($x) => (float) $x[$k], $m));
    return ['jours' => $n, 'nb' => round($s('nb') / $n, 1), 'mort' => (int) round($s('mort') / $n),
        'ouvert' => (int) round($s('ouvert') / $n), 'tickets' => (int) round($s('tickets') / $n),
        'pct' => $s('ouvert') > 0 ? round(100 * $s('mort') / $s('ouvert'), 1) : null,
        'plusLong' => round(max(array_map(static fn ($x) => (float) $x['plusLong']['min'], $m)), 1)];
}

/**
 * GET /exploitation/temps-morts?shop=4&date=YYYY-MM-DD&seuil=10 — l'onglet « Temps morts » du
 * dashboard magasin : le jour (trous un par un, heure par heure), son habitude (les 4 mêmes
 * jours de semaine d'avant), la semaine du lundi au dimanche jour par jour, et la semaine d'avant.
 */
function ep_temps_morts(): array
{
    $auj = date('Y-m-d');
    $sid = (int) ($_GET['shop'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $seuil = (int) ($_GET['seuil'] ?? TM_SEUIL_DEFAUT);
    if (!in_array($seuil, TM_SEUILS, true)) { $seuil = TM_SEUIL_DEFAUT; }
    @set_time_limit(90);

    $jour = static fn (string $d, int $k) => date('Y-m-d', strtotime($d . ' ' . ($k >= 0 ? '+' : '') . $k . ' days'));
    $lun = $jour($date, 1 - (int) date('N', strtotime($date)));
    $semaine = array_map(static fn ($i) => $jour($lun, $i), range(0, 6));
    $avant = array_map(static fn ($i) => $jour($lun, $i - 7), range(0, 6));
    $habitude = array_map(static fn ($k) => $jour($date, -7 * $k), range(1, TM_HABITUDE));
    // L'ordre de lecture est l'ordre d'importance : le jour, sa semaine, son habitude, la semaine d'avant.
    $ordre = array_values(array_unique(array_merge([$date], $semaine, $habitude, $avant)));
    $R = tmJours($sid, $ordre);

    $mes = static function (?array $c, bool $detail = false) use ($seuil): ?array { return $c === null ? null : tmMesure($c['t'], $seuil, $detail); };
    $etat = static function (string $d, ?array $m) use ($auj): string {
        if ($d > $auj) { return 'avenir'; }
        if ($m === null) { return 'nonlu'; }
        return !empty($m['ferme']) ? 'ferme' : ($d === $auj ? 'encours' : 'lu');
    };
    $mJ = $mes($R[$date], true);
    $joursSem = [];
    foreach ($semaine as $d) {
        $m = $d === $date ? $mJ : $mes($R[$d]);
        $l = ['date' => $d, 'etat' => $etat($d, $m)];
        if ($m !== null && empty($m['ferme'])) { $l += array_diff_key($m, ['ticks' => 1, 'trous' => 1, 'ecartes' => 1]); }
        $joursSem[] = $l;
    }
    // Les moyennes ne prennent que les journées closes : la journée en cours tirerait tout vers le bas.
    $clos = static fn (array $ds) => array_map(static fn ($d) => $d < $auj ? $mes($R[$d]) : null, $ds);
    $manquants = count(array_filter($ordre, static fn ($d) => $d <= $auj && $R[$d] === null));
    return [
        'shop' => $sid, 'date' => $date, 'seuil' => $seuil, 'seuils' => TM_SEUILS, 'isole' => TM_ISOLE,
        'jour' => $mJ === null ? null : ['date' => $date, 'etat' => $etat($date, $mJ)] + $mJ,
        'habitude' => ['dates' => $habitude] + tmMoyenne($clos($habitude)),
        'semaine' => ['du' => $semaine[0], 'au' => $semaine[6], 'jours' => $joursSem, 'moyenne' => tmMoyenne($clos($semaine))],
        'semainePrecedente' => ['du' => $avant[0], 'au' => $avant[6], 'moyenne' => tmMoyenne($clos($avant))],
        'manquants' => $manquants,
        'source' => 'liste des tickets du panel (/shops/{id}/transactions?date=), heure de chaque ticket ; tous les tickets, comptoir et pro',
    ];
}

/**
 * Au cron : graver les heures des tickets des jours qui ne les ont pas encore, d'hier à huit
 * semaines en arrière, une liste par jour et par magasin.
 */
function tmMoisson(int $budget = TM_BUDGET_CRON): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['ok' => false, 'motif' => 'compte panel non configuré']; }
    try { $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'motif' => 'magasins illisibles']; }
    $chemins = []; $restants = 0;
    for ($k = 1; $k <= TM_PROFONDEUR; $k++) {
        $j = date('Y-m-d', strtotime('-' . $k . ' days'));
        foreach ($shops as $sid) {
            if (tmValide(setting('tmT' . $sid . ':' . $j), $j)) { continue; }
            if (count($chemins) >= $budget) { $restants++; continue; }
            $chemins[$sid . '|' . $j] = '/shops/' . $sid . '/transactions?date=' . $j;
        }
    }
    $faits = 0;
    $res = $chemins === [] ? [] : PanelApi::getParallele($chemins, 6);
    foreach (array_keys($chemins) as $cle) {
        [$sid, $j] = explode('|', (string) $cle, 2);
        $liste = $res[$cle] ?? null;
        if (is_array($liste)) { tmGraveListe((int) $sid, $j, $liste); $faits++; } else { $restants++; }
    }
    return ['ok' => true, 'joursFaits' => $faits, 'joursRestants' => $restants];
}
