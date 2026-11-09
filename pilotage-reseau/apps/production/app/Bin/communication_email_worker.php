#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$worker = $container->get(App\Services\Communication\CommunicationEmailDeliveryWorker::class);
$limit = max(1, min(100, (int)($argv[1] ?? 20)));
$result = $worker->processDue($limit);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
