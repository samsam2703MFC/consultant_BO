<?php
declare(strict_types=1);
/**
 * JOURS CREUX — où le magasin ne vend pas, et ce qu'on y fait.
 *
 * Trois choses vivent ici :
 *  1. la CARTE des creux d'un magasin : le chiffre par heure, jour de semaine
 *     par jour de semaine, sur les dernières semaines, avec les blocs creux
 *     repérés et ce qu'ils valent (heures gravées svH, aucun appel de plus) ;
 *  2. le CATALOGUE des mécaniques de promotion : un référentiel du réseau,
 *     livré avec 24 fiches ancrées sur des habitudes d'achat mesurées, que
 *     l'on modifie, duplique, désactive, complète ;
 *  3. les PROMOTIONS adoptées par un magasin sur un créneau — et ce qu'elles
 *     changent, le créneau entier face aux quatre semaines d'avant, mêmes
 *     jours, mêmes heures.
 *
 * Le générateur ne prédit rien : il rechiffre chaque mécanique pour le
 * magasin avec ce qu'il sait — les ventes de l'article sur le créneau, sa
 * marge quand la recette est connue, l'attache déclencheur → article quand les
 * croisements la mesurent — et dit ce qu'il ne sait pas.
 */

const JC_SECTIONS = ['matin' => 'Matin (avant 11 h)', 'midi' => 'Midi (11 – 14 h)', 'apres-midi' => 'Après-midi (14 h et plus)'];
const JC_LEVIERS = [
    'trafic' => ['nom' => 'Trafic', 'quoi' => 'Faire venir des clients qui ne viendraient pas à cette heure-là.', 'kpi' => 'clients par heure sur le créneau, face aux 4 semaines d’avant'],
    'panier' => ['nom' => 'Panier', 'quoi' => 'Faire prendre un article de plus à ceux qui viennent déjà.', 'kpi' => 'panier moyen et taux d’attache sur le créneau'],
    'experience' => ['nom' => 'Expérience', 'quoi' => 'Donner une raison de venir : goûter, découvrir, participer.', 'kpi' => 'nouveaux clients (newsletter, carte) et avis Google'],
    'ecouler' => ['nom' => 'Écouler', 'quoi' => 'Vendre ce qui resterait en vitrine plutôt que de le jeter.', 'kpi' => 'pertes du panel et marge nette du créneau'],
];
const JC_JOURS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
const JC_HEURES = [6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];
const JC_SEUIL_CREUX = 0.7;     // sous 70 % de la moyenne du magasin, une case est creuse
const JC_SEMAINES_MOIS = 4.3;

function ensureJoursCreux(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_promo_mecanique ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'code VARCHAR(8) NULL,'
        . 'levier VARCHAR(12) NOT NULL,'
        . 'type VARCHAR(24) NULL,'
        . 'nom VARCHAR(160) NOT NULL,'
        . 'regle TEXT NULL,'
        . 'habitude TEXT NULL,'
        . 'sections VARCHAR(40) NOT NULL DEFAULT \'apres-midi\','
        . 'jours VARCHAR(40) NULL,'
        . 'declencheur TEXT NULL,'
        . 'article TEXT NULL,'
        . 'offre VARCHAR(24) NULL,'
        . 'prix DECIMAL(8,2) NULL,'
        . 'remise_pct DECIMAL(5,1) NULL,'
        . 'marge_min DECIMAL(5,1) NULL,'
        . 'mesure VARCHAR(80) NULL,'
        . 'canaux VARCHAR(200) NULL,'
        . 'caisse VARCHAR(240) NULL,'
        . 'note TEXT NULL,'
        . 'marge_garde TINYINT(1) NOT NULL DEFAULT 1,'
        . 'actif TINYINT(1) NOT NULL DEFAULT 1,'
        . 'ordre INT NOT NULL DEFAULT 0,'
        . 'cree_le DATETIME NULL,'
        . 'maj_le DATETIME NULL,'
        . 'maj_par VARCHAR(120) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_promo ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'mecanique_id INT NULL,'
        . 'levier VARCHAR(12) NOT NULL,'
        . 'type VARCHAR(24) NULL,'
        . 'nom VARCHAR(160) NOT NULL,'
        . 'regle TEXT NULL,'
        . 'jours VARCHAR(40) NOT NULL,'
        . 'heure_de TINYINT NOT NULL,'
        . 'heure_a TINYINT NOT NULL,'
        . 'du DATE NOT NULL,'
        . 'au DATE NOT NULL,'
        . 'declencheur TEXT NULL,'
        . 'article TEXT NULL,'
        . 'offre VARCHAR(24) NULL,'
        . 'prix DECIMAL(8,2) NULL,'
        . 'remise_pct DECIMAL(5,1) NULL,'
        . 'canaux VARCHAR(200) NULL,'
        . 'note TEXT NULL,'
        . 'cible TEXT NULL,'
        . 'ref_ca_h DECIMAL(10,2) NULL,'
        . 'ref_tk_h DECIMAL(8,2) NULL,'
        . 'ref_jours INT NULL,'
        . 'statut VARCHAR(12) NOT NULL DEFAULT \'en_cours\','
        . 'cree_le DATETIME NULL,'
        . 'maj_le DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- les briques ------------------------------------------------------------- */

function jcDateValide(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

/** Les magasins actifs — [id => nom]. */
function jcMagasins(): array
{
    if (function_exists('opMagasins')) { return opMagasins(); }
    $out = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $r) { $out[(string) $r['id']] = (string) $r['name']; } }
    catch (PDOException $e) { /* table absente */ }
    return $out;
}

/** Les N dernières semaines pleines : de lundi il y a N semaines à dimanche dernier. */
function jcFenetre(int $semaines, ?string $jusqua = null): array
{
    $ref = new DateTimeImmutable(($jusqua ?? date('Y-m-d', strtotime('-1 day'))) . ' 12:00:00');
    $dim = $ref->modify('-' . ((int) $ref->format('N') % 7) . ' days');   // le dernier dimanche (ou le jour même)
    $lun = $dim->modify('-' . (7 * $semaines - 1) . ' days');
    $jours = [];
    for ($d = $lun; $d <= $dim; $d = $d->modify('+1 day')) { $jours[] = $d->format('Y-m-d'); }
    return [$lun->format('Y-m-d'), $dim->format('Y-m-d'), $jours];
}

/**
 * La grille d'un magasin : [wd(1-7)][h] => ['ca', 'tk', 'n'] — moyennes par
 * jour ouvert de ce jour de semaine. Un jour sans vente est fermé, pas nul.
 */
