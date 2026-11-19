<?php
declare(strict_types=1);

/**
 * Cadence dynamique des contrôles — une tâche bien tenue se contrôle moins
 * souvent, une tâche qui glisse revient au quotidien (maquette validée).
 *
 * Les RÈGLES vivent dans ceo_app_setting.cadenceControles. Le PLAN (palier par
 * tâche × magasin) se calcule en relisant l'historique des contrôles du panel
 * jour par jour (ep_pwa_tasks) — plusieurs secondes d'API : il se met en cache
 * dans cadencePlan et ne se recalcule que sur demande (bouton, ou cron).
 *
 * LIMITE DITE HONNÊTEMENT : le cockpit calcule et affiche la cadence ; écrire
 * la planification DANS le panel exigerait une API que le panel n'expose pas.
 * Le contrat POST qu'il faudrait est décrit ici et affiché dans Paramètres.
 */

const CADENCE_BUDGET_S = 20; // budget de lecture API — au-delà, plan partiel

function cadenceRegles(): array
{
    $s = setting('cadenceControles');
    if (!is_array($s)) { $s = []; }
    $paliers = array_values(array_filter(array_map('intval', (array) ($s['paliers'] ?? [])),
        fn ($j) => $j >= 1 && $j <= 30));
    if ($paliers === []) { $paliers = [1, 2, 3, 5, 7]; }
    sort($paliers);
    return [
        'actif' => (bool) ($s['actif'] ?? true),
        'serie' => max(2, min(20, (int) ($s['serie'] ?? 5))),
        'moyenneMin' => max(1.0, min(5.0, (float) ($s['moyenneMin'] ?? 4.5))),
        'noteCasse' => max(1, min(5, (int) ($s['noteCasse'] ?? 4))),
        'noteRechute' => max(0, min(5, (int) ($s['noteRechute'] ?? 3))),
        'paliers' => array_values(array_unique($paliers)),
        'perimetre' => ($s['perimetre'] ?? '') === 'reseau' ? 'reseau' : 'par-magasin',
    ];
}

/**
 * La machine à paliers, sur la suite chronologique des notes d'une tâche.
 *   - note ≤ rechute            → palier 1 (quotidien), série vidée ;
 *   - note < casse              → la série de promotion repart de zéro ;
 *   - `serie` notes consécutives de moyenne ≥ moyenneMin → palier suivant ;
 *   - moyenne glissante des `serie` dernières notes passant SOUS moyenneMin
 *     → palier précédent (au franchissement, pas à chaque note).
 */
function cadenceEtat(array $controles, array $r): array
{
    $N = $r['serie']; $min = $r['moyenneMin']; $maxIdx = count($r['paliers']) - 1;
    $pi = 0; $serie = []; $toutes = []; $avgPrev = null; $mouv = ''; $mouvDate = '';
    foreach ($controles as $c) {
        $n = (int) $c['n'];
        $toutes[] = $n;
        if ($n <= $r['noteRechute']) {
            if ($pi > 0) { $mouv = 'rechute'; $mouvDate = $c['date']; }
            $pi = 0; $serie = [];
        } else {
            if ($n < $r['noteCasse']) { $serie = []; } else { $serie[] = $n; }
            if (count($serie) >= $N && array_sum(array_slice($serie, -$N)) / $N >= $min) {
                if ($pi < $maxIdx) { $mouv = 'espacee'; $mouvDate = $c['date']; }
                $pi = min($pi + 1, $maxIdx); $serie = [];
            }
            if (count($toutes) >= $N) {
                $a = array_sum(array_slice($toutes, -$N)) / $N;
                if ($a < $min && $avgPrev !== null && $avgPrev >= $min && $pi > 0) {
                    $pi--; $mouv = 'resserree'; $mouvDate = $c['date'];
                }
                $avgPrev = $a;
            }
        }
    }
    return ['palierIdx' => $pi, 'serieEnCours' => count($serie), 'mouvement' => $mouv, 'mouvementDate' => $mouvDate];
}

