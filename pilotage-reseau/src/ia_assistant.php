<?php
declare(strict_types=1);

/**
 * L'ASSISTANT DE NOTATION (15/11/2026) — page Contrôles des tâches, mode « assisté ».
 *
 * En mode assisté, l'IA propose une note pour chaque photo à contrôler ; le consultant valide ou corrige, une
 * par une ou toutes à la fin. Trois règles :
 *
 *  1. l'IA PROPOSE, elle ne note pas. Rien ne part au panel sans le geste du consultant : la note passe toujours
 *     par POST /pwa/tasks/review, comme en mode manuel ;
 *  2. une proposition est GARDÉE (table pil_cq_ia) : elle se paie une fois, elle se retrouve au rechargement, et
 *     elle reste en face de la note que le consultant a posée ;
 *  3. le modèle ÉVOLUE par les corrections : quand le consultant note autrement que l'IA (ou dit pourquoi), la
 *     correction devient une leçon, envoyée en exemple avec les photos suivantes du même rayon. Une leçon se
 *     retire d'un clic ; la consigne du rayon (back office) prime sur tout.
 *
 * Routes : GET /ia/propositions?date=, POST /ia/proposer, GET /ia/lecons, POST /ia/lecons.
 */

const IA_LECONS_MAX = 6;        // corrections envoyées en exemple avec une photo
const IA_ACCORD_JOURS = 30;     // fenêtre du taux d'accord affiché
const IA_TOLERANCE_MIN = 15;    // retard toléré sur l'heure standard de la checklist (le même à l'écran, app.js)
const IA_AVANCE_MAX = 60;       // au-delà, une photo « très en avance » : le comptoir était-il prêt ?

