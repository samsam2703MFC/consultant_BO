<?php
declare(strict_types=1);

/**
 * PARCOURS COMPÉTENCES (31/10/2026) — pour chaque personne (employé du panel
 * ou candidat franchisé), le chemin vers le poste qu'elle vise, et son suivi.
 *
 * Page : public/formation/parcours/ (mobile d'abord : elle rejoindra
 * l'application employé ; pour l'instant, une entrée dans le rail du cockpit).
 * Maquettes validées : docs/maquettes/parcours-competences/.
 *
 * RÈGLE : LES DONNÉES DES API ONT PRIORITÉ. Ce que vise la personne, ce qu'elle
 * sait, ce que le niveau exige — tout vient du panel : postes et niveaux,
 * compétences du niveau, compétences de la personne, évaluations « [FO-xx] … ».
 * Le référentiel v25 (src/data/referentiel_v25.json, tiré de l'Excel) ne fait
 * que compléter ce que le panel n'a pas : le nom de la formation FO-xx, la
 * matrice obligatoire / recommandée, l'ordre des plans, les postes que le
 * panel n'a pas encore (Locataire-gérant, Franchisé multi-boutique).
 *
 * Simple, sans doublon :
 *   - un parcours = un objectif (un niveau) + des formations dans l'ordre ;
 *   - une formation = ses évaluations (celles du panel, sinon cinq génériques) ;
 *   - valider la dernière évaluation valide la formation ET coche dans le panel
 *     les compétences qui y sont rattachées : une seule action.
 */

const PC_SEMAINES = ['tranquille' => 4.3, 'normal' => 3.0, 'rapide' => 2.0];
const PC_EVALS = ['Théorie', 'Pratique', 'Mise en situation', 'Validation', 'Re-évaluation J+30'];

function ensureParcours(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_parcours ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'employe_id INT NULL,'                 // la personne dans le panel (null : candidat hors panel)
        . 'shop_id INT NULL,'
        . 'nom VARCHAR(120) NOT NULL,'
        . 'profil VARCHAR(12) NOT NULL,'         // employe | candidat
        . 'depart VARCHAR(160) NULL,'            // le niveau tenu au départ, en clair
        . 'cible_cle VARCHAR(40) NOT NULL,'      // n:<id niveau panel> | v25:P9
        . 'cible_nom VARCHAR(160) NOT NULL,'
        . 'rythme VARCHAR(12) NOT NULL,'
        . 'debut DATE NOT NULL,'
        . 'acquis_declares TEXT NULL,'           // JSON : compétences cochées à l'étape 2, à confirmer
        . "statut VARCHAR(10) NOT NULL DEFAULT 'actif',"   // actif | atteint | arrete
        . 'cree_le DATETIME NOT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'KEY (employe_id), KEY (shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_parcours_etape ('
        . 'parcours_id INT NOT NULL,'
        . 'ordre INT NOT NULL,'
        . 'fo VARCHAR(8) NOT NULL,'
        . 'nom VARCHAR(200) NOT NULL,'
        . 'evaluations TEXT NOT NULL,'           // JSON : [{nom, fait, le}]
        . 'prevue DATE NULL,'
        . "statut VARCHAR(10) NOT NULL DEFAULT 'avenir',"  // avenir | encours | validee
        . 'validee_le DATETIME NULL,'
        . "origine VARCHAR(10) NOT NULL DEFAULT 'parcours',"  // parcours | panel (déjà acquise) | declaree (cochée à l'étape 2)
        . 'PRIMARY KEY (parcours_id, fo)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le référentiel v25 (l'Excel), tel que tools/formation/referentiel_excel.py l'a écrit. */
function pcV25(): array
{
    static $v = null;
    if ($v === null) {
        $f = __DIR__ . '/data/referentiel_v25.json';
        $v = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $v += ['postes' => [], 'formations' => [], 'plans' => [], 'competences' => []];
    }
    return $v;
}

/**
 * Une lecture du module Formation, prise dans son cache (celui de l'écran) quand
 * il est frais, sinon refaite et remise en cache : le parcours et le module
 * lisent le même référentiel, une seule fois.
 */
function pcLecture(string $route, callable $calcul, int $ttl): ?array
{
    $k = fmCleCache($route);
    $c = setting($k);
    if (is_array($c) && is_array($c['r'] ?? null) && time() - (int) ($c['le'] ?? 0) < max($ttl, (int) ($c['ttl'] ?? 0))) { return $c['r']; }
    $r = $calcul();
    if (!is_array($r) || isset($r['error'])) { return null; }
    $j = json_encode(['le' => time(), 'ttl' => $ttl, 'r' => $r], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($j)) { try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $j]); } catch (Throwable $e) { /* sans cache */ } }
    return $r;
}

/** Le référentiel du panel et les liens des compétences — ou une erreur à rendre telle quelle. */
function pcPanel(): array
{
    $ref = pcLecture('/formation/referentiel', 'ep_formation_referentiel', 600);
    if ($ref === null) { http_response_code(502); return ['erreur' => ['error' => 'référentiel du panel illisible', 'detail' => PanelApi::$lastError]]; }
    $liens = pcLecture('/formation/liens', 'ep_formation_liens', 900);
    // Calculés sur le moment, les liens sont un objet (pour sortir en {} en JSON) ; relus du cache, un tableau.
    $l = $liens['liens'] ?? [];
    if (is_object($l)) { $l = get_object_vars($l); }
    return ['ref' => $ref, 'liens' => is_array($l) ? $l : []];
}

