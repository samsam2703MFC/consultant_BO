#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * [438] Różnice kursowe : kursy średnie NBP (tabela A) des 10 derniers jours → n_fx_rate, jours notés (table publiée ou
 * non : week-end, jour férié) → n_fx_rate_day. Lecture seule de l'API publique NBP, adresse dans le réglage
 * fx.nbp_api_url (vide = rien). Les jours manquants plus anciens sont demandés à l'écran, au besoin.
 *   php Bin/fx_rates_fetch.php [--days=N]     cron : chaque jour à 13:20 (NBP publie la table A vers midi)
 * Journal : seulement des nombres.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../App/Core/init.php';

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$nbp = $container->get(App\Services\Finances\Fx\NbpRateService::class);
if ($nbp->baseUrl() === null) {
    echo json_encode(['skipped' => 'fx.nbp_api_url empty']) . PHP_EOL;
    exit(0);
}
$days = 10;
foreach ($argv as $a) if (preg_match('/^--days=(\d{1,2})$/', $a, $m)) $days = max(1, min(93, (int) $m[1]));
try {
    $r = $nbp->refresh(null, $days);
    echo json_encode(['fx_rates' => $r, 'at' => date('Y-m-d H:i:s')]) . PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['error' => $e instanceof App\Exceptions\CustomException ? $e->getMessage() : 'NBP fetch failed', 'at' => date('Y-m-d H:i:s')]) . PHP_EOL;
    exit(1);
}
