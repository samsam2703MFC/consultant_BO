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
    $n = 0; $ouvertes = 0; $acceptees = 0; $refusees = 0; $montant = 0.0; $parF = []; $dern = []; $lignes = [];
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
        $ligne = ['id' => $l['id'] ?? null, 'le' => $le, 'fournisseur' => (string) ($l['fournisseur'] ?? ''), 'reference' => (string) ($l['reference'] ?? ''),
            'qte' => $l['qte'] ?? null, 'unite' => (string) ($l['unite'] ?? ''), 'motif' => (string) ($l['motif'] ?? ''), 'statut' => $st, 'ouverte' => $ouverte,
            'reponse' => (string) ($l['reponse'] ?? ''), 'reponseLe' => $l['reponseLe'] ?? null, 'montant' => $l['montant'] ?? null];
        if (count($dern) < 5) { $dern[] = $ligne; }
        if (count($lignes) < 60) { $lignes[] = $ligne; }   // la modale « Tout voir »
    }
    arsort($parF);
    return ['lu' => true, 'du' => $du, 'au' => $au, 'jours' => $jours, 'n' => $n, 'ouvertes' => $ouvertes, 'acceptees' => $acceptees, 'refusees' => $refusees,
        'montant' => round($montant, 2), 'parFournisseur' => array_map(fn ($k, $v) => ['nom' => $k, 'n' => $v], array_keys($parF), array_values($parF)), 'dernieres' => $dern, 'lignes' => $lignes, 'motif' => null];
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
        'journalier' => ['mois' => frMois($shop), 'tachesJour' => frTachesJour($shop), 'taches' => frTachesJours($shop), 'invendus' => frInvendus($shop), 'revues' => frRevues($shop), 'reclamations' => frReclamations($shop), 'objectifs' => frObjectifs($shop),
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
                'mois' => ($mo = frMois($sid))['lu'] ? ['atteinte' => $mo['atteinte'], 'ca' => $mo['ca'], 'budget' => $mo['budget'], 'pro' => $mo['pro']['part'], 'proLus' => $mo['pro']['joursLus'], 'proJours' => $mo['pro']['jours']] : null,
                'google' => $b['google'] ? ['note' => $b['google']['note'], 'avis' => $b['google']['avis'], 'faibles' => $b['google']['faibles']] : null,
                'ca' => $b['ca'] ? ['ca' => $b['ca']['ca'], 'pct' => $b['ca']['pct']] : null],
            'terrain' => ['derniereVisite' => $b['derniereVisite'], 'prochaineVisite' => $b['prochaineVisite'], 'plansOuverts' => $b['plansOuverts'], 'p0' => $b['p0'], 'plano' => $b['plano'],
                'msp' => $msp ? ['trimestre' => $msp['trimestre'], 'obtenu' => $msp['obtenu'], 'maximum' => $msp['maximum'], 'v' => $msp['v']] : null, 'cadre' => count($cadre)]];
    }
    return ['magasins' => $out, 'trimestre' => $sc['trimestre'] ?? null, 'lu' => date('Y-m-d H:i')];
}