/** Relit l'historique du panel et calcule le plan. Lent — à appeler sur demande. */
function cadenceCalculePlan(int $semaines): array
{
    $r = cadenceRegles();
    $semaines = max(1, min(12, $semaines));
    $t0 = microtime(true);
    $auj = new DateTimeImmutable('today');
    $limite = $auj->modify('-' . ($semaines * 7) . ' days');
    // Du plus RÉCENT au plus ancien : si le budget API tombe, on perd les
    // vieux jours, jamais les derniers contrôles — ce sont eux qui décident.
    $groupes = []; $lus = 0; $partiel = false; $premierJour = $auj->format('Y-m-d');
    for ($d = $auj; $d >= $limite; $d = $d->modify('-1 day')) {
        if (microtime(true) - $t0 > CADENCE_BUDGET_S) { $partiel = true; break; }
        $premierJour = $d->format('Y-m-d');
        $j = rapAppel('ep_pwa_tasks', ['date' => $d->format('Y-m-d')]);
        foreach ((array) ($j['shops'] ?? []) as $sh) {
            foreach ((array) ($sh['taches'] ?? []) as $t) {
                if (($t['note'] ?? null) === null) { continue; }
                $mag = (string) ($sh['shop'] ?? '');
                $cle = ($r['perimetre'] === 'reseau' ? '' : $mag . '|') . (string) ($t['tache'] ?? ('#' . ($t['taskId'] ?? '?')));
                $groupes[$cle]['tache'] = (string) ($t['tache'] ?? ('Tâche #' . ($t['taskId'] ?? '?')));
                $groupes[$cle]['magasin'] = $r['perimetre'] === 'reseau' ? 'Réseau' : $mag;
                $groupes[$cle]['shopId'] = (string) ($sh['shopId'] ?? '');
                $groupes[$cle]['controles'][] = ['n' => (int) $t['note'], 'date' => (string) ($t['date'] ?? $d->format('Y-m-d'))];
                $lus++;
            }
        }
    }
    $lignes = [];
    foreach ($groupes as $g) {
        usort($g['controles'], fn ($a, $b) => strcmp($a['date'], $b['date']));
        $etat = cadenceEtat($g['controles'], $r);
        $jours = $r['paliers'][$etat['palierIdx']];
        $dern = end($g['controles'])['date'];
        $dernieres = array_slice($g['controles'], -5);
        $moy = round(array_sum(array_column($dernieres, 'n')) / count($dernieres), 1);
        $prochain = (new DateTimeImmutable($dern))->modify('+' . $jours . ' days')->format('Y-m-d');
        $lignes[] = ['tache' => $g['tache'], 'magasin' => $g['magasin'], 'shopId' => $g['shopId'],
            'notes' => $dernieres, 'moyenne' => $moy, 'palierJours' => $jours,
            'palierIdx' => $etat['palierIdx'], 'serieEnCours' => $etat['serieEnCours'],
            'dernier' => $dern, 'prochain' => $prochain, 'du' => $prochain <= $auj->format('Y-m-d'),
            'mouvement' => $etat['mouvement'], 'mouvementDate' => $etat['mouvementDate']];
    }
    usort($lignes, fn ($a, $b) => [$b['du'], $a['prochain']] <=> [$a['du'], $b['prochain']]);
    return ['calculeLe' => date('Y-m-d H:i'), 'semaines' => $semaines, 'depuis' => $premierJour,
        'partiel' => $partiel, 'controlesLus' => $lus, 'lignes' => $lignes];
}

/** Le contrat d'écriture qu'il FAUDRAIT côté panel — affiché dans Paramètres. */
function cadenceContratApi(): array
{
    return [
        'statut' => 'manque API — le panel n\'expose aucune écriture de planification des tâches',
        'endpoint' => 'POST /api/v1/consultant/shops/{shopId}/tasks/{taskId}/schedule',
        'corps' => ['interval_days' => 2, 'next_due' => '2026-08-23',
            'source' => 'cockpit-ceo', 'reason' => 'cadence-dynamique'],
        'reponse' => ['ok' => true, 'task_id' => 123, 'interval_days' => 2, 'next_due' => '2026-08-23'],
        'note' => 'Tant que cette route n\'existe pas côté panel, le Plan de contrôle du cockpit est le guide '
            . 'du consultant (écran + bloc de rapport) — la PWA garde son rythme propre.',
    ];
}

