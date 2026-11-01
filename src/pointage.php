<?php

declare(strict_types=1);

/**
 * Le pointage des vendeuses (01/11/2026, Sam : « dans leur application, un clock in / clock out : on pousse le bouton,
 * la caméra s'ouvre pour scanner le QR code de la caisse ; avec le listing des pointages dans le profil, in / out en
 * fonction de leur shift » ; maquette validée : docs/maquettes/pointage-app).
 *
 * Le QR code : une BORNE du magasin l'affiche — une page que l'on ouvre sur la tablette (ou tout écran) du magasin,
 * avec un lien que le cockpit donne (mot_borne, le jeton n'est gardé que haché). Le code change toutes les 30 secondes
 * et ne vaut que pour son magasin : « ATP1:<magasin>:<tranche>:<signature> », signé avec une clé tirée une fois dans
 * le dossier privé (pointage.key). Il vaut 90 secondes (sa tranche et les deux précédentes) : le temps de le viser, ou
 * d'ouvrir l'app depuis l'appareil photo du téléphone, pas celui d'une photo envoyée à quelqu'un resté chez lui. Sous le
 * QR, un code à six chiffres pour une caméra qui ne marche pas.
 *
 * L'app envoie ce qu'elle a lu ; le cockpit décide : un pointage ouvert (arrivée sans départ, moins de 16 h) → le
 * départ ; sinon → l'arrivée, rapprochée du service planifié (src/planning_moi.php) : possible dès 30 minutes avant,
 * en retard au-delà de 5 minutes ; sans service ce jour-là, acceptée et marquée « hors planning ». Deux scans en moins
 * de deux minutes ne font qu'un. Un départ oublié : la personne dit son heure ; le magasin la valide (page franchisé).
 *
 * Les routes : l'app (identité signée de « Mes primes ») — POST /ventes/moi/pointage, GET /ventes/moi/pointages,
 * POST /ventes/moi/pointage/depart ; la borne (son jeton) — GET /pointage/borne ; le cockpit (session) —
 * GET /ventes/equipe/pointages, POST /ventes/equipe/pointages/valider, GET|POST /pointage/bornes,
 * POST /pointage/bornes/retirer.
 */

const PTG_PERIODE = 30;            // le QR change toutes les 30 secondes
const PTG_TRANCHES = 3;            // il vaut sa tranche et les deux précédentes : 90 secondes
const PTG_AVANT = 30;              // l'arrivée se pointe dès 30 minutes avant le service
const PTG_GRACE = 5;               // au-delà de 5 minutes après le début : en retard
const PTG_DOUBLE = 120;            // deux scans en moins de deux minutes ne font qu'un
const PTG_OUVERT_MAX = 16 * 3600;  // une arrivée sans départ depuis plus de 16 h : « départ non pointé »
const PTG_JOURS_MAX = 62;

/* ======================================================================
   Stockage
   ====================================================================== */

function ptgTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS mot_pointage ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'emp INT NOT NULL,'
        . 'shop INT NOT NULL,'                         // le magasin du QR scanné à l'arrivée
        . 'jour DATE NOT NULL,'                        // le jour de l'arrivée
        . 'entree DATETIME NOT NULL,'
        . 'sortie DATETIME NULL,'
        . 'entree_par VARCHAR(10) NOT NULL,'           // qr | code
        . 'sortie_par VARCHAR(10) NULL,'               // qr | code | declare
        . 'sortie_validee_par VARCHAR(80) NULL,'       // un départ déclaré, validé au cockpit
        . 'sortie_validee_le DATETIME NULL,'
        . 'prevu_debut CHAR(5) NULL,'                  // le service planifié, figé à l'arrivée
        . 'prevu_fin CHAR(5) NULL,'
        . 'hors_planning TINYINT NOT NULL DEFAULT 0,'
        . 'cree TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'maj TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'KEY ix_mot_pointage_emp (emp, jour), KEY ix_mot_pointage_shop (shop, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    Db::exec('CREATE TABLE IF NOT EXISTS mot_borne ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop INT NOT NULL,'
        . 'nom VARCHAR(60) NOT NULL,'
        . 'jeton_hash CHAR(64) NOT NULL,'              // sha256 du jeton du lien : le jeton lui-même n'est jamais gardé
        . 'actif TINYINT NOT NULL DEFAULT 1,'
        . 'cree TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'vue_le DATETIME NULL,'
        . 'UNIQUE KEY ux_mot_borne_jeton (jeton_hash), KEY ix_mot_borne_shop (shop)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
}

