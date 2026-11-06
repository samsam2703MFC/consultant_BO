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

const DOC_SORTES = ['reunion' => 'Rapport de réunion', 'contrat' => 'Contrat de mise à disposition', 'courrier' => 'Courrier envoyé', 'autre' => 'Autre document'];
const DOC_EXT = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook'];
/** Les copies que le cockpit range lui-même (le mail envoyé, quand le serveur ne fait pas de PDF) ; jamais un dépôt. */
const DOC_EXT_INTERNE = ['html' => 'text/html'];
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
        [$shop, $sorte, mb_substr($titre, 0, 190), $disque, mb_substr($nom, 0, 190), DOC_EXT[$ext] ?? DOC_EXT_INTERNE[$ext] ?? 'application/octet-stream', $octets, $date, $note, date('Y-m-d H:i:s')]);
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

/* --- Le mail « matériel mis à disposition » (22/10/2026, maquette validée par Sam) ------------------------------- */

/** Le nom du magasin (table `shops`), ou « Magasin n ». */
function docMagasinNom(int $shop): string
{
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$shop]); } catch (Throwable $e) { $r = null; }
    return $r !== null && trim((string) $r['name']) !== '' ? (string) $r['name'] : 'Magasin ' . $shop;
}

/** Les adresses que les carnets des rapports connaissent pour ce magasin (toutes lignes confondues). */
function docAdressesMagasin(string $nom): array
{
    $a = [];
    try {
        foreach (Db::rows('SELECT dest_par_magasin FROM ceo_rapport') as $r) {
            foreach ((array) ((json_decode((string) ($r['dest_par_magasin'] ?? ''), true) ?: [])[$nom] ?? []) as $d) { if (is_string($d)) { $a[] = strtolower(trim($d)); } }
        }
    } catch (Throwable $e) { /* sans carnets */ }
    return array_values(array_unique(array_filter($a, static fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL) !== false)));
}

/** « de Florival », « d’Ottignies » : la ville du magasin, son nom entier s'il ne se découpe pas. */
function docDeVille(string $nom): string
{
    $v = function_exists('cqMailVille') ? cqMailVille($nom) : $nom;
    return (preg_match('/^[aeiouyhâàéèêëîïôöûüAEIOUYHÂÀÉÈÊËÎÏÔÖÛÜ]/u', $v) ? 'd’' : 'de ') . $v;
}

/** Le mail : [sujet, html]. `$materiel` : la liste facultative ; `$contrat` : la pièce jointe (ligne de document) ou null. */
function docMailMateriel(string $nom, string $materiel, ?array $contrat): array
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $res = setting('reseau', []);
    $marque = is_array($res) ? ($res['nom'] ?? 'L’Atelier by') : 'L’Atelier by';
    $logoUri = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    $logo = $logoUri !== '' ? '<img src="' . $logoUri . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
        : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>';
    $deVille = docDeVille($nom);
    $c = '<tr><td class="pad" style="' . $F . ';padding:20px 26px 6px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#F4F1EC;border-left:4px solid #8D1D2C;border-radius:8px;padding:14px 16px">'
        . '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8D1D2C;font-weight:700">Mise à disposition du matériel</div>'
        . '<div class="titre" style="font-size:19px;font-weight:700;color:#221E1A;margin-top:4px">Magasin ' . $e($deVille) . '</div>'
        . '<div style="font-size:13px;color:#5d554d;margin-top:3px">Conditions d’entretien et de réparation</div>'
        . '</td></tr></table></td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:14px 26px 4px">'
        . 'Bonjour,<br><br>Nous vous confirmons que le matériel de votre magasin vous est <b>mis à disposition à l’état neuf</b> par ' . $e($marque) . '.</td></tr>';
    $bloc = static fn ($titre, $coul, $fond, $lignes) => '<tr><td class="pad" style="' . $F . ';padding:12px 26px 0">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:' . $fond . ';border-radius:8px;padding:12px 16px">'
        . '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:' . $coul . ';font-weight:700;margin-bottom:6px">' . $titre . '</div>'
        . implode('', array_map(static fn ($l) => '<div style="font-size:14px;line-height:1.55;color:#221E1A;padding:3px 0">' . $l . '</div>', $lignes))
        . '</td></tr></table></td></tr>';
    $c .= $bloc('Mis à disposition par ' . $e($marque), '#2E6DB4', '#EAF1F9', ['✓ Le matériel du magasin, <b>neuf</b>, prêt à l’emploi']);
    $c .= $bloc('À votre charge', '#8A3F14', '#FBEFE7', [
        '• <b>L’entretien</b> du matériel : nettoyage, entretien courant et entretien périodique',
        '• <b>Toutes les pièces qui cassent</b>, leur remplacement et les réparations']);
    $materiel = trim($materiel);
    if ($materiel !== '') {
        $c .= '<tr><td class="pad" style="' . $F . ';padding:14px 26px 0">'
            . '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#8b8177;font-weight:700;margin-bottom:4px">Matériel concerné</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#221E1A">' . nl2br($e($materiel)) . '</div></td></tr>';
    }
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:14px 26px 4px">'
        . 'Le détail figure dans votre contrat de mise à disposition' . ($contrat !== null ? ', joint à ce message' : '') . '.<br><br>Pour toute question, n’hésitez pas à nous contacter.<br><br>Bien cordialement,<br><b>' . $e($marque) . '</b></td></tr>';
    if ($contrat !== null) {
        $ko = max(1, (int) round(((int) $contrat['octets']) / 1024));
        $c .= '<tr><td class="pad" style="' . $F . ';padding:12px 26px 4px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border:1px solid #EFE9DF;border-radius:8px;padding:8px 12px;font-size:13px;color:#221E1A">📎 ' . $e($contrat['nom']) . ' <span style="color:#8b8177">· ' . ($ko >= 1024 ? number_format($ko / 1024, 1, ',', ' ') . ' Mo' : $ko . ' Ko') . '</span></td></tr></table></td></tr>';
    }
    $html = caMailSquelette('Franchise', 'message envoyé depuis le cockpit ; une copie est rangée dans les documents du magasin.');
    $html = str_replace(['{{logo}}', '{{marque}}', '{{contenu}}'], [$logo, $e($marque), $c], $html);
    return ['Matériel mis à disposition — magasin ' . docDeVille($nom), $html];
}

