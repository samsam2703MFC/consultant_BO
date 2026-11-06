<?php
declare(strict_types=1);

/**
 * POSTES, CARTE DES MÉTIERS ET RÉFÉRENTIEL v25 du module Formation — ce qui reste de l'ancien
 * src/parcours.php, retiré le 06/11/2026 (le parcours de chaque personne n'a plus d'écran : le client
 * « n'en voit pas la nécessité »). Reste ce que d'autres écrans lisent :
 *   - GET /formation/carte : la carte des métiers du tableau de bord (des comptes, aucun nom) ;
 *   - GET /formation/v25 : le référentiel v25 (formations, postes, plans, critères d'admission) ;
 *   - POST /formation/postes : créer dans le panel un poste du référentiel qu'il n'a pas encore.
 * Les noms des fonctions gardent leur préfixe pc (les tests et les appels les connaissent ainsi).
 */

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

/** Les critères d'admission du cockpit, par code v25 (src/data/admission.json). */
function pcAdmissionDefs(): array
{
    static $v = null;
    if ($v === null) {
        $f = __DIR__ . '/data/admission.json';
        $j = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $v = is_array($j['postes'] ?? null) ? $j['postes'] : [];
    }
    return $v;
}

/**
 * Une lecture du module Formation, prise dans son cache (celui de l'écran) quand
 * il est frais, sinon refaite et remise en cache : le parcours et le module
 * lisent le même référentiel, une seule fois.
 */
