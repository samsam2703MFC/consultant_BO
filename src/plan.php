<?php
declare(strict_types=1);

/**
 * LE PLAN DE DÉVELOPPEMENT D'UN FRANCHISÉ — l'engagement annuel, et le rituel
 * trimestriel.
 *
 * Une fois par an, le franchisé DÉPOSE son engagement : l'objectif de l'année,
 * posé sur la rampe à 5 ans de son étude de marché, et comment il compte
 * l'atteindre — des actions chiffrées (effet attendu, trimestre de lancement).
 * Le consultant valide. Chaque trimestre, on constate (les chiffres viennent
 * du suivi, rien n'est ressaisi), on met les actions à jour, trois voix
 * annotent — franchisé, consultant, marque, chacun la sienne — et on écrit ce
 * qui est mis en route pour le trimestre suivant. La revue se VALIDE, et
 * s'imprime.
 *
 * Quatre tables, créées au premier appel comme les annotations mensuelles :
 *  · ceo_plan_engagement  — l'objectif de l'année, ses raisons, ses signatures
 *  · ceo_plan_action      — une ligne par action, avec son statut
 *  · ceo_plan_trimestre   — une ligne par (trimestre, voix) : les annotations,
 *                           et la voix « suite » = ce qui est mis en route
 *  · ceo_plan_revue       — l'état du rituel : l'étape atteinte, la validation
 */

const PLAN_VOIX = ['franchise', 'consultant', 'marque', 'suite'];
const PLAN_STATUTS_ACTION = ['alancer', 'encours', 'fait', 'abandonne'];
const PLAN_STATUTS_ENG = ['brouillon', 'engage', 'valide'];

