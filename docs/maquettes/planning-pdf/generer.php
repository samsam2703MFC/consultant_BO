<?php
/**
 * Exemples du planning PDF : TeamSchedulePdfService::plan() puis TeamSchedulePdf, sur deux magasins fictifs.
 *
 *   php docs/maquettes/planning-pdf/generer.php <dossier>/vendor/autoload.php
 *
 * TCPDF 6.x suffit (composer require tecnickcom/tcpdf dans un dossier à part). Les PDF sortent dans exemples/.
 */
$autoload = $argv[1] ?? getenv('TCPDF_AUTOLOAD');
if (!$autoload || !is_file($autoload)) { fwrite(STDERR, "usage : php generer.php <vendor/autoload.php avec TCPDF>\n"); exit(1); }
require $autoload;
require __DIR__ . '/livraison/Api/Services/Employee/TeamSchedulePdfService.php';
require __DIR__ . '/livraison/Api/Repositories/Utils/TeamSchedulePdf.php';

use App\Api\Repositories\Utils\TeamSchedulePdf;
use App\Api\Services\Employee\TeamSchedulePdfService;

// Halle : trois sections, une étudiante sans section. Le samedi, huit services se chevauchent à 11 h ;
// la deuxième semaine, Piotr fait deux nuits au labo (22:00–06:00), coupées à minuit.
$halle = [
    'shop' => ['name' => 'Halle', 'representative_name' => "L'Atelier by Halle"],
    'departments' => [['id' => 1, 'name' => 'Vente'], ['id' => 2, 'name' => 'Production'], ['id' => 3, 'name' => 'Snacking']],
    // [id, nom affiché, prénom, nom, section, horaires par jour ISO (1 = lundi), horaires datés]
    'team' => [
        [11, 'Sophie Lambert', '', '', 1, [1 => '07:00-15:00', 2 => '07:00-15:00', 3 => '07:00-15:00', 4 => '07:00-15:00', 5 => '07:00-15:00'], ['2026-11-11' => null]],
        [12, 'Laura', '', '', 1, [3 => '12:00-20:00', 4 => '12:00-20:00', 5 => '12:00-20:00', 6 => '12:00-20:00', 7 => '12:00-19:00'], []],
        [13, 'Inès Dubois', '', '', 1, [6 => '08:00-13:00', 7 => '08:00-13:00'], []],
        [14, 'Marta Kowalczyk', '', '', 1, [1 => '13:00-19:30', 2 => '13:00-19:30', 4 => '13:00-19:30', 5 => '13:00-19:30', 6 => '07:30-14:00'], []],
        [15, '', 'Julie', 'Mertens', 1, [3 => '09:00-17:30', 6 => '09:00-17:30', 7 => '09:00-17:30'], []],
        [21, 'Thomas Peeters', '', '', 2, [1 => '04:00-12:00', 2 => '04:00-12:00', 3 => '04:00-12:00', 4 => '04:00-12:00', 5 => '04:00-12:00'], []],
        [22, 'Karim', '', '', 2, [2 => '05:30-13:30', 3 => '05:30-13:30', 4 => '05:30-13:30', 5 => '05:30-13:30', 6 => '05:30-13:30'], []],
        [23, 'Piotr Wiśniewski', '', '', 2, [1 => '06:00-14:00', 2 => '06:00-14:00'], ['2026-11-13' => '22:00-06:00', '2026-11-14' => '22:00-06:00']],
        [24, 'Kevin', '', '', 2, [6 => '06:00-14:00', 7 => '06:00-14:00'], []],
        [31, 'Emma Janssens', '', '', 3, [1 => '10:30-14:30', 2 => '10:30-14:30', 3 => '10:30-14:30', 4 => '10:30-14:30', 5 => '10:30-14:30'], []],
        [32, 'Lucas', '', '', 3, [6 => '11:00-15:00', 7 => '11:00-15:00'], []],
        [33, 'Łucja Żak', '', '', 3, [1 => '07:30-11:30', 3 => '07:30-11:30', 5 => '07:30-11:30', 6 => '10:00-16:00'], []],
        [41, 'Anne-Sophie Vandenbroucke', '', '', 0, [6 => '14:00-18:00', 7 => '09:00-13:00'], []],
    ],
];
// Knokke : un petit magasin, pas de sections — tout reste en Rubis, sans légende.
$knokke = [
    'shop' => ['name' => 'Knokke', 'representative_name' => ''],
    'departments' => [],
    'team' => [
        [51, 'Charlotte', '', '', 0, [2 => '08:30-16:30', 3 => '08:30-16:30', 4 => '08:30-16:30', 5 => '08:30-16:30', 6 => '08:00-14:00'], []],
        [52, 'Noah Claes', '', '', 0, [4 => '12:00-19:00', 5 => '12:00-19:00', 6 => '12:00-19:00', 7 => '09:00-18:00'], []],
        [53, 'Elise', '', '', 0, [2 => '13:00-18:00', 6 => '09:30-17:30', 7 => '09:00-14:00'], []],
        [54, 'Giulia Romano', '', '', 0, [1 => '09:00-12:30', 3 => '12:30-18:00', 7 => '13:30-18:00'], []],
    ],
];

