<?php

use App\Core\App;

session_start();

require __DIR__ . "/../App/Core/init.php";

DEBUG ? ini_set('display_errors', 1) : ini_set('display_errors', 0);


$app = new App;
$app->loadController();

