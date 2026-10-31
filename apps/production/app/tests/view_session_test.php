<?php
declare(strict_types=1);
/*
 * [239] Sesja pod awatarem : l'administrateur voit l'ERP comme un poste (Produkcja, Administracja i finanse…) —
 * menu (rail, liens), page d'accueil, « Mój tydzień » / « Moje zadania » du poste ; les droits ne changent pas ;
 * refus pour un non-administrateur, une session inconnue ou un poste d'atelier — sur une base jetable.
 *
 *   php tests/view_session_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_view_session_test est recréée puis supprimée.
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\Web\ViewSession;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\PositionTaskService;

function expectVs(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_view_session_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "view_session_test: PARTIAL (bez bazy : {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['user', 'n_user_position', 'n_position', 'n_position_task', 'n_task_category', 'n_task_occurrence', 'n_task_form', 'n_prod_param',
                 'n_prod_user_role', 'n_page_access', 'n_position_home', 'n_position_sheet', 'n_prod_employee', 'n_activity_view', 'n_task_cron_run',
                 'app_notification', 'n_prod_group', 'n_prod_day_close', 'n_prod_day_line'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    $pdo->exec("INSERT INTO n_prod_param (code, value, default_value, value_type) VALUES ('tasks.workdays', '1,2,3,4,5', '1,2,3,4,5', 'string')");
    $pdo->exec("INSERT INTO user (id, login, displayed_name) VALUES (1, 'admin', 'Admin'), (2, 'ola', 'Ola K.'), (3, 'sam', 'S. V.')");
    $pdo->exec("INSERT INTO n_prod_user_role (id_user, role_code) VALUES (1, 'prod_admin'), (3, 'prod_admin')");
    $pdo->exec("INSERT INTO n_task_category (id, code, name_pl, name_fr, icon, sort_order, active) VALUES (1, 'FIN', 'Finanse', 'Finances', 'bi-cash', 10, 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, sort_order, active) VALUES
        (1, 'ZAKUPY', 'Specjalista ds. zakupów', 'Achats', 'erp', 1, 0), (3, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Chef', 'erp', 2, 1),
        (5, 'ADMINISTRACJA', 'Administracja i fakturowanie', 'Administration', 'erp', 3, 1), (7, 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', 4, 1)");
    $pdo->exec("INSERT INTO n_user_position (id_user, id_position, since) VALUES (2, 5, '2026-01-01')");
    $pdo->exec("INSERT INTO n_page_access (id_position, page_key) VALUES (5, 'margins'), (5, 'payments_day'), (3, 'capacity_full')");
    $pdo->exec("INSERT INTO n_position_home (id_position, home_page) VALUES (5, '/finances/invoices')");
    $pdo->exec("INSERT INTO n_position_task (id, id_position, id_category, title_pl, title_fr, frequency, minutes, sort_order, active) VALUES
        (1, 5, 1, 'Faktury dnia', 'Factures', 'daily', 15, 1, 1), (2, 3, 1, 'Zebranie produkcyjne', 'Réunion', 'weekly', 30, 1, 1)");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $access = fn() => $container->make(PageAccessService::class); // nowa instancja = bez pamięci podręcznej
    $_SESSION = [];
    $invPath = '/finances/invoices';
    $capPath = '/production/capacity/full';
    expectVs(App\Core\Web\PageAccess::keyFor($invPath) === 'margins' && App\Core\Web\PageAccess::keyFor($capPath) === 'capacity_full', 'klucze stron testu');

    // --- Pełny widok ---------------------------------------------------------------------------------------------------------
    $a = $access();
    expectVs($a->railPrefixes(1) === [] && $a->homePath(1) === '/dashboard', 'administrator : pełny widok, start Rentowność');
    $m = $a->sessionMenu(1);
    expectVs($m['can_switch'] && $m['label'] === 'Administrator' && $m['current'] === null, 'menu : administrator, bez sesji');
    expectVs(array_column($m['sessions'], 'name') === ['Pełny widok', 'Produkcja', 'Administracja i finanse'], 'sesje : pełny widok + stanowiska ERP aktywne (bez pracowni i nieaktywnych) ' . json_encode(array_column($m['sessions'], 'name'), JSON_UNESCAPED_UNICODE));
    $adm = $m['sessions'][2];
    expectVs($adm['id'] === 5 && str_contains($adm['sub'], 'Administracja i fakturowanie') && str_contains($adm['sub'], '2 strony') && str_contains($adm['sub'], 'start : Faktury i koszty'), 'opis sesji : ' . $adm['sub']);

    // --- Sesja Administracja i finanse ---------------------------------------------------------------------------------------
    $home = $a->switchSession(1, 5);
    expectVs(ViewSession::position() === 5 && $home === '/finances/invoices', 'zmiana sesji → strona startowa stanowiska');
    $a = $access();
    $rail = $a->railPrefixes(1);
    expectVs($rail !== [] && ($rail['/finances'] ?? null) === true && ($rail['/production/capacity/full'] ?? null) === false, 'rail : strony stanowiska');
    expectVs($a->menuSees(1, $invPath) && !$a->menuSees(1, $capPath) && $a->menuSees(1, '/today'), 'menu : jak stanowisko (wspólne strony widoczne)');
    expectVs($a->canSee(1, $capPath) && $a->isAdmin(1), 'uprawnienia bez zmian (administrator)');
    $m = $a->sessionMenu(1);
    expectVs($m['label'] === 'Sesja : Administracja i finanse' && $m['current'] === 5 && $m['sessions'][2]['current'] && !$m['sessions'][0]['current'], 'menu : sesja zaznaczona');
    $tasks = $container->make(PositionTaskService::class);
    $my = $tasks->myTasks(1, new DateTimeImmutable('2026-11-04'));
    $titles = [];
    foreach ($my['groups'] as $g) foreach ($g['items'] as $it) $titles[] = $it['task']['title_pl'];
    expectVs($titles === ['Faktury dnia'] && $my['user']['position_code'] === 'ADMINISTRACJA' && !empty($my['user']['view_session']), 'Moje zadania : zadania stanowiska sesji');
    $week = $tasks->week(1, new DateTimeImmutable('2026-11-04'));
    expectVs(($week['user']['position_code'] ?? null) === 'ADMINISTRACJA', 'Mój tydzień : stanowisko sesji');

    // Autre administrateur (même session PHP simulée) : sesja Produkcja, start Dzisiaj.
    expectVs($access()->switchSession(3, 3) === '/today', 'sesja Produkcja : start Dzisiaj (brak ustawienia)');
    expectVs($access()->sessionMenu(3)['label'] === 'Sesja : Produkcja', 'etykieta : Produkcja');

    // --- Refus -----------------------------------------------------------------------------------------------------------------
    foreach ([[2, 5, 403], [1, 7, 422], [1, 1, 422], [1, 99, 422], [1, 'x', 422]] as [$u, $pos, $code]) {
        try {
            $access()->switchSession($u, $pos);
            expectVs(false, "odmowa ({$u}, {$pos})");
        } catch (CustomException $e) {
            expectVs($e->getCode() === $code, "odmowa ({$u}, {$pos}) : {$e->getCode()}");
        }
    }
    // Non-administrateur : la valeur en session est ignorée.
    $_SESSION[ViewSession::KEY] = 3;
    $a = $access();
    expectVs($a->viewPosition(2) === null && $a->menuSees(2, $invPath) && !$a->menuSees(2, $capPath), 'pracownik : sesja ignorowana, własne strony');
    $m = $a->sessionMenu(2);
    expectVs(!$m['can_switch'] && $m['label'] === 'Administracja i fakturowanie' && $m['sessions'] === [], 'pracownik : nazwa stanowiska pod imieniem, bez listy');

    // --- Retour au plein écran -------------------------------------------------------------------------------------------------
    expectVs($access()->switchSession(1, null) === '/dashboard' && ViewSession::position() === null, 'pełny widok : start Rentowność');
    expectVs($access()->railPrefixes(1) === [], 'pełny widok : rail bez filtra');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
echo "view_session_test: OK\n";