function jcGrille(int $sid, array $jours): array
{
    $heures = svHeuresJours($sid, $jours);
    $ouverts = [];   // wd => nombre de jours ouverts
    $som = [];
    foreach ($jours as $d) {
        $hs = $heures[$d] ?? null;
        if (!is_array($hs) || $hs === []) { continue; }
        $tot = 0.0;
        foreach ($hs as $l) { $tot += (float) ($l['ca'] ?? 0); }
        if ($tot <= 0) { continue; }
        $wd = (int) date('N', strtotime($d));
        $ouverts[$wd] = ($ouverts[$wd] ?? 0) + 1;
        foreach ($hs as $l) {
            $h = (int) $l['h'];
            $som[$wd][$h] ??= ['ca' => 0.0, 'tk' => 0];
            $som[$wd][$h]['ca'] += (float) ($l['ca'] ?? 0);
            $som[$wd][$h]['tk'] += (int) ($l['tickets'] ?? 0);
        }
    }
    $cells = [];
    foreach ($som as $wd => $hs) {
        foreach ($hs as $h => $x) {
            $n = $ouverts[$wd];
            $cells[$wd][$h] = ['ca' => round($x['ca'] / $n, 1), 'tk' => round($x['tk'] / $n, 1), 'n' => $n];
        }
    }
    return ['cells' => $cells, 'ouverts' => $ouverts, 'joursLus' => count(array_filter($jours, static fn ($d) => isset($heures[$d])))];
}

/** La moyenne du magasin : ses cases de 7 h à 17 h, jours ouverts. */
function jcMoyenne(array $cells): float
{
    $v = [];
    foreach ($cells as $wd => $hs) { foreach ($hs as $h => $x) { if ($h >= 7 && $h <= 17 && $x['ca'] > 0) { $v[] = $x['ca']; } } }
    return $v === [] ? 0.0 : array_sum($v) / count($v);
}

/** Les blocs creux d'une grille : semaine (lun → ven) et chaque jour du week-end, les heures contiguës sous le seuil. */
function jcBlocs(array $cells, float $moy): array
{
    if ($moy <= 0) { return []; }
    $bas = static fn (int $wd, int $h) => isset($cells[$wd][$h]) && $cells[$wd][$h]['ca'] < JC_SEUIL_CREUX * $moy;
    $blocs = [];
    $groupes = [['jours' => [1, 2, 3, 4, 5], 'min' => 3], ['jours' => [6], 'min' => 1], ['jours' => [7], 'min' => 1]];
    foreach ($groupes as $g) {
        $ouverts = array_values(array_filter($g['jours'], static fn ($wd) => isset($cells[$wd])));
        if ($ouverts === []) { continue; }
        $min = min($g['min'], count($ouverts));
        // Les heures où assez de jours du groupe sont creux, puis les plages contiguës.
        $creuses = [];
        for ($h = 8; $h <= 17; $h++) {
            $n = 0;
            foreach ($ouverts as $wd) { if ($bas($wd, $h)) { $n++; } }
            if ($n >= $min) { $creuses[] = $h; }
        }
        $plages = []; $cur = [];
        foreach ($creuses as $h) {
            if ($cur !== [] && $h !== end($cur) + 1) { $plages[] = $cur; $cur = []; }
            $cur[] = $h;
        }
        if ($cur !== []) { $plages[] = $cur; }
        foreach ($plages as $hs) {
            // Les jours du groupe creux sur au moins la moitié des heures de la plage.
            $jours = [];
            foreach ($ouverts as $wd) {
                $n = 0;
                foreach ($hs as $h) { if ($bas($wd, $h)) { $n++; } }
                if ($n * 2 >= count($hs)) { $jours[] = $wd; }
            }
            if ($jours === []) { continue; }
            $blocs[] = jcBloc($cells, $moy, $jours, $hs);
        }
    }
    usort($blocs, static fn ($a, $b) => $b['potentiel'] <=> $a['potentiel']);
    return array_slice($blocs, 0, 4);
}

/** Ce que vaut un bloc jours × heures : CA / h, clients / h, panier, part de la moyenne, potentiel par mois à 70 %. */
function jcBloc(array $cells, float $moy, array $jours, array $heures): array
{
    $ca = 0.0; $tk = 0.0; $n = 0; $manque = 0.0;
    foreach ($jours as $wd) {
        foreach ($heures as $h) {
            $x = $cells[$wd][$h] ?? ['ca' => 0.0, 'tk' => 0.0];
            $ca += $x['ca']; $tk += $x['tk']; $n++;
            $manque += max(0, JC_SEUIL_CREUX * $moy - $x['ca']);
        }
    }
    $caH = $n ? $ca / $n : 0.0; $tkH = $n ? $tk / $n : 0.0;
    sort($jours); sort($heures);
    return ['jours' => $jours, 'heures' => $heures, 'heureDe' => $heures[0], 'heureA' => end($heures),
        'nom' => jcNomBloc($jours, $heures),
        'caH' => round($caH, 1), 'tkH' => round($tkH, 1), 'panier' => $tkH > 0 ? round($caH / $tkH, 2) : null,
        'part' => $moy > 0 ? round(100 * $caH / $moy, 1) : null,
        'potentiel' => (int) round($manque * JC_SEMAINES_MOIS), 'cases' => $n];
}