function pcLecture(string $route, callable $calcul, int $ttl, array $q = []): ?array
{
    $k = fmCleCache($route, $q);
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

/**
 * La famille d'un poste, pour ranger les écrans. DANS LA FRANCHISE (le magasin) :
 * opérationnel (P1–P6), cadre et direction (P7 adjoint, P8 manager), devenir
 * entrepreneur (candidat, franchisé, locataire-gérant, multi-boutique).
 * DANS LA MARQUE (le franchiseur, 31/10/2026) : direction opérationnelle,
 * stratégie, consultants, contrôle qualité, client mystère… — les postes que la
 * matrice v25 ne couvre pas (pcCodeV25 null). Lue sur les noms du panel.
 */
function pcFamille(?string $code): string
{
    if ($code === null) { return 'marque'; }
    $n = (int) substr($code, 1);
    return $n <= 6 ? 'operationnel' : ($n <= 8 ? 'cadre' : 'entrepreneur');
}

/** La famille d'un poste du panel (son nom, puis ceux de ses niveaux). */
function pcFamillePoste(array $p): string
{
    $code = pcCodeV25((string) ($p['nom'] ?? ''), '');
    foreach ($p['niveaux'] ?? [] as $n) { $code ??= pcCodeV25((string) ($p['nom'] ?? ''), (string) ($n['nom'] ?? '')); }
    return pcFamille($code);
}

/**
 * Les trois métiers et leur niveau de MAÎTRISE (31/10/2026, règle du réseau : le
 * gérant de magasin maîtrise la vente, la boulangerie-pâtisserie et le traiteur).
 * Maîtriser un métier = toutes ses compétences cumulées jusqu'au dernier niveau
 * avant « Responsable d'équipe » (Équipier confirmé). Lu sur les noms du panel.
 */
const PC_METIERS = ['P3' => ['vente', 'Vente'], 'P1' => ['boul', 'Boulangerie – Pâtisserie'], 'P2' => ['trait', 'Traiteur']];

function pcMaitrises(array $ref): array
{
    $o = [];
    foreach ($ref['postes'] ?? [] as $p) {
        $code = pcCodeV25((string) ($p['nom'] ?? ''), '');
        if (!isset(PC_METIERS[$code]) || isset($o[PC_METIERS[$code][0]]) || empty($p['niveaux'])) { continue; }
        $k = 0;
        foreach ($p['niveaux'] as $i => $n) { if (!str_contains(mb_strtolower((string) $n['nom']), 'responsable')) { $k = $i; } }
        $cumul = [];
        foreach (array_slice($p['niveaux'], 0, $k + 1) as $n) { foreach ($n['competences'] ?? [] as $c) { $cumul[(int) $c] = true; } }
        $o[PC_METIERS[$code][0]] = ['cle' => PC_METIERS[$code][0], 'libelle' => PC_METIERS[$code][1], 'posteId' => $p['id'], 'poste' => (string) $p['nom'],
            'niveauId' => $p['niveaux'][$k]['id'], 'niveau' => (string) $p['niveaux'][$k]['nom'], 'competences' => array_keys($cumul)];
    }
    $ordre = ['vente' => 0, 'boul' => 1, 'trait' => 2];
    uksort($o, static fn ($a, $b) => $ordre[$a] <=> $ordre[$b]);
    return $o;
}

/**
 * La carte des métiers (GET /formation/carte) : le chemin — trois lignes de métier,
 * la polyvalence, le tronc (adjoint, manager), la franchise — avec, à chaque niveau,
 * les compétences cumulées, la part qui a une formation et le nombre de personnes ;
 * les effectifs ; le vivier (qui maîtrise 3, 2, 1, 0 métier) ; ce qui manque.
 * Aucun nom de personne ne sort : des comptes.
 * $equipes : les employés lus par magasin (ep_formation_equipe), une liste par magasin.
 */
function pcCarte(array $ref, array $liens, array $equipes, int $magasins): array
{
    $avecFo = static fn (int $cid): bool => !empty($liens[(string) $cid]['formations']);
    $actifs = [];
    foreach ($equipes as $l) { foreach ($l as $e) { if (!empty($e['actif'])) { $actifs[(int) $e['id']] = $e; } } }
    $parNiveau = [];
    $sansPoste = 0;
    foreach ($actifs as $e) {
        if (empty($e['postes'])) { $sansPoste++; }
        foreach ($e['postes'] as $p) { if (($p['niveauId'] ?? null) !== null) { $parNiveau[(int) $p['niveauId']] = ($parNiveau[(int) $p['niveauId']] ?? 0) + 1; } }
    }
    $maitrises = pcMaitrises($ref);
    $niveauxDe = static function (array $p) use ($avecFo, $parNiveau): array {
        $cumul = []; $o = [];
        foreach ($p['niveaux'] ?? [] as $n) {
            $nouv = array_map('intval', $n['competences'] ?? []);
            foreach ($nouv as $c) { $cumul[$c] = true; }
            $o[] = ['id' => (int) $n['id'], 'nom' => (string) $n['nom'], 'nouvelles' => count($nouv), 'total' => count($cumul),
                'avecFormation' => count(array_filter(array_keys($cumul), $avecFo)), 'personnes' => $parNiveau[(int) $n['id']] ?? 0];
        }
        return $o;
    };
    $chemin = ['metiers' => [], 'tronc' => [], 'entrepreneur' => [], 'reseau' => []];
    $roles = ['P7' => 'tronc', 'P8' => 'tronc', 'P10' => 'entrepreneur', 'P11' => 'entrepreneur', 'P12' => 'entrepreneur', 'P9' => 'entrepreneur'];
    foreach ($ref['postes'] ?? [] as $p) {
        if (($p['id'] ?? null) === null) { continue; }
        $code = pcCodeV25((string) $p['nom'], '');
        foreach ($p['niveaux'] ?? [] as $n) { $code ??= pcCodeV25((string) $p['nom'], (string) $n['nom']); }
        $x = ['id' => (int) $p['id'], 'nom' => trim((string) $p['nom']), 'code' => $code, 'niveaux' => $niveauxDe($p)];
        if (isset(PC_METIERS[$code])) {
            $m = $maitrises[PC_METIERS[$code][0]] ?? null;
            $x['cle'] = PC_METIERS[$code][0];
            $x['libelle'] = PC_METIERS[$code][1];
            $x['maitrise'] = $m ? array_search($m['niveauId'], array_column($x['niveaux'], 'id'), true) : null;
            $x['maitriseCompetences'] = $m ? count($m['competences']) : 0;
            $chemin['metiers'][PC_METIERS[$code][0]] = $x;
        } elseif (isset($roles[$code])) {
            $chemin[$roles[$code]][$code] = $x;
        } elseif (pcFamillePoste($p) === 'marque') {
            $chemin['reseau'][] = ['id' => (int) $p['id'], 'nom' => trim((string) $p['nom'])];
        }
    }
    $ordreM = ['vente' => 0, 'boul' => 1, 'trait' => 2];
    uksort($chemin['metiers'], static fn ($a, $b) => $ordreM[$a] <=> $ordreM[$b]);
    $chemin['metiers'] = array_values($chemin['metiers']);
    foreach (['tronc' => ['P7', 'P8'], 'entrepreneur' => ['P10', 'P11', 'P12', 'P9']] as $k => $ordre) {
        $chemin[$k] = array_values(array_filter(array_map(static fn ($c) => $chemin[$k][$c] ?? null, $ordre)));
    }
    // La polyvalence : l'union des trois maîtrises, et qui la tient.
    $union = [];
    foreach ($maitrises as $m) { foreach ($m['competences'] as $c) { $union[$c] = true; } }
    $paliers = [0, 0, 0, 0];
    $parMetier = array_fill_keys(array_keys($maitrises), 0);
    $avecCompetences = 0;
    foreach ($actifs as $e) {
        $tenues = array_flip(array_map('intval', $e['competences'] ?? []));
        if ($tenues) { $avecCompetences++; }
        $n = 0;
        foreach ($maitrises as $k => $m) {
            if ($m['competences'] && !array_diff_key(array_flip($m['competences']), $tenues)) { $n++; $parMetier[$k]++; }
        }
        $paliers[$n]++;
    }
    $sansFo = 0; $sansProc = 0;
    foreach ($ref['competences'] ?? [] as $c) {
        $l = $liens[(string) $c['id']] ?? null;
        if (empty($l['formations'])) { $sansFo++; }
        if (empty($l['procedures'])) { $sansProc++; }
    }
    $vides = [];
    foreach (array_merge($chemin['tronc'], $chemin['entrepreneur']) as $x) {
        foreach ($x['niveaux'] as $n) { if ($n['nouvelles'] === 0) { $vides[] = $x['nom']; } }
    }
    return ['chemin' => $chemin,
        'polyvalence' => ['competences' => count($union), 'metiers' => array_values(array_map(static fn ($m) => ['cle' => $m['cle'], 'libelle' => $m['libelle'], 'niveau' => $m['niveau'],
            'competences' => count($m['competences']), 'personnes' => $parMetier[$m['cle']]], $maitrises)), 'paliers' => ['3' => $paliers[3], '2' => $paliers[2], '1' => $paliers[1], '0' => $paliers[0]],
            'avecCompetences' => $avecCompetences],
        'effectifs' => ['actifs' => count($actifs), 'sansPoste' => $sansPoste, 'places' => count($actifs) - $sansPoste, 'magasins' => $magasins, 'equipesLues' => count($equipes)],
        'manques' => ['sansFormation' => $sansFo, 'sansProcedure' => $sansProc, 'competences' => count($ref['competences'] ?? []), 'niveauxVides' => array_values(array_unique($vides))]];
}

/** GET /formation/carte — la carte des métiers, lue sur le référentiel et les équipes en cache. */
function ep_formation_carte(): array
{
    if ($e = fmPret()) { return $e; }
    @set_time_limit(240);
    $p = pcPanel();
    if (isset($p['erreur'])) { return $p['erreur']; }
    $equipe = ep_formation_equipe();
    $equipes = [];
    foreach ($equipe['magasins'] ?? [] as $m) {
        $sid = (int) $m['id'];
        $r = pcLecture('/formation/equipe', static function () use ($sid) {
            $avant = $_GET['shop'] ?? null;
            $_GET['shop'] = (string) $sid;
            try { return ep_formation_equipe(); } finally { if ($avant === null) { unset($_GET['shop']); } else { $_GET['shop'] = $avant; } }
        }, 300, ['shop' => (string) $sid]);
        if ($r !== null && is_array($r['employes'] ?? null)) { $equipes[] = $r['employes']; }
    }
    http_response_code(200);
    return pcCarte($p['ref'], $p['liens'], $equipes, count($equipe['magasins'] ?? []));
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

/** Les postes du référentiel que le module sait créer dans le panel (un poste, un niveau du même nom). */
const PC_POSTES_A_CREER = [
    'P12' => ['nom' => 'Franchisé multi-boutique',
        'description' => 'Franchisé qui exploite deux boutiques ou plus : gouvernance du portefeuille, délégation à un Manager par boutique, finance consolidée et arbitrages.'],
    'P9'  => ['nom' => 'Locataire-gérant',
        'description' => 'Exploite une boutique en location-gérance, selon le concept et les standards de la marque.'],
];

/** Le poste du panel qui porte ce code v25 (relu sans cache) : la ligne, [] s'il n'y en a pas, null si illisible. */
function pcPostePanel(string $code): ?array
{
    $r = PanelApi::getParalleleDetail(['p' => '/positions'], 1, 20)['p'];
    if ($r['code'] < 200 || $r['code'] >= 300) { return null; }
    foreach (fmListe($r['data']) as $p) {
        if (pcCodeV25((string) ($p['name'] ?? ''), '') === $code) { return $p; }
    }
    return [];
}

/** Les niveaux d'un poste du panel, relus sans cache (null si illisible). */
function pcNiveauxPanel(int $pid): ?array
{
    $r = PanelApi::getParalleleDetail(['n' => '/positions/' . $pid . '/levels'], 1, 20)['n'];
    return $r['code'] >= 200 && $r['code'] < 300 ? fmListe($r['data']) : null;
}

/**
 * POST /formation/postes {code} — créer dans le panel un poste du référentiel
 * v25 qui n'y est pas encore (Franchisé multi-boutique, Locataire-gérant) : le
 * poste, puis un niveau du même nom. Chaque pas est relu ; refaire l'appel
 * reprend où il s'est arrêté (rien en double). Ensuite le panel fait foi : le
 * module lit ce poste-là, avec ses exigences et compétences.
 * Les routes du panel n'ont pas de contrat : le corps porte les noms lus dans
 * ses réponses (name, description, app_type ; position_id et id_position).
 */
function wr_formation_poste_creer(): array
{
    if ($e = fmPret()) { return $e; }
    $code = strtoupper(trim((string) (fmCorps()['code'] ?? '')));
    $def = PC_POSTES_A_CREER[$code] ?? null;
    if ($def === null) { return fmRefus(422, 'poste du référentiel inconnu ou non créable : ' . $code); }
    $appels = []; $cree = false; $niveauCree = false;
    $p = pcPostePanel($code);
    if ($p === null) { return fmRefus(502, 'liste des postes du panel illisible'); }
    $pid = $p ? fmInt($p, ['id']) : null;
    if ($pid === null) {
        $w = PanelApi::requete('POST', '/positions', ['name' => $def['nom'], 'description' => $def['description'], 'app_type' => 'EMPLOYEE']);
        $appels[] = fmAppelEcrit('POST', '/positions', $w);
        if ($w['code'] < 200 || $w['code'] >= 300) { return fmRefus(502, 'le panel n’a pas créé le poste : ' . ($w['erreur'] ?? 'HTTP ' . $w['code']), ['appels' => $appels]); }
        $p = pcPostePanel($code);
        $pid = $p ? fmInt($p, ['id']) : null;
        if ($pid === null) { return fmRefus(502, 'poste annoncé créé (HTTP ' . $w['code'] . ') mais introuvable à la relecture', ['appels' => $appels]); }
        $cree = true;
    }
    $niv = pcNiveauxPanel($pid);
    if ($niv === null) { return fmRefus(502, 'poste #' . $pid . ' prêt, mais ses niveaux sont illisibles', ['appels' => $appels]); }
    if (!$niv) {
        $w = PanelApi::requete('POST', '/position-levels', ['position_id' => $pid, 'id_position' => $pid, 'name' => $def['nom'], 'description' => '', 'level_order' => 1]);
        $appels[] = fmAppelEcrit('POST', '/position-levels', $w);
        if ($w['code'] < 200 || $w['code'] >= 300) {
            return fmRefus(502, 'poste #' . $pid . ' créé, mais le panel a refusé son niveau : ' . ($w['erreur'] ?? 'HTTP ' . $w['code']), ['appels' => $appels]);
        }
        $niv = pcNiveauxPanel($pid) ?? [];
        if (!$niv) { return fmRefus(502, 'poste #' . $pid . ' créé ; niveau annoncé créé (HTTP ' . $w['code'] . ') mais absent du poste à la relecture', ['appels' => $appels]); }
        $niveauCree = true;
    }
    // Le référentiel en cache ne connaît pas ce poste : il sera relu.
    try { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [fmCleCache('/formation/referentiel')]); } catch (Throwable $e) { /* relu à l'expiration */ }
    $n = fmNiveau($niv[0], $pid);
    if ($cree || $niveauCree) {
        fmJournal($def['nom'], 'Poste créé dans le panel depuis le référentiel v25 (' . $code . ') : poste #' . $pid . ', niveau #' . $n['id']);
    }
    return ['ok' => true, 'deja' => !$cree && !$niveauCree, 'poste' => ['id' => $pid, 'nom' => (string) ($p['name'] ?? $def['nom'])],
        'niveau' => ['id' => $n['id'], 'nom' => $n['nom']], 'appels' => $appels];
}

/** GET /formation/v25 — le référentiel v25 pour l'écran (formations, postes, plans). */
function ep_formation_v25(): array
{
    $v = pcV25();
    // P1…P12 → le nom du panel (prioritaire), lu dans le référentiel en cache ; à défaut, celui de l'Excel.
    $noms = []; $niveaux = []; $horsPanel = []; $familles = [];
    $c = setting(fmCleCache('/formation/referentiel'));
    if (is_array($c) && is_array($c['r'] ?? null)) {
        foreach ($c['r']['postes'] ?? [] as $p) { if (($p['id'] ?? null) !== null) { $familles[(string) $p['id']] = pcFamillePoste($p); } }
        foreach (pcDestinations($c['r']) as $d) {
            if ($d['niveauId'] !== null) { $niveaux[(string) $d['niveauId']] = $d['v25']; } else { $horsPanel[] = ['code' => $d['v25'], 'nom' => $d['titre'], 'famille' => pcFamille($d['v25'])]; }
            // P1–P3 couvrent tous les niveaux d'opérateur : le nom du poste ; P4–P6, le niveau « Responsable d'équipe ».
            $chef = in_array($d['v25'], ['P4', 'P5', 'P6'], true);
            $noms[$d['v25']] ??= $d['sous'] ? ($chef ? $d['sous'] . ' · ' . $d['titre'] : $d['sous']) : $d['titre'];
        }
    }
    foreach ($v['postes'] as $p) { $noms[$p['code']] ??= $p['nom']; }
    return ['source' => $v['source'] ?? null, 'postes' => $v['postes'], 'nomsPanel' => $noms, 'formations' => $v['formations'], 'plans' => $v['plans'],
        'manuel' => $v['manuel'] ?? [],
        'competences' => $v['competences'], 'admission' => (object) pcAdmissionDefs(), 'niveauxV25' => (object) $niveaux, 'horsPanel' => $horsPanel,
        'familles' => (object) $familles];
}

/**
 * Les tables de l'ancien parcours (ceo_parcours, ceo_parcours_etape), retiré le 06/11/2026 : effacées UNE fois, à
 * l'installation qui suit la mise en ligne. Une table qui a encore des lignes est d'abord gardée en JSON dans le réglage
 * parcours_sauvegarde_2026_11 (rien ne se perd) ; le bilan est dans parcours_efface_2026_11 et au journal.
 */
function formationParcoursEffacer(): void
{
    if (setting('parcours_efface_2026_11') !== null) { return; }
    $sauve = []; $n = [];
    foreach (['ceo_parcours_etape', 'ceo_parcours'] as $t) {
        try { $lignes = Db::rows('SELECT * FROM ' . $t); }
        catch (PDOException $e) { if (isMissingTable($e)) { $n[$t] = null; continue; } throw $e; }
        $n[$t] = count($lignes);
        if ($lignes) { $sauve[$t] = $lignes; }
    }
    if ($sauve) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['parcours_sauvegarde_2026_11', json_encode($sauve, JSON_UNESCAPED_UNICODE)]);
    }
    Db::exec('DROP TABLE IF EXISTS ceo_parcours_etape');
    Db::exec('DROP TABLE IF EXISTS ceo_parcours');
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['parcours_efface_2026_11', json_encode(['le' => date('Y-m-d H:i:s'), 'lignes' => $n, 'sauvegarde' => (bool) $sauve])]);
    $txt = implode(', ', array_map(static fn ($t, $c) => $t . ' : ' . ($c === null ? 'absente' : $c . ' ligne' . ($c > 1 ? 's' : '')), array_keys($n), $n));
    try { journalAdd('Installation', 'Info', 'Formation', 'Parcours retiré : tables effacées (' . $txt . ')' . ($sauve ? ', lignes gardées dans le réglage parcours_sauvegarde_2026_11' : '') . '.'); }
    catch (Throwable $e) { /* journal absent */ }
}