/** La clé qui signe les QR : 32 octets tirés une fois, dans le dossier privé (0600), comme celle du profil. */
function ptgCle(): string
{
    static $k = null;
    if ($k !== null) { return $k; }
    $f = profilDossier() . '/pointage.key';
    if (!is_file($f)) {
        $tmp = $f . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, base64_encode(random_bytes(32)));
        chmod($tmp, 0600);
        if (!@rename($tmp, $f) && !is_file($f)) { throw new RuntimeException('clé du pointage impossible à écrire'); }
        @unlink($tmp);
    }
    $k = base64_decode(trim((string) file_get_contents($f)), true);
    if ($k === false || strlen($k) !== 32) { throw new RuntimeException('clé du pointage illisible'); }
    return $k;
}

/* ======================================================================
   Le code : QR signé et code à six chiffres, par tranche de 30 secondes
   ====================================================================== */

function ptgTranche(int $t): int { return intdiv($t, PTG_PERIODE); }

/** Le texte du QR d'un magasin pour une tranche. */
function ptgQr(int $shop, int $tranche): string
{
    return 'ATP1:' . $shop . ':' . $tranche . ':' . substr(hash_hmac('sha256', 'qr|' . $shop . '|' . $tranche, ptgCle()), 0, 24);
}

/** Le code à six chiffres d'un magasin pour une tranche (pour une caméra qui ne marche pas). */
function ptgCode(int $shop, int $tranche): string
{
    return str_pad((string) (hexdec(substr(hash_hmac('sha256', 'code|' . $shop . '|' . $tranche, ptgCle()), 0, 12)) % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Ce que l'app a lu : le texte du QR (ou un lien qui le porte, « …?c=ATP1:… »), ou le code à six chiffres. Rend
 * ['shop' => id, 'par' => 'qr'|'code'], ou ['motif' => 'illisible'|'faux'|'expire'].
 */
function ptgLire(string $lu, array $shops, int $maintenant): array
{
    $lu = trim($lu);
    if (preg_match('/[?&]c=([^&#\s]+)/', $lu, $m)) { $lu = rawurldecode($m[1]); }
    $t = ptgTranche($maintenant);
    if (preg_match('/^ATP1:(\d+):(\d+):([0-9a-f]{24})$/', $lu, $m)) {
        [$shop, $tranche] = [(int) $m[1], (int) $m[2]];
        if (!hash_equals(ptgQr($shop, $tranche), $lu)) { return ['motif' => 'faux']; }
        if ($tranche > $t + 1 || $tranche <= $t - PTG_TRANCHES) { return ['motif' => 'expire']; }
        return ['shop' => $shop, 'par' => 'qr'];
    }
    $chiffres = preg_replace('/\D/', '', $lu) ?? '';
    if (strlen($chiffres) === 6 && strlen(preg_replace('/[\d\s-]/', '', $lu) ?? '') === 0) {
        $trouve = [];
        foreach ($shops as $shop) {
            for ($i = 0; $i < PTG_TRANCHES; $i++) {
                if (hash_equals(ptgCode((int) $shop, $t - $i), $chiffres)) { $trouve[(int) $shop] = true; }
            }
        }
        if (count($trouve) === 1) { return ['shop' => (int) array_key_first($trouve), 'par' => 'code']; }
        return ['motif' => 'faux'];        // aucun magasin, ou deux à la fois (si rare qu'on le refuse)
    }
    return ['motif' => 'illisible'];
}

/* ======================================================================
   Pointer : l'arrivée ou le départ, face au service planifié
   ====================================================================== */

/** « 07:30:00 » → 450, null si illisible. */
function ptgMin(string $h): ?int
{
    return preg_match('/^(\d{1,2}):(\d{2})/', $h, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
}

function ptgHm(int $min): string { return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60); }

/**
 * Le service du jour auquel rattacher une arrivée à $maintenant : celui dont la fenêtre (30 minutes avant le début,
 * jusqu'à la fin) contient l'heure ; sinon, s'il en reste un plus tard, « trop tôt » ; sinon null (hors planning).
 *
 * @param array $services les services du jour (planningPersonne) — start_hour, end_hour, shop_name
 * @return array{service:?array, tropTot:?string}
 */
function ptgServiceDuJour(array $services, int $maintenant): array
{
    $m = (int) date('G', $maintenant) * 60 + (int) date('i', $maintenant);
    $plusTard = null;
    foreach ($services as $s) {
        $de = ptgMin((string) $s['start_hour']); $a = ptgMin((string) $s['end_hour']);
        if ($de === null || $a === null) { continue; }
        if ($a <= $de) { $a += 1440; }                 // un service qui passe minuit
        if ($m >= $de - PTG_AVANT && $m <= $a) { return ['service' => $s, 'tropTot' => null]; }
        if ($m < $de - PTG_AVANT && ($plusTard === null || $de < $plusTard)) { $plusTard = $de; }
    }
    return ['service' => null, 'tropTot' => $plusTard !== null ? ptgHm($plusTard - PTG_AVANT) : null];
}

/** Le pointage ouvert de la personne (arrivée sans départ, depuis moins de 16 h), ou null. */
function ptgOuvert(int $emp, int $maintenant): ?array
{
    ptgTables();
    $r = Db::row('SELECT * FROM mot_pointage WHERE emp = ? AND sortie IS NULL AND entree >= ? ORDER BY entree DESC LIMIT 1',
        [$emp, date('Y-m-d H:i:s', $maintenant - PTG_OUVERT_MAX)]);
    return $r ?: null;
}

/**
 * Pointe : le départ si un pointage est ouvert, sinon l'arrivée. Rend le résultat pour l'app, ou ['motif' => …] :
 * trop_tot (avec a_partir_de).
 */
function ptgPointer(int $emp, int $shop, string $par, int $maintenant): array
{
    ptgTables();
    $ouvert = ptgOuvert($emp, $maintenant);
    $noms = function_exists('planNomsMagasins') ? planNomsMagasins() : [];
    if ($ouvert !== null) {
        $depuis = $maintenant - strtotime((string) $ouvert['entree']);
        if ($depuis < PTG_DOUBLE) { return ['sens' => 'deja', 'pointage' => ptgVueLigne($ouvert, $noms, $maintenant)]; }
        Db::exec('UPDATE mot_pointage SET sortie = ?, sortie_par = ? WHERE id = ?', [date('Y-m-d H:i:s', $maintenant), $par, (int) $ouvert['id']]);
        $ouvert['sortie'] = date('Y-m-d H:i:s', $maintenant); $ouvert['sortie_par'] = $par;
        return ['sens' => 'depart', 'pointage' => ptgVueLigne($ouvert, $noms, $maintenant)];
    }
    // Deux scans du départ : le second ne rouvre pas une journée.
    $dernier = Db::row('SELECT * FROM mot_pointage WHERE emp = ? AND sortie IS NOT NULL ORDER BY sortie DESC LIMIT 1', [$emp]);
    if ($dernier && $maintenant - strtotime((string) $dernier['sortie']) < PTG_DOUBLE) {
        return ['sens' => 'deja', 'pointage' => ptgVueLigne($dernier, $noms, $maintenant)];
    }
    $jour = date('Y-m-d', $maintenant);
    $services = function_exists('planningPersonne') ? (planningPersonne($emp, $jour, $jour)['services'] ?? []) : [];
    $choix = ptgServiceDuJour($services, $maintenant);
    if ($choix['service'] === null && $choix['tropTot'] !== null) { return ['motif' => 'trop_tot', 'a_partir_de' => $choix['tropTot']]; }
    $s = $choix['service'];
    Db::exec('INSERT INTO mot_pointage (emp, shop, jour, entree, entree_par, prevu_debut, prevu_fin, hors_planning) VALUES (?,?,?,?,?,?,?,?)',
        [$emp, $shop, $jour, date('Y-m-d H:i:s', $maintenant), $par,
            $s ? substr((string) $s['start_hour'], 0, 5) : null, $s ? substr((string) $s['end_hour'], 0, 5) : null, $s ? 0 : 1]);
    $ligne = Db::row('SELECT * FROM mot_pointage WHERE id = ?', [(int) Db::pdo()->lastInsertId()]);
    return ['sens' => 'arrivee', 'pointage' => ptgVueLigne((array) $ligne, $noms, $maintenant)];
}

/**
 * Un pointage prêt à montrer : heures, retard, durée, état du départ.
 * etat : en_service | fait | non_pointe | a_valider ; arrivee : a_l_heure | retard | hors_planning.
 */
function ptgVueLigne(array $r, array $noms, int $maintenant): array
{
    $e = strtotime((string) $r['entree']);
    $s = $r['sortie'] !== null && $r['sortie'] !== '' ? strtotime((string) $r['sortie']) : null;
    $retard = null;
    if ($r['prevu_debut'] !== null && $r['prevu_debut'] !== '') {
        $d = ptgMin((string) $r['prevu_debut']);
        $min = (int) date('G', $e) * 60 + (int) date('i', $e);
        $retard = $d !== null && $min > $d + PTG_GRACE ? $min - $d : 0;
    }
    $etat = $s !== null ? (($r['sortie_par'] ?? '') === 'declare' && empty($r['sortie_validee_le']) ? 'a_valider' : 'fait')
        : ($maintenant - $e < PTG_OUVERT_MAX ? 'en_service' : 'non_pointe');
    return ['id' => (int) $r['id'], 'jour' => (string) $r['jour'], 'shop' => (int) $r['shop'], 'shop_name' => (string) ($noms[(int) $r['shop']] ?? ''),
        'entree' => date('H:i', $e), 'sortie' => $s !== null ? date('H:i', $s) : null, 'sortie_par' => $r['sortie_par'] ?? null,
        'prevu' => $r['prevu_debut'] ? ['debut' => (string) $r['prevu_debut'], 'fin' => (string) $r['prevu_fin']] : null,
        'arrivee' => !empty($r['hors_planning']) ? 'hors_planning' : ($retard > 0 ? 'retard' : 'a_l_heure'), 'retard_min' => (int) $retard,
        'duree_min' => (int) round((($s ?? ($etat === 'en_service' ? $maintenant : $e)) - $e) / 60), 'etat' => $etat];
}

/* ======================================================================
   La liste : les jours, le planning et les pointages
   ====================================================================== */

/** Les jours du … au … : les services planifiés et les pointages de la personne, les totaux. */
function ptgListe(int $emp, string $du, string $au, int $maintenant): array
{
    ptgTables();
    $noms = function_exists('planNomsMagasins') ? planNomsMagasins() : [];
    $plan = function_exists('planningPersonne') ? (planningPersonne($emp, $du, $au, $maintenant)['services'] ?? []) : [];
    $rows = Db::rows('SELECT * FROM mot_pointage WHERE emp = ? AND jour >= ? AND jour <= ? ORDER BY entree', [$emp, $du, $au]);
    $jours = []; $totP = 0; $totPrevu = 0; $aVerifier = 0;
    for ($d = $du; $d <= $au; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $jours[$d] = ['date' => $d, 'prevus' => [], 'pointages' => []]; }
    foreach ($plan as $s) {
        if (!isset($jours[$s['work_date']])) { continue; }
        $de = ptgMin((string) $s['start_hour']); $a = ptgMin((string) $s['end_hour']);
        if ($de === null || $a === null) { continue; }
        $jours[$s['work_date']]['prevus'][] = ['debut' => ptgHm($de), 'fin' => ptgHm($a), 'shop' => (int) $s['id_shop'], 'shop_name' => (string) ($s['shop_name'] ?? ($noms[(int) $s['id_shop']] ?? ''))];
        $totPrevu += ($a - $de + 1440) % 1440;
    }
    foreach ($rows as $r) {
        $v = ptgVueLigne($r, $noms, $maintenant);
        if (!isset($jours[$v['jour']])) { continue; }
        $jours[$v['jour']]['pointages'][] = $v;
        if ($v['etat'] === 'fait' || $v['etat'] === 'a_valider' || $v['etat'] === 'en_service') { $totP += $v['duree_min']; }
        if ($v['etat'] === 'non_pointe' || $v['etat'] === 'a_valider') { $aVerifier++; }
    }
    $ouvert = ptgOuvert($emp, $maintenant);
    return ['emp' => $emp, 'du' => $du, 'au' => $au, 'jours' => array_values($jours),
        'totaux' => ['pointe_min' => $totP, 'prevu_min' => $totPrevu, 'a_verifier' => $aVerifier],
        'ouvert' => $ouvert ? ptgVueLigne($ouvert, $noms, $maintenant) : null];
}

/** Du … au … contrôlés : [du, au], ou null. */
function ptgPeriode(): ?array
{
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    if (profilDate($du) === null || profilDate($au) === null || $au < $du || (strtotime($au) - strtotime($du)) / 86400 > PTG_JOURS_MAX) { return null; }
    return [$du, $au];
}

/* ======================================================================
   Les routes de l'app
   ====================================================================== */

/** POST /ventes/moi/pointage {lu} — le QR (ou le code) lu : l'arrivée ou le départ. */
function wr_pointage_moi(?int $maintenant = null): array
{
    $maintenant ??= time();
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $b = function_exists('body') ? body() : (json_decode((string) file_get_contents('php://input'), true) ?: []);
    $shops = array_map(static fn ($s) => (int) $s['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
    $lu = ptgLire((string) ($b['lu'] ?? ''), $shops, $maintenant);
    if (isset($lu['motif'])) { http_response_code(422); return ['error' => 'code refusé', 'motif' => $lu['motif']]; }
    $r = ptgPointer($emp, $lu['shop'], $lu['par'], $maintenant);
    if (isset($r['motif'])) { http_response_code(422); return ['error' => 'trop tôt'] + $r; }
    return $r;
}

/** GET /ventes/moi/pointages?du=&au= — les jours, le planning et les pointages de la personne. */
function ep_pointages_moi(?int $maintenant = null): array
{
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $p = ptgPeriode();
    if ($p === null) { http_response_code(400); return ['error' => 'du et au attendus (AAAA-MM-JJ, deux mois au plus)']; }
    return ptgListe($emp, $p[0], $p[1], $maintenant ?? time());
}

/**
 * POST /ventes/moi/pointage/depart {id, heure} — un départ oublié : la personne dit son heure (après l'arrivée, dans
 * les 16 h) ; il attend la validation du magasin.
 */
function wr_pointage_depart(?int $maintenant = null): array
{
    $maintenant ??= time();
    $emp = profilAppelant();
    if ($emp === null) { http_response_code(401); return ['error' => 'jeton d’employé invalide']; }
    $b = function_exists('body') ? body() : (json_decode((string) file_get_contents('php://input'), true) ?: []);
    ptgTables();
    $r = Db::row('SELECT * FROM mot_pointage WHERE id = ? AND emp = ?', [(int) ($b['id'] ?? 0), $emp]);
    if ($r === null) { http_response_code(404); return ['error' => 'pointage introuvable']; }
    if ($r['sortie'] !== null) { http_response_code(422); return ['error' => 'départ déjà pointé', 'motif' => 'deja']; }
    $h = ptgMin((string) ($b['heure'] ?? ''));
    if ($h === null || !preg_match('/^\d{1,2}:\d{2}$/', trim((string) $b['heure'])) || $h >= 1440) { http_response_code(422); return ['error' => 'heure illisible', 'motif' => 'heure']; }
    $e = strtotime((string) $r['entree']);
    $s = strtotime(date('Y-m-d', $e) . ' ' . ptgHm($h));
    if ($s <= $e) { $s += 86400; }                     // après minuit : le lendemain
    if ($s - $e > PTG_OUVERT_MAX || $s > $maintenant) { http_response_code(422); return ['error' => 'heure impossible', 'motif' => 'heure']; }
    Db::exec('UPDATE mot_pointage SET sortie = ?, sortie_par = ? WHERE id = ?', [date('Y-m-d H:i:s', $s), 'declare', (int) $r['id']]);
    $r['sortie'] = date('Y-m-d H:i:s', $s); $r['sortie_par'] = 'declare';
    return ['pointage' => ptgVueLigne($r, function_exists('planNomsMagasins') ? planNomsMagasins() : [], $maintenant)];
}

/* ======================================================================
   La borne : le QR du magasin, sur la tablette
   ====================================================================== */

/** La borne d'un jeton (actif), ou null. */
function ptgBorneDuJeton(string $jeton): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $jeton)) { return null; }
    ptgTables();
    $r = Db::row('SELECT * FROM mot_borne WHERE jeton_hash = ? AND actif = 1', [hash('sha256', $jeton)]);
    return $r ?: null;
}

/** GET /pointage/borne?b= — le QR et le code du moment, pour la page de la borne (sans session : le jeton suffit). */
function ep_pointage_borne(?int $maintenant = null): array
{
    $maintenant ??= time();
    $b = ptgBorneDuJeton((string) ($_GET['b'] ?? ''));
    if ($b === null) { http_response_code(404); return ['error' => 'borne inconnue ou retirée']; }
    if ($b['vue_le'] === null || strtotime((string) $b['vue_le']) < $maintenant - 60) {
        Db::exec('UPDATE mot_borne SET vue_le = ? WHERE id = ?', [date('Y-m-d H:i:s', $maintenant), (int) $b['id']]);
    }
    $t = ptgTranche($maintenant);
    $noms = function_exists('planNomsMagasins') ? planNomsMagasins() : [];
    return ['shop' => (int) $b['shop'], 'magasin' => (string) ($noms[(int) $b['shop']] ?? ''), 'nom' => (string) $b['nom'],
        'qr' => ptgQr((int) $b['shop'], $t), 'code' => ptgCode((int) $b['shop'], $t),
        'reste' => PTG_PERIODE - ($maintenant % PTG_PERIODE), 'periode' => PTG_PERIODE, 'heure' => date('H:i', $maintenant)];
}

/* ======================================================================
   Les routes du cockpit (session) : la page franchisé
   ====================================================================== */

/** GET /pointage/bornes?shop= — les bornes d'un magasin (sans leur jeton). */
function ep_pointage_bornes(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'magasin attendu']; }
    ptgTables();
    return ['shop' => $shop, 'bornes' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'cree' => (string) $r['cree'], 'vue_le' => $r['vue_le']],
        Db::rows('SELECT id, nom, cree, vue_le FROM mot_borne WHERE shop = ? AND actif = 1 ORDER BY id', [$shop]))];
}

