<?php
declare(strict_types=1);
/*
 * Module atelier — test d'intégration de bout en bout sur une base jetable :
 * migrations (application, rejeu, empreinte modifiée), contrôles en base, connexion JWT,
 * rotation des jetons, limitation des essais, droits, référentiels, messages PL/FR.
 *
 *   php tests/tfb_api_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root),
 * TFB_TEST_PASS (vide). Les tables existantes de l'ERP utilisées par le module sont recopiées
 * (structure seule) depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_tfb_test est recréée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Services\Workshop\Migration\MigrationRunner;
use Symfony\Component\HttpFoundation\Request;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_tfb_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "tfb_api_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}

// --- Base jetable avec la structure des tables ERP dont dépend le module
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'customer_order', 'communication_template'] as $table) {
    $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
}
$pdo->exec("USE {$testDb}");
$pdo->exec("INSERT INTO user (id, name, surname, displayed_name, email, phone, permission, login, password) VALUES
    (1, 'Anna', 'K', 'Anna K.', 'a@x.test', '1', 0, 'manager', '" . password_hash('Secret-123', PASSWORD_BCRYPT) . "'),
    (2, 'Bob', 'L', 'Bob L.', 'b@x.test', '2', 0, 'legacy', 'plain-pass'),
    (3, 'Cleo', 'M', 'Cleo M.', 'c@x.test', '3', 0, 'norole', 'pass-norole'),
    (4, 'Dan', 'N', 'Dan N.', 'd@x.test', '4', 0, 'direction', 'pass-direction')");
$pdo->exec("INSERT INTO production_sections (id, name, depreciation, water_cost, power_supply_cost, fuel_cost, other_cost) VALUES (1, 'Piekarnia', 0, 0, 0, 0, 0)");
$pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (10, 'Jan', 'Kowalski', 5000, 1), (11, 'Ewa', 'Nowak', 5200, 1)");

// --- Migrations : application, rejeu sans effet, fichier modifié bloqué
$runner = new MigrationRunner($pdo, __DIR__ . '/../migrations/tfb');
$applied = $runner->apply();
check(count($applied) === count(glob(__DIR__ . '/../migrations/tfb/*.sql')), 'all migrations apply on an empty schema');
check($runner->apply() === [], 'a second run applies nothing');
$tmp = sys_get_temp_dir() . '/tfb_migrations_' . bin2hex(random_bytes(4));
mkdir($tmp);
foreach (glob(__DIR__ . '/../migrations/tfb/*.sql') as $file) copy($file, $tmp . '/' . basename($file));
file_put_contents($tmp . '/001_core.sql', "\n-- edited", FILE_APPEND);
$drift = false;
try { (new MigrationRunner($pdo, $tmp))->apply(); } catch (RuntimeException $e) { $drift = str_contains($e->getMessage(), '001_core.sql (drift)'); }
array_map('unlink', glob($tmp . '/*.sql'));
rmdir($tmp);
check($drift, 'an applied migration edited afterwards blocks the run');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_group")->fetchColumn() === 6, '6 default groups');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_shift_template")->fetchColumn() === 3, '3 default shift templates');

// --- Contrôles en base (tests d'acceptation 1 : jamais congelé ⇒ jamais MTS)
$pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, ppb, bpc, cpl, lpp, cpp, id_section, active) VALUES (500, 'Baguette test', 1, 350, 18, 1, 18, 8, 6, 48, 1, 1)");
$pdo->exec("INSERT INTO tfb_prod_item (id, item_type, id_final_product) VALUES (1, 'product', 500)");
$refused = function (string $sql) use ($pdo): bool {
    try { $pdo->exec($sql); return false; } catch (PDOException) { return true; }
};
check($refused("INSERT INTO tfb_prod_product_settings (id_item, mode, never_frozen) VALUES (1, 'MTS', 1)"), 'DB refuses never_frozen + MTS');
$pdo->exec("INSERT INTO tfb_prod_product_settings (id_item, mode, never_frozen) VALUES (1, 'MTO', 1)");
check($refused("UPDATE tfb_prod_product_settings SET mode = 'MTS' WHERE id_item = 1"), 'DB refuses switching a never_frozen product to MTS');
check($refused("UPDATE tfb_prod_product_settings SET stock_min = 10, stock_max = 5 WHERE id_item = 1"), 'DB refuses stock_max < stock_min');
check($refused("INSERT INTO tfb_prod_workstation (code, id_group, name_pl, name_fr, type) VALUES ('R', 1, 'r', 'r', 'robot')"), 'DB refuses a robot without mode');
check($refused("INSERT INTO tfb_prod_workstation (code, id_group, name_pl, name_fr, type, robot_mode) VALUES ('M', 1, 'm', 'm', 'four', 'autonome')"), 'DB refuses a robot mode on an oven');

// --- Rôles
$pdo->exec("INSERT INTO tfb_prod_user_role (id_user, role_code) VALUES (1, 'prod_manager'), (2, 'team_lead'), (4, 'direction')");
$pdo->exec("UPDATE tfb_prod_param SET value = '3' WHERE code = 'auth.max_failed_logins'");

// --- Noyau de l'API, branché sur la base de test
$builder = new DI\ContainerBuilder();
$builder->addDefinitions([
    PDO::class => $pdo,
    WorkshopConfig::class => new WorkshopConfig(['PRODUCTION_JWT_SECRET' => str_repeat('s', 48), 'API_BASE_URL' => 'https://erp.test', 'R2_PUBLIC_BASE_URL' => 'https://photos.r2.test']),
]);
$kernel = $builder->build()->get(WorkshopApiKernel::class);

$call = function (string $method, string $path, ?array $body = null, ?string $token = null, string $lang = 'pl', string $ip = '10.0.0.1') use ($kernel): array {
    $server = ['HTTP_ACCEPT_LANGUAGE' => $lang, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip];
    if ($token !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
    $response = $kernel->handle($request, parse_url('/api/v1/production' . $path, PHP_URL_PATH));
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
};
$codes = fn(array $body) => array_map(fn($e) => $e['field'] . ':' . $e['code'], $body['errors'] ?? []);

// Authentification
[$s, $b] = $call('GET', '/referentiels/groups');
check($s === 401 && $b['description'] === 'UNAUTHENTICATED', 'no token → 401');
[$s, $b] = $call('POST', '/auth/login', ['login' => 'manager', 'password' => 'bad'], null, 'fr');
check($s === 401 && $b['message'] === 'Identifiant ou mot de passe incorrect.', 'wrong password → 401 in French');
[$s, $b] = $call('POST', '/auth/login', ['login' => 'norole', 'password' => 'pass-norole']);
check($s === 403 && $b['description'] === 'NO_PRODUCTION_ROLE', 'account without production role → 403');
[$s, $b] = $call('POST', '/auth/login', ['login' => 'legacy', 'password' => 'plain-pass']);
check($s === 200, 'legacy plain-text ERP password still works');
[$s, $login] = $call('POST', '/auth/login', ['login' => 'manager', 'password' => 'Secret-123']);
check($s === 200 && $login['data']['token_type'] === 'Bearer' && !isset($login['data']['_refresh_id']), 'login → tokens');
$access = $login['data']['access_token'];
[$s, $b] = $call('GET', '/auth/me', null, $access);
check($s === 200 && in_array('prod.referentiels.write', $b['data']['permissions'], true), '/auth/me lists permissions');

// Rotation du jeton de renouvellement et détection de réutilisation
$refresh1 = $login['data']['refresh_token'];
[$s, $r2] = $call('POST', '/auth/refresh', ['refresh_token' => $refresh1]);
check($s === 200 && $r2['data']['refresh_token'] !== $refresh1, 'refresh rotates the token');
[$s, $b] = $call('POST', '/auth/refresh', ['refresh_token' => $refresh1]);
check($s === 401 && $b['description'] === 'REFRESH_INVALID', 'a used refresh token is refused');
[$s, $b] = $call('POST', '/auth/refresh', ['refresh_token' => $r2['data']['refresh_token']]);
check($s === 401, 'reuse of an old token revokes the whole family');

// Limitation des essais (paramètre ramené à 3 pour le test)
for ($i = 0; $i < 3; $i++) $call('POST', '/auth/login', ['login' => 'direction', 'password' => 'x'], null, 'pl', '10.9.9.9');
[$s, $b] = $call('POST', '/auth/login', ['login' => 'direction', 'password' => 'pass-direction'], null, 'pl', '10.9.9.9');
check($s === 429 && $b['description'] === 'TOO_MANY_ATTEMPTS', 'too many failures → 429, even with the right password');
[$s] = $call('POST', '/auth/login', ['login' => 'direction', 'password' => 'pass-direction'], null, 'pl', '10.0.0.2');
check($s === 429, 'the account stays locked from another address (no distributed guessing)');
$pdo->exec("UPDATE tfb_prod_login_attempt SET created_at = NOW() - INTERVAL 16 MINUTE");
[$s, $direction] = $call('POST', '/auth/login', ['login' => 'direction', 'password' => 'pass-direction'], null, 'pl', '10.0.0.2');
check($s === 200, 'login works again once the window has passed');

// Droits : la direction lit mais n'écrit pas
[$s] = $call('GET', '/referentiels/groups', null, $direction['data']['access_token']);
check($s === 200, 'direction can read');
[$s, $b] = $call('POST', '/referentiels/groups', ['code' => 'X1', 'name_pl' => 'x', 'name_fr' => 'x'], $direction['data']['access_token']);
check($s === 403 && $b['description'] === 'FORBIDDEN', 'direction cannot write');

// Groupes
[$s, $b] = $call('POST', '/referentiels/groups', ['code' => 'test_grp', 'name_pl' => 'Grupa testowa', 'name_fr' => 'Groupe test', 'section_id' => 1], $access);
check($s === 201 && $b['data']['code'] === 'TEST_GRP' && $b['data']['section_id'] === 1, 'create group (code upper-cased)');
$groupId = $b['data']['id'];
[$s, $b] = $call('POST', '/referentiels/groups', ['code' => 'TEST_GRP', 'name_pl' => 'a', 'name_fr' => 'b'], $access, 'fr');
check($s === 409 && $b['message'] === 'Le code TEST_GRP existe déjà.', 'duplicate code → 409');
[$s, $b] = $call('POST', '/referentiels/groups', ['code' => 'G2', 'name_pl' => 'a', 'name_fr' => 'b', 'section_id' => 999, 'colour' => '#fff'], $access);
check($s === 422 && $codes($b) === ['colour:UNKNOWN_FIELD', 'section_id:REFERENCE_NOT_FOUND'], 'unknown field and unknown section');
[$s, $b] = $call('PATCH', "/referentiels/groups/{$groupId}", ['active' => false], $access, 'fr');
check($s === 200 && $b['data']['active'] === false && $b['data']['name'] === 'Groupe test', 'partial update, name in French');
[$s, $b] = $call('GET', '/referentiels/groups/9999', null, $access, 'fr');
check($s === 404 && $b['message'] === 'Introuvable : groupe 9999.', '404 names the entity in French');

// Postes
[$s, $b] = $call('POST', '/referentiels/workstations', ['code' => 'ROBOT_1', 'group_id' => 1, 'name_pl' => 'Robot', 'name_fr' => 'Robot', 'type' => 'robot', 'capacity_per_pass' => 0], $access);
check($s === 422 && $codes($b) === ['capacity_per_pass:GREATER_THAN', 'robot_mode:ROBOT_MODE_REQUIRED'], 'robot needs a mode and a capacity > 0');
check(str_contains($b['errors'][1]['message'], 'tryb robota'), 'Polish field label in the message');
[$s, $b] = $call('POST', '/referentiels/workstations', ['code' => 'ROBOT_1', 'group_id' => 1, 'name_pl' => 'Robot', 'name_fr' => 'Robot', 'type' => 'robot',
    'robot_mode' => 'autonome', 'capacity_per_pass' => 12, 'capacity_unit' => 'plaque', 'photo_url' => 'https://photos.r2.test/ws/robot1.jpg',
    'shift_availability' => [['shift_template_id' => 1, 'available' => false]]], $access);
check($s === 201 && $b['data']['night_compatible'] === true && $b['data']['shift_availability'][0]['available'] === false, 'create autonomous robot');
$wsId = $b['data']['id'];
[$s, $b] = $call('PATCH', "/referentiels/workstations/{$wsId}", ['photo_url' => 'https://elsewhere.test/a.jpg'], $access);
check($s === 422 && $codes($b) === ['photo_url:URL_NOT_R2'], 'photo must be a direct R2 link');
[$s, $b] = $call('PATCH', "/referentiels/workstations/{$wsId}", ['type' => 'four'], $access);
check($s === 200 && $b['data']['robot_mode'] === null && $b['data']['night_compatible'] === false, 'robot → oven clears the robot mode');
[$s, $b] = $call('PATCH', "/referentiels/workstations/{$wsId}", ['robot_mode' => 'autonome'], $access);
check($s === 422 && $codes($b) === ['robot_mode:ROBOT_MODE_FORBIDDEN'], 'robot mode refused on an oven');
[$s, $b] = $call('GET', '/referentiels/workstations?type=lazer', null, $access);
check($s === 422 && $codes($b) === ['type:INVALID_CHOICE'], 'bad filter is reported');

// Shifts
[$s, $b] = $call('POST', '/referentiels/shift-templates', ['code' => 'N2', 'name_pl' => 'Noc 2', 'name_fr' => 'Nuit 2', 'start_time' => '23:00', 'end_time' => '23:00'], $access);
check($s === 422 && $codes($b) === ['end_time:SAME_START_END'], 'start = end refused');
[$s, $b] = $call('GET', '/referentiels/shift-templates', null, $access);
$night = array_values(array_filter($b['data'], fn($t) => $t['code'] === 'NUIT'))[0];
check($night['crosses_midnight'] === true && $night['duration_minutes'] === 480 && $night['premium_pct'] === 20.0, 'default night shift 22–06, +20 %');

// Équipes et membres
[$s, $b] = $call('POST', '/referentiels/teams', ['code' => 'T1', 'group_id' => 1, 'shift_template_id' => 1, 'name' => 'Équipe 1', 'planned_headcount' => 2], $access);
check($s === 201, 'create team');
$teamId = $b['data']['id'];
[$s, $b] = $call('PUT', "/referentiels/teams/{$teamId}/members", ['members' => [['production_employee_id' => 10, 'is_lead' => true], ['production_employee_id' => 10], ['production_employee_id' => 77]]], $access);
check($s === 422 && $codes($b) === ['members[1].production_employee_id:DUPLICATE_IN_LIST', 'members[2].production_employee_id:REFERENCE_NOT_FOUND'], 'member list is checked');
[$s, $b] = $call('PUT', "/referentiels/teams/{$teamId}/members", ['members' => [['production_employee_id' => 10, 'is_lead' => true], ['production_employee_id' => 11, 'valid_from' => '2027-04-01']]], $access);
check($s === 200 && count($b['data']) === 2 && $b['data'][0]['is_lead'] === true, 'replace members');
[$s, $b] = $call('GET', "/referentiels/teams/{$teamId}", null, $access);
check($b['data']['member_count'] === 2, 'member count');

// Employés : profil, sans jamais exposer le salaire
[$s, $b] = $call('PATCH', '/referentiels/employees/10', ['panel_employee_id' => 501, 'level_order' => 3, 'night_supervisor' => true], $access);
check($s === 200 && $b['data']['panel_employee_id'] === 501 && $b['data']['level_order'] === 3 && !array_key_exists('cost', $b['data']), 'employee profile, no salary');
[$s, $b] = $call('PATCH', '/referentiels/employees/11', ['panel_employee_id' => 501], $access);
check($s === 422, 'a panel employee is linked once');

// Paramètres
[$s, $b] = $call('PATCH', '/referentiels/params/load.target_pct', ['value' => 250], $access);
check($s === 422 && $codes($b) === ['value:OUT_OF_RANGE'], 'percentage above 200 refused');
[$s, $b] = $call('PATCH', '/referentiels/params/load.target_pct', ['value' => 94.5], $access);
check($s === 200 && $b['data']['value'] === 94.5 && $b['data']['is_default'] === false, 'parameter updated');
[$s, $b] = $call('PATCH', '/referentiels/params/workstation.capacity_units', ['value' => ['plaque', 'Bad Unit']], $access);
check($s === 422, 'capacity unit list is checked');

// Journal, en-têtes, JSON invalide, OpenAPI public
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_audit_log WHERE id_user = 1")->fetchColumn() >= 8, 'every write is audited');
[$s, $b] = $call('POST', '/referentiels/groups', null, $access);
$request = Request::create('/api/v1/production/referentiels/groups', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $access, 'CONTENT_TYPE' => 'application/json'], '{bad');
$response = $kernel->handle($request, '/api/v1/production/referentiels/groups');
check($response->getStatusCode() === 400 && $response->headers->has('X-Request-Id'), 'invalid JSON → 400 with a request id');
$request = Request::create('/api/v1/production/referentiels/groups', 'GET', [], [], [], ['HTTP_X_ACCESS_TOKEN' => $access]);
check($kernel->handle($request, '/api/v1/production/referentiels/groups')->getStatusCode() === 200, 'X-Access-Token works behind Basic Auth');
[$s, $b] = $call('GET', '/openapi.json');
check($s === 200 && $b['openapi'] === '3.0.3', 'OpenAPI is public');

$pdo->exec("DROP DATABASE {$testDb}");
echo "tfb_api_test: OK\n";
