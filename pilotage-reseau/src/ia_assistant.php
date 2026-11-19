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

/* --- Le PLANOGRAMME et les photos d'EXEMPLE, l'entraînement par rayon (15/11/2026) --------------------------------
 *
 * Pour chaque rayon, l'IA juge une photo contre :
 *  1. la PHOTO DU PLANOGRAMME — la présentation attendue par le réseau, une par rayon (con_rayon_photo.photo, la
 *     « photo type » d'avant) : déposée comme fichier, ou prise parmi les vraies photos ;
 *  2. des PHOTOS D'EXEMPLE — de vraies photos que le consultant a jugées parfaites (pil_cq_exemple, plusieurs par
 *     rayon, chacune de son magasin). Avec chaque photo à noter partent les deux plus récentes, celles du même magasin
 *     d'abord : les comptoirs ne se ressemblent pas d'un magasin à l'autre.
 * Le consultant choisit ; l'IA ne choisit jamais ses propres exemples, elle renforcerait ses propres erreurs. Une
 * photo prise est COPIÉE sur le serveur : l'adresse signée du panel expire en 20 min.
 *
 * Routes : GET /ia/entrainement, POST /ia/modeles, PUT /ia/consigne-generale (la consigne générale des contrôles
 * qualité, src/rayons_photos.php).
 */

const IA_EXEMPLES_ENVOYES = 2;   // photos d'exemple envoyées avec une photo à noter, en plus du planogramme

