<?php
/*
 * L'app employés (TFB-Employee), servie par le cockpit sous /consulant_bo/employee : le contrôleur
 * frontal. Le code vit hors du dossier public, dans apps/employee (vendor compris).
 */

use App\Employee\app\Services\Auth\JwtService;
use App\Employee\core\Bootstrap\App;
use App\Employee\core\Support\GlobalRegistry;

$APP = __DIR__ . '/../../apps/employee';
require_once $APP . '/vendor/autoload.php';
require $APP . '/config/app.php';
require $APP . '/src/core/Support/functions.php';

ini_set('display_errors', defined('EMPLOYEE_DEBUG') ? '1' : '0');
error_reporting(E_ALL);

GlobalRegistry::set('lang_code', getUserLanguage());
GlobalRegistry::set('tax_rates', explode(',', $_ENV['TAX_RATES']));
GlobalRegistry::set('currency', $_ENV['CURRENCY']);
GlobalRegistry::set('currency_symbol', $_ENV['CURRENCY_SYMBOL']);

// L'utilisateur connecté, lu dans son jeton.
if (isset($_COOKIE['employee_access_token'])) {
    $jwtService = new JwtService();
    $loggedUser = $jwtService->getLoggedUserObj();
    if ($loggedUser) {
        GlobalRegistry::set('user', $loggedUser);
        GlobalRegistry::set('lang_code', $loggedUser->getLanguageCode());
    }
}

$container = require $APP . '/src/core/Container/Container.php';
$app = $container->get(App::class);
$app->loadController();
