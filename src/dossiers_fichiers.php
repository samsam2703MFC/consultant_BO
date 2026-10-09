<?php
/**
 * Les dossiers déposés dans `docs/dossiers/<dossier>/` (un dossier investisseur, une analyse…) : la liste de
 * leurs fichiers et leur envoi par courriel, en pièces jointes, par la machine SMTP du cockpit — demande du
 * 09/10/2026 (« envoie le dossier par mail »).
 *
 * Garde-fous : le nom du dossier est un slug (lettres, chiffres, tirets) qui doit exister ; seuls les fichiers
 * .pdf, .xlsx, .docx, .csv, .png et .jpg se joignent ; le destinataire et les copies doivent être des adresses
 * que le cockpit connaît déjà (destinataires des rapports, compte SMTP) — pas de relais ouvert. Chaque envoi,
 * réussi ou refusé, laisse une trace au journal.
 *
 * Un `dossier.json` facultatif dans le dossier porte `titre`, `objet` (sujet du courriel) et `resume` (texte du
 * courriel) ; la requête peut les remplacer (`objet`, `message`).
 */
declare(strict_types=1);

const DSR_TYPES = [
    'pdf' => 'application/pdf',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'csv' => 'text/csv', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
];
const DSR_MAX_OCTETS = 20 * 1024 * 1024;   // pièces cumulées par envoi : au-delà, les serveurs de courrier refusent (25 Mo encodés)
const DSR_SLUG = '/^[a-z0-9][a-z0-9-]{0,80}$/';

/** La racine des dossiers déposés : docs/dossiers/ du dépôt (livré sur le serveur avec le reste). */
function dsrRacine(): string
{
    return dirname(__DIR__) . '/docs/dossiers';
}

/** Le chemin d'un dossier, validé : un slug existant sous la racine. Null sinon. */
function dsrChemin(string $dossier): ?string
{
    if (!preg_match(DSR_SLUG, $dossier)) { return null; }
    $c = dsrRacine() . '/' . $dossier;
    return is_dir($c) ? $c : null;
}

/** Les fichiers joignables d'un dossier : nom, type MIME, taille, date de modification. Triés par nom. */
function dsrFichiers(string $chemin): array
{
    $out = [];
    foreach (scandir($chemin) ?: [] as $f) {
        if ($f[0] === '.' || !is_file($chemin . '/' . $f)) { continue; }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!isset(DSR_TYPES[$ext])) { continue; }
        $out[] = ['nom' => $f, 'type' => DSR_TYPES[$ext], 'octets' => (int) filesize($chemin . '/' . $f), 'modifie' => date('c', (int) filemtime($chemin . '/' . $f))];
    }
    usort($out, static fn ($a, $b) => strcmp($a['nom'], $b['nom']));
    return $out;
}

/** Le manifeste facultatif `dossier.json` : titre, objet, resume. Tableau vide sans manifeste. */
function dsrManifeste(string $chemin): array
{
    $m = is_file($chemin . '/dossier.json') ? json_decode((string) file_get_contents($chemin . '/dossier.json'), true) : null;
    return is_array($m) ? $m : [];
}

/** Une taille lisible : « 850 Ko », « 1,2 Mo ». */
function dsrTaille(int $octets): string
{
    return $octets >= 1048576 ? number_format($octets / 1048576, 1, ',', ' ') . ' Mo' : (string) max(1, (int) round($octets / 1024)) . ' Ko';
}

