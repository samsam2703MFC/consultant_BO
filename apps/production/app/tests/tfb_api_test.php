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

use App\Core\Api\Workshop\Jwt;
use App\Core\Api\Workshop\WorkshopApiKernel;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Services\Workshop\Execution\KioskAuthService;
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
foreach (['user', 'production_sections', 'production_employee', 'final_product', 'product_recipe', 'product_recipe_connection', 'raw_material', 'unit', 'customer_order', 'customer_order_product', 'carton_stock', 'document_wdt', 'document_wdt_product', 'communication_template', 'recipe_competency', 'customer', 'carrier', 'carrier_vehicle', 'category_product', 'flattened_recipe_ingredient', 'category_material', 'erp_company_other_settings', 'supplier', 'raw_material_price_changelog', 'cost_month', 'cost_month_employee', 'cost_month_fixed_cost', 'cost_month_logistics', 'cost_media_month', 'cost_media_section_allocation', 'cost_media_type', 'communication_message', 'communication_message_attachment', 'communication_email_account', 'erp_company_data'] as $table) {
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
// Grupy = działy produkcji ERP : dwie grupy piekarni (zakwas, formowanie) w jednej grupie « Piekarnia »,
// zespół dzienny = pracownicy działu. Dalej test buduje własne zespoły.
$bakery = $pdo->query("SELECT code, name_pl, active, id_section FROM tfb_prod_group WHERE id = 1")->fetch();
check($bakery['code'] === 'PIEKARNIA' && $bakery['name_pl'] === 'Piekarnia' && (int) $bakery['active'] === 1 && (int) $bakery['id_section'] === 1
    && (int) $pdo->query("SELECT active FROM tfb_prod_group WHERE code = 'FACONNAGE'")->fetchColumn() === 0, 'groups = ERP production sections (bakery groups merged into « Piekarnia »)');
check((int) $pdo->query("SELECT planned_headcount FROM tfb_prod_team WHERE code = 'PIEKARNIA_DZIEN' AND active = 1")->fetchColumn() === 2, 'day team of the section = its 2 employees');
$pdo->exec("DELETE FROM tfb_prod_team");

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
    // Aucun e-mail ne part pendant les tests : la file est remplie, le transport refuse tout envoi.
    App\Services\Communication\SmtpTransport::class => new class implements App\Services\Communication\SmtpTransport {
        public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string { throw new RuntimeException('no e-mail in tests'); }
    },
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

// Jeden kreator : krok « Produkt » (dane ERP z dawnego formularza edycji), karta produktu ERP, podsumowanie produkcji.
$pdo->exec("INSERT INTO category_product (id, name) VALUES (1, 'Wiennoiserie'), (2, 'Pieczywo')");
[$s, $o] = $call('GET', '/produits/erp-options', null, $dir);
check($s === 200 && array_column($o['data']['categories'], 'name') === ['Pieczywo', 'Wiennoiserie'] && array_column($o['data']['sections'], 'id') === [1] && $o['data']['label_types'] === [0, 1], 'ERP options of the product step');
[$s, $b] = $call('GET', '/produits/items?final_product_id=501', null, $dir);
check($s === 200 && array_column($b['data'], 'id') === [$itemId], 'the sheet of an ERP product is found by final_product_id (product edit opens it)');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/product", ['name' => '  ', 'category_id' => 99, 'section_id' => 77, 'label_type' => 2], $access);
check($s === 422 && $codes($b) === ['name:REQUIRED', 'label_type:OUT_OF_RANGE', 'category_id:REFERENCE_NOT_FOUND', 'section_id:REFERENCE_NOT_FOUND'], 'product step checks (name, category, section, label)');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/product", ['name' => 'Croissant test 75', 'category_id' => 1, 'label_type' => 1, 'shelf_life' => 180, 'stock_minimum' => 12], $dir);
check($s === 403, 'direction does not edit products');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/product", ['name' => 'Croissant test 75', 'category_id' => 1, 'label_type' => 1, 'shelf_life' => 180, 'stock_minimum' => 12], $access);
$fp = $pdo->query("SELECT name, id_category, label_type, shelf_life, stock_minimum FROM final_product WHERE id = 501")->fetch();
check($s === 200 && $b['data']['name'] === 'Croissant test 75' && $b['data']['erp']['label_type'] === 1 && $b['data']['erp']['category_id'] === 1
    && $fp['name'] === 'Croissant test 75' && (int) $fp['shelf_life'] === 180 && (int) $fp['stock_minimum'] === 12, 'product step writes the ERP product');

[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['never_frozen' => true, 'mode' => 'MTS'], $access);
check($s === 422 && $codes($b) === ['mode:NEVER_FROZEN_MTS', 'stock_max:REQUIRED_FOR_MTS'], 'never frozen ⇒ never MTS, MTS needs a max stock');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTS', 'stock_min' => 100, 'stock_max' => 50], $access);
check($s === 422 && $codes($b) === ['stock_max:STOCK_MAX_BELOW_MIN'], 'max stock below min refused');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTS', 'stock_min' => 60, 'stock_max' => 600, 'shelf_life_shop_days' => 2, 'wizard_step' => 4], $access);
check($s === 200 && $b['data']['settings']['mode'] === 'MTS' && $b['data']['settings']['stock_max'] === 600.0, 'MTS with stocks saved');
$erpMin = fn() => (int) $pdo->query('SELECT stock_minimum FROM final_product WHERE id = 501')->fetchColumn();
check($erpMin() === 1, 'card min 60 pcs (60 / carton) copied to the ERP minimum: 1 carton');
$call('PATCH', "/produits/items/{$itemId}/settings", ['stock_min' => 130], $access);
check($erpMin() === 3, 'ERP minimum rounded up to whole cartons (130 pcs → 3)');
$call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTO'], $access);
check($erpMin() === 0, 'switching to MTO clears the ERP minimum');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['mode' => 'MTS', 'stock_min' => 60, 'stock_max' => 600], $access);
check($s === 200 && $erpMin() === 1, 'back to MTS: ERP minimum from the card again');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['never_frozen' => true], $access);
check($s === 422 && $codes($b) === ['mode:NEVER_FROZEN_MTS'], 'an MTS item cannot become never frozen');

[$s, $b] = $call('POST', "/produits/items/{$itemId}/validate", null, $access);
check($s === 422 && $codes($b) === ['routing:ROUTING_EMPTY', 'bake_state:REQUIRED'], 'no validation without a routing nor a baking state');

// Logistique (étape 1) : champs de l'ERP + carton, palette, conservation ; valeurs calculées
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/logistics", ['cpp' => 100, 'carton_ean' => 'abc', 'carton_type' => 'x', 'ppc' => 0], $access);
check($s === 422 && $codes($b) === ['ppc:OUT_OF_RANGE', 'carton_type:INVALID_CHOICE', 'carton_ean:INVALID_EAN', 'cpp:PALLET_OVER_LAYERS'], 'logistics checks (pallet ≤ layers × cartons per layer)');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/logistics", ['weight' => 72, 'carton_length_mm' => 400, 'carton_width_mm' => 300, 'carton_height_mm' => 200,
    'carton_tare_g' => 350, 'carton_type' => 'klapowy', 'carton_ean' => '5901234123457', 'pallet_type' => 'eur', 'storage' => 'frozen'], $access);
$lg = $b['data']['logistics'] ?? [];
check($s === 200 && $b['data']['erp']['weight'] === 72.0 && $lg['carton_type'] === 'klapowy' && $lg['carton_ean'] === '5901234123457'
    && $lg['computed']['carton_net_kg'] === 4.32 && $lg['computed']['carton_gross_kg'] === 4.67 && $lg['computed']['pallet_units'] === 2880
    && $lg['computed']['pallet_net_kg'] === 207.4 && $lg['computed']['pallet_gross_kg'] === 249.2 && $lg['computed']['pallet_height_mm'] === 1344, 'logistics saved, ERP weight updated, carton and pallet computed');
check((float) $pdo->query('SELECT weight FROM final_product WHERE id = 501')->fetchColumn() === 72.0, 'ERP product weight written');
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/logistics", ['carton_tare_g' => 1], $dir);
check($s === 403, 'direction cannot change logistics');
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
$withTemp = $routing;
$withTemp['steps'][0]['temperature_c'] = 30;
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", $withTemp, $access);
check($s === 422 && $codes($b) === ['steps.0.temperature_c:TEMPERATURE_NOT_FOR_WORKSTATION'], 'temperature only on an oven, proofing or cold step');
$withTemp = $routing;
$withTemp['steps'][0]['id'] = $step1;
$withTemp['steps'][1]['id'] = $step2;
$withTemp['steps'][1]['temperature_c'] = 220;
[$s, $b] = $call('PUT', "/produits/items/{$itemId}/routing", $withTemp, $access);
check($s === 200 && $b['data']['routing'][1]['temperature_c'] === 220.0 && $b['data']['routing'][1]['id'] === $step2
    && array_column($b['data']['equipment'], 'type') === ['manuel', 'four'] && $b['data']['equipment'][1]['temperatures_c'] === [220.0]
    && $b['data']['logistics']['computed']['labour_s_per_carton'] > 0, 'oven temperature kept; equipment of the routing listed; labour per carton');

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

[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['bake_state' => 'roasted'], $access);
check($s === 422 && $codes($b) === ['bake_state:INVALID_CHOICE'], 'baking state is none, par_baked or baked');
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['bake_state' => 'baked'], $access);
check($s === 200 && $b['data']['settings']['bake_state'] === 'baked', 'baking state saved');
[$s, $b] = $call('POST', "/produits/items/{$itemId}/validate", null, $access);
check($s === 200 && $b['data']['settings']['status'] === 'validated' && $b['data']['settings']['wizard_step'] === 8, 'sheet validated (baked, oven in the routing)');
[$s, $ps] = $call('GET', "/produits/items/{$itemId}/production-summary", null, $dir);
check($s === 200 && $ps['data']['reference']['kind'] === 'pallet' && $ps['data']['reference']['quantity'] == 60 * 48 && $ps['data']['per_hour'] > 0
    && count($ps['data']['steps']) === count($b['data']['routing']) && $ps['data']['steps'][0]['start_s'] == 0, 'production summary: pieces per hour for one pallet, steps from the start');
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
[$s, $b] = $call('PUT', "/produits/items/{$semiId}/logistics", ['ppc' => 10], $access);
check($s === 409 && $b['description'] === 'NOT_ALLOWED_FOR_TYPE', 'a semi-finished item has no carton logistics');
$pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = 60, stock_max = 300, batch_min = NULL, batch_multiple = 160, coverage_days = NULL WHERE id_item = {$itemId}");
$d1 = date('Y-m-d', strtotime('+2 days'));
$cutoff = date('Y-m-d', strtotime('+0 days')) . ' 13:00:00';
$pdo->exec("INSERT INTO customer_order (id, id_customer, status, expected_shipping_date, create_order_date, id_wdt) VALUES
    (901, 1, 1, '{$d1}', NOW() - INTERVAL 3 DAY, NULL), (902, 2, 1, '{$d1}', '" . date('Y-m-d', strtotime('+1 day')) . " 09:00:00', NULL),
    (903, 1, 1, '{$d1}', NOW() - INTERVAL 3 DAY, NULL), (904, 1, 5, '{$d1}', NOW(), NULL)");
$pdo->exec("INSERT INTO customer_order_product (id_order, id_product, quantity_carton, purchase_price) VALUES
    (901, 500, 10, 30), (902, 500, 5, 30), (903, 501, 3, 40), (904, 500, 99, 30)");
$pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status, date_add) VALUES (1, 1, 501, 1, CURDATE() - INTERVAL 30 DAY), (2, 1, 501, 1, CURDATE() - INTERVAL 30 DAY),
    (3, 1, 501, 1, CURDATE() - INTERVAL 30 DAY), (4, 1, 501, 3, CURDATE() - INTERVAL 30 DAY)");
$pdo->exec("INSERT INTO document_wdt (id, doc_num, id_customer, established_date, currency) VALUES (1, 'W/1', 1, CURDATE() - INTERVAL 10 DAY, 'eur')");
$pdo->exec("INSERT INTO document_wdt_product (id_document_wdt, id_product, name, quantity, price_carton) VALUES (1, 501, 'Croissant test', 42, 40)");
// Stany z ostatnich 6 tygodni (krok « Tryb i zapasy ») : 42 kartony / 6 tyg. = 7 kart./tydz. → min 1 tydz., maks 3 tyg.
[$s, $st] = $call('GET', "/produits/items/{$itemId}/stock-suggestion", null, $dir);
check($s === 200 && $st['data']['weeks'] === 6 && $st['data']['shipped_cartons'] == 42 && $st['data']['weekly_cartons'] == 7
    && $st['data']['stock_min_cartons'] === 7 && $st['data']['stock_max_cartons'] === 21 && $st['data']['stock_min'] === 7 * 60 && $st['data']['stock_max'] === 21 * 60, 'stock suggestion: min 1 week, max 3 weeks of sales, whole cartons');
// Zapasy wg kategorii : 7 kart./tydz. od 1 sklepu, zapas optymalny 21 kart. = 21 / 48 palety.
[$s, $ov] = $call('GET', '/produits/stock-overview', null, $dir);
$row = null;
foreach ($ov['data']['categories'] ?? [] as $c) foreach ($c['items'] as $i) if ($i['id'] === $itemId) $row = $i;
check($s === 200 && $ov['data']['params']['max_weeks'] == 3 && $row && $row['weekly_cartons'] == 7 && $row['shops'] === 1 && $row['per_shop_weekly_cartons'] == 7
    && $row['per_shop_weekly_quantity'] == 420 && $row['optimal_cartons'] === 21 && $row['optimal_pallets'] == round(21 / 48, 2) && $row['suggested_min_cartons'] === 7, 'stock overview: weekly sales, per shop per week, optimal cartons and pallets');
// Zapas docelowy MTS = stan maks. 300 szt. / 60 = 5 kart. ; cena kartonu z WDT 40 € → wartość 200 € ; sumy grupy i całość.
$grp = null;
foreach ($ov['data']['groups'] ?? [] as $g) if ($g['id'] === $row['group_id']) $grp = $g['totals'];
check($row['mode'] === 'MTS' && $row['target_cartons'] === 5 && $row['price_eur'] == 40 && $row['target_value_eur'] == 200 && $row['target_pallets'] == round(5 / 48, 2)
    && $grp && $grp['target_cartons'] >= 5 && $grp['target_value_eur'] >= 200 && $ov['data']['totals']['mts'] >= 1 && $ov['data']['totals']['target_value_eur'] >= 200,
    'stock overview: MTS target stock in cartons, pallets and value, totals by group and overall');
// Stan magazynu : 3 kartony wolne (1 paletyzowany się nie liczy), zamówienie 903 « Nowe » na 3 kartony ;
// do produkcji poza zamówieniami = stan min. − dostępne.
check($row['free_cartons'] == 3 && $row['ordered_cartons'] == 3 && $row['available_cartons'] == round(3 - 3 + $row['in_production_cartons'], 1)
    && ($row['stock_min_cartons'] === null ? $row['to_min_cartons'] === null : $row['to_min_cartons'] === (int) ceil(round(max(0, $row['stock_min_cartons'] - max(0, $row['available_cartons'])), 6))),
    'stock level: free cartons, open orders, available and to produce back to the minimum');
$period = '?from=' . date('Y-m-d') . '&to=' . date('Y-m-d', strtotime('+6 days'));

[$s, $b] = $call('GET', '/planning/demand' . $period, null, $lead);
check($s === 200, 'team lead reads the demand');
$byItem = [];
foreach ($b['data']['lines'] as $l) $byItem[$l['item_id']] = $l;
check($byItem[1]['mode'] === 'MTO' && $byItem[1]['qty_mto'] == 270.0 && $byItem[1]['qty_mts'] == 0.0 && $byItem[1]['customer_orders'] === 2,
    'MTO: 15 cartons × 18 pieces from 2 open orders (archived order ignored); never-frozen product never MTS');
check($byItem[1]['late_orders'] === 1, 'order placed after the cut-off is flagged');
check($byItem[1]['due_at'] === $d1 . ' 19:00:00', 'due before the truck leaves (20:00 − 60 min loading)');
// Klient 2 na trasie z wyjazdem 16:00 : linia MTO (klienci 1 i 2) na 15:00 ; trasa nie w ten dzień tygodnia : bez zmian
$pdo->exec("INSERT IGNORE INTO customer (id, company) VALUES (1, 'Klient A'), (2, 'Klient B')");
$pdo->exec("INSERT INTO tfb_tour (id, name, weekdays, departure_time) VALUES (1, 'Rano', 127, '16:00:00')");
$pdo->exec("INSERT INTO tfb_tour_customer (id_tour, id_customer, stop_order) VALUES (1, 2, 1)");
[, $b] = $call('GET', '/planning/demand' . $period, null, $lead);
$toured = array_column($b['data']['lines'], 'due_at', 'item_id');
$pdo->exec("UPDATE tfb_tour SET weekdays = 127 & ~(1 << (" . date('N', strtotime($d1)) . " - 1)) WHERE id = 1");
[, $b] = $call('GET', '/planning/demand' . $period, null, $lead);
check($toured[1] === $d1 . ' 15:00:00' && array_column($b['data']['lines'], 'due_at', 'item_id')[1] === $d1 . ' 19:00:00', 'MTO due before the earliest tour departure of its customers');
$pdo->exec('DELETE FROM tfb_tour');
$m = $byItem[$itemId]['mts'];
check($m['free_stock'] == 180.0 && $m['available'] == 0.0 && $m['min'] == 60.0 && $m['max'] == 300.0 && $m['reason'] === 'below_min' && $byItem[$itemId]['qty_mts'] == 300.0,
    'MTS (simple rule): free stock 3 cartons − 3 ordered = 0 available, below min 60 → refill up to max 300');
check($byItem[$itemId]['qty_batch'] == 20.0 && $byItem[$itemId]['qty_proposed'] == 320.0 && $byItem[$itemId]['batches'] === 2,
    'week rounded to whole batches: 300 → 2 batches of 160 = 320 (20 more for stock)');
// Partie zrobione w tym okresie (kartony dodane od początku okresu) nie zmniejszają potrzeby okresu.
$pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status, date_add) VALUES (5, 2, 501, 1, CURDATE()), (6, 2, 501, 1, CURDATE())");
[, $made] = $call('GET', '/planning/demand' . $period, null, $lead);
$madeLine = array_column($made['data']['lines'], null, 'item_id')[$itemId] ?? null;
check($madeLine && $madeLine['mts']['free_stock'] == 300.0 && $madeLine['mts']['made'] == 120.0 && $madeLine['mts']['available'] == 0.0 && $madeLine['qty_mts'] == 300.0,
    'cartons made this week stay counted in the week: 2 more cartons in stock, the need of the week does not drop');
