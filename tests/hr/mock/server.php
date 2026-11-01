<?php

declare(strict_types=1);

/**
 * Faux serveurs pour les tests d'intégration et le développement local du module RH — AUCUN appel à l'ONSS
 * réel. Servi par php -S (via tests/hr/dev-router.php) :
 *
 *   /REST/oauth/v5/token                  serveur OAuth : contrôle l'assertion (claims, et signature si le
 *                                         certificat du compte est connu), rend un jeton de 600 s
 *   /REST/dimona/v2/declarations          POST → 201 + Location ; GET /{id} → 404 tant que « non traitée »
 *                                         (HR_MOCK_PENDING relectures), puis le verdict
 *   /REST/dimona/v2/declarations/search   par référence
 *   /REST/dimona/v2/periods/search        les périodes créées par les IN acceptées
 *   /webshop/api/bo/{franchisee|franchisor}/{login,me,logout}   la console franchisé (comptes de test)
 *
 * Les verdicts reprennent les exemples de la doc fonctionnelle v2 (§ 7.2, p. 22-24) et du YAML :
 *   - IN d'un étudiant à plus de 400 heures planifiées → W, « Dépassement du contingent » (90017-369) ;
 *   - heure de fin antérieure à l'heure de début → B, « Heure de fin antérieure… » (00778-345) ;
 *   - NISS 00010100105 → S (en attente SIGeDIS) ; sinon → A.
 *
 * État : un fichier JSON (HR_MOCK_STATE, sinon le dossier temporaire), verrouillé à chaque requête.
 */

$stateFile = getenv('HR_MOCK_STATE') ?: sys_get_temp_dir() . '/hr-mock-state.json';
$pendingReads = (int) (getenv('HR_MOCK_PENDING') ?: 1);
$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$raw = (string) file_get_contents('php://input');

$fh = fopen($stateFile, 'c+');
flock($fh, LOCK_EX);
$state = json_decode((string) stream_get_contents($fh), true) ?: ['tokens' => [], 'declarations' => [], 'periods' => [], 'next' => 600000000001, 'log' => [], 'certs' => []];
$save = static function () use (&$state, $fh): void {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($state));
    fflush($fh);
};
$out = static function (int $status, mixed $body = null, array $headers = []) use ($save, $fh): never {
    $save();
    flock($fh, LOCK_UN);
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
};
$b64 = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$state['log'][] = $method . ' ' . $uri;

