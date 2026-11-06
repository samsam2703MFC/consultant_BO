#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vérification de bout en bout d'un compte REST Chaman en SIMULATION — et rien de plus :
 *   1. obtient un jeton d'accès (assertion JWT RS256, client_credentials, sans scope) ;
 *   2. fait une recherche en lecture seule : POST /declarations/search (YAML, operationId
 *      searchDeclarations, ligne 104 — la recherche Dimona est un POST qui ne déclare rien), une page de 1.
 * Aucune déclaration n'est envoyée ; la production est refusée.
 *
 *   HR_SMOKE_CLIENT_ID=self_service_expeditor_… \
 *   HR_SMOKE_PRIVATE_KEY_FILE=/chemin/societe-dimona.key \
 *   HR_SMOKE_ENTERPRISE_NUMBER=0411702543 \
 *   php scripts/dimona-smoke.php
 *
 * Adresses : celles du YAML, ou HR_DIMONA_BASE_URL_SIM / HR_DIMONA_TOKEN_URL_SIM. Le jeton, l'assertion et
 * la clé ne sont jamais affichés.
 */

use Hr\Dimona\Credential;
use Hr\Dimona\DimonaAuthService;
use Hr\Dimona\DimonaClient;
use Hr\Dimona\Dto\DeclarationSearchRequest;
use Hr\Dimona\MemoryTokenCache;
use Hr\Dimona\PayloadBuilder;
use Hr\Support\Config;
use Hr\Support\CurlHttpClient;

require __DIR__ . '/../src/hr/autoload.php';

$need = ['HR_SMOKE_CLIENT_ID', 'HR_SMOKE_PRIVATE_KEY_FILE', 'HR_SMOKE_ENTERPRISE_NUMBER'];
$missing = array_values(array_filter($need, static fn (string $k): bool => (string) getenv($k) === ''));
if ($missing !== []) {
    fwrite(STDERR, "Variables manquantes : " . implode(', ', $missing) . "\n");
    exit(2);
}
if (strtoupper((string) getenv('HR_SMOKE_ENV')) === 'PROD') {
    fwrite(STDERR, "Ce script ne parle qu'à la simulation.\n");
    exit(2);
}
$key = @file_get_contents((string) getenv('HR_SMOKE_PRIVATE_KEY_FILE'));
if ($key === false || !str_contains($key, 'PRIVATE KEY')) {
    fwrite(STDERR, "Clé privée illisible : " . getenv('HR_SMOKE_PRIVATE_KEY_FILE') . "\n");
    exit(2);
}

$config = Config::load(['masterKey' => base64_encode(random_bytes(32))]);
$http = new CurlHttpClient();
$auth = new DimonaAuthService($http, $config, new MemoryTokenCache());
$cred = new Credential(0, 0, Config::ENV_SIM, (string) getenv('HR_SMOKE_CLIENT_ID'), $key);

echo "Serveur de jetons : " . $config->tokenUrl(Config::ENV_SIM) . "\n";
echo "API Dimona (SIM)  : " . $config->baseUrl(Config::ENV_SIM) . "\n";
try {
    $auth->token($cred, true);
    $exp = $auth->cached($cred)['expires_at'] ?? 0;
    echo "1. Jeton obtenu (valable jusqu'à " . gmdate('H:i:s', $exp) . " UTC).\n";
} catch (Throwable $e) {
    echo "1. Jeton REFUSÉ : " . $e->getMessage() . "\n";
    exit(1);
}
try {
    $res = (new DimonaClient($http, $auth, $config))->searchDeclarations($cred, DeclarationSearchRequest::fromArray([
        'employer' => ['enterpriseNumber' => PayloadBuilder::enterprise((string) getenv('HR_SMOKE_ENTERPRISE_NUMBER'))],
    ]), 1, 1);
    echo "2. Recherche de déclarations : OK, " . (int) ($res->total ?? 0) . " déclaration(s) connue(s) pour cet employeur.\n";
} catch (Throwable $e) {
    echo "2. Recherche REFUSÉE : " . $e->getMessage() . "\n";
    exit(1);
}
echo "OK — rien n'a été déclaré.\n";
