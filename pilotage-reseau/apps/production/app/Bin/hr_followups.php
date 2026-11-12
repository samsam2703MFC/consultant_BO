#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [279] HR : rappel des follow-ups dans la cloche du responsable — chaque jour à partir du terme, tant qu'ils restent
 * ouverts (« po terminie » en rouge). Aucun e-mail ni SMS ; le texte des notes reste chiffré dans l'ERP.
 *   php Bin/hr_followups.php     cron chaque jour à 07:10
 * Journal : seulement des nombres.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$n = $container->get(App\Services\Hr\HrQuickNoteService::class)->reminders();
echo json_encode(['hr_followups' => $n, 'at' => date('Y-m-d H:i:s')]) . PHP_EOL;