function ensurePlan(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plan_engagement ('
        . 'shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL,'
        . 'objectif DECIMAL(14,2) NULL, rampe DECIMAL(14,2) NULL, motif TEXT NULL,'
        . 'statut VARCHAR(12) NOT NULL DEFAULT \'brouillon\','
        . 'engage_par VARCHAR(120) NULL, engage_le DATETIME NULL,'
        . 'valide_par VARCHAR(120) NULL, valide_le DATETIME NULL,'
        . 'maj_le DATETIME NULL, PRIMARY KEY (shop_id, year)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plan_action ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL,'
        . 'libelle VARCHAR(200) NOT NULL, effet_an DECIMAL(12,2) NULL, trimestre TINYINT NULL,'
        . 'statut VARCHAR(12) NOT NULL DEFAULT \'alancer\', responsable VARCHAR(120) NULL,'
        . 'date_lancement DATE NULL, mesure VARCHAR(200) NULL, effet_constate VARCHAR(200) NULL,'
        . 'ordre INT NOT NULL DEFAULT 0, maj_le DATETIME NULL, KEY k_plan (shop_id, year)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plan_trimestre ('
        . 'shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL, trimestre TINYINT NOT NULL,'
        . 'auteur VARCHAR(12) NOT NULL, texte TEXT NULL, maj_par VARCHAR(120) NULL, maj_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, year, trimestre, auteur)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plan_revue ('
        . 'shop_id VARCHAR(8) NOT NULL, year SMALLINT NOT NULL, trimestre TINYINT NOT NULL,'
        . 'etape TINYINT NOT NULL DEFAULT 1, valide_par VARCHAR(120) NULL, valide_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, year, trimestre)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** La rampe à 5 ans d'un magasin, depuis son étude de marché de l'exercice. */
function planRampe(string $shop, int $exercice): array
{
    $b = null;
    try {
        $b = Db::row('SELECT etude_potentiel_maturite, annee_exploitation, montee_regime FROM ceo_shop_budget
                       WHERE shop_id = ? AND fiscal_year = ?', [$shop, $exercice]);
        // À défaut d'étude sur l'exercice, la plus récente : la rampe ne change
        // pas d'une année à l'autre, seule l'année d'exploitation avance.
        if (!$b || !$b['etude_potentiel_maturite']) {
            $b2 = Db::row('SELECT fiscal_year, etude_potentiel_maturite, annee_exploitation, montee_regime FROM ceo_shop_budget
                            WHERE shop_id = ? AND etude_potentiel_maturite > 0 ORDER BY fiscal_year DESC LIMIT 1', [$shop]);
            if ($b2) { $b = $b2; $b['annee_exploitation'] = (int) $b2['annee_exploitation'] + ($exercice - (int) $b2['fiscal_year']); }
        }
    } catch (PDOException $e) { $b = null; }
    $pot = $b ? (float) ($b['etude_potentiel_maturite'] ?? 0) : 0.0;
    if ($pot <= 0) { return ['potentiel' => null, 'anneeExploitation' => null, 'annees' => []]; }
    $anEx = max(1, (int) ($b['annee_exploitation'] ?? 1));
    $ramp = $b['montee_regime'] ? (json_decode((string) $b['montee_regime'], true) ?: []) : [];
    $coef = static fn (int $an): float => $an <= 1 ? (float) ($ramp['a1'] ?? 70)
        : ($an === 2 ? (float) ($ramp['a2'] ?? 80) : ($an === 3 ? (float) ($ramp['a3'] ?? 90) : 100.0));
    $annees = [];
    for ($k = 0; $k < 5; $k++) {
        $anX = min(4, $anEx + $k);
        $annees[] = ['an' => $exercice + $k, 'anneeExploitation' => $anX, 'coef' => $coef($anX),
            'ca' => round($pot * $coef($anX) / 100, 2), 'maturite' => $anX >= 4];
    }
    return ['potentiel' => $pot, 'anneeExploitation' => $anEx, 'annees' => $annees];
}

/** GET /plan?shop=&exercice= */
function ep_plan(): array
{
    ensurePlan();
    $shop = (string) ($_GET['shop'] ?? '');
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    if ($shop === '') { http_response_code(400); return ['error' => 'magasin manquant']; }
    $nom = magasinConnu($shop);
    if ($nom === null) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $auj = date('Y-m-d');

    $rampe = planRampe($shop, $exercice);

    // Les engagements, toutes années (la rampe en montre cinq).
    $engagements = [];
    foreach (Db::rows('SELECT * FROM ceo_plan_engagement WHERE shop_id = ?', [$shop]) as $r) {
        $engagements[(string) $r['year']] = [
            'objectif' => $r['objectif'] !== null ? (float) $r['objectif'] : null,
            'rampe' => $r['rampe'] !== null ? (float) $r['rampe'] : null,
            'motif' => (string) ($r['motif'] ?? ''), 'statut' => (string) $r['statut'],
            'engagePar' => $r['engage_par'], 'engageLe' => $r['engage_le'],
            'validePar' => $r['valide_par'], 'valideLe' => $r['valide_le']];
    }
    $actions = array_map(static fn ($a) => [
        'id' => (int) $a['id'], 'libelle' => (string) $a['libelle'],
        'effetAn' => $a['effet_an'] !== null ? (float) $a['effet_an'] : null,
        'trimestre' => $a['trimestre'] !== null ? (int) $a['trimestre'] : null,
        'statut' => (string) $a['statut'], 'responsable' => (string) ($a['responsable'] ?? ''),
        'dateLancement' => $a['date_lancement'], 'mesure' => (string) ($a['mesure'] ?? ''),
        'effetConstate' => (string) ($a['effet_constate'] ?? ''), 'ordre' => (int) $a['ordre']],
        Db::rows('SELECT * FROM ceo_plan_action WHERE shop_id = ? AND year = ? ORDER BY ordre, id', [$shop, $exercice]));
    $notes = [];
    foreach (Db::rows('SELECT trimestre, auteur, texte, maj_par, maj_le FROM ceo_plan_trimestre WHERE shop_id = ? AND year = ?', [$shop, $exercice]) as $r) {
        $notes[(int) $r['trimestre']][(string) $r['auteur']] = ['texte' => (string) $r['texte'], 'par' => $r['maj_par'], 'le' => $r['maj_le']];
    }
    $revues = [];
    foreach (Db::rows('SELECT trimestre, etape, valide_par, valide_le FROM ceo_plan_revue WHERE shop_id = ? AND year = ?', [$shop, $exercice]) as $r) {
        $revues[(int) $r['trimestre']] = ['etape' => (int) $r['etape'], 'validePar' => $r['valide_par'], 'valideLe' => $r['valide_le']];
    }

    // ── Le constat : les mois du suivi (budget contre réel, ratios), agrégés
    //    par trimestre. Même source que Suivi budget et Performance.
    $_GET['annees'] = ($exercice - 1) . ',' . $exercice;
    $cells = [];
    foreach (ep_perf() as $c) {
        if ((string) $c['storeId'] !== $shop) { continue; }
        $cells[(int) $c['annee']][(int) $c['mois']] = $c;
    }
    $trimestres = planTrimestres($cells, $exercice, $notes, $revues);
    // Tous les trimestres depuis le début du plan : les écarts se lisent d'un
    // trait, d'une année à l'autre (le PDF les imprime tous).
    $historique = [];
    $premierAn = (int) ($rampe['annees'][0]['an'] ?? $exercice) - ($rampe['anneeExploitation'] ?? 1) + 1;
    $premierAn = max($premierAn, $exercice - 4);
    if ($premierAn < $exercice - 1) {
        $_GET['annees'] = implode(',', range($premierAn, $exercice - 2));
        foreach (ep_perf() as $c) { if ((string) $c['storeId'] === $shop) { $cells[(int) $c['annee']][(int) $c['mois']] = $c; } }
    }
    for ($an = $premierAn; $an <= $exercice; $an++) {
        foreach (planTrimestres($cells, $an, [], []) as $t) {
            if ($t['realise'] === null && $t['objectif'] === null) { continue; }
            if ($t['futur']) { continue; }
            $t['an'] = $an; unset($t['notes'], $t['revue']);
            $historique[] = $t;
        }
    }

    // L'année : réalisé, attendu, projection — pour lire l'engagement.
    $engAn = $engagements[(string) $exercice] ?? null;
    $rampeAn = null;
    foreach ($rampe['annees'] as $a) { if ($a['an'] === $exercice) { $rampeAn = $a['ca']; } }
    // Tant que rien n'est déposé, l'année se lit contre la rampe de l'étude —
    // et le dit (objectifSource = 'rampe') : l'écran et le PDF restent lisibles.
    $objAn = $engAn && $engAn['objectif'] ? (float) $engAn['objectif'] : ($rampeAn !== null && $rampeAn > 0 ? (float) $rampeAn : null);
    $objSrc = $engAn && $engAn['objectif'] ? 'engagement' : ($objAn !== null ? 'rampe' : null);
    $realiseAn = 0.0; $attenduAn = 0.0; $objMoisAn = 0.0;
    foreach ($trimestres as $t) { $realiseAn += (float) ($t['realise'] ?? 0); $attenduAn += (float) ($t['attendu'] ?? 0); $objMoisAn += (float) ($t['objectif'] ?? 0); }
    // L'attendu se lit contre l'ENGAGEMENT : la part de l'année déjà écoulée
    // (au rythme des budgets mensuels) appliquée à l'objectif engagé.
    $partEcoulee = $objMoisAn > 0 ? $attenduAn / $objMoisAn : null;
    $attenduEng = ($objAn !== null && $partEcoulee !== null) ? $objAn * $partEcoulee : null;
    $projection = ($attenduEng !== null && $attenduEng > 0 && $objAn !== null) ? $realiseAn / $attenduEng * $objAn : null;
    $annee = ['objectif' => $objAn, 'objectifSource' => $objSrc, 'rampe' => $rampeAn, 'realise' => round($realiseAn, 2),
        'attendu' => $attenduEng !== null ? round($attenduEng, 2) : null,
        'partEcoulee' => $partEcoulee !== null ? round($partEcoulee * 100, 1) : null,
        'ecart' => $attenduEng !== null ? round($realiseAn - $attenduEng, 2) : null,
        'projection' => $projection !== null ? round($projection, 2) : null,
        'budgetMensuelTotal' => round($objMoisAn, 2)];

    // Le trimestre « courant » du rituel : celui en cours, sinon le dernier clos.
    $courant = 1;
    foreach ($trimestres as $t) { if ($t['enCours']) { $courant = $t['t']; } }
    if (!array_filter($trimestres, static fn ($t) => $t['enCours'])) { foreach ($trimestres as $t) { if ($t['clos']) { $courant = $t['t']; } } }

    return ['shop' => $shop, 'magasin' => $nom, 'exercice' => $exercice, 'aujourdhui' => $auj,
        'rampe' => $rampe, 'engagements' => (object) $engagements, 'annee' => $annee,
        'actions' => $actions, 'trimestres' => $trimestres, 'historique' => $historique, 'trimestreCourant' => $courant,
        'voix' => ['franchise' => 'Franchisé', 'consultant' => 'Consultant', 'marque' => 'Marque']];
}

/**
 * Les quatre trimestres d'une année, agrégés depuis les mois du suivi :
 * objectif (budget validé, à défaut théorique), attendu à ce jour (mois
 * écoulés en entier, mois en cours au prorata), réalisé, écart, clients
 * manquants, ratios pondérés par le CA.
 */
function planTrimestres(array $cells, int $exercice, array $notes, array $revues): array
{
    $moisCourant = (int) date('n'); $anCourant = (int) date('Y');
    $joursMois = (int) date('t'); $jourDuMois = (int) date('j');
    $dernierComplet = $exercice < $anCourant ? 12 : ($exercice > $anCourant ? 0 : $moisCourant - 1);
    $objDe = static function (?array $c): ?float {
        if (!$c) { return null; }
        if ($c['caBudget'] !== null && $c['caBudget'] > 0) { return (float) $c['caBudget']; }
        if ($c['caTheorique'] !== null && $c['caTheorique'] > 0) { return (float) $c['caTheorique']; }
        return null;
    };
    $trimestres = [];
    for ($q = 1; $q <= 4; $q++) {
        $obj = 0.0; $objN = 0; $ca = 0.0; $caN = 0; $tk = 0.0; $food = 0.0; $foodCa = 0.0; $lab = 0.0; $labCa = 0.0; $net = 0.0; $netCa = 0.0;
        $attendu = 0.0;
        for ($m = ($q - 1) * 3 + 1; $m <= $q * 3; $m++) {
            $c = $cells[$exercice][$m] ?? null;
            $o = $objDe($c);
            if ($o !== null) { $obj += $o; $objN++;
                if ($exercice < $anCourant || ($exercice === $anCourant && $m < $moisCourant)) { $attendu += $o; }
                elseif ($exercice === $anCourant && $m === $moisCourant) { $attendu += $o * $jourDuMois / $joursMois; }
            }
            if ($c && $c['ca'] !== null && $c['ca'] > 0) {
                $ca += (float) $c['ca']; $caN++;
                if (!empty($c['tickets'])) { $tk += (float) $c['tickets']; }
                if ($c['foodCostPct'] !== null) { $food += $c['foodCostPct'] * $c['ca']; $foodCa += $c['ca']; }
                if ($c['labourCostPct'] !== null) { $lab += $c['labourCostPct'] * $c['ca']; $labCa += $c['ca']; }
                if ($c['margeNette'] !== null) { $net += (float) $c['margeNette']; $netCa += $c['ca']; }
            }
        }
        $clos = $dernierComplet >= $q * 3;
        $enCours = !$clos && ($exercice === $anCourant) && $moisCourant >= ($q - 1) * 3 + 1;
        $futur = !$clos && !$enCours;
        $panier = $tk > 0 ? $ca / $tk : null;
        $ecart = ($objN && $caN) ? $ca - $attendu : null;
        $rv = $revues[$q] ?? ['etape' => 1, 'validePar' => null, 'valideLe' => null];
        $trimestres[] = ['t' => $q, 'label' => 'T' . $q . ' ' . $exercice,
            'mois' => [($q - 1) * 3 + 1, ($q - 1) * 3 + 2, $q * 3],
            'clos' => $clos, 'enCours' => $enCours, 'futur' => $futur,
            'objectif' => $objN ? round($obj, 2) : null, 'attendu' => $objN ? round($attendu, 2) : null,
            'realise' => $caN ? round($ca, 2) : null, 'moisRealises' => $caN,
            'ecart' => $ecart !== null ? round($ecart, 2) : null,
            'ecartPct' => ($ecart !== null && $attendu > 0) ? round($ecart / $attendu * 100, 1) : null,
            'clients' => ($ecart !== null && $panier) ? (int) round(-$ecart / $panier) : null,
            'panier' => $panier !== null ? round($panier, 2) : null,
            'food' => $foodCa > 0 ? round($food / $foodCa, 1) : null,
            'labour' => $labCa > 0 ? round($lab / $labCa, 1) : null,
            'netPct' => $netCa > 0 ? round($net / $netCa * 100, 1) : null,
            'notes' => $notes[$q] ?? (object) [],
            'revue' => $rv];
    }
    return $trimestres;
}

/** POST /plan/engagement — l'objectif de l'année, ses raisons, son statut. */
function wr_plan_engagement(): array
{
    ensurePlan();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? '')); $an = (int) ($b['exercice'] ?? 0);
    if ($shop === '' || $an < 2000) { http_response_code(400); return ['error' => 'magasin ou exercice manquant']; }
    $statut = (string) ($b['statut'] ?? 'brouillon');
    if (!in_array($statut, PLAN_STATUTS_ENG, true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
    $obj = isset($b['objectif']) && $b['objectif'] !== '' ? (float) $b['objectif'] : null;
    $rampe = isset($b['rampe']) && $b['rampe'] !== '' ? (float) $b['rampe'] : null;
    $motif = trim((string) ($b['motif'] ?? ''));
    $par = trim((string) ($b['par'] ?? '')) ?: null;
    if ($statut !== 'brouillon' && ($obj === null || $obj <= 0)) { http_response_code(422); return ['error' => 'un engagement sans objectif ne se dépose pas']; }
    $prev = Db::row('SELECT * FROM ceo_plan_engagement WHERE shop_id = ? AND year = ?', [$shop, $an]);
    $engagePar = $prev['engage_par'] ?? null; $engageLe = $prev['engage_le'] ?? null;
    $validePar = $prev['valide_par'] ?? null; $valideLe = $prev['valide_le'] ?? null;
    if ($statut === 'engage' && ($prev === null || $prev['statut'] === 'brouillon')) { $engagePar = $par; $engageLe = date('Y-m-d H:i:s'); }
    if ($statut === 'valide') { if ($engageLe === null) { $engagePar = $par; $engageLe = date('Y-m-d H:i:s'); } $validePar = $par; $valideLe = date('Y-m-d H:i:s'); }
    if ($statut === 'brouillon') { $engagePar = null; $engageLe = null; $validePar = null; $valideLe = null; }
    Db::exec('INSERT INTO ceo_plan_engagement (shop_id, year, objectif, rampe, motif, statut, engage_par, engage_le, valide_par, valide_le, maj_le)
              VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE objectif = VALUES(objectif), rampe = VALUES(rampe), motif = VALUES(motif), statut = VALUES(statut),
                engage_par = VALUES(engage_par), engage_le = VALUES(engage_le), valide_par = VALUES(valide_par), valide_le = VALUES(valide_le), maj_le = NOW()',
        [$shop, $an, $obj, $rampe, $motif, $statut, $engagePar, $engageLe, $validePar, $valideLe]);
    if ($statut !== 'brouillon') {
        journalAdd($par ?? 'CEO', 'Plan', magasinConnu($shop), ($statut === 'valide' ? 'Engagement validé ' : 'Engagement déposé ') . $an . ' : ' . number_format((float) $obj, 0, ',', ' ') . ' €');
    }
    return ['ok' => true, 'statut' => $statut, 'engageLe' => $engageLe, 'valideLe' => $valideLe];
}

/** POST /plan/action — une action : créer, modifier, supprimer. */
function wr_plan_action(): array
{
    ensurePlan();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? '')); $an = (int) ($b['exercice'] ?? 0);
    if ($shop === '' || $an < 2000) { http_response_code(400); return ['error' => 'magasin ou exercice manquant']; }
    $id = (int) ($b['id'] ?? 0);
    if (!empty($b['supprimer'])) {
        if ($id > 0) { Db::exec('DELETE FROM ceo_plan_action WHERE id = ? AND shop_id = ? AND year = ?', [$id, $shop, $an]); }
        return ['ok' => true, 'supprime' => $id];
    }
    $lib = trim((string) ($b['libelle'] ?? ''));
    if ($lib === '') { http_response_code(422); return ['error' => 'une action sans libellé']; }
    $statut = (string) ($b['statut'] ?? 'alancer');
    if (!in_array($statut, PLAN_STATUTS_ACTION, true)) { http_response_code(422); return ['error' => 'statut inconnu']; }
    $effet = isset($b['effetAn']) && $b['effetAn'] !== '' ? (float) $b['effetAn'] : null;
    $trim = isset($b['trimestre']) && $b['trimestre'] !== '' ? max(1, min(4, (int) $b['trimestre'])) : null;
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['dateLancement'] ?? '')) ? (string) $b['dateLancement'] : null;
    $vals = [mb_substr($lib, 0, 200), $effet, $trim, $statut, mb_substr(trim((string) ($b['responsable'] ?? '')), 0, 120),
        $date, mb_substr(trim((string) ($b['mesure'] ?? '')), 0, 200), mb_substr(trim((string) ($b['effetConstate'] ?? '')), 0, 200)];
    if ($id > 0) {
        Db::exec('UPDATE ceo_plan_action SET libelle = ?, effet_an = ?, trimestre = ?, statut = ?, responsable = ?, date_lancement = ?, mesure = ?, effet_constate = ?, maj_le = NOW()
                  WHERE id = ? AND shop_id = ? AND year = ?', array_merge($vals, [$id, $shop, $an]));
    } else {
        $ordre = (int) (Db::row('SELECT COALESCE(MAX(ordre), 0) + 1 n FROM ceo_plan_action WHERE shop_id = ? AND year = ?', [$shop, $an])['n'] ?? 1);
        Db::exec('INSERT INTO ceo_plan_action (shop_id, year, libelle, effet_an, trimestre, statut, responsable, date_lancement, mesure, effet_constate, ordre, maj_le)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())', array_merge([$shop, $an], $vals, [$ordre]));
        $id = (int) Db::pdo()->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}

/** POST /plan/trimestre — une voix d'un trimestre (ou la suite : ce qui est mis en route). */
function wr_plan_trimestre(): array
{
    ensurePlan();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? '')); $an = (int) ($b['exercice'] ?? 0); $q = (int) ($b['trimestre'] ?? 0);
    $qui = (string) ($b['auteur'] ?? ''); $texte = trim((string) ($b['texte'] ?? ''));
    if ($shop === '' || $an < 2000 || $q < 1 || $q > 4) { http_response_code(400); return ['error' => 'magasin, exercice ou trimestre manquant']; }
    if (!in_array($qui, PLAN_VOIX, true)) { http_response_code(422); return ['error' => 'voix inconnue : ' . $qui]; }
    if (mb_strlen($texte) > 4000) { http_response_code(422); return ['error' => 'texte trop long (maximum 4 000 caractères)']; }
    $par = trim((string) ($b['par'] ?? '')) ?: null;
    if ($texte === '') {
        Db::exec('DELETE FROM ceo_plan_trimestre WHERE shop_id = ? AND year = ? AND trimestre = ? AND auteur = ?', [$shop, $an, $q, $qui]);
        return ['ok' => true, 'vide' => true];
    }
    Db::exec('INSERT INTO ceo_plan_trimestre (shop_id, year, trimestre, auteur, texte, maj_par, maj_le) VALUES (?,?,?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE texte = VALUES(texte), maj_par = VALUES(maj_par), maj_le = NOW()', [$shop, $an, $q, $qui, $texte, $par]);
    return ['ok' => true, 'par' => $par, 'le' => date('Y-m-d H:i:s')];
}