/** Les adresses que le cockpit connaît déjà : destinataires des rapports, compte et expéditeur SMTP. En minuscules, dédoublonnées. */
function dsrAdressesConnues(): array
{
    $a = [];
    try {
        foreach (Db::rows('SELECT destinataires FROM ceo_rapport') as $r) {
            foreach (json_decode((string) ($r['destinataires'] ?? '[]'), true) ?: [] as $d) { if (is_string($d)) { $a[] = $d; } }
        }
    } catch (Throwable $e) { /* sans table : seules les adresses SMTP */ }
    try {
        $c = Smtp::config();
        $a[] = (string) ($c['utilisateur'] ?? '');
        $exp = (string) ($c['expediteur'] ?? '');
        $a[] = preg_match('/<([^>]+)>/', $exp, $m) ? $m[1] : $exp;
    } catch (Throwable $e) { /* sans SMTP */ }
    $a = array_map(static fn ($x) => strtolower(trim((string) $x)), $a);
    return array_values(array_unique(array_filter($a, static fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL) !== false)));
}

/** GET /dossiers/fichiers[?dossier=halle-2026-10] — les dossiers déposés, leurs fichiers joignables, les adresses admises. */
function ep_dossiers_fichiers(): array
{
    $racine = dsrRacine();
    $voulu = trim((string) ($_GET['dossier'] ?? ''));
    $liste = [];
    foreach (is_dir($racine) ? (scandir($racine) ?: []) : [] as $d) {
        if ($d[0] === '.' || !is_dir($racine . '/' . $d) || !preg_match(DSR_SLUG, $d)) { continue; }
        if ($voulu !== '' && $d !== $voulu) { continue; }
        $m = dsrManifeste($racine . '/' . $d);
        $fichiers = dsrFichiers($racine . '/' . $d);
        $liste[] = ['dossier' => $d, 'titre' => (string) ($m['titre'] ?? $d), 'objet' => (string) ($m['objet'] ?? ''), 'fichiers' => $fichiers, 'octets' => array_sum(array_column($fichiers, 'octets'))];
    }
    if ($voulu !== '' && $liste === []) { http_response_code(404); return ['error' => 'dossier inconnu']; }
    return ['dossiers' => $liste, 'adressesConnues' => dsrAdressesConnues(), 'smtp' => Smtp::configured(), 'source' => 'docs/dossiers/<dossier>/ du dépôt livré ; adresses admises = destinataires des rapports et compte SMTP'];
}

/** Le courriel : le titre, le message en paragraphes, la liste des pièces jointes. */
function dsrCourriel(string $titre, string $message, array $fichiers): string
{
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $corps = '';
    foreach (array_filter(array_map('trim', preg_split('/\R\s*\R/', $message) ?: [])) as $p) { $corps .= '<p style="margin:0 0 12px">' . nl2br($h($p)) . '</p>'; }
    $liste = '';
    foreach ($fichiers as $f) { $liste .= '<li>' . $h($f['nom']) . ' <span style="color:#777">· ' . dsrTaille((int) $f['octets']) . '</span></li>'; }
    return '<!doctype html><html lang="fr"><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;line-height:1.5;margin:0;padding:24px">'
        . '<div style="max-width:640px"><h2 style="margin:0 0 16px;font-size:20px">' . $h($titre) . '</h2>' . $corps
        . '<p style="margin:16px 0 6px"><b>En pièces jointes</b></p><ul style="margin:0 0 16px;padding-left:20px">' . $liste . '</ul>'
        . '<p style="color:#777;font-size:13px;margin:0">Envoyé par le cockpit de L’Atelier By le ' . date('d/m/Y à H:i') . '.</p></div></body></html>';
}

/**
 * POST /dossiers/fichiers/envoyer {dossier, a[, copies[], fichiers[], objet, message]} — envoie les fichiers d'un
 * dossier en pièces jointes, par la machine SMTP du cockpit. `a` et `copies` : des adresses déjà connues du cockpit.
 * `fichiers` restreint aux noms donnés ; sans lui, tout le dossier part.
 */