$pdo->exec('DELETE FROM carton_stock WHERE id_carton IN (5, 6)');
check(abs($byItem[$semiId]['qty_semi'] - 320 / 60 * 2.0 * 1.01) < 0.001 && $byItem[$semiId]['qty_proposed'] == 15.0 && $byItem[$semiId]['parents'][0]['item_id'] === $itemId,
    'semi-finished from the BOM: 5.33 cartons × 2 kg + 1 % loss (P2) = 10.77 kg, rounded to a 5 kg batch multiple');
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
check($lines[$itemId]['batch_size'] == 160 && $lines[$itemId]['batches'] === 2 && $lines[$semiId]['batch_size'] == 5 && $lines[$semiId]['batches'] === 3
    && $byItem[$itemId]['batch_size'] == 160 && $byItem[$itemId]['ppc'] === 60, 'plan and proposal show the production batch (number and size)');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 200, 'adjust_reason' => 'x'], $access);
check($s === 422 && $codes($b) === ['qty_final:BELOW_ORDERS'], 'never below the customer orders');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 288], $access);
check($s === 422 && $codes($b) === ['adjust_reason:REQUIRED'], 'a change needs a reason');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[$itemId]['id']}", ['qty_final' => 480, 'adjust_reason' => 'więcej'], $access);
check($s === 422 && $codes($b) === ['qty_final:MTS_ABOVE_STOCK_MAX'], 'acceptance: an MTS adjustment cannot exceed the max stock (in whole batches: 300 → 320)');
[$s, $b] = $call('PATCH', "/planning/plans/{$planId}/lines/{$lines[1]['id']}", ['qty_final' => 288, 'adjust_reason' => 'pełna blacha'], $access);
check($s === 200 && array_values(array_filter($b['data']['lines'], fn($l) => $l['item_id'] === 1))[0]['qty_final'] == 288.0, 'MTO line adjusted with a reason');

[$s, $b] = $call('POST', "/planning/plans/{$planId}/validate", null, $access);
check($s === 200 && $b['data']['status'] === 'validated' && count($b['data']['orders']) === 3, 'plan validated, 3 production orders');
$orders = [];
foreach ($b['data']['orders'] as $o) $orders[$o['origin']] = $o;
check($orders['MTO']['quantity'] == 288.0 && $orders['MTO']['customer_orders'] === 2 && $orders['MTO']['due_at'] === $d1 . ' 19:00:00', 'MTO order linked to its 2 customer orders, due before departure');
check($orders['MTS']['quantity'] == 320.0 && $orders['MTS']['priority'] > $orders['MTO']['priority'], 'MTS order after MTO in priority');
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
check($mtsAgain === null || $mtsAgain['available'] >= 160, 'released MTS orders count in the available stock');
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

// --- P6 : optimiseur de shifts (95 %), application, simulation
// Deuxième produit MTS (pain façonné à 2, 30 s/pièce sur sa table) : de quoi remplir les shifts.
$pdo->exec("UPDATE tfb_prod_product_settings SET stock_max = 5000 WHERE id_item = {$itemId}");
$pdo->exec("INSERT INTO tfb_prod_workstation (id, code, id_group, name_pl, name_fr, type, capacity_per_pass) VALUES (94, 'STOL_P6', 1, 'Stół 2', 'Table 2', 'manuel', 1000)");
$pdo->exec("INSERT INTO final_product (id, name, id_category, weight, ppc, ppb, bpc, cpl, lpp, cpp, id_section, active) VALUES (502, 'Pain test', 1, 300, 10, 1, 10, 8, 6, 48, 1, 1)");
$pdo->exec("INSERT INTO tfb_prod_item (id, item_type, id_final_product) VALUES (50, 'product', 502)");
$pdo->exec("INSERT INTO tfb_prod_product_settings (id_item, mode, stock_max, batch_multiple, id_primary_group, status) VALUES (50, 'MTS', 100000, 10, 1, 'validated')");
$pdo->exec("INSERT INTO tfb_prod_routing_step (id_item, step_no, name_pl, name_fr, id_workstation, variable_time_s, capacity_per_pass, operators_required) VALUES (50, 1, 'Formowanie', 'Façonnage', 94, 30, 1000, 2)");
$wFrom = date('Y-m-d', strtotime('+1 day'));
$wTo = date('Y-m-d', strtotime('+6 days'));
$before = $pdo->query("SELECT id, planned_start, planned_end, id_workstation FROM tfb_prod_operation ORDER BY id")->fetchAll();
[$s, $opt] = $call('GET', "/planning/optimizer?from={$wFrom}&to={$wTo}", null, $lead);
check($s === 200 && $opt['data']['summary']['before']['green'] < $opt['data']['summary']['after']['green'], 'optimizer proposes more shifts in the green band');
$worked = array_values(array_filter($opt['data']['rows'], fn($r) => $r['after']['required_h'] > 0));
check($worked && !array_filter($worked, fn($r) => $r['after']['status'] !== 'ok'), 'acceptance: every worked shift of the week proposed at 90–100 %');
check(count(array_filter($opt['data']['rows'], fn($r) => $r['actions'] && $r['actions'][0]['lever'] === 'advance_mts')) >= 1, 'underloaded shifts filled by advancing MTS');
[$s, $b] = $call('POST', '/planning/optimizer/apply', ['from' => $wFrom, 'to' => $wTo], $lead);
check($s === 403, 'a team lead does not apply the optimizer');
$staffingRows = (int) $pdo->query('SELECT COUNT(*) FROM tfb_prod_staffing')->fetchColumn();
[$s, $sim] = $call('POST', '/planning/simulate', ['from' => $wFrom, 'to' => $wFrom, 'staffing' => [['date' => $wFrom, 'group_id' => 1, 'shift_id' => $jourId = (int) $pdo->query("SELECT id FROM tfb_prod_shift_template WHERE code = 'JOUR'")->fetchColumn(), 'headcount' => 4, 'overtime_h' => 1]]], $lead);
$simRow = array_values(array_filter($sim['data']['rows'], fn($r) => $r['group_id'] === 1 && $r['shift_id'] === $jourId))[0] ?? null;
check($s === 200 && $simRow && $simRow['headcount'] === 4 && $simRow['before']['available_h'] == 33.0 && $simRow['staffing'] === 'simulation', 'simulation: 4 people × 8 h + 1 h overtime');
check((int) $pdo->query('SELECT COUNT(*) FROM tfb_prod_staffing')->fetchColumn() === $staffingRows, 'simulation saves nothing');
[$s, $b] = $call('POST', '/planning/simulate', ['from' => $wFrom, 'to' => $wFrom, 'staffing' => [['date' => $wFrom, 'group_id' => 1, 'shift_id' => $jourId, 'headcount' => 99]]], $lead);
check($s === 422 && $codes($b) === ['staffing.0.headcount:OUT_OF_RANGE'], 'simulated headcount checked');
[$s, $res] = $call('POST', '/planning/optimizer/apply', ['from' => $wFrom, 'to' => $wTo], $access);
check($s === 200 && $res['data']['mts_orders'] === 5 && $res['data']['operations'] >= 5 && $res['data']['conflicts'] === [] && $res['data']['staffing_rows'] === 0,
    'proposal applied: 5 MTS orders scheduled without conflict, no staffing change needed');
$after = $pdo->query("SELECT id, planned_start, planned_end, id_workstation FROM tfb_prod_operation WHERE id <= " . (int) max(array_column($before, 'id')) . " ORDER BY id")->fetchAll();
check($after == $before, 'acceptance: existing operations (MTO) untouched — never delayed');
$newOps = $pdo->query("SELECT op.planned_end, o.due_at FROM tfb_prod_operation op JOIN tfb_prod_order o ON o.id = op.id_order WHERE o.origin = 'MTS' AND op.id > " . (int) max(array_column($before, 'id')))->fetchAll();
check($newOps && !array_filter($newOps, fn($o) => $o['planned_end'] > $o['due_at']), 'advanced MTS placed before the end of its shift');
check($res['data']['summary_after']['green'] === 5 && $res['data']['summary_after']['load_pct'] >= 90 && $res['data']['summary_after']['load_pct'] <= 100, 'acceptance: the week brought to 90–100 % (5 shifts of 5)');
[$s, $load] = $call('GET', "/planning/load?from={$wFrom}&to={$wTo}", null, $dir);
$worked = array_filter($load['data'], fn($l) => $l['required_h'] > 0);
check(count($worked) === 5 && !array_filter($worked, fn($l) => $l['status'] !== 'ok'), 'stored load of every shift in the green band');
// Réduction d'effectif écrite comme obsada du jour quand il n'y a rien à avancer
$pdo->exec("UPDATE tfb_prod_product_settings SET status = 'draft' WHERE id_item IN ({$itemId}, 50)");
$pdo->exec("UPDATE tfb_prod_team SET planned_headcount = 3 WHERE code = 'T1'");
[$s, $res2] = $call('POST', '/planning/optimizer/apply', ['from' => $wFrom, 'to' => $wTo], $access);
$row = $pdo->query("SELECT headcount, source FROM tfb_prod_staffing WHERE work_date = '{$wFrom}'")->fetch();
check($s === 200 && $res2['data']['mts_orders'] === 0 && $res2['data']['staffing_rows'] === 5 && (int) $row['headcount'] === 2 && $row['source'] === 'optimizer',
    'surplus person removed from each shift (3 → 2), written as staffing of the day');
[$s, $load] = $call('GET', "/planning/load?from={$wFrom}&to={$wFrom}", null, $dir);
check($load['data'][0]['headcount'] === 2 && $load['data'][0]['base_headcount'] === 3 && $load['data'][0]['staffing'] === 'optimizer', 'load uses the staffing of the day');
$pdo->exec("UPDATE tfb_prod_team SET planned_headcount = 2 WHERE code = 'T1'");
$day = array_values($worked)[1]['date'];
[$s, $b] = $call('PUT', '/planning/staffing', ['date' => $day, 'group_id' => 1, 'shift_id' => $jourId, 'headcount' => 3, 'overtime_h' => 2, 'note' => 'urlop'], $access);
check($s === 200 && $b['data']['headcount'] === 3 && $b['data']['available_h'] == 26.0 && $b['data']['staffing'] === 'manual', 'manual staffing for a day');
[$s, $b] = $call('PUT', '/planning/staffing', ['date' => $day, 'group_id' => 1, 'shift_id' => $jourId, 'headcount' => null], $access);
check($s === 200 && $pdo->query("SELECT COUNT(*) FROM tfb_prod_staffing WHERE work_date = '{$day}'")->fetchColumn() == 0 && $b['data']['headcount'] === 2, 'back to the teams');
[$s, $b] = $call('PUT', '/planning/staffing', ['date' => $day, 'group_id' => 999, 'shift_id' => $jourId, 'headcount' => 1], $access);
check($s === 422 && $codes($b) === ['group_id:REFERENCE_NOT_FOUND'], 'unknown group');

