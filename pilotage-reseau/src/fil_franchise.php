<?php
declare(strict_types=1);

/**
 * Le fil du franchisé (19/11/2026) : Franchisés › Suivi des franchisés › Fil du franchisé. Maquettes
 * docs/maquettes/fil-franchise ; retenu par Sam le 19/11 : « A on toggle B » — le fil vertical (A), avec le calendrier
 * (B) en bascule.
 *
 * Tout ce qui s'est passé chez un franchisé, sur un fil : ce que le cockpit sait déjà (les visites et leur score, les
 * points décidés, les rapports envoyés et lus, le client mystère) et ce que le bureau ou un consultant y dépose — une
 * note, des photos, des captures d'écran, un appel —, avec des étiquettes. Trois disent le SENS : « Problème de
 * gestion », « À surveiller », « Bonne action » ; les autres le THÈME. Un problème de gestion ouvre un FIL ; les
 * entrées suivantes le prolongent (« suite de ») jusqu'à celle qui le ferme. Une entrée épinglée part dans le dossier
 * de la prochaine visite : un rappel dans Visites, étape 3 (suDossier, bloc « fil »), qui ne part jamais sur la tablette.
 *
 * Le fil ne se lit et ne s'écrit QUE dans le cockpit (19/11/2026, Sam : « le franchisé ne doit rien voir », « seulement
 * via le cockpit ») : routes de la session du siège (src/acces.php ne les ouvre pas au franchisé), rien vers sa page,
 * ses courriels, son rapport ou la tablette de visite.
 *
 * Tables du portail (préfixe pil_, docs/PORTAILS.md) : pil_fil_entree, pil_fil_piece, pil_fil. Les pièces (photos,
 * captures) vont dans le dossier PRIVÉ, hors du site, sous un nom tiré au hasard, et ne sortent que par
 * GET /fil/pieces/{id}, derrière la session du siège.
 *
 *   GET    /fil/magasins                 — la carte de chaque franchisé : consultant, étiquettes du mois, 12 semaines,
 *                                          fils ouverts, dernier ajout, prochaine visite
 *   GET    /fil?shop=&annee=             — le fil d'un magasin sur l'année : entrées, visites, rapports, client mystère,
 *                                          fils, prochaine visite
 *   POST   /fil/entrees                  {shop, type?, texte, tags[], le?, fil?, ferme?, epingle?, auteur?,
 *                                          pieces[{genre, data}]} — une entrée ; « Problème de gestion » sans fil en
 *                                          ouvre un ; « ferme » sur un fil le résout
 *   PUT    /fil/entrees/{id}             {texte?, tags?, le?, type?, fil?, ferme?, epingle?, pieces?, retirer?}
 *   DELETE /fil/entrees/{id}             — l'entrée, ses pièces et leurs fichiers
 *   POST   /fil/fils/{id}                {titre?, resolu?} — renommer, résoudre ou rouvrir un fil
 *   GET    /fil/pieces/{id}              — le fichier d'une pièce (sorti tel quel, pas en JSON : public/api/index.php)
 *
 * Le passé s'importe (19/11/2026) : src/fil_import.php et bin/fil_import.php posent, par filEntreeCreer, les entrées
 * préparées et relues hors du cockpit (courrier, Drive, agenda, WhatsApp), à leur date, sans rien écrire deux fois.
 *
 * Les demandes du helpdesk se lisent à part, par la route existante GET /helpdesk/magasin?shop= (src/helpdesk.php) :
 * un appel au panel ne doit pas retarder le fil.
 */

const FIL_TAGS = [
    'gestion' => ['nom' => 'Problème de gestion', 'sens' => 'neg'],
    // La communication (19/11/2026, Sam : « rajouter dans le fil : Problème de communication, Mauvaise communication ») :
    // le sens négatif, comme le problème de gestion, mais elles n'ouvrent pas de fil (seul « gestion » en ouvre un).
    'communication' => ['nom' => 'Problème de communication', 'sens' => 'neg'],
    'mauvaisecom' => ['nom' => 'Mauvaise communication', 'sens' => 'neg'],
    'surveiller' => ['nom' => 'À surveiller', 'sens' => 'att'],
    'bonne' => ['nom' => 'Bonne action', 'sens' => 'pos'],
    'equipe' => ['nom' => 'Équipe', 'sens' => ''],
    'produit' => ['nom' => 'Produit', 'sens' => ''],
    'hygiene' => ['nom' => 'Hygiène', 'sens' => ''],
    'finances' => ['nom' => 'Finances', 'sens' => ''],
    'client' => ['nom' => 'Client', 'sens' => ''],
];
const FIL_TYPES = ['note' => 'Note', 'photo' => 'Photo', 'capture' => 'Capture', 'appel' => 'Appel'];
const FIL_GENRES_PIECE = ['photo', 'capture'];
const FIL_PIECES_MAX = 8;
const FIL_PIECE_OCTETS = 6 * 1024 * 1024;
const FIL_TEXTE_MAX = 5000;

/* --- tables ------------------------------------------------------------------ */

function filTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS pil_fil (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        titre VARCHAR(190) NOT NULL,
        ouvert_le DATETIME NOT NULL,
        resolu_le DATETIME NULL,
        resolu_par INT NULL,
        cree_le DATETIME NOT NULL,
        KEY k_shop (shop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pil_fil_entree (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        le DATETIME NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT \'note\',
        texte TEXT NULL,
        tags VARCHAR(255) NOT NULL DEFAULT \'\',
        fil_id INT NULL,
        epingle TINYINT NOT NULL DEFAULT 0,
        auteur VARCHAR(120) NOT NULL DEFAULT \'\',
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        KEY k_shop (shop_id, le),
        KEY k_fil (fil_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS pil_fil_piece (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entree_id INT NOT NULL,
        genre VARCHAR(10) NOT NULL DEFAULT \'photo\',
        fichier VARCHAR(80) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        largeur SMALLINT NULL,
        hauteur SMALLINT NULL,
        octets INT NULL,
        KEY k_entree (entree_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Le dossier des pièces : le dossier privé du cockpit (hors du site, que la livraison ne touche pas), sous fil/. */
function filDossier(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__) . '/var/prive']) as $c) {
        $f = rtrim($c, '/') . '/fil';
        if ((is_dir($f) || @mkdir($f, 0700, true)) && is_writable($f)) { return $d = $f; }
    }
    throw new RuntimeException('aucun dossier privé où ranger les pièces du fil');
}

/* --- règles pures ------------------------------------------------------------ */

/** Les étiquettes connues, sans doublon, dans l'ordre de FIL_TAGS. Accepte une liste ou « a,b ». Fonction pure. */
function filTags(mixed $t): array
{
    $l = is_array($t) ? $t : explode(',', (string) $t);
    $l = array_map(static fn ($x) => trim((string) $x), $l);
    return array_values(array_filter(array_keys(FIL_TAGS), static fn ($k) => in_array($k, $l, true)));
}

/** Une date et heure « AAAA-MM-JJ HH:MM[:SS] » (ou avec T) ramenée à « AAAA-MM-JJ HH:MM:00 » ; null si illisible. */
function filDateHeure(mixed $s): ?string
{
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', trim($s), $m)) { return null; }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000 || (int) $m[1] > 2100) { return null; }
    $h = isset($m[4]) ? min(23, (int) $m[4]) : 12; $i = isset($m[5]) ? min(59, (int) $m[5]) : 0;
    return sprintf('%s-%s-%s %02d:%02d:00', $m[1], $m[2], $m[3], $h, $i);
}

/** Le titre d'un fil tiré du texte qui l'ouvre : la première phrase, 120 caractères au plus. Fonction pure. */
function filTitreDe(string $texte): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', $texte));
    if ($t === '') { return 'Problème de gestion'; }
    if (preg_match('/^(.{12,}?[.!?])(\s|$)/u', $t, $m)) { $t = $m[1]; }
    $t = rtrim($t, ' .');
    return mb_strlen($t) > 120 ? rtrim(mb_substr($t, 0, 119)) . '…' : $t;
}

/** Le type d'une entrée : « appel » s'il est demandé, sinon tiré des pièces (une capture l'emporte). Fonction pure. */
function filTypeDe(mixed $demande, array $genres): string
{
    if ($demande === 'appel') { return 'appel'; }
    if (in_array('capture', $genres, true)) { return 'capture'; }
    return in_array('photo', $genres, true) ? 'photo' : 'note';
}

/** Le code d'une semaine pour la carte : g problème, s à surveiller, b bonne action, n autre, '' rien ; + v si visite. */
function filCodeSemaine(array $tags, int $nEntrees, bool $visite): string
{
    $c = in_array('gestion', $tags, true) ? 'g' : (in_array('surveiller', $tags, true) ? 's' : (in_array('bonne', $tags, true) ? 'b' : ($nEntrees > 0 ? 'n' : '')));
    return $c . ($visite ? 'v' : '');
}

/** Le lundi de la semaine d'une date (AAAA-MM-JJ). */
function filLundi(string $d): string
{
    $t = strtotime(substr($d, 0, 10) . ' 12:00:00');
    return date('Y-m-d', $t - ((int) date('N', $t) - 1) * 86400);
}

/* --- lectures ---------------------------------------------------------------- */

function filMagasins(): array
{
    return function_exists('viMagasins') ? viMagasins() : [];
}

function filMagasinConnu(string $shop): bool
{
    return $shop !== '' && (filMagasins() === [] ? preg_match('/^[\w-]{1,32}$/', $shop) === 1 : isset(filMagasins()[$shop]));
}

/** Une pièce telle que l'écran la lit (l'adresse passe par l'API, jamais le chemin du fichier). */
function filPieceLigne(array $p): array
{
    return ['id' => (int) $p['id'], 'genre' => (string) $p['genre'], 'largeur' => $p['largeur'] !== null ? (int) $p['largeur'] : null,
        'hauteur' => $p['hauteur'] !== null ? (int) $p['hauteur'] : null, 'url' => '/fil/pieces/' . (int) $p['id']];
}

/** Une entrée posée au fil, telle que l'écran la lit. */
function filEntreeLigne(array $r, array $pieces): array
{
    return ['id' => 'n' . (int) $r['id'], 'num' => (int) $r['id'], 'source' => 'fil', 'type' => (string) $r['type'],
        'le' => substr((string) $r['le'], 0, 16), 'texte' => (string) ($r['texte'] ?? ''), 'tags' => filTags((string) $r['tags']),
        'fil' => $r['fil_id'] !== null ? (int) $r['fil_id'] : null, 'epingle' => (bool) (int) $r['epingle'],
        'auteur' => (string) $r['auteur'], 'pieces' => array_map('filPieceLigne', $pieces)];
}

/** Les fils d'un magasin, avec leurs entrées comptées, le premier et le dernier jour. */
function filFils(string $shop): array
{
    filTables();
    $n = [];
    foreach (Db::rows('SELECT fil_id, COUNT(*) n, MIN(le) de, MAX(le) a FROM pil_fil_entree WHERE shop_id = ? AND fil_id IS NOT NULL GROUP BY fil_id', [$shop]) as $x) {
        $n[(int) $x['fil_id']] = $x;
    }
    $out = [];
    foreach (Db::rows('SELECT * FROM pil_fil WHERE shop_id = ? ORDER BY (resolu_le IS NULL) DESC, ouvert_le DESC, id DESC', [$shop]) as $f) {
        $id = (int) $f['id']; $x = $n[$id] ?? null;
        $fin = $f['resolu_le'] !== null ? (string) $f['resolu_le'] : null;
        $de = substr((string) $f['ouvert_le'], 0, 10);
        $out[] = ['id' => $id, 'titre' => (string) $f['titre'], 'ouvert' => $fin === null, 'de' => substr((string) $f['ouvert_le'], 0, 16),
            'resolu' => $fin !== null ? substr($fin, 0, 16) : null, 'resoluPar' => $f['resolu_par'] !== null ? 'n' . (int) $f['resolu_par'] : null,
            'jours' => (int) round((strtotime(substr($fin ?? date('Y-m-d'), 0, 10)) - strtotime($de)) / 86400),
            'entrees' => $x ? (int) $x['n'] : 0, 'dernier' => $x ? substr((string) $x['a'], 0, 16) : null];
    }
    return $out;
}

/** Le nom d'un type de visite (vcTypes), sinon le code. */
function filTypeVisite(string $code): string
{
    static $t = null;
    if ($t === null) { $t = []; try { foreach (function_exists('vcTypes') ? vcTypes() : [] as $x) { $t[(string) $x['code']] = (string) $x['nom']; } } catch (Throwable $e) { /* types muets */ } }
    return $t[$code] ?? ($code === '' || $code === 'reguliere' ? 'Visite régulière' : ucfirst(str_replace('_', ' ', $code)));
}

/** Le score figé d'une visite (con_visite.score), sinon null — jamais recalculé ici : le fil reste léger. */
function filScore(array $v): ?float
{
    $s = !empty($v['score']) ? json_decode((string) $v['score'], true) : null;
    return is_array($s) && isset($s['score']) && is_numeric($s['score']) ? round((float) $s['score'], 1) : null;
}

/**
 * Ce que le cockpit sait déjà du magasin entre deux dates : les visites (score, photos, points décidés), les rapports
 * envoyés, le client mystère. Chaque source qui manque se tait sans casser le fil.
 */
function filSysteme(string $shop, string $de, string $a): array
{
    $out = [];
    try {
        if (function_exists('ensureSuivi')) { ensureSuivi(); } elseif (function_exists('ensureVisites')) { ensureVisites(); }
        $V = Db::rows('SELECT * FROM con_visite WHERE shop_id = ? AND prevu_le BETWEEN ? AND ? ORDER BY prevu_le, id', [$shop, $de, $a]);
    } catch (Throwable $e) { $V = []; }
    $ids = array_map(static fn ($v) => (int) $v['id'], $V);
    $photos = []; $points = [];
    if ($ids !== []) {
        $in = implode(',', $ids);
        try { foreach (Db::rows('SELECT visite_id, COUNT(*) n FROM con_visite_photo WHERE visite_id IN (' . $in . ') GROUP BY visite_id') as $x) { $photos[(int) $x['visite_id']] = (int) $x['n']; } } catch (Throwable $e) { /* photos muettes */ }
        try {
            foreach (Db::rows('SELECT visite_id, titre, priorite, statut, echeance FROM con_visite_action WHERE visite_id IN (' . $in . ') ORDER BY FIELD(priorite, \'P0\', \'P1\', \'P2\'), id') as $x) {
                $points[(int) $x['visite_id']][] = ['titre' => (string) $x['titre'], 'priorite' => (string) $x['priorite'], 'statut' => (string) $x['statut'], 'echeance' => $x['echeance'] !== null ? (string) $x['echeance'] : null];
            }
        } catch (Throwable $e) {
            // Sans FIELD (banc SQLite) : l'ordre d'arrivée.
            try { foreach (Db::rows('SELECT visite_id, titre, priorite, statut, echeance FROM con_visite_action WHERE visite_id IN (' . $in . ') ORDER BY priorite, id') as $x) {
                $points[(int) $x['visite_id']][] = ['titre' => (string) $x['titre'], 'priorite' => (string) $x['priorite'], 'statut' => (string) $x['statut'], 'echeance' => $x['echeance'] !== null ? (string) $x['echeance'] : null];
            } } catch (Throwable $e2) { /* points muets */ }
        }
    }
    $prec = null;
    try {
        $p = Db::row('SELECT score FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND prevu_le < ? AND score IS NOT NULL ORDER BY prevu_le DESC, id DESC LIMIT 1', [$shop, $de]);
        $prec = $p ? filScore($p) : null;
    } catch (Throwable $e) { /* sans score d'avant */ }
    foreach ($V as $v) {
        $id = (int) $v['id']; $st = (string) $v['statut']; $sc = $st === 'terminee' ? filScore($v) : null;
        $an = array_key_exists('annoncee', $v) && $v['annoncee'] !== null ? (bool) (int) $v['annoncee'] : null;
        $out[] = ['id' => 'v' . $id, 'visite' => $id, 'source' => 'visites', 'type' => 'visite', 'le' => (string) $v['prevu_le'] . ' ' . substr((string) ($v['debut_h'] ?? '09:00'), 0, 5),
            'titre' => filTypeVisite((string) ($v['type_code'] ?? '')) . ($an === null ? '' : ($an ? ' · annoncée' : ' · inopinée')), 'statut' => $st,
            'consultant' => (string) $v['consultant_nom'], 'duree' => (int) ($v['duree_min'] ?? 0), 'score' => $sc,
            'delta' => $sc !== null && $prec !== null ? round($sc - $prec, 1) : null, 'photos' => $photos[$id] ?? 0, 'points' => $points[$id] ?? []];
        if ($sc !== null) { $prec = $sc; }
        if (!empty($v['rapport_envoye_le'])) {
            $out[] = ['id' => 'r' . $id, 'visite' => $id, 'source' => 'visites', 'type' => 'rapport', 'le' => substr((string) $v['rapport_envoye_le'], 0, 16),
                'titre' => 'Rapport de la visite du ' . date('d/m', strtotime((string) $v['prevu_le'])), 'par' => (string) ($v['rapport_envoye_par'] ?? ''),
                'lu' => !empty($v['rapport_lu_le']) ? substr((string) $v['rapport_lu_le'], 0, 16) : null, 'nbPoints' => count($points[$id] ?? [])];
        }
    }
    try {
        foreach (Db::rows('SELECT * FROM con_scoring_msp WHERE shop_id = ?', [$shop]) as $m) {
            $d = !empty($m['passage_le']) ? (string) $m['passage_le'] : substr((string) ($m['le'] ?? ''), 0, 10);
            if ($d === '' || $d < $de || $d > $a) { continue; }
            $max = (float) $m['maximum'];
            $out[] = ['id' => 'm' . (int) $m['id'], 'source' => 'mystere', 'type' => 'mystere', 'le' => $d . ' 12:00', 'titre' => 'Passage client mystère',
                'score' => $max > 0 ? (int) round((float) $m['obtenu'] / $max * 100) : null, 'commentaire' => (string) ($m['commentaire'] ?? ''),
                'montre' => !array_key_exists('montrer', $m) || (int) $m['montrer'] === 1];
        }
    } catch (Throwable $e) { /* client mystère muet */ }
    return $out;
}

/** La prochaine visite d'un magasin (planifiée, confirmée ou en cours, aujourd'hui ou plus tard). */
function filProchaine(string $shop): ?array
{
    try {
        $v = Db::row('SELECT id, prevu_le, debut_h, consultant_nom, statut FROM con_visite WHERE shop_id = ? AND prevu_le >= ? AND statut IN (\'planifiee\', \'confirmee\', \'en_cours\') ORDER BY prevu_le, id LIMIT 1', [$shop, date('Y-m-d')]);
    } catch (Throwable $e) { return null; }
    return $v ? ['id' => (int) $v['id'], 'le' => (string) $v['prevu_le'], 'h' => substr((string) $v['debut_h'], 0, 5), 'consultant' => (string) $v['consultant_nom'], 'statut' => (string) $v['statut']] : null;
}

/** GET /fil?shop=&annee= — le fil d'un magasin sur une année. */
function ep_fil(): array
{
    filTables();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    if (!filMagasinConnu($shop)) { http_response_code(422); return ['error' => 'magasin inconnu']; }
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    if ($annee < 2020 || $annee > 2100) { $annee = (int) date('Y'); }
    $de = $annee . '-01-01'; $a = $annee . '-12-31';
    $rows = Db::rows('SELECT * FROM pil_fil_entree WHERE shop_id = ? AND le BETWEEN ? AND ? ORDER BY le DESC, id DESC', [$shop, $de . ' 00:00:00', $a . ' 23:59:59']);
    $pieces = [];
    if ($rows !== []) {
        foreach (Db::rows('SELECT * FROM pil_fil_piece WHERE entree_id IN (' . implode(',', array_map(static fn ($r) => (int) $r['id'], $rows)) . ') ORDER BY id') as $p) { $pieces[(int) $p['entree_id']][] = $p; }
    }
    $E = array_map(static fn ($r) => filEntreeLigne($r, $pieces[(int) $r['id']] ?? []), $rows);
    $E = array_merge($E, filSysteme($shop, $de, $a));
    usort($E, static fn ($x, $y) => strcmp($y['le'], $x['le']) ?: strcmp((string) $y['id'], (string) $x['id']));
    $m = filMagasins()[$shop] ?? null;
    $annees = array_map('intval', array_column(Db::rows('SELECT DISTINCT SUBSTR(le, 1, 4) a FROM pil_fil_entree WHERE shop_id = ?', [$shop]), 'a'));
    $annees = array_values(array_unique(array_merge($annees, [(int) date('Y'), $annee])));
    rsort($annees);
    return ['shop' => $shop, 'magasin' => $m ? ['id' => $shop, 'nom' => $m['nom'], 'court' => $m['court']] : ['id' => $shop, 'nom' => $shop, 'court' => $shop],
        'annee' => $annee, 'annees' => $annees, 'aujourdhui' => date('Y-m-d H:i'), 'tags' => FIL_TAGS, 'types' => FIL_TYPES,
        'entrees' => $E, 'fils' => filFils($shop), 'prochaine' => filProchaine($shop), 'auteur' => filAuteur(null)];
}

/** GET /fil/magasins — la carte de chaque franchisé. */
function ep_fil_magasins(): array
{
    filTables();
    $M = filMagasins();
    $auj = date('Y-m-d');
    $lundi = filLundi($auj);
    $debut12 = date('Y-m-d', strtotime($lundi . ' -11 weeks'));
    $mois = substr($auj, 0, 7) . '-01';
    $E = []; $V = []; $ouverts = []; $dernier = []; $prochaine = []; $consultant = [];
    foreach (Db::rows('SELECT shop_id, le, tags FROM pil_fil_entree WHERE le >= ? ORDER BY le', [min($debut12, $mois) . ' 00:00:00']) as $r) { $E[(string) $r['shop_id']][] = $r; }
    foreach (Db::rows('SELECT shop_id, MAX(le) d FROM pil_fil_entree GROUP BY shop_id') as $r) { $dernier[(string) $r['shop_id']] = substr((string) $r['d'], 0, 16); }
    foreach (Db::rows('SELECT shop_id, COUNT(*) n FROM pil_fil WHERE resolu_le IS NULL GROUP BY shop_id') as $r) { $ouverts[(string) $r['shop_id']] = (int) $r['n']; }
    try {
        foreach (Db::rows('SELECT shop_id, prevu_le, statut, consultant_nom FROM con_visite WHERE prevu_le >= ? ORDER BY prevu_le, id', [$debut12]) as $r) {
            $s = (string) $r['shop_id'];
            if ($r['statut'] !== 'annulee' && $r['prevu_le'] <= $auj) { $V[$s][] = (string) $r['prevu_le']; }
            if ($r['prevu_le'] >= $auj && in_array($r['statut'], ['planifiee', 'confirmee', 'en_cours'], true) && !isset($prochaine[$s])) { $prochaine[$s] = ['le' => (string) $r['prevu_le'], 'consultant' => (string) $r['consultant_nom']]; }
        }
        foreach (Db::rows('SELECT shop_id, consultant_nom FROM con_visite WHERE consultant_nom <> \'\' AND statut <> \'annulee\' ORDER BY prevu_le DESC, id DESC') as $r) {
            if (!isset($consultant[(string) $r['shop_id']])) { $consultant[(string) $r['shop_id']] = (string) $r['consultant_nom']; }
        }
    } catch (Throwable $e) { /* visites muettes */ }
    $out = [];
    foreach ($M as $id => $m) {
        $id = (string) $id;
        $cons = '';
        try { $cons = function_exists('suMagasinRegle') ? (string) suMagasinRegle($id)['responsableNom'] : ''; } catch (Throwable $e) { /* règle muette */ }
        $cons = $cons ?: ($prochaine[$id]['consultant'] ?? '') ?: ($consultant[$id] ?? '');
        $cpt = ['gestion' => 0, 'surveiller' => 0, 'bonne' => 0];
        $sem = [];
        for ($i = 0; $i < 12; $i++) { $l = date('Y-m-d', strtotime($debut12 . ' +' . $i . ' weeks')); $sem[$l] = ['tags' => [], 'n' => 0, 'v' => false]; }
        foreach ($E[$id] ?? [] as $r) {
            $t = filTags((string) $r['tags']); $d = substr((string) $r['le'], 0, 10);
            if ($d >= $mois) { foreach ($cpt as $k => $_) { if (in_array($k, $t, true)) { $cpt[$k]++; } } }
            $l = filLundi($d);
            if (isset($sem[$l])) { $sem[$l]['tags'] = array_merge($sem[$l]['tags'], $t); $sem[$l]['n']++; }
        }
        foreach ($V[$id] ?? [] as $d) { $l = filLundi($d); if (isset($sem[$l])) { $sem[$l]['v'] = true; } }
        $out[] = ['id' => $id, 'nom' => $m['nom'], 'court' => $m['court'], 'consultant' => $cons, 'mois' => $cpt,
            'semaines' => array_values(array_map(static fn ($s) => filCodeSemaine($s['tags'], $s['n'], $s['v']), $sem)),
            'premiereSemaine' => (int) date('W', strtotime($debut12)), 'derniereSemaine' => (int) date('W', strtotime($lundi)),
            'filsOuverts' => $ouverts[$id] ?? 0, 'dernier' => $dernier[$id] ?? null, 'prochaine' => $prochaine[$id] ?? null];
    }
    usort($out, static fn ($x, $y) => strcmp($x['court'], $y['court']));
    return ['magasins' => $out, 'tags' => FIL_TAGS, 'aujourdhui' => $auj, 'mois' => substr($auj, 0, 7)];
}

/** Les entrées épinglées d'un magasin entre deux dates, pour le dossier de la visite (suDossier). */
function filEpinglees(string $shop, string $de, string $a): array
{
    try {
        filTables();
        return Db::rows('SELECT id, le, type, texte, tags FROM pil_fil_entree WHERE shop_id = ? AND epingle = 1 AND le BETWEEN ? AND ? ORDER BY le, id', [$shop, $de . ' 00:00:00', $a . ' 23:59:59']);
    } catch (Throwable $e) { return []; }
}

/** Les lignes du bloc « fil » du dossier d'une visite (au cockpit seulement) : les épinglées depuis la visite faite d'avant. Fonction pure. */
function filLignesDossier(array $rows): array
{
    return array_map(static function (array $r): string {
        $t = filTags((string) $r['tags']);
        $sens = array_values(array_filter($t, static fn ($k) => FIL_TAGS[$k]['sens'] !== ''));
        $txt = trim((string) preg_replace('/\s+/u', ' ', (string) ($r['texte'] ?? '')));
        if ($txt === '') { $txt = FIL_TYPES[$r['type']] ?? 'Note'; }
        return date('d/m', strtotime((string) $r['le'])) . ($sens !== [] ? ' · ' . FIL_TAGS[$sens[0]]['nom'] : '') . ' · ' . (mb_strlen($txt) > 160 ? mb_substr($txt, 0, 159) . '…' : $txt);
    }, $rows);
}

/* --- écritures --------------------------------------------------------------- */

/** L'auteur d'une entrée : celui qui est donné (un consultant choisi), sinon la personne connectée. */
function filAuteur(mixed $donne): string
{
    $a = is_string($donne) ? trim($donne) : '';
    if ($a === '' && function_exists('accesIdentite')) { try { $a = (string) ((accesIdentite() ?? [])['nom'] ?? ''); } catch (Throwable $e) { $a = ''; } }
    return mb_substr($a !== '' ? $a : 'Siège', 0, 120);
}

/**
 * Lit et vérifie les pièces d'un envoi (data-URL JPEG, PNG ou WebP, 6 Mo au plus chacune). Rend [pieces, erreur] :
 * rien n'est écrit tant qu'une seule est refusée.
 */
function filPiecesLues(mixed $pieces): array
{
    if ($pieces === null || $pieces === []) { return [[], null]; }
    if (!is_array($pieces)) { return [[], 'pièces illisibles']; }
    if (count($pieces) > FIL_PIECES_MAX) { return [[], FIL_PIECES_MAX . ' pièces au plus par entrée']; }
    $out = [];
    foreach (array_values($pieces) as $i => $p) {
        $data = is_array($p) ? (string) ($p['data'] ?? '') : '';
        if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return [[], 'pièce ' . ($i + 1) . ' illisible (image attendue)']; }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) < 64) { return [[], 'pièce ' . ($i + 1) . ' illisible']; }
        if (strlen($bin) > FIL_PIECE_OCTETS) { return [[], 'pièce ' . ($i + 1) . ' trop lourde (6 Mo au plus)']; }
        $info = @getimagesizefromstring($bin);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if ($ext === null) { return [[], 'pièce ' . ($i + 1) . ' : format non accepté (JPEG, PNG ou WebP)']; }
        $out[] = ['genre' => in_array($p['genre'] ?? '', FIL_GENRES_PIECE, true) ? (string) $p['genre'] : 'photo', 'bin' => $bin, 'ext' => $ext,
            'mime' => (string) $info['mime'], 'largeur' => (int) ($info[0] ?? 0) ?: null, 'hauteur' => (int) ($info[1] ?? 0) ?: null];
    }
    return [$out, null];
}