/* -------------------------------------------------------------------------- */

/** GET /cadence — règles + plan en cache + contrat d'API manquant. */
function ep_cadence(): array
{
    return ['regles' => cadenceRegles(), 'plan' => setting('cadencePlan'), 'apiPost' => cadenceContratApi()];
}

/** PUT /cadence — mise à jour partielle des règles. */
function wr_cadence(): array
{
    $b = body();
    $s = setting('cadenceControles');
    if (!is_array($s)) { $s = []; }
    if (array_key_exists('actif', $b)) { $s['actif'] = (bool) $b['actif']; }
    foreach (['serie' => 'int', 'noteCasse' => 'int', 'noteRechute' => 'int', 'moyenneMin' => 'float'] as $k => $ty) {
        if (array_key_exists($k, $b)) {
            $v = kpiNombre($b[$k]);
            if ($v !== null) { $s[$k] = $ty === 'int' ? (int) $v : $v; }
        }
    }
    if (array_key_exists('paliers', $b)) {
        $liste = is_array($b['paliers']) ? $b['paliers'] : preg_split('/[,;\s]+/', (string) $b['paliers']);
        $s['paliers'] = array_values(array_filter(array_map('intval', (array) $liste), fn ($j) => $j >= 1 && $j <= 30));
    }
    if (array_key_exists('perimetre', $b)) { $s['perimetre'] = $b['perimetre'] === 'reseau' ? 'reseau' : 'par-magasin'; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['cadenceControles', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    $r = cadenceRegles();
    journalAdd('CEO', 'Paramètre', 'Cadence dynamique', 'Règles : série ' . $r['serie'] . ' · moyenne ≥ '
        . $r['moyenneMin'] . ' · rechute ≤ ' . $r['noteRechute'] . ' · paliers ' . implode('/', $r['paliers']) . ' j');
    return ['ok' => true, 'regles' => $r];
}

/** Recalcule le plan, le fige en base et le journalise. Lent : historique API. */
function cadenceRafraichirPlan(int $semaines, string $par): array
{
    $plan = cadenceCalculePlan($semaines);
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['cadencePlan', json_encode($plan, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', 'Cadence dynamique', 'Plan recalculé (' . $par . ') — ' . count($plan['lignes'])
        . ' tâche(s) suivie(s), ' . $plan['controlesLus'] . ' contrôle(s) lus sur ' . $plan['semaines'] . ' semaine(s)'
        . ($plan['partiel'] ? ' (partiel : budget API atteint)' : ''));
    return $plan;
}

/** POST /cadence/plan — recalcule le plan à la demande (bouton de l'écran). */
function wr_cadence_plan(): array
{
    $b = body();
    return ['ok' => true, 'plan' => cadenceRafraichirPlan((int) ($b['semaines'] ?? 4), 'écran')];
}

/**
 * Le passage horaire du cron : rafraîchit le plan UNE fois par jour, au
 * premier passage à partir de 5 h — avant l'arrivée du consultant, hors des
 * heures chargées. Un plan encore jamais calculé se calcule au premier
 * passage venu. Rend l'état, que le recalcul ait eu lieu ou non — le cron
 * dit toujours ce qu'il a décidé.
 */
function cadenceCron(): string
{
    if (!cadenceRegles()['actif']) { return 'désactivée'; }
    $plan = setting('cadencePlan');
    $duJour = is_array($plan) && str_starts_with((string) ($plan['calculeLe'] ?? ''), date('Y-m-d'));
    if ($duJour) { return 'plan à jour (' . substr((string) $plan['calculeLe'], 11) . ')'; }
    if (is_array($plan) && (int) date('G') < 5) { return 'recalcul attendu à 5 h'; }
    $p = cadenceRafraichirPlan(4, 'cron');
    return 'plan recalculé — ' . count($p['lignes']) . ' tâche(s), ' . $p['controlesLus'] . ' contrôle(s) lus';
}
