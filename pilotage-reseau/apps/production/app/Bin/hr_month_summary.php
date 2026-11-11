#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [271] HR : le dernier jour du mois, à partir de 18 h, bilan des notes du mois envoyé à l'administrateur (une fois).
 * L'e-mail ne contient que qui et combien ; le texte des notes reste chiffré dans l'ERP (HR › Notatki miesiąca).
 *   php Bin/hr_month_summary.php     cron chaque jour à 18:05
 * Journal : seulement des nombres.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$r = $container->get(App\Services\Hr\HrService::class)->autoSummary();
echo json_encode(['hr_month_summary' => $r ?? 'nothing today', 'at' => date('Y-m-d H:i:s')]) . PHP_EOL;