function jcNomBloc(array $jours, array $heures): string
{
    $j = array_values($jours);
    $lib = count($j) === 5 && $j === [1, 2, 3, 4, 5] ? 'Lundi → vendredi'
        : (count($j) === 7 ? 'Tous les jours'
        : implode(', ', array_map(static fn ($wd) => ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'][$wd], $j)));
    return $lib . ', ' . $heures[0] . ' – ' . (end($heures) + 1) . ' h';
}

/** Les sections de la journée touchées par une plage d'heures. */
function jcSectionsDe(int $hde, int $ha): array
{
    $s = [];
    for ($h = $hde; $h <= $ha; $h++) { $s[$h < 11 ? 'matin' : ($h < 14 ? 'midi' : 'apres-midi')] = true; }
    return array_keys($s);
}

/* --- 1. la carte des creux ---------------------------------------------------- */

/** GET /creux?shop=&semaines=4 — la grille d'un magasin, ses blocs creux, et le réseau au même exercice. */
function ep_creux(): array
{
    $sid = (int) ($_GET['shop'] ?? 0);
    $semaines = max(2, min(12, (int) ($_GET['semaines'] ?? 4)));
    $nomDe = jcMagasins();
    if ($sid <= 0 || !isset($nomDe[(string) $sid])) { http_response_code(400); return ['error' => 'magasin manquant ou inconnu']; }
    @set_time_limit(120);
    [$du, $au, $jours] = jcFenetre($semaines);
    $g = jcGrille($sid, $jours);
    $moy = jcMoyenne($g['cells']);
    $blocs = jcBlocs($g['cells'], $moy);
    // Le réseau : le bloc principal de chaque magasin, sur sa propre moyenne.
    $reseau = [];
    foreach ($nomDe as $id => $nom) {
        if ((int) $id === $sid) { $reseau[] = ['id' => (string) $id, 'nom' => $nom, 'moyenne' => round($moy, 1), 'bloc' => $blocs[0] ?? null]; continue; }
        $g2 = jcGrille((int) $id, $jours);
        $m2 = jcMoyenne($g2['cells']);
        $b2 = jcBlocs($g2['cells'], $m2);
        $reseau[] = ['id' => (string) $id, 'nom' => $nom, 'moyenne' => round($m2, 1), 'bloc' => $b2[0] ?? null];
    }
    usort($reseau, static fn ($a, $b) => (($b['bloc']['potentiel'] ?? 0) <=> ($a['bloc']['potentiel'] ?? 0)));
    $cells = [];
    foreach ($g['cells'] as $wd => $hs) { foreach ($hs as $h => $x) { $cells[$wd . ':' . $h] = $x; } }
    // Le repère du midi : ce que vaut une heure de rush, pour dire ce qui manque au creux.
    $midi = jcBloc($g['cells'], $moy, [1, 2, 3, 4, 5], [11, 12]);
    return ['shop' => (string) $sid, 'nom' => $nomDe[(string) $sid], 'semaines' => $semaines, 'du' => $du, 'au' => $au,
        'joursLus' => $g['joursLus'], 'ouverts' => $g['ouverts'], 'moyenne' => round($moy, 1), 'seuil' => JC_SEUIL_CREUX,
        'heures' => JC_HEURES, 'cells' => $cells, 'blocs' => $blocs, 'midi' => $midi, 'reseau' => $reseau,
        'sections' => JC_SECTIONS, 'leviers' => JC_LEVIERS,
        'source' => 'heures gravées du panel (svH), ' . $semaines . ' semaines pleines'];
}

/* --- 2. le catalogue des mécaniques ------------------------------------------- */

function jcListe($v): array
{
    if (is_array($v)) { return array_values(array_filter(array_map('strval', $v), static fn ($x) => $x !== '')); }
    $s = trim((string) $v);
    if ($s === '') { return []; }
    $j = json_decode($s, true);
    if (is_array($j)) { return array_values(array_filter(array_map('strval', $j), static fn ($x) => $x !== '')); }
    return array_values(array_filter(array_map('trim', explode(',', $s)), static fn ($x) => $x !== ''));
}

function jcMecaniqueLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'code' => (string) ($r['code'] ?? ''), 'levier' => (string) $r['levier'], 'type' => (string) ($r['type'] ?? ''),
        'nom' => (string) $r['nom'], 'regle' => (string) ($r['regle'] ?? ''), 'habitude' => (string) ($r['habitude'] ?? ''),
        'sections' => jcListe($r['sections'] ?? ''), 'jours' => jcListe($r['jours'] ?? ''),
        'declencheur' => jcListe($r['declencheur'] ?? ''), 'article' => jcListe($r['article'] ?? ''),
        'offre' => (string) ($r['offre'] ?? ''), 'prix' => $r['prix'] !== null ? (float) $r['prix'] : null,
        'remisePct' => $r['remise_pct'] !== null ? (float) $r['remise_pct'] : null, 'margeMin' => $r['marge_min'] !== null ? (float) $r['marge_min'] : null,
        'mesure' => (string) ($r['mesure'] ?? ''), 'canaux' => jcListe($r['canaux'] ?? ''), 'caisse' => (string) ($r['caisse'] ?? ''), 'note' => (string) ($r['note'] ?? ''),
        'margeGarde' => (int) $r['marge_garde'] === 1, 'actif' => (int) $r['actif'] === 1, 'ordre' => (int) $r['ordre'],
        'majLe' => $r['maj_le'] !== null ? (string) $r['maj_le'] : null, 'majPar' => $r['maj_par'] !== null ? (string) $r['maj_par'] : null];
}

/** Les 24 fiches livrées, posées une fois si la table est vide. */
function jcSemer(): void
{
    $n = Db::row('SELECT COUNT(*) n FROM ceo_promo_mecanique');
    if ($n !== null && (int) $n['n'] > 0) { return; }
    $f = __DIR__ . '/data/promo_mecaniques.json';
    if (!is_file($f)) { return; }
    $M = json_decode((string) file_get_contents($f), true);
    if (!is_array($M) || !isset($M['mecaniques'])) { return; }
    $ordre = 0;
    foreach ($M['mecaniques'] as $m) {
        $ordre += 10;
        Db::exec('INSERT INTO ceo_promo_mecanique (code, levier, type, nom, regle, habitude, sections, jours, declencheur, article, offre, prix, remise_pct, marge_min, mesure, canaux, caisse, note, marge_garde, actif, ordre, cree_le, maj_le, maj_par)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            (string) ($m['id'] ?? ''), (string) $m['levier'], (string) ($m['type'] ?? ''), (string) $m['nom'], (string) ($m['regle'] ?? ''), (string) ($m['habitude'] ?? ''),
            implode(',', $m['sections'] ?? ['apres-midi']), implode(',', $m['joursParDefaut'] ?? []),
            json_encode($m['declencheur'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($m['article'] ?? [], JSON_UNESCAPED_UNICODE),
            (string) ($m['offre'] ?? ''), $m['prix'] ?? null, $m['remisePct'] ?? null, 55, (string) ($m['mesure'] ?? ''),
            implode(',', $m['canaux'] ?? []), (string) ($m['caisse'] ?? ''), '', !empty($m['margeGarde']) ? 1 : 0, 1, $ordre,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), 'catalogue']);
    }
}

/** GET /promo/mecaniques — le catalogue, actives et désactivées, dans l'ordre. */
function ep_promo_mecaniques(): array
{
    ensureJoursCreux();
    jcSemer();
    $rows = Db::rows('SELECT * FROM ceo_promo_mecanique ORDER BY ordre, id');
    // Les fiches utilisées par une promotion en cours : on les désactive, on ne les supprime pas.
    $usage = [];
    try {
        foreach (Db::rows("SELECT mecanique_id, COUNT(*) n FROM ceo_promo WHERE statut = 'en_cours' AND mecanique_id IS NOT NULL GROUP BY mecanique_id") as $r) { $usage[(int) $r['mecanique_id']] = (int) $r['n']; }
    } catch (PDOException $e) { /* pas encore de promo */ }
    $out = [];
    foreach ($rows as $r) { $l = jcMecaniqueLigne($r); $l['enCours'] = $usage[$l['id']] ?? 0; $out[] = $l; }
    return ['mecaniques' => $out, 'leviers' => JC_LEVIERS, 'sections' => JC_SECTIONS, 'jours' => JC_JOURS,
        'types' => ['bundle', 'précommande', 'remise', 'article offert', 'suggestion', 'rendez-vous', 'événement', 'atelier', 'jeu', 'fidélité', 'nouveauté'],
        'offres' => ['prix bundle', 'remise %', 'article offert', '3 pour 2', 'aucune remise'],
        'canaux' => ['Écran caisse', 'Affiche A4', 'Facebook', 'Newsletter', 'SMS']];
}

/** POST /promo/mecaniques · PUT /promo/mecaniques/{id} — tout se modifie. */
function wr_promo_mecanique(?int $id): array
{
    ensureJoursCreux();
    jcSemer();
    $b = body();
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 160);
    $levier = (string) ($b['levier'] ?? '');
    if ($id === null && ($nom === '' || !isset(JC_LEVIERS[$levier]))) { http_response_code(422); return ['error' => 'nom et levier sont requis']; }
    if ($id !== null) {
        $cur = Db::row('SELECT * FROM ceo_promo_mecanique WHERE id = ?', [$id]);
        if ($cur === null) { http_response_code(404); return ['error' => 'mécanique inconnue']; }
    }
    $champs = [];
    $pose = static function (string $col, $val) use (&$champs) { $champs[$col] = $val; };
    if (array_key_exists('nom', $b) && $nom !== '') { $pose('nom', $nom); }
    if (isset(JC_LEVIERS[$levier])) { $pose('levier', $levier); }
    foreach (['code' => 8, 'type' => 24, 'offre' => 24, 'mesure' => 80, 'caisse' => 240] as $k => $max) { if (array_key_exists($k, $b)) { $pose($k, mb_substr(trim((string) $b[$k]), 0, $max)); } }
    foreach (['regle', 'habitude', 'note'] as $k) { if (array_key_exists($k, $b)) { $pose($k, trim((string) $b[$k])); } }
    if (array_key_exists('sections', $b)) { $s = array_values(array_intersect(jcListe($b['sections']), array_keys(JC_SECTIONS))); $pose('sections', implode(',', $s === [] ? ['apres-midi'] : $s)); }
    if (array_key_exists('jours', $b)) { $pose('jours', implode(',', array_values(array_intersect(jcListe($b['jours']), JC_JOURS)))); }
    if (array_key_exists('declencheur', $b)) { $pose('declencheur', json_encode(jcListe($b['declencheur']), JSON_UNESCAPED_UNICODE)); }
    if (array_key_exists('article', $b)) { $pose('article', json_encode(jcListe($b['article']), JSON_UNESCAPED_UNICODE)); }
    if (array_key_exists('canaux', $b)) { $pose('canaux', implode(',', jcListe($b['canaux']))); }
    foreach (['prix' => 'prix', 'remisePct' => 'remise_pct', 'margeMin' => 'marge_min'] as $k => $col) {
        if (array_key_exists($k, $b)) { $v = trim(str_replace(',', '.', (string) $b[$k])); $pose($col, $v === '' ? null : (float) $v); }
    }
    if (array_key_exists('margeGarde', $b)) { $pose('marge_garde', $b['margeGarde'] ? 1 : 0); }
    if (array_key_exists('actif', $b)) { $pose('actif', $b['actif'] ? 1 : 0); }
    if (array_key_exists('ordre', $b)) { $pose('ordre', (int) $b['ordre']); }
    $champs['maj_le'] = date('Y-m-d H:i:s');
    $champs['maj_par'] = mb_substr(trim((string) ($b['par'] ?? 'CEO')), 0, 120);
    if ($id === null) {
        $champs['cree_le'] = $champs['maj_le'];
        $champs['sections'] ??= 'apres-midi';
        $champs['marge_garde'] ??= 1; $champs['actif'] ??= 1;
        if (!isset($champs['ordre'])) { $m = Db::row('SELECT MAX(ordre) m FROM ceo_promo_mecanique'); $champs['ordre'] = (int) ($m['m'] ?? 0) + 10; }
        Db::exec('INSERT INTO ceo_promo_mecanique (' . implode(',', array_keys($champs)) . ') VALUES (' . implode(',', array_fill(0, count($champs), '?')) . ')', array_values($champs));
        $id = (int) Db::pdo()->lastInsertId();
        journalAdd('CEO', 'Jours creux', $nom, 'Mécanique créée : ' . $nom);
    } else {
        $set = implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($champs)));
        Db::exec('UPDATE ceo_promo_mecanique SET ' . $set . ' WHERE id = ?', array_merge(array_values($champs), [$id]));
        if (($_GET['journal'] ?? '') !== '0') { journalAdd('CEO', 'Jours creux', (string) ($nom ?: $cur['nom']), 'Mécanique modifiée'); }
    }
    $r = Db::row('SELECT * FROM ceo_promo_mecanique WHERE id = ?', [$id]);
    return ['ok' => true, 'mecanique' => $r !== null ? jcMecaniqueLigne($r) : null];
}