/** Le code FO d'une évaluation du panel (« [FO-01] Évaluation théorique vente » → FO-01). */
function pcCodeFo(string $nom): ?string
{
    return preg_match('/^\s*\[(FO-\d+)\]/', $nom, $m) ? $m[1] : null;
}

/**
 * Le code v25 (P1…P12) d'un niveau du panel. Les noms du panel font foi ; la
 * correspondance passe par eux : « Responsable d'équipe » d'un poste
 * d'opérateur = son chef de section (P4, P5, P6). Null : un rôle du réseau
 * (consultant, contrôleur…) que la matrice ne couvre pas.
 */
function pcCodeV25(string $poste, string $niveau): ?string
{
    $p = mb_strtolower($poste); $n = mb_strtolower($niveau);
    // Les rôles du réseau (franchiseur, consultants, contrôle) ne sont pas des postes de la matrice —
    // mesuré sur le panel : « Interface Franchiseur–Franchisés » passait pour un franchisé.
    if (preg_match('/interface|consultant|contr[ôo]leur|direction|client myst|strat[ée]gie|d[ée]veloppeur|tech/u', $p)) { return null; }
    $chef = str_contains($n, 'responsable');
    if (str_contains($p, 'boulang') || str_contains($p, 'pâtiss')) { return $chef ? 'P5' : 'P1'; }
    if (str_contains($p, 'traiteur')) { return $chef ? 'P6' : 'P2'; }
    if (str_contains($p, 'vente') || str_contains($p, 'vend')) { return $chef ? 'P4' : 'P3'; }
    if (str_contains($p, 'adjoint')) { return 'P7'; }
    if (str_contains($p, 'candidat')) { return 'P10'; }
    if (str_contains($p, 'multi')) { return 'P12'; }
    if (str_contains($p, 'locataire')) { return 'P9'; }
    if (preg_match('/^franchis/u', $p)) { return 'P11'; }
    if (str_contains($p, 'manager')) { return 'P8'; }
    return null;
}

/** Les évaluations « [FO-xx] … » du panel, par code FO, dans l'ordre de leur id. */
function pcEvaluations(array $ref): array
{
    $o = [];
    foreach ($ref['formations'] ?? [] as $t) {
        $fo = pcCodeFo((string) $t['nom']);
        if ($fo === null) { continue; }
        $o[$fo][] = ['id' => (int) $t['id'], 'nom' => trim((string) preg_replace('/^\s*\[FO-\d+\]\s*/', '', (string) $t['nom']))];
    }
    foreach ($o as &$l) { usort($l, static fn ($a, $b) => $a['id'] <=> $b['id']); }
    unset($l);
    return $o;
}

/** Pour chaque FO, les compétences du panel rattachées à ses évaluations. */
function pcCompetencesParFo(array $ref, array $liens): array
{
    $foDe = [];
    foreach ($ref['formations'] ?? [] as $t) { if (($fo = pcCodeFo((string) $t['nom'])) !== null) { $foDe[(int) $t['id']] = $fo; } }
    $o = [];
    foreach ($liens as $cid => $l) {
        foreach ($l['formations'] ?? [] as $f) {
            if (isset($foDe[(int) $f['id']])) { $o[$foDe[(int) $f['id']]][(int) $cid] = true; }
        }
    }
    return array_map('array_keys', $o);
}

/** Toutes les destinations possibles : les niveaux du panel (priorité), plus les postes v25 que le panel n'a pas. */
function pcDestinations(array $ref): array
{
    $o = [];
    foreach ($ref['postes'] ?? [] as $p) {
        $plusieurs = count($p['niveaux'] ?? []) > 1;
        foreach ($p['niveaux'] ?? [] as $n) {
            $code = pcCodeV25((string) $p['nom'], (string) $n['nom']);
            if ($code === null || $p['id'] === null) { continue; }
            $o['n:' . $n['id']] = ['cle' => 'n:' . $n['id'], 'niveauId' => (int) $n['id'], 'posteId' => (int) $p['id'], 'v25' => $code,
                'poste' => (string) $p['nom'], 'niveau' => (string) $n['nom'], 'ordre' => (int) ($n['ordre'] ?? 0),
                'titre' => $plusieurs ? (string) $n['nom'] : (string) $p['nom'], 'sous' => $plusieurs ? (string) $p['nom'] : null,
                'competences' => array_values(array_map('intval', $n['competences'] ?? [])), 'source' => 'panel'];
        }
    }
    $presents = array_column($o, 'v25');
    foreach (pcV25()['postes'] as $p) {
        if (in_array($p['code'], $presents, true)) { continue; }
        $nom = ['P9' => 'Locataire-gérant', 'P12' => 'Franchisé multi-boutique'][$p['code']] ?? (string) $p['nom'];
        $o['v25:' . $p['code']] = ['cle' => 'v25:' . $p['code'], 'niveauId' => null, 'posteId' => null, 'v25' => $p['code'],
            'poste' => $nom, 'niveau' => $nom, 'ordre' => 0, 'titre' => $nom, 'sous' => null, 'competences' => [], 'source' => 'v25'];
    }
    return $o;
}

