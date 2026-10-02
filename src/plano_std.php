<?php
declare(strict_types=1);

/**
 * LE PLANOGRAMME STANDARD — un seul comptoir pour tout le réseau.
 *
 * Le comptoir est modélisé une fois pour toutes (dossier de passation du
 * 29/09) : 25 sections de 30 cm, trois étages, cinq zones. Les sections 1–2
 * portent la caisse ; les étages 2 et 3 n'existent que sur le sec en mortex.
 * Cela fait 66 emplacements, et chacun reçoit UN produit du catalogue (l'API du
 * panel : la ref est l'id produit des tickets) avec la quantité qu'il contient
 * plein. C'est cette quantité qui fait les rotations : les unités vendues au
 * comptoir dans la journée divisées par ce que la section contient.
 *
 * Les MOMENTS de la journée : un emplacement porte un produit pour un, deux ou
 * les trois moments (matin, midi, après-midi — les tranches des statistiques de
 * vente : jusqu'à 10 h, 11–14 h, à partir de 15 h). « Toute la journée » est le
 * cas courant ; les croissants du matin peuvent céder la place au traiteur de
 * midi. Les rotations suivent : un produit ne compte que les ventes des heures
 * où il est posé.
 *
 * Le plan garde son histoire : changer un produit ou une quantité ferme la
 * ligne en vigueur (au = veille) et en ouvre une nouvelle (du = aujourd'hui).
 * Les rotations d'un jour passé se calculent donc sur le plan de CE jour-là.
 *
 * Les ventes des clients pro ne passent pas par le comptoir : le relevé
 * quotidien des produits porte leur part (`pb`), que les rotations retirent.
 */

const PS_ZONES = [
    ['id' => 'z0', 'type' => 'sec', 'mat' => 'mortex', 'nom' => 'Sec · mortex blanc', 'de' => 1, 'a' => 8],
    ['id' => 'z1', 'type' => 'frigo', 'mat' => null, 'nom' => 'Frigo', 'de' => 9, 'a' => 12],
    ['id' => 'z2', 'type' => 'sec', 'mat' => 'mortex', 'nom' => 'Sec · mortex', 'de' => 13, 'a' => 16],
    ['id' => 'z3', 'type' => 'frigo', 'mat' => null, 'nom' => 'Frigo · frais', 'de' => 17, 'a' => 20],
    ['id' => 'z4', 'type' => 'sec', 'mat' => 'bois', 'nom' => 'Sec · table bois', 'de' => 21, 'a' => 25],
];
const PS_CAISSE = [1, 2];
const PS_SECTIONS = 25;
const PS_NIVEAUX = [
    ['k' => 'e3', 'nom' => 'Étage 3', 'court' => 'É3', 'sub' => 'Top picking · 10 cm', 'cm' => 10],
    ['k' => 'e2', 'nom' => 'Étage 2', 'court' => 'É2', 'sub' => '20 cm', 'cm' => 20],
    ['k' => 'e1b', 'nom' => 'Étage 1 · arrière', 'court' => 'É1 arrière', 'sub' => '30 × 30 cm', 'cm' => 30],
    ['k' => 'e1a', 'nom' => 'Étage 1 · avant', 'court' => 'É1 avant', 'sub' => '30 × 30 cm', 'cm' => 30],
];
const PS_PERIODES = [
    ['k' => 'matin', 'nom' => 'Matin', 'court' => 'M', 'de' => 0, 'a' => 10],
    ['k' => 'midi', 'nom' => 'Midi', 'court' => 'Mi', 'de' => 11, 'a' => 14],
    ['k' => 'aprem', 'nom' => 'Après-midi', 'court' => 'AM', 'de' => 15, 'a' => 23],
];
const PS_PHOTOS_PAR_APPEL = 24;   // photos du panel téléchargées au plus par requête
const PS_B2B_BUDGET = 40;          // appels panel au plus, par lecture des rotations, pour compléter la part pro

/** La zone d'une section (1 → 25). */
function psZoneDe(int $section): ?array
{
    foreach (PS_ZONES as $z) { if ($section >= $z['de'] && $section <= $z['a']) { return $z; } }
    return null;
}

/** L'emplacement existe-t-il ? Étage 1 partout sauf la caisse ; étages 2–3 sur le sec en mortex seulement. */
function psDispo(int $section, string $niveau): bool
{
    if (in_array($section, PS_CAISSE, true)) { return false; }
    $z = psZoneDe($section);
    if ($z === null) { return false; }
    if ($niveau === 'e1a' || $niveau === 'e1b') { return true; }
    if ($niveau === 'e2' || $niveau === 'e3') { return $z['type'] === 'sec' && $z['mat'] !== 'bois'; }
    return false;
}

/** Les 66 emplacements, dans l'ordre du comptoir : [[section, niveau], …]. */
function psCles(): array
{
    $out = [];
    for ($s = 1; $s <= PS_SECTIONS; $s++) {
        foreach (['e3', 'e2', 'e1b', 'e1a'] as $n) { if (psDispo($s, $n)) { $out[] = [$s, $n]; } }
    }
    return $out;
}

/** Les moments d'une ligne, dans l'ordre de la journée ; vide ou null = toute la journée. */
function psPeriodesDe(mixed $v): array
{
    $tous = array_column(PS_PERIODES, 'k');
    if ($v === null || $v === '' || $v === 'journee') { return $tous; }
    $l = is_array($v) ? array_map('strval', $v) : explode(',', (string) $v);
    $out = array_values(array_filter($tous, static fn ($k) => in_array($k, $l, true)));
    return $out ?: $tous;
}

/** Ce qui s'écrit en base : null pour toute la journée, sinon « matin,midi ». */
function psPeriodesTxt(array $p): ?string
{
    return count($p) >= count(PS_PERIODES) ? null : implode(',', $p);
}

/** Le moment d'une heure de vente. */
function psPeriodeHeure(int $h): string
{
    foreach (PS_PERIODES as $p) { if ($h >= $p['de'] && $h <= $p['a']) { return $p['k']; } }
    return 'aprem';
}

function psNiveauNom(string $k): string
{
    foreach (PS_NIVEAUX as $n) { if ($n['k'] === $k) { return $n['nom']; } }
    return $k;
}

