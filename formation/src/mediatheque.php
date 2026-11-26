<?php
declare(strict_types=1);

/*
 * MODULE FORMATION — LA MÉDIATHÈQUE : LES VIDÉOS FILMÉES (26/11/2026).
 *
 * Sam : « vidéo d'utilisation du dashboard, vidéo de production… faire une section spéciale dans le rail de formation ».
 * Décisions du 26/11 : cinq collections — Utilisation du dashboard (les vidéos tournées sur écran, registre
 * panel/public/dashboard/videos/videos.json, rien ici), Production, Vente et comptoir, Hygiène et sécurité, Ouverture et
 * fermeture ; une vidéo filmée entre par le portail de formation (Médiathèque › Gérer les vidéos), en FICHIER (un MP4
 * filmé au téléphone, gardé sur le serveur) ou en LIEN (YouTube, Vimeo, Google Drive, ou l'adresse d'un fichier vidéo).
 *
 *  for_mediatheque_video  une vidéo : collection, catégorie (texte libre : « Viennoiseries », « Caisse »…), titre et
 *                         résumé FR / NL, source (fichier · lien), fichier (uploads/formation/videos/…), lien (https),
 *                         affiche (JPEG), durée, publiée, ordre, qui et quand
 *
 * L'envoi d'un fichier se fait en morceaux de 4 Mo au plus (aucune limite d'envoi de PHP à régler sur le serveur) :
 *   POST /formation/mediatheque/envoi {nom, taille}             → {jeton}           un fichier temporaire, hors du site
 *   PUT  /formation/mediatheque/envoi/{jeton}?depuis=N  (octets) → {recu}            chaque morceau, à sa place
 *   POST /formation/mediatheque/envoi/{jeton}/fin                → {fichier, taille}  vérifié (MP4, MOV ou WebM, par sa
 *                                                                                    signature), rangé dans public/uploads
 * Le fichier est servi tel quel par Apache (requêtes partielles : on avance dans la vidéo), sous uploads/formation/videos/.
 *
 * Lecture : GET /formation/mediatheque/liste (les vidéos publiées ; ouverte aux magasins, ACCES_FRANCHISE), lue par le
 * catalogue de formation (#/videos). Le reste est au siège : GET /formation/mediatheque (tout, brouillons compris),
 * POST /formation/mediatheque/videos, PUT et DELETE /formation/mediatheque/videos/{id}.
 */

const MT_COLLECTIONS = [
    'production' => ['fr' => 'Production', 'nl' => 'Productie'],
    'vente' => ['fr' => 'Vente et comptoir', 'nl' => 'Verkoop en toonbank'],
    'hygiene' => ['fr' => 'Hygiène et sécurité', 'nl' => 'Hygiëne en veiligheid'],
    'ouverture' => ['fr' => 'Ouverture et fermeture', 'nl' => 'Opening en sluiting'],
];
const MT_MORCEAU_MAX = 4194304;        // 4 Mo par morceau
const MT_FICHIER_MAX = 2147483648;     // 2 Go par vidéo
const MT_AFFICHE_MAX = 3145728;        // 3 Mo pour l'affiche (JPEG)

