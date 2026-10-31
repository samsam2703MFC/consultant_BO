<?php
declare(strict_types=1);
/*
 * Module atelier — tests unitaires sans base : JWT, validation, durée des shifts,
 * découpage des migrations, mot de passe, description OpenAPI.
 *   php tests/tfb_unit_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\JwtException;
use App\Core\Api\Workshop\OpenApiSpec;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\Validator;
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Services\Workshop\Auth\AuthService;
use App\Services\Workshop\Migration\MigrationRunner;
use App\Services\Workshop\Planning\DemandCalculator;
use App\Services\Workshop\Planning\PlanningService;
use App\Services\Workshop\Planning\WeekSplit;
use App\Services\Workshop\Product\CobotRouting;
use App\Services\Workshop\Product\StandardTime;
use App\Services\Workshop\Scheduling\Scheduler;
use App\Services\Workshop\Scheduling\ShiftOptimizer;
use App\Services\Workshop\Execution\OeeService;
use App\Services\Workshop\Execution\TimesheetService;
use App\Services\Workshop\Manager\ManagerIndicators;
use App\Services\Workshop\Supply\InvoiceParser;
use App\Services\Workshop\Referentiel\ShiftTemplateService;
use App\Core\Web\PageAccess;
use App\Services\Organization\ActivityService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function jwtError(callable $fn): ?string
{
    try { $fn(); } catch (JwtException $e) { return $e->getMessage(); }
    return null;
}

// --- JWT
$secret = str_repeat('k', 40);
$jwt = new Jwt($secret);
$now = 1_800_000_000;
$token = $jwt->encode(['sub' => '7', 'aud' => 'tfb-production', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 900]);
check($jwt->decode($token, 'tfb-production', $now + 10)['sub'] === '7', 'valid token decodes');
check(jwtError(fn() => $jwt->decode($token, 'tfb-production', $now + 900 + 31)) === 'expired', 'expired token is refused');
check(jwtError(fn() => $jwt->decode($token, 'other', $now)) === 'audience', 'wrong audience is refused');
check(jwtError(fn() => (new Jwt(str_repeat('x', 40)))->decode($token, 'tfb-production', $now)) === 'signature', 'other secret is refused');
[$h, $p] = explode('.', $token);
$tampered = $h . '.' . rtrim(strtr(base64_encode(json_encode(['sub' => '1', 'aud' => 'tfb-production', 'exp' => $now + 900])), '+/', '-_'), '=') . '.' . explode('.', $token)[2];
check(jwtError(fn() => $jwt->decode($tampered, 'tfb-production', $now)) === 'signature', 'tampered payload is refused');
$none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $p . '.';
check(jwtError(fn() => $jwt->decode($none, 'tfb-production', $now)) === 'algorithm', 'alg=none is refused');
check(jwtError(fn() => $jwt->decode('abc', 'tfb-production', $now)) === 'malformed', 'garbage is refused');
$short = false;
try { new Jwt('short'); } catch (InvalidArgumentException) { $short = true; }
check($short, 'a short secret is refused');

// --- Mots de passe : bcrypt et comptes historiques en clair
check(AuthService::passwordMatches(password_hash('s3cret', PASSWORD_BCRYPT), 's3cret'), 'bcrypt password matches');
check(!AuthService::passwordMatches(password_hash('s3cret', PASSWORD_BCRYPT), 'nope'), 'wrong bcrypt password fails');
check(AuthService::passwordMatches('legacy', 'legacy') && !AuthService::passwordMatches('legacy', 'Legacy'), 'legacy plain password');
check(!AuthService::passwordMatches('', '') && !AuthService::passwordMatches('x', ''), 'empty passwords never match');
check(\App\Core\Security\Passwords::needsRehash('legacy') && !\App\Core\Security\Passwords::needsRehash(\App\Core\Security\Passwords::hash('x'))
    && \App\Core\Security\Passwords::isHash(\App\Core\Security\Passwords::hash('x')) && !\App\Core\Security\Passwords::isHash('x'), 'plain-text passwords flagged for rehash, bcrypt hashes kept');
// Zapis konta : hasło → bcrypt, puste = bez zmiany, już zaszyfrowane nie szyfrowane drugi raz.
$users = new class extends \App\Repositories\User\UserRepository {
    public array $saved = [];
    public function __construct() {}
    public function insert(array $data): int|bool { $this->saved[] = $data; return 1; }
    public function update(mixed $id, array $data, $id_column = 'id'): bool { $this->saved[] = $data; return true; }
};
$svc = new \App\Services\User\UserService($users);
$svc->insert(['login' => 'a', 'password' => 'Sekret-1']);
$svc->update(1, ['login' => 'a', 'password' => '']);
$hashed = \App\Core\Security\Passwords::hash('x');
$svc->update(1, ['password' => $hashed]);
check(password_verify('Sekret-1', $users->saved[0]['password']) && !array_key_exists('password', $users->saved[1]) && $users->saved[2]['password'] === $hashed,
    'account save: password stored as bcrypt, empty = unchanged, never hashed twice');

// --- Validation
$errors = function (array $input, bool $partial, callable $rules): array {
    $v = new Validator($input, $partial);
    $rules($v);
    return array_map(fn($e) => $e['field'] . ':' . $e['code'], $v->errors());
};
check($errors([], false, fn($v) => $v->string('name_pl', 100)) === ['name_pl:REQUIRED'], 'required on create');
check($errors([], true, fn($v) => $v->string('name_pl', 100)) === [], 'absent field is fine on PATCH');
check($errors(['name_pl' => '  '], true, fn($v) => $v->string('name_pl', 100)) === ['name_pl:REQUIRED'], 'blank string is missing');
check($errors(['x' => 1], false, fn($v) => $v->only(['a'])) === ['x:UNKNOWN_FIELD'], 'unknown field is reported');
check($errors(['code' => 'ab'], false, fn($v) => $v->code('code')) === [], 'code is upper-cased');
check($errors(['code' => 'a b'], false, fn($v) => $v->code('code')) === ['code:INVALID_CODE_FORMAT'], 'bad code');
check($errors(['c' => 0], false, fn($v) => $v->decimal('c', true, 0, null, false, true)) === ['c:GREATER_THAN'], 'capacity must be > 0');
check($errors(['t' => '24:00'], false, fn($v) => $v->time('t')) === ['t:INVALID_TIME'], '24:00 is not a time');
check($errors(['d' => [0, 3]], false, fn($v) => $v->days('d')) === ['d:INVALID_DAYS'], 'day 0 is refused');
check($errors(['u' => 'http://x.test/a.jpg'], false, fn($v) => $v->url('u')) === ['u:INVALID_URL'], 'http photo refused');
check($errors(['u' => 'https://other.test/a.jpg'], false, fn($v) => $v->url('u', 'https://photos.r2.test')) === ['u:URL_NOT_R2'], 'photo outside the R2 bucket refused');
$v = new Validator(['d' => [3, 1, 3], 't' => '6:00'], false);
$v->days('d')->time('t');
check($v->value('d') === '1,3' && $v->hasError('t'), 'days are sorted and deduplicated; 6:00 needs two digits');

// --- Durée d'un shift, minuit compris
check(ShiftTemplateService::durationMinutes('22:00', '06:00') === 480, 'night shift lasts 480 min');
check(ShiftTemplateService::durationMinutes('06:00', '14:00') === 480, 'day shift lasts 480 min');
check(ShiftTemplateService::durationMinutes('23:00', '07:30') === 510, 'shift crossing midnight');

// --- Découpage SQL (points-virgules dans les chaînes, commentaires, couleurs #)
$parts = MigrationRunner::splitStatements("-- a; comment\nINSERT INTO t VALUES ('a;b', 'l''x', '#8D1D2C');\n# other; comment\nSELECT 1;\n");
check(count($parts) === 2 && str_contains($parts[0], "'a;b'") && str_contains($parts[0], '#8D1D2C'), 'SQL splitter respects quotes and comments');

// --- Traductions : mêmes clés en polonais et en français
$pl = json_decode(file_get_contents(__DIR__ . '/../public/resources/lang/api/pl/production.json'), true);
$fr = json_decode(file_get_contents(__DIR__ . '/../public/resources/lang/api/fr/production.json'), true);
foreach (['errors', 'field_errors', 'fields', 'entities'] as $section) {
    check(array_keys($pl[$section]) === array_keys($fr[$section]), "translations: same keys in {$section}");
}
check(Translator::negotiate(null, 'fr-BE,fr;q=0.9', 'pl') === 'fr' && Translator::negotiate('xx', 'de', 'pl') === 'pl', 'language negotiation');
check((new Translator('fr'))->fieldError('robot_mode', 'ROBOT_MODE_REQUIRED') !== 'ROBOT_MODE_REQUIRED', 'field error is translated');

// --- Coboty : etap na cobocie (kadencja cobota, godziny = czas × część osoby), oszczędzone godziny
$manualStep = ['id' => 7, 'fixed_time_s' => 0, 'variable_time_s' => 6, 'capacity_per_pass' => 1, 'operators_required' => 2];
$plainStep = ['id' => 8, 'fixed_time_s' => 600, 'variable_time_s' => 0, 'capacity_per_pass' => 100, 'operators_required' => 1];
$withCobot = CobotRouting::apply([$manualStep, $plainStep], [7 => ['id_workstation' => 55, 'fixed_time_s' => 0, 'variable_time_s' => 4, 'capacity_per_pass' => 1, 'operator_share' => 0.25, 'name' => 'Cobot A']]);
check($withCobot[0]['operators_required'] === 0.25 && $withCobot[0]['variable_time_s'] === 4.0 && $withCobot[0]['cobot']['name'] === 'Cobot A' && $withCobot[0]['manual']['operators_required'] === 2
    && !isset($withCobot[1]['cobot']), 'cobot step: cobot cadence, share of a person; other steps unchanged');
// 100 szt. : ręcznie 6 s × 100 × 2 os. = 1200 s, cobot 4 s × 100 × 0,25 = 100 s ; etap 2 : 600 s w obu → oszczędność 1100 s
check(abs(CobotRouting::savedHours($withCobot, 100) - 1100 / 3600) < 1e-9 && CobotRouting::savedHours([$manualStep], 100) === 0.0 && CobotRouting::savedHours($withCobot, 0) === 0.0,
    'saved hours = manual routing − routing with cobots');
check(StandardTime::routing(CobotRouting::manual($withCobot), 100)['totals']['labour_s'] === StandardTime::routing([$manualStep, $plainStep], 100)['totals']['labour_s'], 'manual routing restored');
$sameCadence = CobotRouting::apply([$manualStep], [7 => ['id_workstation' => 55, 'fixed_time_s' => 0, 'variable_time_s' => 0, 'capacity_per_pass' => 1, 'operator_share' => 0.5]]);
check($sameCadence[0]['variable_time_s'] === 6 && StandardTime::routing($sameCadence, 100)['totals']['labour_s'] === 300.0, 'cobot without own cadence: step times, labour × share');

// --- TRS : dostępność × wydajność × jakość (postoje planowane poza czasem otwarcia)
$f = OeeService::figures(10800, 1800, 9000, 1000, 50);
check($f['availability'] === 85.7 && $f['performance'] === 83.3 && $f['quality'] === 95.0 && $f['oee'] === 67.9, 'TRS: 3 h run, 30 min breakdown, 2.5 h standard, 5 % waste');
$f = OeeService::figures(3600, 0, 4500, 100, 0);
check($f['performance'] === 100.0 && $f['performance_raw'] === 125.0 && $f['oee'] === 100.0, 'performance capped at 100 % (raw value kept)');
$f = OeeService::figures(3600, 600, 0, 0, 0);
check($f['availability'] === 85.7 && $f['performance'] === null && $f['quality'] === null && $f['oee'] === null, 'no production linked: availability only');
check(OeeService::figures(0, 0, 0, 0, 0)['availability'] === null, 'nothing logged: no figures');

// --- Temps standards (P2) : durée = temps fixe × passages + temps par pièce × quantité
$oven = ['fixed_time_s' => 1080, 'variable_time_s' => 0, 'capacity_per_pass' => 60, 'operators_required' => 1];
check(StandardTime::step($oven, 10)['work_s'] === StandardTime::step($oven, 60)['work_s'], 'fixed time identical for 10 and 60 pieces in one pass');
check(StandardTime::step($oven, 60)['passes'] === 1 && StandardTime::step($oven, 61)['passes'] === 2, 'a 61st piece needs a second pass');
check(StandardTime::step($oven, 61)['work_s'] === 2160.0, 'two passes, twice the fixed time');
check(StandardTime::passes(0.3 * 3, 0.3) === 3, 'no extra pass from floating point (0.9 / 0.3)');
check(StandardTime::step($oven, 0)['passes'] === 0 && StandardTime::step($oven, 0)['elapsed_s'] === 0.0, 'nothing to make, no time');
$shaping = ['fixed_time_s' => 300, 'variable_time_s' => 4.5, 'capacity_per_pass' => 200, 'operators_required' => 2, 'cleaning_time_s' => 600, 'delay_after_s' => 3600];
$s = StandardTime::step($shaping, 100);
check($s['work_s'] === 750.0 && $s['cleaning_s'] === 600.0 && $s['elapsed_s'] === 4950.0, 'work + cleaning + delay');
check($s['labour_s'] === 2700.0, 'labour = (work + cleaning) × operators, the delay does not occupy the team');
$r = StandardTime::routing([$shaping + ['step_no' => 1], $oven + ['step_no' => 2]], 100);
check($r['totals']['work_s'] === 750.0 + 2160.0 && abs($r['labour_s_per_unit'] - (2700 + 2160) / 100) < 1e-9, 'routing totals and labour per unit');
check(abs(StandardTime::unitTime('variable', $shaping, 100, 1, 850) - 5.5) < 1e-9, 'measured per-piece time removes the fixed part');
check(abs(StandardTime::unitTime('fixed', $oven, 120, 2, 2400) - 1200) < 1e-9, 'measured per-pass time');
check(StandardTime::unitTime('variable', $shaping, 100, 1, 200) === 0.0, 'never negative');
check(StandardTime::movingAverage([10, 20, 30, 40, 50, 60], 5) === 30.0, 'moving average of the 5 latest');
check(StandardTime::defaultComponent($oven) === 'fixed' && StandardTime::defaultComponent($shaping) === 'variable', 'default component');

// --- Besoins (P3) : lots, MTS plafonné au stock max, surgélateur, semi-finis
check(DemandCalculator::roundBatch(50, 240, 24) === 240.0 && DemandCalculator::roundBatch(250, 240, 24) === 264.0, 'batch: at least the minimum, in multiples');
check(DemandCalculator::roundBatch(0, 240, 24) === 0.0 && DemandCalculator::roundBatch(7, null, null) === 7.0, 'no batch rules, no rounding');
check(DemandCalculator::roundBatch(900, null, 1300) === 1300.0 && DemandCalculator::roundBatch(1400, null, 1300) === 2600.0, 'production batch (pistolet 1300): whole batches');

// --- Tydzień : partia produkcyjna — zadania na wysyłkę w pełnych partiach, dni dzielone na pełne partie
check(WeekSplit::tasks(2600.0, 0.0, ['2026-10-22' => 900.0], 1300.0) === ['direct' => ['2026-10-22' => 1300.0], 'stock' => 1300.0],
    'week: 900 pieces to ship = 1 whole batch of 1300 before the truck, the other batch for stock');
check(WeekSplit::tasks(2600.0, 0.0, ['2026-10-22' => 900.0, '2026-10-23' => 300.0], 1300.0) === ['direct' => ['2026-10-22' => 1300.0], 'stock' => 1300.0],
    'week: the surplus of a batch covers the next truck');
check(WeekSplit::tasks(1300.0, 0.0, ['2026-10-22' => 900.0], null) === ['direct' => ['2026-10-22' => 900.0], 'stock' => 400.0],
    'week without batch: the ordered quantity to ship, the rest for stock');
check(WeekSplit::tasks(1300.0, 1300.0, ['2026-10-22' => 900.0], 1300.0) === ['direct' => [], 'stock' => 1300.0],
    'week: what is already on a day covers the nearest truck first');
$perBatch = fn(float $q) => $q / 1300 * 2.0; // 2 h na partię
check(WeekSplit::place(3900.0, ['d1', 'd2'], 1300.0, ['d1' => 5.0, 'd2' => 8.0], $perBatch) === ['d1' => 2600.0, 'd2' => 1300.0],
    'week: a full day takes whole batches (2 of 3 fit in 5 h), the next batch goes to the next day');
check(WeekSplit::place(3900.0, ['d1', 'd2'], 60.0, ['d1' => 5.0, 'd2' => 8.0], $perBatch) === ['d1' => 3240.0, 'd2' => 660.0],
    'week without batch: whole cartons');
check(WeekSplit::place(1300.0, ['d1', 'd2'], 1300.0, ['d1' => 1.0, 'd2' => 8.0], $perBatch) === ['d2' => 1300.0],
    'week: a batch is never cut — it goes whole to the first day with room');
// Prosta reguła magazynu : dostępne < stan min. → uzupełnienie do stanu maks. ; inaczej nic.
$r = DemandCalculator::refill(1200, 1800, 3600);
check($r['qty'] === 2400.0 && $r['reason'] === 'below_min', 'refill: below the minimum, back up to the maximum (croissant 1200 → 3600)');
check(DemandCalculator::refill(1800, 1800, 3600)['qty'] === 0.0 && DemandCalculator::refill(2500, 1800, 3600)['qty'] === 0.0, 'refill: nothing at or above the minimum');
check(DemandCalculator::refill(-500, 1800, 3600)['qty'] === 4100.0, 'refill: orders above stock (negative available) included');
check(DemandCalculator::refill(100, null, 300)['qty'] === 200.0 && DemandCalculator::refill(300, null, 300)['qty'] === 0.0, 'refill without a minimum: threshold = maximum');
check(DemandCalculator::roundBatch(2400, null, 240) / 240 === 10.0, 'refill 2400 with batches of 240 = 10 batches');
$cap = DemandCalculator::capFreezer([['key' => 'a', 'qty' => 600, 'ppc' => 60, 'multiple' => 60], ['key' => 'b', 'qty' => 240, 'ppc' => 24, 'multiple' => 24]], 1010, 1000);
check($cap['limited'] && ($cap['lines']['a'] / 60 + $cap['lines']['b'] / 24) <= 10, 'freezer: proposed cartons fit the free space');
check(DemandCalculator::capFreezer([['key' => 'a', 'qty' => 600, 'ppc' => 60, 'multiple' => null]], 0, 99999)['limited'] === false, 'freezer capacity not set: no limit');
check(PlanningService::convert(4800, 'g', 'kg') === 4.8 && PlanningService::convert(3, 'szt', 'kg') === 3.0, 'unit conversion');

// --- Ordonnancement (P4) : échéance camion, chambre de pousse, nuit, semi-finis, lots
$h = fn(string $t) => strtotime('2026-11-02 ' . $t);           // lundi
$day = [[$h('06:00'), $h('22:00')], [$h('06:00') + 86400, $h('22:00') + 86400]];
$night = [[$h('22:00') - 86400, $h('06:00')], [$h('22:00'), $h('06:00') + 86400]];
$mk = function (?int $earliest = null) use ($h, $day, $night): Scheduler {
    $sch = new Scheduler($earliest ?? $h('06:00'), 4 * 3600);
    $sch->addResource(1, $day, array_merge($day, $night), false, 1);        // façonneuse
    $sch->addResource(2, $day, array_merge($day, $night), false, 1);        // four
    $sch->addResource(3, array_merge($day, $night), array_merge($day, $night), true, 12); // chambre de pousse 12 unités
    $sch->addResource(4, $day, array_merge($day, $night), false, 1);        // robot autonome
    return $sch;
};
$step = fn(int $id, int $ws, int $fixed, float $var, float $cap, array $extra = []) => $extra + ['id' => $id, 'workstation_id' => $ws, 'fixed_time_s' => $fixed,
    'variable_time_s' => $var, 'capacity_per_pass' => $cap, 'operators_required' => 1, 'delay_after_s' => 0, 'delay_workstation_id' => null, 'cleaning_time_s' => 0, 'night_ok' => false, 'alternatives' => []];
$routing = [$step(1, 1, 600, 3, 100, ['delay_after_s' => 3600, 'delay_workstation_id' => 3]), $step(2, 2, 1200, 0, 100)];
$due = $h('19:00');
$sch = $mk();
$r = $sch->schedule([['id' => 1, 'due' => $due, 'qty' => 800, 'parent' => null, 'steps' => $routing], ['id' => 2, 'due' => $due, 'qty' => 800, 'parent' => null, 'steps' => $routing]]);
check($r['conflicts'] === [] && $r['operations'] !== [], 'two orders scheduled');
check(max(array_map(fn($o) => $o['end'], array_filter($r['operations'], fn($o) => $o['kind'] !== 'delay' || true))) <= $due, 'acceptance: nothing ends after the truck departure');
check(Scheduler::peakUnits($r['operations'], 3) <= 12, 'acceptance: proofing room capacity never exceeded (16 units wanted)');
$byOrderStep = [];
foreach ($r['operations'] as $o) if ($o['kind'] === 'work') $byOrderStep[$o['order_id']][$o['chunk']][$o['step_id']] = $o;
foreach ($byOrderStep as $chunks) foreach ($chunks as $c) check($c[1]['end'] + 3600 <= $c[2]['start'], 'proofing time respected between shaping and baking');
$overlap = false;
foreach ($r['operations'] as $a) foreach ($r['operations'] as $b) {
    if ($a !== $b && $a['kind'] !== 'delay' && $b['kind'] !== 'delay' && $a['workstation_id'] === $b['workstation_id'] && $a['start'] < $b['end'] && $b['start'] < $a['end']) $overlap = true;
}
check(!$overlap, 'one operation at a time per workstation');

// Nuit : interdite sans autorisation, même si le poste est libre la nuit
$sch = $mk($h('22:00'));
$r = $sch->schedule([['id' => 3, 'due' => $h('05:00') + 86400, 'qty' => 100, 'parent' => null, 'steps' => [$step(5, 1, 3600, 0, 100)]]]);
check($r['operations'] === [] && $r['conflicts'][0]['reason'] === 'NO_CAPACITY_BEFORE_DUE', 'acceptance: no night work without the night rule; not placed after the due time either');
$sch = $mk();
$r = $sch->schedule([['id' => 4, 'due' => $h('05:00') + 86400, 'qty' => 100, 'parent' => null, 'steps' => [$step(6, 4, 3600, 0, 100, ['night_ok' => true])]]]);
check($r['conflicts'] === [] && $r['operations'][0]['end'] <= $h('05:00') + 86400 && $r['operations'][0]['start'] >= $h('22:00'), 'autonomous robot runs at night, just in time');

// Semi-fini avant son parent ; gros OF en lots
$sch = $mk();
$r = $sch->schedule([
    ['id' => 10, 'due' => $due, 'qty' => 100, 'parent' => null, 'steps' => [$step(7, 2, 1800, 0, 100)]],
    ['id' => 11, 'due' => $due, 'qty' => 50, 'parent' => 10, 'steps' => [$step(8, 1, 1200, 0, 100)]],
]);
check($r['conflicts'] === [] && $r['starts'][11] < $r['starts'][10] && max(array_map(fn($o) => $o['order_id'] === 11 ? $o['end'] : 0, $r['operations'])) <= $r['starts'][10], 'semi-finished done before its parent starts');
$sch = $mk();
$r = $sch->schedule([['id' => 20, 'due' => $h('21:00') + 86400, 'qty' => 6000, 'parent' => null, 'steps' => [$step(9, 1, 300, 6, 200)]]]);
$works = array_filter($r['operations'], fn($o) => $o['kind'] === 'work');
check($r['conflicts'] === [] && count($works) >= 3 && max(array_map(fn($o) => $o['end'] - $o['start'], $works)) <= 4 * 3600, 'big order split in lots of at most 4 h');
check(array_sum(array_map(fn($o) => $o['qty'], $works)) == 6000, 'lots add up to the order');
check(Scheduler::merge([[1, 5], [5, 9], [12, 14]]) === [[1, 9], [12, 14]], 'contiguous shifts merge');

// --- Chaque route est documentée dans OpenAPI
$spec = OpenApiSpec::build('https://erp.test');
$documented = 0;
foreach ($spec['paths'] as $operations) $documented += count($operations);
check($documented === count(WorkshopApiRoutes::routes()), 'every route has an OpenAPI operation');
check($spec['servers'][0]['url'] === 'https://erp.test/api/v1/production', 'OpenAPI server uses API_BASE_URL');
$committed = __DIR__ . '/../docs/api/production-v1.openapi.json';
check(is_file($committed), 'docs/api/production-v1.openapi.json is committed');
$copy = json_decode((string) file_get_contents($committed), true);
check(array_keys($copy['paths']) === array_keys($spec['paths']), 'committed OpenAPI file is up to date (php Bin/tfb_openapi.php)');


// --- P6 : optymalizator zmian (95 %, zielony 90–100 %)
$cfg = ['target' => 95, 'green_min' => 90, 'green_max' => 100, 'max_overtime_h' => 2];
$levers = ['advance_mts', 'alternative_workstation', 'reassign_polyvalent', 'open_close_shift', 'overtime'];
$row = fn(string $key, int $group, int $people, float $required, int $maxOps = 1, string $date = '2026-10-21') => ['key' => $key, 'date' => $date, 'shift_id' => 1, 'group_id' => $group, 'shift_h' => 8.0, 'headcount' => $people, 'required_h' => $required, 'max_operators' => $maxOps];
// 2 os. × 8 h = 16 h, 10 h pracy (62,5 %) ; MTS z miejscem 400 szt., 0,02 h/szt., partia 50
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 10.0)], [['item_id' => 7, 'group_id' => 1, 'name' => 'Croissant', 'unit_h' => 0.02, 'room' => 400, 'batch_multiple' => 50, 'ppc' => 1]], $levers, $cfg);
check($out[0]['before']['status'] === 'under' && $out[0]['actions'][0]['lever'] === 'advance_mts' && $out[0]['actions'][0]['quantity'] == 250.0,
    'advance MTS: (95 % × 16 − 10) ÷ 0,02 = 260 → 250 (multiple of 50)');
check($out[0]['after']['status'] === 'ok' && abs($out[0]['after']['load_pct'] - 93.8) < 0.01, 'acceptance: the shift reaches the 90–100 % band');
// Pas de MTS : 2 os., 5 h → réduire à 1 (62,5 %) ; jamais sous les opérateurs d'une opération
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 7.5), $row('b', 2, 3, 5.0, 2)], [], $levers, $cfg);
check($out[0]['after']['headcount'] === 1 && $out[0]['after']['status'] === 'ok' && $out[0]['actions'][0]['action'] === 'reduce', 'reduce staffing to 1 person: 7.5 h of 8 h');
check($out[1]['after']['headcount'] === 2, 'never fewer people than one operation needs at once');
// Réaffectation : groupe 1 en surcharge (20 h pour 2 os.), groupe 2 a une personne de trop
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 20.0), $row('b', 2, 3, 15.0)], [], $levers, $cfg);
check($out[0]['after']['headcount'] === 3 && $out[1]['after']['headcount'] === 2 && $out[0]['actions'][0]['lever'] === 'reassign_polyvalent'
    && $out[0]['actions'][0]['from_group_id'] === 2, 'a spare person moves from group 2 to the overloaded group 1');
check($out[0]['after']['load_pct'] <= 100 && $out[1]['after']['load_pct'] <= 100, 'no group left over 100 %');
// Heures sup. : 17 h pour 2 os. → 1 h sup. (≤ 2 h / personne), pas une personne de plus
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 17.0)], [], $levers, $cfg);
check($out[0]['after']['headcount'] === 2 && $out[0]['after']['overtime_h'] == 1.0 && end($out[0]['actions'])['lever'] === 'overtime', 'small overload covered by 1 h overtime');
// Trop pour des heures sup. : 30 h pour 2 os. → 2 personnes de plus
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 30.0)], [], $levers, $cfg);
check($out[0]['after']['headcount'] === 4 && $out[0]['after']['status'] === 'ok', 'large overload: open 2 more places on the shift');
// 1 personne, mais une opération à 2 opérateurs : une personne de plus (les heures sup. ne suffisent pas)
$out = ShiftOptimizer::optimize([$row('a', 1, 1, 6.0, 2)], [['item_id' => 7, 'group_id' => 1, 'name' => 'C', 'unit_h' => 0.02, 'room' => 400, 'operators' => 2, 'ppc' => 1]], $levers, $cfg);
check($out[0]['after']['headcount'] === 2 && !array_filter($out[0]['actions'], fn($a) => $a['lever'] === 'advance_mts'), 'two people for a 2-operator operation; no MTS needing 2 people on a 1-person shift');
// Shift sans travail fermé ; sans le levier, rien ne bouge
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 0.0)], [], $levers, $cfg);
check($out[0]['after']['headcount'] === 0 && $out[0]['actions'][0]['action'] === 'close', 'shift without work is closed');
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 0.0)], [], ['overtime'], $cfg);
check($out[0]['after']['headcount'] === 2 && $out[0]['actions'] === [], 'disabled lever: nothing changes');
// Temps fixe par lancement (passages, nettoyage) compté une fois : 1 h fixe + 0,02 h/pièce
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 10.0)], [['item_id' => 7, 'group_id' => 1, 'name' => 'C', 'unit_h' => 0.02, 'fixed_h' => 1.0, 'room' => 400, 'batch_multiple' => 50, 'ppc' => 1]], ['advance_mts'], $cfg);
check($out[0]['actions'][0]['quantity'] == 200.0 && abs($out[0]['actions'][0]['hours'] - 5.0) < 1e-9, 'fixed time counted once: (15.2 − 10 − 1) ÷ 0.02 = 210 → 200, 1 + 4 h');
// Produit passant aussi par l'emballage (groupe 6) : heures ajoutées à l'emballage ; sans personne à l'emballage, pas d'avance
$croissant = [['item_id' => 7, 'group_id' => 1, 'name' => 'C', 'unit_h' => 0.02, 'room' => 400, 'batch_multiple' => 50, 'ppc' => 1],
    ['item_id' => 7, 'group_id' => 6, 'name' => 'C', 'unit_h' => 0.01, 'room' => 400, 'batch_multiple' => 50, 'ppc' => 1]];
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 10.0), $row('p', 6, 1, 2.0)], $croissant, ['advance_mts'], $cfg);
check($out[0]['actions'][0]['quantity'] == 250.0 && $out[1]['actions'][0]['follows_group_id'] === 1 && abs($out[1]['actions'][0]['hours'] - 2.5) < 1e-9,
    'packaging hours of the advanced MTS added to the packaging group (250 × 0.01)');
check($out[0]['after']['load_pct'] <= 100 && $out[1]['after']['required_h'] >= 4.5, 'the packaging group may advance more, never pushing production over 100 %');
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 10.0), $row('p', 6, 0, 0.0)], $croissant, ['advance_mts'], $cfg);
check($out[0]['actions'] === [], 'no MTS advanced when packaging has nobody on the shift');
// Le stock max est partagé entre les jours : la place MTS n'est pas comptée deux fois
$out = ShiftOptimizer::optimize([$row('a', 1, 2, 10.0, 1, '2026-10-21'), $row('b', 1, 2, 10.0, 1, '2026-10-22')], [['item_id' => 7, 'group_id' => 1, 'name' => 'C', 'unit_h' => 0.02, 'room' => 300, 'batch_multiple' => 50, 'ppc' => 1]], ['advance_mts'], $cfg);
check($out[0]['actions'][0]['quantity'] + ($out[1]['actions'][0]['quantity'] ?? 0) <= 300, 'MTS room (stock max) used once over the week');
$sum = ShiftOptimizer::summary($out);
check($sum['before']['load_pct'] == 62.5 && $sum['after']['shifts'] === 2, 'summary before / after');


// --- P7 : heures de nuit (22:00–06:00) des pointages
$night = fn(string $a, string $b) => TimesheetService::nightSeconds(strtotime($a), strtotime($b)) / 3600;
check($night('2026-10-20 21:00', '2026-10-21 07:00') == 8.0, 'night hours: 21:00 → 07:00 = 8 h');
check($night('2026-10-20 23:00', '2026-10-21 01:00') == 2.0 && $night('2026-10-21 05:00', '2026-10-21 07:00') == 1.0, 'night hours across midnight and at dawn');
check($night('2026-10-20 06:00', '2026-10-20 14:00') == 0.0 && $night('2026-10-20 14:00', '2026-10-20 22:30') == 0.5, 'day shift: no night hours; late shift: 30 min');

// --- Kierownik produkcji : realizacja, współczynnik (próg 90 %, maks. 120 %), premia sekcji, okresy
$att = fn(string $i, ?float $t, ?float $r) => ManagerIndicators::attainment($i, $t, $r);
check(abs($att('otif_pct', 95, 90.25) - 0.95) < 1e-9 && $att('otif_pct', 95, 200) == 1.2, 'higher is better: realized ÷ target, capped at 120 %');
check(abs($att('material_cost_pct', 30, 33.0) - 30 / 33) < 1e-9 && $att('material_cost_pct', 30, 20) == 1.2, 'lower is better: target ÷ realized, capped at 120 %');
check($att('stockouts', 0, 0) == 1.0 && $att('stockouts', 0, 2) == 0.0 && $att('overtime_hours', 10, 0) == 1.2, 'lower is better with zeros: 0 for 0 = 100 %, any for 0 = 0, zero hours = 120 %');
check(abs($att('price_gap_pct', 2, 3) - 102 / 103) < 1e-9 && abs($att('price_gap_pct', 2, -1) - 102 / 99) < 1e-9, 'price gap on the index 100 + gap (gap may be negative)');
check($att('otif_pct', null, 90) === null && $att('otif_pct', 95, null) === null, 'no target or no data: not calculated');
check(ManagerIndicators::coefficient(0.8999) == 0.0 && ManagerIndicators::coefficient(0.9) == 0.9 && ManagerIndicators::coefficient(1.0) == 1.0 && ManagerIndicators::coefficient(1.5) == 1.2,
    'coefficient: under 90 % → 0 (no rounding up), then proportional, capped at 120 %');
check(ManagerIndicators::status(0.8999) === 'red' && ManagerIndicators::status(0.95) === 'orange' && ManagerIndicators::status(1.0) === 'green', 'status: red < 90 %, orange 90–100 %, green ≥ 100 %');
$b = ManagerIndicators::sectionBonus(24000, 'production', 1.0, 0.95);
check(abs($b['share'] - 1200) < 1e-9 && abs($b['amount'] - 1140) < 1e-9, 'section bonus: 24 000 × 20 % × 3/12 × 1 × 0.95 = 1 140');
$b = ManagerIndicators::sectionBonus(24000, 'development', 0.5, 1.2);
check(abs($b['share'] - 1800) < 1e-9 && abs($b['amount'] - 2160) < 1e-9, 'semester section, half the period present: 24 000 × 30 % × 6/12 × 0.5 = 1 800, × 1.2');
check(ManagerIndicators::sectionPeriod('development', '2026-Q3') === null && ManagerIndicators::sectionPeriod('development', '2026-Q4')['from'] === '2026-07-01'
    && ManagerIndicators::sectionPeriod('logistics', '2026-Q1') === ['key' => '2026-Q1', 'from' => '2026-01-01', 'to' => '2026-03-31'], 'periods: quarter, semester only in Q2 and Q4');
check(array_sum(ManagerIndicators::DEFAULT_MISSION_HOURS) == ManagerIndicators::DEFAULT_MONTHLY_HOURS && array_keys(ManagerIndicators::DEFAULT_MISSION_HOURS) === ManagerIndicators::MISSIONS,
    'default hours: every mission, 168 h a month in total');
check(array_sum(array_column(ManagerIndicators::SECTIONS, 'weight')) === 100, 'section weights add up to 100 %');

// --- Faktury zakupowe : XML KSeF (FA), tekst PDF, liczby, jednostki, przeliczniki, podobieństwo nazw
$fa = '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Podmiot1><DaneIdentyfikacyjne><NIP>5260250274</NIP><Nazwa>Młyn</Nazwa></DaneIdentyfikacyjne></Podmiot1>'
    . '<Fa><KodWaluty>PLN</KodWaluty><P_1>2026-10-20</P_1><P_2>FV/1</P_2><P_13_3>220.00</P_13_3><P_15>231.00</P_15>'
    . '<FaWiersz><NrWierszaFa>1</NrWierszaFa><P_7>MĄKA T-65 25KG</P_7><P_8A>szt</P_8A><P_8B>2</P_8B><P_9A>110</P_9A><P_11>220</P_11><P_12>5</P_12></FaWiersz>'
    . '<FaWiersz><NrWierszaFa>2</NrWierszaFa><P_7>Drożdże</P_7><P_8A>kg</P_8A><P_8B>1.5</P_8B><P_9B>12.6</P_9B><P_11A>18.9</P_11A><P_12>5</P_12></FaWiersz></Fa></Faktura>';
$x = InvoiceParser::ksefXml($fa);
check($x['number'] === 'FV/1' && $x['issue_date'] === '2026-10-20' && $x['seller_nip'] === '5260250274' && $x['total_net'] == 220 && $x['total_gross'] == 231
    && count($x['lines']) === 2 && $x['lines'][0]['quantity'] == 2 && $x['lines'][0]['unit_price'] == 110, 'KSeF FA XML: header, totals and lines');
check(abs($x['lines'][1]['net_value'] - 18.0) < 0.01 && abs($x['lines'][1]['unit_price'] - 12.0) < 0.01, 'KSeF line with gross prices only: net derived from the VAT rate');
$bad = false;
try { InvoiceParser::ksefXml('<html/>'); } catch (InvalidArgumentException) { $bad = true; }
check($bad, 'not a KSeF invoice → refused');
$txt = "FAKTURA VAT nr FV/77/10/2026\nSprzedawca:   Pakcer Sp. j.\nNIP: 123-456-32-18\nNIP: 897-190-26-20\nData wystawienia: 21.10.2026\n"
    . "1    Karton 600x400        300    szt     3,50     1 050,00    23%\n2    Taśma klejąca           12    rol     4,10        49,20    23%\n3    Opis bez liczb\n"
    . "                         Razem:       1 099,20\nDo zapłaty: 1 352,02 zł\n";
$t = InvoiceParser::pdfText($txt, 'PL 897-190-26-20');
check($t['number'] === 'FV/77/10/2026' && $t['issue_date'] === '2026-10-21' && $t['seller_nip'] === '1234563218' && $t['seller_name'] === 'Pakcer Sp. j.' && $t['total_gross'] == 1352.02
    && count($t['lines']) === 2 && $t['lines'][0]['name'] === 'Karton 600x400' && $t['lines'][0]['net_value'] == 1050 && $t['lines'][1]['unit'] === 'rolka' && $t['lines'][1]['vat_rate'] === '23',
    'PDF text: number, date, seller (not our NIP), lines where quantity × price = value, totals line skipped');
// OCR (skan, zdjęcie) : kreski tabeli, Lp, « 12. », « SZ », O/l w liczbach, waluta przy kwocie.
$ocr = InvoiceParser::ocrText("FAKTURA VAT nr FV/2210/10/2026\nSprzedawca:   Nabywca:\nHurtownia Odra Sp. z o.o.   ATELIER Sp. z o.o.\nNIP: 894-301-22-17   NIP: 897-190-26-20\nData wystawienia: 22.1O.2026\n"
    . "Lp   |   Nazwa towaru   |   Ilość   |   J.m.   |   Cena netto\nI   Mąka pszenna typ 650 worek 25 kg   |   12. szt   62,40   748,80   5%\nb   Cukier biały 50 kg   |   4 | SZ   189,00   |   756,00   5%\n"
    . "[4   | Drożdże świeże 1 kg   |   10 | kg   |   8,9O zł |   89,00 zł | 5%   I!\nDo zapłaty: 1 676,58 zł");
$o = InvoiceParser::pdfText($ocr, '8971902620');
check($o['number'] === 'FV/2210/10/2026' && $o['issue_date'] === '2026-10-22' && $o['seller_nip'] === '8943012217' && count($o['lines']) === 3
    && $o['lines'][0]['name'] === 'Mąka pszenna typ 650 worek 25 kg' && $o['lines'][0]['quantity'] == 12 && $o['lines'][1]['unit'] === 'szt' && $o['lines'][1]['net_value'] == 756
    && $o['lines'][2]['name'] === 'Drożdże świeże 1 kg' && $o['lines'][2]['unit_price'] == 8.9 && $o['total_gross'] == 1676.58,
    'OCR text: table bars, row numbers misread (I, b, [4), « 12. », « SZ », 8,9O zł → 3 lines like a PDF');
check(!InvoiceParser::hasText("Powered by TCPDF (www.tcpdf.org)\n\f") && InvoiceParser::hasText($ocr), 'PDF without text layer (scan) recognised');
check(InvoiceParser::number('1 234,56') === 1234.56 && InvoiceParser::number('1.234,56') === 1234.56 && InvoiceParser::number('12.5') === 12.5 && InvoiceParser::number('10 45,00') === null,
    'Polish and dotted number formats; ambiguous groups refused');
check(InvoiceParser::defaultFactor('kg', 'g', null) == 1000 && InvoiceParser::defaultFactor('worek', 'g', 25000) == 25000 && InvoiceParser::defaultFactor('szt', 'szt', 100) == 1
    && InvoiceParser::defaultFactor('l', 'ml', null) == 1000, 'default factor: kg → g, bag → package size, same unit → 1');
check(InvoiceParser::defaultFactor('l', 'g', null) == 1000 && InvoiceParser::defaultFactor('kg', 'ml', null) == 1000 && InvoiceParser::defaultFactor('ml', 'g', null) == 1, 'default factor: volume ↔ mass with density 1');
check(InvoiceParser::nameKey('  MĄKA  pszenna, T-65 ') === 'mąka pszenna t 65' && InvoiceParser::unitKey('Szt.') === 'szt' && InvoiceParser::unitKey('opak.') === 'op', 'name and unit keys for supplier mappings');
check(InvoiceParser::similarity('MĄKA PSZENNA T-65 WOREK 25KG', 'Mąka T65') > InvoiceParser::similarity('MĄKA PSZENNA T-65 WOREK 25KG', 'Masło'), 'name similarity ranks the right material first');
check(InvoiceParser::packSize('MAKA PSZENNA T65 WOREK 25KG') === [25000.0, 'g'] && InvoiceParser::packSize('DROZDZE 0,5KG') === [500.0, 'g'] && InvoiceParser::packSize('Cukier 10x1kg') === [10000.0, 'g']
    && InvoiceParser::packSize('Śmietana 36% 1L') === [1000.0, 'ml'] && InvoiceParser::packSize('MASLO EXTRA 82% BLOK') === null && InvoiceParser::packSize('Folia 450') === null,
    'pack size read in the invoice line name (25KG, 0,5KG, 10x1kg, 1L ; 82 % or 450 are not weights)');
check(InvoiceParser::defaultFactor('szt', 'g', 1000, 'MAKA WOREK 25KG') == 25000 && InvoiceParser::defaultFactor('szt', 'ml', null, 'Śmietana 1L') == 1000
    && InvoiceParser::defaultFactor('kg', 'g', null, 'MASLO 10KG') == 1000 && InvoiceParser::defaultFactor('szt', 'szt', null, 'Jajka 30 szt') == 1,
    'default factor: weight in the name wins over the material package (piece = 25 kg bag), invoice in kg stays kg → g');
check(InvoiceParser::similarity('MASLO EXTRA 82% BLOK', 'Masło') === 1.0 && InvoiceParser::similarity('DROZDZE PIEKARSKIE', 'Drożdże') === 1.0, 'names compared without Polish accents (invoices often without them)');
check(InvoiceParser::similarity('Karton klapowy 590x390x210', 'Karton 590 x 390 x 210') === 1.0 && InvoiceParser::similarity('Karton klapowy 590x390x210', 'karton 590x290x340') === 0.5,
    'dimensions: « 590 x 390 x 210 » = « 590x390x210 », other sizes do not match');

// --- Uprawnienia stanowisk : strona = najdłuższy pasujący prefiks katalogu, migracja przyznaje wszystkie strony poza administratorem
check(PageAccess::keyFor('/settings/ksef/purchase-invoices') === 'ksef_purchases' && PageAccess::keyFor('/settings/ksef') === 'settings'
    && PageAccess::keyFor('/communication/email-settings') === 'settings' && PageAccess::keyFor('/communication/templates') === 'communication', 'access: longest prefix wins (KSeF invoices, e-mail settings)');
check(PageAccess::keyFor('/purchasing/nc') === 'production' && PageAccess::keyFor('/purchasing/offers?x=1') === 'purchasing' && PageAccess::keyFor('/finances/sales-analytics') === 'sales'
    && PageAccess::keyFor('/finances/product-margin') === 'margins', 'access: nested prefixes and query strings');
check(PageAccess::keyFor('/production-employees/3/edit') === 'employees' && PageAccess::keyFor('/production/follow-up') === 'production' && PageAccess::keyFor('/products/12/edit') === 'products'
    && PageAccess::keyFor('/workshop/products') === 'product_cards', 'access: /production vs /production-employees, /products vs /workshop/products');
check(PageAccess::keyFor('/today') === null && PageAccess::keyFor('/my-tasks/4/form') === null && PageAccess::keyFor('/kiosk') === null && PageAccess::keyFor('/') === null, 'access: shared pages outside the catalogue');
check(PageAccess::keyFor('/workshop/manager') === 'manager' && PageAccess::adminOnly('manager') && PageAccess::keyFor('/organization/access') === 'access' && PageAccess::adminOnly('access')
    && PageAccess::keyFor('/organization/positions') === 'positions' && !in_array('manager', PageAccess::grantable(), true), 'access: manager module and access page admin only');
$sql = file_get_contents(__DIR__ . '/../migrations/tfb/050_page_access.sql');
preg_match_all("/'([a-z_]+)'/", substr($sql, strpos($sql, 'CROSS JOIN ('), strpos($sql, ') k') - strpos($sql, 'CROSS JOIN (')), $m);
// Pages ajoutées plus tard : la migration recopie les droits d'une page existante (059 : supplier_prices depuis materials,
// 063 : reminders et client_volume pour la section Finanse).
$laterSql = file_get_contents(__DIR__ . '/../migrations/tfb/059_navigation.sql') . file_get_contents(__DIR__ . '/../migrations/tfb/063_finance.sql');
preg_match_all("/SELECT id_position, '([a-z_]+)'/", $laterSql, $later);
// 146 : « Kto co zrobił » (execution) dla przełożonych.
preg_match_all("/INSERT IGNORE INTO n_page_access \(id_position, page_key\)\s*SELECT [^']*'([a-z_]+)'/", file_get_contents(__DIR__ . '/../migrations/tfb/146_task_wizards_cron.sql'), $m146);
// 149 [230] : przyjęcia, płatności dnia, ocena dostawców — z uprawnień stron macierzystych.
preg_match_all("/INSERT IGNORE INTO n_page_access \(id_position, page_key\)\s*SELECT [^']*'([a-z_]+)'/", file_get_contents(__DIR__ . '/../migrations/tfb/149_lot4_wizards.sql'), $m149);
// 152 [234] : Moce produkcyjne — pełna wersja (dyrektor zakładu).
preg_match_all("/INSERT IGNORE INTO n_page_access \(id_position, page_key\)\s*SELECT [^']*'([a-z_]+)'/", file_get_contents(__DIR__ . '/../migrations/tfb/152_capacity_full_access.sql'), $m152);
$seeded = array_values(array_unique(array_merge($m[1], $later[1], $m146[1], $m149[1], $m152[1])));
sort($seeded);
$grantable = PageAccess::grantable();
sort($grantable);
check($seeded === $grantable, 'access: migrations 050 + 059 + 063 + 146 + 149 + 152 seed exactly the grantable pages (' . implode(',', array_diff($grantable, $seeded)) . ' | ' . implode(',', array_diff($seeded, $grantable)) . ')');

// --- Aktywność w ERP : trasa (identyfikatory → {id}), ścieżka bez zapytania, okres uporządkowany i ograniczony
check(ActivityService::route('/products/12/edit') === '/products/{id}/edit' && ActivityService::route('/workshop/gantt') === '/workshop/gantt'
    && ActivityService::route('/archive/2026-10-01') === '/archive/{id}', 'activity: ids and dates in the path become {id}');
check(ActivityService::path('/today?x=1#y') === '/today' && ActivityService::path('https://x.test/clients/5/') === '/clients/5' && ActivityService::path('javascript:alert(1)') === null
    && ActivityService::path('') === null, 'activity: path without query, hash or trailing slash; not a path → null');
check(ActivityService::period('2026-10-31', '2026-10-01') === ['2026-10-01', '2026-10-31'] && ActivityService::period('2020-01-01', '2026-10-22')[0] === '2025-10-22',
    'activity: period ordered and limited to 365 days');
check(PageAccess::keyFor('/organization/activity') === 'activity' && PageAccess::adminOnly('activity'), 'activity report is admin only');
check(PageAccess::keyFor('/settings/panel-api') === 'panel_api' && PageAccess::adminOnly('panel_api') && PageAccess::keyFor('/settings/ksef') === 'settings'
    && PageAccess::keyFor('/finances/category-margin') === 'margins', 'panel API account (store prices) is admin only, margins stay grantable');

echo "tfb_unit_test: OK\n";