function ensureIaAssistant(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_cq_ia ('
        . 'shop_id INT NOT NULL,'
        . 'task_id INT NOT NULL,'
        . 'jour DATE NOT NULL,'
        . 'tache VARCHAR(190) NULL,'
        . 'rayon VARCHAR(80) NULL,'                 // clé du rayon (src/rayons_photos.php), pour les leçons
        . 'niveau TINYINT NULL,'                    // la proposition ; NULL = pas de proposition (motif)
        . 'confiance VARCHAR(10) NULL,'
        . 'constats TEXT NULL,'                     // JSON
        . 'commentaire TEXT NULL,'
        . 'motif VARCHAR(300) NULL,'
        . 'modele VARCHAR(80) NULL,'
        . 'lecons_vues TINYINT NOT NULL DEFAULT 0,' // combien de leçons accompagnaient la photo
        . 'heure_prevue CHAR(5) NULL,'              // l'heure standard de la checklist
        . 'heure_prise CHAR(5) NULL,'               // l'heure de la photo
        . 'propose_le DATETIME NOT NULL,'
        . 'note_finale TINYINT NULL,'               // la note du consultant, quand elle est posée
        . 'commentaire_final TEXT NULL,'
        . 'raison VARCHAR(400) NULL,'               // pourquoi le consultant a corrigé (facultatif)
        . 'valide_par VARCHAR(190) NULL,'
        . 'valide_le DATETIME NULL,'
        . 'lecon TINYINT NOT NULL DEFAULT 1,'       // 0 : correction écartée, elle ne sert plus d'exemple
        . 'PRIMARY KEY (shop_id, task_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le barème partagé, indexé par niveau : [n => ['nom' => …, 'couleur' => …]]. */
function iaBareme(): array
{
    $sig = setting('signalement', []);
    $niv = is_array($sig) && !empty($sig['niveaux']) ? $sig['niveaux']
        : (function_exists('signalementDefaut') ? signalementDefaut()['niveaux'] : []);
    $out = [];
    foreach ($niv as $n) { if (isset($n['n'])) { $out[(int) $n['n']] = ['nom' => (string) ($n['nom'] ?? ''), 'couleur' => (string) ($n['couleur'] ?? '')]; } }
    return $out;
}

function iaNomNiveau(?int $n): ?string
{
    if ($n === null) { return null; }
    return iaBareme()[$n]['nom'] ?? ($n . '/5');
}

/**
 * La ponctualité d'une photo : son heure contre l'heure standard de la checklist. À l'heure jusqu'à
 * IA_TOLERANCE_MIN minutes de retard ; « en retard » au-delà ; « très en avance » plus de IA_AVANCE_MAX minutes
 * avant (le comptoir n'était peut-être pas prêt). `phrase` est ce que le modèle lit.
 *
 * @param ?string $prevue  HH:MM
 * @param ?string $prise   AAAA-MM-JJ HH:MM ou HH:MM
 */
function iaPonctualite(?string $prevue, ?string $prise): ?array
{
    $hm = static fn (?string $v): ?string => is_string($v) && preg_match('/(\d{2}):(\d{2})(?::\d{2})?$/', trim($v), $m) ? $m[1] . ':' . $m[2] : null;
    $p = $hm($prevue); $f = $hm($prise !== null && strlen(trim($prise)) > 5 ? substr(trim($prise), 11, 5) : $prise);
    if ($p === null || $f === null) { return null; }
    $min = static fn (string $x): int => (int) substr($x, 0, 2) * 60 + (int) substr($x, 3, 2);
    $ecart = $min($f) - $min($p);
    $etat = $ecart > IA_TOLERANCE_MIN ? 'retard' : ($ecart < -IA_AVANCE_MAX ? 'avance' : 'heure');
    $duree = static function (int $m): string { $m = abs($m); return $m >= 60 ? intdiv($m, 60) . ' h ' . sprintf('%02d', $m % 60) : $m . ' min'; };
    $verdict = $etat === 'retard' ? 'en retard de ' . $duree($ecart)
        : ($etat === 'avance' ? 'très en avance (' . $duree($ecart) . ' avant)' : 'à l’heure' . ($ecart < 0 ? ' (' . $duree($ecart) . ' avant)' : ($ecart > 0 ? ' (' . $duree($ecart) . ' après, toléré)' : '')));
    return ['prevue' => $p, 'prise' => $f, 'ecart' => $ecart, 'etat' => $etat, 'verdict' => $verdict, 'tolerance' => IA_TOLERANCE_MIN,
        'phrase' => 'photo prise à ' . $f . ', heure standard de la checklist ' . $p . ' : ' . $verdict];
}

/** Une ligne de pil_cq_ia telle que l'écran la lit. */
function iaPropositionLigne(array $r): array
{
    $niveau = $r['niveau'] !== null ? (int) $r['niveau'] : null;
    $finale = $r['note_finale'] !== null ? (int) $r['note_finale'] : null;
    $constats = json_decode((string) ($r['constats'] ?? '[]'), true);
    return [
        'shopId' => (string) $r['shop_id'], 'taskId' => (string) $r['task_id'], 'date' => (string) $r['jour'],
        'tache' => $r['tache'], 'rayon' => $r['rayon'],
        'niveau' => $niveau, 'nom' => iaNomNiveau($niveau), 'confiance' => $r['confiance'],
        'constats' => is_array($constats) ? array_values($constats) : [], 'commentaire' => $r['commentaire'],
        'motif' => $r['motif'], 'modele' => $r['modele'], 'leconsVues' => (int) $r['lecons_vues'],
        'proposeLe' => $r['propose_le'],
        // L'état : proposée (en attente du consultant), validée telle quelle, corrigée, ou sans proposition.
        'etat' => $finale !== null ? ($finale === $niveau ? 'valide' : 'corrige') : ($niveau === null ? 'echec' : 'propose'),
        'noteFinale' => $finale, 'nomFinal' => iaNomNiveau($finale), 'commentaireFinal' => $r['commentaire_final'],
        'raison' => $r['raison'], 'validePar' => $r['valide_par'], 'valideLe' => $r['valide_le'],
        'lecon' => (bool) (int) $r['lecon'],
        'ponctualite' => iaPonctualite($r['heure_prevue'] ?? null, $r['heure_prise'] ?? null),
    ];
}

/**
 * Le taux d'accord des 30 derniers jours : sur les propositions que le consultant a notées, combien l'ont été au
 * même niveau, et dans quel sens l'IA se trompe quand elle se trompe.
 */
function iaAccord(): array
{
    $depuis = date('Y-m-d', strtotime('-' . IA_ACCORD_JOURS . ' days'));
    $rows = Db::rows('SELECT niveau, note_finale FROM pil_cq_ia WHERE niveau IS NOT NULL AND note_finale IS NOT NULL AND jour >= ?', [$depuis]);
    $n = count($rows);
    $pareil = 0; $severe = 0; $indulgente = 0;
    foreach ($rows as $r) {
        $d = (int) $r['niveau'] - (int) $r['note_finale'];
        if ($d === 0) { $pareil++; } elseif ($d < 0) { $severe++; } else { $indulgente++; }
    }
    return ['jours' => IA_ACCORD_JOURS, 'notees' => $n, 'pareil' => $pareil,
        'pct' => $n > 0 ? (int) round(100 * $pareil / $n) : null, 'tropSevere' => $severe, 'tropIndulgente' => $indulgente];
}

/** GET /ia/propositions?date= — les propositions d'une journée, le taux d'accord, le nombre de leçons. */
function ep_ia_propositions(): array
{
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); return ['error' => 'date (YYYY-MM-DD) requise']; }
    ensureIaAssistant();
    $props = array_map('iaPropositionLigne', Db::rows('SELECT * FROM pil_cq_ia WHERE jour = ? ORDER BY shop_id, task_id', [$date]));
    return ['date' => $date, 'propositions' => $props, 'accord' => iaAccord(),
        'lecons' => (int) ((Db::row('SELECT COUNT(*) AS n FROM pil_cq_ia WHERE ' . iaSqlLecon()) ?? [])['n'] ?? 0),
        'configure' => class_exists('Anthropic') && Anthropic::configured()];
}

