<?php
// Faux Meta Graph + faux panel (ERP) + faux SMSAPI pour l'essai local de MétaDonnée (voir LISEZMOI.md).
//   php -S 127.0.0.1:8098 tools/metadonnee-essai/router.php
// L'état (leads Meta, leads reçus par l'ERP, SMS partis, abonnements, adresse de rappel, appels) vit dans etat/, hors Git.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$E = __DIR__ . '/etat';
if (!is_dir($E)) { mkdir($E, 0775, true); }
$lire = fn (string $f, $d = []) => is_file("$E/$f") ? json_decode((string) file_get_contents("$E/$f"), true) : $d;
$ecrire = fn (string $f, $v) => file_put_contents("$E/$f", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$out = fn ($v, int $c = 200) => (http_response_code($c) || true) && print(json_encode($v, JSON_UNESCAPED_UNICODE));
$journal = function (string $l) use ($E) { file_put_contents("$E/appels.log", date('H:i:s') . " $l\n", FILE_APPEND); };
$journal("$m $uri " . ($_SERVER['QUERY_STRING'] ?? ''));

/* ---------------- Graph ---------------- */
// UNE Page (celle du CRM) et l'application 4242 ; jeton d'utilisateur système `tok-system`, échangé contre `tok-page-501`.
// L'adresse de rappel de l'application est d'office celle du CRM : etat/rappel-libre.flag la libère.
if (preg_match('#^/graph/v[\d.]+/(.+)$#', $uri, $g)) {
    $p = $g[1];
    $q = $m === 'POST' ? $_POST : $_GET;
    if ($p === 'debug_token') {
        $in = (string) ($q['input_token'] ?? '');
        $scopes = ['leads_retrieval', 'pages_manage_metadata', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads', 'ads_management'];
        if ($in === 'tok-system') { $out(['data' => ['is_valid' => true, 'app_id' => '4242', 'application' => 'Atelier by', 'type' => 'SYSTEM_USER', 'expires_at' => 0, 'scopes' => $scopes]]); return; }
        if ($in === 'tok-page-501') { $out(['data' => ['is_valid' => true, 'app_id' => '4242', 'type' => 'PAGE', 'profile_id' => '501', 'expires_at' => 0, 'scopes' => $scopes]]); return; }
        $out(['data' => ['is_valid' => false, 'error' => ['message' => 'Invalid OAuth access token.']]]); return;
    }
    if ($p === 'oauth/access_token') {
        if (($q['client_secret'] ?? '') !== 'secret-app') { $out(['error' => ['message' => 'Error validating client secret.', 'code' => 101]], 400); return; }
        $out(['access_token' => '4242|app', 'token_type' => 'bearer']); return;
    }
    if ($p === '4242/subscriptions') {
        $libre = is_file("$E/rappel-libre.flag");
        $sub = $lire('app_subscriptions.json', $libre ? [] : [['object' => 'page', 'callback_url' => 'https://franchise.atelierby.be/api/meta/leads', 'active' => true, 'fields' => [['name' => 'leadgen', 'version' => 'v25.0']]]]);
        if ($m === 'POST') { $sub = [['object' => 'page', 'callback_url' => (string) ($q['callback_url'] ?? ''), 'active' => true, 'fields' => [['name' => 'leadgen']]]]; $ecrire('app_subscriptions.json', $sub); $out(['success' => true]); return; }
        $out(['data' => $sub]); return;
    }
    $qBureau = [
        ['key' => 'full_name', 'label' => 'Nom complet', 'type' => 'FULL_NAME'],
        ['key' => 'email', 'label' => 'E-mail', 'type' => 'EMAIL'],
        ['key' => 'phone_number', 'label' => 'Numéro de téléphone', 'type' => 'PHONE'],
        ['key' => 'company_name', 'label' => 'Nom de l’entreprise', 'type' => 'COMPANY_NAME'],
        ['key' => 'job_title', 'label' => 'Fonction', 'type' => 'JOB_TITLE'],
        ['key' => 'zip', 'label' => 'Code postal', 'type' => 'ZIP'],
        ['key' => 'combien_de_personnes_au_bureau_?', 'label' => 'Combien de personnes au bureau ?', 'type' => 'CUSTOM'],
        ['key' => 'que_souhaitez-vous_?', 'label' => 'Que souhaitez-vous ?', 'type' => 'CUSTOM', 'options' => [
            ['key' => 'lunch', 'value' => 'Un lunch tous les jours'], ['key' => 'plateaux', 'value' => 'Des plateaux pour nos réunions'],
            ['key' => 'petitdej', 'value' => 'Un petit-déjeuner d’équipe'], ['key' => 'autre', 'value' => 'Autre']]],
        ['key' => 'pour_quand_?', 'label' => 'Pour quand ?', 'type' => 'CUSTOM'],
        ['key' => 'un_mot_pour_nous_?', 'label' => 'Un mot pour nous ?', 'type' => 'CUSTOM'],
    ];
    $qSomb = $qBureau;
    if (is_file("$E/allergies.flag")) { $qSomb[] = ['key' => 'allergies_?', 'label' => 'Des allergies à signaler ?', 'type' => 'CUSTOM']; }
    // Les formulaires de la Page : ceux des bureaux (pour le Cockpit) à côté de ceux des candidats (pour le CRM).
    $forms = [
        ['id' => '9001', 'name' => 'Lunch au bureau — Gosselies', 'status' => 'ACTIVE', 'locale' => 'fr_FR', 'leads_count' => 52, 'questions' => $qBureau],
        ['id' => '9002', 'name' => 'RECRUTEMENT B2B HALLE', 'status' => 'ACTIVE', 'locale' => 'fr_FR', 'leads_count' => 37, 'questions' => $qBureau],
        ['id' => '9003', 'name' => 'Plateaux réunion — réseau', 'status' => 'ACTIVE', 'locale' => 'fr_FR', 'leads_count' => 26, 'questions' => $qBureau],
        ['id' => '9004', 'name' => 'Petit-déj d’équipe — Sombreffe', 'status' => 'ACTIVE', 'locale' => 'fr_FR', 'leads_count' => 11, 'questions' => $qSomb],
        ['id' => '9007', 'name' => 'FRANCHISE ANDERLUES', 'status' => 'ACTIVE', 'locale' => 'fr_FR', 'leads_count' => 64,
         'questions' => [['key' => 'full_name', 'type' => 'FULL_NAME'], ['key' => 'email', 'type' => 'EMAIL'], ['key' => 'phone_number', 'type' => 'PHONE'],
             ['key' => 'quel_emplacement_?', 'label' => 'Quel emplacement ?', 'type' => 'CUSTOM']]],
        ['id' => '9006', 'name' => 'Concours « La Fève d’Or »', 'status' => 'ARCHIVED', 'locale' => 'fr_FR', 'leads_count' => 412,
         'questions' => [['key' => 'full_name', 'type' => 'FULL_NAME'], ['key' => 'email', 'type' => 'EMAIL'], ['key' => 'numero_du_ticket', 'label' => 'Numéro du ticket', 'type' => 'CUSTOM']]],
    ];
    if ($p === '501') {
        if (str_contains((string) ($q['fields'] ?? ''), 'access_token')) { $out(['id' => '501', 'name' => "L'Atelier by", 'access_token' => 'tok-page-501']); return; }
        $out(['id' => '501', 'name' => "L'Atelier by"]); return;
    }
    if ($p === '501/subscribed_apps') {
        // Le CRM et le Cockpit partagent l'application 4242 : un seul abonnement de la Page pour eux deux.
        $abo = $lire('abonnements.json', ['4242' => ['leadgen']]);
        if ($m === 'POST') { $abo['4242'] = array_values(array_unique(array_merge($abo['4242'] ?? [], explode(',', (string) ($q['subscribed_fields'] ?? ''))))); $ecrire('abonnements.json', $abo); $out(['success' => true]); return; }
        $out(['data' => array_map(fn ($id, $f) => ['id' => (string) $id, 'name' => 'Atelier by', 'subscribed_fields' => $f], array_keys($abo), $abo)]); return;
    }
    if ($p === '501/leadgen_forms') { $out(['data' => $forms]); return; }
    $leads = $lire('leads_meta.json', []);
    if (preg_match('#^(\d+)/leads$#', $p, $x)) {
        $f = json_decode((string) ($_GET['filtering'] ?? '[]'), true);
        $depuis = (int) ($f[0]['value'] ?? 0);
        $out(['data' => array_values(array_filter($leads, fn ($l) => $l['form_id'] === $x[1] && strtotime($l['created_time']) > $depuis))]); return;
    }
    if (preg_match('#^(\d+)$#', $p, $x)) {
        foreach ($leads as $l) { if ($l['id'] === $x[1]) { $out($l); return; } }
        $out(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400); return;
    }
    $out(['error' => ['message' => 'inconnu ' . $p, 'code' => 100]], 400); return;
}

/* ---------------- panel (ERP) ---------------- */
if (str_starts_with($uri, '/panel/api/v1/')) {
    $p = substr($uri, strlen('/panel/api/v1'));
    if ($p === '/consultant/auth/login') { $out(['access_token' => 'tok-panel', 'expires_in' => 1800]); return; }
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-panel') { $out(['description' => 'non authentifié'], 401); return; }
    $erp = $lire('erp.json', ['n' => 20470, 'leads' => []]);
    if ($p === '/consultant/leads' && $m === 'POST') {
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if (empty($b['contact']['phone'])) { $out(['message' => 'contact.phone requis'], 422); return; }
        $mid = (string) $b['meta_lead_id'];
        if (isset($erp['leads'][$mid])) { $out(['id' => $erp['leads'][$mid]['id'], 'status' => $erp['leads'][$mid]['status']], 409); return; }
        $id = 'L-' . (++$erp['n']);
        $erp['leads'][$mid] = ['id' => $id, 'meta_lead_id' => $mid, 'shop_id' => $b['shop_id'], 'status' => 'new', 'recu' => $b, 'created_at' => date('c')];
        $ecrire('erp.json', $erp);
        $out(['data' => ['id' => $id, 'status' => 'new']], 201); return;
    }
    if ($p === '/consultant/leads' && $m === 'GET') {
        $ids = array_filter(explode(',', (string) ($_GET['ids'] ?? '')));
        $rows = [];
        foreach ($erp['leads'] as $l) {
            if ($ids && !in_array($l['id'], $ids, true)) { continue; }
            $rows[] = array_intersect_key($l, array_flip(['id', 'meta_lead_id', 'status', 'seen_at', 'contacted_at', 'connected_at']));
        }
        $out(['data' => $rows]); return;
    }
    $out(['description' => 'route inconnue'], 404); return;
}

/* ---------------- SMSAPI ---------------- */
if ($uri === '/smsapi/profile') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-sms') { $out(['error' => 101, 'message' => 'Authorization failed']); return; }
    $out(['name' => 'L\'Atelier by', 'username' => 'atelierby', 'points' => 412.5, 'payment_type' => 'prepaid']); return;
}
if ($uri === '/smsapi/sms.do') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-sms') { $out(['error' => 101, 'message' => 'Authorization failed']); return; }
    $sms = $lire('sms.json', []);
    $sms[] = ['to' => $_POST['to'] ?? '', 'from' => $_POST['from'] ?? '', 'message' => $_POST['message'] ?? '', 'encoding' => $_POST['encoding'] ?? '', 'at' => date('c')];
    $ecrire('sms.json', $sms);
    $out(['count' => 1, 'list' => [['id' => 'SMS' . count($sms), 'points' => 0.16, 'number' => $_POST['to'] ?? '', 'status' => 'QUEUE']]]); return;
}
$out(['error' => 'inconnu'], 404);
