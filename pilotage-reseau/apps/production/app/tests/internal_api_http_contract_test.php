<?php
declare(strict_types=1);

function expectInternalApiHttp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$htaccess = file_get_contents(__DIR__ . '/../public/.htaccess');
$api = file_get_contents(__DIR__ . '/../public/api.php');
$routes = file_get_contents(__DIR__ . '/../App/Core/Routes/ApiRoutes.php');

expectInternalApiHttp(str_contains($htaccess, 'RewriteRule ^api/(.*)$ api.php [QSA,L]'), 'HTTP /api requests must be forwarded to api.php without a redirect.');
expectInternalApiHttp(!preg_match('/^\s*RewriteRule\b.*\[(?:[^\]]*,)?R(?:=\d+)?(?:,[^\]]*)?\]/mi', $htaccess) && !preg_match('/^\s*RewriteCond\b.*(?:HTTPS|X_FORWARDED_PROTO)/mi', $htaccess), 'The API rewrite configuration must not force HTTP requests to HTTPS.');
expectInternalApiHttp(str_contains($routes, '/api/internal/v1/client-orders'), 'The internal client-orders endpoint must remain registered.');
expectInternalApiHttp(!str_contains($routes, "'auth' => 'device'"), 'All internal API routes must be available without device authentication.');
expectInternalApiHttp(str_contains($api, 'Device authentication is intentionally disabled for the internal API.'), 'The API device-authentication gateway must remain disabled.');

echo "internal_api_http_contract_test: OK\n";