/** Range les pièces lues d'une entrée : un fichier au nom tiré au hasard dans le dossier privé, une ligne chacune. */
function filPiecesRanger(int $entree, array $pieces): void
{
    if ($pieces === []) { return; }
    $dos = filDossier();
    foreach ($pieces as $p) {
        $nom = bin2hex(random_bytes(12)) . '.' . $p['ext'];
        if (@file_put_contents($dos . '/' . $nom, $p['bin']) === false) { throw new RuntimeException('écriture de la pièce impossible'); }
        @chmod($dos . '/' . $nom, 0600);
        Db::exec('INSERT INTO pil_fil_piece (entree_id, genre, fichier, mime, largeur, hauteur, octets) VALUES (?,?,?,?,?,?,?)',
            [$entree, $p['genre'], $nom, $p['mime'], $p['largeur'], $p['hauteur'], strlen($p['bin'])]);
    }
}

/** Retire des pièces (lignes et fichiers). */
function filPiecesRetirer(array $rows): void
{
    foreach ($rows as $p) {
        $f = basename((string) $p['fichier']);
        try { if ($f !== '' && is_file(filDossier() . '/' . $f)) { @unlink(filDossier() . '/' . $f); } } catch (Throwable $e) { /* fichier déjà parti */ }
        Db::exec('DELETE FROM pil_fil_piece WHERE id = ?', [(int) $p['id']]);
    }
}

