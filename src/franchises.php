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
function frInvendus(string $shop, int $jours = 7): array
{
    static $caM = null; static $fen = null;
    $au = date('Y-m-d'); $du = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    if (!function_exists('invBilan')) { return ['lu' => false, 'du' => $du, 'au' => $au, 'motif' => 'module absent']; }
    try {
        $b = invBilan((int) $shop, $du, $au, invLignes((int) $shop, $du, $au));
        if ($caM === null || $fen !== $du . $au) { $caM = invCaMagasins($du, $au); $fen = $du . $au; }
    } catch (Throwable $e) { return ['lu' => false, 'du' => $du, 'au' => $au, 'motif' => $e->getMessage()]; }
    if ($b === null) { return ['lu' => false, 'du' => $du, 'au' => $au, 'motif' => 'la poubelle du panel ne se lit pas']; }
    $ca = $caM[(int) $shop] ?? $caM[$shop] ?? null;
    return ['lu' => true, 'du' => $du, 'au' => $au, 'pieces' => $b['pieces'] ?? null, 'cout' => $b['cout'] ?? null, 'caPerdu' => $b['caPerdu'] ?? null, 'references' => $b['references'] ?? 0,
        'ca' => $ca, 'part' => $ca !== null && (float) $ca > 0 ? round(100 * (float) ($b['cout'] ?? 0) / (float) $ca, 1) : null,
        'parMotif' => array_slice($b['parMotif'] ?? [], 0, 4), 'produits' => array_slice($b['produits'] ?? [], 0, 5)];
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
        'journalier' => ['tachesJour' => frTachesJour($shop), 'taches' => frTachesJours($shop), 'invendus' => frInvendus($shop), 'objectifs' => frObjectifs($shop),
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
        $t = frTachesJours($sid); $inv = frInvendus($sid); $msp = frMsp($sid); $s = $scPar[$sid] ?? null;
        $cadre = function_exists('vcCadreDe') ? vcCadreDe($sid) : [];
        $resp = ''; foreach ($cadre as $l) { if ($l['consultant'] !== '' && $resp === '') { $resp = $l['consultantNom']; } }
        $out[] = ['id' => $sid, 'nom' => $b['nom'], 'court' => $b['court'], 'fr' => $b['fr'], 'feu' => $b['feu'], 'motifs' => $b['motifs'], 'due' => $b['due'], 'responsable' => $resp,
            'journalier' => ['scoring' => $s ? ['total' => $s['total'], 'etoiles' => $s['etoiles'], 'rang' => $s['rang'], 'n' => $s['n'], 'sur' => $s['sur']] : null,
                'taches' => ['pct' => $t['pct'], 'joursObligManques' => $t['joursObligManques'], 'motif' => $t['motif']],
                'invendus' => ['lu' => $inv['lu'], 'part' => $inv['part'] ?? null, 'pieces' => $inv['pieces'] ?? null],
                'google' => $b['google'] ? ['note' => $b['google']['note'], 'avis' => $b['google']['avis'], 'faibles' => $b['google']['faibles']] : null,
                'ca' => $b['ca'] ? ['ca' => $b['ca']['ca'], 'pct' => $b['ca']['pct']] : null],
            'terrain' => ['derniereVisite' => $b['derniereVisite'], 'prochaineVisite' => $b['prochaineVisite'], 'plansOuverts' => $b['plansOuverts'], 'p0' => $b['p0'], 'plano' => $b['plano'],
                'msp' => $msp ? ['trimestre' => $msp['trimestre'], 'obtenu' => $msp['obtenu'], 'maximum' => $msp['maximum'], 'v' => $msp['v']] : null, 'cadre' => count($cadre)]];
    }
    return ['magasins' => $out, 'trimestre' => $sc['trimestre'] ?? null, 'lu' => date('Y-m-d H:i')];
}
