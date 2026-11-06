<?php
declare(strict_types=1);
/*
 * [256] Moja karta stanowiska (A4, makieta 35) : misje z zadaniami, godziny na misję, zatwierdzone cele — bez wyników,
 * premii i wynagrodzenia. Dane bez bazy (cardFrom).
 *
 *   php tests/manager_card_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Workshop\Manager\ManagerIndicators;
use App\Services\Workshop\Manager\ManagerService;

function expectCard(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$svc = (new ReflectionClass(ManagerService::class))->newInstanceWithoutConstructor();
$plan = ['id' => 1, 'year' => 2026, 'holder_user_id' => 7, 'holder_name' => 'Osoba Testowa', 'holder_start' => '2026-03-01',
    'salary_monthly' => '9000.00', 'envelope_pct' => '20', 'currency' => 'PLN', 'team_organisation' => null, 'auditor' => 'Audytor X',
    'audit_threshold' => '85', 'margin_threshold_pct' => null, 'price_reference_date' => null, 'workplace' => null, 'contract_type' => null,
    'working_time' => null, 'monthly_hours' => '168', 'mission_hours' => json_encode(['production' => 50, 'development' => 30, 'purchasing' => 22, 'staffing' => 16, 'logistics' => 25, 'haccp' => 25]),
    'updated_at' => null];
$versions = [
    ['indicator' => 'material_cost_pct', 'target' => '33', 'validated_at' => '2026-02-01 10:00:00'],
    ['indicator' => 'otif_pct', 'target' => '95', 'validated_at' => '2026-02-01 10:00:00'],
    ['indicator' => 'loss_pct', 'target' => '2', 'validated_at' => null],                   // szkic : nie pokazywany
    ['indicator' => 'stock_days', 'target' => '14', 'validated_at' => '2026-12-01 10:00:00'], // zatwierdzony później
];
$c = $svc->cardFrom($plan, $versions, '2026-06-01 12:00:00');

expectCard(array_column($c['missions'], 'code') === ManagerIndicators::MISSIONS, 'sześć misji w kolejności karty');
expectCard($c['hours_total'] == 168.0 && $c['missions'][0]['hours'] == 50.0 && abs($c['missions'][0]['share'] - 50 / 168) < 1e-9, 'godziny i udział czasu');
expectCard(count($c['missions'][0]['tasks']) === 3 && str_contains(implode(' ', $c['missions'][5]['tasks']), 'Audytor X'), 'zadania z karty, audytor podstawiony');
$goals = array_merge(...array_column($c['missions'], 'goals'));
$byName = array_column($goals, 'target', 'name');
expectCard(count($goals) === count(ManagerIndicators::INDICATORS) + 1 && $c['goals_count'] === count($goals), '12 wskaźników + próg audytu HACCP');
expectCard($byName['Koszt surowców % ceny sprzedaży'] === '≤ 33,0 %' && $byName['Dostawy kompletne i na czas'] === '≥ 95,0 %', 'cele zatwierdzone ze znakiem kierunku');
expectCard($byName['Wskaźnik strat w pracowni'] === null && $byName['Dni zapasu (wyroby gotowe)'] === null, 'szkic i cel zatwierdzony później : « cel do ustalenia »');
expectCard($byName['Wynik audytu HACCP'] === '≥ 85 %' && $c['goals_set'] === 3, 'próg audytu HACCP i liczba ustalonych celów');
expectCard($c['validated_at'] === '2026-02-01 10:00:00' && $c['holder'] === 'Osoba Testowa' && $c['year'] === 2026, 'data zatwierdzenia, osoba, rok');
$json = json_encode($c);
expectCard(!str_contains($json, '9000') && !str_contains($json, 'salary') && !str_contains($json, 'envelope'), 'bez wynagrodzenia i puli premii');

// Godziny bez zapisu w planie : propozycja (domyślne 168 h).
$c2 = $svc->cardFrom(['mission_hours' => null, 'monthly_hours' => null] + $plan, [], '2026-06-01 12:00:00');
expectCard($c2['hours_default'] && $c2['hours_total'] == array_sum(ManagerIndicators::DEFAULT_MISSION_HOURS) && $c2['goals_set'] === 1 && $c2['validated_at'] === null, 'godziny domyślne, bez celów');

echo "manager_card_test: OK\n";