/** Le fil désigné par l'envoi : un fil existant du même magasin, ou null. */
function filDuMagasin(mixed $fil, string $shop): ?array
{
    if ($fil === null || $fil === '' || $fil === false || (int) $fil <= 0) { return null; }
    return Db::row('SELECT * FROM pil_fil WHERE id = ? AND shop_id = ?', [(int) $fil, $shop]);
}

/** Ouvre un fil (un problème de gestion qui n'en suit pas un autre). */
function filOuvrir(string $shop, string $titre, string $le): int
{
    Db::exec('INSERT INTO pil_fil (shop_id, titre, ouvert_le, cree_le) VALUES (?,?,?,?)', [$shop, mb_substr($titre, 0, 190), $le, date('Y-m-d H:i:s')]);
    return (int) Db::pdo()->lastInsertId();
}

/** Ferme un fil par une entrée (sa date), ou le rouvre (null). */
function filFermer(int $fil, ?int $entree, ?string $le): void
{
    Db::exec('UPDATE pil_fil SET resolu_le = ?, resolu_par = ? WHERE id = ?', [$entree !== null ? $le : null, $entree, $fil]);
}

/** POST /fil/entrees — une entrée au fil d'un magasin. */
function wr_fil_entree_creer(): array
{
    $r = filEntreeCreer(body());
    if (isset($r['code'])) { http_response_code((int) $r['code']); unset($r['code']); }
    return $r;
}

