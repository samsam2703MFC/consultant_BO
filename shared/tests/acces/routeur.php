<?php
// Banc de l'accès (src/acces.php) : la vraie API du cockpit et ses pages, et une FAUSSE console franchisé sous
// /webshop/api (deux comptes fictifs : « Siège Test », et « Franchisé Test » qui n'a que le magasin 2). Pour le banc
// sans console (comme le serveur au 13/11/2026), HR_CONSOLE_API_INTERNAL pointe une adresse absente (404).
// Lancement : COCKPIT_ACCES=console HR_CONSOLE_API_INTERNAL=http://127.0.0.1:8777/webshop/api HR_CONSOLE_API_BASE=/webshop/api \
//             php -S 127.0.0.1:8777 -t public tests/acces/routeur.php
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($u, '/webshop/api/bo/')) {
    header('Content-Type: application/json');
    $comptes = ['franchisee' => ['fb_franchisee_session', 'fra-ok', ['id' => 7, 'display_name' => 'Franchisé Test', 'email' => 'f@test', 'shops' => [2]], 'f@test'],
        'franchisor' => ['fb_franchisor_session', 'sie-ok', ['id' => 1, 'display_name' => 'Siège Test', 'email' => 's@test'], 's@test']];
    if (!preg_match('#^/webshop/api/bo/(franchisee|franchisor)/(me|login|logout)$#', $u, $m)) { http_response_code(404); echo '{}'; return true; }
    [$cookie, $valeur, $user, $email] = $comptes[$m[1]];
    if ($m[2] === 'me') {
        if (($_COOKIE[$cookie] ?? '') === $valeur) { echo json_encode(['user' => $user, 'csrf' => 'csrf-' . $m[1]]); }
        else { http_response_code(401); echo '{"error":"non connecté"}'; }
        return true;
    }
    if ($m[2] === 'login') {
        $b = json_decode((string) file_get_contents('php://input'), true) ?: [];
        if (($b['email'] ?? '') === $email && ($b['password'] ?? '') === 'motdepasse-banc') {
            setcookie($cookie, $valeur, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            echo json_encode(['user' => $user]);
        } else { http_response_code(401); echo '{"error":"Identifiants invalides"}'; }
        return true;
    }
    setcookie($cookie, '', ['expires' => time() - 3600, 'path' => '/']);
    echo '{"ok":true}';
    return true;
}
return require __DIR__ . '/../../public/router.php';