/* --- Santé du mois : le CA face au budget, la part du CA pro (09/10/2026) ---------------- */
const FR_PRO_MAX = 40.0;
const FR_PRO_ALERTE = 35.0;
/** Le résultat du mois en cours, magasin par magasin : une lecture (/exploitation/periode, servie en cache). */
function frMoisTous(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [];
    if (!function_exists('ep_exploitation_periode')) { return $c; }
    $get = $_GET; $_GET = ['vue' => 'mois', 'date' => date('Y-m-d')];
    try { $r = ep_exploitation_periode(); } catch (Throwable $e) { $r = []; }
    $_GET = $get;
    foreach ((array) ($r['magasins'] ?? []) as $l) { if (is_array($l) && isset($l['shopId'])) { $c[(string) $l['shopId']] = $l; } }
    return $c;
}
function frMois(string $shop): array
{
    $l = frMoisTous()[$shop] ?? null;
    $mois = date('Y-m');
    if ($l === null || empty($l['ouvert'])) { return ['lu' => false, 'mois' => $mois, 'motif' => $l === null ? 'le résultat du mois ne se lit pas' : 'magasin fermé ce mois']; }
    $jours = [];
    foreach ((array) ($l['jours'] ?? []) as $j) {
        if (!is_array($j)) { continue; }
        $jours[] = ['date' => (string) ($j['date'] ?? ''), 'court' => (string) ($j['court'] ?? ''), 'ca' => $j['ca'] ?? null, 'objectif' => $j['objectif'] ?? null,
            'ferme' => !empty($j['ferme']), 'passe' => !empty($j['passe']) || !empty($j['aujourdhui'])];
    }
    $att = $l['atteinte'] ?? null;
    return ['lu' => true, 'mois' => $mois, 'ca' => $l['realise'] ?? null, 'budget' => $l['objectif'] ?? null, 'budgetSource' => $l['objectifSource'] ?? null,
        'attendu' => $l['attendu'] ?? null, 'atteinte' => $att !== null ? round(100 * (float) $att, 1) : null, 'ecart' => $l['ecart'] ?? null,
        'tickets' => $l['tickets'] ?? null, 'panier' => $l['panier'] ?? null,
        'pro' => ['part' => $l['partPro'] ?? null, 'ca' => $l['caPro'] ?? null, 'tickets' => $l['ticketsPro'] ?? null, 'panier' => $l['panierPro'] ?? null,
            'joursLus' => $l['proJoursLus'] ?? null, 'jours' => $l['proJours'] ?? null, 'complet' => $l['proComplet'] ?? null, 'max' => FR_PRO_MAX, 'alerte' => FR_PRO_ALERTE],
        'jours' => $jours, 'motif' => null];
}

/* --- Les checklists du magasin, rien que ce que l'API du panel fournit (09/10/2026) ---------------------------
 * Les checklists du jour (/consultant/shops/{id}/checklists), l'avancement de chacune tâche par tâche (…/progress :
 * statut, heure, photo, note, commentaire, validation), leur suivi jour par jour sur la période
 * (…/checklists/progress?from=&to=) et la checklist propre du consultant (/consultant/tasks, cadre opérationnel). */