/** POST /pointage/bornes {shop, nom} — une borne : rend son jeton, une seule fois (le lien s'en construit). */
function wr_pointage_borne_creer(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 60) ?: 'Tablette du magasin';
    if ($shop <= 0) { http_response_code(400); return ['error' => 'magasin attendu']; }
    ptgTables();
    $jeton = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    Db::exec('INSERT INTO mot_borne (shop, nom, jeton_hash) VALUES (?,?,?)', [$shop, $nom, hash('sha256', $jeton)]);
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Pointage', null, 'Borne de pointage créée : magasin ' . $shop . ' · ' . $nom); }
    return ['shop' => $shop, 'nom' => $nom, 'jeton' => $jeton];
}

/** POST /pointage/bornes/retirer {id} — le lien ne marche plus. */
function wr_pointage_borne_retirer(): array
{
    $b = body();
    ptgTables();
    $n = Db::exec('UPDATE mot_borne SET actif = 0 WHERE id = ? AND actif = 1', [(int) ($b['id'] ?? 0)]);
    if ($n === 0) { http_response_code(404); return ['error' => 'borne introuvable']; }
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Pointage', null, 'Borne de pointage retirée : ' . (int) $b['id']); }
    return ['ok' => true];
}

/**
 * GET /ventes/equipe/pointages?shop=&du=&au= — les pointages d'un magasin (arrivées scannées à ce magasin), le plus
 * récent d'abord, avec le nom de chacun ; et ses bornes.
 */
