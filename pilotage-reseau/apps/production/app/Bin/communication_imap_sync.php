#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$worker = $container->get(App\Services\Communication\CommunicationImapSyncWorker::class);
$limit = max(1, min(500, (int)($argv[1] ?? 100)));
echo json_encode($worker->syncActive($limit), JSON_UNESCAPED_UNICODE) . PHP_EOL;
