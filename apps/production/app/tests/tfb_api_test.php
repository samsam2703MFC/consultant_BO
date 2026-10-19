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
foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit', 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency', 'customer', 'carrier_vehicle'] as $table) {
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

// --- P2 : fiche produit, gamme, temps standards, chronométrage
$pdo->exec("INSERT INTO unit (id, name) VALUES (1, 'g') ON DUPLICATE KEY UPDATE name = name");
$pdo->exec("SET SESSION sql_mode = REPLACE(@@sql_mode, 'STRICT_TRANS_TABLES', '')");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, price_per_unit) VALUES (900, 'Mąka T65', 1, 0.004), (901, 'Masło', 1, 0.03)");
$pdo->exec("INSERT INTO product_recipe (id, name, id_type, is_subrecipe, id_unit) VALUES (700, 'Croissant — receptura', 3, 0, 1), (701, 'Ciasto francuskie', 2, 1, 1)");
$pdo->exec("INSERT INTO product_recipe_connection (parent_recipe_id, child_recipe_id, child_ingredient_id, quantity) VALUES (700, 701, NULL, 2000), (700, NULL, 901, 300)");
$pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, ppb, bpc, cpl, lpp, cpp, id_section, id_recipe, active) VALUES (501, 'Croissant test', 1, 70, 60, 1, 60, 8, 6, 48, 1, 700, 1)");
$pdo->exec("INSERT INTO tfb_prod_workstation (id, code, id_group, name_pl, name_fr, type, capacity_per_pass) VALUES
    (90, 'STOL_P2', 1, 'Stół', 'Table', 'manuel', 200), (91, 'PIEC_P2', 1, 'Piec', 'Four', 'four', 60),
    (92, 'PIEC_P2B', 1, 'Piec 2', 'Four 2', 'four', 40), (93, 'KOMORA_P2', 1, 'Komora', 'Chambre de pousse', 'fermentation', 400)");
[$s, $teamLead] = $call('POST', '/auth/login', ['login' => 'legacy', 'password' => 'plain-pass']);
$lead = $teamLead['data']['access_token'];
$dir = $direction['data']['access_token'];

[$s, $b] = $call('GET', '/produits/candidates?item_type=product&search=test', null, $access);
check($s === 200 && array_column($b['data'], 'id') === [501], 'candidates: ERP products without a sheet (500 already has one)');
[$s, $b] = $call('POST', '/produits/items', ['item_type' => 'product', 'recipe_id' => 701], $access);
check($s === 422 && $codes($b) === ['final_product_id:REQUIRED', 'recipe_id:NOT_ALLOWED_FOR_TYPE'], 'product sheet needs a final product, not a recipe');
[$s, $b] = $call('POST', '/produits/items', ['item_type' => 'product', 'final_product_id' => 501], $dir);
check($s === 403, 'direction cannot create sheets');
[$s, $item] = $call('POST', '/produits/items', ['item_type' => 'product', 'final_product_id' => 501], $access);
check($s === 201 && $item['data']['name'] === 'Croissant test' && $item['data']['settings']['status'] === 'draft'
    && $item['data']['settings']['primary_group_id'] === 1 && $item['data']['recipe_id'] === 700, 'sheet created from the ERP product, group from its section');
$itemId = $item['data']['id'];
check(count($item['data']['bom']) === 2 && $item['data']['bom'][0]['child_kind'] === 'recipe', 'BOM read from the ERP recipe');
[$s, $b] = $call('POST', '/produits/items', ['item_type' => 'product', 'final_product_id' => 501], $access);
check($s === 409 && $b['description'] === 'ITEM_EXISTS', 'one sheet per ERP product');

[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['never_frozen' => true, 'mode' => 'MTS'], $access);
check($s === 422 && $codes($b) === ['mode:NEVER_FROZEN_MTS', 'stock_max:REQUIRED_FOR_MTS'], 'never frozen ⇒ never MTS, MTS needs a max stock');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTS', 'stock_min' => 100, 'stock_max' => 50], $access);
check($s === 422 && $codes($b) === ['stock_max:STOCK_MAX_BELOW_MIN'], 'max stock below min refused');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTS', 'stock_min' => 60, 'stock_max' => 600, 'shelf_life_shop_days' => 2, 'wizard_step' => 4], $access);
check($s === 200 && $b['data']['settings']['mode'] === 'MTS' && $b['data']['settings']['stock_max'] === 600.0, 'MTS with stocks saved');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['never_frozen' => true], $access);
check($s === 422 && $codes($b) === ['mode:NEVER_FROZEN_MTS'], 'an MTS item cannot become never frozen');

