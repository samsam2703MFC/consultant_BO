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

Database::setConnection(Db::pdo());
AuthContext::set(1, 'BRAND_ADMIN');

// Les visuels de campagne vivent sous la page de l'assistant : l'API renvoie
// « uploads/… », que la page résout en /assistant/uploads/…. Le déploiement
// exclut ce dossier du rsync --delete, comme public/uploads.
Env::set('MAR_UPLOAD_DIR', __DIR__ . '/../../assistant/uploads');

$router = new Router();
(require __DIR__ . '/routes.php')($router);

Response::emit($router->dispatch(Request::fromGlobals()));
