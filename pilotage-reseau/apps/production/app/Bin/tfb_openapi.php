#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Écrit la description OpenAPI de l'API du module atelier dans docs/api/production-v1.openapi.json
 * (adresse du serveur laissée en variable : API_BASE_URL). À relancer après tout changement de route.
 *   php Bin/tfb_openapi.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

$target = dirname(__DIR__) . '/docs/api/production-v1.openapi.json';
$json = json_encode(App\Core\Api\Workshop\OpenApiSpec::build(''), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($target, $json . "\n");
echo "written {$target}\n";
