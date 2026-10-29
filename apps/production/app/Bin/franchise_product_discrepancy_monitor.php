#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Client\PriceList\FranchiseProductDiscrepancyNotificationService;
use App\Services\Cron\CronRunService;
use App\Services\Cron\CronSkippedException;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
$container = require $root . '/App/Core/bootstrap.php';
$runner = $container->get(CronRunService::class);

exit($runner->run('franchise_product_discrepancy_monitor', static function () use ($container): void {
    $lock = fopen(sys_get_temp_dir() . '/franchise_product_discrepancy_monitor.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new CronSkippedException('Another franchise product monitor is already running.');
    }
    try {
        $container->get(FranchiseProductDiscrepancyNotificationService::class)->refreshAndNotify();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}));
