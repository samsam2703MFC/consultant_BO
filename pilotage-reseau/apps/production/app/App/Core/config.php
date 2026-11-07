<?php
require_once __DIR__ . '/../../public/vendor/autoload.php'; // Wczytaj autoloader Composera
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../', null, false);
$dotenv->load();

/** Adresy z .env (APP_URL, APIPREFIXE, ATELIER_RESOURCES_PREFIXE) mają pierwszeństwo przed wartościami poniżej **/
if (($_ENV['APP_URL'] ?? '') !== '') {
    $appUrl = rtrim($_ENV['APP_URL'], '/');
    // Schemat jak w bieżącym żądaniu: strona otwarta przez https nie może ładować CSS/JS przez http
    // (przeglądarka blokuje „mixed content” i strona wyświetla się bez stylów).
    if (PHP_SAPI !== 'cli') {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $appUrl = preg_replace('#^https?://#', $https ? 'https://' : 'http://', $appUrl);
    }
    define('ROOT', $appUrl);
}
if (($_ENV['APIPREFIXE'] ?? '') !== '') define('APIPREFIXE', $_ENV['APIPREFIXE']);
if (($_ENV['ATELIER_RESOURCES_PREFIXE'] ?? '') !== '') define('ATELIER_RESOURCES_PREFIXE', $_ENV['ATELIER_RESOURCES_PREFIXE']);

/** Baza danych: DB_HOST / DB_NAME / DB_USER / DB_PASS w pliku .env (App/Core/Database.php) **/
if(($_SERVER['SERVER_NAME'] ?? '') == 'slfood.local')
{
    //define('APIPREFIXE', 'https://franchiseatelier.com/api/public/Production/');
    defined('APIPREFIXE') || define('APIPREFIXE', 'http://localhost/Intranet/api/public/Production/');
//    define('APIPREFIXE', 'https://franchiseatelier.com/api/public/Production/');

    defined('ATELIER_RESOURCES_PREFIXE') || define('ATELIER_RESOURCES_PREFIXE', 'http://localhost/Intranet/resources/');

    defined('ROOT') || define('ROOT',
        (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
            ? 'https://'
            : 'http://') . $_SERVER['SERVER_NAME']);

}else
{
    defined('APIPREFIXE') || define('APIPREFIXE', 'https://franchiseatelier.com/api/public/Production/');
    defined('ATELIER_RESOURCES_PREFIXE') || define('ATELIER_RESOURCES_PREFIXE', 'https://franchiseatelier.com/resources/');
    //define('APIPREFIXE', 'http://localhost/Intranet/api/public/Production/');

    defined('ROOT') || define('ROOT', 'http://192.168.1.150/erp');
}

define('APP_NAME', "My Webiste");
define('APP_DESC', "Best website on the planet");

define('WDT_PRINT_COUNT', 1);
define('DIFF_PRINT_COUNT', 1);
define('WZ_PRINT_COUNT', 3);
define('CMR_PRINT_COUNT', 3);
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', "zł");
}//define('CURRENCY_SYMBOL', "zł");

/** true means show errors **/
define('DEBUG', true);