// --- P7 : tablettes, identification, pointage, opérations, signature, heures, KPIs
$kiosk = function (string $method, string $path, ?array $body, string $device, ?string $employee = null) use ($kernel): array {
    $server = ['HTTP_ACCEPT_LANGUAGE' => 'pl', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_DEVICE_TOKEN' => $device];
    if ($employee !== null) $server['HTTP_X_EMPLOYEE_TOKEN'] = $employee;
    $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
    $response = $kernel->handle($request, parse_url('/api/v1/production' . $path, PHP_URL_PATH));
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
};
$jan = (int) $pdo->query('SELECT id FROM tfb_prod_employee WHERE id_production_employee = 10')->fetchColumn();
$ewa = (int) $pdo->query('SELECT id FROM tfb_prod_employee WHERE id_production_employee = 11')->fetchColumn();
[$s] = $call('POST', '/kiosk/devices', ['name' => 'Tablet Piekarnia', 'group_id' => 1], $dir);
check($s === 403, 'direction does not register tablets');
[$s, $dev] = $call('POST', '/kiosk/devices', ['name' => 'Tablet Piekarnia', 'group_id' => 1], $access);
check($s === 201 && preg_match('/^[A-Z2-9]{8}$/', $dev['data']['pairing_code']) === 1, 'tablet registered, one-time pairing code');
check($pdo->query("SELECT pairing_code_hash FROM tfb_prod_device WHERE id = {$dev['data']['id']}")->fetchColumn() === hash('sha256', $dev['data']['pairing_code']), 'only the hash of the code is stored');
[$s, $b] = $call('POST', '/kiosk/pair', ['code' => 'WRONGCOD']);
check($s === 422 && $codes($b) === ['code:PAIRING_CODE_INVALID'], 'wrong pairing code');
[$s, $pair] = $call('POST', '/kiosk/pair', ['code' => strtolower(substr($dev['data']['pairing_code'], 0, 4)) . '-' . substr($dev['data']['pairing_code'], 4)]);
check($s === 200 && strlen($pair['data']['device_token']) === 64 && $pair['data']['device']['group_id'] === 1, 'tablet paired (code case and dash tolerated)');
$device = $pair['data']['device_token'];
[$s] = $call('POST', '/kiosk/pair', ['code' => $dev['data']['pairing_code']]);
check($s === 422, 'pairing code used once');
[$s, $b] = $kiosk('GET', '/kiosk/people', null, 'bad-token');
check($s === 401 && $b['description'] === 'DEVICE_UNAUTHENTICATED', 'unknown tablet refused');
[$s, $people] = $kiosk('GET', '/kiosk/people', null, $device);
check($s === 200 && in_array($jan, array_column($people['data'], 'id'), true) && !$people['data'][array_search($jan, array_column($people['data'], 'id'))]['has_pin'], 'people of the tablet group listed');

[$s, $b] = $call('PUT', "/kiosk/employees/{$jan}/credentials", ['badge' => '04:A3:7F:11', 'pin' => '12'], $access);
check($s === 422 && $codes($b) === ['pin:INVALID_PIN'], 'PIN of 4 to 6 digits');
[$s, $b] = $call('PUT', "/kiosk/employees/{$jan}/credentials", ['badge' => '04:A3:7F:11', 'pin' => '2468'], $access);
check($s === 200 && $b['data']['has_badge'] && $b['data']['has_pin'] && !$b['data']['biometric_consent'], 'badge and PIN set');
$stored = $pdo->query("SELECT badge_hash, pin_hash FROM tfb_prod_employee_credential WHERE id_employee = {$jan}")->fetch();
check(!str_contains($stored['badge_hash'], '04A37F11') && strlen($stored['badge_hash']) === 64 && str_starts_with($stored['pin_hash'], '$2y$'), 'badge and PIN never stored in clear');
[$s, $b] = $call('PUT', "/kiosk/employees/{$ewa}/credentials", ['badge' => '04a37f11'], $access);
check($s === 422 && $codes($b) === ['badge:BADGE_TAKEN'], 'one badge per person');
[$s, $b] = $kiosk('POST', '/kiosk/identify', ['badge' => 'FFFF', 'pin' => '2468'], $device);
check($s === 401 && $b['description'] === 'BADGE_UNKNOWN', 'unknown badge');
[$s, $b] = $kiosk('POST', '/kiosk/identify', ['employee_id' => $ewa, 'pin' => '1111'], $device);
check($s === 409 && $b['description'] === 'PIN_NOT_SET', 'no PIN yet');
[$s, $b] = $kiosk('POST', '/kiosk/identify', ['badge' => '04a37f11', 'pin' => '0000'], $device);
check($s === 401 && $b['description'] === 'PIN_INVALID' && str_contains($b['message'], '4'), 'wrong PIN, attempts left');
for ($i = 0; $i < 3; $i++) $kiosk('POST', '/kiosk/identify', ['badge' => '04a37f11', 'pin' => '0000'], $device);
[$s, $b] = $kiosk('POST', '/kiosk/identify', ['badge' => '04a37f11', 'pin' => '0000'], $device);
check($s === 423 && $b['description'] === 'EMPLOYEE_LOCKED', '5 wrong PINs: locked');
[$s, $b] = $kiosk('POST', '/kiosk/identify', ['badge' => '04a37f11', 'pin' => '2468'], $device);
check($s === 423, 'locked even with the right PIN');
$call('PUT', "/kiosk/employees/{$jan}/credentials", ['unlock' => true], $access);
[$s, $id] = $kiosk('POST', '/kiosk/identify', ['badge' => '04 a3 7f 11', 'pin' => '2468'], $device);
check($s === 200 && $id['data']['employee']['id'] === $jan && $id['data']['expires_in'] === 120, 'identified by badge + PIN after unlock');
$emp = $id['data']['employee_token'];
[$s, $b] = $kiosk('GET', '/kiosk/me', null, $device);
check($s === 401 && $b['description'] === 'EMPLOYEE_UNAUTHENTICATED', 'personal actions need the person token');

// Opérations du jour : la tablette montre de maintenant − 6 h à + 24 h ; on place deux opérations du groupe de la
// tablette dans cette fenêtre (le plan de test est daté, l'heure d'exécution du test ne doit pas compter).
$pdo->exec("UPDATE tfb_prod_operation op JOIN tfb_prod_workstation w ON w.id = op.id_workstation SET op.planned_start = NOW() + INTERVAL 1 HOUR, op.planned_end = NOW() + INTERVAL 2 HOUR
            WHERE w.id_group = 1 AND op.status = 'planned' ORDER BY op.id LIMIT 2");
[$s, $me] = $kiosk('GET', '/kiosk/me', null, $device, $emp);
check($s === 200 && !$me['data']['clocked_in'] && count($me['data']['operations']) > 0, 'my day: not clocked in, operations of my group');
$opId = $me['data']['operations'][0]['id'];
[$s, $b] = $kiosk('POST', "/kiosk/operations/{$opId}/start", [], $device, $emp);
check($s === 409 && $b['description'] === 'NOT_CLOCKED_IN', 'clock in before starting work');
$uuid = '7d9f0c2e-1a2b-4c3d-8e9f-0a1b2c3d4e5f';
[$s, $in] = $kiosk('POST', '/kiosk/clock', ['client_uuid' => $uuid], $device, $emp);
check($s === 200 && $in['data']['clock']['clock_out'] === null && !$in['data']['replayed'], 'one tap: clock in');
[$s, $b] = $kiosk('POST', '/kiosk/clock', ['client_uuid' => $uuid], $device, $emp);
check($s === 200 && $b['data']['replayed'] && $b['data']['clock']['id'] === $in['data']['clock']['id'], 'replayed tap (offline tablet) creates nothing twice');
[$s, $b] = $kiosk('POST', '/kiosk/clock', ['direction' => 'in'], $device, $emp);
check($s === 409 && $b['description'] === 'CLOCK_ALREADY_IN', 'already in');
[$s, $op] = $kiosk('POST', "/kiosk/operations/{$opId}/start", [], $device, $emp);
check($s === 200 && $op['data']['status'] === 'in_progress' && $op['data']['working'] === [$jan] && $op['data']['actual_start'] !== null, 'operation started by the person');
[$s, $op] = $kiosk('POST', "/kiosk/operations/{$opId}/pause", null, $device, $emp);
check($s === 200 && $op['data']['status'] === 'paused' && $op['data']['working'] === [], 'paused');
$kiosk('POST', "/kiosk/operations/{$opId}/start", [], $device, $emp);
$pdo->exec("UPDATE tfb_prod_time_log SET started_at = NOW() - INTERVAL 20 MINUTE WHERE id_operation = {$opId} AND ended_at IS NULL");
[$s, $b] = $kiosk('POST', "/kiosk/operations/{$opId}/finish", ['qty_good' => 90, 'qty_scrap' => 2], $device, $emp);
check($s === 422 && $codes($b) === ['scrap_reason:REQUIRED'], 'scrap needs a reason');
$studies = (int) $pdo->query('SELECT COUNT(*) FROM tfb_prod_time_study')->fetchColumn();
[$s, $op] = $kiosk('POST', "/kiosk/operations/{$opId}/finish", ['qty_good' => 90, 'qty_scrap' => 2, 'scrap_reason' => 'przypalone'], $device, $emp);
check($s === 200 && $op['data']['status'] === 'done' && $op['data']['qty_good'] == 90.0 && $op['data']['qty_scrap'] == 2.0, 'operation finished with quantities');
$opKind = $pdo->query("SELECT kind FROM tfb_prod_operation WHERE id = {$opId}")->fetchColumn();
check($opKind !== 'work' || (int) $pdo->query('SELECT COUNT(*) FROM tfb_prod_time_study')->fetchColumn() === $studies + 1, 'real time of a work operation becomes a time study (P2)');
[$s, $b] = $kiosk('POST', "/kiosk/operations/{$opId}/start", [], $device, $emp);
check($s === 409 && $b['description'] === 'OPERATION_NOT_STARTABLE', 'a finished operation does not restart');
$other = (int) $pdo->query("SELECT op.id FROM tfb_prod_operation op WHERE op.status = 'planned' ORDER BY op.id LIMIT 1")->fetchColumn();
$kiosk('POST', "/kiosk/operations/{$other}/start", [], $device, $emp);
[$s, $out] = $kiosk('POST', '/kiosk/clock', [], $device, $emp);
check($s === 200 && $out['data']['clock']['clock_out'] !== null && $out['data']['signature_required'], 'second tap: clock out, signature asked');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_time_log WHERE id_employee = {$jan} AND ended_at IS NULL")->fetchColumn() === 0
    && $pdo->query("SELECT status FROM tfb_prod_operation WHERE id = {$other}")->fetchColumn() === 'paused', 'clocking out closes my running work');
[$s, $b] = $kiosk('POST', '/kiosk/signatures', ['purpose' => 'clock_out', 'ref' => (string) $out['data']['clock']['id'], 'image' => 'data:image/png;base64,AAAA'], $device, $emp);
check($s === 422 && $codes($b) === ['image:INVALID_IMAGE'], 'signature must be a PNG');
$img = imagecreatetruecolor(300, 120);
imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
imageline($img, 10, 100, 290, 20, imagecolorallocate($img, 0, 0, 0));
ob_start();
imagepng($img);
$png = ob_get_clean();
[$s, $sig] = $kiosk('POST', '/kiosk/signatures', ['purpose' => 'clock_out', 'ref' => (string) $out['data']['clock']['id'], 'image' => 'data:image/png;base64,' . base64_encode($png)], $device, $emp);
check($s === 201 && $sig['data']['sha256'] === hash('sha256', $png), 'handwritten signature stored with its SHA-256');
[$s, $b] = $kiosk('POST', '/kiosk/signatures', ['purpose' => 'clock_out', 'ref' => '999999', 'image' => 'data:image/png;base64,' . base64_encode($png)], $device, $emp);
check($s === 422 && $codes($b) === ['ref:SIGNATURE_REF_INVALID'], 'signature only on my own entries');
$month = date('Y-m');
$kiosk('POST', '/kiosk/signatures', ['purpose' => 'timesheet_month', 'ref' => $month, 'image' => 'data:image/png;base64,' . base64_encode($png)], $device, $emp);

// Tablet grupy : produkcja dnia, partie wyrobu, partie surowców (zdjęcie etykiety + OCR) — identyfikowalność.
$pdo->exec("INSERT INTO category_material (id, id_group, name) VALUES (105, 0, 'Mąka'), (200, 1, 'Kartony')");
$pdo->exec("INSERT INTO raw_material (id, name, id_unit, id_category, price_per_unit) VALUES (902, 'Karton 60', 1, 200, 3.7)");
$pdo->exec("UPDATE raw_material SET id_category = 105 WHERE id IN (900, 901)");
$pdo->exec("INSERT INTO flattened_recipe_ingredient (recipe_id, material_id, quantity) VALUES (700, 900, 1500), (700, 901, 300), (700, 902, 1)");
$croissant = (int) $pdo->query('SELECT id FROM tfb_prod_item WHERE id_final_product = 501')->fetchColumn();
$pdo->exec("UPDATE tfb_prod_product_settings SET id_primary_group = 1 WHERE id_item = {$croissant}");
$pdo->exec("INSERT INTO tfb_prod_week_slot (week_start, id_item, work_date, quantity) VALUES ('" . date('Y-m-d', strtotime('monday this week')) . "', {$croissant}, CURDATE(), 120)");
[$s, $b] = $kiosk('GET', '/kiosk/production', null, $device);
check($s === 401 && $b['description'] === 'EMPLOYEE_UNAUTHENTICATED', 'production of the day needs the person');
[$s, $b] = $kiosk('POST', '/kiosk/refresh', null, $device, $emp);
check($s === 200 && $b['data']['employee']['id'] === $jan && $b['data']['employee_token'] !== $emp && $b['data']['expires_in'] === 120, 'person session prolonged while working');
$emp = $b['data']['employee_token'];
[$s, $pd] = $kiosk('GET', '/kiosk/production', null, $device, $emp);
$row = array_values(array_filter($pd['data']['items'] ?? [], fn($i) => $i['item_id'] === $croissant))[0] ?? null;
check($s === 200 && $pd['data']['group']['id'] === 1 && $row && $row['planned'] == 120.0 && $row['produced'] == 0.0 && $row['ppc'] === 60
    && in_array($croissant, array_column($pd['data']['catalog'], 'item_id'), true), 'production of the day: planned from the week Gantt, group catalog');
$batchOf = (float) $pdo->query("SELECT COALESCE(batch_multiple, 0) FROM tfb_prod_product_settings WHERE id_item = {$croissant}")->fetchColumn();
check(($row['batch'] ?? null) == ($batchOf > 0 ? $batchOf : null), 'tablet: production batch of the card (one tile per planned batch)');
[$s, $b] = $kiosk('POST', '/kiosk/production', ['item_id' => $croissant, 'quantity' => 0], $device, $emp);
check($s === 422 && $codes($b) === ['quantity:OUT_OF_RANGE'], 'quantity above zero');
$outUuid = '5b1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d';
[$s, $batch] = $kiosk('POST', '/kiosk/production', ['item_id' => $croissant, 'quantity' => 120, 'client_uuid' => $outUuid], $device, $emp);
$prefix = date('ymd') . '-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string) $pdo->query('SELECT code FROM tfb_prod_group WHERE id = 1')->fetchColumn()), 0, 4)) . '-';
$ing = array_column($batch['data']['ingredients'] ?? [], null, 'material_id');
check($s === 201 && $batch['data']['lot_code'] === $prefix . '01' && $batch['data']['basis'] === 'batch' && count($ing) === 3
    && $ing[900]['quantity'] == 3000.0 && $ing[902]['packaging'] && !$ing[900]['packaging'] && $ing[900]['lot_number'] === null, 'batch recorded: own lot number, recipe scaled to 2 cartons');
[$s, $b] = $kiosk('POST', '/kiosk/production', ['item_id' => $croissant, 'quantity' => 120, 'client_uuid' => $outUuid], $device, $emp);
check($s === 201 && $b['data']['replayed'] && $b['data']['id'] === $batch['data']['id'], 'replayed batch (offline tablet) recorded once');
$batchId = $batch['data']['id'];
$jpg = imagecreatetruecolor(200, 80);
imagefill($jpg, 0, 0, imagecolorallocate($jpg, 255, 255, 255));
imagestring($jpg, 5, 10, 30, 'LOT L2310-A', imagecolorallocate($jpg, 0, 0, 0));
ob_start();
imagejpeg($jpg, null, 80);
$jpeg = ob_get_clean();
[$s, $b] = $kiosk('PUT', "/kiosk/production/{$batchId}/lots/900", ['lot_number' => 'x', 'photo' => 'data:image/jpeg;base64,AAAA'], $device, $emp);
check($s === 422 && $codes($b) === ['photo:INVALID_PHOTO'], 'label photo must be an image');
[$s, $b] = $kiosk('PUT', "/kiosk/production/{$batchId}/lots/999", ['lot_number' => 'x'], $device, $emp);
check($s === 422 && $codes($b) === ['material_id:MATERIAL_NOT_IN_RECIPE'], 'only materials of the recipe');
[$s, $b] = $kiosk('PUT', "/kiosk/production/{$batchId}/lots/900", ['lot_number' => '  l2310-a ', 'best_before' => '2026-12-01',
    'photo' => 'data:image/jpeg;base64,' . base64_encode($jpeg), 'ocr_text' => "MĄKA T65\nLOT L2310-A"], $device, $emp);
$ing = array_column($b['data']['ingredients'] ?? [], null, 'material_id');
$photo = $pdo->query("SELECT p.sha256, p.mime FROM tfb_prod_output_lot l JOIN tfb_prod_lot_photo p ON p.id = l.id_photo WHERE l.id_output = {$batchId} AND l.id_material = 900")->fetch();
check($s === 200 && $ing[900]['lot_number'] === 'L2310-A' && $ing[900]['best_before'] === '2026-12-01' && $ing[900]['photo'] && $b['data']['lots_done'] === 1 && $b['data']['lots_total'] === 3
    && $photo && $photo['sha256'] === hash('sha256', $jpeg) && $photo['mime'] === 'image/jpeg', 'material lot saved with best-before date and label photo');
[$s, $b2] = $kiosk('POST', '/kiosk/production', ['item_id' => $croissant, 'quantity' => 60], $device, $emp);
$ing = array_column($b2['data']['ingredients'] ?? [], null, 'material_id');
check($s === 201 && $b2['data']['lot_code'] === $prefix . '02' && $ing[900]['quantity'] == 1500.0 && ($ing[900]['last']['lot_number'] ?? null) === 'L2310-A', 'next batch: « same as last time » lot suggested');
[$s, $pd] = $kiosk('GET', '/kiosk/production', null, $device, $emp);
$row = array_values(array_filter($pd['data']['items'], fn($i) => $i['item_id'] === $croissant))[0];
check($row['produced'] == 180.0 && count($row['batches']) === 2 && $row['batches'][0]['lots_done'] === 1 && $row['batches'][0]['lots_total'] === 3, 'day: 180 pieces in 2 batches, lots entered counted');
[$s, $b] = $kiosk('PATCH', "/kiosk/production/{$b2['data']['id']}", ['cancelled' => true], $device, $emp);
check($s === 200 && $b['data']['cancelled'] && !$b['data']['editable'], 'batch cancelled');
[$s, $b] = $kiosk('PATCH', "/kiosk/production/{$b2['data']['id']}", ['quantity' => 50], $device, $emp);
check($s === 409 && $b['description'] === 'OUTPUT_CANCELLED', 'a cancelled batch is not edited');
[$s, $pd] = $kiosk('GET', '/kiosk/production', null, $device, $emp);
$row = array_values(array_filter($pd['data']['items'], fn($i) => $i['item_id'] === $croissant))[0];
check($row['produced'] == 120.0, 'cancelled batch not counted');
[$s, $b] = $kiosk('POST', '/kiosk/production', ['item_id' => $croissant, 'quantity' => 10, 'date' => date('Y-m-d', strtotime('-5 days'))], $device, $emp);
check($s === 409 && $b['description'] === 'OUTPUT_LOCKED', 'old days locked on the tablet');
$pdo->exec("INSERT INTO tfb_prod_output (work_date, id_group, id_item, quantity, lot_code) VALUES (CURDATE(), 2, {$croissant}, 5, 'X-OTHER-01')");
$foreign = (int) $pdo->lastInsertId();
[$s, $b] = $kiosk('GET', "/kiosk/production/{$foreign}", null, $device, $emp);
check($s === 403 && $b['description'] === 'OUTPUT_NOT_IN_YOUR_GROUPS', 'batches of another group hidden');
// --- Kontrola jakości na tablecie : partia OK albo niezgodność (rejestr niezgodności ERP)
[$s, $q] = $kiosk('GET', '/kiosk/quality', null, $device, $emp);
$qb = array_column($q['data']['batches'] ?? [], null, 'id');
check($s === 200 && isset($qb[$batchId]) && $qb[$batchId]['qc'] === null && !isset($qb[$b2['data']['id']]) && !isset($qb[$foreign]) && $q['data']['catalog'],
    'quality: batches of the day of the tablet group (not cancelled), not checked yet');
[$s, $b] = $kiosk('POST', "/kiosk/quality/batches/{$batchId}", ['result' => 'nc'], $device, $emp);
check($s === 422 && $codes($b) === ['description:REQUIRED'], 'a non-conformity needs a description');
[$s, $q] = $kiosk('POST', "/kiosk/quality/batches/{$batchId}", ['result' => 'ok', 'note' => 'waga OK'], $device, $emp);
$qb = array_column($q['data']['batches'] ?? [], null, 'id');
check($s === 200 && $qb[$batchId]['qc']['status'] === 'ok' && $qb[$batchId]['qc']['note'] === 'waga OK' && $qb[$batchId]['qc']['employee'] !== null, 'batch checked OK (who, when)');
[$s, $q] = $kiosk('POST', "/kiosk/quality/batches/{$batchId}", ['result' => 'nc', 'description' => 'Za jasne, niedopieczone', 'severity' => 'major'], $device, $emp);
$qb = array_column($q['data']['batches'] ?? [], null, 'id');
$nc = $pdo->query("SELECT * FROM tfb_nc WHERE id = (SELECT id_nc FROM tfb_prod_output WHERE id = {$batchId})")->fetch();
check($s === 200 && $qb[$batchId]['qc']['status'] === 'nc' && $nc && $nc['source'] === 'production' && $nc['severity'] === 'major' && (int) $nc['id_prod_group'] === 1
    && (int) $nc['id_final_product'] === 501 && $nc['quantity'] == 120 && str_contains($nc['description'], $prefix . '01') && $qb[$batchId]['qc']['nc_number'] === $nc['number']
    && in_array($nc['number'], array_column($q['data']['reports'], 'number'), true), 'non-conformity of a batch: in the ERP register (production, product, quantity, lot, group)');
[$s, $b] = $kiosk('POST', "/kiosk/quality/batches/{$batchId}", ['result' => 'ok'], $device, $emp);
check($s === 409 && $b['description'] === 'QC_ALREADY_NC', 'a batch with a non-conformity is followed in the ERP');
[$s, $b] = $kiosk('POST', "/kiosk/quality/batches/{$foreign}", ['result' => 'ok'], $device, $emp);
check($s === 403, 'batches of another group are not checked');
[$s, $q] = $kiosk('POST', '/kiosk/quality/reports', ['item_id' => $croissant, 'description' => 'Folia rozdarta na rolce', 'severity' => 'minor'], $device, $emp);
check($s === 201 && count($q['data']['reports']) === 2 && (int) $pdo->query("SELECT COUNT(*) FROM tfb_nc WHERE source = 'production' AND id_prod_group = 1")->fetchColumn() === 2,
    'non-conformity reported from the tablet without a batch');
$pdo->exec("UPDATE tfb_prod_device SET id_group = NULL WHERE id = {$dev['data']['id']}");
[$s, $b] = $kiosk('GET', '/kiosk/production', null, $device, $emp);
check($s === 409 && $b['description'] === 'DEVICE_WITHOUT_GROUP', 'tablet without group');
$pdo->exec("UPDATE tfb_prod_device SET id_group = 1 WHERE id = {$dev['data']['id']}");
$pdo->exec("DELETE FROM tfb_prod_week_slot WHERE id_item = {$croissant}");
// Plan : sztuki do produkcji ÷ szt./karton × receptura na karton = surowce planu (półprodukty już w recepturze wypłaszczonej).
$finalCroissant = (float) $pdo->query("SELECT SUM(qty_final) FROM tfb_prod_plan_line WHERE id_plan = {$planId} AND id_item = {$croissant}")->fetchColumn();
[$s, $pl] = $call('GET', "/planning/plans/{$planId}", null, $access);
$mat = array_column($pl['data']['materials'] ?? [], null, 'material_id');
check($s === 200 && $finalCroissant > 0 && isset($mat[900], $mat[902]) && abs($mat[900]['quantity'] - $finalCroissant / 60 * 1500) < 0.01 && $mat[902]['packaging'] && !$mat[900]['packaging']
    && abs($mat[900]['value'] - $finalCroissant / 60 * 1500 * 0.004) < 0.01, 'plan materials: pieces ÷ pieces per carton × recipe per carton, with value');

[$s, $b] = $call('PATCH', "/kiosk/clocks/{$out['data']['clock']['id']}", ['clock_in' => date('Y-m-d H:i', strtotime('-2 hours'))], $access);
check($s === 422 && $codes($b) === ['note:REQUIRED'], 'a correction needs a reason');
[$s, $b] = $call('PATCH', "/kiosk/clocks/{$out['data']['clock']['id']}", ['clock_in' => date('Y-m-d H:i:s', strtotime($out['data']['clock']['clock_out']) - 7200), 'note' => 'zapomniał odbić'], $access);
check($s === 200 && $b['data']['corrected_by'] === 1, 'clock corrected by the manager');
$prevMonth = date('Y-m', strtotime('first day of last month'));
[$s, $b] = $call('POST', '/kiosk/clocks', ['employee_id' => $jan, 'clock_in' => $prevMonth . '-10 21:00', 'clock_out' => $prevMonth . '-11 07:00', 'note' => 'zmiana nocna'], $access);
check($s === 201, 'manual entry added');
[$s, $ts] = $call('GET', "/kiosk/employees/{$jan}/timesheet?month={$prevMonth}", null, $access);
check($s === 200 && $ts['data']['totals']['hours'] == 10.0 && $ts['data']['totals']['night_hours'] == 8.0 && $ts['data']['days'][0]['source'] === 'manual', 'timesheet: 10 h of which 8 h at night (22:00–06:00)');
[$s, $ts] = $call('GET', "/kiosk/employees/{$jan}/timesheet?month={$month}", null, $access);
check($s === 200 && $ts['data']['totals']['hours'] == 2.0 && $ts['data']['totals']['signed'] === 1 && $ts['data']['days'][0]['corrected'] && $ts['data']['month_signature'] !== null, 'timesheet of the month: corrected 2 h, signed day and signed month');
[$s, $list] = $call('GET', "/kiosk/employees?month={$month}&group_id=1", null, $access);
$row = array_values(array_filter($list['data'], fn($r) => $r['employee_id'] === $jan))[0] ?? null;
check($s === 200 && $row && $row['hours'] == 2.0 && $row['month_signed'], 'monthly summary per person');
[$s, $b] = $call('GET', "/kiosk/employees/{$jan}/timesheet?month=2026-13", null, $access);
check($s === 422, 'month checked');

// Téléphone : QR changeant de la tablette, enregistrement nom + PIN, entrée / sortie en un geste
$phone = function (string $method, string $path, ?array $body, string $qr, ?string $phoneToken = null) use ($kernel): array {
    $server = ['HTTP_ACCEPT_LANGUAGE' => 'pl', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_QR_TOKEN' => $qr, 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8)'];
    if ($phoneToken !== null) $server['HTTP_X_PHONE_TOKEN'] = $phoneToken;
    $request = Request::create('/api/v1/production' . $path, $method, [], [], [], $server, $body === null ? null : json_encode($body));
    $response = $kernel->handle($request, parse_url('/api/v1/production' . $path, PHP_URL_PATH));
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
};
[$s, $b] = $kiosk('GET', '/kiosk/qr?base=' . rawurlencode('javascript:alert(1)'), null, $device);
check($s === 422 && $codes($b) === ['base:INVALID_URL'], 'QR address must be http(s)');
[$s, $q] = $kiosk('GET', '/kiosk/qr?base=' . rawurlencode('https://erp.example/production/'), null, $device);
$qr = substr($q['data']['url'], strpos($q['data']['url'], '#t=') + 3);
check($s === 200 && str_starts_with($q['data']['url'], 'https://erp.example/production/kiosk/scan#t=') && $q['data']['expires_in'] === 90 && $q['data']['refresh_s'] === 20
    && $q['data']['size'] >= 21 && count($q['data']['rows']) === $q['data']['size'] && strlen($q['data']['rows'][0]) === $q['data']['size'], 'tablet shows a QR of the scan page (matrix) with a short token');
[$s, $b] = $phone('GET', '/kiosk/phone/people', null, 'nonsense');
check($s === 401 && $b['description'] === 'QR_INVALID', 'invalid QR refused');
$expired = (new Jwt(str_repeat('s', 48)))->encode(['aud' => KioskAuthService::QR_AUDIENCE, 'dev' => $dev['data']['id'], 'iat' => time() - 200, 'nbf' => time() - 200, 'exp' => time() - 100]);
[$s, $b] = $phone('GET', '/kiosk/phone/people', null, $expired);
check($s === 401 && $b['description'] === 'QR_EXPIRED', 'a photo of an old QR does not work');
[$s, $b] = $phone('GET', '/kiosk/phone/people', null, $emp);
check($s === 401 && $b['description'] === 'QR_INVALID', 'a person token is not a QR');
[$s, $pp] = $phone('GET', '/kiosk/phone/people', null, $qr);
check($s === 200 && in_array($jan, array_column($pp['data'], 'id'), true) && !array_key_exists('clocked_in', $pp['data'][0]), 'first scan: people of the tablet group, presence not shown');
[$s, $b] = $phone('GET', '/kiosk/phone/me', null, $qr);
check($s === 401 && $b['description'] === 'PHONE_UNAUTHENTICATED', 'clocking needs a registered phone');
[$s, $b] = $phone('POST', '/kiosk/phone/enroll', ['employee_id' => $jan, 'pin' => '9999'], $qr);
check($s === 401 && $b['description'] === 'PIN_INVALID', 'phone registration checks the PIN');
[$s, $en] = $phone('POST', '/kiosk/phone/enroll', ['employee_id' => $jan, 'pin' => '2468'], $qr);
$phoneToken = $en['data']['phone_token'] ?? '';
check($s === 201 && strlen($phoneToken) === 64 && $en['data']['employee']['id'] === $jan && $en['data']['expires_in_days'] === 180, 'phone registered with name + PIN');
$storedPhone = $pdo->query("SELECT token_hash, label FROM tfb_prod_phone WHERE id_employee = {$jan}")->fetch();
check($storedPhone['token_hash'] === hash('sha256', $phoneToken) && str_contains($storedPhone['label'], 'Android'), 'phone token stored only as a hash, with the device description');
[$s, $pm] = $phone('GET', '/kiosk/phone/me', null, $qr, $phoneToken);
check($s === 200 && !$pm['data']['clocked_in'] && $pm['data']['employee']['id'] === $jan && !isset($pm['data']['operations']), 'phone: my clock state only');
[$s, $b] = $phone('GET', '/kiosk/phone/me', null, '', $phoneToken);
check($s === 401 && $b['description'] === 'QR_INVALID', 'no clocking from home: a fresh QR of the tablet is needed');
[$s, $pin] = $phone('POST', '/kiosk/phone/clock', ['client_uuid' => '8e9f0c2e-1a2b-4c3d-8e9f-0a1b2c3d4e5f'], $qr, $phoneToken);
check($s === 200 && $pin['data']['clock']['clock_out'] === null
    && (int) $pdo->query("SELECT id_device FROM tfb_prod_clock WHERE id = {$pin['data']['clock']['id']}")->fetchColumn() === $dev['data']['id'], 'one tap on the phone: clock in, recorded at the tablet of the QR');
[$s, $pout] = $phone('POST', '/kiosk/phone/clock', [], $qr, $phoneToken);
check($s === 200 && $pout['data']['clock']['clock_out'] !== null && $pout['data']['signature_required'], 'second tap: clock out, signature asked');
[$s] = $phone('POST', '/kiosk/phone/signatures', ['purpose' => 'clock_out', 'ref' => (string) $pout['data']['clock']['id'], 'image' => 'data:image/png;base64,' . base64_encode($png)], $qr, $phoneToken);
check($s === 201, 'finger signature on the phone');
[$s, $list] = $call('GET', "/kiosk/employees?month={$month}&group_id=1", null, $access);
check((array_values(array_filter($list['data'], fn($r) => $r['employee_id'] === $jan))[0]['phones'] ?? null) === 1, 'manager sees the registered phone');
[$s, $r] = $call('DELETE', "/kiosk/employees/{$jan}/phones", null, $dir);
check($s === 403, 'direction cannot revoke phones');
[$s, $r] = $call('DELETE', "/kiosk/employees/{$jan}/phones", null, $access);
check($s === 200 && $r['data']['revoked'] === 1, 'manager revokes the phone');
[$s, $b] = $phone('POST', '/kiosk/phone/clock', [], $qr, $phoneToken);
check($s === 401 && $b['description'] === 'PHONE_REVOKED', 'revoked phone cannot clock');

[$s, $k] = $call('GET', '/execution/kpis?from=' . date('Y-m-d') . '&to=' . date('Y-m-d', strtotime('+6 days')), null, $dir);
check($s === 200 && $k['data']['totals']['operations_done'] >= 1 && $k['data']['totals']['qty_scrap'] == 2.0 && abs($k['data']['totals']['scrap_pct'] - 2 / 92 * 100) < 0.1, 'KPIs: done operations, scrap rate');
check(isset($k['data']['groups'][0]['efficiency_pct']) && array_key_exists('otd_pct', $k['data']['totals']), 'KPIs by group, on-time delivery');
[$s, $c] = $call('GET', '/execution/cockpit', null, $dir);
check($s === 200 && isset($c['data']['operations']['late']) && array_key_exists('punctuality_pct', $c['data']['week']) && $c['data']['people_present'] === 0, 'cockpit of the day');
[$s] = $call('GET', '/execution/cockpit', null, $lead);
check($s === 200, 'team lead reads the cockpit');

[$s, $b] = $call('PATCH', "/kiosk/devices/{$dev['data']['id']}", ['active' => false], $access);
[$s, $b] = $kiosk('GET', '/kiosk/people', null, $device);
check($s === 403 && $b['description'] === 'DEVICE_DISABLED', 'blocked tablet refused');
[$s, $re] = $call('PATCH', "/kiosk/devices/{$dev['data']['id']}", ['active' => true, 'new_pairing_code' => true], $access);
[$s, $b] = $kiosk('GET', '/kiosk/people', null, $device);
check($s === 401 && isset($re['data']['pairing_code']), 'new pairing code revokes the old tablet token');
[$s, $devices] = $call('GET', '/kiosk/devices', null, $access);
check($devices['data'][0]['pairing_pending'] && !$devices['data'][0]['paired'], 'device list shows pending pairing');

// --- Gantt tygodnia : produkty na dni (przeciągnij i upuść), « Rozdziel na dni », godziny i wykonanie
// Wszystkie dni tygodnia produkcyjne : test nie zależy od dnia, w którym działa.
[$s] = $call('PATCH', '/referentiels/params/week.production_days', ['value' => '1,2,3,4,5,6,7'], $access);
check($s === 200, 'production days are a parameter');
$today = date('Y-m-d');
// Tydzień produkcji od week.start_day (w pracowni środa) : $monday = pierwszy dzień tygodnia, $sunday = ostatni.
$weekStartDay = (int) ($pdo->query("SELECT value FROM tfb_prod_param WHERE code = 'week.start_day'")->fetchColumn() ?: 1);
$monday = App\Services\Workshop\Planning\WeekStart::of($today, $weekStartDay);
[$s, $w] = $call('GET', "/planning/week?date={$today}", null, $dir);
$wp = array_column($w['data']['products'] ?? [], null, 'item_id');
check($s === 200 && $w['data']['week_start'] === $monday && count($w['data']['days']) === 7 && $w['data']['source']['type'] === 'plan'
    && in_array($planId, array_column($w['data']['source']['plans'], 'id'), true), 'week: quantities of the validated plan, every day of the week');
check(isset($wp[$itemId]) && $wp[$itemId]['hours'] > 0 && $wp[$itemId]['unassigned_qty'] == $wp[$itemId]['quantity'] && $wp[$itemId]['slots'] === []
    && in_array('UNASSIGNED', array_column($w['data']['warnings'], 'code'), true), 'week: products wait in « to assign » with their standard hours');
$g1 = array_column($w['data']['groups'], null, 'id')[1] ?? null;
$sundayRow = $g1 ? array_values(array_filter($g1['days'], fn($d) => (int) date('N', strtotime($d['date'])) === 7))[0] ?? null : null;
check($g1 !== null && $g1['days'][0]['available_h'] == 16 && $sundayRow && $sundayRow['available_h'] == 0 && $g1['available_h'] == 16 * 6 && $w['data']['totals']['done_h'] > 0,
    'week: hours available = 2 people × 8 h on the days of the shift (no Sunday shift); done hours from finished operations');
check($w['data']['fte_hours'] == 40, 'week: full-time hours (FTE = group hours ÷ 40 h, parameter week.fte_hours)');
[$s, $sh] = $call('GET', "/planning/sheet?date={$today}", null, $dir);
$sr = array_column($sh['data']['rows'] ?? [], null, 'item_id');
check($s === 200 && isset($sr[$itemId]) && $sr[$itemId]['mode'] === 'MTS' && $sr[$itemId]['batch'] == 160 && $sr[$itemId]['stock_max'] !== null
    && $sr[$itemId]['batches'] === (int) ceil($sr[$itemId]['to_produce'] / 160 - 1e-9) && $sr[$itemId]['to_produce'] == $wp[$itemId]['quantity']
    && $sh['data']['fte_hours'] == 40 && $sh['data']['groups'] && $sh['data']['can_edit'] === false,
    'sheet: one line per product (same quantity as the week board, batches = to produce ÷ pieces per batch, rounded up)');
[$s, $b] = $call('GET', '/planning/week?date=jutro', null, $dir);
check($s === 422 && $codes($b) === ['date:INVALID_DATE'], 'week: date checked');
[$s, $b] = $call('POST', '/planning/week/distribute', ['date' => $today], $dir);
check($s === 403, 'direction does not plan the days');
[$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'to' => date('Y-m-d', strtotime($monday . ' +8 days'))], $lead);
check($s === 422 && $codes($b) === ['to:DAY_NOT_IN_WEEK'], 'a product moves only to a day of the week');
if ($today !== $monday) {
    [$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'to' => $monday], $lead);
    check($s === 422 && $codes($b) === ['to:DAY_IN_PAST'], 'no production planned on a past day');
}
[$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => 999, 'to' => $today], $lead);
check($s === 422 && $codes($b) === ['item_id:NOT_IN_WEEK'], 'only products of the week');
[$s, $d] = $call('POST', '/planning/week/distribute', ['date' => $today], $access);
$dp = array_column($d['data']['products'], null, 'item_id');
check($s === 200 && $d['data']['totals']['unassigned'] === 0 && $dp[$itemId]['slots'][0]['day'] >= $today && $dp[$itemId]['unassigned_qty'] == 0
    && !in_array('UNASSIGNED', array_column($d['data']['warnings'], 'code'), true), 'distribute by day: every product on a day from today');