function wr_dossiers_envoyer(): array
{
    $b = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($b)) { $b = $_POST; }
    $dossier = trim((string) ($b['dossier'] ?? ''));
    $chemin = dsrChemin($dossier);
    if ($chemin === null) { http_response_code(404); return ['ok' => false, 'error' => 'dossier inconnu — un nom en lettres, chiffres et tirets, présent sous docs/dossiers/']; }
    $a = strtolower(trim((string) ($b['a'] ?? '')));
    if (filter_var($a, FILTER_VALIDATE_EMAIL) === false) { http_response_code(400); return ['ok' => false, 'error' => 'destinataire invalide']; }
    $copies = array_values(array_unique(array_filter(array_map(static fn ($x) => strtolower(trim((string) $x)), is_array($b['copies'] ?? null) ? $b['copies'] : []), static fn ($x) => $x !== '' && $x !== $a)));
    foreach ($copies as $x) { if (filter_var($x, FILTER_VALIDATE_EMAIL) === false) { http_response_code(400); return ['ok' => false, 'error' => 'copie invalide : ' . $x]; } }
    $connues = dsrAdressesConnues();
    foreach (array_merge([$a], $copies) as $x) {
        if (!in_array($x, $connues, true)) { http_response_code(403); return ['ok' => false, 'error' => 'adresse inconnue du cockpit : ' . $x . ' — renseignez-la d’abord comme destinataire d’un rapport (Rapports)']; }
    }
    if (!Smtp::configured()) { http_response_code(503); return ['ok' => false, 'error' => 'SMTP non configuré (Paramètres)']; }
    $fichiers = dsrFichiers($chemin);
    $voulus = is_array($b['fichiers'] ?? null) ? array_map('strval', $b['fichiers']) : [];
    if ($voulus !== []) { $fichiers = array_values(array_filter($fichiers, static fn ($f) => in_array($f['nom'], $voulus, true))); }
    if ($fichiers === []) { http_response_code(404); return ['ok' => false, 'error' => 'aucun fichier joignable dans ce dossier (.pdf, .xlsx, .docx, .csv, .png, .jpg)']; }
    $total = (int) array_sum(array_column($fichiers, 'octets'));
    if ($total > DSR_MAX_OCTETS) { http_response_code(413); return ['ok' => false, 'error' => 'pièces jointes trop lourdes : ' . dsrTaille($total) . ', limite ' . dsrTaille(DSR_MAX_OCTETS)]; }
    $m = dsrManifeste($chemin);
    $titre = (string) ($m['titre'] ?? $dossier);
    $objet = trim((string) ($b['objet'] ?? '')) ?: (string) ($m['objet'] ?? $titre);
    $message = trim((string) ($b['message'] ?? '')) ?: (string) ($m['resume'] ?? '');
    $pieces = [];
    foreach ($fichiers as $f) { $pieces[] = ['nom' => $f['nom'], 'type' => $f['type'], 'contenu' => (string) file_get_contents($chemin . '/' . $f['nom'])]; }
    $ok = Smtp::envoyer($a, $objet, dsrCourriel($titre, $message, $fichiers), $pieces, '', $copies);
    $trace = $titre . ' → ' . $a . ($copies !== [] ? ' (cc ' . implode(', ', $copies) . ')' : '') . ' : ' . count($pieces) . ' pièce(s), ' . dsrTaille($total) . ($ok ? '' : ' — refusé : ' . (Smtp::$lastError ?? 'sans détail'));
    if (function_exists('journalAdd')) { try { journalAdd('cockpit', 'dossier', $dossier, ($ok ? 'Dossier envoyé : ' : 'Envoi du dossier refusé : ') . $trace); } catch (Throwable $e) { /* sans journal */ } }
    if (!$ok) { http_response_code(502); return ['ok' => false, 'error' => 'envoi refusé — ' . (Smtp::$lastError ?? 'sans détail'), 'dossier' => $dossier, 'a' => $a]; }
    return ['ok' => true, 'dossier' => $dossier, 'titre' => $titre, 'a' => $a, 'copies' => $copies, 'objet' => $objet,
        'pieces' => array_map(static fn ($f) => ['nom' => $f['nom'], 'octets' => $f['octets']], $fichiers), 'octets' => $total, 'via' => 'smtp', 'envoye' => date('c')];
}