/** Une correction qui sert de leçon : notée autrement que proposé, ou avec une raison donnée. */
function iaSqlLecon(): string
{
    return "lecon = 1 AND niveau IS NOT NULL AND note_finale IS NOT NULL AND (note_finale <> niveau OR (raison IS NOT NULL AND raison <> ''))";
}

/**
 * Les leçons pour une photo : les dernières corrections du même rayon (ou de la même tâche quand ce n'est pas une
 * photo de comptoir), en phrases courtes que le modèle lit avant de juger.
 *
 * @return list<string>
 */
function iaLeconsPour(?string $rayon, string $tache, int $max = IA_LECONS_MAX): array
{
    try {
        ensureIaAssistant();
        $rows = $rayon !== null && $rayon !== ''
            ? Db::rows('SELECT * FROM pil_cq_ia WHERE ' . iaSqlLecon() . ' AND rayon = ? ORDER BY valide_le DESC LIMIT ' . (int) $max, [$rayon])
            : Db::rows('SELECT * FROM pil_cq_ia WHERE ' . iaSqlLecon() . ' AND tache = ? ORDER BY valide_le DESC LIMIT ' . (int) $max, [$tache]);
    } catch (PDOException $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $constats = json_decode((string) ($r['constats'] ?? '[]'), true);
        $vu = is_array($constats) && $constats ? ' (tu avais relevé : « ' . mb_substr((string) $constats[0], 0, 160) . ' »)' : '';
        $raison = trim((string) ($r['raison'] ?? ''));
        $out[] = 'Tu avais proposé « ' . iaNomNiveau((int) $r['niveau']) . ' »' . $vu . ' ; le consultant a noté « '
            . iaNomNiveau((int) $r['note_finale']) . ' »' . ($raison !== '' ? ' — sa raison : ' . mb_substr($raison, 0, 300) : '') . '.';
    }
    return $out;
}

/**
 * POST /ia/proposer {shopId, taskId, date, refaire?} — la proposition d'une photo, calculée une fois puis gardée.
 * La même que le bouton « Proposer une évaluation » du volet (ep_ia_note), leçons du rayon comprises.
 */
