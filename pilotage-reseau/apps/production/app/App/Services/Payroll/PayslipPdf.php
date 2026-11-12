<?php
declare(strict_types=1);

namespace App\Services\Payroll;

use App\Domain\Payroll\BankXml;
use App\Services\Utils\TCPDFService;

/**
 * [277] Pasek wynagrodzenia A4 (makieta 41) : document de l'employeur (logo SL Food seul), une page par personne —
 * składniki wynagrodzenia, składki pracownika, podatek, do wypłaty, koszty pracodawcy ; rachunek masqué ; projet tant
 * que la liste n'est pas approuvée.
 */
final class PayslipPdf
{
    /** @param array $p PayrollService::printable() @return array{filename: string, content: string} */
    public static function render(array $p): array
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Pasek wynagrodzenia ' . $p['month']);
        $company = trim((string) ($p['company']['name'] ?? '')) ?: 'Pracodawca';
        foreach ($p['lines'] as $l) self::page($pdf, $p, $l, $company);
        $one = count($p['lines']) === 1 ? '_' . preg_replace('/[^A-Za-z0-9]+/', '_', self::ascii($p['lines'][0]['name'])) : '';
        return ['filename' => 'PASEK_' . $p['month'] . $one . ($p['draft'] ? '_PROJEKT' : '') . '.pdf', 'content' => $pdf->Output('', 'S')];
    }

    private static function page(TCPDFService $pdf, array $p, array $l, string $company): void
    {
        $c = $l['calc'];
        $z = fn(float $v) => number_format($v, 2, ',', ' ');
        $title = 'Pasek wynagrodzenia';
        $pdf->brandPage($title, ['ref' => $l['name'] . ' · ' . $p['month'], 'company' => $company . ' · lista płac', 'producer_only' => true]);
        $pdf->brandHeader($title, [
            ['Pracownik', $l['name']],
            ['Stanowisko', $l['position'] ?: ($l['group'] ?: '—')],
            ['Umowa', $l['contract'] . ($l['work_time'] ? ' · ' . $l['work_time'] : '')],
            ['Miesiąc', $p['label']],
        ], $p['draft'] ? 'PROJEKT — lista niezatwierdzona' : $p['label']);

        $pdf->brandSection('Wynagrodzenie');
        $rows = array_map(fn($e) => [$e['label'], $z((float) $e['amount'])], $c['earnings']);
        $rows[] = ['_total' => ['Brutto', $z($c['gross'])]];
        $pdf->brandTable([['label' => 'Składnik', 'w' => 4], ['label' => 'Kwota (zł)', 'w' => 1.2, 'align' => 'R']], $rows, ['font' => 8.5]);

        $pdf->brandSection('Składki pracownika (ZUS) i zdrowotna');
        $pdf->brandTable([['label' => 'Składka', 'w' => 4], ['label' => 'Kwota (zł)', 'w' => 1.2, 'align' => 'R']], [
            ['Emerytalna (podstawa ' . $z($c['pension_base']) . ')', $z($c['zus']['pension'])],
            ['Rentowa', $z($c['zus']['disability'])],
            ['Chorobowa' . ($c['zus']['sickness'] == 0 ? ' — nie dotyczy' : ''), $z($c['zus']['sickness'])],
            ['Zdrowotna 9 % (od ' . $z($c['health_base']) . ')', $z($c['health'])],
            ['_total' => ['Razem', $z($c['zus_total'] + $c['health'])]],
        ], ['font' => 8.5]);

        $pdf->brandSection('Podatek (zaliczka PIT)');
        $tax = [];
        if ($c['exempt_revenue'] > 0) $tax[] = ['Przychód zwolniony (ulga dla młodych)', $z($c['exempt_revenue'])];
        $tax[] = ['Koszty uzyskania przychodu', $z($c['kup'])];
        $tax[] = ['Podstawa opodatkowania (zaokr.)', number_format($c['tax_base'], 0, ',', ' ')];
        $tax[] = ['Podatek' . ($c['reduction'] > 0 ? ' − kwota zmniejszająca ' . $z($c['reduction']) . ' (PIT-2)' : ''), $z($c['pit'])];
        if ($c['deduction'] > 0) $tax[] = ['Potrącenia z wynagrodzenia netto', $z($c['deduction'])];
        $pdf->brandTable([['label' => 'Pozycja', 'w' => 4], ['label' => 'Kwota (zł)', 'w' => 1.2, 'align' => 'R']], $tax, ['font' => 8.5]);

        $pdf->brandCallout('Do wypłaty : ' . $z($c['net']) . ' zł');

        $er = $c['employer'];
        $pdf->brandSection('Pracodawca');
        $pdf->brandTable([['label' => 'Składka', 'w' => 4], ['label' => 'Kwota (zł)', 'w' => 1.2, 'align' => 'R']], [
            ['Emerytalna · rentowa', $z($er['pension']) . ' · ' . $z($er['disability'])],
            ['Wypadkowa · Fundusz Pracy · FGŚP', $z($er['accident']) . ' · ' . $z($er['fp']) . ' · ' . $z($er['fgsp'])],
            ['_total' => ['Koszt pracodawcy', $z($c['cost'])]],
        ], ['font' => 8.5]);

        $account = BankXml::masked($l['iban'] ?? null);
        $note = ($account ? 'Rachunek : ' . $account . ' (zaszyfrowany w HR). ' : 'Brak rachunku w karcie HR. ')
            . 'Wypłata do ' . date('d.m.Y', strtotime($p['pay_by'])) . ' (art. 85 Kodeksu pracy). Stawki ' . substr($p['month'], 0, 4) . ' : Ustawienia płac, każda ze źródłem.';
        if ($c['warnings']) $note .= ' Uwagi : ' . implode(' ', $c['warnings']);
        $pdf->brandNote($note);
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
    }
}