/** POST /promo/mecaniques/{id}/dupliquer — une variante, désactivée par défaut jusqu'à ce qu'on l'ait relue. */
function wr_promo_mecanique_dupliquer(int $id): array
{
    ensureJoursCreux();
    $cur = Db::row('SELECT * FROM ceo_promo_mecanique WHERE id = ?', [$id]);
    if ($cur === null) { http_response_code(404); return ['error' => 'mécanique inconnue']; }
    unset($cur['id']);
    $cur['nom'] = mb_substr((string) $cur['nom'] . ' — variante', 0, 160);
    $cur['code'] = null; $cur['actif'] = 1; $cur['ordre'] = (int) $cur['ordre'] + 1;
    $cur['cree_le'] = $cur['maj_le'] = date('Y-m-d H:i:s'); $cur['maj_par'] = 'CEO';
    Db::exec('INSERT INTO ceo_promo_mecanique (' . implode(',', array_keys($cur)) . ') VALUES (' . implode(',', array_fill(0, count($cur), '?')) . ')', array_values($cur));
    $nid = (int) Db::pdo()->lastInsertId();
    $r = Db::row('SELECT * FROM ceo_promo_mecanique WHERE id = ?', [$nid]);
    return ['ok' => true, 'mecanique' => $r !== null ? jcMecaniqueLigne($r) : null];
}

/** DELETE /promo/mecaniques/{id} — refusé si une promotion en cours s'en sert : on la désactive. */
function wr_promo_mecanique_suppr(int $id): array
{
    ensureJoursCreux();
    $cur = Db::row('SELECT nom FROM ceo_promo_mecanique WHERE id = ?', [$id]);
    if ($cur === null) { http_response_code(404); return ['error' => 'mécanique inconnue']; }
    $n = Db::row("SELECT COUNT(*) n FROM ceo_promo WHERE mecanique_id = ? AND statut = 'en_cours'", [$id]);
    if ($n !== null && (int) $n['n'] > 0) { http_response_code(409); return ['error' => 'utilisée par ' . $n['n'] . ' promotion(s) en cours — désactivez-la plutôt']; }
    Db::exec('DELETE FROM ceo_promo_mecanique WHERE id = ?', [$id]);
    journalAdd('CEO', 'Jours creux', (string) $cur['nom'], 'Mécanique supprimée');
    return ['ok' => true];
}

