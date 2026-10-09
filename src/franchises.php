<?php
declare(strict_types=1);

/**
 * Les franchisés — évaluation et suivi, réunis (09/10/2026).
 *
 * Deux volets, lus là où chaque chose vit, sans rien ressaisir :
 *
 *   - SUIVI JOURNALIER ET OPÉRATIONS : ce que les données disent chaque jour,
 *     sans aller sur place — les tâches du panel et leurs photos (relevé
 *     `ceo_tache_jour`, revues du jour), les invendus, les objectifs produits et
 *     de campagne, les remarques aux opérateurs, la note Google, le CA de la
 *     semaine face à l'objectif.
 *   - SUIVI DE TERRAIN : ce que le consultant constate sur place — la dernière
 *     visite et sa checklist, les plans d'action, les prochaines visites au
 *     cadre, le client mystère, la conformité du comptoir, le scoring du
 *     trimestre (la synthèse : deux postes du journalier, un du terrain, un du
 *     pilotage).
 *
 * `GET /franchises` rend le tableau des magasins avec les indicateurs des deux
 * volets ; `GET /franchises/fiche?shop=` la fiche d'un magasin, avec le journal
 * des constats. Une source qui ne répond pas rend son motif, pas un zéro.
 */

/** Le relevé des tâches du panel sur n jours : rendues / attendues, les jours où une obligatoire a manqué. */
function frTachesJours(string $shop, int $jours = 30): array
{
    $out = ['pct' => null, 'rendues' => 0, 'attendues' => 0, 'joursObligManques' => 0, 'jours' => [], 'releveAu' => null, 'motif' => null, 'du' => date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'))];
    try {
        if (function_exists('tachesSuiviTables')) { tachesSuiviTables(); }
        $oblig = [];
        if (function_exists('sqObligatoires')) { try { $oblig = array_map('intval', (array) (sqObligatoires()['ids'] ?? [])); } catch (Throwable $e) { $oblig = []; } }
        $rows = Db::rows('SELECT jour, id_task, fait FROM ceo_tache_jour WHERE id_shop = ? AND jour >= ? ORDER BY jour', [(int) $shop, $out['du']]);
    } catch (Throwable $e) { $out['motif'] = 'relevé des tâches indisponible'; return $out; }
    $parJour = [];
    foreach ($rows as $r) {
        $j = (string) $r['jour'];
        $parJour[$j] = $parJour[$j] ?? ['jour' => $j, 'f' => 0, 't' => 0, 'oblig' => false];
        $parJour[$j]['t']++;
        if ((int) $r['fait'] === 1) { $parJour[$j]['f']++; }
        elseif ($oblig !== [] && in_array((int) $r['id_task'], $oblig, true)) { $parJour[$j]['oblig'] = true; }
    }
    foreach ($parJour as $d) { $out['rendues'] += $d['f']; $out['attendues'] += $d['t']; if ($d['oblig']) { $out['joursObligManques']++; } }
    $out['pct'] = $out['attendues'] > 0 ? (int) round(100 * $out['rendues'] / $out['attendues']) : null;
    $out['jours'] = array_values($parJour);
    $out['releveAu'] = $parJour ? (string) array_key_last($parJour) : null;
    if ($parJour === []) { $out['motif'] = 'aucune journée relevée sur la fenêtre'; }
    return $out;
}

/** Les revues du jour (panel) pour un magasin : tâches photographiées, validées, à noter. */
function frTachesJour(string $shop): array
{
    if (!function_exists('ep_pwa_tasks')) { return ['lu' => false, 'motif' => 'module absent']; }
    try { $t = ep_pwa_tasks(); } catch (Throwable $e) { return ['lu' => false, 'motif' => $e->getMessage()]; }
    if (!empty($t['indispo'])) { return ['lu' => false, 'motif' => 'les revues du panel ne se lisent pas']; }
    foreach ($t['shops'] ?? [] as $s) {
        if ((string) ($s['shopId'] ?? '') !== $shop) { continue; }
        $n = count($s['taches'] ?? []); $valides = 0; $notees = 0;
        foreach ($s['taches'] as $x) { if (!empty($x['valide'])) { $valides++; } if (($x['note'] ?? null) !== null) { $notees++; } }
        return ['lu' => true, 'date' => $t['date'] ?? null, 'taches' => $n, 'valides' => $valides, 'notees' => $notees, 'aNoter' => max(0, $n - $notees)];
    }
    return ['lu' => true, 'date' => $t['date'] ?? null, 'taches' => 0, 'valides' => 0, 'notees' => 0, 'aNoter' => 0];
}

/** Les invendus des n derniers jours : pièces, coût, part du CA, motifs, produits. Une lecture du panel, gardée par le module invendus. */
const FR_INVENDUS_CIBLE = 4.0;
function frInvendus(string $shop, int $jours = 7): array
{
    // Le franchisé est suivi sur UNE mesure (demande du 09/10/2026) : la part des invendus dans le
    // CA. Les pièces, les motifs et les produits restent sur l'écran Invendus et poubelle.
    static $caM = null; static $fen = null;
    $au = date('Y-m-d'); $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $base = ['lu' => false, 'du' => $du, 'au' => $au, 'jours' => $jours, 'part' => null, 'cible' => FR_INVENDUS_CIBLE, 'motif' => null];
    if (!function_exists('invBilan')) { $base['motif'] = 'module absent'; return $base; }
    try {
        $b = invBilan((int) $shop, $du, $au, invLignes((int) $shop, $du, $au));
        if ($caM === null || $fen !== $du . $au) { $caM = invCaMagasins($du, $au); $fen = $du . $au; }
    } catch (Throwable $e) { $base['motif'] = $e->getMessage(); return $base; }
    if ($b === null) { $base['motif'] = 'la poubelle du panel ne se lit pas'; return $base; }
    $ca = $caM[(int) $shop] ?? $caM[$shop] ?? null;
    $cout = (float) ($b['cout'] ?? 0);
    $caOk = $ca !== null && (float) $ca > 0;
    return ['lu' => true, 'du' => $du, 'au' => $au, 'jours' => $jours, 'cout' => round($cout, 2), 'ca' => $ca,
        'part' => $caOk ? round(100 * $cout / (float) $ca, 1) : null, 'cible' => FR_INVENDUS_CIBLE, 'motif' => $caOk ? null : 'CA de la période inconnu'];
}
function frRevues(string $shop, int $jours = 30): array
{
    // Les notes que le consultant pose sur les tâches photographiées (mac_task_review, miroir local
    // des revues du panel) : les points moyens, et TOUTES les infractions — chaque tâche notée sous
    // le seuil du barème (3 mineur, 2 majeur, 1 critique), avec sa récidive et sa suite.
    $au = date('Y-m-d'); $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $sig = function_exists('setting') ? setting('signalement', []) : [];
    $seuil = is_array($sig) && isset($sig['seuil']) ? (int) $sig['seuil'] : 4;
    $noms = [];
    foreach ((is_array($sig) && !empty($sig['niveaux'])) ? (array) $sig['niveaux'] : [] as $n) { if (is_array($n) && isset($n['n'])) { $noms[(int) $n['n']] = (string) ($n['nom'] ?? ''); } }
    $defaut = [5 => 'Exemplaire', 4 => 'Conforme', 3 => 'Non conforme — mineur', 2 => 'Non conforme — majeur', 1 => 'Non conforme — critique'];
    $out = ['lu' => false, 'du' => $du, 'au' => $au, 'jours' => $jours, 'seuil' => $seuil, 'notees' => 0, 'moyenne' => null, 'parNote' => [],
        'nc' => 0, 'mineures' => 0, 'majeures' => 0, 'critiques' => 0, 'infractions' => [], 'motif' => null];
    foreach ([5, 4, 3, 2, 1] as $n) { $out['parNote'][] = ['note' => $n, 'nom' => $noms[$n] ?? $defaut[$n], 'n' => 0]; }
    $fenDu = date('Y-m-d', strtotime($du . ' -6 days'));
    try {
        $rows = Db::rows('SELECT id_task, review_date, rating, is_accepted, comment, consultant_name FROM mac_task_review'
            . ' WHERE id_shop = ? AND review_date BETWEEN ? AND ? ORDER BY review_date', [(int) $shop, $fenDu, $au]);
    } catch (Throwable $e) { $out['motif'] = 'les revues des tâches ne se lisent pas'; return $out; }
    $out['lu'] = true;
    $estNC = static fn (array $r): bool => ($r['rating'] !== null && (int) $r['rating'] < $seuil) || ($r['is_accepted'] !== null && (int) $r['is_accepted'] === 0);
    $parTache = []; $somme = 0.0; $vus = [];
    foreach ($rows as $r) {
        $t = (int) $r['id_task']; $j = (string) $r['review_date']; $note = $r['rating'] !== null ? (int) $r['rating'] : null;
        $parTache[$t][] = ['jour' => $j, 'note' => $note, 'nc' => $estNC($r)];
        if ($j < $du) { continue; }
        $vus[$t] = true;
        if ($note === null) { continue; }
        $out['notees']++; $somme += $note;
        foreach ($out['parNote'] as $k => $p) { if ($p['note'] === $note) { $out['parNote'][$k]['n']++; } }
    }
    $out['moyenne'] = $out['notees'] > 0 ? round($somme / $out['notees'], 1) : null;
    // Le nom de la tâche : le relevé quotidien (le plus récent), le référentiel, sinon l'identifiant.
    $nomsT = []; $vu = [];
    try {
        foreach (Db::rows("SELECT id_task, nom FROM ceo_tache_jour WHERE id_shop = ? AND jour >= ? AND nom <> '' ORDER BY jour DESC", [(int) $shop, $fenDu]) as $r) {
            $t = (int) $r['id_task']; if (isset($vu[$t])) { continue; } $vu[$t] = true; $nomsT[$t] = trim((string) $r['nom']);
        }
    } catch (Throwable $e) { /* sans relevé : le référentiel */ }
    foreach (function_exists('todoTaskNames') ? (array) todoTaskNames() : [] as $t => $n) { $nomsT[(int) $t] = $nomsT[(int) $t] ?? (string) $n; }
    $reste = array_keys(array_diff_key($vus, $nomsT));
    if ($reste !== []) {
        try {
            foreach (Db::rows('SELECT id_task, nom FROM ceo_tache_jour WHERE id_task IN (' . implode(',', array_fill(0, count($reste), '?')) . ") AND nom <> '' ORDER BY jour DESC", $reste) as $r) {
                $t = (int) $r['id_task']; $nomsT[$t] = $nomsT[$t] ?? trim((string) $r['nom']);
            }
        } catch (Throwable $e) { /* l'identifiant, jamais un nom inventé */ }
    }
    foreach ($rows as $r) {
        $j = (string) $r['review_date'];
        if ($j < $du || !$estNC($r)) { continue; }
        $t = (int) $r['id_task']; $note = $r['rating'] !== null ? (int) $r['rating'] : null;
        $niveau = $note === null ? 'mineure' : ($note <= 1 ? 'critique' : ($note === 2 ? 'majeure' : 'mineure'));
        $out['nc']++; $out[$niveau . 's']++;
        // Récidive : les jours non conformes de la même tâche sur les sept jours qui finissent là ;
        // suite : la première note posée après, conforme ou non.
        $debRec = date('Y-m-d', strtotime($j . ' -6 days')); $jRec = []; $suite = null;
        foreach ($parTache[$t] as $h) {
            if ($h['nc'] && $h['jour'] >= $debRec && $h['jour'] <= $j) { $jRec[$h['jour']] = true; }
            if ($suite === null && $h['jour'] > $j && $h['note'] !== null) { $suite = ['jour' => $h['jour'], 'note' => $h['note'], 'conforme' => !$h['nc']]; }
        }
        $com = $r['comment'] !== null ? trim((string) $r['comment']) : '';
        $out['infractions'][] = ['jour' => $j, 'taskId' => (string) $t, 'tache' => $nomsT[$t] ?? ('Tâche #' . $t), 'note' => $note, 'niveau' => $niveau,
            'niveauNom' => $note !== null ? ($noms[$note] ?? $defaut[$note] ?? ('note ' . $note)) : 'refusée sans note',
            'comment' => $com !== '' ? $com : null, 'consultant' => $r['consultant_name'] !== null ? (string) $r['consultant_name'] : null,
            'recidive' => count($jRec) > 1 ? count($jRec) : null, 'suite' => $suite];
    }
    usort($out['infractions'], fn ($a, $b) => strcmp($b['jour'], $a['jour']));
    return $out;
}
function frReclamationsTous(): array
{
    // Une seule lecture du panel pour tous les magasins (la fiche comme le tableau).
    static $cache = null;
    if ($cache !== null) { return $cache; }
    if (!function_exists('ep_fournisseurs_reclamations')) { return $cache = ['indispo' => true, 'motif' => 'module absent']; }
    $get = $_GET; $_GET = ['mois' => '2'];
    try { $r = ep_fournisseurs_reclamations(); } catch (Throwable $e) { $r = ['indispo' => true, 'motif' => $e->getMessage()]; }
    $_GET = $get;
    return $cache = is_array($r) ? $r : ['indispo' => true, 'motif' => 'sans réponse'];
}
function frReclamations(string $shop, int $jours = 30): array
{
    // Les réclamations fournisseur du magasin (panel, material-complaints) : combien, lesquelles
    // restent sans réponse, ce qui est réclamé.
    $au = date('Y-m-d'); $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $r = frReclamationsTous();
    if (!empty($r['indispo'])) { return ['lu' => false, 'du' => $du, 'au' => $au, 'jours' => $jours, 'n' => 0, 'ouvertes' => 0, 'motif' => (string) ($r['motif'] ?? 'le panel n’a pas rendu les réclamations')]; }
    $n = 0; $ouvertes = 0; $acceptees = 0; $refusees = 0; $montant = 0.0; $parF = []; $dern = [];
    foreach ((array) ($r['lignes'] ?? []) as $l) {
        if (!is_array($l) || (string) ($l['shopId'] ?? '') !== $shop) { continue; }
        $le = (string) ($l['le'] ?? '');
        if ($le !== '' && $le < $du) { continue; }
        $n++;
        $ouverte = !empty($l['ouverte']); if ($ouverte) { $ouvertes++; }
        $st = (string) ($l['statut'] ?? '');
        if ($st === 'ACCEPTED') { $acceptees++; } elseif ($st === 'REJECTED') { $refusees++; }
        if (isset($l['montant']) && $l['montant'] !== null) { $montant += (float) $l['montant']; }
        $f = (string) ($l['fournisseur'] ?? ''); $f = $f !== '' ? $f : 'Fournisseur inconnu'; $parF[$f] = ($parF[$f] ?? 0) + 1;
        if (count($dern) < 5) {
            $dern[] = ['id' => $l['id'] ?? null, 'le' => $le, 'fournisseur' => (string) ($l['fournisseur'] ?? ''), 'reference' => (string) ($l['reference'] ?? ''),
                'qte' => $l['qte'] ?? null, 'unite' => (string) ($l['unite'] ?? ''), 'motif' => (string) ($l['motif'] ?? ''), 'statut' => $st, 'ouverte' => $ouverte,
                'reponse' => (string) ($l['reponse'] ?? ''), 'montant' => $l['montant'] ?? null];
        }
    }
    arsort($parF);
    return ['lu' => true, 'du' => $du, 'au' => $au, 'jours' => $jours, 'n' => $n, 'ouvertes' => $ouvertes, 'acceptees' => $acceptees, 'refusees' => $refusees,
        'montant' => round($montant, 2), 'parFournisseur' => array_map(fn ($k, $v) => ['nom' => $k, 'n' => $v], array_keys($parF), array_values($parF)), 'dernieres' => $dern, 'motif' => null];
}

/** Les objectifs du moment : produits (campagne d'objectifs produits) et clients (campagnes marketing en cours). */
function frObjectifs(string $shop): array
{
    $out = ['produits' => [], 'campagnes' => []];
    if (function_exists('ep_objectifs_produits_magasin')) {
        $sauve = $_GET;
        try {
            $_GET['shop'] = $shop; $_GET['date'] = date('Y-m-d');
            $o = ep_objectifs_produits_magasin();
            foreach ($o['campagnes'] ?? [] as $c) {
                $obj = (float) ($c['objectif'] ?? 0); $vendu = (float) ($c['vendu'] ?? 0);
                $out['produits'][] = ['id' => $c['id'], 'nom' => $c['nom'], 'objectif' => $c['objectif'] ?? null, 'vendu' => $c['vendu'] ?? null, 'ceJour' => $c['ceJour'] ?? null,
                    'pct' => $obj > 0 ? (int) round(100 * $vendu / $obj) : null, 'debut' => $c['debut'] ?? null, 'fin' => $c['fin'] ?? null, 'produits' => count($c['produits'] ?? [])];
            }
        } catch (Throwable $e) { /* pas d'objectif produit */ }
        $_GET = $sauve;
    }
    if (function_exists('viCampagnesDe')) {
        try {
            $k = viCampagnesDe($shop, date('Y-m-d'));
            foreach ($k['campagnes'] ?? [] as $c) {
                if (($c['statut'] ?? '') !== 'encours') { continue; }
                $out['campagnes'][] = ['id' => $c['id'] ?? null, 'nom' => $c['nom'] ?? '', 'pct' => $c['pct'] ?? null, 'reel' => $c['reel'] ?? null, 'n1Ecoule' => $c['n1Ecoule'] ?? null,
                    'clientsPrevus' => $c['clientsPrevus'] ?? null, 'jourCourant' => $c['jourCourant'] ?? null, 'nbJours' => $c['nbJours'] ?? null, 'objectifCA' => $c['objectifCA'] ?? null, 'caReel' => $c['caReel'] ?? null];
            }
        } catch (Throwable $e) { /* pas de campagne */ }
    }
    return $out;
}

/** Les remarques aux opérateurs des 30 derniers jours : combien, par opérateur, les dernières. */
function frRemarques(string $shop, int $jours = 30): array
{
    $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $r = function_exists('invRemarques') ? invRemarques((int) $shop, $du, null, null, 200) : [];
    $par = [];
    foreach ($r as $x) { $k = (string) ($x['employe'] ?? '?'); $par[$k] = ($par[$k] ?? 0) + 1; }
    arsort($par);
    return ['n' => count($r), 'du' => $du, 'parOperateur' => array_map(fn ($k, $n) => ['employe' => $k, 'n' => $n], array_keys($par), array_values($par)), 'dernieres' => array_slice($r, 0, 5)];
}

/** Le scoring du trimestre courant, pour tous les magasins (une seule lecture par appel). */
function frScoringTous(): ?array
{
    static $d = null; static $lu = false;
    if ($lu) { return $d; }
    $lu = true;
    if (!function_exists('sqCalcul')) { return null; }
    try { $d = sqCalcul(sqTrimestre(null)); } catch (Throwable $e) { $d = null; }
    return $d;
}

function frScoring(string $shop): ?array
{
    $d = frScoringTous();
    foreach ($d['magasins'] ?? [] as $l) {
        if ((string) $l['id'] !== $shop) { continue; }
        return ['trimestre' => $d['trimestre'], 'postes' => $l['postes'], 'total' => $l['total'], 'n' => $l['n'], 'sur' => $l['sur'], 'etoiles' => $l['etoiles'],
            'prec' => $l['prec'], 'delta' => $l['delta'], 'rang' => $l['rang'], 'magasins' => count($d['magasins']), 'noms' => array_map(fn ($p) => $p['nom'], $d['postes'] ?? [])];
    }
    return null;
}

/** Le dernier rapport client mystère encodé au scoring. */
function frMsp(string $shop): ?array
{
    if (!function_exists('sqMspLigne')) { return null; }
    try { if (function_exists('ensureScoring')) { ensureScoring(); } $r = Db::row('SELECT * FROM ceo_scoring_msp WHERE shop_id = ? ORDER BY trimestre DESC, id DESC LIMIT 1', [$shop]); }
    catch (Throwable $e) { return null; }
    return $r ? sqMspLigne($r) : null;
}

/** Les visites du magasin : la dernière faite avec sa checklist et ses écarts, les prochaines, le mois, l'historique. */
function frVisites(string $shop): array
{
    ensureVisites();
    if (function_exists('ensureVisitesCadre')) { ensureVisitesCadre(); }
    $auj = date('Y-m-d');
    $typeNom = [];
    foreach (function_exists('vcTypes') ? vcTypes() : [] as $t) { $typeNom[$t['code']] = $t['nom']; }
    $nomDe = fn ($code) => $typeNom[(string) ($code ?: 'reguliere')] ?? ((string) $code === '' || $code === 'reguliere' ? 'Régulière' : (string) $code);
    $derniere = null;
    $der = Db::row('SELECT * FROM ceo_visite WHERE shop_id = ? AND statut = \'terminee\' ORDER BY prevu_le DESC, id DESC LIMIT 1', [$shop]);
    if ($der !== null) {
        $v = viVisiteLigne($der); $v['typeNom'] = $nomDe($v['type']);
        $seuil = (int) viSeuils()['planoOrange'];
        $pts = array_map('viPointLigne', Db::rows('SELECT * FROM ceo_visite_point WHERE visite_id = ?', [(int) $der['id']]));
        $ok = 0; $ko = 0; $faits = 0; $ecarts = [];
        foreach ($pts as $p) {
            $fait = $p['etat'] !== '' || $p['note'] !== null || $p['valeur'] !== null;
            if ($fait) { $faits++; }
            $nc = $p['etat'] === 'ko' || ($p['note'] !== null && $p['note'] <= 2) || ($p['valeur'] !== null && $p['valeur'] < $seuil);
            if ($nc) { $ko++; $ecarts[] = ['ref' => $p['ref'], 'module' => $p['module'], 'libelle' => $p['libelle'], 'note' => $p['note'], 'valeur' => $p['valeur'], 'commentaire' => $p['commentaire']]; }
            elseif ($fait) { $ok++; }
        }
        $v['points'] = ['total' => count($pts), 'faits' => $faits, 'ok' => $ok, 'ko' => $ko];
        $v['ecarts'] = $ecarts;
        $derniere = $v;
    }
    $prochaines = [];
    foreach (Db::rows('SELECT * FROM ceo_visite WHERE shop_id = ? AND statut IN (\'planifiee\', \'confirmee\', \'en_cours\') AND prevu_le >= ? ORDER BY prevu_le, debut_h LIMIT 6', [$shop, $auj]) as $r) {
        $v = viVisiteLigne($r); $v['typeNom'] = $nomDe($v['type']); $v['google'] = function_exists('vcGoogleLien') ? vcGoogleLien($r) : null; $prochaines[] = $v;
    }
    $n = Db::row('SELECT SUM(CASE WHEN statut = \'terminee\' THEN 1 ELSE 0 END) f, SUM(CASE WHEN statut IN (\'planifiee\', \'confirmee\', \'en_cours\') THEN 1 ELSE 0 END) p FROM ceo_visite WHERE shop_id = ? AND prevu_le BETWEEN ? AND ?', [$shop, date('Y-m-01'), date('Y-m-t')]);
    $historique = array_map(fn ($r) => ['id' => (int) $r['id'], 'le' => $r['prevu_le'], 'type' => (string) (($r['type_code'] ?? '') ?: 'reguliere'), 'typeNom' => $nomDe($r['type_code'] ?? ''), 'consultant' => $r['consultant_nom'], 'reco' => $r['reco']],
        Db::rows('SELECT id, prevu_le, type_code, consultant_nom, reco FROM ceo_visite WHERE shop_id = ? AND statut = \'terminee\' ORDER BY prevu_le DESC LIMIT 8', [$shop]));
    return ['derniere' => $derniere, 'prochaines' => $prochaines, 'mois' => ['faites' => (int) ($n['f'] ?? 0), 'planifiees' => (int) ($n['p'] ?? 0)], 'historique' => $historique];
}

function frConformite(string $shop): ?array
{
    if (!function_exists('vcConformiteMagasin')) { return null; }
    try { return vcConformiteMagasin((int) $shop, 30); } catch (Throwable $e) { return ['motif' => $e->getMessage()]; }
}

/** Le journal de l'évaluation : visites, plans d'action, remarques, client mystère — les deux volets mêlés, du plus récent au plus ancien. */
function frJournal(string $shop, int $jours = 60): array
{
    $du = date('Y-m-d', strtotime('-' . $jours . ' days'));
    $typeNom = [];
    foreach (function_exists('vcTypes') ? vcTypes() : [] as $t) { $typeNom[$t['code']] = $t['nom']; }
    $ev = [];
    try {
        foreach (Db::rows('SELECT id, prevu_le, type_code, consultant_nom, reco FROM ceo_visite WHERE shop_id = ? AND statut = \'terminee\' AND prevu_le >= ?', [$shop, $du]) as $r) {
            $ev[] = ['le' => $r['prevu_le'], 'volet' => 'terrain', 'genre' => 'visite', 'id' => (int) $r['id'],
                'texte' => 'Visite ' . ($typeNom[(string) (($r['type_code'] ?? '') ?: 'reguliere')] ?? 'régulière') . ' par ' . $r['consultant_nom'] . (!empty($r['reco']) ? ' — ' . mb_substr((string) $r['reco'], 0, 140) : '')];
        }
        foreach (Db::rows('SELECT e.quand, e.qui, e.de_statut, e.vers_statut, e.commentaire, a.titre, a.priorite, a.id plan_id FROM ceo_visite_action_evt e JOIN ceo_visite_action a ON a.id = e.plan_id WHERE a.shop_id = ? AND e.quand >= ? ORDER BY e.quand DESC LIMIT 40', [$shop, $du . ' 00:00:00']) as $r) {
            $ev[] = ['le' => substr((string) $r['quand'], 0, 10), 'volet' => 'terrain', 'genre' => 'plan', 'id' => (int) $r['plan_id'],
                'texte' => $r['priorite'] . ' ' . $r['titre'] . ' : ' . ($r['de_statut'] ? $r['de_statut'] . ' → ' : '') . $r['vers_statut'] . (!empty($r['commentaire']) ? ' · ' . mb_substr((string) $r['commentaire'], 0, 100) : '') . ' (' . $r['qui'] . ')'];
        }
        foreach (Db::rows('SELECT id, titre, priorite, cree_par, cree_le FROM ceo_visite_action WHERE shop_id = ? AND cree_le >= ? ORDER BY cree_le DESC LIMIT 40', [$shop, $du . ' 00:00:00']) as $r) {
            $ev[] = ['le' => substr((string) $r['cree_le'], 0, 10), 'volet' => 'terrain', 'genre' => 'plan', 'id' => (int) $r['id'], 'texte' => $r['priorite'] . ' ' . $r['titre'] . ' ouvert (' . $r['cree_par'] . ')'];
        }
    } catch (Throwable $e) { /* tables des visites absentes */ }
    if (function_exists('invRemarques')) {
        foreach (invRemarques((int) $shop, $du, null, null, 40) as $r) {
            $ev[] = ['le' => (string) ($r['le'] ?? ''), 'volet' => 'journalier', 'genre' => 'remarque', 'id' => $r['id'] ?? null,
                'texte' => 'Remarque à ' . ($r['employe'] ?? 'un opérateur') . ' : ' . ($r['produit'] ?? '') . (!empty($r['motif']) ? ' · ' . $r['motif'] : '') . (!empty($r['texte']) ? ' — ' . mb_substr((string) $r['texte'], 0, 100) : '')];
        }
    }
    try {
        foreach (Db::rows('SELECT id, trimestre, obtenu, maximum, le FROM ceo_scoring_msp WHERE shop_id = ? AND le >= ?', [$shop, $du . ' 00:00:00']) as $r) {
            $ev[] = ['le' => substr((string) $r['le'], 0, 10), 'volet' => 'terrain', 'genre' => 'msp', 'id' => (int) $r['id'], 'texte' => 'Client mystère ' . $r['trimestre'] . ' : ' . $r['obtenu'] . ' / ' . $r['maximum'] . ' encodé'];
        }
    } catch (Throwable $e) { /* pas de scoring */ }
    usort($ev, fn ($a, $b) => strcmp((string) $b['le'], (string) $a['le']));
    return array_slice($ev, 0, 40);
}

/** GET /franchises/fiche?shop=4 — la fiche d'un franchisé, en deux volets, avec le journal. */
function ep_franchises_fiche(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $mags = viMagasins();
    if ($shop === '' || !isset($mags[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    @set_time_limit(120);
    $etat = viBoutiquesEtat($shop, date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('+60 days')));
    $b = $etat['boutiques'][0] ?? null;
    $cadre = function_exists('vcAttendu') ? array_values(array_filter(vcAttendu(date('Y-m')), fn ($l) => $l['shop'] === $shop)) : [];
    $cons = [];
    foreach ($cadre as $l) { if ($l['consultant'] !== '' && !isset($cons[$l['consultant']])) { $cons[$l['consultant']] = ['id' => $l['consultant'], 'nom' => $l['consultantNom'], 'types' => []]; } if ($l['consultant'] !== '') { $cons[$l['consultant']]['types'][] = $l['typeNom']; } }
    return ['shop' => $shop, 'magasin' => $mags[$shop], 'lu' => date('Y-m-d H:i'), 'aujourdhui' => date('Y-m-d'),
        'feu' => $b ? ['feu' => $b['feu'], 'motifs' => $b['motifs'], 'due' => $b['due']] : null, 'consultants' => array_values($cons),
        'journalier' => ['tachesJour' => frTachesJour($shop), 'taches' => frTachesJours($shop), 'invendus' => frInvendus($shop), 'revues' => frRevues($shop), 'reclamations' => frReclamations($shop), 'objectifs' => frObjectifs($shop),
            'remarques' => frRemarques($shop), 'google' => $etat['google'][$shop] ?? null, 'ca' => $etat['ca'][$shop] ?? null],
        'terrain' => ['visites' => frVisites($shop), 'plans' => $etat['plans'], 'cadre' => $cadre, 'msp' => frMsp($shop), 'mspVisites' => $etat['msp'][$shop] ?? [],
            'conformite' => frConformite($shop), 'scoring' => frScoring($shop), 'plano' => $b['plano'] ?? null, 'equipe' => $etat['equipe'][$shop] ?? null,
            'app' => rtrim(rapBaseUrl(), '/') . '/visites/?shop=' . rawurlencode($shop)],
        'journal' => frJournal($shop)];
}

/** GET /franchises — le tableau des franchisés : les indicateurs des deux volets, magasin par magasin. */
function ep_franchises(): array
{
    @set_time_limit(120);
    $etat = viBoutiquesEtat(null, date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('+60 days')));
    $sc = frScoringTous(); $scPar = [];
    foreach ($sc['magasins'] ?? [] as $l) { $scPar[(string) $l['id']] = $l; }
    $out = [];
    foreach ($etat['boutiques'] as $b) {
        $sid = (string) $b['id'];
        $t = frTachesJours($sid); $inv = frInvendus($sid); $rv = frRevues($sid); $rc = frReclamations($sid); $msp = frMsp($sid); $s = $scPar[$sid] ?? null;
        $cadre = function_exists('vcCadreDe') ? vcCadreDe($sid) : [];
        $resp = ''; foreach ($cadre as $l) { if ($l['consultant'] !== '' && $resp === '') { $resp = $l['consultantNom']; } }
        $out[] = ['id' => $sid, 'nom' => $b['nom'], 'court' => $b['court'], 'fr' => $b['fr'], 'feu' => $b['feu'], 'motifs' => $b['motifs'], 'due' => $b['due'], 'responsable' => $resp,
            'journalier' => ['scoring' => $s ? ['total' => $s['total'], 'etoiles' => $s['etoiles'], 'rang' => $s['rang'], 'n' => $s['n'], 'sur' => $s['sur']] : null,
                'taches' => ['pct' => $t['pct'], 'joursObligManques' => $t['joursObligManques'], 'motif' => $t['motif']],
                'invendus' => ['lu' => $inv['lu'], 'part' => $inv['part'], 'cible' => $inv['cible']],
                'revues' => ['lu' => $rv['lu'], 'moyenne' => $rv['moyenne'], 'notees' => $rv['notees'], 'nc' => $rv['nc'], 'mineures' => $rv['mineures'], 'majeures' => $rv['majeures'], 'critiques' => $rv['critiques'], 'motif' => $rv['motif']],
                'reclamations' => ['lu' => $rc['lu'], 'n' => $rc['n'], 'ouvertes' => $rc['ouvertes'], 'motif' => $rc['motif']],
                'google' => $b['google'] ? ['note' => $b['google']['note'], 'avis' => $b['google']['avis'], 'faibles' => $b['google']['faibles']] : null,
                'ca' => $b['ca'] ? ['ca' => $b['ca']['ca'], 'pct' => $b['ca']['pct']] : null],
            'terrain' => ['derniereVisite' => $b['derniereVisite'], 'prochaineVisite' => $b['prochaineVisite'], 'plansOuverts' => $b['plansOuverts'], 'p0' => $b['p0'], 'plano' => $b['plano'],
                'msp' => $msp ? ['trimestre' => $msp['trimestre'], 'obtenu' => $msp['obtenu'], 'maximum' => $msp['maximum'], 'v' => $msp['v']] : null, 'cadre' => count($cadre)]];
    }
    return ['magasins' => $out, 'trimestre' => $sc['trimestre'] ?? null, 'lu' => date('Y-m-d H:i')];
}