/** POST /plan/revue — l'étape atteinte du rituel, et sa validation. */
function wr_plan_revue(): array
{
    ensurePlan();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? '')); $an = (int) ($b['exercice'] ?? 0); $q = (int) ($b['trimestre'] ?? 0);
    if ($shop === '' || $an < 2000 || $q < 1 || $q > 4) { http_response_code(400); return ['error' => 'magasin, exercice ou trimestre manquant']; }
    $etape = max(1, min(5, (int) ($b['etape'] ?? 1)));
    $par = trim((string) ($b['par'] ?? '')) ?: null;
    $valider = !empty($b['valider']);
    if ($valider) {
        // Une revue ne se valide pas avec une voix muette : c'est le sens du rituel.
        $voix = [];
        foreach (Db::rows('SELECT auteur, texte FROM ceo_plan_trimestre WHERE shop_id = ? AND year = ? AND trimestre = ?', [$shop, $an, $q]) as $r) {
            if (trim((string) $r['texte']) !== '') { $voix[(string) $r['auteur']] = true; }
        }
        $manque = array_values(array_filter(['franchise', 'consultant', 'marque', 'suite'], static fn ($v) => !isset($voix[$v])));
        if ($manque !== []) {
            http_response_code(422);
            $noms = ['franchise' => 'la voix du franchisé', 'consultant' => 'la voix du consultant', 'marque' => 'la voix de la marque', 'suite' => 'ce qui est mis en route au prochain trimestre'];
            return ['error' => 'La revue ne se valide pas : il manque ' . implode(', ', array_map(static fn ($v) => $noms[$v], $manque)) . '.'];
        }
        Db::exec('INSERT INTO ceo_plan_revue (shop_id, year, trimestre, etape, valide_par, valide_le) VALUES (?,?,?,5,?,NOW())
                  ON DUPLICATE KEY UPDATE etape = 5, valide_par = VALUES(valide_par), valide_le = NOW()', [$shop, $an, $q, $par]);
        journalAdd($par ?? 'CEO', 'Plan', magasinConnu($shop), 'Revue T' . $q . ' ' . $an . ' validée');
        return ['ok' => true, 'valideLe' => date('Y-m-d H:i:s'), 'validePar' => $par];
    }
    Db::exec('INSERT INTO ceo_plan_revue (shop_id, year, trimestre, etape) VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE etape = GREATEST(etape, VALUES(etape))', [$shop, $an, $q, $etape]);
    return ['ok' => true, 'etape' => $etape];
}
