<?php
/**
 * Routeur pour le serveur de développement PHP :
 *   php -S 0.0.0.0:8080 -t public public/router.php
 *
 * /api/cockpit/*  → API REST
 * fichiers réels  → servis tels quels
 * dossier avec son index.html (tablette/, planogramme/…) → cet index.html
 * fichier absent sous tablette/ ou uploads/ → 404, comme le .htaccess
 * tout le reste   → index.html (SPA)
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($uri, '/api/cockpit')) {
    require __DIR__ . '/api/index.php';
    return true;
}
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    // Le serveur intégré ne connaît pas le type du manifeste d'une PWA.
    if (str_ends_with($uri, '.webmanifest')) {
        header('Content-Type: application/manifest+json');
        readfile(__DIR__ . $uri);
        return true;
    }
    return false; // fichier statique
}
if ($uri !== '/' && is_dir(__DIR__ . $uri) && is_file(rtrim(__DIR__ . $uri, '/') . '/index.html')) {
    // Sans la barre finale, les chemins relatifs de la page (./assets/…) partiraient du dossier parent.
    if (!str_ends_with($uri, '/')) {
        $q = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        header('Location: ' . $uri . '/' . ($q ? '?' . $q : ''), true, 301);
        return true;
    }
    return false; // le serveur intégré sert l'index.html du dossier
}
if (preg_match('#^/(tablette|uploads|assistant/uploads)(/|$)#', $uri)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Fichier absent';
    return true;
}
require __DIR__ . '/index.html';
return true;
