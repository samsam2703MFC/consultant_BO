<?php
declare(strict_types=1);

/**
 * La photo des échantillons reçus (portail Commercial, 19/11/2026, demande : « rajouter faire une photo des échantillons
 * reçus par le commercial »).
 *
 * Quand le commercial reçoit l'échantillon du magasin (retrait ou livraison), il le photographie : depuis l'app mobile
 * (l'appareil photo s'ouvre) ou depuis la version bureau (Échantillons). La photo garde la trace de ce que le magasin a
 * préparé ; l'échantillon passe à « reçu ». Les photos arrivent en data-URL (réduites par le navigateur), sont vérifiées
 * (JPEG, PNG ou WebP, 6 Mo au plus) et rangées dans le dossier privé du cockpit, hors du site : elles ne se lisent que
 * par l'API (GET /commercial/echantillons/photos/{id}), comme les pièces du fil du franchisé. Table : sm_echantillon_photo.
 */

const SM_PHOTO_OCTETS = 6 * 1024 * 1024;
const SM_PHOTOS_MAX = 6;

function smTablesPhotos(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS sm_echantillon_photo ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, echantillon_id INT NOT NULL, fichier VARCHAR(80) NOT NULL, mime VARCHAR(40) NOT NULL,'
        . 'largeur SMALLINT NULL, hauteur SMALLINT NULL, octets INT NULL, prise_le DATETIME NOT NULL, KEY (echantillon_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Le dossier des photos : le dossier privé du cockpit (hors du site, que la livraison ne touche pas), sous commercial_echantillons/. */
function smPhotosDossier(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__, 2) . '/var/prive']) as $c) {
        $f = rtrim($c, '/') . '/commercial_echantillons';
        if ((is_dir($f) || @mkdir($f, 0700, true)) && is_writable($f)) { return $d = $f; }
    }
    throw new RuntimeException('aucun dossier privé où ranger les photos des échantillons');
}

/**
 * Lit et vérifie les photos d'un envoi (data-URL JPEG, PNG ou WebP, 6 Mo au plus chacune). Rend [photos, erreur] : rien
 * n'est écrit tant qu'une seule est refusée. Fonction pure.
 */
function smPhotosLues(mixed $photos): array
{
    if (!is_array($photos) || $photos === []) { return [[], 'aucune photo reçue']; }
    if (count($photos) > SM_PHOTOS_MAX) { return [[], SM_PHOTOS_MAX . ' photos au plus par envoi']; }
    $out = [];
    foreach (array_values($photos) as $i => $p) {
        $data = is_array($p) ? (string) ($p['data'] ?? '') : (string) $p;
        if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return [[], 'photo ' . ($i + 1) . ' illisible (image attendue)']; }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) < 64) { return [[], 'photo ' . ($i + 1) . ' illisible']; }
        if (strlen($bin) > SM_PHOTO_OCTETS) { return [[], 'photo ' . ($i + 1) . ' trop lourde (6 Mo au plus)']; }
        $info = @getimagesizefromstring($bin);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if ($ext === null) { return [[], 'photo ' . ($i + 1) . ' : format non accepté (JPEG, PNG ou WebP)']; }
        $out[] = ['bin' => $bin, 'ext' => $ext, 'mime' => (string) $info['mime'], 'largeur' => (int) ($info[0] ?? 0) ?: null, 'hauteur' => (int) ($info[1] ?? 0) ?: null];
    }
    return [$out, null];
}

/** Les photos de plusieurs échantillons : [id de l'échantillon => [{id, le, largeur, hauteur}]]. */
function smPhotosDe(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) { return []; }
    smTablesPhotos();
    $out = [];
    foreach (Db::rows('SELECT id, echantillon_id, largeur, hauteur, prise_le FROM sm_echantillon_photo WHERE echantillon_id IN (' . implode(',', $ids) . ') ORDER BY id') as $r) {
        $out[(int) $r['echantillon_id']][] = ['id' => (int) $r['id'], 'le' => $r['prise_le'],
            'largeur' => $r['largeur'] !== null ? (int) $r['largeur'] : null, 'hauteur' => $r['hauteur'] !== null ? (int) $r['hauteur'] : null];
    }
    return $out;
}