/** Le groupe d'une destination dans l'assistant. */
function pcGroupe(string $code, ?string $metier): string
{
    if (in_array($code, ['P10', 'P11', 'P9', 'P12'], true)) { return 'entrepreneur'; }
    if (in_array($code, ['P7', 'P8'], true)) { return 'encadrer'; }
    // Le même métier : l'opérateur et son chef de section (le niveau « Responsable d'équipe »).
    $paires = ['P1' => 'P5', 'P5' => 'P1', 'P2' => 'P6', 'P6' => 'P2', 'P3' => 'P4', 'P4' => 'P3'];
    return $metier !== null && ($code === $metier || ($paires[$metier] ?? null) === $code) ? 'metier' : 'autre';
}

/** Le plan de formation qui donne l'ordre (référentiel v25). */
function pcPlan(string $code, string $niveauActuel): ?array
{
    $plans = [];
    foreach (pcV25()['plans'] as $p) { $plans[$p['code']] = $p; }
    $cle = match (true) {
        in_array($code, ['P3', 'P4'], true) => str_contains(mb_strtolower($niveauActuel), 'etudiant') ? 'PF-ETU' : 'PF-VEND',
        $code === 'P12' => 'PF-MULTI',
        in_array($code, ['P7', 'P8', 'P9', 'P10', 'P11'], true) => 'PF-GER',
        default => null,
    };
    return $cle !== null ? ($plans[$cle] ?? null) : null;
}

/**
 * Les formations d'une destination, pour une personne : `[requis, facultatif]`.
 *   requis    = formations que le PANEL rattache aux compétences du niveau
 *               (priorité) + les obligatoires de la matrice v25 ;
 *   facultatif = les recommandées v25 qui ne sont pas déjà requises.
 * `acquises` : celles dont la personne a déjà toutes les compétences du panel, ou
 * déjà validées dans un parcours — gardées, en tête : c'est le passé de la frise.
 * Les autres dans l'ordre du plan.
 */
function pcFormations(array $dest, array $ctx, array $tenues, array $faites, string $niveauActuel): array
{
    $v25 = [];
    foreach (pcV25()['formations'] as $f) { $v25[$f['code']] = $f; }
    $api = [];
    foreach ($dest['competences'] as $cid) {
        foreach ($ctx['liens'][(string) $cid]['formations'] ?? [] as $f) {
            foreach ($ctx['evalsParId'][(int) $f['id']] ?? [] as $fo) { $api[$fo] = true; }
        }
    }
    $o = []; $r = [];
    foreach ($v25 as $code => $f) {
        $a = $f['attendu'][$dest['v25']] ?? '';
        if ($a === 'O') { $o[$code] = true; } elseif ($a === 'R') { $r[$code] = true; }
    }
    $requis = array_keys($api + $o);
    $facultatif = array_values(array_diff(array_keys($r), $requis));
    $acquise = static function (string $fo) use ($ctx, $tenues, $faites): bool {
        if (in_array($fo, $faites, true)) { return true; }
        $c = $ctx['compParFo'][$fo] ?? [];
        return $c !== [] && !array_diff($c, $tenues);
    };
    $acquises = array_values(array_filter($requis, $acquise));
    $facultatif = array_values(array_filter($facultatif, static fn ($fo) => !$acquise($fo)));
    $rang = [];
    $plan = pcPlan($dest['v25'], $niveauActuel);
    foreach ($plan['blocs'] ?? [] as $b) { foreach ($b['formations'] as $fo) { $rang[$fo] ??= count($rang); } }
    $tri = static fn ($a, $b) => [$rang[$a] ?? 999, (int) substr($a, 3)] <=> [$rang[$b] ?? 999, (int) substr($b, 3)];
    usort($requis, $tri);
    usort($facultatif, $tri);
    // Celles déjà acquises d'abord : elles sont le passé de la frise.
    usort($requis, static fn ($a, $b) => (int) !in_array($a, $acquises, true) <=> (int) !in_array($b, $acquises, true));
    return [$requis, $facultatif, $plan['nom'] ?? null, $acquises];
}

/** Le contexte commun : panel, évaluations, compétences par FO. */
function pcContexte(): array
{
    $p = pcPanel();
    if (isset($p['erreur'])) { return $p; }
    $evals = pcEvaluations($p['ref']);
    $parId = [];
    foreach ($evals as $fo => $l) { foreach ($l as $x) { $parId[$x['id']][] = $fo; } }
    $comp = [];
    foreach ($p['ref']['competences'] ?? [] as $c) { $comp[(int) $c['id']] = (string) $c['nom']; }
    return $p + ['evals' => $evals, 'evalsParId' => $parId, 'compParFo' => pcCompetencesParFo($p['ref'], $p['liens']), 'compNom' => $comp];
}

