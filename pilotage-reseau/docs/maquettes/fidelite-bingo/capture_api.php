<?php
declare(strict_types=1);

/**
 * Captures de l'écran Fidélité tel que codé (code-*.png) : le cockpit servi tel quel (public/), et son API réduite aux
 * routes de la fidélité, branchées sur le faux ERP (tools/fidelite_faux_erp.php), plus /meta ; les autres lectures
 * rendent une liste vide. Jeudi 14 janvier 2027, 16:00 — la semaine 8 du bingo d'hiver.
 *
 *   php -S 127.0.0.1:8098 -t public docs/maquettes/fidelite-bingo/capture_api.php     (depuis pilotage-reseau/)
 *   node docs/maquettes/fidelite-bingo/capturer.cjs
 */
$chemin = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// La page de test à part : son api.php, branchée sur le faux ERP (avant les doublures ci-dessous, qu'elle n'utilise pas).
if ($chemin === '/fidelite-test/api.php') {
    require __DIR__ . '/../../../tools/fidelite_faux_erp.php';
    $GLOBALS['FID_ERP'] = new FidFauxErp(['clients' => 3180]);
    $GLOBALS['FID_AUJOURDHUI'] = '2027-01-14';
    $GLOBALS['FID_HEURE'] = '16:00';
    require __DIR__ . '/../../../public/fidelite-test/api.php';
    return true;
}
if (!str_starts_with($chemin, '/api/')) { return false; }        // le reste : les fichiers de public/
header('Content-Type: application/json; charset=utf-8');
$route = preg_replace('#^/api/cockpit#', '', $chemin);
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!function_exists('body')) { function body(): array { $d = json_decode((string) file_get_contents('php://input'), true); return is_array($d) ? $d : []; } }
if (!function_exists('setting')) { function setting(string $k, mixed $d = null): mixed { return $d; } }
if (!function_exists('journalAdd')) { function journalAdd(string $a, string $k, ?string $p, string $m, ?string $ts = null): void {} }
require __DIR__ . '/../../../src/fidelite.php';
require __DIR__ . '/../../../tools/fidelite_faux_erp.php';
$GLOBALS['FID_ERP'] = new FidFauxErp(['clients' => 3180]);
$GLOBALS['FID_AUJOURDHUI'] = '2027-01-14';
$GLOBALS['FID_HEURE'] = '16:00';

// /meta seul suffit au cockpit pour se croire branché (source « api ») : ses écritures partent alors pour de vrai.
$meta = ['reseau' => ['nom' => 'L’Atelier by', 'sousTitre' => 'Pilotage réseau'], 'utilisateur' => ['initiales' => 'SV', 'nom' => 'Sam', 'role' => 'CEO'],
    'aujourdhui' => '2027-01-14', 'dateLabel' => 'jeudi 14 janvier 2027', 'periodeLabel' => 'janvier 2027', 'exercice' => 2027,
    'moisLabels' => ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'],
    'seuils' => ['food' => 32, 'labour' => 33, 'overhead' => 13.5, 'royalties' => 3, 'financieres' => 2.2, 'caEtp' => 13000],
    'contribOuverture' => 0, 'notes' => new stdClass(), 'familles' => [], 'reportTypes' => []];
$r = match (true) {
    $methode === 'GET' && $route === '/meta' => $meta,
    $methode === 'GET' && $route === '/fidelite' => ep_fidelite(),
    $methode === 'GET' && $route === '/fidelite/clients' => ep_fidelite_clients(),
    $methode === 'GET' && $route === '/fidelite/verification' => ep_fidelite_verification(),
    $methode === 'POST' && $route === '/fidelite/simuler' => wr_fidelite_simuler(),
    $methode === 'POST' && $route === '/fidelite/bingo/bons' => wr_fidelite_bingo_bons(),
    $methode === 'POST' && $route === '/fidelite/publier' => wr_fidelite_publier(),
    default => null,
};
// Hors fidélité : des listes vides (le chargement du cockpit les attend), l'authentification absente (mode sans compte).
if ($r === null && $route === '/auth/status') { http_response_code(503); echo '{"error":"hors capture"}'; return true; }
if ($r === null) { echo $methode === 'GET' ? '[]' : '{"ok":true}'; return true; }
echo json_encode($r, JSON_UNESCAPED_UNICODE);
return true;
