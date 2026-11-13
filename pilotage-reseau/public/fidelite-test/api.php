<?php
declare(strict_types=1);

/**
 * L'API de la page de test de la fidélité (public/fidelite-test), À PART du cockpit — 13/11/2026, Sam : « c'est une
 * page à part, ne touche pas au dashboard, c'est un test externe au dashboard ».
 *
 * Elle ne passe pas par l'API du cockpit (public/api/index.php) et ne charge pas l'ancien src/fidelite.php : seulement
 * src/fidelite_bingo.php (le module de la PR #103), le client de l'ERP et l'accès du cockpit. LECTURE SEULE : la
 * vérification de l'API de l'ERP, le programme, la simulation d'un ticket (que l'ERP n'écrit pas), les clients et les
 * bons dus en simulation. Rien n'est publié, aucun bon n'est émis.
 *
 *   GET  api.php?r=/fidelite/verification
 *   GET  api.php?r=/fidelite[&saison=]
 *   GET  api.php?r=/fidelite/clients[&shop=&q=]
 *   POST api.php?r=/fidelite/simuler          {shop, montant, quand}
 *   POST api.php?r=/fidelite/bingo/bons       (toujours en simulation, quoi qu'on envoie)
 *
 * L'accès suit celui du cockpit (src/acces.php) : en mode « console », la session du siège.
 */

require __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/erp_cles.php';
require __DIR__ . '/../../src/endpoints.php';
require __DIR__ . '/../../src/writes.php';
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/acces.php';
require __DIR__ . '/../../src/panel_api.php';
require __DIR__ . '/../../src/erp_api.php';
require __DIR__ . '/../../src/fidelite_bingo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = (string) ($_GET['r'] ?? '');

try {
    // Réservée au siège quand l'accès est fermé : « /fidelite-test… » n'est pas un écran de magasin.
    if (($refus = accesRefus($methode, '/fidelite-test' . $route)) !== null) {
        http_response_code($refus[0]);
        echo json_encode($refus[1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = match (true) {
        $methode === 'GET' && $route === '/fidelite/verification' => ep_fidelite_verification(),
        $methode === 'GET' && $route === '/fidelite' => ep_fidelite(),
        $methode === 'GET' && $route === '/fidelite/clients' => ep_fidelite_clients(),
        $methode === 'POST' && $route === '/fidelite/simuler' => wr_fidelite_simuler(),
        $methode === 'POST' && $route === '/fidelite/bingo/bons' => fidBingoBons(false),   // jamais d'émission ici
        default => null,
    };
    if ($r === null) {
        http_response_code(404);
        $r = ['error' => 'route inconnue de la page de test : ' . $methode . ' ' . $route];
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'erreur du serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
