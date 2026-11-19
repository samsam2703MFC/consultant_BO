<?php
declare(strict_types=1);

/**
 * L'API de la page « app client en direct » (public/fidelite-app), À PART du cockpit et du dashboard (18/11/2026, Sam :
 * « faire un test en live (Halle, client Vincent) sur le serveur, une page indépendante »).
 *
 * LECTURE SEULE : trois GET, aucune écriture, aucun bon émis. Elle charge le module bingo (src/fidelite_bingo.php :
 * accès à l'ERP, calendrier, règles) et src/fidelite_app.php (la vue du client).
 *
 *   GET api.php?r=/etat                    la carte à points de l'ERP, les magasins, la saison (ou son rejeu)
 *   GET api.php?r=/clients&shop=4&q=vincent les membres de la carte trouvés par leur nom (prénom et initiale)
 *   GET api.php?r=/carte&id=…&q=vincent     la carte du client : 13 semaines, points, bons
 *
 * L'accès suit celui de la page de test : src/acces.php s'il est là (le siège), sinon la connexion du cockpit.
 */

require __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/erp_cles.php';
require __DIR__ . '/../../src/endpoints.php';
require __DIR__ . '/../../src/writes.php';
require __DIR__ . '/../../src/auth.php';
if (is_file(__DIR__ . '/../../src/acces.php')) { require __DIR__ . '/../../src/acces.php'; }
require __DIR__ . '/../../src/panel_api.php';
require __DIR__ . '/../../src/erp_api.php';
require __DIR__ . '/../../src/fidelite_bingo.php';
require __DIR__ . '/../../src/fidelite_app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = (string) ($_GET['r'] ?? '');

try {
    $refus = function_exists('accesRefus') ? accesRefus($methode, '/fidelite-app' . $route)
        : (authEnabled() && !authOk() ? [401, ['error' => 'auth']] : null);
    if ($refus !== null) {
        http_response_code($refus[0]);
        echo json_encode($refus[1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = $methode !== 'GET' ? null : match ($route) {
        '/etat' => ep_fap_etat(),
        '/clients' => ep_fap_clients(),
        '/carte' => ep_fap_carte(),
        default => null,
    };
    if ($r === null) {
        http_response_code(404);
        $r = ['error' => 'route inconnue (page en lecture seule) : ' . $methode . ' ' . $route];
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'erreur du serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