[$s, $b] = $call('POST', "/produits/items/{$itemId}/validate", null, $access);
check($s === 422 && $codes($b) === ['routing:ROUTING_EMPTY'], 'no validation without a routing');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", ['steps' => [
    ['name_pl' => 'Formowanie', 'workstation_id' => 90, 'delay_after_s' => 0, 'delay_workstation_id' => 91],
    ['name_pl' => 'Pieczenie', 'workstation_id' => 91, 'fixed_time_s' => 1080, 'cleaning_time_s' => 300, 'alternatives' => [['workstation_id' => 91]]],
]], $access);
check($s === 422 && $codes($b) === ['steps.0.delay_workstation_id:DELAY_WORKSTATION_TYPE', 'steps.0.delay_after_s:REQUIRED_WITH_DELAY_WORKSTATION',
    'steps.0.variable_time_s:NO_TIME', 'steps.1.cleaning_time_s:CLEANING_NOT_REQUIRED', 'steps.1.alternatives.0.workstation_id:SAME_AS_MAIN_WORKSTATION'], 'routing errors located step by step');
check(str_contains($b['errors'][0]['message'], 'komorą fermentacyjną'), 'step errors are translated');
$routing = ['steps' => [
    ['name_pl' => 'Formowanie', 'name_fr' => 'Façonnage', 'workstation_id' => 90, 'fixed_time_s' => 300, 'variable_time_s' => 4.5, 'operators_required' => 2,
        'delay_after_s' => 3600, 'delay_workstation_id' => 93],
    ['name_pl' => 'Pieczenie', 'workstation_id' => 91, 'fixed_time_s' => 1080, 'cleaning_required' => true, 'cleaning_time_s' => 300,
        'alternatives' => [['workstation_id' => 92, 'capacity_per_pass' => 40]]],
]];
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", $routing, $access);
check($s === 200 && count($b['data']['routing']) === 2 && $b['data']['routing'][1]['capacity_per_pass'] === 60.0
    && $b['data']['routing'][1]['alternatives'][0]['fixed_time_s'] === 1080 && $b['data']['routing'][0]['name_fr'] === 'Façonnage', 'routing saved, oven capacity and times inherited');
$step1 = $b['data']['routing'][0]['id'];
$step2 = $b['data']['routing'][1]['id'];

// Test d'acceptation : même temps fixe pour 10 et 60 pièces dans un passage
[$s, $d10] = $call('GET', "/produits/items/{$itemId}/duration?quantity=10", null, $dir);
[$s, $d60] = $call('GET', "/produits/items/{$itemId}/duration?quantity=60", null, $dir);
check($d10['data']['steps'][1]['work_s'] === $d60['data']['steps'][1]['work_s'] && $d60['data']['steps'][1]['passes'] === 1, 'oven: same time for 10 and 60 pieces in one pass');
[$s, $d61] = $call('GET', "/produits/items/{$itemId}/duration?quantity=61", null, $dir);
check($d61['data']['steps'][1]['passes'] === 2 && $d61['data']['steps'][1]['work_s'] === 2160.0, '61 pieces need a second pass');
check($d60['data']['elapsed_s'] === (300 + 4.5 * 60 + 3600) + (1080 + 300) && $d60['data']['labour_s'] === (300 + 270) * 2 + 1380.0, 'elapsed and labour time');
[$s, $b] = $call('GET', "/produits/items/{$itemId}/duration?quantity=abc", null, $dir);
check($s === 422, 'quantity must be a number');

[$s, $b] = $call('PUT', "/produits/items/{$itemId}/bom-losses", ['losses' => [['child_kind' => 'material', 'child_id' => 900, 'loss_pct' => 2]]], $access);
check($s === 422 && $codes($b) === ['losses.0.child_id:NOT_IN_BOM'], 'loss only on a BOM line');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/bom-losses", ['losses' => [['child_kind' => 'material', 'child_id' => 901, 'loss_pct' => 3.5], ['child_kind' => 'recipe', 'child_id' => 701, 'loss_pct' => 1]]], $access);
check($s === 200 && array_column($b['data']['bom'], 'loss_pct') === [1.0, 3.5], 'BOM losses saved');