function ep_pointages_equipe(?int $maintenant = null): array
{
    $maintenant ??= time();
    $p = ptgPeriode();
    if ($p === null) { http_response_code(400); return ['error' => 'du et au attendus (AAAA-MM-JJ, deux mois au plus)']; }
    ptgTables();
    $noms = function_exists('planNomsMagasins') ? planNomsMagasins() : [];
    // Les magasins actifs, pour choisir ; sans « shop », le premier.
    $magasins = [];
    foreach (Db::rows('SELECT id FROM shops WHERE active = 1 ORDER BY id') as $m) { $magasins[] = ['id' => (int) $m['id'], 'nom' => (string) ($noms[(int) $m['id']] ?? ('Magasin ' . $m['id']))]; }
    $shop = (int) ($_GET['shop'] ?? 0) ?: (int) ($magasins[0]['id'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'aucun magasin actif']; }
    $emps = function_exists('venteEmployes') ? venteEmployes() : [];
    $lignes = [];
    foreach (Db::rows('SELECT * FROM mot_pointage WHERE shop = ? AND jour >= ? AND jour <= ? ORDER BY entree DESC', [$shop, $p[0], $p[1]]) as $r) {
        $lignes[] = ptgVueLigne($r, $noms, $maintenant) + ['emp' => (int) $r['emp'], 'nom' => (string) ($emps[(int) $r['emp']]['nom'] ?? ('Employé ' . $r['emp'])),
            'valide_par' => $r['sortie_validee_par'] ?? null];
    }
    $_GET['shop'] = (string) $shop;
    return ['shop' => $shop, 'magasins' => $magasins, 'du' => $p[0], 'au' => $p[1], 'lignes' => $lignes, 'bornes' => ep_pointage_bornes()['bornes']];
}