/** Ce que TeamSchedulePdfRepository::read() rend : les services du jour d'avant au dernier jour. */
function source(array $shop, string $start, int $days): array
{
    $shifts = []; $id = 1;
    for ($d = -1; $d <= $days; $d++) {
        $date = (new DateTimeImmutable($start))->modify(($d >= 0 ? '+' : '') . $d . ' days');
        foreach ($shop['team'] as [$employee, $display, $name, $surname, $department, $week, $dated]) {
            $hours = array_key_exists($date->format('Y-m-d'), $dated) ? $dated[$date->format('Y-m-d')] : ($week[(int) $date->format('N')] ?? null);
            if ($hours === null) continue;
            [$from, $to] = explode('-', $hours);
            $shifts[] = ['id_schedule' => $id++, 'id_employee' => $employee, 'display_name' => $display, 'name' => $name, 'surname' => $surname,
                'department_id' => $department, 'work_date' => $date->format('Y-m-d'), 'start_hour' => $from . ':00', 'end_hour' => $to . ':00'];
        }
    }
    return ['shop' => $shop['shop'], 'departments' => $shop['departments'], 'shifts' => $shifts];
}

// Les copies de lang/ sont celles de livraison/ : TeamSchedulePdf les lit à côté de lui (__DIR__/../../../lang).
$exemples = [
    ['planning-magasin-7j-fr', $halle, '2026-11-02', '7', 'shop', 'fr'],
    ['planning-sections-7j-fr', $halle, '2026-11-02', '7', 'departments', 'fr'],
    ['planning-magasin-14j-nl', $halle, '2026-11-02', '14', 'shop', 'nl'],
    ['planning-magasin-7j-pl', $halle, '2026-11-09', '7', 'shop', 'pl'],
    ['planning-sections-14j-en', $halle, '2026-11-02', '14', 'departments', 'en'],
    ['planning-petit-magasin-7j-it', $knokke, '2026-11-02', '7', 'shop', 'it'],
];
@mkdir(__DIR__ . '/exemples');
foreach ($exemples as [$fichier, $shop, $start, $days, $layout, $language]) {
    $options = TeamSchedulePdfService::options(['start_date' => $start, 'days' => $days, 'layout' => $layout]);
    $plan = TeamSchedulePdfService::plan(source($shop, $start, (int) $days), $options);
    $pdf = new TeamSchedulePdf($plan, $language); $pdf->render();
    file_put_contents(__DIR__ . '/exemples/' . $fichier . '.pdf', $pdf->Output('schedule.pdf', 'S'));
    $heures = implode(', ', array_map(fn($w) => sprintf('%02d:%02d → %02d:%02d', intdiv($w['minimum'], 60), $w['minimum'] % 60, intdiv($w['maximum'], 60), $w['maximum'] % 60), $plan['weeks']));
    printf("%-34s %d feuille(s) — %s\n", $fichier . '.pdf', count($plan['weeks']) * count($plan['pages']), $heures);
}