/**
 * Pose une entrée au fil : le corps de POST /fil/entrees, partagé avec l'import rétroactif (bin/fil_import.php).
 * Rend l'entrée, ou une erreur avec le code HTTP à poser (« code »).
 */
function filEntreeCreer(array $b): array
{
    filTables();
    $shop = trim((string) ($b['shop'] ?? ''));
    if (!filMagasinConnu($shop)) { return ['code' => 422, 'error' => 'magasin inconnu']; }
    $texte = trim((string) ($b['texte'] ?? ''));
    if (mb_strlen($texte) > FIL_TEXTE_MAX) { return ['code' => 422, 'error' => 'texte trop long (' . FIL_TEXTE_MAX . ' caractères au plus)']; }
    [$pieces, $err] = filPiecesLues($b['pieces'] ?? null);
    if ($err !== null) { return ['code' => 422, 'error' => $err]; }
    if ($texte === '' && $pieces === []) { return ['code' => 422, 'error' => 'une note, une photo ou une capture']; }
    $le = array_key_exists('le', $b) && $b['le'] !== null && $b['le'] !== '' ? filDateHeure($b['le']) : date('Y-m-d H:i:00');
    if ($le === null) { return ['code' => 422, 'error' => 'date illisible']; }
    $tags = filTags($b['tags'] ?? []);
    $type = filTypeDe($b['type'] ?? null, array_column($pieces, 'genre'));
    $fil = filDuMagasin($b['fil'] ?? null, $shop);
    if (($b['fil'] ?? null) !== null && ($b['fil'] ?? '') !== '' && $fil === null) { return ['code' => 422, 'error' => 'fil inconnu pour ce magasin']; }
    $maintenant = date('Y-m-d H:i:s');
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        $filId = $fil !== null ? (int) $fil['id'] : null;
        $ouvert = false;
        if ($filId === null && in_array('gestion', $tags, true)) { $filId = filOuvrir($shop, filTitreDe((string) ($b['titreFil'] ?? '') ?: $texte), $le); $ouvert = true; }
        Db::exec('INSERT INTO pil_fil_entree (shop_id, le, type, texte, tags, fil_id, epingle, auteur, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$shop, $le, $type, $texte, implode(',', $tags), $filId, !empty($b['epingle']) ? 1 : 0, filAuteur($b['auteur'] ?? null), $maintenant, $maintenant]);
        $id = (int) $pdo->lastInsertId();
        filPiecesRanger($id, $pieces);
        if ($fil !== null && !empty($b['ferme'])) { filFermer((int) $fil['id'], $id, $le); }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['code' => 500, 'error' => 'entrée non enregistrée : ' . $e->getMessage()];
    }
    $r = Db::row('SELECT * FROM pil_fil_entree WHERE id = ?', [$id]);
    return ['ok' => true, 'entree' => filEntreeLigne($r, Db::rows('SELECT * FROM pil_fil_piece WHERE entree_id = ? ORDER BY id', [$id])), 'filOuvert' => $ouvert ? $filId : null];
}