/** PUT /promo/mecaniques/ordre — { ids: [...] } dans l'ordre voulu. */
function wr_promo_mecaniques_ordre(): array
{
    ensureJoursCreux();
    $ids = array_values(array_filter(array_map('intval', (array) (body()['ids'] ?? [])), static fn ($x) => $x > 0));
    $o = 0;
    foreach ($ids as $id) { $o += 10; Db::exec('UPDATE ceo_promo_mecanique SET ordre = ? WHERE id = ?', [$o, $id]); }
    return ['ok' => true, 'n' => count($ids)];
}

/**
 * GET /promo/recherche?q=tart — groupes, catégories et produits du catalogue
 * dont le nom contient le mot : le multiselect du déclencheur et de l'article.
 */
function ep_promo_recherche(): array
{
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    if (mb_strlen($q) < 2) { return ['q' => $q, 'resultats' => []]; }
    $groupes = []; $cats = []; $prods = [];
    $cat = [];
    if (function_exists('ep_prod_catalogue')) {
        try { $cat = ep_prod_catalogue(); } catch (Throwable $e) { $cat = []; }
    }
    $nCat = []; $nGrp = [];
    foreach ($cat as $p) {
        $g = trim((string) ($p['groupe'] ?? '')); $c = trim((string) ($p['categorie'] ?? '')); $nom = trim((string) ($p['nom'] ?? ''));
        if ($c !== '') { $nCat[$c] = ($nCat[$c] ?? 0) + 1; if ($g !== '') { $nGrp[$g] = ($nGrp[$g] ?? 0) + 1; } }
        if ($nom !== '' && ($p['pwaId'] ?? null) !== null && mb_stripos($nom, $q) !== false) {
            $prods[] = ['sel' => 'p:' . (int) $p['pwaId'], 'nom' => $nom, 'type' => 'produit', 'info' => $c];
        }
    }
    foreach ($nGrp as $g => $n) { if (mb_stripos($g, $q) !== false) { $groupes[] = ['sel' => 'g:' . $g, 'nom' => $g, 'type' => 'groupe', 'info' => $n . ' références']; } }
    foreach ($nCat as $c => $n) { if (mb_stripos($c, $q) !== false) { $cats[] = ['sel' => 'c:' . $c, 'nom' => $c, 'type' => 'catégorie', 'info' => $n . ' références']; } }
    // Sans catalogue local (banc, base muette) : les catégories du panel et les produits gravés.
    if ($cat === []) {
        foreach (svCategories() as $pid => $c) { if (mb_stripos($c, $q) !== false && !isset($cats[$c])) { $cats[$c] = ['sel' => 'c:' . $c, 'nom' => $c, 'type' => 'catégorie', 'info' => '']; } }
        $cats = array_values($cats);
        foreach (function_exists('panelCatalogue') ? panelCatalogue()['produits'] : [] as $x) {
            if (!empty($x['actif']) && mb_stripos((string) $x['nom'], $q) !== false) { $prods[] = ['sel' => 'p:' . (int) $x['id'], 'nom' => (string) $x['nom'], 'type' => 'produit', 'info' => (string) ($x['cat'] ?? '')]; }
            if (count($prods) >= 80) { break; }
        }
        if ($prods === []) { try {
            foreach (Db::rows('SELECT id, name FROM product WHERE is_active = 1 AND name LIKE ? ORDER BY name LIMIT 80', ['%' . $q . '%']) as $r) {
                $prods[] = ['sel' => 'p:' . (int) $r['id'], 'nom' => (string) $r['name'], 'type' => 'produit', 'info' => svCategories()[(int) $r['id']] ?? ''];
            }
        } catch (PDOException $e) { /* pas de table produit */ } }
    }
    usort($prods, static fn ($a, $b) => strcmp($a['nom'], $b['nom']));
    return ['q' => $q, 'resultats' => array_merge(array_slice($groupes, 0, 10), array_slice($cats, 0, 20), array_slice($prods, 0, 50))];
}

/* --- 3. le générateur : les propositions chiffrées pour un créneau ---------------- */

/** Un sélecteur sans son libellé : « p:1043|Pommes Tranches » → « p:1043 ». Le libellé ne sert qu'à l'écran. */
function jcSelBrut(string $s): string
{
    $p = strpos($s, '|');
    return $p === false ? $s : substr($s, 0, $p);
}

/** Les identifiants de produits d'une liste de sélecteurs (g:, c:, p:), d'après les catégories du panel et les noms gravés. */
function jcIdsDe(array $sels, array $nomsGraves): array
{
    $cats = svCategories(); $grp = svGroupes();
    $ids = [];
    foreach ($sels as $s) {
        $s = jcSelBrut($s);
        $t = substr($s, 0, 2); $v = trim(substr($s, 2));
        if ($v === '') { continue; }
        if ($t === 'p:') {
            if (ctype_digit($v)) { $ids[(int) $v] = true; continue; }
            foreach ($nomsGraves as $pid => $nom) { if (mb_strtolower(trim($nom)) === mb_strtolower($v)) { $ids[(int) $pid] = true; } }
        } elseif ($t === 'c:') {
            foreach ($cats as $pid => $c) { if ($c === $v) { $ids[(int) $pid] = true; } }
        } elseif ($t === 'g:') {
            foreach ($cats as $pid => $c) { if (($grp[$c] ?? '') === $v) { $ids[(int) $pid] = true; } }
        }
    }
    return array_keys($ids);
}

/**
 * Les ventes gravées d'un magasin sur un créneau (jours × heures) et une fenêtre :
 * par produit — nom, quantité, ventes, coût matière (null si inconnu) — et
 * le nombre de jours ouverts lus.
 */
function jcVentesCreneau(int $sid, array $wds, int $hde, int $ha, array $jours, int &$cout, int $budget): array
{
    $prod = []; $joursLus = 0; $manquants = 0;
    foreach ($jours as $d) {
        if (!in_array((int) date('N', strtotime($d)), $wds, true)) { continue; }
        if ($d < SV_DEBUT) { $manquants++; continue; }
        $p = svProduitsJour($sid, $d, $cout, $budget);
        if ($p === null) { $manquants++; continue; }
        $vu = false;
        foreach ($p as $h => $lst) {
            $h = (int) $h;
            if ($h < $hde || $h > $ha) { continue; }
            foreach ((array) $lst as $pid => $x) {
                $pid = (int) $pid; $vu = true;
                $prod[$pid] ??= ['nom' => svNomProduit($pid, (string) $x[0]), 'q' => 0.0, 'v' => 0.0, 'c' => 0.0, 'cInconnu' => false];
                $prod[$pid]['q'] += (float) $x[1]; $prod[$pid]['v'] += (float) $x[2];
                if ($x[3] === null) { $prod[$pid]['cInconnu'] = true; } else { $prod[$pid]['c'] += (float) $x[3]; }
            }
        }
        $joursLus++;
    }
    return ['produits' => $prod, 'joursLus' => $joursLus, 'manquants' => $manquants];
}

