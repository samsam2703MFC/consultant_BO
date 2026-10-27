<?php
require_once __DIR__ . '/../App/Core/bootstrap.php';

$sectionId = isset($argv[1]) ? (int)$argv[1] : 0;
$monthId = isset($argv[2]) ? (int)$argv[2] : 0;

if ($sectionId <= 0) {
    echo "Usage: php Bin/cost_analysis_section_smoke.php <section_id> [month_id]\n";
    exit(0);
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$service = $container->get(App\Services\Finances\CostAnalysisSectionService::class);

$result = $service->calculate($sectionId, $monthId);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