/** Le nom de la formation : celui du référentiel v25, sinon ce que le panel en dit. */
function pcNomFo(string $fo, array $ctx): string
{
    foreach (pcV25()['formations'] as $f) { if ($f['code'] === $fo) { return (string) $f['nom']; } }
    return ($ctx['evals'][$fo][0]['nom'] ?? null) !== null ? 'Formation ' . $fo : $fo;
}

/** Ce qu'une formation montre dans l'assistant et la frise. */
function pcFicheFo(string $fo, array $ctx): array
{
    $v = null;
    foreach (pcV25()['formations'] as $f) { if ($f['code'] === $fo) { $v = $f; break; } }
    return ['fo' => $fo, 'nom' => pcNomFo($fo, $ctx), 'priorites' => $v['priorites'] ?? [], 'frequence' => $v['frequence'] ?? null,
        'evaluations' => count($ctx['evals'][$fo] ?? []) ?: count(PC_EVALS),
        'competences' => count($ctx['compParFo'][$fo] ?? [])];
}

/** La personne vue par le panel : nom, niveau tenu (le plus haut), compétences. */
function pcPersonne(?int $eid, ?int $shop, array $ref): array
{
    if ($eid === null) { return ['id' => null, 'postes' => [], 'competences' => [], 'niveau' => null]; }
    [$f] = fmRelireEmploye($eid);
    if ($f === null) { return ['id' => $eid, 'postes' => [], 'competences' => [], 'niveau' => null, 'illisible' => true]; }
    $tenu = null;
    foreach ($f['postes'] as $p) {
        $code = pcCodeV25((string) ($p['nom'] ?? ''), (string) ($p['niveau'] ?? ''));
        if ($code !== null && ($tenu === null || ($p['ordre'] ?? 0) > ($tenu['ordre'] ?? 0))) { $tenu = $p + ['v25' => $code]; }
    }
    return $f + ['niveau' => $tenu, 'nomAffiche' => fmNomEmploye($shop, $eid)];
}

/**
 * GET /parcours/destinations?employe=&shop= (ou sans employé : un candidat).
 * Pour l'assistant : où la personne en est, et chaque destination avec ce
 * qu'elle demande — compétences du niveau (panel), formations à suivre.
 */
function ep_parcours_destinations(): array
{
    if ($e = fmPret()) { return $e; }
    ensureParcours();
    @set_time_limit(120);
    $ctx = pcContexte();
    if (isset($ctx['erreur'])) { return $ctx['erreur']; }
    $eid = fmId('employe'); $shop = fmId('shop');
    $pers = pcPersonne($eid, $shop, $ctx['ref']);
    $faites = $eid !== null ? array_column(Db::rows("SELECT e.fo FROM ceo_parcours_etape e JOIN ceo_parcours p ON p.id = e.parcours_id WHERE p.employe_id = ? AND e.statut = 'validee'", [$eid]), 'fo') : [];
    $tenues = $pers['competences'];
    $metier = $pers['niveau']['v25'] ?? null;
    $niveauActuel = (string) ($pers['niveau']['niveau'] ?? '');
    $out = [];
    foreach (pcDestinations($ctx['ref']) as $d) {
        // Pas de destination « en dessous » : un niveau inférieur du même poste, ou le niveau tenu.
        if ($pers['niveau'] !== null && $d['posteId'] !== null && $d['posteId'] === ($pers['niveau']['id'] ?? null) && $d['ordre'] <= (int) ($pers['niveau']['ordre'] ?? 0)) { continue; }
        $groupe = pcGroupe($d['v25'], $metier);
        if ($eid === null && $groupe !== 'entrepreneur') { continue; }   // un candidat vise la franchise
        if ($groupe === 'autre') { continue; }
        [$requis, $facultatif, , $acquises] = pcFormations($d, $ctx, $tenues, $faites, $niveauActuel);
        $out[] = ['cle' => $d['cle'], 'titre' => $d['titre'], 'sous' => $d['sous'], 'groupe' => $groupe, 'source' => $d['source'],
            'competences' => array_map(static fn ($cid) => ['id' => $cid, 'nom' => $ctx['compNom'][$cid] ?? ('#' . $cid), 'acquise' => in_array($cid, $tenues, true)], $d['competences']),
            'formations' => array_map(static fn ($fo) => pcFicheFo($fo, $ctx) + ['acquise' => in_array($fo, $acquises, true)], $requis),
            'aFaire' => count($requis) - count($acquises), 'facultatives' => count($facultatif)];
    }
    $ordre = ['metier' => 0, 'encadrer' => 1, 'entrepreneur' => 2];
    usort($out, static fn ($a, $b) => [$ordre[$a['groupe']], $a['aFaire']] <=> [$ordre[$b['groupe']], $b['aFaire']]);
    $actif = $eid !== null ? Db::row("SELECT id FROM ceo_parcours WHERE employe_id = ? AND statut = 'actif' ORDER BY id DESC LIMIT 1", [$eid]) : null;
    return ['personne' => ['id' => $eid, 'nom' => $pers['nom'] ?? ($pers['nomAffiche'] ?? null), 'niveau' => $pers['niveau'] ? ($pers['niveau']['nom'] . ' · ' . $pers['niveau']['niveau']) : null,
        'formationsValidees' => count($faites)], 'destinations' => $out, 'parcoursActif' => $actif ? (int) $actif['id'] : null,
        'rythmes' => array_map(static fn ($s) => (float) $s, PC_SEMAINES)];
}