/** La somme d'un sous-ensemble de produits : quantité, ventes, marge % quand tous les coûts sont connus. */
function jcSomme(array $prod, array $ids): array
{
    $q = 0.0; $v = 0.0; $c = 0.0; $inconnu = false; $n = 0;
    foreach ($ids as $pid) {
        $x = $prod[$pid] ?? null;
        if ($x === null) { continue; }
        $n++; $q += $x['q']; $v += $x['v']; $c += $x['c'];
        if ($x['cInconnu']) { $inconnu = true; }
    }
    return ['q' => $q, 'v' => $v, 'prixMoyen' => $q > 0 ? $v / $q : null, 'margePct' => (!$inconnu && $v > 0) ? round(100 * ($v - $c) / $v, 1) : null, 'refs' => $n];
}

/** L'attache déclencheur → article sur le dernier mois clos, pour ce magasin et cette section — via les croisements, si la base les permet. */
function jcAttache(array $decl, array $art, string $section, int $sid): ?array
{
    if ($decl === [] || $art === [] || !function_exists('croisIds') || !function_exists('croisMoisServi')) { return null; }
    try {
        $sa = jcSelBrut($decl[0]); $sb = jcSelBrut($art[0]);
        $a = croisIds($sa); $b = croisIds($sb);
        if ($a === null || $b === null) { return null; }
        $mois = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
        $dp = ['matin' => 'matin', 'midi' => 'midi', 'apres-midi' => 'apresmidi'][$section] ?? '';
        $r = croisMoisServi($sa, $sb, $a['ids'] ?? $a, $b['ids'] ?? $b, $mois, jcMagasins(), $dp);
        if (!is_array($r)) { return null; }
        $x = $r['shops'][(string) $sid] ?? null;
        if ($x === null || (int) $x['ff'] === 0) { return null; }
        return ['taux' => round(100 * $x['avec'] / $x['ff'], 1), 'tickets' => (int) $x['ff'], 'mois' => $mois];
    } catch (Throwable $e) { return null; }
}

/**
 * GET /promo/propositions?shop=&levier=&jours=1,2,3,4,5&hde=14&ha=17 —
 * les mécaniques du levier qui s'appliquent aux sections du créneau, chiffrées
 * pour le magasin : ce que l'article y vend déjà, sa marge, l'attache mesurée,
 * le seuil pour payer la remise. Ce qui n'est pas mesurable est dit tel.
 */
function ep_promo_propositions(): array
{
    ensureJoursCreux();
    jcSemer();
    $sid = (int) ($_GET['shop'] ?? 0);
    $levier = (string) ($_GET['levier'] ?? '');
    $wds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['jours'] ?? '1,2,3,4,5'))), static fn ($x) => $x >= 1 && $x <= 7));
    $hde = max(0, min(23, (int) ($_GET['hde'] ?? 14))); $ha = max($hde, min(23, (int) ($_GET['ha'] ?? 17)));
    if ($sid <= 0 || !isset(JC_LEVIERS[$levier]) || $wds === []) { http_response_code(400); return ['error' => 'magasin, levier ou créneau manquant']; }
    @set_time_limit(120);
    [$du, $au, $jours] = jcFenetre(4);
    $sections = jcSectionsDe($hde, $ha);
    $cout = 0; $budget = SV_BUDGET_DEMANDE;
    $ventes = jcVentesCreneau($sid, $wds, $hde, $ha, $jours, $cout, $budget);
    $prod = $ventes['produits']; $nJ = max(1, $ventes['joursLus']);
    $noms = []; foreach ($prod as $pid => $x) { $noms[$pid] = $x['nom']; }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_promo_mecanique WHERE actif = 1 AND levier = ? ORDER BY ordre, id', [$levier]) as $r) {
        $m = jcMecaniqueLigne($r);
        if (array_intersect($m['sections'], $sections) === []) { continue; }
        $idsA = jcIdsDe($m['article'], $noms); $idsD = jcIdsDe($m['declencheur'], $noms);
        $art = jcSomme($prod, $idsA); $decl = jcSomme($prod, $idsD);
        $remise = $m['remisePct'] !== null ? $m['remisePct'] / 100 : 0.0;
        $prixRef = $m['prix'] ?? $art['prixMoyen'];
        $margeApres = null; $margeEur = null; $seuil = null;
        if ($art['margePct'] !== null && $prixRef !== null && $prixRef > 0) {
            $coutUnit = $prixRef * (1 - $art['margePct'] / 100);
            $prixApres = $prixRef * (1 - $remise);
            $margeEur = $prixApres - $coutUnit;
            $margeApres = $prixApres > 0 ? round(100 * $margeEur / $prixApres, 1) : null;
            // Le seuil : ce que la remise coûte sur ce qui se vend déjà, ramené à ce que rapporte une vente de plus.
            $perte = $remise > 0 ? ($art['q'] / $nJ) * $prixRef * $remise : 0.0;
            $seuil = $margeEur > 0 ? round($perte / $margeEur, 1) : null;
        }
        $attache = null;
        if ($m['declencheur'] !== [] && $m['article'] !== [] && count($sections) === 1) { $attache = jcAttache($m['declencheur'], $m['article'], $sections[0], $sid); }
        $jourOk = $m['jours'] === [] ? true : array_intersect(array_map(static fn ($wd) => JC_JOURS[$wd - 1], $wds), $m['jours']) !== [];
        $out[] = ['mecanique' => $m, 'jourOk' => $jourOk,
            'article' => ['parJour' => round($art['q'] / $nJ, 1), 'caParJour' => round($art['v'] / $nJ, 1), 'refs' => $art['refs'], 'margePct' => $art['margePct'], 'prixMoyen' => $art['prixMoyen'] !== null ? round($art['prixMoyen'], 2) : null],
            'declencheur' => ['parJour' => round($decl['q'] / $nJ, 1), 'refs' => $decl['refs']],
            'chiffres' => ['prix' => $prixRef !== null ? round($prixRef, 2) : null, 'remisePct' => $m['remisePct'], 'margeApres' => $margeApres, 'margeEur' => $margeEur !== null ? round($margeEur, 2) : null,
                'seuilParJour' => $seuil, 'margeGardee' => $margeApres === null ? null : ($m['margeMin'] === null || $margeApres >= $m['margeMin'])],
            'attache' => $attache];
    }
    // Les mieux placées d'abord : marge gardée, jours qui collent, attache basse (le plus de place pour progresser).
    usort($out, static function ($a, $b) {
        $ka = [(int) !($a['chiffres']['margeGardee'] ?? true), (int) !$a['jourOk'], $a['attache']['taux'] ?? 50, $a['mecanique']['ordre']];
        $kb = [(int) !($b['chiffres']['margeGardee'] ?? true), (int) !$b['jourOk'], $b['attache']['taux'] ?? 50, $b['mecanique']['ordre']];
        return $ka <=> $kb;
    });
    return ['shop' => (string) $sid, 'levier' => JC_LEVIERS[$levier] + ['cle' => $levier], 'jours' => $wds, 'heureDe' => $hde, 'heureA' => $ha, 'sections' => $sections,
        'fenetre' => ['du' => $du, 'au' => $au, 'joursLus' => $ventes['joursLus'], 'manquants' => $ventes['manquants']],
        'propositions' => $out, 'ticketsLus' => $cout];
}