function ensureIaModeles(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_cq_exemple ('
        . 'rayon VARCHAR(80) NOT NULL,'             // clé du rayon (src/rayons_photos.php)
        . 'shop_id INT NOT NULL,'                   // le magasin de la photo
        . 'source_task INT NOT NULL,'               // la tâche et le jour d'où vient la photo
        . 'source_jour DATE NOT NULL,'
        . 'photo VARCHAR(255) NOT NULL,'            // chemin sous public/ (uploads/rayons/exemples/…)
        . 'tache VARCHAR(190) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NOT NULL,'
        . 'PRIMARY KEY (rayon, shop_id, source_task, source_jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Les photos d'exemple d'un rayon, celles du magasin d'abord, les plus récentes ensuite. */
function iaExemplesPour(?string $rayon, int $shopId, int $max = IA_EXEMPLES_ENVOYES): array
{
    if ($rayon === null || $rayon === '') { return []; }
    try {
        ensureIaModeles();
        return Db::rows('SELECT * FROM pil_cq_exemple WHERE rayon = ? ORDER BY CASE WHEN shop_id = ? THEN 0 ELSE 1 END, le DESC LIMIT ' . max(1, $max), [$rayon, $shopId]);
    } catch (PDOException $e) { return []; }
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

/** Le niveau le plus haut du barème (Exemplaire) : une photo notée ainsi est candidate à l'exemple. */
function iaNiveauMax(): int
{
    $b = iaBareme();
    return $b ? max(array_keys($b)) : 5;
}

/**
 * POST /ia/modeles —
 *  {shopId, taskId, date, portee: "exemple"}     la photo de cette tâche devient une photo d'exemple de son rayon ;
 *  {shopId, taskId, date, portee: "planogramme"} elle devient la photo du planogramme du rayon (la consigne reste) ;
 *  {rayon, shopId, taskId, date, retirer: true}  retirer une photo d'exemple.
 */
function wr_ia_modeles(): array
{
    $b = body();
    ensureIaModeles();
    $auteur = function_exists('psAuteur') ? psAuteur() : 'CEO';
    $shopId = (int) ($b['shopId'] ?? 0); $taskId = (int) ($b['taskId'] ?? 0); $date = (string) ($b['date'] ?? '');
    if (!empty($b['retirer'])) {
        $rayon = (string) ($b['rayon'] ?? '');
        $e = Db::row('SELECT * FROM pil_cq_exemple WHERE rayon = ? AND shop_id = ? AND source_task = ? AND source_jour = ?', [$rayon, $shopId, $taskId, $date]);
        if ($e === null) { http_response_code(404); return ['error' => 'photo d’exemple introuvable']; }
        $f = __DIR__ . '/../public/' . $e['photo'];
        if (is_file($f)) { @unlink($f); }
        Db::exec('DELETE FROM pil_cq_exemple WHERE rayon = ? AND shop_id = ? AND source_task = ? AND source_jour = ?', [$rayon, $shopId, $taskId, $date]);
        if (function_exists('journalAdd')) { journalAdd('CEO', 'Assistant de notation', 'Rayon ' . $rayon, 'Photo d’exemple retirée — boutique #' . $shopId . ', ' . $date); }
        return ep_ia_entrainement() + ['ok' => true];
    }
    $portee = (string) ($b['portee'] ?? 'exemple') === 'planogramme' ? 'planogramme' : 'exemple';
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shopId, taskId et date (YYYY-MM-DD) sont requis'];
    }
    // La photo et son rayon : le même détail que le volet de notation.
    $_GET['shop'] = (string) $shopId; $_GET['task'] = (string) $taskId; $_GET['date'] = $date;
    $d = ep_pwa_task_detail();
    $rv = is_array($d['rayon'] ?? null) ? $d['rayon'] : null;
    if ($rv === null) { http_response_code(422); return ['error' => 'seule une « Photo du comptoir » a un rayon : cette tâche ne peut pas servir d’exemple']; }
    if (empty($d['photo'])) { http_response_code(422); return ['error' => 'aucune photo sur cette tâche']; }
    [$octets, $err] = iaTelecharger((string) $d['photo']);
    if ($octets === null) { http_response_code(502); return ['error' => (string) $err]; }
    $info = @getimagesizefromstring($octets);
    $data = 'data:' . ((string) ($info['mime'] ?? 'image/jpeg')) . ';base64,' . base64_encode($octets);
    $cle = (string) $rv['cle'];
    if ($portee === 'exemple') {
        $f = psImage($data, 'uploads/rayons/exemples/' . $cle . '-' . $shopId . '-' . $taskId . '-' . str_replace('-', '', $date) . '-' . date('His'));
        if (isset($f['error'])) { http_response_code((int) $f['code']); return ['error' => $f['error']]; }
        $avant = Db::row('SELECT photo FROM pil_cq_exemple WHERE rayon = ? AND shop_id = ? AND source_task = ? AND source_jour = ?', [$cle, $shopId, $taskId, $date]);
        if ($avant !== null && is_file(__DIR__ . '/../public/' . $avant['photo'])) { @unlink(__DIR__ . '/../public/' . $avant['photo']); }
        Db::exec('REPLACE INTO pil_cq_exemple (rayon, shop_id, source_task, source_jour, photo, tache, par, le) VALUES (?,?,?,?,?,?,?,?)',
            [$cle, $shopId, $taskId, $date, $f['chemin'], $d['tache'] ?? null, $auteur, date('Y-m-d H:i:s')]);
    } else {
        // Le planogramme du rayon : la photo de référence du réseau (con_rayon_photo.photo ; la consigne reste).
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
        journalAdd('CEO', 'Assistant de notation', 'Rayon ' . (string) $rv['nom'], ($portee === 'exemple' ? 'Photo d’exemple ajoutée' : 'Photo du planogramme posée')
            . ' : boutique #' . $shopId . ', tâche #' . $taskId . ' du ' . $date);
    }
    return ep_ia_entrainement() + ['ok' => true, 'portee' => $portee, 'rayon' => $cle];
}

/**
 * PUT /ia/consigne-generale — {points: ["…", "…"]} remplace les points de la consigne générale des contrôles qualité
 * (src/rayons_photos.php) ; {retablir: true} revient à la liste par défaut. Un point vide est écarté ; trop de points
 * ou un point trop long : 422, rien n'est écrit. Rend l'entraînement relu.
 */
function wr_ia_consigne_generale(): array
{
    $b = body();
    $auteur = function_exists('psAuteur') ? psAuteur() : 'CEO';
    if (!empty($b['retablir'])) {
        Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['cqConsigneGenerale']);
        if (function_exists('journalAdd')) { journalAdd('CEO', 'Assistant de notation', 'Consigne générale', 'Consigne par défaut rétablie (' . $auteur . ')'); }
        return ep_ia_entrainement() + ['ok' => true];
    }
    if (!isset($b['points']) || !is_array($b['points']) || !array_is_list($b['points'])) {
        http_response_code(422);
        return ['error' => 'points attendus : une liste de textes'];
    }
    $points = array_values(array_filter(array_map('cqPoint', $b['points']), static fn (string $p) => $p !== ''));
    if (count($points) > CQ_POINTS_MAX) {
        http_response_code(422);
        return ['error' => CQ_POINTS_MAX . ' points au plus'];
    }
    foreach ($points as $i => $p) {
        if (mb_strlen($p) > CQ_POINT_LONGUEUR) {
            http_response_code(422);
            return ['error' => 'le point ' . ($i + 1) . ' dépasse ' . CQ_POINT_LONGUEUR . ' caractères'];
        }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['cqConsigneGenerale', json_encode($points, JSON_UNESCAPED_UNICODE)]);
    if (function_exists('journalAdd')) {
        journalAdd('CEO', 'Assistant de notation', 'Consigne générale', count($points) . ' point' . (count($points) > 1 ? 's' : '') . ' enregistré' . (count($points) > 1 ? 's' : '') . ' (' . $auteur . ')');
    }
    return ep_ia_entrainement() + ['ok' => true];
}

/**
 * GET /ia/entrainement — rayon par rayon : la photo du planogramme, les photos d'exemple, la consigne (et les critères
 * par défaut quand elle est vide), l'accord de l'IA avec le consultant, les leçons ; et les photos notées Exemplaire
 * ces 30 derniers jours, candidates à l'exemple.
 */
function ep_ia_entrainement(): array
{
    ensureIaAssistant(); ensureIaModeles();
    $depuis = date('Y-m-d', strtotime('-' . IA_ACCORD_JOURS . ' days'));
    $exemples = [];
    foreach (Db::rows('SELECT * FROM pil_cq_exemple ORDER BY le DESC') as $m) {
        $exemples[(string) $m['rayon']][] = ['shopId' => (string) $m['shop_id'], 'photo' => $m['photo'], 'taskId' => (string) $m['source_task'],
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
    // Les candidates : notées Exemplaire par le consultant, pas encore prises en exemple.
    $pris = [];
    foreach ($exemples as $l) { foreach ($l as $m) { $pris[$m['shopId'] . '|' . $m['taskId'] . '|' . $m['date']] = true; } }
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
        $rayons[] = ['cle' => $cle, 'nom' => $rv['nom'], 'consigne' => $rv['consigne'], 'planogramme' => $rv['photo'], 'defaut' => (bool) ($rv['defaut'] ?? false),
            'criteresDefaut' => defined('RV_CRITERES_IA') ? (RV_CRITERES_IA[$cle] ?? null) : null,
            'exemples' => $exemples[$cle] ?? [], 'accord' => $a + ['jours' => IA_ACCORD_JOURS],
            'lecons' => array_slice($lecons[$cle] ?? [], 0, 20)];
    }
    return ['rayons' => $rayons, 'candidates' => array_slice($candidates, 0, 24), 'accord' => iaAccord(),
        'communs' => defined('RV_CRITERES_COMMUNS') ? RV_CRITERES_COMMUNS : '', 'leconsMax' => IA_LECONS_MAX, 'exemplesEnvoyes' => IA_EXEMPLES_ENVOYES,
        // La consigne générale des contrôles qualité, pour toute photo de contrôle (src/rayons_photos.php) : ses points, et s'ils sont ceux par défaut.
        'consigneGenerale' => function_exists('cqConsigneGenerale') ? cqConsigneGenerale() : [],
        'consigneGeneraleDefaut' => !is_array(function_exists('setting') ? setting('cqConsigneGenerale', null) : null),
        'consigneGeneraleMax' => defined('CQ_POINTS_MAX') ? CQ_POINTS_MAX : 20,
        'niveauModele' => iaNiveauMax(), 'nomNiveauModele' => iaNomNiveau(iaNiveauMax())];
}

/* --- L'ASSISTANT D'ENTRAÎNEMENT (15/11/2026) -------------------------------------------------------------------------
 *
 * Pour un rayon (ou une tâche qui n'est pas une photo de comptoir) et une période, il relit les évaluations gardées
 * (pil_cq_ia) : ce que l'IA a vu et proposé sur chaque photo, la note que le consultant a posée et pourquoi. Un seul
 * appel au modèle, en TEXTE (Anthropic::analyserSujet), rend ce qui va, ce qui ne va pas (fréquence, magasins), les
 * désaccords à enseigner, une consigne proposée, des conseils aux magasins et les photos à prendre en exemple. La
 * dernière analyse de chaque sujet est gardée (pil_cq_analyse) : on la relit sans repayer.
 *
 * Routes : GET /ia/analyses, POST /ia/analyser.
 */

const IA_ANALYSE_MAX_PHOTOS = 120;   // lignes du dossier au plus (les plus récentes)
const IA_ANALYSE_MIN_PHOTOS = 3;     // en dessous, rien de sérieux à tirer

function ensureIaAnalyses(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_cq_analyse ('
        . 'sujet VARCHAR(200) NOT NULL PRIMARY KEY,'   // clé du rayon, ou « tache:<nom> »
        . 'jours INT NOT NULL,'
        . 'nb INT NOT NULL,'                          // photos relues
        . 'notees INT NOT NULL,'                      // dont notées par le consultant
        . 'resultat MEDIUMTEXT NOT NULL,'             // l'analyse, en JSON
        . 'modele VARCHAR(80) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Les noms courts des magasins (« Halle ») : [id => nom]. */
function iaNomsMagasins(): array
{
    static $noms = null;
    if ($noms !== null) { return $noms; }
    $noms = [];
    try {
        foreach (function_exists('ep_stores') ? ep_stores() : [] as $s) {
            $n = (string) ($s['nom'] ?? '');
            if ($n !== '') { $noms[(int) ($s['id'] ?? 0)] = str_contains($n, ' - ') ? trim(substr($n, strrpos($n, ' - ') + 3)) : $n; }
        }
    } catch (Throwable $e) { /* sans référentiel : les numéros */ }
    return $noms;
}

function iaNomMagasin(int $id): string
{
    return iaNomsMagasins()[$id] ?? ('magasin #' . $id);
}

/** Un sujet : ['type' => 'rayon'|'tache', 'cle' => …, 'nom' => …, 'rayon' => ?array]. */
function iaSujet(string $sujet): ?array
{
    if (str_starts_with($sujet, 'tache:')) {
        $t = trim(substr($sujet, 6));
        return $t !== '' ? ['type' => 'tache', 'cle' => $sujet, 'nom' => $t, 'rayon' => null] : null;
    }
    foreach ((function_exists('rvListe') ? rvListe() : []) as $rv) {
        if ((string) $rv['cle'] === $sujet) { return ['type' => 'rayon', 'cle' => $sujet, 'nom' => (string) $rv['nom'], 'rayon' => $rv]; }
    }
    return null;
}

/** Les évaluations d'un sujet sur la période, les plus récentes d'abord. */
function iaEvaluations(array $s, int $jours): array
{
    ensureIaAssistant();
    $depuis = date('Y-m-d', strtotime('-' . max(1, $jours) . ' days'));
    return $s['type'] === 'rayon'
        ? Db::rows('SELECT * FROM pil_cq_ia WHERE rayon = ? AND niveau IS NOT NULL AND jour >= ? ORDER BY jour DESC, shop_id LIMIT ' . IA_ANALYSE_MAX_PHOTOS, [$s['cle'], $depuis])
        : Db::rows('SELECT * FROM pil_cq_ia WHERE rayon IS NULL AND tache = ? AND niveau IS NOT NULL AND jour >= ? ORDER BY jour DESC, shop_id LIMIT ' . IA_ANALYSE_MAX_PHOTOS, [$s['nom'], $depuis]);
}

/**
 * Le dossier que lit le modèle : le contexte du sujet, puis une ligne par photo (P1, P2…). Rend [texte, refs] où refs
 * relie chaque repère à sa photo.
 */
function iaDossier(array $s, array $rows, int $jours): array
{
    $rv = $s['rayon'];
    $tete = $s['type'] === 'rayon' ? 'Rayon « ' . $s['nom'] . ' »' : 'Tâche « ' . $s['nom'] . ' »';
    if ($rv !== null) {
        $consigne = trim((string) ($rv['consigne'] ?? ''));
        $defaut = defined('RV_CRITERES_IA') ? (RV_CRITERES_IA[$s['cle']] ?? '') : '';
        $tete .= "\nConsigne actuelle du rayon : " . ($consigne !== '' ? $consigne : '(aucune — critères par défaut : ' . ($defaut !== '' ? $defaut : 'aucun') . ')')
            . "\nPhoto du planogramme : " . (!empty($rv['photo']) ? 'posée' : 'absente')
            . ' ; photos d’exemple : ' . (function_exists('iaExemplesPour') ? count(iaExemplesPour($s['cle'], 0, 50)) : 0) . '.';
    }
    $notees = count(array_filter($rows, fn ($r) => $r['note_finale'] !== null));
    $tete .= "\nPériode : " . $jours . ' jours, ' . count($rows) . ' photos évaluées par l’assistant, dont ' . $notees . ' notées par le consultant.';
    $lignes = []; $refs = [];
    foreach (array_values($rows) as $i => $r) {
        $id = 'P' . ($i + 1);
        $refs[$id] = ['shopId' => (string) $r['shop_id'], 'taskId' => (string) $r['task_id'], 'date' => (string) $r['jour'], 'magasin' => iaNomMagasin((int) $r['shop_id']), 'tache' => $r['tache']];
        $constats = json_decode((string) ($r['constats'] ?? '[]'), true);
        $ia = 'assistant : ' . (int) $r['niveau'] . '/5 ' . iaNomNiveau((int) $r['niveau']) . ' (confiance ' . ($r['confiance'] ?? '?') . ')'
            . (is_array($constats) && $constats ? ' ; vu : ' . implode(' / ', array_map(fn ($c) => mb_substr((string) $c, 0, 180), array_slice($constats, 0, 4))) : '');
        $humain = $r['note_finale'] === null ? 'consultant : pas encore noté'
            : 'consultant : ' . (int) $r['note_finale'] . '/5 ' . iaNomNiveau((int) $r['note_finale'])
              . (trim((string) ($r['raison'] ?? '')) !== '' ? ' — sa raison : « ' . mb_substr((string) $r['raison'], 0, 300) . ' »' : '')
              . (trim((string) ($r['commentaire_final'] ?? '')) !== '' ? ' — commentaire au magasin : « ' . mb_substr((string) $r['commentaire_final'], 0, 240) . ' »' : '');
        $heure = function_exists('iaPonctualite') ? iaPonctualite($r['heure_prevue'] ?? null, $r['heure_prise'] ?? null) : null;
        $lignes[] = $id . ' · ' . $r['jour'] . ' · ' . iaNomMagasin((int) $r['shop_id'])
            . ($heure !== null ? ' · photo ' . $heure['verdict'] : '') . "\n  " . $ia . "\n  " . $humain;
    }
    return [$tete . "\n\nLes évaluations :\n" . implode("\n", $lignes), $refs];
}

/** Une analyse gardée, telle que l'écran la lit. */
function iaAnalyseLigne(array $r): array
{
    $res = json_decode((string) $r['resultat'], true);
    return ['sujet' => $r['sujet'], 'jours' => (int) $r['jours'], 'nb' => (int) $r['nb'], 'notees' => (int) $r['notees'],
        'modele' => $r['modele'], 'par' => $r['par'], 'le' => $r['le']] + (is_array($res) ? $res : []);
}

/**
 * GET /ia/analyses — les sujets qu'on peut analyser (les rayons, puis les autres tâches évaluées), le nombre de photos
 * évaluées sur 90 jours, et la dernière analyse de chacun.
 */
function ep_ia_analyses(): array
{
    ensureIaAssistant(); ensureIaAnalyses();
    $depuis = date('Y-m-d', strtotime('-90 days'));
    $parRayon = []; $parTache = [];
    foreach (Db::rows('SELECT rayon, tache, COUNT(*) AS n FROM pil_cq_ia WHERE niveau IS NOT NULL AND jour >= ? GROUP BY rayon, tache', [$depuis]) as $r) {
        if ($r['rayon'] !== null) { $parRayon[(string) $r['rayon']] = ($parRayon[(string) $r['rayon']] ?? 0) + (int) $r['n']; }
        elseif ($r['tache'] !== null) { $parTache[(string) $r['tache']] = (int) $r['n']; }
    }
    $analyses = [];
    foreach (Db::rows('SELECT * FROM pil_cq_analyse ORDER BY le DESC') as $r) { $analyses[(string) $r['sujet']] = iaAnalyseLigne($r); }
    $sujets = [];
    foreach ((function_exists('rvListe') ? rvListe() : []) as $rv) {
        $sujets[] = ['cle' => (string) $rv['cle'], 'nom' => (string) $rv['nom'], 'type' => 'rayon', 'n' => $parRayon[(string) $rv['cle']] ?? 0];
    }
    ksort($parTache);
    foreach ($parTache as $t => $n) { $sujets[] = ['cle' => 'tache:' . $t, 'nom' => $t, 'type' => 'tache', 'n' => $n]; }
    return ['sujets' => $sujets, 'analyses' => (object) $analyses, 'min' => IA_ANALYSE_MIN_PHOTOS];
}

/** POST /ia/analyser {sujet, jours} — relit les évaluations du sujet et garde l'analyse. */
function wr_ia_analyser(): array
{
    $b = body();
    $s = iaSujet((string) ($b['sujet'] ?? ''));
    $jours = in_array((int) ($b['jours'] ?? 30), [7, 14, 30, 90], true) ? (int) $b['jours'] : 30;
    if ($s === null) { http_response_code(400); return ['error' => 'sujet inconnu : un rayon, ou « tache:<nom> »']; }
    $rows = iaEvaluations($s, $jours);
    if (count($rows) < IA_ANALYSE_MIN_PHOTOS) {
        http_response_code(422);
        return ['error' => count($rows) . ' photo' . (count($rows) > 1 ? 's' : '') . ' évaluée' . (count($rows) > 1 ? 's' : '') . ' sur ' . $jours
            . ' jours pour « ' . $s['nom'] . ' » : il en faut ' . IA_ANALYSE_MIN_PHOTOS . ' au moins. Passez des photos en mode assisté, ou allongez la période.'];
    }
    [$texte, $refs] = iaDossier($s, $rows, $jours);
    $r = Anthropic::analyserSujet($texte);
    if ($r['analyse'] === null) { http_response_code(str_contains((string) $r['erreur'], 'clé') ? 503 : 502); return ['error' => (string) $r['erreur']]; }
    $a = $r['analyse'];
    // Les photos suggérées en exemple : le repère P… relié à sa photo ; un repère inconnu est écarté.
    $a['exemples_suggeres'] = array_values(array_filter(array_map(static function ($e) use ($refs) {
        $ref = strtoupper(trim((string) ($e['ref'] ?? '')));
        return isset($refs[$ref]) ? $refs[$ref] + ['ref' => $ref, 'pourquoi' => (string) ($e['pourquoi'] ?? '')] : null;
    }, (array) ($a['exemples_suggeres'] ?? []))));
    $a['consigne_proposee'] = mb_substr(trim((string) ($a['consigne_proposee'] ?? '')), 0, 600);
    $a['type'] = $s['type']; $a['nom'] = $s['nom'];
    ensureIaAnalyses();
    $notees = count(array_filter($rows, fn ($x) => $x['note_finale'] !== null));
    $auteur = function_exists('psAuteur') ? psAuteur() : 'CEO';
    Db::exec('REPLACE INTO pil_cq_analyse (sujet, jours, nb, notees, resultat, modele, par, le) VALUES (?,?,?,?,?,?,?,?)',
        [$s['cle'], $jours, count($rows), $notees, json_encode($a, JSON_UNESCAPED_UNICODE), $r['modele'], $auteur, date('Y-m-d H:i:s')]);
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Assistant de notation', null, 'Analyse « ' . $s['nom'] . ' » : ' . count($rows) . ' photos sur ' . $jours . ' jours'); }
    return ['ok' => true, 'analyse' => iaAnalyseLigne(Db::row('SELECT * FROM pil_cq_analyse WHERE sujet = ?', [$s['cle']]) ?? [])];
}