/** PUT /fil/entrees/{id} — modifier une entrée (texte, étiquettes, date, fil, épingle, visibilité, pièces). */
function wr_fil_entree_maj(int $id): array
{
    filTables();
    $r = Db::row('SELECT * FROM pil_fil_entree WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'entrée inconnue']; }
    $b = body();
    $shop = (string) $r['shop_id'];
    $set = []; $args = [];
    if (array_key_exists('texte', $b)) {
        $t = trim((string) $b['texte']);
        if (mb_strlen($t) > FIL_TEXTE_MAX) { http_response_code(422); return ['error' => 'texte trop long']; }
        $set[] = 'texte = ?'; $args[] = $t;
    }
    if (array_key_exists('tags', $b)) { $set[] = 'tags = ?'; $args[] = implode(',', filTags($b['tags'])); }
    if (array_key_exists('le', $b)) {
        $le = filDateHeure($b['le']);
        if ($le === null) { http_response_code(422); return ['error' => 'date illisible']; }
        $set[] = 'le = ?'; $args[] = $le;
    }
    if (array_key_exists('type', $b) && isset(FIL_TYPES[(string) $b['type']])) { $set[] = 'type = ?'; $args[] = (string) $b['type']; }
    if (array_key_exists('epingle', $b)) { $set[] = 'epingle = ?'; $args[] = !empty($b['epingle']) ? 1 : 0; }
    $fil = null;
    if (array_key_exists('fil', $b)) {
        $fil = filDuMagasin($b['fil'], $shop);
        if ($b['fil'] !== null && $b['fil'] !== '' && $fil === null) { http_response_code(422); return ['error' => 'fil inconnu pour ce magasin']; }
        $set[] = 'fil_id = ?'; $args[] = $fil !== null ? (int) $fil['id'] : null;
    }
    [$pieces, $err] = filPiecesLues($b['pieces'] ?? null);
    if ($err !== null) { http_response_code(422); return ['error' => $err]; }
    $retirer = array_map('intval', is_array($b['retirer'] ?? null) ? $b['retirer'] : []);
    $deja = Db::rows('SELECT * FROM pil_fil_piece WHERE entree_id = ?', [$id]);
    if (count($deja) - count(array_intersect(array_column($deja, 'id'), $retirer)) + count($pieces) > FIL_PIECES_MAX) { http_response_code(422); return ['error' => FIL_PIECES_MAX . ' pièces au plus par entrée']; }
    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        if ($set !== []) { Db::exec('UPDATE pil_fil_entree SET ' . implode(', ', $set) . ', maj_le = ? WHERE id = ?', array_merge($args, [date('Y-m-d H:i:s'), $id])); }
        filPiecesRanger($id, $pieces);
        filPiecesRetirer(array_values(array_filter($deja, static fn ($p) => in_array((int) $p['id'], $retirer, true))));
        // Le fil fermé par cette entrée : « ferme » le garde fermé (à sa date), sinon il se rouvre si elle le quitte.
        $apres = Db::row('SELECT * FROM pil_fil_entree WHERE id = ?', [$id]);
        $filId = $apres['fil_id'] !== null ? (int) $apres['fil_id'] : null;
        if (array_key_exists('ferme', $b) && $filId !== null) { filFermer($filId, !empty($b['ferme']) ? $id : null, (string) $apres['le']); }
        foreach (Db::rows('SELECT id FROM pil_fil WHERE resolu_par = ? AND (? IS NULL OR id <> ?)', [$id, $filId, $filId]) as $f) { filFermer((int) $f['id'], null, null); }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        return ['error' => 'entrée non modifiée : ' . $e->getMessage()];
    }
    $r = Db::row('SELECT * FROM pil_fil_entree WHERE id = ?', [$id]);
    return ['ok' => true, 'entree' => filEntreeLigne($r, Db::rows('SELECT * FROM pil_fil_piece WHERE entree_id = ? ORDER BY id', [$id]))];
}