/* ---------------------------------------------------------------- console franchisé */
$accounts = [
    'franchise@test.be' => ['id' => 11, 'role' => 'franchisee', 'display_name' => 'Julie Franchisée', 'shops' => [4, 7]],
    'siege@test.be' => ['id' => 21, 'role' => 'franchisor', 'display_name' => 'Siège L’Atelier', 'shops' => null],
];
if (preg_match('#^/webshop/api/bo/(franchisee|franchisor)/(login|me|logout)$#', $uri, $m)) {
    [$all, $bo, $action] = $m;
    $cookieName = 'fb_' . $bo . '_session';
    if (getenv('HR_MOCK_CONSOLE_OFF') === '1') {
        $out(500, ['error' => "Back-office '$bo' non configuré (section 'bo' de config.php)"]);
    }
    if ($action === 'login' && $method === 'POST') {
        $j = json_decode($raw, true) ?: [];
        $a = $accounts[strtolower((string) ($j['email'] ?? ''))] ?? null;
        if ($a === null || ($j['password'] ?? '') !== 'test1234' || $a['role'] !== $bo) {
            $out(401, ['error' => 'Identifiants incorrects.']);
        }
        $tok = 'mock.' . bin2hex(random_bytes(8));
        $state['sessions'][$tok] = $a['id'];
        setcookie($cookieName, $tok, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $out(200, ['user' => ['id' => $a['id'], 'email' => array_search($a, $accounts, true), 'display_name' => $a['display_name'], 'role' => $bo === 'franchisee' ? 'franchise' : 'siege'] + ($a['shops'] !== null ? ['shops' => $a['shops']] : []), 'csrf' => 'csrf-' . $a['id']]);
    }
    $tok = $_COOKIE[$cookieName] ?? '';
    $uid = $state['sessions'][$tok] ?? null;
    $acc = null;
    foreach ($accounts as $email => $a) {
        if ($a['id'] === $uid && $a['role'] === $bo) {
            $acc = ['email' => $email] + $a;
        }
    }
    if ($acc === null) {
        $out(401, ['error' => 'Non authentifié.', 'login_url' => '/webshop/backoffice_' . $bo . '/']);
    }
    if ($action === 'logout') {
        unset($state['sessions'][$tok]);
        setcookie($cookieName, '', ['expires' => time() - 3600, 'path' => '/']);
        $out(200, ['ok' => true]);
    }
    $out(200, ['user' => ['id' => $acc['id'], 'email' => $acc['email'], 'display_name' => $acc['display_name'], 'role' => $bo === 'franchisee' ? 'franchise' : 'siege'] + ($acc['shops'] !== null ? ['shops' => $acc['shops']] : []), 'csrf' => 'csrf-' . $acc['id']]);
}

/* ---------------------------------------------------------------- OAuth */
if ($uri === '/REST/oauth/v5/token' && $method === 'POST') {
    parse_str($raw, $f);
    if (($f['grant_type'] ?? '') !== 'client_credentials' || ($f['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' || isset($f['scope'])) {
        $out(400, ['error' => 'invalid_request', 'error_description' => 'paramètres inattendus']);
    }
    $parts = explode('.', (string) ($f['client_assertion'] ?? ''));
    if (count($parts) !== 3) {
        $out(400, ['error' => 'invalid_client', 'error_description' => 'assertion illisible']);
    }
    $h = json_decode($b64($parts[0]), true);
    $c = json_decode($b64($parts[1]), true);
    $tokenUrl = (getenv('HR_MOCK_SELF') ?: 'http://' . $_SERVER['HTTP_HOST']) . '/REST/oauth/v5/token';
    $why = null;
    if (($h['alg'] ?? '') !== 'RS256') { $why = 'alg'; }
    elseif (empty($c['jti']) || in_array($c['jti'], $state['jti'] ?? [], true)) { $why = 'jti'; }
    elseif (($c['iss'] ?? null) !== ($c['sub'] ?? '') || !str_starts_with((string) $c['iss'], 'self_service_expeditor_')) { $why = 'iss/sub'; }
    elseif (($c['aud'] ?? '') !== $tokenUrl) { $why = 'aud'; }
    elseif (($c['exp'] ?? 0) < time() || ($c['exp'] ?? 0) - ($c['iat'] ?? 0) > 300) { $why = 'exp'; }
    if ($why === null && ($cert = $state['certs'][$c['iss']] ?? null) !== null) {
        if (openssl_verify($parts[0] . '.' . $parts[1], $b64($parts[2]), $cert, OPENSSL_ALGO_SHA256) !== 1) {
            $why = 'signature';
        }
    }
    if (str_contains((string) ($c['iss'] ?? ''), 'REFUSE')) { $why = 'compte refusé'; }
    if ($why !== null) {
        $out(401, ['error' => 'invalid_client', 'error_description' => 'client assertion rejected (' . $why . ')']);
    }
    $state['jti'][] = $c['jti'];
    $tok = 'at-' . bin2hex(random_bytes(8));
    $state['tokens'][$tok] = time() + 600;
    $out(200, ['access_token' => $tok, 'token_type' => 'Bearer', 'expires_in' => 600]);
}
/** Le test enregistre le certificat d'un Client ID : la signature des assertions sera vérifiée. */
if ($uri === '/__mock/cert' && $method === 'POST') {
    $j = json_decode($raw, true) ?: [];
    $state['certs'][(string) $j['clientId']] = (string) $j['certificate'];
    $out(204);
}
if ($uri === '/__mock/state') {
    $out(200, $state);
}
if ($uri === '/__mock/reset' && $method === 'POST') {
    $state = ['tokens' => [], 'declarations' => [], 'periods' => [], 'next' => 600000000001, 'log' => [], 'certs' => [], 'sessions' => $state['sessions'] ?? []];
    $out(204);
}

/* ---------------------------------------------------------------- Dimona v2 */
if (str_starts_with($uri, '/REST/dimona/v2/')) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tok = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if (($state['tokens'][$tok] ?? 0) < time()) {
        $out(401, ['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401]);
    }
    $path = substr($uri, strlen('/REST/dimona/v2'));
    $base = (getenv('HR_MOCK_SELF') ?: 'http://' . $_SERVER['HTTP_HOST']) . '/REST/dimona/v2';

    if ($path === '/declarations' && $method === 'POST') {
        $req = json_decode($raw, true);
        if (!is_array($req)) {
            $out(400, ['type' => 'about:blank', 'title' => 'Bad Request', 'status' => 400, 'detail' => 'JSON illisible']);
        }
        if (isset($req['dimonaIn']) && empty($req['dimonaIn']['features']['workerType'])) {
            $out(400, ['title' => 'Bad Request', 'status' => 400, 'detail' => 'Input validation failed', 'issues' => [['in' => 'body', 'name' => 'dimonaIn.features.workerType', 'detail' => 'must not be null']]]);
        }
        $id = $state['next']++;
        $state['declarations'][(string) $id] = ['request' => $req, 'reads' => 0];
        $out(201, null, ['Location' => $base . '/declarations/' . $id]);
    }
    if (preg_match('#^/declarations/(\d+)$#', $path, $m) && $method === 'GET') {
        $d = $state['declarations'][$m[1]] ?? null;
        if ($d === null) {
            $out(404, ['id' => 'x', 'code' => 'Not Found', 'message' => 'Unknown declaration ' . $m[1]]);
        }
        $state['declarations'][$m[1]]['reads']++;
        if ($d['reads'] < $pendingReads) {
            // doc fonctionnelle v2, p. 24 : la forme exacte d'une déclaration encore en traitement
            $out(404, ['id' => '3b730695-1d9d-42c6-90b5-3497209edf56', 'code' => 'Not Found', 'message' => ' Declaration with Dimona Declaration Nbr ' . $m[1] . ' has been submitted but not processed yet', 'contact' => null]);
        }
        $req = $d['request'];
        $in = $req['dimonaIn'] ?? null;
        $result = 'A';
        $anom = [];
        $info = [];
        if ($in !== null && ($in['startHour'] ?? null) !== null && ($in['endHour'] ?? null) !== null && ($in['endDate'] ?? $in['startDate']) === $in['startDate'] && $in['endHour'] < $in['startHour']) {
            $result = 'B';
            $anom[] = ['errorId' => '00778-345', 'label' => ['nl' => 'UUR - EINDUUR : Het einduur valt vroeger dan het beginuur', 'fr' => "HEURE DE FIN : Heure de fin antérieure à l'heure de début"]];
        } elseif ($in !== null && ($in['features']['workerType'] ?? '') === 'STU' && (int) ($in['plannedHoursNumber'] ?? 0) > 400) {
            $result = 'W';
            $anom[] = ['errorId' => '90017-369', 'label' => ['nl' => 'Natuurlijke persoon : Overschrijding van het contingent', 'fr' => 'Personne physique : Dépassement du contingent']];
            $info[] = ['type' => 'QUOTA WARNING: STUDENT', 'value' => (string) ((int) $in['plannedHoursNumber'] - 400)];
        } elseif (($req['worker']['ssin'] ?? '') === '00010100105') {
            $result = 'S';
            $anom[] = ['errorId' => '?????-???', 'label' => ['nl' => 'In afwachting', 'fr' => 'En attente']];
        }
        $periodId = null;
        if (in_array($result, ['A', 'W'], true)) {
            if ($in !== null) {
                $periodId = (int) $m[1] + 500000;
                $state['periods'][(string) $periodId] = ['periodId' => $periodId, 'employer' => $req['employer'], 'worker' => ['ssin' => $req['worker']['ssin']],
                    'startDate' => $in['startDate'], 'startHour' => $in['startHour'] ?? null, 'endDate' => $in['endDate'] ?? null, 'endHour' => $in['endHour'] ?? null,
                    'plannedHoursNumber' => $in['plannedHoursNumber'] ?? null, 'dimonaFeatures' => $in['features'], 'isCanceled' => false, 'lastDimonaType' => 'in'];
            } else {
                $blk = $req['dimonaOut'] ?? $req['dimonaUpdate'] ?? $req['dimonaCancel'];
                $periodId = (int) $blk['periodId'];
                if (isset($state['periods'][(string) $periodId])) {
                    if (isset($req['dimonaOut'])) { $state['periods'][(string) $periodId]['endDate'] = $blk['endDate']; $state['periods'][(string) $periodId]['lastDimonaType'] = 'out'; }
                    if (isset($req['dimonaCancel'])) { $state['periods'][(string) $periodId]['isCanceled'] = true; $state['periods'][(string) $periodId]['lastDimonaType'] = 'cancel'; }
                    if (isset($req['dimonaUpdate'])) { foreach (['startDate', 'endDate', 'startHour', 'endHour', 'plannedHoursNumber'] as $k) { if (isset($blk[$k])) { $state['periods'][(string) $periodId][$k] = $blk[$k]; } } $state['periods'][(string) $periodId]['lastDimonaType'] = 'update'; }
                }
            }
        }
        $resp = $req;
        $resp['declarationStatus'] = ['declarationId' => (string) $m[1], 'result' => $result,
            'period' => $periodId !== null ? ['href' => $base . '/periods/' . $periodId, 'id' => $periodId] : new stdClass(),
            'anomalies' => $anom, 'informationsCollection' => $info];
        $out(200, $resp);
    }
    if ($path === '/declarations/search' && $method === 'POST') {
        $q = json_decode($raw, true) ?: [];
        $items = [];
        foreach ($state['declarations'] as $id => $d) {
            $blk = $d['request']['dimonaIn'] ?? $d['request']['dimonaOut'] ?? $d['request']['dimonaUpdate'] ?? $d['request']['dimonaCancel'] ?? [];
            if (($q['references']['declaration'] ?? null) !== null && ($blk['references']['declaration'] ?? null) === $q['references']['declaration']) {
                $items[] = $d['request'] + ['href' => $base . '/declarations/' . $id, 'declarationStatus' => ['declarationId' => (int) $id]];
            }
        }
        $out(200, ['self' => $base . '/declarations/search', 'prev' => null, 'next' => null, 'page' => 1, 'pageSize' => 50, 'total' => count($items), 'items' => $items]);
    }
    if ($path === '/periods/search' && $method === 'POST') {
        $q = json_decode($raw, true) ?: [];
        $items = [];
        foreach ($state['periods'] as $p) {
            if (($q['worker']['ssin'] ?? null) === $p['worker']['ssin']) {
                $items[] = $p + ['href' => $base . '/periods/' . $p['periodId']];
            }
        }
        $out(200, ['self' => $base . '/periods/search', 'prev' => null, 'next' => null, 'page' => 1, 'pageSize' => 50, 'total' => count($items), 'items' => $items]);
    }
    $out(404, ['title' => 'Not Found', 'status' => 404]);
}

$out(404, ['error' => 'mock : ' . $method . ' ' . $uri]);
