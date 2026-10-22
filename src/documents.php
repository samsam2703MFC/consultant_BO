<?php
declare(strict_types=1);

/**
 * Les documents d'un franchisé (22/10/2026, demandé par Sam) : l'onglet Documents de la fiche franchisé — les rapports
 * de réunion, les contrats de mise à disposition du matériel, et le reste, rangés par magasin.
 *
 * Les fichiers vivent sous `public/uploads/documents/{magasin}/` (le dossier que le déploiement préserve), sous un nom
 * tiré au hasard ; un `.htaccess` y refuse tout accès direct : un contrat ne se lit que par l'API
 * (GET /franchises/documents/fichier), jamais par une adresse devinée. La table `ceo_doc` garde le titre, la sorte,
 * la date du document, le nom d'origine et la taille.
 */

const DOC_SORTES = ['reunion' => 'Rapport de réunion', 'contrat' => 'Contrat de mise à disposition', 'autre' => 'Autre document'];
const DOC_EXT = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook'];
const DOC_MAX_OCTETS = 25 * 1024 * 1024;

function docTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_doc ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'shop INT NOT NULL,'
        . 'sorte VARCHAR(20) NOT NULL,'
        . 'titre VARCHAR(190) NOT NULL,'
        . 'fichier VARCHAR(80) NOT NULL,'            // le nom sur disque, tiré au hasard
        . 'nom_origine VARCHAR(190) NOT NULL,'
        . 'mime VARCHAR(120) NOT NULL,'
        . 'octets INT NOT NULL DEFAULT 0,'
        . 'date_doc DATE NULL,'
        . 'note VARCHAR(500) NULL,'
        . 'depose_le DATETIME NOT NULL,'
        . 'KEY idx_doc_shop (shop, date_doc)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le dossier des documents d'un magasin, créé au besoin, l'accès direct fermé. */
function docDossier(int $shop): string
{
    $racine = __DIR__ . '/../public/uploads/documents';
    if (!is_dir($racine)) { @mkdir($racine, 0775, true); }
    if (!is_file($racine . '/.htaccess')) {
        @file_put_contents($racine . '/.htaccess', "# Les documents des franchisés ne se lisent que par l'API du cockpit.\nRequire all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $d = $racine . '/' . $shop;
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

/** La taille la plus grande qu'un envoi passe : la nôtre, celle de PHP (upload_max_filesize, post_max_size). */
function docMaxOctets(): int
{
    $o = static function (string $v): int {
        $v = trim($v); if ($v === '' || $v === '0') { return PHP_INT_MAX; }
        $n = (int) $v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
    };
    return (int) min(DOC_MAX_OCTETS, $o((string) ini_get('upload_max_filesize')), $o((string) ini_get('post_max_size')));
}

function docLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'shop' => (string) $r['shop'], 'sorte' => (string) $r['sorte'], 'sorteLib' => DOC_SORTES[(string) $r['sorte']] ?? 'Autre document',
        'titre' => (string) $r['titre'], 'nom' => (string) $r['nom_origine'], 'mime' => (string) $r['mime'], 'octets' => (int) $r['octets'],
        'date' => $r['date_doc'] !== null ? (string) $r['date_doc'] : null, 'note' => $r['note'] !== null ? (string) $r['note'] : null,
        'deposeLe' => substr((string) $r['depose_le'], 0, 16)];
}

/** GET /franchises/documents?shop=4 — les documents du magasin, les plus récents d'abord. */
function ep_franchises_documents(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    docTable();
    $l = array_map('docLigne', Db::rows('SELECT * FROM ceo_doc WHERE shop = ? ORDER BY COALESCE(date_doc, DATE(depose_le)) DESC, id DESC', [$shop]));
    return ['shop' => (string) $shop, 'documents' => $l, 'sortes' => array_map(static fn ($k, $v) => ['v' => $k, 'nom' => $v], array_keys(DOC_SORTES), DOC_SORTES),
        'maxOctets' => docMaxOctets(), 'extensions' => array_keys(DOC_EXT)];
}

/** Les fichiers d'un envoi multipart, à plat : [[name, tmp_name, size, error], …] (un champ `fichiers[]` ou `fichier`). */
function docFichiersRecus(): array
{
    $out = [];
    foreach (['fichiers', 'fichier'] as $champ) {
        $f = $_FILES[$champ] ?? null;
        if (!is_array($f)) { continue; }
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $n) { $out[] = [(string) $n, (string) $f['tmp_name'][$i], (int) $f['size'][$i], (int) $f['error'][$i]]; }
        } else {
            $out[] = [(string) $f['name'], (string) $f['tmp_name'], (int) $f['size'], (int) $f['error']];
        }
    }
    return $out;
}