/** DELETE /fil/entrees/{id} — l'entrée, ses pièces ; le fil qu'elle fermait se rouvre, un fil vide disparaît. */
function wr_fil_entree_suppr(int $id): array
{
    filTables();
    $r = Db::row('SELECT * FROM pil_fil_entree WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'entrée inconnue']; }
    filPiecesRetirer(Db::rows('SELECT * FROM pil_fil_piece WHERE entree_id = ?', [$id]));
    Db::exec('DELETE FROM pil_fil_entree WHERE id = ?', [$id]);
    foreach (Db::rows('SELECT id FROM pil_fil WHERE resolu_par = ?', [$id]) as $f) { filFermer((int) $f['id'], null, null); }
    $vide = null;
    if ($r['fil_id'] !== null && (int) (Db::row('SELECT COUNT(*) n FROM pil_fil_entree WHERE fil_id = ?', [(int) $r['fil_id']])['n'] ?? 0) === 0) {
        Db::exec('DELETE FROM pil_fil WHERE id = ?', [(int) $r['fil_id']]);
        $vide = (int) $r['fil_id'];
    }
    return ['ok' => true, 'filRetire' => $vide];
}

/** POST /fil/fils/{id} {titre?, resolu?} — renommer un fil, le résoudre (aujourd'hui) ou le rouvrir. */
function wr_fil_fil(int $id): array
{
    filTables();
    $f = Db::row('SELECT * FROM pil_fil WHERE id = ?', [$id]);
    if ($f === null) { http_response_code(404); return ['error' => 'fil inconnu']; }
    $b = body();
    if (array_key_exists('titre', $b)) {
        $t = trim((string) $b['titre']);
        if ($t === '') { http_response_code(422); return ['error' => 'titre vide']; }
        Db::exec('UPDATE pil_fil SET titre = ? WHERE id = ?', [mb_substr($t, 0, 190), $id]);
    }
    if (array_key_exists('resolu', $b)) { Db::exec('UPDATE pil_fil SET resolu_le = ?, resolu_par = NULL WHERE id = ?', [!empty($b['resolu']) ? date('Y-m-d H:i:00') : null, $id]); }
    $fils = array_values(array_filter(filFils((string) $f['shop_id']), static fn ($x) => $x['id'] === $id));
    return ['ok' => true, 'fil' => $fils[0] ?? null];
}

/** GET /fil/pieces/{id} — le fichier d'une pièce, tel quel (appelé par public/api/index.php, qui sort ensuite). */
function filPieceSortie(int $id): void
{
    filTables();
    $p = Db::row('SELECT * FROM pil_fil_piece WHERE id = ?', [$id]);
    $f = $p !== null ? filDossier() . '/' . basename((string) $p['fichier']) : '';
    if ($p === null || !is_file($f)) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'pièce introuvable']); exit; }
    header('Content-Type: ' . (in_array($p['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $p['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; sandbox");
    readfile($f);
    exit;
}

/** Les routes /fil (session du siège ; le franchisé n'y a pas accès, src/acces.php). */
function filRoute(string $method, string $path): mixed
{
    if ($method === 'GET' && $path === '/fil') { return ep_fil(); }
    if ($method === 'GET' && $path === '/fil/magasins') { return ep_fil_magasins(); }
    if ($method === 'POST' && $path === '/fil/entrees') { return wr_fil_entree_creer(); }
    if (preg_match('#^/fil/entrees/(\d+)$#', $path, $m)) {
        if ($method === 'PUT') { return wr_fil_entree_maj((int) $m[1]); }
        if ($method === 'DELETE') { return wr_fil_entree_suppr((int) $m[1]); }
    }
    if ($method === 'POST' && preg_match('#^/fil/fils/(\d+)$#', $path, $m)) { return wr_fil_fil((int) $m[1]); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