/** Les dates prévues des étapes non validées, au rythme choisi, à partir d'aujourd'hui (ou du début). */
function pcPlanifier(int $pid): void
{
    $p = Db::row('SELECT rythme, debut FROM ceo_parcours WHERE id = ?', [$pid]);
    if ($p === null) { return; }
    $sem = PC_SEMAINES[$p['rythme']] ?? 3.0;
    $base = max(strtotime((string) $p['debut']), strtotime(date('Y-m-d')));
    $i = 0;
    $premiere = true;
    foreach (Db::rows("SELECT fo, statut FROM ceo_parcours_etape WHERE parcours_id = ? ORDER BY ordre", [$pid]) as $e) {
        if ($e['statut'] === 'validee') { continue; }
        Db::exec('UPDATE ceo_parcours_etape SET prevue = ?, statut = ? WHERE parcours_id = ? AND fo = ?',
            [date('Y-m-d', (int) ($base + round($i * $sem * 7) * 86400)), $premiere ? 'encours' : 'avenir', $pid, $e['fo']]);
        $premiere = false;
        $i++;
    }
}

/**
 * POST /parcours {employe?, shop?, nom?, cible, rythme, acquis[]} — l'assistant
 * a fini : le parcours est créé, ses formations datées. Un parcours actif de la
 * même personne est arrêté (on ne vise qu'un objectif à la fois).
 */
function wr_parcours_creer(): array
{
    if ($e = fmPret()) { return $e; }
    ensureParcours();
    @set_time_limit(120);
    $b = fmCorps();
    $eid = fmIdCorps($b, 'employe'); $shop = fmIdCorps($b, 'shop');
    $rythme = isset(PC_SEMAINES[$b['rythme'] ?? '']) ? (string) $b['rythme'] : 'normal';
    $cle = (string) ($b['cible'] ?? '');
    $ctx = pcContexte();
    if (isset($ctx['erreur'])) { return $ctx['erreur']; }
    $dests = pcDestinations($ctx['ref']);
    if (!isset($dests[$cle])) { return fmRefus(422, 'destination inconnue'); }
    $d = $dests[$cle];
    $pers = pcPersonne($eid, $shop, $ctx['ref']);
    $nom = $eid !== null ? (string) ($pers['nom'] ?? ('Employé ' . $eid)) : trim(mb_substr((string) ($b['nom'] ?? ''), 0, 120));
    if ($nom === '') { return fmRefus(422, 'le nom du candidat est obligatoire'); }
    $faites = $eid !== null ? array_column(Db::rows("SELECT e.fo FROM ceo_parcours_etape e JOIN ceo_parcours p ON p.id = e.parcours_id WHERE p.employe_id = ? AND e.statut = 'validee'", [$eid]), 'fo') : [];
    [$requis, , , $acquises] = pcFormations($d, $ctx, $pers['competences'], $faites, (string) ($pers['niveau']['niveau'] ?? ''));
    $suivies = array_values(array_intersect(is_array($b['suivies'] ?? null) ? array_map('strval', $b['suivies']) : [], $requis));
    // L'ordre de la frise : le déjà fait d'abord (acquis, puis coché), ensuite le reste dans l'ordre du plan.
    $rangDe = static fn ($fo) => in_array($fo, $acquises, true) ? 0 : (in_array($fo, $suivies, true) ? 1 : 2);
    $pos = array_flip($requis);
    usort($requis, static fn ($a, $b) => [$rangDe($a), $pos[$a]] <=> [$rangDe($b), $pos[$b]]);
    $acquis = array_values(array_intersect(array_map('intval', is_array($b['acquis'] ?? null) ? $b['acquis'] : []), $d['competences']));
    if ($eid !== null) {
        Db::exec("UPDATE ceo_parcours SET statut = 'arrete', maj_le = NOW() WHERE employe_id = ? AND statut = 'actif'", [$eid]);
    }
    $depart = $pers['niveau'] ? $pers['niveau']['nom'] . ' · ' . $pers['niveau']['niveau'] : ($eid === null ? 'Candidat franchisé' : null);
    Db::exec('INSERT INTO ceo_parcours (employe_id, shop_id, nom, profil, depart, cible_cle, cible_nom, rythme, debut, acquis_declares, statut, cree_le, maj_le)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, \'actif\', NOW(), NOW())',
        [$eid, $shop, $nom, $eid === null ? 'candidat' : 'employe', $depart, $cle, $d['titre'] . ($d['sous'] ? ' · ' . $d['sous'] : ''), $rythme, json_encode($acquis)]);
    $pid = (int) Db::row('SELECT LAST_INSERT_ID() AS id')['id'];
    foreach ($requis as $i => $fo) {
        $ev = array_map(static fn ($x) => ['nom' => $x['nom'], 'fait' => false, 'le' => null], $ctx['evals'][$fo] ?? []);
        if (!$ev) { $ev = array_map(static fn ($n) => ['nom' => $n, 'fait' => false, 'le' => null], PC_EVALS); }
        $origine = in_array($fo, $acquises, true) ? 'panel' : (in_array($fo, $suivies, true) ? 'declaree' : 'parcours');
        Db::exec('INSERT INTO ceo_parcours_etape (parcours_id, ordre, fo, nom, evaluations, statut, validee_le, origine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $i, $fo, mb_substr(pcNomFo($fo, $ctx), 0, 200), json_encode($ev, JSON_UNESCAPED_UNICODE),
             $origine === 'parcours' ? 'avenir' : 'validee', $origine === 'parcours' ? null : date('Y-m-d H:i:s'), $origine]);
    }
    pcPlanifier($pid);
    pcJournal($nom, 'Parcours créé vers « ' . $d['titre'] . ' » : ' . (count($requis) - count($acquises) - count($suivies)) . ' formation(s) à suivre, '
        . (count($acquises) + count($suivies)) . ' déjà faite(s), rythme ' . $rythme);
    return ep_parcours_un($pid);
}