function ensureMediatheque(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS for_mediatheque_video ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'collection VARCHAR(16) NOT NULL,'
        . "categorie VARCHAR(80) NOT NULL DEFAULT '',"
        . 'titre VARCHAR(160) NOT NULL,'
        . "titre_nl VARCHAR(160) NOT NULL DEFAULT '',"
        . 'resume TEXT NULL,'
        . 'resume_nl TEXT NULL,'
        . "source VARCHAR(8) NOT NULL DEFAULT 'fichier',"   // fichier | lien
        . 'fichier VARCHAR(160) NULL,'                      // uploads/formation/videos/<nom>
        . 'taille BIGINT NULL,'
        . 'url VARCHAR(500) NULL,'                          // un lien https (source = lien)
        . 'affiche VARCHAR(160) NULL,'                      // uploads/formation/videos/<nom>.jpg
        . 'duree_s INT NULL,'
        . 'publie TINYINT(1) NOT NULL DEFAULT 1,'
        . 'ordre INT NOT NULL DEFAULT 0,'
        . 'cree_le DATETIME NOT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'KEY collection (collection, categorie)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le dossier public des vidéos (servi par Apache) et son adresse relative à la racine du site. */
function mtDossier(): string { return (function_exists('depotPublic') ? depotPublic() : dirname(__DIR__, 2) . '/public') . '/uploads/formation/videos'; }
const MT_ADRESSE = 'uploads/formation/videos/';
/** Le dossier des envois en cours (hors du site : un morceau n'est jamais servi). */
function mtTemp(): string { return sys_get_temp_dir() . '/cockpit-mediatheque'; }

/**
 * Un lien de vidéo reconnu : {type, id} — youtube, vimeo, drive (l'écran construit le lecteur depuis l'identifiant,
 * jamais depuis l'adresse brute), ou fichier (une adresse https qui finit par .mp4, .webm, .mov, .m4v). Null sinon.
 */
function mtLien(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 500 || !str_starts_with($url, 'https://') || preg_match('/[\s"<>\']/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) { return null; }
    $h = strtolower((string) parse_url($url, PHP_URL_HOST));
    $p = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $m = [];
    if (in_array($h, ['www.youtube.com', 'youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
        if (is_string($q['v'] ?? null) && preg_match('/^[\w-]{11}$/', $q['v'])) { return ['type' => 'youtube', 'id' => $q['v']]; }
        if (preg_match('#^/(?:shorts|embed|live)/([\w-]{11})#', $p, $m)) { return ['type' => 'youtube', 'id' => $m[1]]; }
        return null;
    }
    if ($h === 'youtu.be' && preg_match('#^/([\w-]{11})#', $p, $m)) { return ['type' => 'youtube', 'id' => $m[1]]; }
    if (in_array($h, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{5,12})(?:/([0-9a-f]{6,20}))?#', $p, $m)) {
        return ['type' => 'vimeo', 'id' => $m[1] . (isset($m[2]) && $m[2] !== '' ? '/' . $m[2] : '')];
    }
    if ($h === 'drive.google.com' && preg_match('#/file/d/([\w-]{10,80})#', $p, $m)) { return ['type' => 'drive', 'id' => $m[1]]; }
    if ($h === 'drive.google.com' && is_string($q['id'] ?? null) && preg_match('/^[\w-]{10,80}$/', $q['id'])) { return ['type' => 'drive', 'id' => $q['id']]; }
    if (preg_match('/\.(mp4|m4v|webm|mov)$/i', $p)) { return ['type' => 'fichier', 'id' => $url]; }
    return null;
}

/** La signature d'un fichier vidéo : mp4 (MP4, M4V, MOV : « ftyp » à l'octet 4), webm, ou null. */
function mtSignature(string $debut): ?string
{
    if (strlen($debut) >= 12 && substr($debut, 4, 4) === 'ftyp') { return substr($debut, 8, 2) === 'qt' ? 'mov' : 'mp4'; }
    if (str_starts_with($debut, "\x1A\x45\xDF\xA3")) { return 'webm'; }
    return null;
}

function mtQui(): string { return function_exists('fcQui') ? fcQui('siege')['nom'] : 'Siège'; }

function mtSiege(): bool
{
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    return is_array($id) && ($id['role'] ?? '') === 'siege';
}

function mtRoute(string $method, string $path): ?array
{
    if ($path !== '/formation/mediatheque' && !str_starts_with($path, '/formation/mediatheque/')) { return null; }
    if ($method === 'GET' && $path === '/formation/mediatheque/liste') { ensureMediatheque(); return ep_mt_liste(false); }
    if (!mtSiege()) { return foRefus(403, 'réservé au siège'); }
    ensureMediatheque();
    $m = [];
    if ($method === 'GET' && $path === '/formation/mediatheque') { return ep_mt_liste(true); }
    if ($method === 'POST' && $path === '/formation/mediatheque/videos') { return wr_mt_video(null); }
    if (preg_match('#^/formation/mediatheque/videos/(\d+)$#', $path, $m)) {
        if ($method === 'PUT') { return wr_mt_video((int) $m[1]); }
        if ($method === 'DELETE') { return wr_mt_supprimer((int) $m[1]); }
    }
    if ($method === 'POST' && $path === '/formation/mediatheque/envoi') { return wr_mt_envoi_debut(); }
    if ($method === 'PUT' && preg_match('#^/formation/mediatheque/envoi/([0-9a-f]{32})$#', $path, $m)) { return wr_mt_envoi_morceau($m[1]); }
    if ($method === 'POST' && preg_match('#^/formation/mediatheque/envoi/([0-9a-f]{32})/fin$#', $path, $m)) { return wr_mt_envoi_fin($m[1]); }
    return null;
}

/** Une vidéo telle que l'API la rend. */
function mtSortie(array $r): array
{
    $lien = $r['source'] === 'lien' && $r['url'] ? mtLien((string) $r['url']) : null;
    $d = $r['duree_s'] !== null ? (int) $r['duree_s'] : null;
    return ['id' => (int) $r['id'], 'collection' => (string) $r['collection'], 'categorie' => (string) $r['categorie'],
        'titre' => (string) $r['titre'], 'titreNl' => (string) $r['titre_nl'], 'resume' => (string) ($r['resume'] ?? ''), 'resumeNl' => (string) ($r['resume_nl'] ?? ''),
        'source' => (string) $r['source'], 'fichier' => $r['fichier'] ? (string) $r['fichier'] : null, 'taille' => $r['taille'] !== null ? (int) $r['taille'] : null,
        'url' => $r['url'] ? (string) $r['url'] : null, 'lien' => $lien, 'affiche' => $r['affiche'] ? (string) $r['affiche'] : null,
        'dureeS' => $d, 'duree' => $d !== null ? mtDuree($d) : null, 'publie' => (bool) $r['publie'], 'ordre' => (int) $r['ordre'],
        'creeLe' => (string) $r['cree_le'], 'majLe' => (string) $r['maj_le'], 'par' => (string) ($r['par'] ?? '')];
}

/** « 45 s », « 2 min 05 », « 1 h 02 » (comme les vidéos du dashboard). */
function mtDuree(int $s): string
{
    if ($s < 60) { return $s . ' s'; }
    if ($s < 3600) { return intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT); }
    return intdiv($s, 3600) . ' h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
}

/** GET /formation/mediatheque[/liste] — les collections et leurs vidéos (publiées seulement pour /liste). */
function ep_mt_liste(bool $tout): array
{
    $rows = Db::rows('SELECT * FROM for_mediatheque_video' . ($tout ? '' : ' WHERE publie = 1') . ' ORDER BY collection, categorie, ordre, id');
    $cols = [];
    foreach (MT_COLLECTIONS as $k => $n) { $cols[] = ['cle' => $k, 'nom' => $n]; }
    return ['ok' => true, 'collections' => $cols, 'adresse' => MT_ADRESSE, 'videos' => array_map('mtSortie', $rows)];
}

/**
 * POST /formation/mediatheque/videos et PUT …/videos/{id} — {collection, categorie, titre, titreNl?, resume?, resumeNl?,
 * source: fichier | lien, fichier? (rendu par l'envoi), url?, affiche? (data:image/jpeg;base64,…), dureeS?, publie?, ordre?}.
 * En modification, une clé absente reste inchangée.
 */
function wr_mt_video(?int $id): array
{
    $b = body();
    $av = null;
    if ($id !== null) {
        $av = Db::row('SELECT * FROM for_mediatheque_video WHERE id = ?', [$id]);
        if ($av === null) { return foRefus(404, 'vidéo inconnue'); }
    }
    $v = fn (string $k, mixed $defaut) => array_key_exists($k, $b) ? $b[$k] : $defaut;
    $col = (string) $v('collection', $av['collection'] ?? '');
    if (!isset(MT_COLLECTIONS[$col])) { return foRefus(422, 'collection : ' . implode(', ', array_keys(MT_COLLECTIONS))); }
    $titre = trim(mb_substr((string) $v('titre', $av['titre'] ?? ''), 0, 160));
    if ($titre === '') { return foRefus(422, 'le titre manque'); }
    $cat = trim(mb_substr((string) $v('categorie', $av['categorie'] ?? ''), 0, 80));
    $source = (string) $v('source', $av['source'] ?? 'fichier');
    if (!in_array($source, ['fichier', 'lien'], true)) { return foRefus(422, 'source : fichier ou lien'); }
    $fichier = $av['fichier'] ?? null; $taille = $av['taille'] ?? null; $url = $av['url'] ?? null;
    if ($source === 'lien') {
        $url = trim((string) $v('url', $url ?? ''));
        if (mtLien($url) === null) { return foRefus(422, 'lien non reconnu : YouTube, Vimeo, Google Drive, ou l’adresse https d’un fichier .mp4, .webm, .mov'); }
    } else {
        if (array_key_exists('fichier', $b)) {
            $f = basename((string) $b['fichier']);
            if (!preg_match('/^\d{8}-[0-9a-f]{12}\.(mp4|webm|mov)$/', $f) || !is_file(mtDossier() . '/' . $f)) { return foRefus(422, 'fichier vidéo introuvable : envoyez-le d’abord'); }
            $fichier = MT_ADRESSE . $f; $taille = filesize(mtDossier() . '/' . $f) ?: null;
        }
        if (!$fichier) { return foRefus(422, 'le fichier vidéo manque'); }
        $url = null;
    }
    $affiche = $av['affiche'] ?? null;
    if (is_string($b['affiche'] ?? null) && str_starts_with($b['affiche'], 'data:')) {
        $a = mtAffiche($b['affiche']);
        if (isset($a['erreur'])) { return foRefus(422, $a['erreur']); }
        $affiche = $a['affiche'];
    } elseif (array_key_exists('affiche', $b) && $b['affiche'] === null) { $affiche = null; }
    $duree = $v('dureeS', $av['duree_s'] ?? null);
    $duree = is_numeric($duree) && $duree >= 0 && $duree < 86400 ? (int) round((float) $duree) : null;
    $ordre = (int) $v('ordre', $av['ordre'] ?? 0);
    $p = [$col, $cat, $titre, trim(mb_substr((string) $v('titreNl', $av['titre_nl'] ?? ''), 0, 160)),
        trim(mb_substr((string) $v('resume', $av['resume'] ?? ''), 0, 2000)), trim(mb_substr((string) $v('resumeNl', $av['resume_nl'] ?? ''), 0, 2000)),
        $source, $fichier, $taille, $url, $affiche, $duree, $v('publie', $av['publie'] ?? true) ? 1 : 0, $ordre, mtQui()];
    if ($id === null) {
        Db::exec('INSERT INTO for_mediatheque_video (collection, categorie, titre, titre_nl, resume, resume_nl, source, fichier, taille, url, affiche, duree_s, publie, ordre, par, cree_le, maj_le)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())', $p);
        $id = (int) Db::pdo()->lastInsertId();
    } else {
        Db::exec('UPDATE for_mediatheque_video SET collection = ?, categorie = ?, titre = ?, titre_nl = ?, resume = ?, resume_nl = ?, source = ?, fichier = ?, taille = ?, url = ?,'
            . ' affiche = ?, duree_s = ?, publie = ?, ordre = ?, par = ?, maj_le = NOW() WHERE id = ?', [...$p, $id]);
        // L'ancien fichier ou l'ancienne affiche remplacés : retirés du disque.
        foreach (['fichier' => $fichier, 'affiche' => $affiche] as $k => $neuf) {
            if (!empty($av[$k]) && $av[$k] !== $neuf) { mtEffacer((string) $av[$k]); }
        }
    }
    if (function_exists('journalAdd')) { journalAdd(mtQui(), 'Formation', 'Médiathèque', ($av ? 'Vidéo modifiée : ' : 'Vidéo ajoutée : ') . $titre . ' (' . MT_COLLECTIONS[$col]['fr'] . ')'); }
    return ['ok' => true, 'video' => mtSortie((array) Db::row('SELECT * FROM for_mediatheque_video WHERE id = ?', [$id]))];
}

/** DELETE /formation/mediatheque/videos/{id} — la vidéo, son fichier et son affiche. */
function wr_mt_supprimer(int $id): array
{
    $r = Db::row('SELECT * FROM for_mediatheque_video WHERE id = ?', [$id]);
    if ($r === null) { return foRefus(404, 'vidéo inconnue'); }
    Db::exec('DELETE FROM for_mediatheque_video WHERE id = ?', [$id]);
    foreach (['fichier', 'affiche'] as $k) { if (!empty($r[$k])) { mtEffacer((string) $r[$k]); } }
    if (function_exists('journalAdd')) { journalAdd(mtQui(), 'Formation', 'Médiathèque', 'Vidéo supprimée : ' . $r['titre']); }
    return ['ok' => true, 'id' => $id];
}

/** Retire un fichier de la médiathèque (seulement sous uploads/formation/videos/). */
function mtEffacer(string $chemin): void
{
    if (!str_starts_with($chemin, MT_ADRESSE)) { return; }
    $f = mtDossier() . '/' . basename($chemin);
    if (is_file($f)) { @unlink($f); }
}

/** L'affiche : une image en data-URL, réécrite en JPEG (GD : ni métadonnées, ni position), 1280 px au plus. */
function mtAffiche(string $data): array
{
    if (preg_match('#^data:image/(?:jpeg|png|webp);base64,#', $data, $m) !== 1) { return ['erreur' => 'affiche : une image JPEG, PNG ou WebP']; }
    $brut = base64_decode(substr($data, strlen($m[0])), true);
    if ($brut === false || $brut === '' || strlen($brut) > MT_AFFICHE_MAX) { return ['erreur' => 'affiche illisible ou trop lourde (3 Mo au plus)']; }
    $ext = str_starts_with($brut, "\xFF\xD8\xFF") ? 'jpg' : (str_starts_with($brut, "\x89PNG\r\n\x1A\n") ? 'png' : (substr($brut, 0, 4) === 'RIFF' && substr($brut, 8, 4) === 'WEBP' ? 'webp' : ''));
    if ($ext === '') { return ['erreur' => 'l’affiche n’est pas une image']; }
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($brut)) !== false) {
        $w = imagesx($im); $h = imagesy($im); $k = min(1, 1280 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        imagecopyresampled($out, $im, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start(); imagejpeg($out, null, 80); $brut = (string) ob_get_clean();
        imagedestroy($im); imagedestroy($out); $ext = 'jpg';
    }
    $dir = mtDossier();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return ['erreur' => 'dossier des vidéos impossible à créer']; }
    $nom = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $nom, $brut) === false) { return ['erreur' => 'affiche impossible à enregistrer']; }
    return ['affiche' => MT_ADRESSE . $nom];
}

