<?php

declare(strict_types=1);

/**
 * Le module RH en local, sur une seule origine comme en production — sans ONSS ni console réels :
 *
 *   HR_DB_DSN=sqlite:/tmp/hr-dev.sqlite HR_DEV_DIRECTORY=tests/hr/fixtures/directory.json \
 *   HR_PRIVATE_DIR=/tmp/hr-dev-prive HR_CONSOLE_API_BASE=/webshop/api \
 *   HR_CONSOLE_API_INTERNAL=http://127.0.0.1:8099/webshop/api \
 *   HR_DIMONA_BASE_URL_SIM=http://127.0.0.1:8099/REST/dimona/v2 \
 *   HR_DIMONA_TOKEN_URL_SIM=http://127.0.0.1:8099/REST/oauth/v5/token \
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8099 -t public tests/hr/dev-router.php
 *
 * puis http://127.0.0.1:8099/hr/ — compte franchisé franchise@test.be / test1234 (boutiques 4 et 7),
 * franchiseur siege@test.be / test1234. Plusieurs workers : l'API appelle le faux serveur sur le même port.
 */
$uri = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($uri, '/REST/') || str_starts_with($uri, '/webshop/api/') || str_starts_with($uri, '/__mock/')) {
    require __DIR__ . '/mock/server.php';
    return true;
}
if (str_contains($uri, '/api/v1/erp/dimona')) {
    require __DIR__ . '/../../public/api/hr/index.php';
    return true;
}
if ($uri === '/hr') {
    header('Location: /hr/', true, 301);
    return true;
}
return require __DIR__ . '/../../public/router.php';