function ensurePlanoStd(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plano_std_emplacement ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'section TINYINT NOT NULL,'
        . 'niveau VARCHAR(4) NOT NULL,'
        . 'ref VARCHAR(40) NOT NULL,'
        . 'nom VARCHAR(200) NULL,'
        . 'groupe VARCHAR(80) NULL,'
        . 'qte DECIMAL(8,2) NULL,'
        . 'photo VARCHAR(255) NULL,'
        . 'crop VARCHAR(120) NULL,'
        . 'periodes VARCHAR(24) NULL,'
        . 'du DATE NOT NULL,'
        . 'au DATE NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NULL,'
        . 'KEY k_ps_slot (section, niveau, au),'
        . 'KEY k_ps_ref (ref)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les moments de la journée, ajoutés après la première version de la table.
    // On regarde d'abord (lecture vide, sans coût) : l'ALTER ne part que sur une table d'avant.
    try { Db::rows('SELECT periodes FROM ceo_plano_std_emplacement LIMIT 0'); }
    catch (Throwable $e) {
        try { Db::exec('ALTER TABLE ceo_plano_std_emplacement ADD COLUMN periodes VARCHAR(24) NULL'); } catch (Throwable $e2) { /* déjà là */ }
    }
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plano_std_zone ('
        . 'id VARCHAR(4) NOT NULL PRIMARY KEY,'
        . 'nom VARCHAR(80) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plano_std_photo ('
        . 'ref VARCHAR(40) NOT NULL PRIMARY KEY,'
        . 'nom VARCHAR(200) NULL,'
        . 'fichier VARCHAR(255) NULL,'
        . 'maj DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_plano_std_montage ('
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'zone VARCHAR(4) NOT NULL,'
        . 'jour DATE NOT NULL,'
        . 'photo VARCHAR(255) NOT NULL,'
        . 'auteur VARCHAR(190) NULL,'
        . 'quand DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, zone, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Qui écrit : l'utilisateur connecté, sinon « CEO ». */
function psAuteur(): string
{
    $u = setting('utilisateur', []);
    return is_array($u) && !empty($u['nom']) ? mb_substr((string) $u['nom'], 0, 120) : 'CEO';
}

/** Le nom de chaque zone : celui qu'on lui a donné, sinon le nom par défaut. */
function psNomsZones(): array
{
    $noms = [];
    foreach (PS_ZONES as $z) { $noms[$z['id']] = $z['nom']; }
    try {
        foreach (Db::rows('SELECT id, nom FROM ceo_plano_std_zone') as $r) {
            $n = trim((string) ($r['nom'] ?? ''));
            if ($n !== '' && isset($noms[(string) $r['id']])) { $noms[(string) $r['id']] = $n; }
        }
    } catch (PDOException $e) { /* table absente : noms par défaut */ }
    return $noms;
}

/** Les lignes du plan en vigueur un jour donné — ["section|niveau" => [lignes, une par moment ou groupe de moments]]. */
function psLignes(string $jour): array
{
    ensurePlanoStd();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_plano_std_emplacement WHERE du <= ? AND (au IS NULL OR au >= ?) ORDER BY du, id', [$jour, $jour]) as $r) {
        $out[(int) $r['section'] . '|' . (string) $r['niveau']][] = $r;
    }
    return $out;
}

/** Les lignes qui touchent une étendue [du, au] — pour les rotations, jour par jour. */
function psLignesEtendue(string $du, string $au): array
{
    ensurePlanoStd();
    return Db::rows('SELECT * FROM ceo_plano_std_emplacement WHERE du <= ? AND (au IS NULL OR au >= ?) ORDER BY du, id', [$au, $du]);
}

function psCrop(?string $json): ?array
{
    if ($json === null || $json === '') { return null; }
    $c = json_decode($json, true);
    if (!is_array($c)) { return null; }
    return ['x' => (float) ($c['x'] ?? 0.5), 'y' => (float) ($c['y'] ?? 0.5), 's' => (float) ($c['s'] ?? 1)];
}

/** Une ligne du plan telle que l'écran la lit (un « occupant » d'emplacement). */
function psOccupant(array $r): array
{
    $p = psPeriodesDe($r['periodes'] ?? null);
    return ['periodes' => $p, 'journee' => count($p) === count(PS_PERIODES),
        'ref' => (string) $r['ref'], 'nom' => $r['nom'] !== null ? (string) $r['nom'] : null,
        'groupe' => $r['groupe'] !== null ? (string) $r['groupe'] : null, 'qte' => $r['qte'] !== null ? (float) $r['qte'] : null,
        'photo' => $r['photo'] !== null && $r['photo'] !== '' ? (string) $r['photo'] : null, 'crop' => psCrop($r['crop']),
        'du' => (string) $r['du'], 'par' => $r['par'] !== null ? (string) $r['par'] : null, 'le' => $r['le'] !== null ? (string) $r['le'] : null];
}

/** Le plan tel que le cockpit et la tablette le lisent. */
function psEtat(?string $jour = null): array
{
    ensurePlanoStd();
    $auj = date('Y-m-d');
    $jour = ($jour !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) ? $jour : $auj;
    $noms = psNomsZones();
    $L = psLignes($jour);
    $ordre = array_flip(array_column(PS_PERIODES, 'k'));
    $empl = []; $remplis = 0; $sansQ = 0; $unites = 0.0; $depuis = null; $partages = 0;
    foreach (psCles() as [$s, $n]) {
        $z = psZoneDe($s);
        $occ = array_map('psOccupant', $L[$s . '|' . $n] ?? []);
        usort($occ, static fn ($a, $b) => $ordre[$a['periodes'][0]] <=> $ordre[$b['periodes'][0]]);
        // L'occupant « principal » : celui de la journée entière, sinon celui qui couvre le plus de moments.
        $pr = null;
        foreach ($occ as $o) { if ($pr === null || count($o['periodes']) > count($pr['periodes'])) { $pr = $o; } }
        $e = ['cle' => $s . '|' . $n, 'section' => $s, 'niveau' => $n, 'zone' => $z['id'], 'occupants' => $occ,
            'journee' => count($occ) === 1 && $occ[0]['journee'], 'moments' => count($occ),
            'ref' => $pr['ref'] ?? null, 'nom' => $pr['nom'] ?? null, 'groupe' => $pr['groupe'] ?? null, 'qte' => $pr['qte'] ?? null,
            'photo' => $pr['photo'] ?? null, 'crop' => $pr['crop'] ?? null, 'du' => $pr['du'] ?? null, 'par' => $pr['par'] ?? null, 'le' => $pr['le'] ?? null];
        if ($occ) {
            $remplis++;
            if (count($occ) > 1) { $partages++; }
            foreach ($occ as $o) {
                if ($o['qte'] === null || $o['qte'] <= 0) { $sansQ++; } else { $unites += $o['qte']; }
                if ($depuis === null || $o['du'] > $depuis) { $depuis = $o['du']; }
            }
        }
        $empl[] = $e;
    }
    $modif = null;
    try {
        $m = Db::row('SELECT le, par FROM ceo_plano_std_emplacement WHERE le IS NOT NULL ORDER BY le DESC LIMIT 1');
        if ($m !== null) { $modif = ['le' => (string) $m['le'], 'par' => (string) ($m['par'] ?? '')]; }
        $mz = Db::row('SELECT le, par FROM ceo_plano_std_zone WHERE le IS NOT NULL ORDER BY le DESC LIMIT 1');
        if ($mz !== null && ($modif === null || (string) $mz['le'] > $modif['le'])) { $modif = ['le' => (string) $mz['le'], 'par' => (string) ($mz['par'] ?? '')]; }
    } catch (PDOException $e) { /* pas d'historique */ }
    return [
        'date' => $jour,
        'layout' => [
            'sections' => PS_SECTIONS, 'largeurCm' => 30, 'caisse' => PS_CAISSE,
            'zones' => array_map(static fn ($z) => ['id' => $z['id'], 'nom' => $noms[$z['id']], 'nomDefaut' => $z['nom'],
                'type' => $z['type'], 'mat' => $z['mat'], 'de' => $z['de'], 'a' => $z['a']], PS_ZONES),
            'niveaux' => PS_NIVEAUX,
            'periodes' => PS_PERIODES,
        ],
        'emplacements' => $empl,
        'totaux' => ['emplacements' => count($empl), 'remplis' => $remplis, 'partages' => $partages, 'sansQuantite' => $sansQ, 'unites' => round($unites, 2)],
        'modifie' => $modif,
        'enVigueurDepuis' => $depuis,
        'source' => 'plan standard du cockpit — un seul plan pour tout le réseau, produits du catalogue (API panel), un produit par emplacement et par moment',
    ];
}

/** GET /planogramme/standard[?date=YYYY-MM-DD] (et l'alias GET /planogramme). */
function ep_plano_std(): array
{
    $d = (string) ($_GET['date'] ?? '');
    return psEtat($d !== '' ? $d : null);
}

/** L'emplacement d'un corps de requête, validé ; sinon une erreur 400. */
function psCleCorps(array $b): ?array
{
    $s = (int) ($b['section'] ?? 0); $n = (string) ($b['niveau'] ?? '');
    return psDispo($s, $n) ? [$s, $n] : null;
}

/**
 * Poser, changer ou vider un emplacement sur des MOMENTS, avec l'histoire.
 *
 * Avec `ref` dans `$chg` : le produit (ou le vide, ref null) prend les moments
 * `$P` — les lignes qui les couvraient les cèdent (fermées si elles datent
 * d'avant aujourd'hui, retouchées sinon) et gardent leurs autres moments. Le même
 * produit reposé garde sa quantité, sa photo et son recadrage.
 * Sans `ref` : on règle l'occupant de ces moments (quantité : nouvelle version,
 * car elle change les rotations ; photo et recadrage : sur place). Rend false si
 * aucun occupant ne correspond.
 */
function psPoser(int $s, string $n, array $P, array $chg): bool
{
    ensurePlanoStd();
    $auj = date('Y-m-d'); $hier = date('Y-m-d', strtotime('-1 day'));
    $par = psAuteur(); $le = date('Y-m-d H:i:s');
    $rows = Db::rows('SELECT * FROM ceo_plano_std_emplacement WHERE section = ? AND niveau = ? AND au IS NULL ORDER BY du, id', [$s, $n]);
    $cols = 'section, niveau, ref, nom, groupe, qte, photo, crop, periodes, du, au, par, le';
    $inserer = static function (array $r, array $per) use ($s, $n, $auj, $par, $le, $cols): void {
        Db::exec('INSERT INTO ceo_plano_std_emplacement (' . $cols . ') VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?,?)',
            [$s, $n, (string) $r['ref'], $r['nom'], $r['groupe'], $r['qte'], $r['photo'], $r['crop'], psPeriodesTxt($per), $auj, $par, $le]);
    };

    if (!array_key_exists('ref', $chg)) {
        $cible = null;
        foreach ($rows as $r) { if (psPeriodesDe($r['periodes']) === $P) { $cible = $r; } }
        if ($cible === null) {
            $inter = array_values(array_filter($rows, static fn ($r) => array_intersect(psPeriodesDe($r['periodes']), $P) !== []));
            if (count($inter) === 1) { $cible = $inter[0]; }
        }
        if ($cible === null) { return false; }
        $qAv = $cible['qte'] !== null ? (float) $cible['qte'] : null;
        $qte = array_key_exists('qte', $chg) ? ($chg['qte'] === null ? null : round((float) $chg['qte'], 2)) : $qAv;
        $photo = array_key_exists('photo', $chg) ? $chg['photo'] : $cible['photo'];
        $crop = array_key_exists('crop', $chg) ? $chg['crop'] : $cible['crop'];
        if ($qte === $qAv || (string) $cible['du'] >= $auj) {
            Db::exec('UPDATE ceo_plano_std_emplacement SET qte = ?, photo = ?, crop = ?, par = ?, le = ? WHERE id = ?', [$qte, $photo, $crop, $par, $le, (int) $cible['id']]);
        } else {
            Db::exec('UPDATE ceo_plano_std_emplacement SET au = ? WHERE id = ?', [$hier, (int) $cible['id']]);
            $inserer(array_merge($cible, ['qte' => $qte, 'photo' => $photo, 'crop' => $crop]), psPeriodesDe($cible['periodes']));
        }
        return true;
    }

    $ref = $chg['ref'] === null || $chg['ref'] === '' ? null : (string) $chg['ref'];
    // Le même produit déjà là sur exactement ces moments : rien ne change (sauf une quantité donnée).
    if ($ref !== null) {
        foreach ($rows as $r) {
            if ((string) $r['ref'] === $ref && psPeriodesDe($r['periodes']) === $P) {
                return array_key_exists('qte', $chg) ? psPoser($s, $n, $P, ['qte' => $chg['qte']]) : true;
            }
        }
    }
    // Ce que le même produit portait (quantité, photo, recadrage) se reprend.
    $avant = null;
    foreach ($rows as $r) { if ($ref !== null && (string) $r['ref'] === $ref) { $avant = $r; break; } }
    foreach ($rows as $r) {
        $rp = psPeriodesDe($r['periodes']);
        if (array_intersect($rp, $P) === []) { continue; }
        $reste = array_values(array_diff($rp, $P));
        if ((string) $r['du'] >= $auj) {
            if ($reste) { Db::exec('UPDATE ceo_plano_std_emplacement SET periodes = ?, par = ?, le = ? WHERE id = ?', [psPeriodesTxt($reste), $par, $le, (int) $r['id']]); }
            else { Db::exec('DELETE FROM ceo_plano_std_emplacement WHERE id = ?', [(int) $r['id']]); }
        } else {
            Db::exec('UPDATE ceo_plano_std_emplacement SET au = ? WHERE id = ?', [$hier, (int) $r['id']]);
            if ($reste) { $inserer($r, $reste); }
        }
    }
    if ($ref !== null) {
        $nouv = ['ref' => $ref,
            'nom' => array_key_exists('nom', $chg) && $chg['nom'] !== null ? mb_substr(trim((string) $chg['nom']), 0, 200) : ($avant['nom'] ?? null),
            'groupe' => array_key_exists('groupe', $chg) && $chg['groupe'] !== null ? mb_substr(trim((string) $chg['groupe']), 0, 80) : ($avant['groupe'] ?? null),
            'qte' => array_key_exists('qte', $chg) ? ($chg['qte'] === null ? null : round((float) $chg['qte'], 2)) : (isset($avant['qte']) && $avant['qte'] !== null ? (float) $avant['qte'] : null),
            'photo' => $avant['photo'] ?? null, 'crop' => $avant['crop'] ?? null];
        $inserer($nouv, $P);
    }
    psFusionner($s, $n);
    return true;
}

/**
 * Deux lignes du jour identiques (même produit, quantité, photo, recadrage) sur
 * des moments différents n'en font qu'une : reposer le même produit sur l'autre
 * moment rend « toute la journée ».
 */
function psFusionner(int $s, string $n): void
{
    $auj = date('Y-m-d');
    $rows = Db::rows('SELECT * FROM ceo_plano_std_emplacement WHERE section = ? AND niveau = ? AND au IS NULL AND du >= ? ORDER BY id', [$s, $n, $auj]);
    $vus = [];
    foreach ($rows as $r) {
        $k = $r['ref'] . '|' . ($r['qte'] ?? '') . '|' . ($r['photo'] ?? '') . '|' . ($r['crop'] ?? '');
        if (!isset($vus[$k])) { $vus[$k] = $r; continue; }
        $a = $vus[$k];
        $union = psPeriodesDe(array_merge(psPeriodesDe($a['periodes']), psPeriodesDe($r['periodes'])));
        Db::exec('UPDATE ceo_plano_std_emplacement SET periodes = ? WHERE id = ?', [psPeriodesTxt($union), (int) $a['id']]);
        Db::exec('DELETE FROM ceo_plano_std_emplacement WHERE id = ?', [(int) $r['id']]);
        $vus[$k]['periodes'] = psPeriodesTxt($union);
    }
}

/** Le libellé d'un emplacement pour le journal : « S4 · Étage 1 · avant ». */
function psLib(int $s, string $n): string { return 'S' . $s . ' · ' . psNiveauNom($n); }

/** Les moments d'un corps de requête : une liste, « journee », ou absent (null). */
function psPeriodesCorps(array $b): ?array
{
    if (!array_key_exists('periodes', $b) || $b['periodes'] === null) { return null; }
    $v = $b['periodes'];
    if ($v === 'journee' || $v === '' || $v === []) { return psPeriodesDe(null); }
    $l = is_array($v) ? $v : explode(',', (string) $v);
    $ok = array_values(array_filter(array_column(PS_PERIODES, 'k'), static fn ($k) => in_array($k, array_map('strval', $l), true)));
    return $ok ?: null;
}

/** Le texte des moments pour le journal. */
function psMomentsTxt(array $P): string
{
    if (count($P) === count(PS_PERIODES)) { return 'toute la journée'; }
    $noms = array_column(PS_PERIODES, 'nom', 'k');
    return mb_strtolower(implode(' + ', array_map(static fn ($k) => $noms[$k], $P)));
}

/**
 * PUT /planogramme/standard/emplacement — {section, niveau, periodes?, ref|null, nom?, groupe?, qte?, crop?}.
 * `periodes` : ["matin","midi","aprem"] (ou "journee") ; avec `ref`, absent = toute la journée ;
 * sans `ref`, absent = le seul occupant de l'emplacement.
 */
function wr_plano_std_emplacement(): array
{
    $b = body();
    $k = psCleCorps($b);
    if ($k === null) { http_response_code(400); return ['error' => 'emplacement inconnu — caisse, ou étage absent sur cette section']; }
    [$s, $n] = $k;
    $P = psPeriodesCorps($b);
    $chg = [];
    if (array_key_exists('ref', $b)) {
        $ref = $b['ref'] === null ? null : trim((string) $b['ref']);
        if ($ref !== null && $ref !== '' && !preg_match('/^[\w.-]{1,40}$/u', $ref)) { http_response_code(400); return ['error' => 'référence produit illisible']; }
        $chg['ref'] = ($ref === '' ? null : $ref);
        if ($P === null) { $P = psPeriodesDe(null); }
    }
    foreach (['nom', 'groupe'] as $c) { if (array_key_exists($c, $b)) { $chg[$c] = $b[$c] === null ? null : (string) $b[$c]; } }
    if (array_key_exists('qte', $b)) {
        $q = $b['qte'];
        if ($q === null || $q === '') { $chg['qte'] = null; }
        elseif (!is_numeric($q) || (float) $q < 0 || (float) $q > 9999) { http_response_code(400); return ['error' => 'quantité illisible (0 à 9 999)']; }
        else { $chg['qte'] = (float) $q; }
    }
    if (array_key_exists('crop', $b)) {
        $c = $b['crop'];
        $chg['crop'] = is_array($c) ? json_encode(['x' => max(0, min(1, (float) ($c['x'] ?? 0.5))), 'y' => max(0, min(1, (float) ($c['y'] ?? 0.5))), 's' => max(1, min(6, (float) ($c['s'] ?? 1)))]) : null;
    }
    if ($P === null) { $P = psPeriodesDe(null); }
    if (!psPoser($s, $n, $P, $chg)) { http_response_code(409); return ['error' => 'aucun produit sur ces moments — posez d’abord un produit, ou précisez le moment']; }
    $occ = array_map('psOccupant', psLignes(date('Y-m-d'))[$s . '|' . $n] ?? []);
    $txt = $occ === [] ? 'Emplacement vidé'
        : implode(' · ', array_map(static fn ($o) => ((string) ($o['nom'] ?? $o['ref'])) . ($o['qte'] !== null ? ' ×' . rtrim(rtrim(number_format($o['qte'], 2, '.', ''), '0'), '.') : ' (quantité à régler)') . ' — ' . psMomentsTxt($o['periodes']), $occ));
    journalAdd('CEO', 'Planogramme', psLib($s, $n), $txt);
    return psEtat();
}

/** POST /planogramme/standard/photo — {section, niveau, periodes?, data (data-URL image) | ''}. */
function wr_plano_std_photo(): array
{
    $b = body();
    $k = psCleCorps($b);
    if ($k === null) { http_response_code(400); return ['error' => 'emplacement inconnu']; }
    [$s, $n] = $k;
    $P = psPeriodesCorps($b) ?? psPeriodesDe(null);
    $data = (string) ($b['data'] ?? '');
    if (trim($data) === '') {
        if (!psPoser($s, $n, $P, ['photo' => null, 'crop' => null])) { http_response_code(409); return ['error' => 'aucun produit sur ces moments']; }
        journalAdd('CEO', 'Planogramme', psLib($s, $n), 'Photo retirée — retour à la photo du panel');
        return psEtat();
    }
    $f = psImage($data, 'uploads/plano/std/' . $s . '-' . $n . '-' . date('YmdHis'));
    if (isset($f['error'])) { http_response_code($f['code']); return ['error' => $f['error']]; }
    if (!psPoser($s, $n, $P, ['photo' => $f['chemin'], 'crop' => null])) { http_response_code(409); return ['error' => 'posez d’abord un produit sur cet emplacement']; }
    journalAdd('CEO', 'Planogramme', psLib($s, $n), 'Photo déposée');
    return psEtat();
}

/**
 * Une image data-URL écrite sous public/ : JPEG, PNG ou WebP, 6 Mo au plus,
 * vérifiée par son contenu (pas par ce qu'elle déclare).
 */
function psImage(string $data, string $rel): array
{
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return ['code' => 422, 'error' => 'image illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64) { return ['code' => 422, 'error' => 'image illisible']; }
    if (strlen($bin) > 6 * 1024 * 1024) { return ['code' => 413, 'error' => 'image trop lourde — 6 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { return ['code' => 415, 'error' => 'format non accepté — JPEG, PNG ou WebP']; }
    $chemin = $rel . '.' . $ext;
    $abs = __DIR__ . '/../public/' . $chemin;
    $dos = dirname($abs);
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { return ['code' => 500, 'error' => 'dossier des photos impossible à créer']; }
    if (@file_put_contents($abs, $bin) === false) { return ['code' => 500, 'error' => 'écriture de la photo impossible']; }
    return ['chemin' => $chemin];
}

/** PUT /planogramme/standard/zone — {zone:"z0", nom:"…"} ; un nom vide rend le nom par défaut. */
function wr_plano_std_zone(): array
{
    ensurePlanoStd();
    $b = body();
    $id = (string) ($b['zone'] ?? '');
    $def = null;
    foreach (PS_ZONES as $z) { if ($z['id'] === $id) { $def = $z; } }
    if ($def === null) { http_response_code(400); return ['error' => 'zone inconnue']; }
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 80);
    Db::exec('DELETE FROM ceo_plano_std_zone WHERE id = ?', [$id]);
    Db::exec('INSERT INTO ceo_plano_std_zone (id, nom, par, le) VALUES (?,?,?,?)', [$id, $nom !== '' ? $nom : null, psAuteur(), date('Y-m-d H:i:s')]);
    journalAdd('CEO', 'Planogramme', 'Zone ' . $def['nom'], $nom !== '' ? 'Renommée « ' . $nom . ' »' : 'Nom par défaut rétabli');
    return psEtat();
}

/** POST /planogramme/standard/vider — {confirmer:true} : le plan en vigueur se ferme (l'histoire reste). */
function wr_plano_std_vider(): array
{
    ensurePlanoStd();
    if (empty(body()['confirmer'])) { http_response_code(400); return ['error' => 'confirmation attendue']; }
    $auj = date('Y-m-d');
    Db::exec('DELETE FROM ceo_plano_std_emplacement WHERE au IS NULL AND du >= ?', [$auj]);
    Db::exec('UPDATE ceo_plano_std_emplacement SET au = ? WHERE au IS NULL', [date('Y-m-d', strtotime('-1 day'))]);
    journalAdd('CEO', 'Planogramme', 'Comptoir standard', 'Plan vidé');
    return psEtat();
}

/* --- Les photos du panel, gardées sur le serveur -------------------------------------------- */

/**
 * GET /planogramme/standard/photos[?refs=a,b] — la photo de recette du panel
 * de chaque produit, téléchargée UNE fois et servie depuis public/uploads :
 * les liens du panel sont signés pour une heure et se relisent un par un, ce
 * qui faisait attendre l'écran à chaque heure. Une absence se retient sept
 * jours. Au plus PS_PHOTOS_PAR_APPEL téléchargements par appel : `restants`
 * dit s'il faut rappeler.
 */
function ep_plano_std_photos(): array
{
    ensurePlanoStd();
    $refs = [];
    $q = trim((string) ($_GET['refs'] ?? ''));
    if ($q !== '') {
        foreach (explode(',', $q) as $r) { $r = trim($r); if (preg_match('/^[\w.-]{1,40}$/u', $r)) { $refs[] = $r; } }
    } else {
        foreach (psLignes(date('Y-m-d')) as $rs) { foreach ($rs as $l) { $refs[] = (string) $l['ref']; } }
    }
    $refs = array_slice(array_values(array_unique($refs)), 0, 120);
    $r = psPhotosResoudre($refs);
    return ['photos' => (object) $r['photos'], 'restants' => $r['restants'],
        'source' => 'photo de recette du panel, gardée sur le serveur ; une photo déposée au cockpit la remplace'];
}

/**
 * Le cœur de la lecture des photos, partagé par le planogramme et la tablette
 * des vendeuses : chaque référence fraîche est rendue telle quelle, les autres
 * sont relues au panel (au plus PS_PHOTOS_PAR_APPEL), téléchargées sous
 * uploads/plano/panel/ et notées dans ceo_plano_std_photo — une absence aussi,
 * pour sept jours.
 *
 * La recette se trouve de deux façons. Quand l'appelant connaît l'id_recipe du
 * produit (`$recettes`, ref → id, tiré du catalogue), elle se lit directement :
 * aucune boutique n'intervient. Sinon on passe par products/available d'un
 * magasin (`$shop`, à défaut le premier actif) — qui ne connaît que ce que CE
 * magasin vend : un produit absent de son assortiment restait sans photo.
 *
 * @param list<string>       $refs
 * @param array<string,int>  $recettes ref → id_recipe connu
 * @return array{photos: array<string,array{url:?string,nom:string}>, restants:int, lus:int}
 */
function psPhotosResoudre(array $refs, int $shop = 0, array $recettes = []): array
{
    ensurePlanoStd();
    $connu = [];
    if ($refs) {
        $in = implode(',', array_fill(0, count($refs), '?'));
        foreach (Db::rows('SELECT * FROM ceo_plano_std_photo WHERE ref IN (' . $in . ')', $refs) as $r) { $connu[(string) $r['ref']] = $r; }
    }
    $photos = []; $aLire = [];
    foreach ($refs as $r) {
        $c = $connu[$r] ?? null;
        $frais = $c !== null && (!empty($c['fichier']) ? is_file(__DIR__ . '/../public/' . $c['fichier']) : (time() - (strtotime((string) $c['maj']) ?: 0) < 7 * 86400));
        if ($frais) { $photos[$r] = ['url' => !empty($c['fichier']) ? (string) $c['fichier'] : null, 'nom' => (string) ($c['nom'] ?? '')]; continue; }
        $aLire[] = $r;
    }
    $restants = max(0, count($aLire) - PS_PHOTOS_PAR_APPEL);
    $aLire = array_slice($aLire, 0, PS_PHOTOS_PAR_APPEL);
    $lus = 0;
    if ($aLire && PanelApi::configured()) {
        // Recette connue : lue directement. Les autres passent par un magasin.
        $parRecette = []; $parMagasin = [];
        foreach ($aLire as $r) {
            $rid = (int) ($recettes[$r] ?? 0);
            if ($rid > 0 && ctype_digit((string) $r)) { $parRecette[(int) $r] = $rid; } else { $parMagasin[] = $r; }
        }
        $trouves = $parRecette ? PanelApi::recipePhotos($parRecette) : [];
        $ids = array_values(array_filter(array_map(static fn ($r) => is_numeric($r) ? (int) $r : 0, $parMagasin)));
        if ($ids) {
            if ($shop <= 0) {
                try { $s = Db::row('SELECT id FROM shops WHERE active = 1 ORDER BY id LIMIT 1'); $shop = $s !== null ? (int) $s['id'] : 0; } catch (PDOException $e) { /* sans magasin : pas de recette */ }
            }
            if ($shop > 0) { $trouves += PanelApi::productPhotos($ids, $shop); }
        }
        foreach ($aLire as $r) {
            $t = $trouves[(int) $r] ?? null;
            $fichier = null;
            if ($t !== null && !empty($t['url'])) { $fichier = psTelecharger((string) $t['url'], 'uploads/plano/panel/' . preg_replace('/[^\w.-]/', '_', $r)); }
            $photos[$r] = ['url' => $fichier, 'nom' => (string) ($t['nom'] ?? '')];
            Db::exec('DELETE FROM ceo_plano_std_photo WHERE ref = ?', [$r]);
            Db::exec('INSERT INTO ceo_plano_std_photo (ref, nom, fichier, maj) VALUES (?,?,?,?)', [$r, mb_substr((string) ($t['nom'] ?? ''), 0, 190), $fichier, date('Y-m-d H:i:s')]);
            $lus++;
        }
    }
    return ['photos' => $photos, 'restants' => $restants, 'lus' => $lus];
}

/** Télécharge une image (lien signé du panel) sous public/ ; le chemin relatif, ou null. */
function psTelecharger(string $url, string $rel): ?string
{
    if (!preg_match('#^https?://#i', $url)) { return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5]);
    $bin = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($bin) || $code < 200 || $code >= 300 || strlen($bin) < 64 || strlen($bin) > 8 * 1024 * 1024) { return null; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { return null; }
    $chemin = $rel . '.' . $ext;
    $abs = __DIR__ . '/../public/' . $chemin;
    $dos = dirname($abs);
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { return null; }
    return @file_put_contents($abs, $bin) === false ? null : $chemin;
}

/* --- Les ventes : les relevés quotidiens, sans appel au panel ------------------------------- */

/** Les magasins actifs — [[id, nom], …]. */
function psMagasins(): array
{
    try { $rows = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
    catch (PDOException $e) { $rows = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name"); }
    $out = array_map(static fn ($r) => ['id' => (string) $r['id'], 'nom' => (string) $r['name']], $rows);
    usort($out, static fn ($a, $b) => strcmp(psCourt($a['nom']), psCourt($b['nom'])));
    return $out;
}

function psCourt(string $nom): string
{
    return preg_match('/-\s*([^-]+)$/u', $nom, $m) ? trim($m[1]) : $nom;
}

/**
 * Les unités vendues AU COMPTOIR d'un magasin un jour — ['etat' => lu|nonLu|sansB2b|ferme,
 * 'u' => [pid => unités], 'h' => [heure => [pid => unités]], 'total' => unités].
 * Le relevé `svP` porte toutes les ventes heure par heure (`p`) et la part des
 * clients pro du jour (`pb`, par produit) : le comptoir est la différence,
 * retirée heure par heure au prorata. Un relevé sans `pb` n'est pas un relevé
 * du comptoir — le jour attend sa part pro.
 */
function psVentesJour(string $sid, string $j, int &$cout, int $budget): array
{
    $c = setting('svP' . $sid . ':' . $j);
    if (!is_array($c) || !isset($c['p']) || !is_array($c['p'])) { return ['etat' => 'nonLu', 'u' => [], 'h' => [], 'total' => 0.0]; }
    $pb = $c['pb'] ?? null;
    if (!is_array($pb) && function_exists('svProduitsB2b') && $cout < $budget) {
        $pb = svProduitsB2b((int) $sid, $j, $cout, $budget);
    }
    $u = []; $h = [];
    foreach ($c['p'] as $heure => $lst) {
        if (!is_array($lst)) { continue; }
        foreach ($lst as $pid => $x) {
            if (!is_array($x)) { continue; }
            $q = (float) ($x[1] ?? 0);
            $u[(string) $pid] = ($u[(string) $pid] ?? 0.0) + $q;
            $h[(int) $heure][(string) $pid] = ($h[(int) $heure][(string) $pid] ?? 0.0) + $q;
        }
    }
    $total = array_sum($u);
    if ($total <= 0) { return ['etat' => 'ferme', 'u' => [], 'h' => [], 'total' => 0.0]; }
    if (!is_array($pb)) { return ['etat' => 'sansB2b', 'u' => $u, 'h' => $h, 'total' => $total]; }
    foreach ($pb as $pid => $q) {
        $pid = (string) $pid;
        if (!isset($u[$pid]) || $u[$pid] <= 0) { continue; }
        $f = max(0.0, 1 - (float) $q / $u[$pid]);   // la part comptoir de ce produit, appliquée à chaque heure
        foreach ($h as $hh => $l) { if (isset($l[$pid])) { $h[$hh][$pid] = $l[$pid] * $f; } }
        $u[$pid] = $u[$pid] * $f;
    }
    return ['etat' => 'lu', 'u' => $u, 'h' => $h, 'total' => array_sum($u)];
}

/** GET /planogramme/standard/ventes?jours=14 — unités vendues au comptoir, réseau, par produit (tri du sélecteur). */
function ep_plano_std_ventes(): array
{
    $n = max(1, min(60, (int) ($_GET['jours'] ?? 14)));
    $au = date('Y-m-d', strtotime('-1 day'));
    $du = date('Y-m-d', strtotime($au . ' -' . ($n - 1) . ' days'));
    $v = []; $cout = 0;
    foreach (psMagasins() as $m) {
        for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
            $r = psVentesJour($m['id'], $j, $cout, 0);
            foreach ($r['u'] as $pid => $q) { $v[$pid] = ($v[$pid] ?? 0.0) + $q; }
        }
    }
    foreach ($v as $k => $q) { $v[$k] = round($q, 1); }
    arsort($v);
    return ['du' => $du, 'au' => $au, 'ventes' => (object) $v, 'source' => 'relevés quotidiens des tickets du panel, clients pro retirés quand leur part est relevée'];
}

/**
 * GET /planogramme/rotations?shop=2|reseau&jours=7|14|30[&au=YYYY-MM-DD][&plan=actuel]
 *
 * Un produit posé sur des moments ne compte que les ventes des heures de ces
 * moments. Rotation d'un occupant un jour = ses unités vendues au comptoir ÷ sa
 * quantité au plan ; d'un emplacement = la somme de ses occupants (combien de
 * fois il s'est vidé) ; d'une section = la moyenne de ses emplacements pondérée
 * par leur capacité (la quantité, au prorata des moments tenus) — pour un
 * produit posé toute la journée, c'est Σ vendus ÷ Σ quantités. Un produit posé
 * sur plusieurs emplacements au même moment partage ses ventes au prorata des
 * quantités. Le plan est celui du jour. Les moyennes ne prennent que les jours
 * ouverts, lus et dont la part pro est connue.
 */
function ep_plano_std_rotations(): array
{
    @set_time_limit(90);
    $n = (int) ($_GET['jours'] ?? 14);
    $n = in_array($n, [7, 14, 30], true) ? $n : max(1, min(60, $n));
    $auj = date('Y-m-d');
    $au = (string) ($_GET['au'] ?? date('Y-m-d', strtotime('-1 day')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au > $auj) { $au = date('Y-m-d', strtotime('-1 day')); }
    $du = date('Y-m-d', strtotime($au . ' -' . ($n - 1) . ' days'));
    $jours = [];
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $jours[] = $j; }
    $nbP = count(PS_PERIODES);

    // Le plan de chaque jour : [cle => [lignes]].
    $lignes = psLignesEtendue($du, $au);
    $planDe = [];
    foreach ($jours as $j) {
        $P = [];
        foreach ($lignes as $r) {
            if ((string) $r['du'] <= $j && ($r['au'] === null || (string) $r['au'] >= $j)) { $P[(int) $r['section'] . '|' . (string) $r['niveau']][] = $r; }
        }
        $planDe[$j] = $P;
    }
    // Simulation : le plan ACTUEL appliqué à toute l'étendue — à la demande
    // (?plan=actuel), ou d'office quand aucun plan n'était en vigueur sur ces
    // jours-là (un plan tout neuf n'a pas encore d'histoire).
    $simulation = ($_GET['plan'] ?? '') === 'actuel' || !array_filter($planDe);
    if ($simulation) {
        $actuel = psLignes($auj);
        foreach ($jours as $j) { $planDe[$j] = $actuel; }
    }
    $dernier = $planDe[$au] ?? [];
    $sansQ = [];
    foreach ($dernier as $k => $rs) { foreach ($rs as $r) { if ($r['qte'] === null || (float) $r['qte'] <= 0) { $sansQ[] = $k; break; } } }

    $demande = (string) ($_GET['shop'] ?? 'reseau');
    $mags = array_values(array_filter(psMagasins(), static fn ($m) => $demande === 'reseau' || $demande === '' || $m['id'] === $demande));
    $cout = 0; $sortie = [];
    foreach ($mags as $m) {
        $sid = $m['id'];
        $ouverts = []; $nonLus = []; $fermes = []; $sansB2b = [];
        $secJ = []; $empJ = []; $vendus = []; $blocJ = []; $comptoirJ = []; $horsU = 0.0; $totU = 0.0;
        // Les jours les plus récents d'abord : ce sont eux que le budget de la part pro doit servir.
        foreach (array_reverse($jours) as $j) {
            $V = psVentesJour($sid, $j, $cout, PS_B2B_BUDGET);
            if ($V['etat'] === 'nonLu') { $nonLus[] = $j; continue; }
            if ($V['etat'] === 'ferme') { $fermes[] = $j; continue; }
            if ($V['etat'] === 'sansB2b') { $sansB2b[] = $j; continue; }
            $ouverts[] = $j;
            $P = $planDe[$j];
            // Les blocs du jour : un occupant, ses moments, sa quantité.
            $blocs = []; $surPlan = [];
            foreach ($P as $k => $rs) {
                foreach ($rs as $r) {
                    $per = psPeriodesDe($r['periodes'] ?? null);
                    $surPlan[(string) $r['ref']] = true;
                    $blocs[] = ['k' => $k, 'ref' => (string) $r['ref'], 'per' => $per, 'q' => (float) ($r['qte'] ?? 0), 'v' => 0.0];
                }
            }
            // Le prorata par moment : la quantité de chaque produit sur les blocs qui tiennent ce moment.
            $qMoment = [];
            foreach ($blocs as $b) { if ($b['q'] > 0) { foreach ($b['per'] as $pk) { $qMoment[$b['ref']][$pk] = ($qMoment[$b['ref']][$pk] ?? 0.0) + $b['q']; } } }
            foreach ($V['h'] as $hh => $l) {
                $pk = psPeriodeHeure((int) $hh);
                foreach ($blocs as $i => $b) {
                    if ($b['q'] <= 0 || !in_array($pk, $b['per'], true) || !isset($l[$b['ref']])) { continue; }
                    $blocs[$i]['v'] += $l[$b['ref']] * $b['q'] / $qMoment[$b['ref']][$pk];
                }
            }
            foreach ($V['u'] as $pid => $q) { $totU += $q; if (!isset($surPlan[(string) $pid])) { $horsU += $q; } }
            // L'emplacement : la somme de ses occupants ; sa capacité, pondérée par les moments tenus.
            $slotR = []; $slotC = []; $slotV = [];
            foreach ($blocs as $b) {
                if ($b['q'] <= 0) { continue; }
                $slotR[$b['k']] = ($slotR[$b['k']] ?? 0.0) + $b['v'] / $b['q'];
                $slotC[$b['k']] = ($slotC[$b['k']] ?? 0.0) + $b['q'] * count($b['per']) / $nbP;
                $slotV[$b['k']] = ($slotV[$b['k']] ?? 0.0) + $b['v'];
                $blocJ[$b['k'] . '#' . $b['ref'] . '#' . implode(',', $b['per'])][$j] = ['v' => $b['v'], 'r' => $b['v'] / $b['q']];
            }
            $sR = []; $sC = []; $tR = 0.0; $tC = 0.0;
            foreach ($slotR as $k => $r) {
                $empJ[$k][$j] = round($r, 2); $vendus[$k][$j] = round($slotV[$k], 1);
                $sec = (int) explode('|', $k)[0];
                $sR[$sec] = ($sR[$sec] ?? 0.0) + $r * $slotC[$k]; $sC[$sec] = ($sC[$sec] ?? 0.0) + $slotC[$k];
                $tR += $r * $slotC[$k]; $tC += $slotC[$k];
            }
            foreach ($sR as $sec => $v) { $secJ[$sec][$j] = round($v / $sC[$sec], 2); }
            if ($tC > 0) { $comptoirJ[$j] = $tR / $tC; }
        }
        $moy = static fn (array $a): ?float => $a ? round(array_sum($a) / count($a), 2) : null;
        $sections = [];
        for ($s = 1; $s <= PS_SECTIONS; $s++) {
            if (in_array($s, PS_CAISSE, true)) { continue; }
            $cap = 0.0;
            foreach ($dernier as $k => $rs) { if ((int) explode('|', $k)[0] === $s) { foreach ($rs as $r) { $cap += (float) ($r['qte'] ?? 0) * count(psPeriodesDe($r['periodes'] ?? null)) / $nbP; } } }
            $jr = [];
            foreach ($jours as $j) { $jr[$j] = $secJ[$s][$j] ?? null; }
            $valides = array_values(array_filter(array_map(static fn ($j) => $secJ[$s][$j] ?? null, $ouverts), static fn ($v) => $v !== null));
            $sections[(string) $s] = ['zone' => psZoneDe($s)['id'], 'capacite' => round($cap, 2), 'jours' => (object) $jr, 'moyenne' => $moy($valides)];
        }
        $empl = [];
        foreach ($dernier as $k => $rs) {
            $jr = []; $vd = [];
            foreach ($jours as $j) { $jr[$j] = $empJ[$k][$j] ?? null; $vd[$j] = $vendus[$k][$j] ?? null; }
            $vv = array_values(array_filter(array_map(static fn ($j) => $vendus[$k][$j] ?? null, $ouverts), static fn ($v) => $v !== null));
            $rr = array_values(array_filter(array_map(static fn ($j) => $empJ[$k][$j] ?? null, $ouverts), static fn ($v) => $v !== null));
            $bl = [];
            foreach ($rs as $r) {
                $per = psPeriodesDe($r['periodes'] ?? null);
                $bk = $k . '#' . $r['ref'] . '#' . implode(',', $per);
                $bj = []; $bv = []; $brv = [];
                foreach ($jours as $j) { $x = $blocJ[$bk][$j] ?? null; $bj[$j] = $x !== null ? round($x['r'], 2) : null; if ($x !== null && in_array($j, $ouverts, true)) { $bv[] = $x['v']; $brv[] = $x['r']; } }
                $bl[] = ['ref' => (string) $r['ref'], 'nom' => (string) ($r['nom'] ?? $r['ref']), 'groupe' => $r['groupe'] !== null ? (string) $r['groupe'] : null,
                    'qte' => $r['qte'] !== null ? (float) $r['qte'] : null, 'periodes' => $per, 'journee' => count($per) === $nbP,
                    'jours' => (object) $bj, 'vendusMoyen' => $bv ? round(array_sum($bv) / count($bv), 1) : null, 'rotation' => $moy($brv)];
            }
            $pr = $bl[0] ?? null;
            foreach ($bl as $b) { if (count($b['periodes']) > count($pr['periodes'])) { $pr = $b; } }
            $empl[$k] = ['ref' => $pr['ref'] ?? null, 'nom' => $pr['nom'] ?? null, 'groupe' => $pr['groupe'] ?? null, 'qte' => $pr['qte'] ?? null,
                'blocs' => $bl, 'vendus' => (object) $vd, 'jours' => (object) $jr,
                'vendusMoyen' => $vv ? round(array_sum($vv) / count($vv), 1) : null, 'rotation' => $moy($rr)];
        }
        sort($ouverts); sort($nonLus); sort($fermes); sort($sansB2b);
        $sortie[] = ['id' => $sid, 'nom' => $m['nom'], 'court' => psCourt($m['nom']),
            'ouverts' => $ouverts, 'nonLus' => $nonLus, 'fermes' => $fermes, 'sansB2b' => $sansB2b,
            'comptoir' => $moy(array_values($comptoirJ)), 'horsComptoir' => $totU > 0 ? round(100 * $horsU / $totU, 1) : null,
            'sections' => (object) $sections, 'emplacements' => (object) $empl];
    }
    // Le réseau : la moyenne des magasins qui ont une valeur.
    $reseauS = [];
    for ($s = 1; $s <= PS_SECTIONS; $s++) {
        if (in_array($s, PS_CAISSE, true)) { continue; }
        $v = array_values(array_filter(array_map(static fn ($m) => ((array) $m['sections'])[(string) $s]['moyenne'] ?? null, $sortie), static fn ($x) => $x !== null));
        $reseauS[(string) $s] = $v ? round(array_sum($v) / count($v), 2) : null;
    }
    $cv = array_values(array_filter(array_map(static fn ($m) => $m['comptoir'], $sortie), static fn ($x) => $x !== null));
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'magasins' => $sortie,
        'reseau' => ['sections' => (object) $reseauS, 'comptoir' => $cv ? round(array_sum($cv) / count($cv), 2) : null],
        'sansQuantite' => array_values(array_unique($sansQ)), 'appels' => $cout, 'simulation' => $simulation, 'periodes' => PS_PERIODES,
        'source' => 'ventes du panel produit par produit et heure par heure (relevés quotidiens), clients pro retirés — plan en vigueur chaque jour, chaque produit sur ses moments'];
}

/* --- Les produits posés, pour le catalogue ---------------------------------------------------- */

/**
 * Où chaque produit est posé aujourd'hui — [ref => zone, meuble (« Section N »),
 * niveau, slot (section), emplacements]. Le catalogue en tire ses colonnes
 * Zone / Section / Étage ; un produit posé deux fois garde son premier emplacement.
 */
function psPlacements(): array
{
    $out = [];
    try {
        $noms = psNomsZones();
        $L = psLignes(date('Y-m-d'));
        foreach (psCles() as [$s, $n]) {
            foreach ($L[$s . '|' . $n] ?? [] as $r) {
                $ref = (string) $r['ref'];
                if (!isset($out[$ref])) {
                    $z = psZoneDe($s);
                    $out[$ref] = ['zone' => $noms[$z['id']], 'meuble' => 'Section ' . $s, 'niveau' => psNiveauNom($n), 'slot' => $s, 'emplacements' => 0];
                }
                $out[$ref]['emplacements']++;
            }
        }
    } catch (PDOException $e) { /* table absente : aucun produit posé */ }
    return $out;
}

/* --- Le montage en magasin : une photo par magasin, zone et jour ------------------------------ */

/** GET /planogramme/standard/montage?shop=4&date=YYYY-MM-DD */
function ep_plano_std_montage(): array
{
    ensurePlanoStd();
    $sid = (string) (int) ($_GET['shop'] ?? 0);
    $jour = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($sid === '0' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) { http_response_code(400); return ['error' => 'shop ou date manquant']; }
    $out = [];
    foreach (Db::rows('SELECT zone, photo, auteur, quand FROM ceo_plano_std_montage WHERE shop_id = ? AND jour = ?', [$sid, $jour]) as $r) {
        $out[(string) $r['zone']] = ['photo' => (string) $r['photo'], 'auteur' => (string) ($r['auteur'] ?? ''), 'quand' => (string) ($r['quand'] ?? '')];
    }
    return ['shop' => $sid, 'date' => $jour, 'zones' => (object) $out];
}

/** POST /planogramme/standard/montage — {shop, zone:"z0", date?, data (data-URL) | '', auteur?} */
function wr_plano_std_montage(): array
{
    ensurePlanoStd();
    $b = body();
    $sid = (string) (int) ($b['shop'] ?? 0);
    $zone = (string) ($b['zone'] ?? '');
    $jour = (string) ($b['date'] ?? date('Y-m-d'));
    if ($sid === '0' || !in_array($zone, array_column(PS_ZONES, 'id'), true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
        http_response_code(422); return ['error' => 'magasin, zone et date sont requis'];
    }
    $anc = Db::row('SELECT photo FROM ceo_plano_std_montage WHERE shop_id = ? AND zone = ? AND jour = ?', [$sid, $zone, $jour]);
    $data = (string) ($b['data'] ?? '');
    if (trim($data) === '') {
        if ($anc !== null && !empty($anc['photo'])) { $f = __DIR__ . '/../public/' . $anc['photo']; if (is_file($f)) { @unlink($f); } }
        Db::exec('DELETE FROM ceo_plano_std_montage WHERE shop_id = ? AND zone = ? AND jour = ?', [$sid, $zone, $jour]);
        return ['ok' => true, 'retiree' => true];
    }
    $f = psImage($data, 'uploads/plano/montage/' . $sid . '-' . $zone . '-' . $jour . '-' . date('His'));
    if (isset($f['error'])) { http_response_code($f['code']); return ['error' => $f['error']]; }
    if ($anc !== null && !empty($anc['photo'])) { $fa = __DIR__ . '/../public/' . $anc['photo']; if (is_file($fa)) { @unlink($fa); } }
    $auteur = mb_substr(trim((string) ($b['auteur'] ?? '')), 0, 190);
    if ($auteur === '') { $auteur = 'Tablette'; }
    Db::exec('DELETE FROM ceo_plano_std_montage WHERE shop_id = ? AND zone = ? AND jour = ?', [$sid, $zone, $jour]);
    Db::exec('INSERT INTO ceo_plano_std_montage (shop_id, zone, jour, photo, auteur, quand) VALUES (?,?,?,?,?,?)',
        [$sid, $zone, $jour, $f['chemin'], $auteur, date('Y-m-d H:i:s')]);
    return ['ok' => true, 'photo' => $f['chemin'], 'auteur' => $auteur, 'quand' => date('Y-m-d H:i:s')];
}