/* ═══ L'envoi d'un fichier, en morceaux ═══════════════════════════════════════════════════════════ */

/** POST /formation/mediatheque/envoi {nom, taille} — ouvre un envoi ; rend son jeton. */
function wr_mt_envoi_debut(): array
{
    $b = body();
    $taille = (int) ($b['taille'] ?? 0);
    if ($taille <= 0) { return foRefus(422, 'taille du fichier attendue'); }
    if ($taille > MT_FICHIER_MAX) { return foRefus(413, 'vidéo trop lourde (2 Go au plus)'); }
    if (!preg_match('/\.(mp4|m4v|mov|webm)$/i', (string) ($b['nom'] ?? ''))) { return foRefus(422, 'une vidéo MP4, MOV ou WebM'); }
    $dir = mtTemp();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return foRefus(500, 'dossier temporaire impossible à créer'); }
    // Les envois abandonnés depuis plus d'un jour s'effacent.
    foreach (glob($dir . '/*') ?: [] as $f) { if (@filemtime($f) < time() - 86400) { @unlink($f); } }
    $jeton = bin2hex(random_bytes(16));
    file_put_contents("$dir/$jeton.json", json_encode(['taille' => $taille, 'nom' => mb_substr((string) $b['nom'], 0, 120)]));
    touch("$dir/$jeton.part");
    return ['ok' => true, 'jeton' => $jeton, 'morceau' => MT_MORCEAU_MAX];
}