[$s, $b] = $call('POST', "/produits/items/{$itemId}/validate", null, $access);
check($s === 200 && $b['data']['settings']['status'] === 'validated' && $b['data']['settings']['wizard_step'] === 7, 'sheet validated');
[$s, $b] = $call('GET', '/produits/items?status=validated&mode=MTS', null, $dir);
check($s === 200 && array_column($b['data'], 'id') === [$itemId], 'list filters');

// Chronométrage : moyenne glissante, alerte d'écart, application de la norme
foreach ([[100, 850], [100, 900], [100, 800]] as [$qty, $dur]) {
    [$s, $b] = $call('POST', "/produits/routing-steps/{$step1}/time-studies", ['quantity' => $qty, 'duration_s' => $dur, 'production_employee_id' => 10], $lead);
    check($s === 201, 'team lead records a measurement');
}
$variable = $b['data']['proposals'][0];
check($variable['count'] === 3 && $variable['ready'] && abs($variable['proposed_s'] - 5.5) < 1e-9 && $variable['alert'] && $variable['deviation_pct'] === 22.2, 'per-piece time = (duration − fixed) / quantity, averaged, deviation flagged');
check($b['data']['measurements'][0]['employee'] === 'Jan Kowalski', 'measured operator kept');
[$s, $b] = $call('POST', "/produits/routing-steps/{$step1}/time-studies", ['quantity' => 0, 'duration_s' => 10, 'workstation_id' => 91, 'measured_at' => '2099-01-01 08:00'], $lead);
check($s === 422 && $codes($b) === ['quantity:OUT_OF_RANGE', 'workstation_id:NOT_STEP_WORKSTATION', 'measured_at:IN_FUTURE'], 'measurement checks');
[$s, $b] = $call('POST', "/produits/routing-steps/{$step1}/apply-standard", ['component' => 'variable'], $lead);
check($s === 403, 'a team lead measures but does not change standards');
[$s, $b] = $call('POST', "/produits/routing-steps/{$step2}/apply-standard", ['component' => 'fixed'], $access);
check($s === 409 && $b['description'] === 'NOT_ENOUGH_MEASUREMENTS', 'no standard without enough measurements');
$firstId = $b === null ? 0 : (int) $pdo->query("SELECT MIN(id) FROM tfb_prod_time_study")->fetchColumn();
[$s, $b] = $call('PATCH', "/produits/time-studies/{$firstId}", ['excluded' => true, 'note' => 'awaria'], $lead);
check($s === 200 && $b['data']['proposals'][0]['count'] === 2 && !$b['data']['proposals'][0]['ready'], 'an excluded measurement leaves the average');
$call('PATCH', "/produits/time-studies/{$firstId}", ['excluded' => false], $lead);
[$s, $b] = $call('POST', "/produits/routing-steps/{$step1}/apply-standard", ['component' => 'variable'], $access);
check($s === 200 && $b['data']['step']['variable_time_s'] === 5.5 && $b['data']['proposals'][0]['deviation_pct'] === 0.0, 'proposed standard applied to the routing');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_time_study WHERE applied_at IS NOT NULL")->fetchColumn() === 3, 'measurements marked as used');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_audit_log WHERE entity = 'routing_step' AND action = 'apply_standard'")->fetchColumn() === 1, 'standard change audited');
[$s, $b] = $call('POST', "/produits/routing-steps/{$step2}/time-studies", ['quantity' => 120, 'duration_s' => 2400], $lead, 'fr');
check($s === 201 && $b['data']['measurements'][0]['component'] === 'fixed' && $b['data']['measurements'][0]['passes'] === 2
    && $b['data']['measurements'][0]['unit_time_s'] === 1200.0 && $b['data']['step']['name'] === 'Pieczenie', 'oven measured per pass (2 passes for 120 pieces)');

// Gamme remplacée : l'étape retirée part avec ses mesures
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", ['steps' => [['id' => $step2, 'name_pl' => 'Pieczenie', 'workstation_id' => 91, 'fixed_time_s' => 1100]]], $access);
check($s === 200 && count($b['data']['routing']) === 1 && $b['data']['routing'][0]['id'] === $step2 && $b['data']['routing'][0]['step_no'] === 1
    && $b['data']['routing'][0]['alternatives'] === [], 'kept step renumbered, removed step deleted');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_time_study WHERE id_routing_step = {$step1}")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_time_study WHERE id_routing_step = {$step2}")->fetchColumn() === 1, 'measurements follow their step');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", ['steps' => [['id' => 99999, 'name_pl' => 'X', 'workstation_id' => 91, 'fixed_time_s' => 1]]], $access);