$slotDay = $dp[$itemId]['slots'][0]['day'];
$qty = $dp[$itemId]['slots'][0]['quantity'];
[$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'to' => $slotDay], $lead);
check($s === 422 && $codes($b) === ['from:NOTHING_TO_MOVE'], 'nothing left in « to assign »');
[$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'from' => $slotDay, 'to' => null, 'quantity' => $qty + 1], $lead);
check($s === 422 && $codes($b) === ['quantity:OUT_OF_RANGE'], 'cannot move more than the block');
[$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'from' => $slotDay, 'to' => null, 'quantity' => 16], $lead);
$bp = array_column($b['data']['products'], null, 'item_id')[$itemId];
check($s === 200 && $bp['unassigned_qty'] == 16 && $bp['slots'][0]['quantity'] == $qty - 16, 'team lead puts part of a block back to « to assign »');
$sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
if ($slotDay < $sunday) {
    $other = date('Y-m-d', strtotime($slotDay . ' +1 day'));
    [$s, $b] = $call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'from' => $slotDay, 'to' => $other], $lead);
    $bp = array_column($b['data']['products'], null, 'item_id')[$itemId];
    check($s === 200 && count($bp['slots']) === 1 && $bp['slots'][0]['day'] === $other && $bp['slots'][0]['quantity'] == $qty - 16, 'drag and drop: the block moves to another day');
    check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_audit_log WHERE entity = 'week_slot' AND action = 'move'")->fetchColumn() === 2, 'day moves are audited');
    // Uszeregowanie : zlecenie MTS kończy się najpóźniej w dniu z Gantta tygodnia.
    $mtsOrder = (int) $pdo->query("SELECT id FROM tfb_prod_order WHERE id_plan = {$planId} AND origin = 'MTS'")->fetchColumn();
    [$s, $b] = $call('POST', "/planning/plans/{$planId}/schedule", null, $access);
    $due = $pdo->query("SELECT due_at FROM tfb_prod_order WHERE id = {$mtsOrder}")->fetchColumn();
    $lastEnd = $pdo->query("SELECT MAX(planned_end) FROM tfb_prod_operation WHERE id_order = {$mtsOrder} AND status = 'planned'")->fetchColumn();
    check($s === 200 && $due === $other . ' 23:59:00' && ($lastEnd === null || $lastEnd <= $due), 'scheduling: the MTS order is due on its day of the week Gantt');
}
[$s, $r] = $call('POST', '/planning/week/distribute', ['date' => $today, 'reset' => true], $access);
$rp = array_column($r['data']['products'], null, 'item_id');
check($s === 200 && $rp[$itemId]['unassigned_qty'] == 0 && $rp[$itemId]['slots'][0]['day'] === $slotDay, 'distribute again from scratch');
// Najpierw wysyłka (zamówienia, przed wyjazdem), magazyn tylko w dni magazynowe : tu tylko ostatni dzień tygodnia.
// Dzień magazynowy = ostatni dzień tygodnia produkcji (nigdy przed dzisiaj, niezależnie od dnia testu).
$weekSunday = date('Y-m-d', strtotime($monday . ' +6 days'));
$call('PATCH', '/referentiels/params/week.stock_days', ['value' => (string) date('N', strtotime($weekSunday))], $access);
[$s, $r] = $call('POST', '/planning/week/distribute', ['date' => $today, 'reset' => true], $access);
$stockP = array_values(array_filter($r['data']['products'] ?? [], fn($p) => $p['stock'] > 0 && $p['direct'] == 0));
$directP = array_values(array_filter($r['data']['products'] ?? [], fn($p) => $p['direct'] > 0));
$stockDay = array_values(array_filter($r['data']['days'], fn($d) => $d['stock_day']));
check($s === 200 && count($stockDay) === 1 && $stockDay[0]['date'] === $weekSunday && $stockP && $directP
    && array_sum(array_map(fn($p) => count(array_filter($p['slots'], fn($sl) => $sl['day'] !== $weekSunday)), $stockP)) === 0
    && array_sum(array_map(fn($p) => $p['stock_qty'] ?? array_sum(array_column($p['slots'], 'stock_qty')), $stockP)) == array_sum(array_column($stockP, 'stock')),
    'stock products only on stock days');