function pcJournal(string $qui, string $message): void
{
    if (!function_exists('journalAdd')) { return; }
    try { journalAdd('CEO', 'Parcours', $qui, $message); } catch (Throwable $e) { /* le parcours est écrit */ }
}

/** Un parcours et ses étapes, prêts pour la frise. */
function ep_parcours_un(int $pid): array
{
    ensureParcours();
    $p = Db::row('SELECT * FROM ceo_parcours WHERE id = ?', [$pid]);
    if ($p === null) { http_response_code(404); return ['error' => 'parcours inconnu']; }
    $etapes = [];
    foreach (Db::rows('SELECT * FROM ceo_parcours_etape WHERE parcours_id = ? ORDER BY ordre', [$pid]) as $e) {
        $ev = json_decode((string) $e['evaluations'], true) ?: [];
        $etapes[] = ['fo' => $e['fo'], 'nom' => $e['nom'], 'statut' => $e['statut'], 'origine' => $e['origine'] ?? 'parcours', 'prevue' => $e['prevue'], 'valideeLe' => $e['validee_le'],
            'evaluations' => $ev, 'faites' => count(array_filter($ev, static fn ($x) => !empty($x['fait'])))];
    }
    $v = count(array_filter($etapes, static fn ($x) => $x['statut'] === 'validee'));
    $retard = false;
    foreach ($etapes as $x) { if ($x['statut'] === 'encours' && $x['prevue'] !== null && strtotime($x['prevue'] . ' +14 days') < time()) { $retard = true; } }
    $fin = $etapes ? end($etapes)['prevue'] : null;
    return ['parcours' => ['id' => (int) $p['id'], 'employe' => $p['employe_id'] !== null ? (int) $p['employe_id'] : null, 'shop' => $p['shop_id'] !== null ? (int) $p['shop_id'] : null,
        'nom' => $p['nom'], 'profil' => $p['profil'], 'depart' => $p['depart'], 'cible' => $p['cible_cle'], 'cibleNom' => $p['cible_nom'],
        'rythme' => $p['rythme'], 'debut' => $p['debut'], 'statut' => $p['statut'], 'acquisDeclares' => pcNomsCompetences(json_decode((string) $p['acquis_declares'], true) ?: []),
        'validees' => $v, 'total' => count($etapes), 'retard' => $retard, 'objectif' => $fin], 'etapes' => $etapes];
}

/** Les noms des compétences (lus dans le référentiel en cache), pour l'écran. */
function pcNomsCompetences(array $ids): array
{
    if (!$ids) { return []; }
    $c = setting(fmCleCache('/formation/referentiel'));
    $noms = [];
    foreach (is_array($c) && is_array($c['r'] ?? null) ? ($c['r']['competences'] ?? []) : [] as $x) { $noms[(int) $x['id']] = (string) $x['nom']; }
    return array_map(static fn ($id) => ['id' => (int) $id, 'nom' => $noms[(int) $id] ?? ('Compétence #' . $id)], $ids);
}

/** GET /parcours?id= ou GET /parcours/liste?shop= — un parcours, ou la liste des parcours actifs. */
function ep_parcours(): array
{
    ensureParcours();
    if (($id = fmId()) !== null) { return ep_parcours_un($id); }
    $shop = fmId('shop');
    $tous = !empty($_GET['tous']);
    $rows = Db::rows('SELECT id FROM ceo_parcours WHERE ' . ($tous ? '1' : "statut IN ('actif','atteint')") . ($shop !== null ? ' AND shop_id = ?' : '') . ' ORDER BY maj_le DESC LIMIT 300',
        $shop !== null ? [$shop] : []);
    $out = [];
    foreach ($rows as $r) {
        $u = ep_parcours_un((int) $r['id']);
        $p = $u['parcours'];
        $en = array_values(array_filter($u['etapes'], static fn ($x) => $x['statut'] === 'encours'))[0] ?? null;
        $out[] = $p + ['frise' => array_map(static fn ($x) => $x['statut'], $u['etapes']), 'enCours' => $en ? ['fo' => $en['fo'], 'nom' => $en['nom'], 'faites' => $en['faites'], 'evaluations' => count($en['evaluations']), 'prevue' => $en['prevue']] : null];
    }
    return ['parcours' => $out];
}

