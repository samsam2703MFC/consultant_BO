<?php
// Remplace le .htaccess d'Apache pour le serveur intégré de PHP.
$root = $_SERVER['DOCUMENT_ROOT'];
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (preg_match('#^/api/#', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api.php';
    require $root . '/api.php';
    return true;
}
if ($path !== '/' && (is_file($root . $path) || is_dir($root . $path))) {
    return false;
}
$_GET['url'] = ltrim($path, '/');
$_REQUEST['url'] = $_GET['url'];
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
return true;