check($s === 422 && $codes($b) === ['steps.0.id:REFERENCE_NOT_FOUND'], 'a step of another item cannot be reused');

// --- P3 : besoins MTO / MTS / semi-finis, plan proposé, ajusté, validé
$pdo->exec("UPDATE unit SET name = 'g' WHERE id = 1");
$pdo->exec("INSERT IGNORE INTO unit (id, name) VALUES (3, 'g'), (5, 'szt')");
$pdo->exec("UPDATE product_recipe SET id_unit = 5, yield_quantity = 1 WHERE id = 700");
$pdo->exec("UPDATE product_recipe SET id_unit = 3, yield_quantity = 1000 WHERE id = 701");
[$s, $semi] = $call('POST', '/produits/items', ['item_type' => 'semi', 'recipe_id' => 701], $access);
check($s === 201, 'semi-finished sheet');
$semiId = $semi['data']['id'];
$call('PATCH', "/produits/items/{$semiId}/settings", ['primary_group_id' => 1, 'batch_multiple' => 5], $access);
$call('PUT', "/produits/items/{$semiId}/routing", ['steps' => [['name_pl' => 'Laminowanie', 'workstation_id' => 90, 'fixed_time_s' => 600, 'variable_time_s' => 30]]], $access);
$pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = 60, stock_max = 300, batch_min = NULL, batch_multiple = 160, coverage_days = NULL WHERE id_item = {$itemId}");
$d1 = date('Y-m-d', strtotime('+2 days'));
$cutoff = date('Y-m-d', strtotime('+0 days')) . ' 13:00:00';
$pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date, id_wdt) VALUES
    (901, 1, 1, '{$d1}', NOW() - INTERVAL 3 DAY, NULL), (902, 2, 1, '{$d1}', '" . date('Y-m-d', strtotime('+1 day')) . " 09:00:00', NULL),
    (903, 1, 1, '{$d1}', NOW() - INTERVAL 3 DAY, NULL), (904, 1, 5, '{$d1}', NOW(), NULL)");
$pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, purchase_price) VALUES
    (901, 500, 10, 30), (902, 500, 5, 30), (903, 501, 3, 40), (904, 500, 99, 30)");
$pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status) VALUES (1, 1, 501, 1), (2, 1, 501, 1), (3, 1, 501, 1), (4, 1, 501, 3)");
$pdo->exec("INSERT INTO document_wdt (id, doc_num, id_customer, established_date, currency) VALUES (1, 'W/1', 1, CURDATE() - INTERVAL 10 DAY, 'eur')");
$pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (1, 501, 'Croissant test', 42, 40)");
$period = '?from=' . date('Y-m-d') . '&to=' . date('Y-m-d', strtotime('+6 days'));

[$s, $b] = $call('GET', '/planning/demand' . $period, null, $lead);
check($s === 200, 'team lead reads the demand');
$byItem = [];
foreach ($b['data']['lines'] as $l) $byItem[$l['item_id']] = $l;
check($byItem[1]['mode'] === 'MTO' && $byItem[1]['qty_mto'] == 270.0 && $byItem[1]['qty_mts'] == 0.0 && $byItem[1]['customer_orders'] === 2,
    'MTO: 15 cartons × 18 pieces from 2 open orders (archived order ignored); never-frozen product never MTS');
check($byItem[1]['late_orders'] === 1, 'order placed after the cut-off is flagged');
check($byItem[1]['due_at'] === $d1 . ' 19:00:00', 'due before the truck leaves (20:00 − 60 min loading)');
$m = $byItem[$itemId]['mts'];
check($m['free_stock'] == 180.0 && $m['projected'] == 0.0 && $m['target'] == 300.0 && $byItem[$itemId]['qty_mts'] == 160.0 && $m['capped'],
    'MTS: free stock 3 cartons − 3 ordered = 0; target capped at max 300; batch 160 fits, 320 would not');
check($m['projected'] + $byItem[$itemId]['qty_mts'] <= 300, 'acceptance: MTS stays within the max stock');
check(abs($byItem[$semiId]['qty_semi'] - 160 / 60 * 2.0 * 1.01) < 0.001 && $byItem[$semiId]['qty_proposed'] == 10.0 && $byItem[$semiId]['parents'][0]['item_id'] === $itemId,
    'semi-finished from the BOM: 2.67 cartons × 2 kg + 1 % loss (P2) = 5.39 kg, rounded to a 5 kg batch multiple');