const FR_CL_JOURS = [1, 7, 30];
function frClFait(array $t): bool
{
    $st = strtoupper(trim((string) ($t['status'] ?? '')));
    return in_array($st, ['DONE', 'COMPLETED', 'COMPLETE', 'FINISHED'], true) || !empty($t['completed_at']) || !empty($t['is_done']);
}
function frClTache(array $t): array
{
    $note = isset($t['review_rating']) && $t['review_rating'] !== null && $t['review_rating'] !== '' && $t['review_rating'] !== 'NULL' ? (int) $t['review_rating'] : null;
    $acc = $t['review_is_accepted'] ?? null;
    $acc = $acc === null || $acc === '' || $acc === 'NULL' ? null : (bool) (int) $acc;
    $txt = static fn ($v) => $v === null || $v === 'NULL' ? '' : trim((string) $v);
    return ['id' => (int) ($t['task_id'] ?? $t['id'] ?? 0), 'ordre' => (int) ($t['sort_order'] ?? 0), 'nom' => $txt($t['name'] ?? $t['task_name'] ?? ''),
        'description' => $txt($t['description'] ?? ''), 'heure' => substr($txt($t['execution_time'] ?? $t['scheduled_time'] ?? ''), 0, 5),
        'obligatoire' => !empty($t['is_mandatory']) && $t['is_mandatory'] !== '0', 'photoRequise' => !empty($t['requires_photo']) && $t['requires_photo'] !== '0',
        'statut' => strtoupper($txt($t['status'] ?? '')), 'fait' => frClFait($t), 'faitLe' => substr($txt($t['completed_at'] ?? ''), 0, 16), 'faitPar' => $txt($t['completed_by'] ?? ''),
        'photo' => (int) ($t['attachment_id'] ?? 0) > 0, 'note' => $note, 'accepte' => $acc, 'commentaire' => $txt($t['review_comment'] ?? $t['note'] ?? ''),
        'notePar' => $txt($t['review_by_name'] ?? ''), 'noteLe' => substr($txt($t['reviewed_at'] ?? ''), 0, 16),
        'valideLe' => substr($txt($t['admin_validated_at'] ?? ''), 0, 16), 'validePar' => $txt($t['admin_validated_by_name'] ?? ''), 'produit' => $txt($t['product_name'] ?? '')];
}
function ep_franchises_checklists(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $mags = viMagasins();
    if ($shop === '' || !isset($mags[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $jours = (int) ($_GET['jours'] ?? 7);
    $jours = in_array($jours, FR_CL_JOURS, true) ? $jours : 7;
    $auj = date('Y-m-d'); $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $out = ['shop' => $shop, 'magasin' => $mags[$shop], 'lu' => false, 'date' => $auj, 'du' => $du, 'au' => $auj, 'jours' => $jours, 'checklists' => [], 'consultant' => null, 'motif' => null, 'source' => 'API du panel'];
    if (!PanelApi::configured()) { $out['motif'] = 'compte consultant du panel non configuré'; return $out; }
    @set_time_limit(90);
    $sid = (int) $shop;
    $r = PanelApi::getParallele(['jour' => '/consultant/shops/' . $sid . '/checklists?date=' . $auj,
        'periode' => '/consultant/shops/' . $sid . '/checklists/progress?' . http_build_query(['from' => $du, 'to' => $auj]),
        'consultant' => '/consultant/tasks?date=' . $auj]);
    $jour = is_array($r['jour'] ?? null) ? PanelApi::liste($r['jour']) : null;
    if ($jour === null) { $out['motif'] = 'le panel n’a pas rendu les checklists du magasin'; return $out; }
    $out['lu'] = true;
    $cls = [];
    foreach ($jour as $c) {
        $cid = (int) ($c['id'] ?? $c['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        $cls[$cid] = ['id' => $cid, 'nom' => trim((string) ($c['name'] ?? $c['checklist_name'] ?? ('Checklist ' . $cid))), 'description' => trim((string) ($c['description'] ?? '')),
            'heure' => substr((string) ($c['execution_time'] ?? ''), 0, 5), 'total' => (int) ($c['tasks_total'] ?? 0), 'faites' => (int) ($c['tasks_done'] ?? 0),
            'taches' => [], 'jours' => [], 'periode' => ['total' => 0, 'faites' => 0, 'pct' => null], 'notees' => 0, 'nc' => 0, 'obligManquees' => 0];
    }
    // L'avancement de chaque checklist du jour : ses tâches une à une.
    $req = [];
    foreach (array_keys($cls) as $cid) { $req[$cid] = '/consultant/shops/' . $sid . '/checklists/' . $cid . '/progress?date=' . $auj; }
    foreach ($req ? PanelApi::getParallele($req) : [] as $cid => $p) {
        if (!is_array($p)) { continue; }
        $ts = array_map('frClTache', PanelApi::liste(is_array($p['tasks'] ?? null) ? $p['tasks'] : $p));
        usort($ts, fn ($a, $b) => [$a['ordre'], $a['heure']] <=> [$b['ordre'], $b['heure']]);
        $cls[$cid]['taches'] = $ts;
        if (isset($p['summary']['total'])) { $cls[$cid]['total'] = (int) $p['summary']['total']; $cls[$cid]['faites'] = (int) ($p['summary']['done'] ?? $cls[$cid]['faites']); }
        foreach ($ts as $t) {
            if ($t['note'] !== null) { $cls[$cid]['notees']++; if ($t['note'] < 4 || $t['accepte'] === false) { $cls[$cid]['nc']++; } }
            if ($t['obligatoire'] && !$t['fait']) { $cls[$cid]['obligManquees']++; }
        }
    }
    // La période : chaque jour, chaque checklist, faites sur le total.
    $per = is_array($r['periode'] ?? null) ? $r['periode'] : [];
    foreach ((array) ($per['days'] ?? []) as $d) {
        if (!is_array($d)) { continue; }
        $dt = substr((string) ($d['date'] ?? $d['day'] ?? ''), 0, 10);
        foreach (PanelApi::liste(is_array($d['checklists'] ?? null) ? $d['checklists'] : []) as $c) {
            $cid = (int) ($c['id'] ?? $c['checklist_id'] ?? 0);
            if ($cid <= 0 || $dt === '') { continue; }
            $tot = (int) ($c['tasks_total'] ?? $c['total'] ?? ($c['summary']['total'] ?? 0));
            $fai = (int) ($c['tasks_done'] ?? $c['done'] ?? ($c['summary']['done'] ?? 0));
            if (!isset($cls[$cid])) {
                $cls[$cid] = ['id' => $cid, 'nom' => trim((string) ($c['name'] ?? $c['checklist_name'] ?? ('Checklist ' . $cid))), 'description' => trim((string) ($c['description'] ?? '')),
                    'heure' => substr((string) ($c['execution_time'] ?? ''), 0, 5), 'total' => 0, 'faites' => 0, 'taches' => [], 'jours' => [], 'periode' => ['total' => 0, 'faites' => 0, 'pct' => null], 'notees' => 0, 'nc' => 0, 'obligManquees' => 0, 'pasAujourdhui' => true];
            }
            $cls[$cid]['jours'][$dt] = ['date' => $dt, 'total' => $tot, 'faites' => $fai];
        }
    }
    foreach ($cls as &$c) {
        ksort($c['jours']); $c['jours'] = array_values($c['jours']);
        foreach ($c['jours'] as $j) { $c['periode']['total'] += $j['total']; $c['periode']['faites'] += $j['faites']; }
        $c['periode']['pct'] = $c['periode']['total'] > 0 ? (int) round(100 * $c['periode']['faites'] / $c['periode']['total']) : null;
    }
    unset($c);
    uasort($cls, fn ($a, $b) => [$a['heure'] === '' ? '99' : $a['heure'], $a['nom']] <=> [$b['heure'] === '' ? '99' : $b['heure'], $b['nom']]);
    $out['checklists'] = array_values($cls);
    // La checklist propre du consultant, au cadre opérationnel de son poste : elle n'est rattachée à aucun magasin.
    $k = is_array($r['consultant'] ?? null) ? $r['consultant'] : null;
    if ($k !== null) {
        $pos = is_array($k['position'] ?? null) ? $k['position'] : [];
        $out['consultant'] = ['poste' => trim((string) ($pos['position_name'] ?? '')), 'niveau' => trim((string) ($pos['level_name'] ?? '')),
            'taches' => array_map(fn ($t) => ['id' => (int) ($t['id'] ?? 0), 'nom' => trim((string) ($t['name'] ?? $t['base_name'] ?? '')),
                'section' => trim((string) ($t['section_name'] ?? $t['base_section_name'] ?? '')), 'categorie' => trim((string) ($t['category_name'] ?? $t['base_category_name'] ?? '')),
                'heure' => substr((string) ($t['execution_time'] ?? ''), 0, 5) === 'NULL' ? '' : substr((string) ($t['execution_time'] ?? ''), 0, 5), 'obligatoire' => !empty($t['is_mandatory']) && $t['is_mandatory'] !== '0',
                'photoRequise' => !empty($t['requires_photo']) && $t['requires_photo'] !== '0', 'fait' => !empty($t['is_done']) && $t['is_done'] !== '0'], PanelApi::liste(is_array($k['tasks'] ?? null) ? $k['tasks'] : []))];
    }
    return $out;
}

/* --- La météo du franchisé : moral, envie, équipe, relation, ses envies, ses demandes, ses inquiétudes ------------ */
const FR_METEO_ECHELLES = ['moral' => 'Moral', 'envie' => 'Envie, motivation', 'equipe' => 'Climat de l’équipe', 'relation' => 'Relation avec le réseau'];
function ensureFrMeteo(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_franchise_meteo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(40) NULL,
        shop_id VARCHAR(12) NOT NULL,
        le DATE NOT NULL,
        consultant_id VARCHAR(32) NOT NULL DEFAULT \'\',
        consultant_nom VARCHAR(120) NOT NULL DEFAULT \'\',
        moral TINYINT NULL,
        envie TINYINT NULL,
        equipe TINYINT NULL,
        relation TINYINT NULL,
        envies TEXT NULL,
        demandes TEXT NULL,
        inquietudes TEXT NULL,
        note TEXT NULL,
        cree_par VARCHAR(120) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        UNIQUE KEY u_client (client_id),
        KEY k_shop (shop_id, le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function frMeteoLigne(array $r, array $taches): array
{
    $dem = json_decode((string) ($r['demandes'] ?? ''), true);
    $dem = is_array($dem) ? $dem : [];
    $out = ['id' => (int) $r['id'], 'le' => (string) $r['le'], 'consultant' => (string) $r['consultant_nom'], 'creePar' => (string) $r['cree_par']];
    foreach (array_keys(FR_METEO_ECHELLES) as $k) { $out[$k] = $r[$k] !== null ? (int) $r[$k] : null; }
    $out['envies'] = (string) ($r['envies'] ?? ''); $out['inquietudes'] = (string) ($r['inquietudes'] ?? ''); $out['note'] = (string) ($r['note'] ?? '');
    $out['demandes'] = array_map(function ($d) use ($taches) {
        $tid = isset($d['tache']) ? (int) $d['tache'] : null; $t = $tid ? ($taches[$tid] ?? null) : null;
        return ['texte' => (string) ($d['texte'] ?? ''), 'tacheId' => $tid, 'statut' => $t ? (string) $t['statut'] : null, 'faitLe' => $t ? $t['fait_le'] : null];
    }, $dem);
    return $out;
}
/** GET /franchises/meteo?shop= — les météos du magasin, la plus récente d'abord, et la tendance du moral. */
function ep_franchises_meteo(): array
{
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $mags = viMagasins();
    if ($shop === '' || !isset($mags[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    ensureFrMeteo();
    $rows = Db::rows('SELECT * FROM ceo_franchise_meteo WHERE shop_id = ? ORDER BY le DESC, id DESC LIMIT 36', [$shop]);
    $ids = [];
    foreach ($rows as $r) { foreach ((array) json_decode((string) ($r['demandes'] ?? ''), true) as $d) { if (is_array($d) && !empty($d['tache'])) { $ids[] = (int) $d['tache']; } } }
    $taches = [];
    if ($ids !== [] && function_exists('ensureVisitesCadre')) {
        try {
            ensureVisitesCadre();
            foreach (Db::rows('SELECT id, statut, fait_le FROM ceo_consultant_tache WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $t) { $taches[(int) $t['id']] = $t; }
        } catch (Throwable $e) { /* les demandes restent, sans leur suite */ }
    }
    $meteos = array_map(fn ($r) => frMeteoLigne($r, $taches), $rows);
    $ouvertes = 0;
    foreach ($meteos as $m) { foreach ($m['demandes'] as $d) { if ($d['statut'] === 'a_faire' || $d['statut'] === 'en_cours') { $ouvertes++; } } }
    $tendance = [];
    foreach (array_reverse(array_slice($meteos, 0, 12)) as $m) { $tendance[] = ['le' => $m['le'], 'moral' => $m['moral'], 'envie' => $m['envie'], 'equipe' => $m['equipe'], 'relation' => $m['relation']]; }
    return ['shop' => $shop, 'magasin' => $mags[$shop], 'lu' => date('Y-m-d H:i'), 'echelles' => FR_METEO_ECHELLES,
        'derniere' => $meteos[0] ?? null, 'meteos' => $meteos, 'tendance' => $tendance, 'demandesOuvertes' => $ouvertes];
}
/** POST /franchises/meteo — une météo ; chaque demande peut devenir une tâche du consultant (échéance J+7). */
function wr_franchises_meteo(): array
{
    ensureFrMeteo();
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if ($shop === '' || !isset(viMagasins()[$shop])) { http_response_code(422); return ['error' => 'magasin requis']; }
    $cid = mb_substr(trim((string) ($b['client_id'] ?? '')), 0, 40) ?: null;
    if ($cid !== null && ($ex = Db::row('SELECT * FROM ceo_franchise_meteo WHERE client_id = ?', [$cid])) !== null) { return ['ok' => true, 'deja' => true, 'meteo' => frMeteoLigne($ex, [])]; }
    $ech = []; $rien = true;
    foreach (array_keys(FR_METEO_ECHELLES) as $k) {
        $v = isset($b[$k]) && is_numeric($b[$k]) ? (int) $b[$k] : null;
        $ech[$k] = $v !== null && $v >= 1 && $v <= 5 ? $v : null;
        if ($ech[$k] !== null) { $rien = false; }
    }
    $txt = static fn ($k) => mb_substr(trim((string) ($b[$k] ?? '')), 0, 4000);
    $demandes = is_array($b['demandes'] ?? null) ? $b['demandes'] : preg_split('/\R/u', (string) ($b['demandes'] ?? ''));
    $demandes = array_values(array_filter(array_map(fn ($d) => mb_substr(trim((string) (is_array($d) ? ($d['texte'] ?? '') : $d)), 0, 300), $demandes), fn ($d) => $d !== ''));
    if ($rien && $demandes === [] && $txt('envies') === '' && $txt('inquietudes') === '' && $txt('note') === '') { http_response_code(422); return ['error' => 'météo vide']; }
    $le = viDate($b['le'] ?? null) ?? date('Y-m-d');
    $cons = mb_substr(trim((string) ($b['consultant'] ?? '')), 0, 32);
    if ($cons === '') { $cons = (string) (consultantIdCompte() ?? ''); }
    $consNom = $cons !== '' ? viConsultantNom($cons) : '';
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_franchise_meteo (client_id, shop_id, le, consultant_id, consultant_nom, moral, envie, equipe, relation, envies, demandes, inquietudes, note, cree_par, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$cid, $shop, $le, $cons, $consNom, $ech['moral'], $ech['envie'], $ech['equipe'], $ech['relation'], $txt('envies') ?: null, '[]', $txt('inquietudes') ?: null, $txt('note') ?: null, viQui($b), $now]);
    $id = (int) Db::pdo()->lastInsertId();
    $dem = []; $creees = 0;
    $enTaches = !array_key_exists('taches', $b) || !empty($b['taches']);
    foreach ($demandes as $n => $d) {
        $tid = null;
        if ($enTaches && $cons !== '' && function_exists('ensureVisitesCadre')) {
            ensureVisitesCadre();
            Db::exec('INSERT INTO ceo_consultant_tache (client_id, consultant_id, consultant_nom, shop_id, visite_id, source, quand, delai, titre, detail, echeance, statut, cree_par, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                ['m' . $id . ':' . $n, $cons, $consNom, $shop, null, 'perso', null, null, mb_substr('Demande du franchisé : ' . $d, 0, 190), 'Météo du ' . $le, date('Y-m-d', strtotime($le . ' +7 days')), 'a_faire', viQui($b), $now, $now]);
            $tid = (int) Db::pdo()->lastInsertId(); $creees++;
        }
        $dem[] = ['texte' => $d, 'tache' => $tid];
    }
    Db::exec('UPDATE ceo_franchise_meteo SET demandes = ? WHERE id = ?', [json_encode($dem, JSON_UNESCAPED_UNICODE), $id]);
    if (function_exists('journalAdd')) { try { journalAdd(viQui($b), 'franchise', null, 'Météo du franchisé ' . (viMagasins()[$shop]['court'] ?? $shop) . ' : moral ' . ($ech['moral'] ?? '—') . ' / 5, ' . count($dem) . ' demande(s)'); } catch (Throwable $e) { /* le journal ne bloque pas */ } }
    $r = Db::row('SELECT * FROM ceo_franchise_meteo WHERE id = ?', [$id]);
    return ['ok' => true, 'meteo' => frMeteoLigne($r, []), 'taches' => $creees];
}
