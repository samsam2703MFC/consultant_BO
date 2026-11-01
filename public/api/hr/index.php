<?php

declare(strict_types=1);

/**
 * Contrôleur frontal de l'API du module RH : /api/v1/erp/dimona/* (Hr\Http\Api).
 *
 * Servi à https://…/hr/api/v1/erp/dimona/… (alias Apache posé par bin/deploy.sh) et sous le cockpit
 * (…/consulant_bo/api/v1/erp/dimona/…, public/.htaccess). Identité : la session de la console franchisé.
 * Sur le serveur de développement PHP seulement, HR_DEV_IDENTITY peut fixer une identité d'essai.
 */

use Hr\Auth\ConsoleIdentityResolver;
use Hr\Auth\FixedIdentityResolver;
use Hr\Http\Api;
use Hr\Http\Request;
use Hr\Http\Response;

try {
    $app = require __DIR__ . '/../../../src/hr/bootstrap.php';
} catch (Throwable $e) {
    error_log('[hr] démarrage : ' . $e->getMessage());
    require_once __DIR__ . '/../../../src/hr/autoload.php';
    Response::error('Module RH indisponible (base ou configuration).', 503, [], 'unavailable')->emit();
    return;
}

$dev = PHP_SAPI === 'cli-server' ? (string) (getenv('HR_DEV_IDENTITY') ?: '') : '';
$identities = $dev !== '' ? FixedIdentityResolver::fromJson($dev) : new ConsoleIdentityResolver($app->http, $app->config);

(new Api($app, $identities))->handle(Request::fromGlobals())->emit();