/**
 * PATCH /parcours/{id}/etape/{fo} {evaluation, fait} — cocher une évaluation.
 * La dernière cochée valide la formation : étape « validée », la suivante
 * passe « en cours », et dans le panel les compétences rattachées à la
 * formation sont cochées pour la personne (relues ensuite).
 */
function wr_parcours_evaluation(int $pid, string $fo): array
{
    ensureParcours();
    $b = fmCorps();
    $i = isset($b['evaluation']) && is_numeric($b['evaluation']) ? (int) $b['evaluation'] : -1;
    $fait = !empty($b['fait']);
    $p = Db::row('SELECT * FROM ceo_parcours WHERE id = ?', [$pid]);
    $e = Db::row('SELECT * FROM ceo_parcours_etape WHERE parcours_id = ? AND fo = ?', [$pid, $fo]);
    if ($p === null || $e === null) { return fmRefus(404, 'étape inconnue'); }
    if ($p['statut'] !== 'actif') { return fmRefus(409, 'parcours terminé ou arrêté'); }
    $ev = json_decode((string) $e['evaluations'], true) ?: [];
    if (!isset($ev[$i])) { return fmRefus(422, 'évaluation inconnue'); }
    $ev[$i]['fait'] = $fait;
    $ev[$i]['le'] = $fait ? date('Y-m-d') : null;
    $tout = !array_filter($ev, static fn ($x) => empty($x['fait']));
    $etaitValidee = $e['statut'] === 'validee';
    Db::exec('UPDATE ceo_parcours_etape SET evaluations = ?, statut = ?, validee_le = ? WHERE parcours_id = ? AND fo = ?',
        [json_encode($ev, JSON_UNESCAPED_UNICODE), $tout ? 'validee' : ($etaitValidee ? 'encours' : $e['statut']), $tout ? date('Y-m-d H:i:s') : null, $pid, $fo]);
    if ($e['statut'] === 'avenir' && !$tout) {
        // On a commencé une formation à venir : elle passe en cours, sans toucher aux autres.
        Db::exec("UPDATE ceo_parcours_etape SET statut = 'encours' WHERE parcours_id = ? AND fo = ?", [$pid, $fo]);
    }
    $panel = null;
    if ($tout && !$etaitValidee) {
        pcJournal((string) $p['nom'], 'Formation validée : ' . $e['nom'] . ' (' . $fo . ')');
        if ($p['employe_id'] !== null) { $panel = pcCocherCompetences((int) $p['employe_id'], $p['shop_id'] !== null ? (int) $p['shop_id'] : null, $fo); }
        pcPlanifier($pid);
    } elseif (!$tout && $etaitValidee) {
        pcPlanifier($pid);
    }
    Db::exec('UPDATE ceo_parcours SET maj_le = NOW() WHERE id = ?', [$pid]);
    return ep_parcours_un($pid) + ['panel' => $panel];
}

/** Dans le panel, les compétences rattachées à une formation, pour la personne. Relu. */
function pcCocherCompetences(int $eid, ?int $shop, string $fo): array
{
    if (fmPret() !== null) { http_response_code(200); return ['ok' => false, 'raison' => 'compte panel non configuré']; }
    $ctx = pcContexte();
    if (isset($ctx['erreur'])) { http_response_code(200); return ['ok' => false, 'raison' => 'référentiel illisible']; }
    $voulues = $ctx['compParFo'][$fo] ?? [];
    if (!$voulues) { return ['ok' => true, 'cochees' => 0, 'aucune' => true]; }
    [$avant] = fmRelireEmploye($eid);
    if ($avant === null) { return ['ok' => false, 'raison' => 'personne illisible dans le panel']; }
    $manque = array_values(array_diff($voulues, $avant['competences']));
    foreach ($manque as $cid) { PanelApi::requete('PUT', '/employees/' . $eid . '/competencies', ['competency_id' => $cid]); }
    [$apres] = fmRelireEmploye($eid);
    $ok = $apres !== null && !array_diff($voulues, $apres['competences']);
    if ($apres !== null) { fmPatchEmploye($shop, $apres); }
    return ['ok' => $ok, 'cochees' => $apres !== null ? count(array_intersect($manque, $apres['competences'])) : 0, 'attendues' => count($manque)];
}

/** POST /parcours/{id}/acquis/{cid} — la manager confirme une compétence cochée à l'étape 2 : elle passe dans le panel. */
function wr_parcours_confirmer(int $pid, int $cid): array
{
    if ($e = fmPret()) { return $e; }
    ensureParcours();
    $p = Db::row('SELECT * FROM ceo_parcours WHERE id = ?', [$pid]);
    if ($p === null) { return fmRefus(404, 'parcours inconnu'); }
    $decl = json_decode((string) $p['acquis_declares'], true) ?: [];
    if (!in_array($cid, $decl, true)) { return fmRefus(422, 'compétence non déclarée'); }
    if ($p['employe_id'] !== null) {
        $_GET['shop'] = (string) $p['shop_id'];
        $r = wr_formation_employe_competence('PUT', (int) $p['employe_id'], $cid);
        if (empty($r['ok'])) { return $r; }
    }
    Db::exec('UPDATE ceo_parcours SET acquis_declares = ?, maj_le = NOW() WHERE id = ?', [json_encode(array_values(array_diff($decl, [$cid]))), $pid]);
    return ep_parcours_un($pid);
}

