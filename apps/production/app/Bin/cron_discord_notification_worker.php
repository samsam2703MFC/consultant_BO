#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Cron\CronDiscordNotificationWorker;
use App\Services\Cron\CronRunService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$runner = $container->get(CronRunService::class);

// The transport worker is logged, but does not notify about itself to avoid a loop.
exit($runner->run('cron_discord_notification_worker', static function () use ($container): void {
    $container->get(CronDiscordNotificationWorker::class)->processDue();
}, [], false));
