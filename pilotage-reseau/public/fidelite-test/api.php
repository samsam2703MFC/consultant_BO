<?php
declare(strict_types=1);

/**
 * L'API de la page de test de la fidélité (public/fidelite-test), À PART du cockpit — 13/11/2026, Sam : « c'est une
 * page à part, ne touche pas au dashboard, c'est un test externe au dashboard ».
 *
 * Elle ne passe pas par l'API du cockpit (public/api/index.php). Elle charge le module de la fidélité bingo + points :
 * src/fidelite_bingo.php en production (à côté de l'ancien src/fidelite.php, qu'elle ne charge pas), src/fidelite.php
 * sur la branche de la PR #103. Rien n'est publié ni émis. DEUX ÉCRITURES seulement, confirmées dans la page et
 * journalisées : créer la carte à points (POST /admin/loyalty/cards) et créer les campagnes du bingo qui manquent, en
 * brouillon (POST /admin/voucher-campaigns).
 *
 *   GET  api.php?r=/fidelite/verification
 *   GET  api.php?r=/fidelite[&saison=]
 *   GET  api.php?r=/fidelite/creation[&saison=]        aperçu de la création (admin) : rien n'est envoyé
 *   GET  api.php?r=/fidelite/clients[&shop=&q=&tous=1]
 *   GET  api.php?r=/fidelite/client&id=                la carte d'un client
 *   POST api.php?r=/fidelite/simuler                   {shop, montant, quand}
 *   POST api.php?r=/fidelite/bingo/bons                toujours en simulation, quoi qu'on envoie
 *   POST api.php?r=/fidelite/creer                     {points?, debut?, confirmer: true} — crée la carte à points
 *   POST api.php?r=/fidelite/creer-campagnes           {saison?, confirmer: true} — crée les campagnes manquantes (brouillon)
 *
 * L'accès suit celui du cockpit : src/acces.php s'il est là (en mode « console », la session du siège), sinon la
 * connexion du cockpit (src/auth.php).
 */

require __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/erp_cles.php';
require __DIR__ . '/../../src/endpoints.php';
require __DIR__ . '/../../src/writes.php';
require __DIR__ . '/../../src/auth.php';
if (is_file(__DIR__ . '/../../src/acces.php')) { require __DIR__ . '/../../src/acces.php'; }
require __DIR__ . '/../../src/panel_api.php';
require __DIR__ . '/../../src/erp_api.php';
require is_file(__DIR__ . '/../../src/fidelite_bingo.php') ? __DIR__ . '/../../src/fidelite_bingo.php' : __DIR__ . '/../../src/fidelite.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = (string) ($_GET['r'] ?? '');

try {
    // Réservée au siège quand l'accès est fermé : « /fidelite-test… » n'est pas un écran de magasin.
    $refus = function_exists('accesRefus') ? accesRefus($methode, '/fidelite-test' . $route)
        : (authEnabled() && !authOk() ? [401, ['error' => 'auth']] : null);
    if ($refus !== null) {
        http_response_code($refus[0]);
        echo json_encode($refus[1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = match (true) {
        $methode === 'GET' && $route === '/fidelite/verification' => ep_fidelite_verification(),
        $methode === 'GET' && $route === '/fidelite' => ep_fidelite(),
        $methode === 'GET' && $route === '/fidelite/creation' => ep_fidelite_creation(),
        $methode === 'GET' && $route === '/fidelite/clients' => ep_fidelite_clients(),
        $methode === 'GET' && $route === '/fidelite/client' => ep_fidelite_client(),
        $methode === 'POST' && $route === '/fidelite/simuler' => wr_fidelite_simuler(),
        $methode === 'POST' && $route === '/fidelite/bingo/bons' => fidBingoBons(false),   // jamais d'émission ici
        // Les deux seules écritures de la page (Sam, 13/11/2026 : « je dois pouvoir la créer ») : confirmées, journalisées.
        $methode === 'POST' && $route === '/fidelite/creer' => wr_fidelite_creer(),
        $methode === 'POST' && $route === '/fidelite/creer-campagnes' => wr_fidelite_creer_campagnes(),
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