function wr_ia_proposer(): array
{
    $b = body();
    $shopId = (int) ($b['shopId'] ?? 0);
    $taskId = (int) ($b['taskId'] ?? 0);
    $date = (string) ($b['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    ensureIaAssistant();
    $avant = Db::row('SELECT * FROM pil_cq_ia WHERE shop_id = ? AND task_id = ? AND jour = ?', [$shopId, $taskId, $date]);
    // Déjà proposée : on ne repaie pas. Déjà notée par le consultant : on ne repropose pas par-dessus sa note.
    if ($avant !== null && ($avant['note_finale'] !== null || (empty($b['refaire']) && $avant['niveau'] !== null))) {
        return ['ok' => true, 'proposition' => iaPropositionLigne($avant), 'cache' => true];
    }
    $_GET['shop'] = (string) $shopId; $_GET['task'] = (string) $taskId; $_GET['date'] = $date;
    $r = ep_ia_note();
    if (($r['etat'] ?? '') !== 'ok' && str_starts_with((string) ($r['motif'] ?? ''), 'manque API')) {
        http_response_code(503);
        return ['error' => (string) $r['motif']];
    }
    $ponct = is_array($r['ponctualite'] ?? null) ? $r['ponctualite'] : null;
    Db::exec('REPLACE INTO pil_cq_ia (shop_id, task_id, jour, tache, rayon, niveau, confiance, constats, commentaire, motif,'
        . ' modele, lecons_vues, propose_le, heure_prevue, heure_prise, lecon) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
        [$shopId, $taskId, $date, $r['tache'] ?? null, $r['rayon'] ?? null,
         ($r['etat'] ?? '') === 'ok' ? $r['niveau'] : null, $r['confiance'] ?? null,
         json_encode(array_values((array) ($r['constats'] ?? [])), JSON_UNESCAPED_UNICODE),
         $r['commentaire'] ?? null, ($r['etat'] ?? '') === 'ok' ? null : mb_substr((string) ($r['motif'] ?? 'pas de proposition'), 0, 300),
         $r['modele'] ?? null, (int) ($r['leconsVues'] ?? 0), date('Y-m-d H:i:s'), $ponct['prevue'] ?? null, $ponct['prise'] ?? null]);
    $ligne = Db::row('SELECT * FROM pil_cq_ia WHERE shop_id = ? AND task_id = ? AND jour = ?', [$shopId, $taskId, $date]);
    return ['ok' => true, 'proposition' => iaPropositionLigne($ligne ?? []), 'cache' => false]
        + (!empty($r['avertissement']) ? ['avertissement' => $r['avertissement']] : []);
}

/**
 * La note du consultant rejoint la proposition (appelé par wr_pwa_task_review, après le panel). Qu'elle vienne de
 * la carte, du volet, de la validation finale ou du mode manuel : la note humaine est la vérité, la proposition
 * lui est comparée. Sans proposition, rien.
 */
function iaNoteHumaine(int $shopId, int $taskId, string $date, int $note, string $comment, ?string $raison, string $auteur): void
{
    try {
        ensureIaAssistant();
        $raison = $raison !== null ? mb_substr(trim($raison), 0, 400) : '';
        Db::exec('UPDATE pil_cq_ia SET note_finale = ?, commentaire_final = ?, raison = ?, valide_par = ?, valide_le = ?'
            . ' WHERE shop_id = ? AND task_id = ? AND jour = ?',
            [$note, $comment !== '' ? $comment : null, $raison !== '' ? $raison : null, mb_substr($auteur, 0, 190),
             date('Y-m-d H:i:s'), $shopId, $taskId, $date]);
    } catch (PDOException $e) { /* le suivi de l'assistant ne doit jamais faire échouer une note */ }
}

/** GET /ia/lecons — les corrections du consultant, retenues ou écartées, les plus récentes d'abord. */
function ep_ia_lecons(): array
{
    ensureIaAssistant();
    $rows = Db::rows("SELECT * FROM pil_cq_ia WHERE niveau IS NOT NULL AND note_finale IS NOT NULL"
        . " AND (note_finale <> niveau OR (raison IS NOT NULL AND raison <> '')) ORDER BY valide_le DESC LIMIT 200");
    $lecons = array_map('iaPropositionLigne', $rows);
    return ['lecons' => $lecons, 'retenues' => count(array_filter($lecons, fn ($l) => $l['lecon'])), 'max' => IA_LECONS_MAX];
}

/** POST /ia/lecons {shopId, taskId, date, retenir} — écarter une correction (ou la reprendre) comme exemple. */
function wr_ia_lecons(): array
{
    $b = body();
    $shopId = (int) ($b['shopId'] ?? 0); $taskId = (int) ($b['taskId'] ?? 0); $date = (string) ($b['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    ensureIaAssistant();
    // Existence d'abord : MySQL compte 0 ligne touchée quand la valeur ne change pas.
    if (Db::row('SELECT shop_id FROM pil_cq_ia WHERE shop_id = ? AND task_id = ? AND jour = ?', [$shopId, $taskId, $date]) === null) {
        http_response_code(404);
        return ['error' => 'correction introuvable'];
    }
    Db::exec('UPDATE pil_cq_ia SET lecon = ? WHERE shop_id = ? AND task_id = ? AND jour = ?', [!empty($b['retenir']) ? 1 : 0, $shopId, $taskId, $date]);
    if (function_exists('journalAdd')) {
        journalAdd('CEO', 'Assistant de notation', null, (!empty($b['retenir']) ? 'Leçon reprise' : 'Leçon écartée') . ' — tâche #' . $taskId . ' (boutique #' . $shopId . ', ' . $date . ')');
    }
    return ep_ia_lecons() + ['ok' => true];
}

/* --- Les photos MODÈLES et l'entraînement par rayon (15/11/2026) ----------------------------------------------------
 *
 * Une photo rendue que le consultant juge parfaite devient le modèle de son rayon : pour son MAGASIN (pil_cq_modele,
 * une par rayon et par magasin — les comptoirs ne se ressemblent pas d'un magasin à l'autre), ou pour le RÉSEAU (la
 * photo type du rayon, con_rayon_photo). L'IA juge ensuite chaque photo contre le modèle de son magasin, à défaut
 * contre la photo type du réseau. Le consultant choisit le modèle ; l'IA ne choisit jamais les siens, elle
 * renforcerait ses propres erreurs. La photo est COPIÉE sur le serveur : l'adresse signée du panel expire en 20 min.
 *
 * Routes : GET /ia/entrainement, POST /ia/modeles.
 */

function ensureIaModeles(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_cq_modele ('
        . 'rayon VARCHAR(80) NOT NULL,'             // clé du rayon (src/rayons_photos.php)
        . 'shop_id INT NOT NULL,'
        . 'photo VARCHAR(255) NOT NULL,'            // chemin sous public/ (uploads/rayons/modeles/…)
        . 'source_task INT NULL,'                   // la tâche et le jour d'où vient la photo
        . 'source_jour DATE NULL,'
        . 'tache VARCHAR(190) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NOT NULL,'
        . 'PRIMARY KEY (rayon, shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le modèle d'un rayon pour un magasin, ou null. */
function iaModelePour(?string $rayon, int $shopId): ?array
{
    if ($rayon === null || $rayon === '' || $shopId <= 0) { return null; }
    try {
        ensureIaModeles();
        return Db::row('SELECT * FROM pil_cq_modele WHERE rayon = ? AND shop_id = ?', [$rayon, $shopId]);
    } catch (PDOException $e) { return null; }
}

/** Télécharge une photo (adresse signée du panel) : [octets, erreur]. Les essais posent $GLOBALS['IA_TELECHARGER']. */
function iaTelecharger(string $url): array
{
    if (isset($GLOBALS['IA_TELECHARGER']) && is_callable($GLOBALS['IA_TELECHARGER'])) { return ($GLOBALS['IA_TELECHARGER'])($url); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) { return [null, 'photo illisible (HTTP ' . $code . ')']; }
    return [(string) $raw, null];
}

/** Le niveau le plus haut du barème (Exemplaire) : une photo notée ainsi est candidate au modèle. */
function iaNiveauMax(): int
{
    $b = iaBareme();
    return $b ? max(array_keys($b)) : 5;
}

/**
 * POST /ia/modeles — {shopId, taskId, date, portee: "magasin"|"reseau"} : la photo de cette tâche devient le modèle
 * de son rayon, pour son magasin ou pour le réseau ; {rayon, shopId, retirer: true} : retirer le modèle d'un magasin.
 */
function wr_ia_modeles(): array
{
    $b = body();
    ensureIaModeles();
    $auteur = function_exists('psAuteur') ? psAuteur() : 'CEO';
    if (!empty($b['retirer'])) {
        $rayon = (string) ($b['rayon'] ?? ''); $shopId = (int) ($b['shopId'] ?? 0);
        $m = iaModelePour($rayon, $shopId);
        if ($m === null) { http_response_code(404); return ['error' => 'pas de modèle pour ce rayon dans ce magasin']; }
        $f = __DIR__ . '/../public/' . $m['photo'];
        if (is_file($f)) { @unlink($f); }
        Db::exec('DELETE FROM pil_cq_modele WHERE rayon = ? AND shop_id = ?', [$rayon, $shopId]);
        if (function_exists('journalAdd')) { journalAdd('CEO', 'Assistant de notation', 'Rayon ' . $rayon, 'Photo modèle retirée — boutique #' . $shopId); }
        return ep_ia_entrainement() + ['ok' => true];
    }
    $shopId = (int) ($b['shopId'] ?? 0); $taskId = (int) ($b['taskId'] ?? 0); $date = (string) ($b['date'] ?? '');
    $portee = (string) ($b['portee'] ?? 'magasin') === 'reseau' ? 'reseau' : 'magasin';
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    // La photo et son rayon : le même détail que le volet de notation.
    $_GET['shop'] = (string) $shopId; $_GET['task'] = (string) $taskId; $_GET['date'] = $date;
    $d = ep_pwa_task_detail();
    $rv = is_array($d['rayon'] ?? null) ? $d['rayon'] : null;
    if ($rv === null) { http_response_code(422); return ['error' => 'seule une « Photo du comptoir » a un rayon : cette tâche ne peut pas servir de modèle']; }
    if (empty($d['photo'])) { http_response_code(422); return ['error' => 'aucune photo sur cette tâche']; }
    [$octets, $err] = iaTelecharger((string) $d['photo']);
    if ($octets === null) { http_response_code(502); return ['error' => (string) $err]; }
    $info = @getimagesizefromstring($octets);
    $data = 'data:' . ((string) ($info['mime'] ?? 'image/jpeg')) . ';base64,' . base64_encode($octets);
    $cle = (string) $rv['cle'];
    if ($portee === 'magasin') {
        $f = psImage($data, 'uploads/rayons/modeles/' . $cle . '-' . $shopId . '-' . date('YmdHis'));
        if (isset($f['error'])) { http_response_code((int) $f['code']); return ['error' => $f['error']]; }
        $avant = iaModelePour($cle, $shopId);
        if ($avant !== null && is_file(__DIR__ . '/../public/' . $avant['photo'])) { @unlink(__DIR__ . '/../public/' . $avant['photo']); }
        Db::exec('REPLACE INTO pil_cq_modele (rayon, shop_id, photo, source_task, source_jour, tache, par, le) VALUES (?,?,?,?,?,?,?,?)',
            [$cle, $shopId, $f['chemin'], $taskId, $date, $d['tache'] ?? null, $auteur, date('Y-m-d H:i:s')]);
    } else {
        // Le réseau : la photo type du rayon (la consigne reste).
        ensureRayonsPhotos();
        $f = psImage($data, 'uploads/rayons/' . $cle . '-' . date('YmdHis'));
        if (isset($f['error'])) { http_response_code((int) $f['code']); return ['error' => $f['error']]; }
        $avant = Db::row('SELECT * FROM con_rayon_photo WHERE cle = ?', [$cle]);
        if ($avant !== null && !empty($avant['photo']) && is_file(__DIR__ . '/../public/' . $avant['photo'])) { @unlink(__DIR__ . '/../public/' . $avant['photo']); }
        if ($avant !== null) {
            Db::exec('UPDATE con_rayon_photo SET photo = ?, par = ?, le = ? WHERE cle = ?', [$f['chemin'], $auteur, date('Y-m-d H:i:s'), $cle]);
        } else {
            Db::exec('INSERT INTO con_rayon_photo (cle, nom, photo, consigne, par, le) VALUES (?,?,?,?,?,?)', [$cle, (string) $rv['nom'], $f['chemin'], null, $auteur, date('Y-m-d H:i:s')]);
        }
    }
    if (function_exists('journalAdd')) {
        journalAdd('CEO', 'Assistant de notation', 'Rayon ' . (string) $rv['nom'], 'Photo modèle ' . ($portee === 'magasin' ? 'du magasin #' . $shopId : 'du réseau')
            . ' : tâche #' . $taskId . ' du ' . $date);
    }
    return ep_ia_entrainement() + ['ok' => true, 'portee' => $portee, 'rayon' => $cle];
}

/**
 * GET /ia/entrainement — rayon par rayon : la consigne (et les critères par défaut quand elle est vide), la photo type
 * du réseau, les modèles des magasins, l'accord de l'IA avec le consultant, les leçons ; et les photos notées
 * Exemplaire ces 30 derniers jours, candidates au modèle.
 */
function ep_ia_entrainement(): array
{
    ensureIaAssistant(); ensureIaModeles();
    $depuis = date('Y-m-d', strtotime('-' . IA_ACCORD_JOURS . ' days'));
    $modeles = [];
    foreach (Db::rows('SELECT * FROM pil_cq_modele ORDER BY le DESC') as $m) {
        $modeles[(string) $m['rayon']][] = ['shopId' => (string) $m['shop_id'], 'photo' => $m['photo'], 'taskId' => $m['source_task'] !== null ? (string) $m['source_task'] : null,
            'date' => $m['source_jour'], 'par' => $m['par'], 'le' => $m['le']];
    }
    $accord = [];
    foreach (Db::rows('SELECT rayon, niveau, note_finale FROM pil_cq_ia WHERE rayon IS NOT NULL AND niveau IS NOT NULL AND note_finale IS NOT NULL AND jour >= ?', [$depuis]) as $r) {
        $a = &$accord[(string) $r['rayon']];
        $a = $a ?? ['notees' => 0, 'pareil' => 0, 'tropSevere' => 0, 'tropIndulgente' => 0];
        $d = (int) $r['niveau'] - (int) $r['note_finale'];
        $a['notees']++;
        if ($d === 0) { $a['pareil']++; } elseif ($d < 0) { $a['tropSevere']++; } else { $a['tropIndulgente']++; }
        unset($a);
    }
    $lecons = [];
    foreach (Db::rows("SELECT * FROM pil_cq_ia WHERE rayon IS NOT NULL AND niveau IS NOT NULL AND note_finale IS NOT NULL"
        . " AND (note_finale <> niveau OR (raison IS NOT NULL AND raison <> '')) ORDER BY valide_le DESC LIMIT 300") as $r) {
        $lecons[(string) $r['rayon']][] = iaPropositionLigne($r);
    }
    // Les candidates : notées Exemplaire par le consultant, pas encore modèles.
    $pris = [];
    foreach ($modeles as $l) { foreach ($l as $m) { $pris[$m['shopId'] . '|' . $m['taskId'] . '|' . $m['date']] = true; } }
    $candidates = [];
    foreach (Db::rows('SELECT * FROM pil_cq_ia WHERE rayon IS NOT NULL AND note_finale = ? AND jour >= ? ORDER BY jour DESC, valide_le DESC LIMIT 60', [iaNiveauMax(), $depuis]) as $r) {
        $l = iaPropositionLigne($r);
        if (empty($pris[$l['shopId'] . '|' . $l['taskId'] . '|' . $l['date']])) { $candidates[] = $l; }
    }
    $rayons = [];
    foreach ((function_exists('rvListe') ? rvListe() : []) as $rv) {
        $cle = (string) $rv['cle'];
        $a = $accord[$cle] ?? ['notees' => 0, 'pareil' => 0, 'tropSevere' => 0, 'tropIndulgente' => 0];
        $a['pct'] = $a['notees'] > 0 ? (int) round(100 * $a['pareil'] / $a['notees']) : null;
        $rayons[] = ['cle' => $cle, 'nom' => $rv['nom'], 'consigne' => $rv['consigne'], 'photo' => $rv['photo'], 'defaut' => (bool) ($rv['defaut'] ?? false),
            'criteresDefaut' => defined('RV_CRITERES_IA') ? (RV_CRITERES_IA[$cle] ?? null) : null,
            'modeles' => $modeles[$cle] ?? [], 'accord' => $a + ['jours' => IA_ACCORD_JOURS],
            'lecons' => array_slice($lecons[$cle] ?? [], 0, 20)];
    }
    return ['rayons' => $rayons, 'candidates' => array_slice($candidates, 0, 24), 'accord' => iaAccord(),
        'communs' => defined('RV_CRITERES_COMMUNS') ? RV_CRITERES_COMMUNS : '', 'leconsMax' => IA_LECONS_MAX,
        'niveauModele' => iaNiveauMax(), 'nomNiveauModele' => iaNomNiveau(iaNiveauMax())];
}
