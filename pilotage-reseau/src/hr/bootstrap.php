<?php

declare(strict_types=1);

/**
 * Assemble le module RH pour l'API (public/api/hr/index.php) et le cron (bin/hr_dimona_cron.php).
 *
 * Production : la base du cockpit (Db::pdo()), l'annuaire du panel (PanelApi) et le profil chiffré des
 * employés (src/profil_employe.php). Développement local seulement (php -S ou CLI) : HR_DB_DSN (ex.
 * sqlite:/tmp/hr.sqlite, ou mysql:… avec HR_DB_USER / HR_DB_PASSWORD) et HR_DEV_DIRECTORY (annuaire JSON)
 * remplacent la base et l'annuaire.
 */

use Hr\App;
use Hr\Directory\CockpitDirectory;
use Hr\Directory\StaticDirectory;
use Hr\Support\Config;
use Hr\Support\Crypto;
use Hr\Support\CurlHttpClient;
use Hr\Support\Database;

require_once __DIR__ . '/autoload.php';
require_once dirname(__DIR__) . '/Db.php';

// PanelApi lit ses réglages par setting() (défini par l'API du cockpit, absent ici) : la même lecture,
// sans le reste du cockpit.
if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        try {
            $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
        } catch (Throwable $e) {
            return $default;
        }
        return $r === null ? $default : json_decode((string) $r['value'], true);
    }
}
foreach (['panel_api.php', 'profil_employe.php'] as $f) {
    if (is_file(dirname(__DIR__) . '/' . $f)) {
        require_once dirname(__DIR__) . '/' . $f;
    }
}

return (static function (): App {
    $local = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server';
    $dsn = $local ? (string) (getenv('HR_DB_DSN') ?: '') : '';
    Database::set($dsn !== '' ? new PDO($dsn, (string) (getenv('HR_DB_USER') ?: '') ?: null, (string) (getenv('HR_DB_PASSWORD') ?: '') ?: null) : Db::pdo());

    $file = Db::config()['hr'] ?? [];
    $config = Config::load(is_array($file) ? $file : []);

    $dirFile = $local ? (string) (getenv('HR_DEV_DIRECTORY') ?: '') : '';
    $directory = $dirFile !== '' ? StaticDirectory::fromFile($dirFile) : new CockpitDirectory();

    return new App($config, new Crypto($config->masterKey()), new CurlHttpClient(), $directory);
})();