check(array_sum(array_map(fn($p) => count(array_filter($p['slots'], fn($sl) => $sl['direct_qty'] > 0 && $sl['day'] > $p['latest_day'])), $directP)) === 0
    && $r['data']['totals']['direct_h'] > 0 && abs($r['data']['totals']['direct_h'] + $r['data']['totals']['stock_h'] - $r['data']['totals']['hours']) < 0.05,
    'shipping first: orders never after the truck day; hours split shipping / stock');
$rp = array_column($r['data']['products'], null, 'item_id');
check($rp[$itemId]['batch'] == 160 && $rp[$itemId]['batches'] == $rp[$itemId]['quantity'] / 160 && $rp[$semiId]['batch'] == 5
    && array_sum(array_column($rp[$semiId]['slots'], 'quantity')) == $rp[$semiId]['quantity'] && fmod($rp[$semiId]['quantity'], 5.0) == 0.0,
    'week: production batch of the product card, quantities in whole batches');
$call('PATCH', '/referentiels/params/week.stock_days', ['value' => '3,4,5'], $access);

// --- 📌 Stałe partie co tydzień : blok w dniu przypięty → ten dzień tygodnia w każdym kolejnym tygodniu
[$s, $w] = $call('GET', "/planning/week?date={$today}", null, $access);
$wp = array_column($w['data']['products'], null, 'item_id')[$itemId];
$pinDay = $wp['slots'][0]['day'];
$pinQty = $wp['slots'][0]['quantity'];
$emptyDay = array_values(array_filter(array_column($w['data']['days'], 'date'), fn($d) => !in_array($d, array_column($wp['slots'], 'day'), true)))[0];
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'pinned' => true], $dir);
check($s === 403, 'direction does not pin batches');
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $emptyDay, 'pinned' => true], $access);
check($s === 422 && $codes($b) === ['day:NOTHING_ON_DAY'], 'only a block placed on a day can be pinned');
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'pinned' => false], $access);
check($s === 422 && $codes($b) === ['day:NOT_PINNED'], 'nothing to unpin');
[$s, $b] = $call('POST', '/planning/week/time', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'time' => '25:00'], $access);
check($s === 422 && $codes($b) === ['time:INVALID_TIME'], 'start time checked');
[$s, $b] = $call('POST', '/planning/week/time', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'time' => '05:30'], $access);
$bp = array_column($b['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
check($s === 200 && $bp && array_column($bp['slots'], 'time', 'day')[$pinDay] === '05:30', 'typical week: start time of the block on its day');
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'pinned' => true], $access);
$bp = array_column($b['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
check($s === 200 && $bp && count($bp['pins']) === 1 && $bp['pins'][0]['weekday'] === (int) date('N', strtotime($pinDay)) && $bp['pins'][0]['quantity'] == $pinQty
    && $bp['fixed_qty'] == $pinQty && $bp['pins'][0]['filled'] == $pinQty && array_column($b['data']['pins'], 'item_id') === [$itemId] && $bp['pins'][0]['time'] === '05:30',
    'pin: the block becomes a fixed batch, every week on this weekday at its start time');
// Tydzień później : partie tygodnia (na żywo) wchodzą najpierw na przypięty dzień, do miejsca (ilość przypięta w pełnych
// partiach) ; nadmiar czeka w « Do zrobienia » ; przypięcie nie dokłada produkcji.
$nextDay = date('Y-m-d', strtotime($pinDay . ' +7 days'));
$room = ceil($pinQty / 160) * 160;
$onDay = fn($p, $day) => $p ? array_sum(array_map(fn($x) => $x['day'] === $day ? $x['quantity'] : 0, $p['slots'])) : 0;
$need = function (string $day) use ($call, $access, $itemId) {
    [, $w] = $call('GET', "/planning/week?date={$day}", null, $access);
    [, $dm] = $call('GET', '/planning/demand?from=' . $w['data']['week_start'] . '&to=' . $w['data']['week_end'], null, $access);
    $lines = array_filter($dm['data']['lines'] ?? [], fn($l) => $l['item_id'] === $itemId);
    return [array_sum(array_column($lines, 'qty_proposed')), array_sum(array_column($lines, 'qty_fixed'))];
};
// Potrzeba na następny tydzień : wysoki stan min./maks. (uzupełnienie kilku partii).
$pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = 5000, stock_max = 5000 WHERE id_item = {$itemId}");
[$qNext, $fixedNext] = $need($nextDay);
[$s, $n] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
$np = array_column($n['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
$noShift = in_array('FIXED_NO_SHIFT', array_column($n['data']['warnings'] ?? [], 'code'), true);
$expect = $noShift ? 0.0 : min($room, $qNext);
check($s === 200 && $n['data']['source']['type'] === 'demand' && $fixedNext == 0 && $qNext > $room && $np && $np['quantity'] == $qNext,
    'a pin adds no production: the week quantity is the live need (orders + refill, whole batches)');
check($onDay($np, $nextDay) == $expect && abs($np['unassigned_qty'] - ($qNext - $expect)) < 0.001 && $np['pins'][0]['filled'] == $expect && $np['fixed_qty'] == $expect,
    'next week: the batches of the week go first on the pinned day (up to its room), the rest waits in « to do »');
check($onDay($np, $nextDay) == 0 || array_column($np['slots'], 'time', 'day')[$nextDay] === '05:30', 'next week: the pinned day keeps its start time');
[$s, $b] = $call('POST', '/planning/week/time', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'time' => '06:00'], $access);
check($s === 200 && array_column($b['data']['products'], null, 'item_id')[$itemId]['pins'][0]['time'] === '06:00', 'changing the time of a pinned block changes the fixed batch time');
[$s, $n1] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
$np1 = array_column($n1['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
check($onDay($np1, $nextDay) == 0 || array_column($np1['slots'], 'time', 'day')[$nextDay] === '06:00', '… also in the later week where the batch is already placed');
if (!$noShift) {
    $weekday = (int) date('N', strtotime($pinDay));
    // Więcej miejsca niż partii : dzień dostaje wszystkie partie tygodnia, nic nie czeka, miejsce zostaje częściowo wolne.
    $pdo->exec("UPDATE tfb_prod_fixed_batch SET quantity = " . ($qNext + 3 * 160) . " WHERE id_item = {$itemId} AND weekday = {$weekday}");
    [, $n2] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
    $np2 = array_column($n2['data']['products'], null, 'item_id')[$itemId];
    check($onDay($np2, $nextDay) == $qNext && $np2['unassigned_qty'] == 0 && $np2['pins'][0]['filled'] == $qNext && $np2['pins'][0]['room'] == $qNext + 3 * 160,
        'not enough batches for the pinned room: the day gets what the week needs, the rest of the room stays free');
    // Na żywo : potrzeba spada do zera (stan maks. = 0) → przypięty dzień pustoszeje ; wraca, gdy potrzeba wraca.
    $pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = NULL, stock_max = 0 WHERE id_item = {$itemId}");
    [, $n3] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
    $np3 = array_column($n3['data']['products'], null, 'item_id')[$itemId] ?? null;
    $emptied = (float) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM tfb_prod_week_slot WHERE week_start = '{$n3['data']['week_start']}' AND id_item = {$itemId} AND work_date = '{$nextDay}'")->fetchColumn();
    $pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = 5000, stock_max = 5000 WHERE id_item = {$itemId}");
    [, $n4] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
    $np4 = array_column($n4['data']['products'], null, 'item_id')[$itemId];
    check($np3 === null && $emptied == 0 && $onDay($np4, $nextDay) == $qNext, 'live: no need → the pinned day is empty; the need comes back → the batches go back on the pinned day');
    $pdo->exec("UPDATE tfb_prod_fixed_batch SET quantity = {$pinQty} WHERE id_item = {$itemId} AND weekday = {$weekday}");
    [, $n5] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
    $np5 = array_column($n5['data']['products'], null, 'item_id')[$itemId];
    check($onDay($np5, $nextDay) == $expect && abs($np5['unassigned_qty'] - ($qNext - $expect)) < 0.001, 'smaller pin again: the surplus is back in « to do »');
    // Partia zdjęta ręcznie z przypiętego dnia : w tym tygodniu dzień nie bierze już partii sam ; « Ułóż tydzień » znów go wypełnia.
    $call('POST', '/planning/week/move', ['date' => $nextDay, 'item_id' => $itemId, 'from' => $nextDay, 'to' => null], $access);
    [, $n6] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
    $np6 = array_column($n6['data']['products'], null, 'item_id')[$itemId];
    check($onDay($np6, $nextDay) == 0 && $np6['unassigned_qty'] == $qNext && $np6['pins'][0]['released'], 'a pinned batch taken off its day by hand does not come back by itself that week');
    [$s, $n7] = $call('POST', '/planning/week/distribute', ['date' => $nextDay], $access);
    $np7 = array_column($n7['data']['products'], null, 'item_id')[$itemId];
    check($s === 200 && $onDay($np7, $nextDay) >= $expect && !$np7['pins'][0]['released'] && $np7['pins'][0]['filled'] == $expect, '« arrange the week » fills the pinned day first again');
}
[$s, $dm] = $call('GET', '/planning/demand?from=' . $n['data']['week_start'] . '&to=' . $n['data']['week_end'], null, $access);
check(array_sum(array_column(array_filter($dm['data']['lines'] ?? [], fn($l) => $l['item_id'] === $itemId), 'qty_proposed')) == $qNext,
    'proposal: unchanged by the pin (no minimum from fixed batches)');
[, $before] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
$bpNext = array_column($before['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
$placedNext = (float) $pdo->query("SELECT COALESCE(SUM(placed), 0) FROM tfb_prod_fixed_batch_week WHERE week_start > '{$monday}' AND id_item = {$itemId}")->fetchColumn();
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $pinDay, 'pinned' => false], $access);
$bp = array_column($b['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
check($s === 200 && $bp && $bp['pins'] === [] && $b['data']['pins'] === [] && $onDay($bp, $pinDay) == $pinQty, 'unpin: this week stays as it is');
[$s, $n3] = $call('GET', "/planning/week?date={$nextDay}", null, $access);
$np3 = array_column($n3['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
check($s === 200 && abs($onDay($np3, $nextDay) - ($onDay($bpNext, $nextDay) - $placedNext)) < 0.001
    && (int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_fixed_batch_week WHERE week_start > '{$monday}'")->fetchColumn() === 0,
    'unpin: what the pin put in the later week is undone (what was placed by hand stays)');
check((int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_audit_log WHERE entity = 'fixed_batch'")->fetchColumn() === 2, 'pin and unpin are audited');
$pdo->exec("UPDATE tfb_prod_product_settings SET stock_min = 60, stock_max = 300 WHERE id_item = {$itemId}");
// « Wyczyść tydzień » : wszystko z dni od dzisiaj wraca do « Do zrobienia »
[$s, $b] = $call('POST', '/planning/week/clear', ['date' => $today], $dir);
check($s === 403, 'direction does not clear the week');
[$s, $b] = $call('POST', '/planning/week/clear', ['date' => $today], $access);
$left = array_sum(array_map(fn($p) => count(array_filter($p['slots'], fn($x) => $x['day'] >= $today)), $b['data']['products'] ?? []));
check($s === 200 && $left === 0 && $b['data']['totals']['unassigned'] > 0, 'clear the week: every product from today is back in « to do »');
// Przypięty blok zdjęty z dnia, potem odpięty : partia tego dnia nie liczy się już w tygodniu (bez podwójnej ilości)
$call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'to' => $today], $access);
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $today, 'pinned' => true], $access);
$call('POST', '/planning/week/move', ['date' => $today, 'item_id' => $itemId, 'from' => $today, 'to' => null], $access);
[$s, $b] = $call('POST', '/planning/week/pin', ['date' => $today, 'item_id' => $itemId, 'day' => $today, 'pinned' => false], $access);
$bp = array_column($b['data']['products'] ?? [], null, 'item_id')[$itemId] ?? null;
$others = (float) $pdo->query("SELECT COALESCE(SUM(placed), 0) FROM tfb_prod_fixed_batch_week WHERE week_start = '{$monday}' AND id_item = {$itemId} AND work_date <> '{$today}'")->fetchColumn();
$todayMarks = (int) $pdo->query("SELECT COUNT(*) FROM tfb_prod_fixed_batch_week WHERE week_start = '{$monday}' AND id_item = {$itemId} AND work_date = '{$today}'")->fetchColumn();
check($s === 200 && $bp && $todayMarks === 0 && abs($bp['fixed_qty'] - $others) < 0.001 && $bp['pins'] === [],
    'pinned block moved off its day then unpinned: no fixed batch left on that day this week');

// Pracownik dodany w ERP : obsada zmiany dziennej grupy działu nadąża (osoby × 40 h w Gantcie tygodnia).
$pdo->exec("INSERT INTO production_employee (id, name, surname, cost, id_section) VALUES (12, 'Ola', 'Wiśniewska', 5100, 1)");
(new App\Repositories\Employee\ProductionEmployeeRepository($pdo))->syncWorkshopHeadcount();
check((int) $pdo->query("SELECT planned_headcount FROM tfb_prod_team WHERE code = 'T1'")->fetchColumn() === 3, 'day team headcount follows the employees of the section');

// Czas partii w arkuszu : osoby × minuty = roboczogodziny na partię ; godziny tygodnia = partie × to (zamiast marszruty)
[, $sh] = $call('GET', "/planning/sheet?date={$today}", null, $access);
$row = array_column($sh['data']['rows'] ?? [], null, 'item_id')[$itemId] ?? null;
[$s, $b] = $call('PATCH', "/produits/items/{$itemId}/settings", ['batch_people' => 3, 'batch_minutes' => 0], $access);
check($s === 422 && $codes($b) === ['batch_minutes:OUT_OF_RANGE'], 'batch time must be more than 0 minutes');
[$s] = $call('PATCH', "/produits/items/{$itemId}/settings", ['batch_people' => 3, 'batch_minutes' => 90], $access);
[, $sh2] = $call('GET', "/planning/sheet?date={$today}", null, $access);
$row2 = array_column($sh2['data']['rows'] ?? [], null, 'item_id')[$itemId] ?? null;
check($s === 200 && $row && $row['hours_source'] === 'routing' && $row2 && $row2['hours_source'] === 'batch' && $row2['batch_hours'] == 4.5 && $row2['to_produce'] > 0
    && abs($row2['hours'] - $row2['to_produce'] / 160 * 4.5) < 0.01, 'batch time: 3 people × 90 min = 4.5 h per batch; week hours = batches × 4.5 h');
$call('PATCH', "/produits/items/{$itemId}/settings", ['batch_people' => null, 'batch_minutes' => null], $access);

// --- Kierownik produkcji : plan, cele (zatwierdzone przed okresem), raport, HACCP, premia, rozmowa, audyt kompetencji
$year = (int) date('Y');
[$s] = $call('GET', "/manager/plan?year={$year}", null, $lead);
check($s === 403, 'a team lead does not see the production manager module');
[$s, $b] = $call('GET', "/manager/plan?year={$year}", null, $access);
check($s === 200 && !$b['data']['plan']['exists'] && count($b['data']['indicators']) === 12 && $b['data']['plan']['mission_hours_total'] == 168
    && !$b['data']['can_write'] && $b['data']['can_report'], 'manager plan: 12 indicators, 168 h a month split by mission; the holder reports but does not set his objectives');
[$s] = $call('PUT', '/manager/plan', ['year' => $year, 'salary_monthly' => 10000], $access);
check($s === 403, 'the holder cannot set his own pay');
[$s, $b] = $call('PUT', '/manager/plan', ['year' => $year, 'mission_hours' => ['cooking' => 5]], $dir);
check($s === 422 && $codes($b) === ['mission_hours:INVALID_JSON_VALUE'], 'hours only for the missions of the job');
[$s, $b] = $call('PUT', '/manager/plan', ['year' => $year, 'holder_user_id' => 1, 'holder_name' => 'Jan K.', 'holder_start' => "{$year}-01-01", 'salary_monthly' => 10000,
    'envelope_pct' => 20, 'margin_threshold_pct' => 30, 'audit_threshold' => 80, 'auditor' => 'Audit Sp. z o.o.', 'price_reference_date' => "{$year}-01-01",
    'workplace' => 'Wrocław', 'mission_hours' => ['production' => 60, 'development' => 30, 'purchasing' => 20, 'staffing' => 15, 'logistics' => 23, 'haccp' => 20]], $dir);
$mp = $b['data']['plan'] ?? [];
check($s === 200 && $mp['envelope_annual'] == 24000 && $mp['monthly_at_target'] == 12000 && $mp['monthly_at_cap'] == 12400 && $mp['mission_hours']['production'] == 60
    && $mp['mission_hours_total'] == 168, 'plan: 10 000 fixed + 20 % variable = 12 000 gross a month at 100 %, 12 400 at the 120 % cap; hours per mission');
// Cele : szkic, zatwierdzenie ; cel zatwierdzony po początku kwartału nie liczy się do premii tego kwartału
[$s, $b] = $call('PUT', '/manager/objectives', ['year' => $year, 'objectives' => ['otif_pct' => 95, 'nope' => 1]], $dir);
check($s === 422 && $codes($b) === ['objectives.nope:UNKNOWN_FIELD'], 'objectives only for the indicators of the job');
$targets = ['material_cost_pct' => 30, 'loss_pct' => 3, 'range_margin_pct' => 60, 'new_profitable_pct' => 70, 'below_threshold_pct' => 10, 'price_gap_pct' => 2,
    'stockouts' => 0, 'stock_days' => 20, 'staff_cost_per_carton' => 5, 'overtime_hours' => 10, 'otif_pct' => 95, 'logistics_cost_pct' => 8];
[$s, $b] = $call('PUT', '/manager/objectives', ['year' => $year, 'objectives' => $targets], $dir);
check($s === 200 && $b['data']['drafts'] === 12 && array_column($b['data']['indicators'], 'validated') === array_fill(0, 12, null), 'twelve draft objectives, none validated yet');
$quarter = $year . '-Q' . (intdiv((int) date('n') - 1, 3) + 1);
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $access);
check($s === 200 && $bn['data']['sections'] && !array_filter($bn['data']['sections'], fn($x) => $x['status'] !== 'no_objective') && $bn['data']['total'] == 0,
    'no validated objective: no bonus for any section');
[$s, $b] = $call('POST', '/manager/objectives/validate', ['year' => $year], $dir);
check($s === 200 && $b['data']['drafts'] === 0 && $b['data']['indicators'][0]['validated']['target'] == 30, 'objectives validated');
[$s, $b] = $call('POST', '/manager/objectives/validate', ['year' => $year], $dir);
check($s === 409 && $b['description'] === 'MANAGER_NO_DRAFTS', 'nothing left to validate');
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $access);
check(!array_filter($bn['data']['sections'], fn($x) => $x['status'] !== 'no_objective'), 'objectives validated after the start of the quarter do not count for this quarter');
// Cele zatwierdzone przed kwartałem : sekcje liczone z danych ERP (albo brak danych — wtedy brak premii, bez zgadywania)
$pdo->exec("UPDATE tfb_mgr_objective SET validated_at = '{$year}-01-01 00:00:00'");
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $access);
$statuses = array_column($bn['data']['sections'], 'status', 'code');
check($s === 200 && !in_array('no_objective', $statuses, true) && $statuses['purchasing'] === 'missing_data' && $bn['data']['sections'][0]['provisional'],
    'objectives validated before the quarter: sections computed; stock-outs not entered → no purchasing bonus; the quarter is not over (provisional)');
// Wpis ręczny osoby na stanowisku = niezweryfikowany
$month = date('Y-m');
[$s, $b] = $call('PUT', '/manager/values', ['month' => $month, 'indicator' => 'material_cost_pct', 'value' => 10], $access);
check($s === 422 && $codes($b) === ['indicator:INVALID_CHOICE'], 'no manual value for an indicator the ERP computes');
[$s, $r] = $call('PUT', '/manager/values', ['month' => $month, 'indicator' => 'stockouts', 'value' => 1, 'note' => 'brak mąki'], $access);
$so = array_column($r['data']['indicators'] ?? [], null, 'code')['stockouts'] ?? null;
check($s === 200 && $so && $so['value'] == 1.0 && $so['origin'] === 'manual' && $so['unverified'] && $so['status'] === 'red'
    && in_array(['code' => 'UNVERIFIED_VALUE', 'indicator' => 'stockouts'], $r['data']['missing'], true), 'stock-out entered by the holder: counted, flagged as not verified, red against 0');
// HACCP : kontrola niekorzystna blokuje całą premię okresu ; alert HACCP pierwszy
[$s, $b] = $call('POST', '/manager/haccp', ['event_date' => date('Y-m-d'), 'kind' => 'official_control', 'authority' => 'Sanepid'], $access);
check($s === 422 && $codes($b) === ['result:REQUIRED'], 'an official control needs its result');
[$s, $b] = $call('POST', '/manager/haccp', ['event_date' => date('Y-m-d'), 'kind' => 'official_control', 'authority' => 'Sanepid', 'result' => 'unfavorable', 'description' => 'Brak zapisów temperatury'], $access);
$hid = $b['data']['id'] ?? 0;
check($s === 201 && $b['data']['unverified'], 'HACCP event recorded (entered by the holder: not verified)');
[$s, $r] = $call('GET', "/manager/report?month={$month}", null, $dir);
check($s === 200 && $r['data']['alerts'][0]['level'] === 'haccp' && $r['data']['alerts'][0]['code'] === 'OFFICIAL_CONTROL_UNFAVORABLE' && count($r['data']['indicators']) === 12
    && in_array('NO_AUDIT_QUARTER', array_column($r['data']['missing'], 'code'), true), 'monthly report: HACCP alert first, 12 indicators, missing independent audit reported');
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $dir);
check($bn['data']['haccp']['blocked'] && !array_filter($bn['data']['sections'], fn($x) => $x['status'] !== 'haccp_blocked') && $bn['data']['total'] == 0,
    'HACCP condition: an unfavorable official control blocks the whole bonus of the period');
[$s] = $call('DELETE', "/manager/haccp/{$hid}", null, $access);
check($s === 403, 'the holder cannot delete a HACCP event');
[$s] = $call('POST', '/manager/haccp', ['event_date' => date('Y-m-d'), 'kind' => 'cold_chain_break', 'description' => 'Chłodnia 2 : −12 °C przez 3 h'], $dir);
[$s, $b] = $call('DELETE', "/manager/haccp/{$hid}", null, $dir);
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $dir);
check($bn['data']['haccp']['blocked'] && $bn['data']['haccp']['blocking'][0]['code'] === 'COLD_CHAIN_UNTREATED', 'an untreated cold-chain break blocks the bonus');
$cid = $bn['data']['haccp']['blocking'][0]['event_id'];
[$s, $b] = $call('PATCH', "/manager/haccp/{$cid}", ['treated' => true, 'treated_at' => date('Y-m-d')], $dir);
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $dir);
check($s === 200 && !$bn['data']['haccp']['blocked'], 'treated cold-chain break: the bonus is no longer blocked');
[$s, $b] = $call('POST', '/manager/haccp', ['event_date' => date('Y-m-d'), 'kind' => 'independent_audit', 'score' => 75], $dir);
[$s, $bn] = $call('GET', "/manager/bonus?period={$quarter}", null, $dir);
check($bn['data']['haccp']['blocked'] && $bn['data']['haccp']['blocking'][0]['code'] === 'AUDIT_BELOW_THRESHOLD', 'independent audit at 75 % under the 80 % threshold blocks the bonus');
$pdo->exec('DELETE FROM tfb_mgr_haccp_event');
// Nowy produkt : cele wprowadzenia i zalecana decyzja
$pdo->exec("UPDATE final_product SET create_timestamp = NOW() - INTERVAL 1 YEAR");
[$s, $b] = $call('PUT', '/manager/launches/501', ['launch_date' => date('Y-m-d', strtotime('-10 weeks')), 'min_margin_pct' => 99, 'expected_week_shop' => 1000, 'target_price' => 40], $access);
[$s2, $r] = $call('GET', "/manager/report?month={$month}", null, $dir);
$np = array_column($r['data']['products'] ?? [], null, 'product_id')[501] ?? null;
check($s === 200 && $np && $np['weeks'] >= 9 && $np['recommendation'] === 'stop', 'new product 10 weeks after launch, under its minimum margin and volume: stop recommended');
[$s] = $call('PUT', '/manager/launches/999999', ['launch_date' => date('Y-m-d')], $access);
check($s === 404, 'launch targets only for an existing product');
// Trzy działania
[$s, $b] = $call('PUT', '/manager/actions', ['month' => $month, 'actions' => array_fill(0, 4, ['text' => 'x'])], $access);
check($s === 422 && $codes($b) === ['actions:TOO_MANY'], 'three priority actions at most');
[$s, $r] = $call('PUT', '/manager/actions', ['month' => $month, 'actions' => [['text' => 'Zapas bezpieczeństwa mąki T65 : 2 tygodnie', 'owner' => 'Jan K.', 'due_date' => date('Y-m-t', strtotime('+1 month'))]]], $access);
check($s === 200 && !$r['data']['actions_suggested'] && $r['data']['actions'][0]['owner'] === 'Jan K.', 'actions of the month saved');
// Rozmowa : 3 miesiące
[$s, $b] = $call('GET', "/manager/interview?month={$month}", null, $dir);
check($s === 200 && count($b['data']['months']) === 3 && $b['data']['months'][2] === $month && count($b['data']['indicators']) === 12
    && in_array('stockouts', array_column($b['data']['indicators'], 'code'), true), 'interview: last 3 months, strengths, recurring gaps, proposed objectives');
// Audyt kompetencji : wszystkie pytania, skala 0–3, odchylenia poniżej poziomu oczekiwanego
[$s, $q] = $call('GET', '/manager/audits', null, $access);
$questions = array_merge(...array_values($q['data']['areas']));
check($s === 200 && count($q['data']['areas']) === 9 && count($questions) >= 20 && $q['data']['expected']['lang_pl'] === 3, 'competency questionnaire: 9 areas of the job');
[$s, $b] = $call('POST', '/manager/audits', ['audit_date' => date('Y-m-d'), 'assessed_name' => 'Jan K.', 'answers' => ['qual_haccp' => ['score' => 2]]], $dir);
check($s === 422 && $codes($b) === ['answers:INCOMPLETE'], 'every question must be answered');
$answers = array_fill_keys($questions, ['score' => 2]);
$answers['qual_haccp'] = ['score' => 1, 'comment' => 'Zapisy temperatur niekompletne'];
$answers['qual_audit'] = ['score' => 4];
[$s, $b] = $call('POST', '/manager/audits', ['audit_date' => date('Y-m-d'), 'assessed_name' => 'Jan K.', 'answers' => $answers], $dir);
check($s === 422 && $codes($b) === ['answers.qual_audit:OUT_OF_RANGE'], 'scores from 0 to 3');
$answers['qual_audit'] = ['score' => 3];
[$s, $b] = $call('POST', '/manager/audits', ['audit_date' => date('Y-m-d'), 'assessed_name' => 'Jan K.', 'answers' => $answers, 'comment' => 'Pierwszy audyt'], $dir);
$a = $b['data']['audits'][0] ?? null;
check($s === 201 && $a && array_column($a['gaps'], 'question') === ['qual_haccp', 'lang_pl'] && abs($a['areas']['quality'] - 2.0) < 1e-9,
    'competency audit saved: gaps under the expected level (HACCP 1/2, native Polish 2/3), score by area');
[$s, $b] = $call('GET', "/manager/interview?month={$month}", null, $dir);
check($b['data']['competency']['id'] === $a['id'], 'interview shows the latest competency audit');

// --- Zaopatrzenie : potrzeby z arkusza tygodnia, tryb i stany surowców, zamówienia do dostawców (mail), przyjęcia
$pdo->exec("INSERT INTO supplier (id, name, email, is_active) VALUES (60, 'Młyn test', 'mlyn@supplier.test', 1), (61, 'Pakcer test', '', 1)");
$pdo->exec("UPDATE raw_material SET id_current_supplier = 60, package_size_in_base_unit = 25000 WHERE id = 900");
$pdo->exec("INSERT INTO unit (id, name) VALUES (5, 'szt') ON DUPLICATE KEY UPDATE name = name");
$pdo->exec("UPDATE raw_material SET id_current_supplier = 61, package_size_in_base_unit = 100, id_unit = 5 WHERE id = 902");
$pdo->exec("UPDATE raw_material SET id_current_supplier = NULL WHERE id = 901");
[$s] = $call('GET', '/supply/needs', null, $lead);
check($s === 403, 'supply needs its own permission (team lead has none)');
[, $sh] = $call('GET', "/planning/sheet?date={$today}", null, $access);
$cartons = (array_column($sh['data']['rows'], null, 'item_id')[$itemId]['to_produce'] ?? 0) / 60;
[$s, $n] = $call('GET', "/supply/needs?date={$today}&weeks=1", null, $access);
$flour = array_column($n['data']['rows'] ?? [], null, 'id')[900] ?? null;
check($s === 200 && $cartons > 0 && $flour && abs($flour['need'] - $cartons * 1500) < 0.01 && $flour['unit'] === 'kg' && $flour['factor'] === 1000 && $flour['mode'] === 'order',
    'need = to produce ÷ pieces per carton × recipe per carton (grams shown in kg)');
check($flour['stock'] === null && $flour['to_order'] >= $flour['need'] && fmod($flour['to_order'], 25000) == 0 && $flour['packages'] === (int) ceil(round($flour['need'] / 25000, 6))
    && in_array('Masło', $n['data']['warnings']['no_supplier'], true), 'on-demand material: the missing quantity rounded up to full supplier packages (25 kg); material without supplier flagged');
[$s, $b] = $call('GET', "/supply/needs?date={$today}&weeks=9", null, $access);
check($s === 422 && $codes($b) === ['weeks:OUT_OF_RANGE'], 'horizon of 1 to 4 weeks');
// Na stanie : min. / maks., inwentaryzacja (stan na koniec dnia), zużycie z produkcji po niej
[$s, $b] = $call('PATCH', '/supply/materials/900', ['supply_mode' => 'stock', 'stock_min' => 50000, 'stock_max' => 10000], $access);
check($s === 422 && $codes($b) === ['stock_max:STOCK_MAX_BELOW_MIN'], 'stock max below min refused');
[$s, $m] = $call('PATCH', '/supply/materials/900', ['supply_mode' => 'stock', 'stock_min' => 50000, 'stock_max' => 200000], $lead);
check($s === 403, 'team lead cannot change supply settings');
[$s, $m] = $call('PATCH', '/supply/materials/900', ['supply_mode' => 'stock', 'stock_min' => 50000, 'stock_max' => 200000], $access);
check($s === 200 && $m['data']['mode'] === 'stock' && $m['data']['stock_max'] == 200000 && $m['data']['stock'] === null, 'material switched to stock with min / max');
[$s, $b] = $call('POST', '/supply/materials/900/count', ['quantity' => 30000, 'count_date' => date('Y-m-d', strtotime('+1 day'))], $access);
check($s === 422 && $codes($b) === ['count_date:IN_FUTURE'], 'no inventory in the future');
// Kartony croissanta : 1 w dniu inwentaryzacji (już w stanie na koniec dnia), 2 dziś (zużycie po inwentaryzacji).
$pdo->exec("INSERT INTO carton_stock (id_carton, batch_number, id_product, status, date_add) VALUES (9001, 9, 501, 1, CURDATE() - INTERVAL 1 DAY), (9002, 9, 501, 1, CURDATE()), (9003, 9, 501, 1, CURDATE())");
[$s, $m] = $call('POST', '/supply/materials/900/count', ['quantity' => 30000, 'count_date' => date('Y-m-d', strtotime('-1 day'))], $access);
$made = (int) $pdo->query("SELECT COUNT(*) FROM carton_stock WHERE id_product = 501 AND date_add > CURDATE() - INTERVAL 1 DAY")->fetchColumn();
check($s === 201 && $made === 2 && abs($m['data']['stock'] - (30000 - $made * 1500)) < 0.01 && $m['data']['stock_basis'] === 'count', 'stock = count + receipts − production after the count day (cartons × recipe)');
[$s, $n] = $call('GET', "/supply/needs?date={$today}&weeks=1", null, $access);
$flour = array_column($n['data']['rows'], null, 'id')[900];
$after = max(0, $flour['stock']) - $flour['need'];
$expect = $after < 50000 ? ceil(round((200000 - $after) / 25000, 6)) * 25000 : 0;
check(abs($flour['available_after'] - $after) < 0.01 && abs($flour['to_order'] - $expect) < 0.01 && abs($flour['value'] - $flour['to_order'] * 0.004) < 0.01,
    'stocked material: under min after production → refill up to max, in full packages, valued at the catalogue price');
// Zamówienia : jedno na dostawcę, mail, przyjęcia (ilości i ceny)
[$s, $b] = $call('POST', '/supply/orders', ['lines' => [['raw_material_id' => 901, 'quantity' => 1000]]], $access);
check($s === 422 && $b['description'] === 'SUPPLY_NO_SUPPLIER' && str_contains($b['message'], 'Masło'), 'a material without a supplier cannot be ordered');
[$s, $b] = $call('POST', '/supply/orders', ['lines' => [['raw_material_id' => 900, 'quantity' => 0], ['raw_material_id' => 999999, 'quantity' => 5]]], $access);
check($s === 422 && $codes($b) === ['lines.0.quantity:GREATER_THAN', 'lines.1.raw_material_id:REFERENCE_NOT_FOUND'], 'order lines validated');
[$s, $c] = $call('POST', '/supply/orders', ['week_start' => $n['data']['date'], 'lines' => [['raw_material_id' => 900, 'quantity' => 50000], ['raw_material_id' => 902, 'quantity' => 300]]], $access);
$bySupplier = array_column(array_map(fn($o) => $o + ['sid' => $o['supplier']['id']], $c['data']['orders'] ?? []), null, 'sid');
$flourPo = $bySupplier[60] ?? null;
$boxPo = $bySupplier[61] ?? null;
$delivery = max(date('Y-m-d', strtotime('tomorrow')), date('Y-m-d', strtotime($n['data']['date'] . ' -1 day')));
check($s === 201 && $flourPo && $boxPo && $flourPo['status'] === 'draft' && $flourPo['email_to'] === 'mlyn@supplier.test' && $boxPo['email_to'] === null
    && $flourPo['lines'][0]['package_size'] == 25000 && $flourPo['lines'][0]['unit_price'] == 0.004 && $flourPo['delivery_date'] === $delivery && preg_match('/^ZD-\d{8}-\d{3}$/', $flourPo['code']),
    'one draft order per supplier, catalogue price, delivery the day before the production week');
[, $n2] = $call('GET', "/supply/needs?date={$today}&weeks=1", null, $access);
check(abs(array_column($n2['data']['rows'], null, 'id')[900]['on_order'] - 50000) < 0.01, 'ordered quantities count as on order (no double proposal)');
[$s, $p] = $call('GET', "/supply/orders/{$flourPo['id']}/preview", null, $access);
check($s === 200 && str_contains($p['data']['body'], 'Mąka T65 — 50 kg (2 × 25 kg)') && str_contains($p['data']['subject'], $flourPo['code']) && $p['data']['recipient'] === 'mlyn@supplier.test',
    'e-mail preview: lines in kg with supplier packages');
[$s, $b] = $call('POST', "/supply/orders/{$boxPo['id']}/send", [], $access);
check($s === 422 && $b['description'] === 'PO_NO_EMAIL', 'no supplier e-mail: cannot send by e-mail');
[$s, $b] = $call('PATCH', "/supply/orders/{$boxPo['id']}", ['email_to' => 'not-an-email'], $access);
check($s === 422 && $codes($b) === ['email_to:INVALID_EMAIL'], 'order e-mail address validated');
[$s, $b] = $call('PATCH', "/supply/orders/{$boxPo['id']}", ['email_to' => 'biuro@pakcer.test', 'save_supplier_email' => true, 'language' => 'fr'], $access);
check($s === 200 && $b['data']['email_to'] === 'biuro@pakcer.test' && $b['data']['language'] === 'fr'
    && $pdo->query('SELECT email FROM supplier WHERE id = 61')->fetchColumn() === 'biuro@pakcer.test', 'address saved on the order and at the supplier');
[$s, $p] = $call('GET', "/supply/orders/{$boxPo['id']}/preview", null, $access);
check(str_starts_with($p['data']['body'], 'Bonjour') && str_contains($p['data']['body'], 'Karton 60 — 300 szt (3 × 100 szt)'), 'French e-mail for a French order');
[$s, $b] = $call('POST', "/supply/orders/{$flourPo['id']}/receive", ['lines' => [['id' => $flourPo['lines'][0]['id'], 'quantity' => 10]]], $access);
check($s === 409 && $b['description'] === 'PO_NOT_RECEIVABLE', 'a draft order cannot be received');
[$s, $sent] = $call('POST', "/supply/orders/{$flourPo['id']}/send", [], $access);
$msg = $pdo->query("SELECT m.recipient_email, m.status, m.message_type, COUNT(a.id) AS files FROM communication_message m LEFT JOIN communication_message_attachment a ON a.id_message = m.id WHERE m.id = " . (int) ($pdo->query("SELECT id_message FROM tfb_po WHERE id = {$flourPo['id']}")->fetchColumn() ?: 0) . ' GROUP BY m.id')->fetch();
check($s === 200 && $sent['data']['status'] === 'sent' && $sent['data']['sent_by'] === 'email' && $msg && $msg['recipient_email'] === 'mlyn@supplier.test'
    && $msg['status'] === 'queued' && $msg['message_type'] === 'supplier_order' && (int) $msg['files'] === 1, 'order sent: e-mail queued (CSV attached), nothing sent from the test');
[$s, $b] = $call('PATCH', "/supply/orders/{$flourPo['id']}", ['note' => 'x'], $access);
check($s === 409 && $b['description'] === 'PO_NOT_EDITABLE', 'a sent order is no longer editable');
[$s, $b] = $call('POST', "/supply/orders/{$boxPo['id']}/send", ['manual' => true], $access);
check($s === 200 && $b['data']['status'] === 'sent' && $b['data']['sent_by'] === 'manual', 'order placed by phone: marked as sent without e-mail');
$lineId = $flourPo['lines'][0]['id'];
[$s, $r] = $call('POST', "/supply/orders/{$flourPo['id']}/receive", ['lines' => [['id' => $lineId, 'quantity' => 25000, 'unit_price' => 0.0044]]], $access);
$l = $r['data']['lines'][0] ?? null;
check($s === 200 && $r['data']['status'] === 'partial' && $l && $l['received_qty'] == 25000 && $l['remaining'] == 25000 && abs($l['price_gap_pct'] - 10) < 0.01,
    'partial delivery at a higher price: received, remaining and price gap (+10 %)');
[, $mats] = $call('GET', '/supply/materials', null, $access);
$fm = array_column($mats['data']['rows'], null, 'id')[900];
check(abs($fm['stock'] - (30000 - $made * 1500 + 25000)) < 0.01 && abs($fm['on_order'] - 25000) < 0.01, 'the receipt adds to stock, the rest stays on order');
[$s, $r] = $call('POST', "/supply/orders/{$flourPo['id']}/receive", ['received_on' => date('Y-m-d'), 'lines' => [['id' => $lineId, 'quantity' => 25000]]], $access);
check($s === 200 && $r['data']['status'] === 'received' && $r['data']['lines'][0]['received_qty'] == 50000 && count($r['data']['receipts']) === 2, 'second delivery completes the order');
[$s, $list] = $call('GET', '/supply/orders?status=all', null, $access);
$sum = array_column($list['data']['orders'] ?? [], null, 'id')[$flourPo['id']] ?? null;
check($s === 200 && $sum && $sum['received_pct'] == 100 && $sum['price_gaps'] === 1 && abs($sum['value_ordered'] - 200) < 0.01 && abs($sum['value_received'] - 210) < 0.01,
    'order list: received %, price gaps, ordered 200 / received 210 PLN');
[$s, $b] = $call('GET', '/supply/orders?status=bad', null, $access);
check($s === 422, 'unknown status filter refused');
[$s, $b] = $call('DELETE', "/supply/orders/{$flourPo['id']}", null, $access);
check($s === 409, 'only a draft can be deleted');
[$s, $c] = $call('POST', '/supply/orders', ['lines' => [['raw_material_id' => 902, 'quantity' => 100]]], $access);
$draft = $c['data']['orders'][0]['id'];
[$s, $b] = $call('PUT', "/supply/orders/{$draft}/lines", ['lines' => [['raw_material_id' => 902, 'quantity' => 200, 'unit_price' => 3.5], ['raw_material_id' => 902, 'quantity' => 1]]], $access);
check($s === 422 && $codes($b) === ['lines.1.raw_material_id:DUPLICATE_IN_LIST'], 'a material appears once per order');
[$s, $b] = $call('PUT', "/supply/orders/{$draft}/lines", ['lines' => [['raw_material_id' => 902, 'quantity' => 200, 'unit_price' => 3.5]]], $access);
check($s === 200 && $b['data']['lines'][0]['quantity'] == 200 && $b['data']['lines'][0]['unit_price'] == 3.5 && $b['data']['value_ordered'] == 700, 'draft lines replaced');
[$s, $b] = $call('DELETE', "/supply/orders/{$draft}", null, $access);
check($s === 200 && $b['data']['deleted'] && (int) $pdo->query("SELECT COUNT(*) FROM tfb_po WHERE id = {$draft}")->fetchColumn() === 0, 'draft deleted');
[$s, $b] = $call('POST', "/supply/orders/{$boxPo['id']}/cancel", null, $access);
check($s === 200 && $b['data']['status'] === 'cancelled', 'sent order cancelled');
[$s] = $call('GET', '/supply/orders/999999', null, $access);
check($s === 404, 'unknown order → 404');

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