/**
 * POST /commercial/echantillons/{id}/photos — { photos: [{data}] } : les photos de l'échantillon reçu, rangées ; un
 * échantillon « à envoyer » ou « commandé » passe à « reçu ».
 */
function wr_sm_echantillon_photos(int $id): array
{
    smTables();
    smTablesPhotos();
    if (Db::row('SELECT id FROM sm_echantillon WHERE id = ?', [$id]) === null) { http_response_code(404); return ['error' => 'échantillon introuvable']; }
    [$photos, $err] = smPhotosLues(body()['photos'] ?? null);
    if ($err !== null) { http_response_code(422); return ['error' => $err]; }
    $dos = smPhotosDossier();
    foreach ($photos as $p) {
        $nom = bin2hex(random_bytes(12)) . '.' . $p['ext'];
        if (@file_put_contents($dos . '/' . $nom, $p['bin']) === false) { http_response_code(500); return ['error' => 'le serveur n’a pas pu ranger la photo']; }
        @chmod($dos . '/' . $nom, 0600);
        Db::exec('INSERT INTO sm_echantillon_photo (echantillon_id, fichier, mime, largeur, hauteur, octets, prise_le) VALUES (?,?,?,?,?,?,NOW())',
            [$id, $nom, $p['mime'], $p['largeur'], $p['hauteur'], strlen($p['bin'])]);
    }
    Db::exec('UPDATE sm_echantillon SET statut = "recu" WHERE id = ? AND statut IN ("a_envoyer", "commande")', [$id]);
    journalAdd('Commercial', 'Commercial', null, 'Échantillon ' . $id . ' reçu : ' . count($photos) . ' photo(s)');
    return ['ok' => true, 'echantillon' => smEchVue(Db::row('SELECT * FROM sm_echantillon WHERE id = ?', [$id]), smMagasins())];
}

/** DELETE /commercial/echantillons/photos/{id} — retire une photo (la ligne et le fichier). */
function wr_sm_echantillon_photo_suppr(int $pid): array
{
    smTablesPhotos();
    $p = Db::row('SELECT * FROM sm_echantillon_photo WHERE id = ?', [$pid]);
    if ($p === null) { http_response_code(404); return ['error' => 'photo introuvable']; }
    $f = basename((string) $p['fichier']);
    try { if ($f !== '' && is_file(smPhotosDossier() . '/' . $f)) { @unlink(smPhotosDossier() . '/' . $f); } } catch (Throwable $e) { /* fichier déjà parti */ }
    Db::exec('DELETE FROM sm_echantillon_photo WHERE id = ?', [$pid]);
    return ['ok' => true];
}

/** GET /commercial/echantillons/photos/{id} — le fichier de la photo, tel quel, puis exit. */
function smPhotoSortie(int $pid): void
{
    smTablesPhotos();
    $p = Db::row('SELECT * FROM sm_echantillon_photo WHERE id = ?', [$pid]);
    $f = $p !== null ? smPhotosDossier() . '/' . basename((string) $p['fichier']) : '';
    if ($p === null || !is_file($f)) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'photo introuvable']); exit; }
    header('Content-Type: ' . (in_array($p['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $p['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; sandbox");
    readfile($f);
    exit;
}

/** Les routes des photos d'échantillon ; null pour une autre adresse. */
function smRoutePhotos(string $method, string $p): mixed
{
    if ($method === 'POST' && preg_match('#^/echantillons/(\d+)/photos$#', $p, $m)) { return wr_sm_echantillon_photos((int) $m[1]); }
    if (preg_match('#^/echantillons/photos/(\d+)$#', $p, $m)) {
        if ($method === 'GET') { smPhotoSortie((int) $m[1]); }
        if ($method === 'DELETE') { return wr_sm_echantillon_photo_suppr((int) $m[1]); }
    }
    return null;
}