/** POST /ventes/equipe/pointages/valider {id, refuser?} — un départ déclaré, validé (ou refusé : redevient « non pointé »). */
function wr_pointages_valider(): array
{
    $b = body();
    ptgTables();
    $r = Db::row('SELECT * FROM mot_pointage WHERE id = ?', [(int) ($b['id'] ?? 0)]);
    if ($r === null || ($r['sortie_par'] ?? '') !== 'declare' || !empty($r['sortie_validee_le'])) { http_response_code(404); return ['error' => 'aucun départ déclaré à valider']; }
    if (!empty($b['refuser'])) {
        Db::exec('UPDATE mot_pointage SET sortie = NULL, sortie_par = NULL WHERE id = ?', [(int) $r['id']]);
        if (function_exists('journalAdd')) { journalAdd('CEO', 'Pointage', null, 'Départ déclaré refusé : pointage ' . (int) $r['id'] . ', employé ' . (int) $r['emp']); }
        return ['ok' => true, 'refuse' => true];
    }
    Db::exec('UPDATE mot_pointage SET sortie_validee_par = ?, sortie_validee_le = ? WHERE id = ?', ['cockpit', date('Y-m-d H:i:s'), (int) $r['id']]);
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Pointage', null, 'Départ déclaré validé : pointage ' . (int) $r['id'] . ', employé ' . (int) $r['emp']); }
    return ['ok' => true];
}