/** Garde un fichier reçu (déjà vérifié) : rend la ligne enregistrée. `$deplacer` : le chemin vient d'un envoi HTTP. */
function docGarder(int $shop, string $sorte, string $titre, ?string $date, ?string $note, string $nom, string $chemin, int $octets, bool $deplacer = true): ?array
{
    $ext = strtolower((string) pathinfo($nom, PATHINFO_EXTENSION));
    $disque = bin2hex(random_bytes(12)) . '.' . $ext;
    $cible = docDossier($shop) . '/' . $disque;
    $ok = $deplacer ? @move_uploaded_file($chemin, $cible) : @copy($chemin, $cible);
    if (!$ok) { return null; }
    @chmod($cible, 0640);
    Db::exec('INSERT INTO ceo_doc (shop, sorte, titre, fichier, nom_origine, mime, octets, date_doc, note, depose_le) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$shop, $sorte, mb_substr($titre, 0, 190), $disque, mb_substr($nom, 0, 190), DOC_EXT[$ext] ?? 'application/octet-stream', $octets, $date, $note, date('Y-m-d H:i:s')]);
    $id = (int) Db::pdo()->lastInsertId();
    $r = Db::row('SELECT * FROM ceo_doc WHERE id = ?', [$id]);
    return $r !== null ? docLigne($r) : null;
}

/**
 * POST /franchises/documents (multipart : shop, sorte, titre?, date?, note?, fichiers[]) — dépose un ou plusieurs
 * fichiers dans les documents du magasin. Un fichier refusé (sorte de fichier, taille, envoi coupé) se dit, les autres
 * passent. Sans titre, le nom du fichier en tient lieu.
 */
function wr_franchises_documents(): array
{
    $shop = (int) ($_POST['shop'] ?? 0);
    if ($shop <= 0) {
        // Un envoi plus lourd que post_max_size arrive vide : PHP a tout jeté, il faut le dire.
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) { http_response_code(413); return ['error' => 'envoi trop lourd pour le serveur : ' . round(docMaxOctets() / 1048576) . ' Mo au plus']; }
        http_response_code(400); return ['error' => 'shop requis'];
    }
    $sorte = (string) ($_POST['sorte'] ?? 'autre');
    if (!isset(DOC_SORTES[$sorte])) { $sorte = 'autre'; }
    $titre = trim((string) ($_POST['titre'] ?? ''));
    $date = trim((string) ($_POST['date'] ?? ''));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    $note = trim((string) ($_POST['note'] ?? '')) ?: null;
    if ($note !== null) { $note = mb_substr($note, 0, 500); }
    $recus = docFichiersRecus();
    if ($recus === []) { http_response_code(422); return ['error' => 'aucun fichier reçu']; }
    docTable();
    $max = docMaxOctets();
    $ajoutes = []; $refuses = [];
    foreach ($recus as [$nom, $tmp, $octets, $err]) {
        $nom = trim(str_replace(["\0", '/', '\\'], '', $nom)) ?: 'document';
        $ext = strtolower((string) pathinfo($nom, PATHINFO_EXTENSION));
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($err === UPLOAD_ERR_OK && $octets > $max)) { $refuses[] = ['nom' => $nom, 'motif' => 'trop lourd : ' . round($max / 1048576) . ' Mo au plus']; continue; }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { $refuses[] = ['nom' => $nom, 'motif' => 'envoi interrompu, réessayez']; continue; }
        if (!isset(DOC_EXT[$ext])) { $refuses[] = ['nom' => $nom, 'motif' => 'sorte de fichier refusée (' . ($ext ?: 'sans extension') . ')']; continue; }
        $t = $titre !== '' ? ($titre . (count($recus) > 1 ? ' — ' . pathinfo($nom, PATHINFO_FILENAME) : '')) : (string) pathinfo($nom, PATHINFO_FILENAME);
        $l = docGarder($shop, $sorte, $t, $date, $note, $nom, $tmp, $octets);
        if ($l === null) { $refuses[] = ['nom' => $nom, 'motif' => 'le serveur n’a pas pu l’écrire']; continue; }
        $ajoutes[] = $l;
    }
    if ($ajoutes !== [] && function_exists('journalAdd')) {
        journalAdd('CEO', 'Franchisé', 'Documents', count($ajoutes) . ' document(s) déposé(s) pour le magasin ' . $shop . ' : ' . implode(', ', array_map(static fn ($l) => $l['titre'], $ajoutes)));
    }
    if ($ajoutes === []) { http_response_code(422); }
    return ['ok' => $ajoutes !== [], 'ajoutes' => $ajoutes, 'refuses' => $refuses];
}

/** GET /franchises/documents/fichier?id=12[&telecharger=1] — le fichier lui-même : un PDF ou une image s'ouvre, le reste se télécharge. */
function ep_franchises_document_fichier(): void
{
    docTable();
    $r = Db::row('SELECT * FROM ceo_doc WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
    $chemin = $r !== null ? docDossier((int) $r['shop']) . '/' . basename((string) $r['fichier']) : '';
    if ($r === null || !is_file($chemin)) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'document introuvable']); exit; }
    $mime = (string) $r['mime'];
    $enLigne = empty($_GET['telecharger']) && ($mime === 'application/pdf' || str_starts_with($mime, 'image/'));
    $nom = (string) $r['nom_origine'];
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    // Pas de CSP sur un PDF : Chrome n'affiche plus un PDF « sandboxé » ; le reste s'ouvre sans rien exécuter.
    if ($mime !== 'application/pdf') { header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); }
    header('Content-Disposition: ' . ($enLigne ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]/u', '_', $nom) . '"; filename*=UTF-8\'\'' . rawurlencode($nom));
    readfile($chemin);
    exit;
}

/** DELETE /franchises/documents?id=12 — retire un document (la ligne et le fichier). */
function wr_franchises_document_suppr(): array
{
    docTable();
    $id = (int) ($_GET['id'] ?? 0);
    $r = Db::row('SELECT * FROM ceo_doc WHERE id = ?', [$id]);
    if ($r === null) { http_response_code(404); return ['error' => 'document introuvable']; }
    @unlink(docDossier((int) $r['shop']) . '/' . basename((string) $r['fichier']));
    Db::exec('DELETE FROM ceo_doc WHERE id = ?', [$id]);
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Franchisé', 'Documents', 'Document retiré du magasin ' . $r['shop'] . ' : ' . $r['titre']); }
    return ['ok' => true];
}
