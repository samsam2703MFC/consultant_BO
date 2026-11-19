<?php
declare(strict_types=1);

/*
 * SONDE — Contrôles : la vérification IA des photos (mode assisté et « Proposer une évaluation »), en vrai
 * (19/11/2026). Sam : « je n'arrive pas à lancer la vérification IA sur les photos ». Le workflow
 * (.github/workflows/sonde-ia-photo.yml) lance ce script deux fois sur le serveur :
 *   php -- /var/www/consulant_bo deploye  < tools/sonde_ia_photo.php   le code en ligne (src/anthropic.php du site)
 *   php -- /var/www/consulant_bo nouveau  < tools/sonde_ia_photo.php   la correction, déposée dans /tmp
 * Chaque passage :
 *   0. dit l'état de l'assistance (configurée, modèle, espace de travail), sans jamais montrer la clé ;
 *   1. (en ligne seulement) la dernière trace du connecteur « Assistance IA » et les propositions gardées
 *      (pil_cq_ia) des sept derniers jours : combien, combien en échec, et pourquoi ;
 *   2. reprend la dernière photo en échec (sinon une photo récente) et la décrit (poids, type, dimensions) ;
 *   3. redemande la proposition par la route du bouton (ep_ia_note) et rend la réponse, ou l'erreur.
 * Écritures : la trace du connecteur « anthropic » (ceo_connecteur), la même qu'un clic. Rien d'autre : la
 * proposition n'est PAS enregistrée dans pil_cq_ia (ce serait wr_ia_proposer).
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$passage = ($argv[2] ?? 'deploye') === 'nouveau' ? 'nouveau' : 'deploye';
echo "##### passage : ", $passage === 'nouveau' ? 'la CORRECTION (/tmp/sonde_ia_anthropic.php)' : 'le code EN LIGNE', "\n";

// Les fichiers de l'API (la liste de public/api/index.php, Db.php compris), anthropic.php pris selon le passage.
require_once $passage === 'nouveau' ? '/tmp/sonde_ia_anthropic.php' : "$dir/src/anthropic.php";
preg_match_all("#require(?:_once)? __DIR__ \. '/\.\./\.\./src/([A-Za-z_]+\.php)'#", (string) file_get_contents("$dir/public/api/index.php"), $mm);
foreach ($mm[1] as $fic) { if ($fic !== 'anthropic.php') { require_once "$dir/src/$fic"; } }

echo "=== 0. L'assistance IA\n";
$en = (string) @file_get_contents("$dir/src/anthropic.php");
printf("  src/anthropic.php en ligne : %d octets · md5 %s · réduit les photos : %s\n",
    strlen($en), substr(md5($en), 0, 10), str_contains($en, 'function preparerImage') ? 'oui' : 'non');
$cfg = Anthropic::config();
$st = setting('anthropic', []);
$st = is_array($st) ? $st : [];
printf("  configurée : %s · modèle : %s (réglage cockpit : %s) · espace de travail : %s\n",
    Anthropic::configured() ? 'oui' : 'NON', $cfg['modele'], trim((string) ($st['modele'] ?? '')) ?: '—',
    $cfg['espace'] !== '' ? 'oui' : 'aucun');
printf("  la clé vient de : %s\n", trim((string) ($st['cle'] ?? '')) !== '' ? 'réglage du cockpit'
    : (erpCle('anthropic_api_key') !== '' ? 'erp_cles (anthropic_api_key)' : (getenv('ANTHROPIC_API_KEY') ? 'environnement' : 'NULLE PART')));
printf("  GD : %s · exif : %s · memory_limit %s\n", function_exists('imagecreatefromstring') ? 'oui' : 'NON',
    function_exists('exif_read_data') ? 'oui' : 'non', ini_get('memory_limit'));

$cible = null;
if ($passage === 'deploye') {
    echo "=== 1. Ce que l'écran a vu\n";
    try {
        $c = Db::row("SELECT * FROM ceo_connecteur WHERE code = 'anthropic'");
        echo "  connecteur : ";
        echo $c === null ? "aucune ligne\n" : sprintf("%s appels · dernier %s · dernier succès %s · dernière erreur %s : %s\n",
            (string) $c['runs'], (string) $c['last_run_at'], (string) ($c['last_ok_at'] ?? '—'), (string) ($c['last_error_at'] ?? '—'),
            (string) ($c['last_error'] ?? '—'));
    } catch (Throwable $e) { echo "  connecteur illisible : ", $e->getMessage(), "\n"; }
    try {
        foreach (Db::rows('SELECT jour, COUNT(*) n, SUM(niveau IS NOT NULL) ok, SUM(niveau IS NULL) ko, MAX(propose_le) der'
            . ' FROM pil_cq_ia WHERE jour >= CURDATE() - INTERVAL 7 DAY GROUP BY jour ORDER BY jour DESC') as $r) {
            printf("  %s : %3d proposition(s) · %3d rendue(s) · %3d en échec · dernière demande %s\n", $r['jour'], $r['n'], $r['ok'], $r['ko'], $r['der']);
        }
        echo "  motifs d'échec (7 jours) :\n";
        foreach (Db::rows('SELECT motif, COUNT(*) n, MAX(propose_le) der FROM pil_cq_ia WHERE niveau IS NULL'
            . ' AND jour >= CURDATE() - INTERVAL 7 DAY GROUP BY motif ORDER BY n DESC LIMIT 12') as $r) {
            printf("    %3d × %s (dernier %s)\n", $r['n'], (string) $r['motif'], $r['der']);
        }
        $cible = Db::row('SELECT shop_id, task_id, jour, motif FROM pil_cq_ia WHERE niveau IS NULL ORDER BY propose_le DESC LIMIT 1');
    } catch (Throwable $e) { echo "  pil_cq_ia illisible : ", $e->getMessage(), "\n"; }
    file_put_contents('/tmp/sonde_ia_cible.json', json_encode($cible));
} else {
    $cible = json_decode((string) @file_get_contents('/tmp/sonde_ia_cible.json'), true) ?: null;
}

echo "=== 2. La photo\n";
if ($cible !== null) {
    $trouve = ['shop' => (int) $cible['shop_id'], 'date' => (string) $cible['jour'], 'task' => (int) $cible['task_id']];
    printf("  la dernière en échec : magasin %d · %s · tâche %d · motif gardé : %s\n", $trouve['shop'], $trouve['date'], $trouve['task'], (string) ($cible['motif'] ?? ''));
} else {
    $trouve = null;
    foreach ([0, 1, 2] as $j) {
        $date = date('Y-m-d', strtotime("-$j day"));
        foreach ([4, 1, 2, 3, 5, 6] as $sid) {
            $_GET = ['shop' => (string) $sid, 'date' => $date];
            $p = ep_pwa_tasks_photos();
            if (!empty($p['photos'])) { $trouve = ['shop' => $sid, 'date' => $date, 'task' => (int) $p['photos'][0]['taskId']]; break 2; }
        }
    }
    if ($trouve === null) { echo "  aucune photo sur 3 jours\n"; exit(0); }
    printf("  aucun échec gardé ; photo récente : magasin %d · %s · tâche %d\n", $trouve['shop'], $trouve['date'], $trouve['task']);
}
$_GET = ['shop' => (string) $trouve['shop'], 'task' => (string) $trouve['task'], 'date' => $trouve['date']];
$d = ep_pwa_task_detail();
printf("  tâche « %s » · fiche produit : %s · rayon : %s · erreur panel : %s\n", (string) ($d['tache'] ?? '?'),
    !empty($d['photoRef']) ? 'oui' : 'non', !empty($d['rayon']) ? (string) $d['rayon']['nom'] : '—', (string) ($d['api']['erreur'] ?? '—'));
foreach (['photo' => $d['photo'] ?? null, 'photoRef' => $d['photoRef'] ?? null] as $nom => $url) {
    if (!$url) { printf("  %-8s aucune\n", $nom); continue; }
    $ch = curl_init((string) $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
    $t0 = microtime(true); $o = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $im = is_string($o) ? @getimagesizefromstring($o) : false;
    printf("  %-8s HTTP %d en %.1f s · « %s » annoncé · %.2f Mo (%.2f Mo en base64) · %s %s\n", $nom, $code, microtime(true) - $t0, $ct ?: '?',
        is_string($o) ? strlen($o) / 1048576 : 0, is_string($o) ? ceil(strlen($o) / 3) * 4 / 1048576 : 0,
        is_array($im) ? (string) $im['mime'] : 'type non reconnu', is_array($im) ? $im[0] . ' × ' . $im[1] : '');
}
foreach ((array) ($d['exemples'] ?? []) as $ex) {
    $f = "$dir/public/" . (string) ($ex['photo'] ?? '');
    printf("  exemple  %s · %s\n", (string) ($ex['photo'] ?? '?'), is_file($f) ? round(filesize($f) / 1048576, 2) . ' Mo' : 'ABSENT');
}
if (!empty($d['rayon']['photo'])) {
    $f = "$dir/public/" . $d['rayon']['photo'];
    printf("  planogramme %s · %s\n", $d['rayon']['photo'], is_file($f) ? round(filesize($f) / 1048576, 2) . ' Mo' : 'ABSENT');
}

echo "=== 3. La proposition, par la route du bouton (ep_ia_note, sans l'enregistrer)\n";
$t0 = microtime(true);
$r = ep_ia_note();
printf("  en %.1f s · %d référence(s) envoyée(s) · %d leçon(s)\n", microtime(true) - $t0, (int) ($r['references'] ?? 0), (int) ($r['leconsVues'] ?? 0));
echo json_encode(array_intersect_key($r, array_flip(['etat', 'motif', 'niveau', 'nom', 'confiance', 'modele', 'commentaire', 'constats', 'avertissement'])),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
echo "fin du passage $passage\n";
