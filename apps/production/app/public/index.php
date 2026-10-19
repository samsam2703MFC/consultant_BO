<?php

use App\Core\App;

// Własne ciasteczko sesji ERP, ograniczone do katalogu aplikacji: na wspólnym
// serwerze (cockpit, panel) sesja innej aplikacji nie może otworzyć ERP.
session_name('ERPSESSID');
session_set_cookie_params([
    'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require __DIR__ . "/../App/Core/init.php";

DEBUG ? ini_set('display_errors', 1) : ini_set('display_errors', 0);


$app = new App;
$app->loadController();

