<?php
declare(strict_types=1);

namespace App\Services\Workshop\Time;

use App\Services\Utils\TCPDFService;

/**
 * [262] Karta czasu pracy A4 (maquette 5) : logo SL Food seul (document de l'employeur, pas du réseau L'Atelier),
 * une page par personne et par mois — jours, entrées / sorties, heures, heures supplémentaires et motif, absence,
 * validation du kierownik ; totaux, absences, banque d'heures sup. ; signatures salarié / kierownik produkcji.
 */
final class TimeSheetPdf
{
    public function __construct(private TimeService $time)
    {
    }

    /** @param list<int> $employeeIds @return array{filename:string, content:string} */
    public function render(array $employeeIds, string $month): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Karta czasu pracy ' . $month);
        $last = null;
        foreach ($employeeIds as $id) {
            $s = $this->time->sheet($id, $month);
            $this->page($pdf, $s);
            $last = $s;
        }
        $name = count($employeeIds) === 1 && $last
            ? 'KARTA_CZASU_PRACY_' . $month . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', self::ascii($last['employee']['name'])) . '.pdf'
            : 'KARTY_CZASU_PRACY_' . $month . '.pdf';
        return ['filename' => $name, 'content' => $pdf->Output('', 'S')];
    }

    private function page(TCPDFService $pdf, array $s): void
    {
        $r = $s['rules'];
        $hm = fn(int $m) => $m > 0 ? TimeService::hm($m) : '';
        $title = 'Karta czasu pracy';
        $pdf->brandPage($title, ['ref' => $s['employee']['name'] . ' · ' . $s['month'], 'company' => $r['company'] . ' · karta czasu pracy', 'producer_only' => true]);
        $pdf->brandHeader($title, [
            ['Pracownik', $s['employee']['name']],
            ['Grupa', $s['employee']['group'] ?: '—'],
            ['Miesiąc', $s['label']],
            ['Plan', rtrim(rtrim(number_format($r['day_plan'] / 60, 1, ',', ''), '0'), ',') . ' h / dzień · ' . intdiv($r['week_plan'], 60) . ' h / tydz.'],
        ], $s['label']);

        $rows = [];
        foreach ($s['rows'] as $d) {
            $weekend = !$d['workday'] && $d['worked'] === 0;
            $rows[] = [
                $d['dow'], date('d.m.Y', strtotime($d['date'])),
                $d['in'], $d['out'],
                $d['worked'] > 0 ? TimeService::hm($d['worked']) : ($weekend ? '—' : ''),
                $hm($d['overtime']),
                $d['reason'],
                $d['validated'] ? 'kier. ' . date('d.m', strtotime($d['validated']['at'])) : ($weekend ? '' : ($d['date'] <= date('Y-m-d') ? 'niezatw.' : '')),
            ];
        }
        $t = $s['totals'];
        $rows[] = ['_total' => ['Razem', '', '', '', TimeService::hm($t['worked']), $hm($t['overtime']),
            sprintf('urlop %d d · L4 %d d · wolne za nadgodziny %d d%s', $t['leave'], $t['l4'], $t['wn'], $t['wn'] ? ' (' . TimeService::hm($t['wn_minutes']) . ')' : ''), '']];
        $pdf->brandTable([
            // [265] Jour et date dans deux colonnes alignées (le jour à gauche, la date complète à côté).
            ['label' => 'Dzień', 'w' => 0.7],
            ['label' => 'Data', 'w' => 0.95, 'align' => 'C'],
            ['label' => 'Wejście', 'w' => 0.8, 'align' => 'C'],
            ['label' => 'Wyjście', 'w' => 0.8, 'align' => 'C'],
            ['label' => 'Godziny', 'w' => 0.85, 'align' => 'R'],
            ['label' => 'Nadgodziny', 'w' => 1.0, 'align' => 'R'],
            ['label' => 'Powód / nieobecność', 'w' => 3.35],
            ['label' => 'Zatwierdził', 'w' => 1.05],
        ], $rows, ['font' => 7.5, 'head_font' => 7, 'min_h' => 5]);

        $b = $s['bank'];
        $note = sprintf('Bank nadgodzin na %s : %s', date('d.m.Y', strtotime(min(date('Y-m-t', strtotime($s['month'] . '-01')), date('Y-m-d')))), TimeService::hm((int) $b['bank']));
        if ($b['deadline']) $note .= sprintf(' (najstarsze do odbioru do %s)', date('d.m.Y', strtotime($b['deadline'])));
        $note .= sprintf('. Nadgodziny : ponad %s dziennie (tolerancja %d min) i ponad %d h w tygodniu ; odbiór jako dzień wolny w ciągu %d mies., z podpisem pracownika.',
            TimeService::hm($r['day_plan']), $r['tolerance'], intdiv($r['week_plan'], 60), $r['recovery_months']);
        $note .= ' Odbicia z tabletu biurowego ; korekty kierownika z powodem w dzienniku.';
        $pdf->brandNote($note);
        $pdf->brandSignatures(['Podpis pracownika', 'Podpis kierownika produkcji']);
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
    }
}