/**
 * PATCH /parcours/{id} {rythme?, statut?: arrete|atteint} — changer le rythme
 * (les dates restantes se recalculent), arrêter, ou marquer l'objectif atteint :
 * pour un niveau du panel, le niveau est alors affecté à la personne (relu).
 */
function wr_parcours_modifier(int $pid): array
{
    ensureParcours();
    $b = fmCorps();
    $p = Db::row('SELECT * FROM ceo_parcours WHERE id = ?', [$pid]);
    if ($p === null) { return fmRefus(404, 'parcours inconnu'); }
    if (isset($b['rythme']) && isset(PC_SEMAINES[$b['rythme']])) {
        Db::exec('UPDATE ceo_parcours SET rythme = ?, maj_le = NOW() WHERE id = ?', [$b['rythme'], $pid]);
        pcPlanifier($pid);
        pcJournal((string) $p['nom'], 'Rythme du parcours : ' . $b['rythme']);
    }
    $st = (string) ($b['statut'] ?? '');
    if ($st === 'arrete') {
        Db::exec("UPDATE ceo_parcours SET statut = 'arrete', maj_le = NOW() WHERE id = ?", [$pid]);
        pcJournal((string) $p['nom'], 'Parcours arrêté (vers « ' . $p['cible_nom'] . ' »)');
    } elseif ($st === 'atteint') {
        $reste = (int) Db::row("SELECT COUNT(*) AS n FROM ceo_parcours_etape WHERE parcours_id = ? AND statut <> 'validee'", [$pid])['n'];
        if ($reste > 0) { return fmRefus(409, $reste . ' formation(s) encore à valider'); }
        if ($p['employe_id'] !== null && str_starts_with((string) $p['cible_cle'], 'n:')) {
            if ($e = fmPret()) { return $e; }
            $nid = (int) substr((string) $p['cible_cle'], 2);
            $ctx = pcContexte();
            if (isset($ctx['erreur'])) { return $ctx['erreur']; }
            $d = pcDestinations($ctx['ref'])['n:' . $nid] ?? null;
            if ($d === null) { return fmRefus(422, 'le niveau visé n’existe plus dans le panel'); }
            $_GET['shop'] = (string) $p['shop_id'];
            $r = pcAffecter((int) $p['employe_id'], (int) $d['posteId'], $nid);
            if (empty($r['ok'])) { return $r; }
        }
        Db::exec("UPDATE ceo_parcours SET statut = 'atteint', maj_le = NOW() WHERE id = ?", [$pid]);
        pcJournal((string) $p['nom'], 'Objectif atteint : ' . $p['cible_nom']);
    }
    return ep_parcours_un($pid);
}

/** Affecte un poste et un niveau dans le panel (la route du module Formation, relue). */
function pcAffecter(int $eid, int $poste, int $niveau): array
{
    $chemin = '/employees/' . $eid . '/positions';
    $w = PanelApi::requete('PUT', $chemin, ['position_id' => $poste, 'level_id' => $niveau]);
    if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel a refusé : ' . ($w['erreur'] ?? 'erreur')); }
    [$apres] = fmRelireEmploye($eid);
    if ($apres === null || !array_filter($apres['postes'], static fn ($x) => $x['id'] === $poste && $x['niveauId'] === $niveau)) {
        return fmRefus(502, 'le panel a répondu HTTP ' . $w['code'] . ' mais le niveau n’a pas changé');
    }
    fmPatchEmploye(fmId('shop'), $apres);
    return ['ok' => true];
}

/** GET /formation/v25 — le référentiel v25 pour l'écran (formations, postes, plans). */
function ep_formation_v25(): array
{
    $v = pcV25();
    // P1…P12 → le nom du panel (prioritaire), lu dans le référentiel en cache ; à défaut, celui de l'Excel.
    $noms = [];
    $c = setting(fmCleCache('/formation/referentiel'));
    if (is_array($c) && is_array($c['r'] ?? null)) {
        foreach (pcDestinations($c['r']) as $d) {
            // P1–P3 couvrent tous les niveaux d'opérateur : le nom du poste ; P4–P6, le niveau « Responsable d'équipe ».
            $chef = in_array($d['v25'], ['P4', 'P5', 'P6'], true);
            $noms[$d['v25']] ??= $d['sous'] ? ($chef ? $d['sous'] . ' · ' . $d['titre'] : $d['sous']) : $d['titre'];
        }
    }
    foreach ($v['postes'] as $p) { $noms[$p['code']] ??= $p['nom']; }
    return ['source' => $v['source'] ?? null, 'postes' => $v['postes'], 'nomsPanel' => $noms, 'formations' => $v['formations'], 'plans' => $v['plans'],
        'competences' => $v['competences']];
}
