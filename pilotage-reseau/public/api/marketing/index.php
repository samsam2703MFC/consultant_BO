<?php

declare(strict_types=1);

/**
 * API de l'assistant de campagne — copie du module marketing, portée dans le
 * cockpit pour survivre à la suppression du module d'origine.
 *
 * Montage : les appels arrivent en /api/v1/marketing/… (réécriture Apache) ;
 * `Request::stripBasePath()` coupe au premier « /api/v1/ », le sous-répertoire
 * d'installation n'a donc pas d'importance.
 *
 * Identité : le cockpit n'a pas d'authentification propre — l'identité est
 * posée ici en dur, au même niveau de confiance que le reste de son API.
 * La connexion MySQL est celle du cockpit, injectée plutôt que rouverte.
 */

use Marketing\Support\AuthContext;
use Marketing\Support\Database;
use Marketing\Support\Env;
use Marketing\Support\Request;
use Marketing\Support\Response;
use Marketing\Support\Router;

require __DIR__ . '/src/autoload.php';
require __DIR__ . '/../../../src/Db.php';
require_once __DIR__ . '/../../../src/endpoints.php';
require_once __DIR__ . '/../../../src/writes.php';
require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/acces.php';

// L'accès (13/11/2026) : l'identité posée en dur ci-dessous ne vaut que pour le siège connecté (src/acces.php). Seules les routes publiques des campagnes (/api/v1/public/…) restent ouvertes.
$chemin = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (!str_contains($chemin, '/api/v1/public/')) {
    $refus = accesRefus((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), '/marketing-assistant');
    if ($refus !== null) {
        http_response_code($refus[0]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($refus[1], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

Database::setConnection(Db::pdo());
AuthContext::set(1, 'BRAND_ADMIN');

// Les visuels de campagne vivent sous la page de l'assistant : l'API renvoie
// « uploads/… », que la page résout en /assistant/uploads/…. Le déploiement
// exclut ce dossier du rsync --delete, comme public/uploads.
Env::set('MAR_UPLOAD_DIR', __DIR__ . '/../../assistant/uploads');

$router = new Router();
(require __DIR__ . '/routes.php')($router);

Response::emit($router->dispatch(Request::fromGlobals()));
