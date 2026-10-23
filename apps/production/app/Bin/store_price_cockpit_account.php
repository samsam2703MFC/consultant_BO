#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Ceny w sklepach (marże, Belgia) : konto API panelu = to samo co w cockpicie konsultanta.
 * Czyta na serwerze konto cockpitu (tabela ceo_app_setting, klucz panelApi ; potem 'panelApi' z config.php cockpitu ;
 * potem PANEL_API_PHONE / _PASSWORD / _BASE) i zapisuje je w tfb_store_price_config, hasło zaszyfrowane.
 * Konto wpisane w ERP ręcznie nie jest nadpisywane. Hasło nigdy nie jest wypisywane (tylko końcówka telefonu).
 *
 *   COCKPIT_CFG=/var/www/consulant_bo/config/config.php php Bin/store_price_cockpit_account.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopConfig;
use App\Repositories\Finances\StorePriceRepository;
use App\Repositories\Organization\PageAccessRepository;
use App\Services\Finances\StorePrice\PanelApiClient;
use App\Services\Finances\StorePrice\PanelSecretCipher;
use App\Services\Finances\StorePrice\StorePriceAccountService;
use App\Services\Organization\PageAccessService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) Dotenv::createImmutable($root)->safeLoad();

$say = static fn(string $m) => print('Konto API panelu (ceny w sklepach) : ' . $m . PHP_EOL);

/** Konto panelu z cockpitu : [base, phone, password] albo null. */
$cockpitAccount = static function (): ?array {
    $path = getenv('COCKPIT_CFG') ?: '/var/www/consulant_bo/config/config.php';
    $cfg = is_readable($path) ? (static fn(string $f) => require $f)($path) : null;
    $candidates = [];
    if (is_array($cfg) && is_array($cfg['db'] ?? null)) {
        $db = $cfg['db'];
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? '127.0.0.1', $db['port'] ?? '3306', $db['name'] ?? ''),
                (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $st = $pdo->prepare('SELECT value FROM ceo_app_setting WHERE `key` = ?');
            $st->execute(['panelApi']);
            $v = $st->fetchColumn();
            if ($v !== false) $candidates[] = json_decode((string) $v, true);
        } catch (Throwable $e) {
            // Baza cockpitu niedostępna : następne źródła.
        }
    }
    if (is_array($cfg) && is_array($cfg['panelApi'] ?? null)) $candidates[] = $cfg['panelApi'];
    $candidates[] = ['base' => getenv('PANEL_API_BASE') ?: null, 'phone' => getenv('PANEL_API_PHONE') ?: null, 'password' => getenv('PANEL_API_PASSWORD') ?: null];
    foreach ($candidates as $c) {
        if (is_array($c) && trim((string) ($c['phone'] ?? '')) !== '' && (string) ($c['password'] ?? '') !== '') {
            return ['base' => $c['base'] ?? null, 'phone' => (string) $c['phone'], 'password' => (string) $c['password']];
        }
    }
    return null;
};

try {
    $account = $cockpitAccount();
    if ($account === null) {
        $say('brak konta w cockpicie konsultanta — wpisz je w ERP (Ustawienia → Konto API panelu).');
        exit(0);
    }
    $pdo = (new App\Core\Database())->getConnection();
    $repo = new StorePriceRepository($pdo);
    $cipher = new PanelSecretCipher(new WorkshopConfig());
    $service = new StorePriceAccountService($repo, $cipher, new PanelApiClient($repo, $cipher), new PageAccessService(new PageAccessRepository($pdo)));
    $tail = '…' . substr(preg_replace('/\D/', '', $account['phone']), -3);
    $say(match ($service->importFromCockpit($account)) {
        'copied' => 'skopiowane z cockpitu konsultanta (telefon ' . $tail . ').',
        'unchanged' => 'to samo co w cockpicie (telefon ' . $tail . ') — bez zmian.',
        'manual' => 'konto wpisane w ERP ręcznie — bez zmian.',
        default => 'brak konta w cockpicie konsultanta.',
    });
} catch (Throwable $e) {
    // Bez szczegółów mogących zawierać dane konta : tylko typ błędu.
    fwrite(STDERR, 'store_price_cockpit_account: błąd (' . get_class($e) . ') — konto nie skopiowane.' . PHP_EOL);
    exit(1);
}