check(in_array('FREEZER_CAPACITY_NOT_SET', array_column($b['data']['warnings'], 'code'), true), 'freezer capacity not set is reported');

// Surgélateur : 1 carton libre au-delà des 3 cartons présents
[$s] = $call('PATCH', '/referentiels/params/cold.freezer_capacity_cartons', ['value' => 4], $access);
check($s === 200, 'freezer capacity set');
[$s, $b] = $call('GET', '/planning/demand' . $period, null, $access);
$capped = array_values(array_filter($b['data']['lines'], fn($l) => $l['item_id'] === $itemId))[0] ?? null;
check($capped === null && in_array('FREEZER_CAPACITY_LIMITED', array_column($b['data']['warnings'], 'code'), true), 'acceptance: MTS reduced to the freezer capacity (no full batch fits)');
$call('PATCH', '/referentiels/params/cold.freezer_capacity_cartons', ['value' => 0], $access);

[$s] = $call('POST', '/planning/plans', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+6 days'))], $lead);
check($s === 403, 'a team lead cannot create a plan');
[$s, $b] = $call('POST', '/planning/plans', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+40 days'))], $access);
check($s === 422 && $codes($b) === ['to:PERIOD_TOO_LONG'], 'plan period limited');
[$s, $plan] = $call('POST', '/planning/plans', ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+6 days')), 'notes' => 'Tydzień testowy'], $access);
check($s === 201 && $plan['data']['status'] === 'proposed' && count($plan['data']['lines']) === 3 && $plan['data']['orders'] === [], 'plan proposed with its lines');
$planId = $plan['data']['id'];
$lines = [];
foreach ($plan['data']['lines'] as $l) $lines[$l['item_id']] = $l;
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 200, 'adjust_reason' => 'x'], $access);
check($s === 422 && $codes($b) === ['qty_final:BELOW_ORDERS'], 'never below the customer orders');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 288], $access);
check($s === 422 && $codes($b) === ['adjust_reason:REQUIRED'], 'a change needs a reason');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[$itemId]['id']}", ['qty_final' => 320, 'adjust_reason' => 'więcej'], $access);
check($s === 422 && $codes($b) === ['qty_final:MTS_ABOVE_STOCK_MAX'], 'acceptance: an MTS adjustment cannot exceed the max stock');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 288, 'adjust_reason' => 'pełna blacha'], $access);
check($s === 200 && array_values(array_filter($b['data']['lines'], fn($l) => $l['item_id'] === 1))[0]['qty_final'] == 288.0, 'MTO line adjusted with a reason');

[$s, $b] = $call('POST', "/planning/plans/{$planId}/validate", null, $access);
check($s === 200 && $b['data']['status'] === 'validated' && count($b['data']['orders']) === 3, 'plan validated, 3 production orders');
$orders = [];
foreach ($b['data']['orders'] as $o) $orders[$o['origin']] = $o;
check($orders['MTO']['quantity'] == 288.0 && $orders['MTO']['customer_orders'] === 2 && $orders['MTO']['due_at'] === $d1 . ' 19:00:00', 'MTO order linked to its 2 customer orders, due before departure');
check($orders['MTS']['quantity'] == 160.0 && $orders['MTS']['priority'] > $orders['MTO']['priority'], 'MTS order after MTO in priority');
check($orders['SEMI']['parent_order_id'] === $orders['MTS']['id'] && $orders['SEMI']['due_at'] < $orders['MTS']['due_at'], 'semi-finished order tied to its parent and due before it starts');
check(preg_match('/^OF-\d{8}-\d{3}$/', $orders['MTO']['code']) === 1, 'order codes');
[$s, $b] = $call('POST', "/planning/plans/{$planId}/validate", null, $access);
check($s === 409 && $b['description'] === 'PLAN_NOT_EDITABLE', 'a validated plan is not validated twice');
[$s, $plan2] = $call('POST', '/planning/plans', ['from' => date('Y-m-d', strtotime('+3 days')), 'to' => date('Y-m-d', strtotime('+9 days'))], $access);
check($plan2['data']['version'] === 1, 'another period starts at version 1');
[$s, $b] = $call('POST', "/planning/plans/{$plan2['data']['id']}/validate", null, $access);
check($s === 409 && $b['description'] === 'PLAN_OVERLAP', 'one validated plan per day');
[$s, $b] = $call('GET', '/planning/demand' . $period, null, $access);
$mtsAgain = array_values(array_filter($b['data']['lines'], fn($l) => $l['item_id'] === $itemId))[0]['mts'] ?? null;
check($mtsAgain === null || $mtsAgain['projected'] >= 160, 'released MTS orders count in the projected stock');
[$s, $b] = $call('GET', '/planning/plans?status=validated', null, $dir);
check($s === 200 && array_column($b['data'], 'id') === [$planId], 'direction reads validated plans');

