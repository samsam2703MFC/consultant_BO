<?php
/*
 * Réglages de l'app employés (TFB-Employee), hébergée par le cockpit sous /consulant_bo/employee
 * (demande du 06/10/2026). L'app d'origine lisait tout dans un .env et supposait vivre à la racine
 * d'un domaine (/employee) ; ici :
 *   - ROOT se déduit de l'adresse appelée (le dossier public/employee, quel que soit l'alias) ;
 *   - l'API est celle du panel, la même que le cockpit (config/config.php → panelApi.base), sinon
 *     EMPLOYEE_API_BASE, sinon le panel de la marque ;
 *   - les autres réglages ont une valeur par défaut, et se surchargent par variable d'environnement
 *     ou par un apps/employee/.env (jamais dans le dépôt).
 */

$https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$scheme = $https ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

// Le chemin public de l'app : le dossier du contrôleur frontal, tel que le serveur le sert.
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/employee/index.php');
$base = rtrim(str_replace('\\', '/', dirname($script)), '/');
if (preg_match('#/employee$#', $base) !== 1) { $base .= '/employee'; }
if (!defined('EMPLOYEE_BASE_PATH')) { define('EMPLOYEE_BASE_PATH', $base); }
define('ROOT', $scheme . $host . $base);
define('IS_HTTPS', $https);

// Un .env optionnel, hors dépôt, pour surcharger ce qui suit.
$envFile = __DIR__ . '/../.env';
if (is_file($envFile) && class_exists('Dotenv\Dotenv')) {
    try { Dotenv\Dotenv::createImmutable(dirname($envFile))->safeLoad(); } catch (Throwable $e) { /* .env illisible : les défauts */ }
}
$env = static function (string $k, string $d): string {
    $v = $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k);
    return is_string($v) && $v !== '' ? $v : $d;
};

// L'API du panel : celle du cockpit quand elle est réglée, pour ne la tenir qu'à un endroit.
$apiBase = $env('EMPLOYEE_API_BASE', '');
if ($apiBase === '') {
    $cfg = __DIR__ . '/../../../config/config.php';
    if (is_file($cfg)) {
        try { $c = require $cfg; $apiBase = (string) ($c['panelApi']['base'] ?? ''); } catch (Throwable $e) { $apiBase = ''; }
    }
}
if ($apiBase === '') { $apiBase = 'https://atelierby.tfbuddy.com/api/v1'; }
define('API_BASE_URL', rtrim($apiBase, '/'));
$panelHost = preg_replace('#/api/v1/?$#', '', API_BASE_URL);
define('SHARED_FILES_URL', $env('EMPLOYEE_SHARED_FILES_URL', $panelHost . '/shared-assets'));
// L'API du cockpit, pour « Mes primes » : celle qui héberge l'app (…/consulant_bo/api/cockpit),
// sinon EMPLOYEE_COCKPIT_API. Le cockpit vérifie le jeton d'employé auprès du panel.
define('COCKPIT_API_URL', rtrim($env('EMPLOYEE_COCKPIT_API', $scheme . $host . preg_replace('#/employee$#', '', $base) . '/api/cockpit'), '/'));
define('THEME_CONFIG_PATH', $env('EMPLOYEE_THEME_CONFIG', $panelHost . '/shared/admin-theme-config.json'));

// Le jeton n'est pas vérifié ici (l'API le vérifie) : ces réglages ne servent qu'aux durées.
define('JWT_SECRET_KEY', $env('JWT_SECRET', ''));
define('JWT_ISSUER', $env('JWT_ISSUER', 'tfbuddy'));
define('JWT_ACCESS_TOKEN_EXPIRY', (int) $env('JWT_ACCESS_TOKEN_EXPIRY', '3600'));
define('JWT_REFRESH_TOKEN_EXPIRY', (int) $env('JWT_REFRESH_TOKEN_EXPIRY', '1209600'));

define('DEFAULT_LANGUAGE', $env('DEFAULT_LANGUAGE', 'fr'));
define('COUNTRY_CODE', $env('DEFAULT_COUNTRY', 'BE'));
define('CURRENCY', $env('CURRENCY', 'EUR'));
if (!defined('CURRENCY_SYMBOL')) { define('CURRENCY_SYMBOL', $env('CURRENCY_SYMBOL', '€')); }
define('APP_CURRENCY_SYMBOL', CURRENCY_SYMBOL);
define('APP_NAME', $env('APP_NAME', 'L’Atelier by · Équipe'));
define('APP_DESC', $env('APP_DESC', 'Horaire, tâches et profil de l’équipe'));
foreach (['TAX_RATES' => '6,12,21', 'CURRENCY' => CURRENCY, 'CURRENCY_SYMBOL' => CURRENCY_SYMBOL] as $k => $v) {
    if (!isset($_ENV[$k]) || $_ENV[$k] === '') { $_ENV[$k] = $env($k, $v); }
}

// En production, aucune erreur à l'écran : EMPLOYEE_DEBUG=1 pour les voir.
const DEBUG = false;
if ($env('EMPLOYEE_DEBUG', '0') === '1' && !defined('EMPLOYEE_DEBUG')) { define('EMPLOYEE_DEBUG', true); }