/** PUT /formation/mediatheque/envoi/{jeton}?depuis=N — un morceau (le corps brut), écrit à la suite s'il tombe à sa place. */
function wr_mt_envoi_morceau(string $jeton): array
{
    $dir = mtTemp();
    $meta = json_decode((string) @file_get_contents("$dir/$jeton.json"), true);
    if (!is_array($meta) || !is_file("$dir/$jeton.part")) { return foRefus(404, 'envoi inconnu ou expiré'); }
    clearstatcache(true, "$dir/$jeton.part");
    $recu = (int) filesize("$dir/$jeton.part");
    $depuis = (int) ($_GET['depuis'] ?? -1);
    // Un morceau déjà reçu (renvoyé après une coupure) : on dit où l'on en est, sans rien réécrire.
    if ($depuis !== $recu) { return ['ok' => true, 'recu' => $recu, 'decale' => true]; }
    $in = fopen((string) ($GLOBALS['MT_ENTREE'] ?? 'php://input'), 'rb');   // MT_ENTREE : les tests
    $out = fopen("$dir/$jeton.part", 'ab');
    if ($in === false || $out === false) { return foRefus(500, 'écriture impossible'); }
    $n = stream_copy_to_stream($in, $out, MT_MORCEAU_MAX + 1);
    fclose($in); fclose($out);
    if ($n === false || $n > MT_MORCEAU_MAX || $recu + $n > (int) $meta['taille']) {
        @unlink("$dir/$jeton.part"); @unlink("$dir/$jeton.json");
        return foRefus(413, 'morceau trop gros : envoi annulé');
    }
    return ['ok' => true, 'recu' => $recu + $n];
}

