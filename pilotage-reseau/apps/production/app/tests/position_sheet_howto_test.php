<?php
/**
 * [422] Feuille de fonction « Zadania i jak je wykonać » : TaskHowTo couvre chaque assistant du CATALOG ; Kiedy / Gdzie /
 * Gotowe calculés pour des tâches types ; tâche manuelle avec et sans étapes ; champs du plan (howFields) ; migration 254
 * rejouable ; karta (écran) et PDF PL / FR / both d'un poste ; PDF de toutes les fiches sans les postes inactifs.
 *
 *   php tests/position_sheet_howto_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * base erp_howto_test recréée (schéma minimal des tables n_* lues par la karta) puis supprimée. Sans base : les parties
 * sans base seulement. Textes du PDF lus avec pdftotext quand il est installé.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Organization\{PositionSheetService, PositionTaskService, TaskHowTo, TaskSheet, TaskWizards};
use App\Services\Workshop\Migration\MigrationRunner;

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };

// --- 1. TaskHowTo : chaque code d'assistant a ses étapes PL et FR (3 à 5), son « gotowe » FR ; rien en trop
$check(TaskHowTo::missing() === [], 'every TaskWizards::CATALOG code has its « Jak » steps' . (TaskHowTo::missing() ? ' — missing : ' . implode(', ', TaskHowTo::missing()) : ''));
$check(array_diff(array_keys(TaskHowTo::HOWTO), array_keys(TaskWizards::CATALOG)) === [], 'no « Jak » for an unknown assistant code');
$bad = [];
foreach (TaskHowTo::HOWTO as $code => $h) {
    $n = count($h['pl']);
    $short = array_filter([...$h['pl'], ...$h['fr']], fn($s) => !is_string($s) || trim($s) === '' || mb_strlen($s) > 160);
    if ($n < 3 || $n > 5 || count($h['fr']) !== $n || $short || trim($h['done_fr'] ?? '') === '') $bad[] = $code;
    foreach (['problem', 'where', 'when'] as $k) if (isset($h[$k]) && (count($h[$k]) !== 2 || trim($h[$k][0]) === '' || trim($h[$k][1]) === '')) $bad[] = $code . ':' . $k;
}
$check($bad === [], '3–5 short steps, same number in PL and FR, French « gotowe », [PL, FR] extras' . ($bad ? ' — ' . implode(', ', $bad) : ''));
foreach (['group_day_closed', 'shift_day_validated', 'packing_done', 'route_done', 'receiving_checked'] as $tablet) {
    $check(str_contains(TaskHowTo::for($tablet)['where'][0] ?? '', 'Tablet'), "tablet task {$tablet} : « Gdzie » is the tablet");
}

// --- 2. Kiedy : calendrier de la tâche
$w = fn(array $t) => TaskSheet::when($t + ['title_pl' => 'x']);
$check($w(['frequency' => 'daily']) === ['Codziennie, w dni robocze.', 'Chaque jour ouvré.'], 'daily → working days');
$check($w(['frequency' => 'daily', 'weekdays' => '3,1', 'due_time' => '12:00:00']) === ['W dni : pon., śr., do 12:00.', 'Les jours : lun., mer., avant 12:00.'], 'daily on some days, due time');
$check($w(['frequency' => 'weekly', 'due_weekday' => null]) === ['Co tydzień w piątek.', 'Chaque semaine, le vendredi.'], 'weekly : Friday by default');
$check($w(['frequency' => 'weekly', 'due_weekday' => 2, 'due_time' => '09:30']) === ['Co tydzień we wtorek, do 09:30.', 'Chaque semaine, le mardi, avant 09:30.'], 'weekly on Tuesday before 09:30');
$check($w(['frequency' => 'monthly', 'month_day' => 'wd3']) === ['Co miesiąc, 3. dzień roboczy.', 'Chaque mois, 3e jour ouvré.'], 'monthly : 3rd working day');
$check($w(['frequency' => 'monthly', 'month_day' => 'd1']) === ['Co miesiąc, 1. dzień miesiąca.', 'Chaque mois, le 1er.'], 'monthly : 1st day');
$check($w(['frequency' => 'monthly']) === ['Co miesiąc, ostatni dzień miesiąca.', 'Chaque mois, le dernier jour.'], 'monthly : last day by default');
$check($w(['frequency' => 'quarterly', 'month_day' => 'wd5'])[0] === 'Co kwartał, 5. dzień roboczy pierwszego miesiąca kwartału.', 'quarterly : 5th working day of the first month');
$check($w(['frequency' => 'quarterly'])[1] === 'Chaque trimestre, le dernier jour.', 'quarterly : last day');
$check(TaskSheet::duration(10) === '10 min' && TaskSheet::duration(90) === '1 h 30 min' && TaskSheet::duration(120) === '2 h' && TaskSheet::duration(null) === null, 'duration labels');
$check(TaskSheet::rhythm(['frequency' => 'daily', 'weekdays' => '1,4']) === ['pon., czw.', 'lun., jeu.'] && TaskSheet::rhythm(['frequency' => 'weekly'])[0] === 'co tydzień', 'rhythm in the block header');

// --- 3. Gdzie : chemin du menu, adresse sinon ; assistants ; tablettes
$check(TaskSheet::menuPath('/time') === 'ERP › Produkcja › Czas pracy › Dzień', 'menu path of a tab (/time)');
$check(TaskSheet::menuPath('/workshop/buy') === 'ERP › Zakupy › Zakupy tygodnia', 'repeated labels merged (Zakupy › Zakupy)');
$check(TaskSheet::menuPath('/communication/messages') === 'ERP › Administracja › Ustawienia › Komunikacja › Historia wiadomości', 'tab group under Ustawienia');
$check(TaskSheet::menuPath('/nie-ma-takiej-strony') === null && TaskSheet::menuPath('/ppwr/month', true) === null, 'page outside the menu → null');
$manual = ['title_pl' => 'Sprawdzić niezgodności', 'title_fr' => '', 'frequency' => 'daily', 'minutes' => 10, 'link_path' => '/purchasing/nc'];
$b = TaskSheet::block($manual, ['Kierownik operacyjny', 'Directeur des opérations']);
$check($b['where'] === ['ERP › Produkcja › Niezgodności', 'ERP › Produkcja › Niezgodności'], 'manual task : « Gdzie » = menu path of its link');
$check(TaskSheet::block(['link_path' => '/nie-ma/tej#x'] + $manual)['where'][0] === 'ERP › /nie-ma/tej', 'unknown page : URL path');
$check(TaskSheet::block(['link_path' => null] + $manual)['where'][0] === 'ERP › Dzisiaj i zadania', 'no link : Dzisiaj i zadania');
$a = TaskSheet::block(['title_pl' => 'Czas pracy dnia', 'frequency' => 'daily', 'minutes' => 10, 'link_path' => '/time', 'auto_code' => 'time_day_validated']);
$check($a['where'][0] === 'ERP › Dzisiaj i zadania › kliknij zadanie · w menu : Produkcja › Czas pracy › Dzień', 'assistant task : task card + its menu page');
$check(TaskSheet::block(['title_pl' => 'PPWR', 'frequency' => 'monthly', 'link_path' => '/ppwr/month', 'auto_code' => 'ppwr_month_reviewed'])['where'][1] === 'ERP › Dzisiaj i zadania › cliquer la tâche', 'assistant outside the menu : task card only (FR)');
$r = TaskSheet::block(['title_pl' => 'Trasa', 'frequency' => 'daily', 'link_path' => '/logistics/deliveries', 'auto_code' => 'route_done']);
$check($r['where'][0] === 'Tablet kierowcy (w kabinie)' && str_contains($r['steps']['pl'][1], 'Start trasy'), 'driver : tablet and its real buttons');
$check(TaskSheet::block(['title_pl' => 'Dzień', 'frequency' => 'daily', 'link_path' => '/production/follow-up', 'auto_code' => 'production_day_closed'])['where'][0] === 'ERP › Produkcja › Tablety › Zatwierdzenia produkcji',
    'assistant with its own page : menu path of that page');

// --- 4. Gotowe, gdy / Problem ? / Jak
$check($a['done'][0] === 'Dzień zatwierdzony (odbicia, powody nadgodzin i nieobecności) — zadanie odhacza się samo.' && str_ends_with($a['done'][1], 'la tâche se valide seule.')
    && $a['auto'] && $a['source'] === 'assistant' && count($a['steps']['fr']) === 4, 'assistant : CATALOG rule, closes itself, steps PL / FR');
$check(str_contains($a['problem'][0], 'brygadzistę'), 'assistant : its own « Problem ? »');
$check($b['done'] === ['Zadanie odhaczone w « Moje zadania ».', 'Tâche cochée dans « Moje zadania ».'] && !$b['auto'], 'manual task : ticked in Moje zadania');
$check(TaskSheet::block(['title_pl' => 'Czas pracy miesiąca', 'frequency' => 'monthly', 'month_day' => 'wd2', 'auto_code' => 'time_month_closed', 'link_path' => '/time/month'])['when']
    === ['Co miesiąc, 2. dzień roboczy — za poprzedni miesiąc.', 'Chaque mois, 2e jour ouvré — pour le mois précédent.'], 'assistant on the previous month : said in « Kiedy »');
$check(TaskSheet::block(['form_code' => 'production_meeting'] + $manual)['done'][0] === 'Protokół zakończony w « Moje zadania ».', 'task with a form : protocol');
$at = TaskSheet::block(['link_path' => null] + $manual, null, true);
$check($at['where'] === ['Na stanowisku pracy', 'Au poste de travail'] && $at['done'][0] === 'Zrobione i sprawdzone przez przełożonego.', 'workshop position (no ERP account) : at the work station, checked by the superior');
$check(TaskSheet::block($manual, null, true)['where'][0] === 'ERP › Produkcja › Niezgodności' && TaskSheet::block(['auto_code' => 'route_done', 'link_path' => '/logistics/deliveries'] + $manual, null, true)['done'][0] !== $at['done'][0],
    'workshop position : a link keeps its page, an assistant keeps its rule');
$check($b['source'] === 'none' && $b['steps'] === ['pl' => [], 'fr' => []] && $b['title'][1] === 'Sprawdzić niezgodności', 'manual task without « Jak » : no steps (neutral line), FR title = PL');
$check($b['problem'][0] === 'Nie wiesz, co zrobić : zapytaj przełożonego (Kierownik operacyjny).' && $b['problem'][1] === 'En cas de doute : demandez à votre responsable (Directeur des opérations).', 'default « Problem ? » names the superior');
$h = TaskSheet::block(['how_pl' => "1. Otwórz listę.\n- Sprawdź nowe NC.\n\nKliknij « Zapisz ».", 'problem_pl' => 'Dzwoń do Jakości.'] + $manual);
$check($h['source'] === 'manual' && $h['steps']['pl'] === ['Otwórz listę.', 'Sprawdź nowe NC.', 'Kliknij « Zapisz ».'] && $h['steps']['fr'] === $h['steps']['pl'], 'manual « Jak » : one step per line, FR falls back to PL');
$check($h['problem'] === ['Dzwoń do Jakości.', 'Dzwoń do Jakości.'], 'own « Problem ? » (FR falls back to PL)');
$h2 = TaskSheet::block(['how_pl' => "A\nB", 'how_fr' => "Un\nDeux\nTrois"] + $manual);
$check($h2['steps']['fr'] === ['Un', 'Deux', 'Trois'] && $h2['steps']['pl'] === ['A', 'B'], 'FR steps used when written');
$ws = TaskSheet::block(['wizard_steps' => json_encode([['title' => 'Sprawdź faktury przewoźników', 'hint' => '', 'link' => '/shipments'], ['title' => 'Zapisz']])] + $manual);
$check($ws['source'] === 'wizard_steps' && $ws['steps']['pl'] === ['Sprawdź faktury przewoźników', 'Zapisz'], 'step assistant (wizard_steps) used when no « Jak » is written');
$check(TaskSheet::block(['auto_code' => 'stary_kod'] + $manual)['source'] === 'none', 'unknown assistant code : handled as a manual task');

// --- 5. Champs du plan du poste (Administracja › Zadania stanowisk)
$f = PositionTaskService::howFields(['how_pl' => " 1) Otwórz\n\n• Sprawdź ", 'how_fr' => '', 'problem_pl' => "  Dzwoń\n do  szefa ", 'other' => 'x'], 1);
$check($f === ['how_pl' => "Otwórz\nSprawdź", 'how_fr' => null, 'problem_pl' => 'Dzwoń do szefa'], 'howFields : steps cleaned, empty = NULL, absent = unchanged');
try { PositionTaskService::howFields(['problem_fr' => str_repeat('x', 301)], 3); $check(false, 'problem too long refused'); }
catch (\App\Exceptions\CustomException $e) { $check(str_contains($e->getMessage(), 'Wiersz 3'), 'problem too long refused (row named)'); }

// --- 6. Base : migration 254, karta d'un poste, PDF, toutes les fiches
try {
    $pdo = new PDO((getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock') . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "database part SKIPPED (no database: {$e->getMessage()})\n";
    echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
    exit($fail ? 1 : 0);
}
$pdo->exec('DROP DATABASE IF EXISTS erp_howto_test');
$pdo->exec('CREATE DATABASE erp_howto_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE erp_howto_test');
try {
    $pdo->exec("CREATE TABLE n_position (id INT PRIMARY KEY, code VARCHAR(32), name_pl VARCHAR(100), name_fr VARCHAR(100), description VARCHAR(500) NULL, kind VARCHAR(10) DEFAULT 'erp',
        id_group INT NULL, id_reports_to INT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
    $pdo->exec("CREATE TABLE n_task_category (id INT PRIMARY KEY, code VARCHAR(32), name_pl VARCHAR(100), name_fr VARCHAR(100), icon VARCHAR(40), sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
    $pdo->exec("CREATE TABLE n_position_task (id INT AUTO_INCREMENT PRIMARY KEY, id_position INT, id_backup_position INT NULL, id_category INT NULL, title_pl VARCHAR(200), title_fr VARCHAR(200),
        frequency ENUM('daily','weekly','monthly','quarterly'), due_weekday TINYINT NULL, minutes SMALLINT NULL, weekdays VARCHAR(13) NULL, due_time TIME NULL, month_day VARCHAR(5) NULL,
        link_path VARCHAR(255) NULL, extra_links VARCHAR(600) NULL, form_code VARCHAR(32) NULL, auto_code VARCHAR(32) NULL, cron_on TINYINT DEFAULT 0, cron_then VARCHAR(4) DEFAULT 'wait',
        wizard_steps TEXT NULL, sort_order INT DEFAULT 0, active TINYINT DEFAULT 1)");
    $fields = implode(', ', array_map(fn($f) => "{$f}_pl TEXT NULL, {$f}_fr TEXT NULL", \App\Repositories\Organization\PositionRepository::SHEET_FIELDS));
    $pdo->exec("CREATE TABLE n_position_sheet (id_position INT PRIMARY KEY, {$fields}, version INT DEFAULT 1, validated_at DATETIME NULL, validated_by INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_by INT NULL)");
    $pdo->exec("CREATE TABLE user (id INT PRIMARY KEY, displayed_name VARCHAR(100), name VARCHAR(50), surname VARCHAR(50), email VARCHAR(100), phone VARCHAR(30), login VARCHAR(50))");
    $pdo->exec("CREATE TABLE n_user_position (id_user INT PRIMARY KEY, id_position INT, since DATE NULL)");
    $pdo->exec("CREATE TABLE production_employee (id INT PRIMARY KEY, name VARCHAR(50), surname VARCHAR(50))");
    $pdo->exec("CREATE TABLE n_prod_employee (id INT AUTO_INCREMENT PRIMARY KEY, id_production_employee INT, id_position INT NULL, id_primary_group INT NULL, active TINYINT DEFAULT 1)");
    $pdo->exec("CREATE TABLE n_prod_group (id INT PRIMARY KEY, code VARCHAR(32), name_pl VARCHAR(100), name_fr VARCHAR(100), sort_order INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE n_page_access (id_position INT, page_key VARCHAR(40), PRIMARY KEY (id_position, page_key))");

    // Migration 254 : deux fois (rejouable), colonnes présentes une fois
    $sql = MigrationRunner::splitStatements((string) file_get_contents(__DIR__ . '/../migrations/tfb/254_task_howto.sql'));
    for ($k = 0; $k < 2; $k++) foreach ($sql as $s) $pdo->exec($s);
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'erp_howto_test' AND TABLE_NAME = 'n_position_task' AND COLUMN_NAME IN ('how_pl', 'how_fr', 'problem_pl', 'problem_fr') ORDER BY COLUMN_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $check($cols === ['how_fr', 'how_pl', 'problem_fr', 'problem_pl'], 'migration 254 : how_pl / how_fr / problem_pl / problem_fr, replayed twice');

    // Données fictives : Kierownik operacyjny ← Kierownik produkcji ; Jakość inactif ; Piekarz (pracownia)
    $pdo->exec("INSERT INTO n_prod_group VALUES (1, 'PIEKARNIA', 'Piekarnia', 'Boulangerie', 1)");
    $pdo->exec("INSERT INTO n_position (id, code, name_pl, name_fr, kind, id_group, id_reports_to, sort_order, active) VALUES
        (1, 'KIEROWNIK_OPERACYJNY', 'Kierownik operacyjny', 'Directeur des opérations', 'erp', NULL, NULL, 1, 1),
        (2, 'KIEROWNIK_PRODUKCJI', 'Kierownik produkcji', 'Responsable de production', 'erp', NULL, 1, 2, 1),
        (3, 'JAKOSC', 'Jakość', 'Qualité', 'erp', NULL, 2, 3, 0),
        (4, 'PIEKARZ', 'Piekarz', 'Boulanger', 'atelier', 1, 2, 4, 1)");
    $pdo->exec("INSERT INTO n_position_sheet (id_position, mission_pl, mission_fr, responsibilities_pl, version, validated_at) VALUES
        (2, 'Prowadzi produkcję.', 'Dirige la production.', 'Plan produkcji\nKontrola na hali', 3, '2026-11-12 10:00:00'),
        (4, 'Robi pieczywo.', 'Fabrique le pain.', 'Wypiek\nSprzątanie', 1, NULL)");
    $ins = $pdo->prepare('INSERT INTO n_position_task (id_position, title_pl, title_fr, frequency, due_weekday, minutes, weekdays, due_time, month_day, link_path, auto_code, how_pl, how_fr, problem_pl, sort_order, active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        [2, 'Czas pracy dnia', 'Temps de travail du jour', 'daily', null, 10, null, '09:00:00', null, '/time', 'time_day_validated', null, null, null, 10, 1],
        [2, 'Obchód jakości', 'Tournée qualité', 'daily', null, 45, null, null, null, '/workshop/traceability', null, "Ok. 8:00, 10:30 i 13:00 : przejdź przez halę.\nSprawdź wagę 3 sztuk i wygląd.\nZapisz kontrolę.", "Vers 8 h, 10 h 30 et 13 h : faites le tour.\nPesez 3 pièces, regardez l’aspect.\nEnregistrez le contrôle.", 'Zła partia : wstrzymaj ją i zadzwoń do Kierownika operacyjnego.', 20, 1],
        [2, 'PPWR i opakowania', 'PPWR et emballages', 'monthly', null, 30, null, null, 'wd5', '/ppwr/month', 'ppwr_month_reviewed', null, null, null, 30, 1],
        [2, 'Teczka do audytu', 'Classeur d’audit', 'quarterly', null, 60, null, null, 'wd10', '/audit-folder', 'audit_folder_reviewed', null, null, null, 40, 1],
        [2, 'Raport dla zarządu', 'Rapport pour la direction', 'weekly', 1, 20, null, null, null, '/dashboard/monthly', null, null, null, null, 50, 1],
        [2, 'Stare zadanie', 'Ancienne tâche', 'weekly', 2, 5, null, null, null, null, null, null, null, null, 60, 0],
        [3, 'Zadanie Jakości', 'Tâche Qualité', 'daily', null, 5, null, null, null, null, null, null, null, null, 10, 1],
        [4, 'Dzień zatwierdzony na tablecie — Piekarnia', 'Journée validée sur la tablette — Boulangerie', 'daily', null, 10, null, null, null, '/production/follow-up', 'group_day_closed', null, null, null, 10, 1],
    ] as $row) $ins->execute($row);

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $sheets = $builder->build()->get(PositionSheetService::class);

    $kp = $sheets->sheet(2);
    $check(count($kp['tasks']) === 5 && array_column($kp['tasks'], 'title_pl')[0] === 'Czas pracy dnia', 'sheet : active tasks only, schedule order');
    $byTitle = array_column($kp['tasks'], null, 'title_pl');
    $check($byTitle['Obchód jakości']['source'] === 'manual' && $byTitle['Obchód jakości']['steps']['fr'][1] === 'Pesez 3 pièces, regardez l’aspect.'
        && $byTitle['Obchód jakości']['problem'][0] === 'Zła partia : wstrzymaj ją i zadzwoń do Kierownika operacyjnego.', 'manual task with its « Jak » and « Problem ? » from the database');
    $check($byTitle['Raport dla zarządu']['source'] === 'none' && $byTitle['Raport dla zarządu']['where'][0] === 'ERP › Analiza › Rentowność › Raport miesięczny'
        && str_contains($byTitle['Raport dla zarządu']['problem'][0], 'Kierownik operacyjny') && $byTitle['Raport dla zarządu']['when'][0] === 'Co tydzień w poniedziałek.', 'manual task without « Jak » : page of the task, superior named');
    $check($byTitle['Teczka do audytu']['when'][0] === 'Co kwartał, 10. dzień roboczy pierwszego miesiąca kwartału.' && $byTitle['Teczka do audytu']['auto'], 'quarterly assistant task');
    $check($kp['no_steps'] === TaskSheet::NO_STEPS, 'neutral line passed to the screen');
    $check(array_column($kp['subordinates'], 'name_pl') === ['Piekarz'], 'inactive position (Jakość) is no longer listed as a subordinate');

    $text = function (string $pdf): ?string {
        if (!is_executable('/usr/bin/pdftotext') && trim((string) shell_exec('command -v pdftotext')) === '') return null;
        $f = tempnam(sys_get_temp_dir(), 'howto');
        file_put_contents($f, $pdf);
        $out = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        unlink($f);
        return $out;
    };
    $pl = $sheets->pdf(2, 'pl');
    $check($pl['filename'] === 'KARTA_STANOWISKA_KIEROWNIK_PRODUKCJI_PL.pdf' && str_starts_with($pl['content'], '%PDF'), 'PDF PL of the production manager');
    $fr = $sheets->pdf(2, 'fr');
    $both = $sheets->pdf(2, 'both');
    $check(str_starts_with($fr['content'], '%PDF') && strlen($both['content']) > strlen($pl['content']), 'PDF FR and PL + FR');
    if (($t = $text($pl['content'])) !== null) {
        $check(str_contains($t, 'Zadania i jak je wykonać') && !str_contains($t, 'Zadania cykliczne w ERP') && str_contains($t, 'Gotowe, gdy') && str_contains($t, 'Kroki nie są jeszcze opisane'),
            'PDF PL : new section replaces the old list, neutral line for a task without steps');
        $check(str_contains($t, 'v3 · zatwierdzona 12.11.2026') && str_contains($t, 'Cel stanowiska') && str_contains($t, 'Zakres obowiązków'), 'PDF PL : version, mission and duties kept');
        $tf = (string) $text($fr['content']);
        $check(str_contains($tf, 'Tâches et comment les faire') && str_contains($tf, 'Pesez 3 pièces') && str_contains($tf, 'Fait quand') && str_contains($tf, 'Fiche de poste'), 'PDF FR : French labels and French steps');
        $all = $sheets->pdfAll('pl');
        $ta = (string) $text($all['content']);
        $check($all['filename'] === 'KARTY_STANOWISK_PL.pdf' && str_contains($ta, 'Kierownik produkcji') && str_contains($ta, 'Piekarz') && str_contains($ta, 'Kierownik operacyjny')
            && !str_contains($ta, 'Zadanie Jakości') && !str_contains($ta, 'Jakość'), 'all sheets : active positions only (Jakość left out)');
        $check(substr_count($ta, 'Strona 1 /') === 3, 'all sheets : one page group per position');
        $check(str_contains($ta, 'Tablet grupy produkcji'), 'all sheets : workshop position with its tablet task');
    } else {
        echo "skip PDF text checks (pdftotext not installed)\n";
    }
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS erp_howto_test');
}

echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
