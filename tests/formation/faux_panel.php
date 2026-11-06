<?php
declare(strict_types=1);

/*
 * Faux panel TF Buddy pour essayer le Manuel du réseau en local (correspondance des compétences, « vu faire »).
 * Aucune donnée réelle : tests/formation/fixtures/faux_panel.json (107 compétences et trois personnes FICTIVES).
 *
 *   php -S 127.0.0.1:8102 tests/formation/faux_panel.php
 *   PANEL_API_BASE=http://127.0.0.1:8102 PANEL_API_PHONE=essai PANEL_API_PASSWORD=essai \
 *     php -S 127.0.0.1:8101 -t public public/router.php
 *
 * Routes imitées (celles que src/sop.php et src/formation.php appellent) :
 *   POST /consultant/auth/login           → un jeton
 *   GET  /competencies                    → les 107 compétences
 *   GET  /shops/{id}/employees            → les trois personnes, PIN compris (le cockpit le vérifie côté serveur)
 *   GET  /employees/{id}                  → la fiche et ses compétences cochées
 *   PUT  /employees/{id}/competencies     → coche une compétence ({competency_id})
 * L'état des compétences cochées est gardé dans FAUX_PANEL_ETAT (défaut : dossier temporaire du système).
 */

$fix = json_decode((string) file_get_contents(__DIR__ . '/fixtures/faux_panel.json'), true);
$etatF = getenv('FAUX_PANEL_ETAT') ?: sys_get_temp_dir() . '/faux_panel_etat.json';
$etat = is_file($etatF) ? (json_decode((string) file_get_contents($etatF), true) ?: []) : [];
$uri = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = (string) preg_replace('#^/api/v1#', '', $uri);
$m = $_SERVER['REQUEST_METHOD'];
$corps = json_decode((string) file_get_contents('php://input'), true) ?: [];

header('Content-Type: application/json; charset=utf-8');
$sortir = static function (int $code, mixed $d): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); };
$journal = static function (string $l) { file_put_contents('php://stderr', '[faux panel] ' . $l . "\n"); };

if ($m === 'POST' && $uri === '/consultant/auth/login') { $sortir(200, ['access_token' => 'jeton-faux-panel']); return; }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer jeton-faux-panel') { $sortir(401, ['message' => 'jeton attendu']); return; }
if ($m === 'GET' && $uri === '/competencies') { $sortir(200, ['data' => $fix['competences']]); return; }
if ($m === 'GET' && preg_match('#^/shops/[\w-]+/employees$#', $uri)) { $sortir(200, ['data' => $fix['employes']]); return; }
if (preg_match('#^/employees/(\d+)(/competencies)?$#', $uri, $x)) {
    $e = null;
    foreach ($fix['employes'] as $y) { if ((int) $y['id'] === (int) $x[1]) { $e = $y; } }
    if ($e === null) { $sortir(404, ['message' => 'employé inconnu']); return; }
    $ids = $etat[(string) $e['id']] ?? [];
    if ($m === 'PUT' && isset($x[2])) {
        $cid = (int) ($corps['competency_id'] ?? 0);
        if ($cid <= 0) { $sortir(422, ['message' => 'competency_id attendu']); return; }
        if (!in_array($cid, $ids, true)) { $ids[] = $cid; }
        $etat[(string) $e['id']] = $ids;
        file_put_contents($etatF, json_encode($etat));
        $journal('PUT /employees/' . $e['id'] . '/competencies ' . $cid);
        $sortir(200, ['data' => ['ok' => true]]);
        return;
    }
    if ($m === 'GET' && !isset($x[2])) {
        $sortir(200, ['data' => ['id' => $e['id'], 'name' => $e['name'], 'surname' => $e['surname'], 'positions' => [],
            'competencies' => array_map(static fn ($c) => ['competency_id' => $c], $ids)]]);
        return;
    }
}
$journal($m . ' ' . $uri . ' → 404');
$sortir(404, ['message' => 'route absente du faux panel']);
