<?php
/**
 * Routeur pour le serveur de développement PHP :
 *   php -S 0.0.0.0:8080 -t public public/router.php
 *
 * /api/cockpit/*  → API REST
 * fichiers réels  → servis tels quels
 * tout le reste   → index.html (SPA)
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/api/cockpit')) {
    require __DIR__ . '/api/index.php';
    return true;
}
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false; // fichier statique
}
require __DIR__ . '/index.html';
return true;