/* --- 4. les promotions adoptées, et ce qu'elles changent ------------------------ */

function jcPromoLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'shop' => (string) $r['shop_id'], 'mecaniqueId' => $r['mecanique_id'] !== null ? (int) $r['mecanique_id'] : null,
        'levier' => (string) $r['levier'], 'type' => (string) ($r['type'] ?? ''), 'nom' => (string) $r['nom'], 'regle' => (string) ($r['regle'] ?? ''),
        'jours' => array_map('intval', jcListe($r['jours'])), 'heureDe' => (int) $r['heure_de'], 'heureA' => (int) $r['heure_a'],
        'du' => substr((string) $r['du'], 0, 10), 'au' => substr((string) $r['au'], 0, 10),
        'declencheur' => jcListe($r['declencheur'] ?? ''), 'article' => jcListe($r['article'] ?? ''),
        'offre' => (string) ($r['offre'] ?? ''), 'prix' => $r['prix'] !== null ? (float) $r['prix'] : null, 'remisePct' => $r['remise_pct'] !== null ? (float) $r['remise_pct'] : null,
        'canaux' => jcListe($r['canaux'] ?? ''), 'note' => (string) ($r['note'] ?? ''), 'cible' => (string) ($r['cible'] ?? ''),
        'ref' => ['caH' => $r['ref_ca_h'] !== null ? (float) $r['ref_ca_h'] : null, 'tkH' => $r['ref_tk_h'] !== null ? (float) $r['ref_tk_h'] : null, 'jours' => $r['ref_jours'] !== null ? (int) $r['ref_jours'] : null],
        'statut' => (string) $r['statut'], 'creeLe' => $r['cree_le'] !== null ? (string) $r['cree_le'] : null];
}

/** Le CA / h et les clients / h d'un créneau sur une liste de dates, jours ouverts seulement. */
function jcCreneauSur(int $sid, array $wds, int $hde, int $ha, array $dates): array
{
    $heures = svHeuresJours($sid, $dates);
    $ca = 0.0; $tk = 0; $n = 0; $parJour = [];
    foreach ($dates as $d) {
        if (!in_array((int) date('N', strtotime($d)), $wds, true)) { continue; }
        $hs = $heures[$d] ?? null;
        if (!is_array($hs) || $hs === []) { continue; }
        $tot = 0.0; foreach ($hs as $l) { $tot += (float) ($l['ca'] ?? 0); }
        if ($tot <= 0) { continue; }
        $c = 0.0; $t = 0;
        foreach ($hs as $l) { $h = (int) $l['h']; if ($h >= $hde && $h <= $ha) { $c += (float) ($l['ca'] ?? 0); $t += (int) ($l['tickets'] ?? 0); } }
        $nh = $ha - $hde + 1;
        $parJour[$d] = ['caH' => round($c / $nh, 1), 'tkH' => round($t / $nh, 1)];
        $ca += $c; $tk += $t; $n++;
    }
    $nh = ($ha - $hde + 1) * max(1, $n);
    return ['caH' => $n ? round($ca / $nh, 1) : null, 'tkH' => $n ? round($tk / $nh, 1) : null, 'jours' => $n, 'parJour' => $parJour];
}

/** La référence d'un créneau : les 4 semaines avant la date, mêmes jours, mêmes heures. */
function jcReference(int $sid, array $wds, int $hde, int $ha, string $du): array
{
    [$rdu, $rau, $dates] = jcFenetre(4, date('Y-m-d', strtotime($du . ' -1 day')));
    return jcCreneauSur($sid, $wds, $hde, $ha, $dates) + ['du' => $rdu, 'au' => $rau];
}

/** L'effet d'une promotion : le créneau depuis son début face à sa référence gelée, et le verdict. */
function jcEffet(array $p, string $date): array
{
    $fin = min($p['au'], $date, date('Y-m-d'));
    $dates = [];
    for ($d = $p['du']; $d <= $fin; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $dates[] = $d; }
    $dep = $dates === [] ? ['caH' => null, 'tkH' => null, 'jours' => 0, 'parJour' => []] : jcCreneauSur((int) $p['shop'], $p['jours'], $p['heureDe'], $p['heureA'], $dates);
    $refCa = $p['ref']['caH']; $refTk = $p['ref']['tkH'];
    $dCa = ($dep['caH'] !== null && $refCa) ? round(100 * ($dep['caH'] - $refCa) / $refCa, 1) : null;
    $dTk = ($dep['tkH'] !== null && $refTk) ? round(100 * ($dep['tkH'] - $refTk) / $refTk, 1) : null;
    $verdict = 'tot';
    if ($dep['jours'] >= 5 && $dCa !== null) { $verdict = $dCa >= 8 ? 'garder' : ($dCa >= -3 ? 'ajuster' : 'arreter'); }
    $lib = ['tot' => 'trop tôt', 'garder' => 'garder', 'ajuster' => 'ajuster', 'arreter' => 'arrêter ou déplacer'][$verdict];
    return ['caH' => $dep['caH'], 'tkH' => $dep['tkH'], 'joursLus' => $dep['jours'], 'deltaCaPct' => $dCa, 'deltaTkPct' => $dTk,
        'parJour' => $dep['parJour'], 'verdict' => $verdict, 'verdictLib' => $lib];
}

/** GET /promo?shop=&date= — les promotions d'un magasin (ou de tous), avec leur effet. */
function ep_promos(): array
{
    ensureJoursCreux();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!jcDateValide($date)) { $date = date('Y-m-d'); }
    $nomDe = jcMagasins();
    $rows = $shop !== '' ? Db::rows('SELECT * FROM ceo_promo WHERE shop_id = ? ORDER BY du DESC, id DESC', [$shop]) : Db::rows('SELECT * FROM ceo_promo ORDER BY du DESC, id DESC');
    @set_time_limit(120);
    $out = [];
    foreach ($rows as $r) {
        $p = jcPromoLigne($r);
        $p['magasin'] = $nomDe[$p['shop']] ?? $p['shop'];
        $p['effet'] = $p['statut'] === 'brouillon' ? null : jcEffet($p, $date);
        $p['active'] = $p['statut'] === 'en_cours' && $p['du'] <= $date && $p['au'] >= $date;
        $out[] = $p;
    }
    return ['date' => $date, 'promos' => $out, 'leviers' => JC_LEVIERS];
}