/** Le contrat choisi comme pièce jointe : une ligne de ce magasin, de la sorte « contrat », ou null. */
function docContrat(int $shop, int $id): ?array
{
    if ($id <= 0) { return null; }
    docTable();
    $r = Db::row('SELECT * FROM ceo_doc WHERE id = ? AND shop = ?', [$id, $shop]);
    return $r !== null ? docLigne($r) + ['fichier' => (string) $r['fichier']] : null;
}

/** GET /franchises/documents/mail-materiel?shop=4[&materiel=…&contrat=12] — l'aperçu, les adresses connues, les contrats du magasin. */
function ep_franchises_mail_materiel(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    docTable();
    $nom = docMagasinNom($shop);
    $contrats = array_map('docLigne', Db::rows("SELECT * FROM ceo_doc WHERE shop = ? AND sorte = 'contrat' ORDER BY COALESCE(date_doc, DATE(depose_le)) DESC, id DESC", [$shop]));
    $contrat = docContrat($shop, (int) ($_GET['contrat'] ?? 0));
    [$sujet, $html] = docMailMateriel($nom, mb_substr((string) ($_GET['materiel'] ?? ''), 0, 2000), $contrat);
    return ['shop' => (string) $shop, 'magasin' => $nom, 'sujet' => $sujet, 'html' => $html, 'adresses' => docAdressesMagasin($nom),
        'contrats' => array_map(static fn ($l) => ['id' => $l['id'], 'titre' => $l['titre'], 'nom' => $l['nom']], $contrats), 'smtp' => Smtp::configured()];
}

/**
 * POST /franchises/documents/mail-materiel {shop, a, materiel?, contrat?} — envoie le mail au franchisé (le contrat en
 * pièce jointe s'il est choisi) et range une copie dans ses documents (« Courrier envoyé », en PDF quand le serveur sait
 * en faire, sinon en HTML).
 */
function wr_franchises_mail_materiel(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    $a = strtolower(trim((string) ($b['a'] ?? '')));
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'adresse du franchisé invalide']; }
    if (!Smtp::configured()) { http_response_code(503); return ['error' => 'SMTP non configuré (Paramètres)']; }
    docTable();
    $nom = docMagasinNom($shop);
    $contrat = docContrat($shop, (int) ($b['contrat'] ?? 0));
    $pieces = [];
    if ($contrat !== null) {
        $chemin = docDossier($shop) . '/' . basename($contrat['fichier']);
        if (!is_file($chemin)) { http_response_code(404); return ['error' => 'le contrat choisi n’est plus sur le serveur']; }
        if (filesize($chemin) > 10 * 1024 * 1024) { http_response_code(413); return ['error' => 'le contrat dépasse 10 Mo : trop lourd pour un e-mail']; }
        $pieces[] = ['nom' => $contrat['nom'], 'type' => $contrat['mime'], 'contenu' => (string) file_get_contents($chemin)];
    }
    $materiel = mb_substr(trim((string) ($b['materiel'] ?? '')), 0, 2000);
    [$sujet, $html] = docMailMateriel($nom, $materiel, $contrat);
    if (!Smtp::envoyer($a, $sujet, $html, $pieces)) { http_response_code(502); return ['error' => 'envoi refusé : ' . (Smtp::$lastError ?? 'SMTP')]; }
    // La copie : le mail tel qu'envoyé, rangé dans les documents du magasin.
    $pdf = function_exists('rapPdfRendu') ? rapPdfRendu($html, ['magasin' => $nom, 'rapport' => 'Mise à disposition du matériel', 'genere' => date('d/m/Y à H:i'), 'envoye' => date('d/m/Y')]) : null;
    $tmp = tempnam(sys_get_temp_dir(), 'docm');
    file_put_contents($tmp, $pdf ?? $html);
    $copie = docGarder($shop, 'courrier', 'Mail « matériel mis à disposition »', date('Y-m-d'), 'envoyé à ' . $a . ($contrat !== null ? ', contrat joint : ' . $contrat['nom'] : ''),
        'mail-materiel-' . date('Y-m-d') . ($pdf !== null ? '.pdf' : '.html'), $tmp, (int) filesize($tmp), false);
    @unlink($tmp);
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Franchisé', 'Mail matériel', 'Envoyé à ' . $a . ' pour ' . $nom . ($contrat !== null ? ' (contrat joint)' : '')); }
    return ['ok' => true, 'a' => $a, 'sujet' => $sujet, 'copie' => $copie];
}