/** POST /formation/mediatheque/envoi/{jeton}/fin — le fichier complet, vérifié, rangé dans uploads/formation/videos/. */
function wr_mt_envoi_fin(string $jeton): array
{
    $dir = mtTemp();
    $meta = json_decode((string) @file_get_contents("$dir/$jeton.json"), true);
    $part = "$dir/$jeton.part";
    if (!is_array($meta) || !is_file($part)) { return foRefus(404, 'envoi inconnu ou expiré'); }
    clearstatcache(true, $part);
    $taille = (int) filesize($part);
    if ($taille !== (int) $meta['taille']) { return foRefus(409, 'fichier incomplet : ' . $taille . ' octets sur ' . (int) $meta['taille']); }
    $ext = mtSignature((string) file_get_contents($part, false, null, 0, 16));
    if ($ext === null) { @unlink($part); @unlink("$dir/$jeton.json"); return foRefus(422, 'ce fichier n’est pas une vidéo MP4, MOV ou WebM'); }
    $dest = mtDossier();
    if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) { return foRefus(500, 'dossier des vidéos impossible à créer'); }
    // Un MOV (iPhone) est rangé en .mp4 : même conteneur, et Chrome le lit sous ce type (video/mp4) mieux que video/quicktime.
    $nom = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . ($ext === 'mov' ? 'mp4' : $ext);
    if (!@rename($part, "$dest/$nom") && !(@copy($part, "$dest/$nom") && @unlink($part))) { return foRefus(500, 'vidéo impossible à ranger'); }
    @chmod("$dest/$nom", 0644);
    @unlink("$dir/$jeton.json");
    return ['ok' => true, 'fichier' => $nom, 'adresse' => MT_ADRESSE . $nom, 'taille' => $taille];
}
