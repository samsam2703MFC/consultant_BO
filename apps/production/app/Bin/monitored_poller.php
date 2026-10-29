#!/usr/bin/env php
<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Cron\CronRunService;
use App\Services\Cron\CronSkippedException;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$runner = $container->get(CronRunService::class);

exit($runner->run('poller', static function () use ($container): void {
    $lock = fopen(sys_get_temp_dir() . '/orders_collector.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new CronSkippedException('Another poller instance is already running.');
    }

    try {
        $errors = [];

        try {
            $container->get(\App\Services\Client\Order\OrderCollectorService::class)->runCollector(50);
        } catch (Throwable $exception) {
            $errors[] = 'order: ' . $exception->getMessage();
        }

        try {
            $container->get(\App\Services\Client\Complaint\ComplaintCollectorService::class)->runCollector(50);
        } catch (Throwable $exception) {
            $errors[] = 'complaint: ' . $exception->getMessage();
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}));