// --- P4 : ordonnancement du plan validé, opérations, charge
[$s, $b] = $call('POST', "/planning/plans/{$plan2['data']['id']}/schedule", null, $access);
check($s === 409 && $b['description'] === 'PLAN_NOT_VALIDATED', 'only a validated plan is scheduled');
// Échéances décalées d'un jour : le test ne dépend pas de l'heure à laquelle il tourne (shift de jour 06:00–14:00).
$pdo->exec("UPDATE tfb_prod_order SET due_at = due_at + INTERVAL 1 DAY WHERE id_plan = {$planId}");
[$s, $b] = $call('POST', "/planning/plans/{$planId}/schedule", null, $access);
check($s === 200 && $b['data']['operations'] > 0, 'plan scheduled');
$conflicts = array_column($b['data']['conflicts'], 'reason', 'code');
check(($conflicts[$orders['MTO']['code']] ?? '') === 'NO_ROUTING', 'an order without routing is reported, not placed');
$late = (int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_operation op JOIN tfb_prod_order o ON o.id = op.id_order WHERE op.planned_end > o.due_at")->fetchColumn();
check($late === 0, 'acceptance: no operation ends after its order is due (truck departure)');
$outsideShift = (int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_operation WHERE kind <> 'delay' AND (TIME(planned_start) < '06:00' OR TIME(planned_end) > '14:00' OR DATE(planned_end) <> DATE(planned_start))")->fetchColumn();
check($outsideShift === 0, 'work only on the staffed day shift (no afternoon or night team in this group)');
$semiEnd = $pdo->query("SELECT MAX(planned_end) FROM tfb_prod_operation WHERE id_order = {$orders['SEMI']['id']}")->fetchColumn();
$parentStart = $pdo->query("SELECT MIN(planned_start) FROM tfb_prod_operation WHERE id_order = {$orders['MTS']['id']}")->fetchColumn();
check($semiEnd !== null && $parentStart !== null && $semiEnd <= $parentStart, 'semi-finished ends before its parent starts');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_operation WHERE kind <> 'delay' AND id_team IS NULL")->fetchColumn() === 0, 'each operation has its team');
$from = date('Y-m-d', strtotime('-1 day'));
$to = date('Y-m-d', strtotime('+6 days'));
[$s, $ops] = $call('GET', "/planning/operations?from={$from}&to={$to}&group_id=1", null, $lead);
check($s === 200 && count($ops['data']) === $b['data']['operations'] && $ops['data'][0]['team'] === 'T1', 'operations listed for the Gantt');
[$s, $load] = $call('GET', "/planning/load?from={$from}&to={$to}", null, $dir);
$busy = array_values(array_filter($load['data'], fn($l) => $l['required_h'] > 0));
check($s === 200 && $busy && $busy[0]['shift'] === 'JOUR' && $busy[0]['available_h'] == 16 && in_array($busy[0]['status'], ['under', 'ok', 'over'], true), 'load = planned hours ÷ (team T1: 2 people × 8 h)');
$pdo->exec("UPDATE tfb_prod_operation SET locked = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM tfb_prod_operation) x)");
$lockedBefore = $pdo->query("SELECT planned_start FROM tfb_prod_operation WHERE locked = 1")->fetchColumn();
[$s, $b2] = $call('POST', "/planning/plans/{$planId}/schedule", null, $access);
check($s === 200 && $pdo->query("SELECT planned_start FROM tfb_prod_operation WHERE locked = 1")->fetchColumn() === $lockedBefore, 'a locked operation stays where it is when rescheduling');

// --- P5 : déplacer / verrouiller une opération sur le Gantt
$pdo->exec("UPDATE tfb_prod_operation SET locked = 0");
$semiOp = $pdo->query("SELECT * FROM tfb_prod_operation WHERE id_order = {$orders['SEMI']['id']} ORDER BY planned_start DESC LIMIT 1")->fetch();
$mtsFirst = $pdo->query("SELECT * FROM tfb_prod_operation WHERE id_order = {$orders['MTS']['id']} ORDER BY planned_start LIMIT 1")->fetch();
$at = fn(string $dt, int $minutes) => date('Y-m-d H:i', strtotime($dt) + $minutes * 60);
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['start' => $at($semiOp['planned_start'], -60)], $dir);
check($s === 403, 'direction reads the Gantt but does not move operations');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['start' => 'demain'], $lead);
check($s === 422 && $codes($b) === ['start:INVALID_DATETIME'], 'start must be a date and time');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['start' => date('Y-m-d', strtotime($semiOp['planned_start'])) . ' 02:00'], $lead);
check($s === 422 && in_array('start:OUTSIDE_STAFFED_SHIFT', $codes($b), true), 'no move to the night: the group has no night team');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['start' => $at($mtsFirst['planned_start'], 0)], $lead);
check($s === 422 && in_array('start:AFTER_PARENT_START', $codes($b), true), 'a semi-finished product must be ready before its parent order starts');
[$s, $b] = $call('PATCH', "/planning/operations/{$mtsFirst['id']}", ['start' => $at($semiOp['planned_start'], -15)], $lead);
check($s === 422 && in_array('start:CHILD_NOT_READY', $codes($b), true), 'a parent order cannot start before its semi-finished products are ready');
$mtsLast = $pdo->query("SELECT o.*, ord.due_at FROM tfb_prod_operation o JOIN tfb_prod_order ord ON ord.id = o.id_order WHERE o.id_order = {$orders['MTS']['id']} ORDER BY o.planned_start DESC LIMIT 1")->fetch();
[$s, $b] = $call('PATCH', "/planning/operations/{$mtsLast['id']}", ['start' => $at($mtsLast['due_at'], 0)], $lead);
check($s === 422 && in_array('start:AFTER_DUE', $codes($b), true), 'acceptance: never moved after the order is due (truck departure)');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['start' => date('Y-m-d H:i', strtotime('-2 days'))], $lead);
check($s === 422 && in_array('start:IN_PAST', $codes($b), true), 'no move into the past');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiOp['id']}", ['workstation_id' => 9999], $lead);
check($s === 422 && $codes($b) === ['workstation_id:REFERENCE_NOT_FOUND'], 'unknown workstation');
$semiFirst = $pdo->query("SELECT * FROM tfb_prod_operation WHERE id_order = {$orders['SEMI']['id']} ORDER BY planned_start LIMIT 1")->fetch();
[$s, $b] = $call('PATCH', "/planning/operations/{$semiFirst['id']}", ['start' => $at($semiFirst['planned_start'], -60)], $lead);
check($s === 200 && $b['data']['start'] === $at($semiFirst['planned_start'], -60) . ':00' && $b['data']['locked'] === true
    && strtotime($b['data']['end']) - strtotime($b['data']['start']) === strtotime($semiFirst['planned_end']) - strtotime($semiFirst['planned_start']), 'moved one hour earlier, duration kept, locked');
check($pdo->query("SELECT COUNT(*) FROM tfb_prod_audit_log WHERE entity = 'operation' AND action = 'move'")->fetchColumn() == 1, 'move recorded in the audit log');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiFirst['id']}", ['locked' => false], $lead);
check($s === 200 && $b['data']['locked'] === false && $b['data']['start'] === $at($semiFirst['planned_start'], -60) . ':00', 'unlock without moving');
[$s, $b] = $call('PATCH', "/planning/operations/{$semiFirst['id']}", [], $lead);
check($s === 422, 'nothing to change');
[$s, $b] = $call('PATCH', '/planning/operations/999999', ['locked' => true], $lead);
check($s === 404, 'unknown operation');
$pdo->exec("UPDATE tfb_prod_operation SET status = 'done' WHERE id = {$semiFirst['id']}");
[$s, $b] = $call('PATCH', "/planning/operations/{$semiFirst['id']}", ['start' => $at($semiFirst['planned_start'], -30)], $lead);
check($s === 409 && $b['description'] === 'OPERATION_NOT_MOVABLE', 'a finished operation does not move');
$pdo->exec("UPDATE tfb_prod_operation SET status = 'planned' WHERE id = {$semiFirst['id']}");

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