/** POST /promo — adopter : la promotion naît avec sa référence gelée. */
function wr_promo_post(): array
{
    ensureJoursCreux();
    $b = body();
    $nomDe = jcMagasins();
    $shop = trim((string) ($b['shop'] ?? ''));
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 160);
    $levier = (string) ($b['levier'] ?? '');
    $wds = array_values(array_filter(array_map('intval', jcListe($b['jours'] ?? [])), static fn ($x) => $x >= 1 && $x <= 7));
    $hde = (int) ($b['heureDe'] ?? -1); $ha = (int) ($b['heureA'] ?? -1);
    $du = (string) ($b['du'] ?? ''); $au = (string) ($b['au'] ?? '');
    if ($shop === '' || !isset($nomDe[$shop]) || $nom === '' || !isset(JC_LEVIERS[$levier]) || $wds === [] || $hde < 0 || $ha < $hde || $ha > 23 || !jcDateValide($du) || !jcDateValide($au) || $au < $du) {
        http_response_code(422); return ['error' => 'magasin, nom, levier, jours, heures et dates sont requis'];
    }
    $ref = jcReference((int) $shop, $wds, $hde, $ha, $du);
    $statut = ($b['statut'] ?? '') === 'brouillon' ? 'brouillon' : 'en_cours';
    $mid = isset($b['mecaniqueId']) && (int) $b['mecaniqueId'] > 0 ? (int) $b['mecaniqueId'] : null;
    Db::exec('INSERT INTO ceo_promo (shop_id, mecanique_id, levier, type, nom, regle, jours, heure_de, heure_a, du, au, declencheur, article, offre, prix, remise_pct, canaux, note, cible, ref_ca_h, ref_tk_h, ref_jours, statut, cree_le, maj_le)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $shop, $mid, $levier, mb_substr(trim((string) ($b['type'] ?? '')), 0, 24), $nom, trim((string) ($b['regle'] ?? '')),
        implode(',', $wds), $hde, $ha, $du, $au,
        json_encode(jcListe($b['declencheur'] ?? []), JSON_UNESCAPED_UNICODE), json_encode(jcListe($b['article'] ?? []), JSON_UNESCAPED_UNICODE),
        mb_substr(trim((string) ($b['offre'] ?? '')), 0, 24),
        isset($b['prix']) && trim((string) $b['prix']) !== '' ? (float) str_replace(',', '.', (string) $b['prix']) : null,
        isset($b['remisePct']) && trim((string) $b['remisePct']) !== '' ? (float) str_replace(',', '.', (string) $b['remisePct']) : null,
        implode(',', jcListe($b['canaux'] ?? [])), trim((string) ($b['note'] ?? '')), mb_substr(trim((string) ($b['cible'] ?? '')), 0, 200),
        $ref['caH'], $ref['tkH'], $ref['jours'], $statut, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd('CEO', 'Jours creux', $nomDe[$shop], 'Promotion adoptée : ' . $nom . ' · ' . jcNomBloc($wds, range($hde, $ha)) . ' · du ' . $du . ' au ' . $au);
    $r = Db::row('SELECT * FROM ceo_promo WHERE id = ?', [$id]);
    $p = jcPromoLigne($r); $p['reference'] = $ref;
    return ['ok' => true, 'promo' => $p];
}

/** PUT /promo/{id} — statut (en_cours, terminee, arretee, brouillon), note, dates, canaux. */
function wr_promo_put(int $id): array
{
    ensureJoursCreux();
    $cur = Db::row('SELECT * FROM ceo_promo WHERE id = ?', [$id]);
    if ($cur === null) { http_response_code(404); return ['error' => 'promotion inconnue']; }
    $b = body(); $champs = [];
    if (isset($b['statut']) && in_array($b['statut'], ['brouillon', 'en_cours', 'terminee', 'arretee'], true)) { $champs['statut'] = $b['statut']; }
    if (array_key_exists('note', $b)) { $champs['note'] = trim((string) $b['note']); }
    if (array_key_exists('cible', $b)) { $champs['cible'] = mb_substr(trim((string) $b['cible']), 0, 200); }
    if (array_key_exists('canaux', $b)) { $champs['canaux'] = implode(',', jcListe($b['canaux'])); }
    if (array_key_exists('nom', $b) && trim((string) $b['nom']) !== '') { $champs['nom'] = mb_substr(trim((string) $b['nom']), 0, 160); }
    if (array_key_exists('regle', $b)) { $champs['regle'] = trim((string) $b['regle']); }
    if (isset($b['au']) && jcDateValide((string) $b['au']) && $b['au'] >= substr((string) $cur['du'], 0, 10)) { $champs['au'] = (string) $b['au']; }
    if ($champs === []) { return ['ok' => true, 'promo' => jcPromoLigne($cur)]; }
    $champs['maj_le'] = date('Y-m-d H:i:s');
    Db::exec('UPDATE ceo_promo SET ' . implode(', ', array_map(static fn ($k) => $k . ' = ?', array_keys($champs))) . ' WHERE id = ?', array_merge(array_values($champs), [$id]));
    if (isset($champs['statut'])) { journalAdd('CEO', 'Jours creux', (string) $cur['nom'], 'Promotion ' . $champs['statut']); }
    $r = Db::row('SELECT * FROM ceo_promo WHERE id = ?', [$id]);
    return ['ok' => true, 'promo' => jcPromoLigne($r)];
}

/** DELETE /promo/{id} — un brouillon s'efface ; une promotion lancée se termine ou s'arrête, elle garde son histoire. */
function wr_promo_suppr(int $id): array
{
    ensureJoursCreux();
    $cur = Db::row('SELECT statut, nom FROM ceo_promo WHERE id = ?', [$id]);
    if ($cur === null) { http_response_code(404); return ['error' => 'promotion inconnue']; }
    if ((string) $cur['statut'] !== 'brouillon') { http_response_code(409); return ['error' => 'une promotion lancée ne s’efface pas : terminez-la ou arrêtez-la']; }
    Db::exec('DELETE FROM ceo_promo WHERE id = ?', [$id]);
    return ['ok' => true];
}

/** GET /exploitation/promos?shop=&date= — le dashboard du magasin : ses promotions en cours ce jour-là, avec leur effet. */
function ep_promos_magasin(): array
{
    ensureJoursCreux();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($shop === '' || !jcDateValide($date)) { http_response_code(400); return ['error' => 'magasin ou date manquant']; }
    $out = ['shop' => $shop, 'date' => $date, 'promos' => []];
    $wd = (int) date('N', strtotime($date));
    foreach (Db::rows("SELECT * FROM ceo_promo WHERE shop_id = ? AND statut = 'en_cours' AND du <= ? AND au >= ? ORDER BY heure_de, id", [$shop, $date, $date]) as $r) {
        $p = jcPromoLigne($r);
        $p['ceJour'] = in_array($wd, $p['jours'], true);
        $p['effet'] = jcEffet($p, $date);
        $p['creneau'] = jcNomBloc($p['jours'], range($p['heureDe'], $p['heureA']));
        $out['promos'][] = $p;
    }
    return $out;
}
