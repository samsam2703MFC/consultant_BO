<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Repositories\Organization\TaskWizardRepository;
use App\Services\Production\Cost\MonthCloseService;
use Psr\Container\ContainerInterface;

/**
 * Kreatory podpinane pod zadania stanowisk [227] (« zadanie = kreator ») : strona kreatora, kiedy zadanie zalicza się samo
 * i stan w okresie zadania — zakończony (z wynikiem liczbowym na karcie) albo w toku (krok z kroków). Kreatory
 * miesięczne (zamknięcie miesiąca, koszty usług) dotyczą miesiąca poprzedniego (zamykany na początku następnego).
 */
final class TaskWizards
{
    /** kod => [nazwa, link, zalicza się, gdy…, kroki] */
    public const CATALOG = [
        'forecast_approved' => ['Prognoza 13 tygodni', '/production/forecast', 'prognoza zatwierdzona', 1],
        'buy_sent' => ['Zakupy tygodnia', '/workshop/buy', 'zapytania ofertowe wysłane', 3],
        'inv_checked' => ['Kreator faktur', '/workshop/supply#tab=invoices&inv=wizard', 'pozycje faktur sprawdzone', 1],
        'costs_recalc' => ['Kreator cen — koszty i marże', '/finances/price-wizard', 'koszty i marże przeliczone', 3],
        'prices_planned' => ['Kreator cen — nowe ceny', '/finances/price-wizard#step=2', 'nowe ceny zaplanowane', 3],
        'week_plan_validated' => ['Plan produkcji tygodnia', '/production/plan-wizard', 'plan tygodnia zatwierdzony', 2],
        'production_day_closed' => ['Zatwierdzenia produkcji', '/production/follow-up', 'dzień zatwierdzony na tabletach', 1],
        'day_shipped' => ['Wysyłka dnia', '/shipping/wizard', 'wszystkie palety dnia z dokumentami', 1],
        'logistics_plan_approved' => ['Plan logistyki 13 tygodni', '/logistics/plan', 'tydzień planu zatwierdzony', 1],
        'month_closed' => ['Zamknięcie miesiąca', '/month-close', 'miesiąc zamknięty (8 kroków)', 8],
        'services_closed' => ['Koszty usług miesiąca', '/finances/costs', 'miesiąc zamknięty w Finansach', 1],
        // Tablety [229] : zadanie dnia stanowiska pracowni, zaliczane przez zatwierdzenie na tablecie.
        'group_day_closed' => ['Dzień grupy (tablet produkcji)', '/production/follow-up', 'grupa zatwierdziła dzień na tablecie', 1],
        'shift_day_validated' => ['Zmiana (wszystkie grupy)', '/production/follow-up', 'wszystkie grupy z produkcją zatwierdziły dzień', 1],
        'packing_done' => ['Pakowanie dnia (tablet pakowania)', '/logistics/live', 'wszystko spakowane na dzień wysyłki', 1],
        'route_done' => ['Trasa dnia (tablet kierowcy)', '/logistics/deliveries', 'trasa zakończona na tablecie kierowcy', 1],
        // Lot 4 [230] : przyjęcia (tablet pakowania, tryb Przyjęcia ; podsumowanie w ERP), płatności dnia, ocena dostawców.
        'receiving_checked' => ['Kontrola przyjęć dnia', '/workshop/receiving', 'wszystkie dostawy dnia sprawdzone', 3],
        'payments_reviewed' => ['Płatności dnia', '/finances/payments-day', 'wpłaty, przypomnienia i różnice dostaw przejrzane', 3],
        'suppliers_reviewed' => ['Ocena dostawców', '/purchasing/supplier-review', 'ocena kwartału zatwierdzona', 3],
    ];

    /** Stanowisko pracowni → grupa produkcji (dzień grupy na tablecie). */
    public const POSITION_GROUP = ['PIEKARZ' => 'PIEKARNIA', 'ROGAL' => 'ROGAL', 'CUKIERNIK' => 'CUKIERNIA', 'GARMAZER' => 'GARMAZERIA'];

    /** Zalicza się samo, gdy… (lista do planu stanowiska). */
    public const AUTO = [
        'inv_checked' => 'gdy pozycje faktur są sprawdzone (kreator faktur)',
        'costs_recalc' => 'gdy koszty i marże są przeliczone (kreator cen, krok 1)',
        'prices_planned' => 'gdy nowe ceny są zaplanowane (kreator cen, krok 3)',
        'buy_sent' => 'gdy zapytania ofertowe są wysłane (zakupy tygodnia)',
        'forecast_approved' => 'gdy prognoza 13 tygodni jest zatwierdzona (Produkcja › Prognoza)',
        'week_plan_validated' => 'gdy plan produkcji tygodnia jest zatwierdzony (kreator planu)',
        'production_day_closed' => 'gdy dzień jest zatwierdzony na tabletach (zatwierdzenia produkcji)',
        'day_shipped' => 'gdy wszystkie palety dnia mają dokumenty (wysyłka dnia)',
        'logistics_plan_approved' => 'gdy tydzień planu logistyki jest zatwierdzony',
        'month_closed' => 'gdy poprzedni miesiąc jest zamknięty (zamknięcie miesiąca, 8 kroków)',
        'services_closed' => 'gdy koszty usług poprzedniego miesiąca są zamknięte (Finanse)',
        'group_day_closed' => 'gdy grupa stanowiska zatwierdzi dzień na tablecie produkcji',
        'shift_day_validated' => 'gdy wszystkie grupy z produkcją zatwierdzą dzień na tabletach',
        'packing_done' => 'gdy wszystkie produkty dnia wysyłki są spakowane (tablet pakowania)',
        'route_done' => 'gdy trasy dnia są zakończone na tablecie kierowcy',
        'receiving_checked' => 'gdy wszystkie dostawy dnia są sprawdzone (tablet pakowania › Przyjęcia)',
        'payments_reviewed' => 'po kroku 3 kreatora płatności (wpłaty, przypomnienia, różnice dostaw)',
        'suppliers_reviewed' => 'gdy ocena dostawców kwartału jest zatwierdzona (kreator oceny)',
    ];

    private const MONTH_STEPS = ['cartons' => 'Kartony', 'staff' => 'Personel', 'media' => 'Media', 'fixed' => 'Koszty stałe i logistyka',
        'inventory' => 'Inwentaryzacja', 'recalc' => 'Przeliczenia', 'manager' => 'Kierownik produkcji', 'close' => 'Zamknięcie i raport'];
    private const MONTHS = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /** Zamknięcie miesiąca (dużo zależności) — tylko gdy trzeba pokazać krok. */
    public function __construct(private TaskWizardRepository $repo, private ContainerInterface $container)
    {
    }

    /** @return array{code:string, label:string, link:string, event:string, steps:int}|null */
    public static function info(?string $code): ?array
    {
        if ($code === null || !isset(self::CATALOG[$code])) return null;
        [$label, $link, $event, $steps] = self::CATALOG[$code];
        return ['code' => $code, 'label' => $label, 'link' => $link, 'event' => $event, 'steps' => $steps];
    }

    /** Kod kreatora po linku zadania (strona kreatora). */
    public static function byLink(?string $link): ?string
    {
        foreach (self::CATALOG as $code => $w) if ($link !== null && $w[1] === $link) return $code;
        return null;
    }

    /**
     * Stan kreatora w okresie zadania [from, to) (`day` = dzień zadania codziennego, termin dla pozostałych).
     * @return array{done:bool, summary:?string, step:?int, steps:int, step_label:?string}
     */
    public function state(string $code, string $from, string $to, string $day, ?string $positionCode = null): array
    {
        $info = self::info($code) ?? throw new \InvalidArgumentException('Nieznany kreator: ' . $code);
        $out = ['done' => false, 'summary' => null, 'step' => null, 'steps' => $info['steps'], 'step_label' => null];
        $hm = fn(?string $ts) => $ts ? substr($ts, 11, 5) : '';
        switch ($code) {
            case 'forecast_approved':
                if ($r = $this->repo->forecastApproved($from, $to)) {
                    $out = ['done' => true, 'summary' => sprintf('%s kart. · zatwierdzona %s%s', self::num((int) $r['cartons']), $hm($r['approved_at']), $r['by_name'] ? ' — ' . $r['by_name'] : '')] + $out;
                }
                break;
            case 'buy_sent':
                $r = $this->repo->buyRound($from, $to);
                if ($r['sent']) $out = ['done' => true, 'summary' => sprintf('zapytania %s wysłane %s', $r['sent']['code'], $hm($r['sent']['sent_at']))] + $out;
                elseif ($r['draft']) $out = ['step' => 2, 'step_label' => 'Komu — szkic ' . $r['draft']['code']] + $out;
                break;
            case 'inv_checked':
                $r = $this->repo->invChecked($from, $to);
                if ($r['checked'] > 0 && $r['waiting'] === 0) {
                    $out = ['done' => true, 'summary' => $r['checked'] . ' ' . self::pl($r['checked'], 'pozycja sprawdzona', 'pozycje sprawdzone', 'pozycji sprawdzonych')
                        . ($r['disputed'] ? ' · ' . $r['disputed'] . ' sporne' : '')] + $out;
                } elseif ($r['checked'] > 0) {
                    $out = ['step' => 1, 'steps' => 1, 'step_label' => $r['waiting'] . ' ' . self::pl($r['waiting'], 'pozycja czeka', 'pozycje czekają', 'pozycji czeka')] + $out;
                }
                break;
            case 'costs_recalc':
                if ($r = $this->repo->costsRecalc($from, $to)) $out = ['done' => true, 'summary' => 'koszty i marże przeliczone ' . $hm($r['run_at'])] + $out;
                break;
            case 'prices_planned':
                if (($n = $this->repo->pricesPlanned($from, $to)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'publikacja cen', 'publikacje cen', 'publikacji cen')] + $out;
                break;
            case 'week_plan_validated':
                $r = $this->repo->weekPlan($from, $to);
                if ($r['validated']) {
                    $out = ['done' => true, 'summary' => sprintf('plan %s – %s zatwierdzony %s', self::dm($r['validated']['period_start']), self::dm($r['validated']['period_end']), $hm($r['validated']['validated_at']))] + $out;
                } elseif ($r['draft']) {
                    $out = ['step' => 1, 'step_label' => sprintf('propozycja %s – %s', self::dm($r['draft']['period_start']), self::dm($r['draft']['period_end']))] + $out;
                }
                break;
            case 'production_day_closed':
                if (($n = $this->repo->dayClosedGroups($day)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'grupa zatwierdziła dzień', 'grupy zatwierdziły dzień', 'grup zatwierdziło dzień')] + $out;
                break;
            case 'day_shipped':
                $r = $this->repo->dayShipping($day);
                if ($r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak wysyłek tego dnia'] + $out;
                elseif ($r['waiting'] === 0) $out = ['done' => true, 'summary' => sprintf('%d %s · %d %s z dokumentami', $r['clients'], self::pl($r['clients'], 'klient', 'klientów', 'klientów'), $r['pallets'], self::pl($r['pallets'], 'paleta', 'palety', 'palet'))] + $out;
                elseif ($r['shipped'] > 0) $out = ['step' => 1, 'step_label' => $r['waiting'] . ' ' . self::pl($r['waiting'], 'paleta czeka', 'palety czekają', 'palet czeka')] + $out;
                break;
            case 'logistics_plan_approved':
                if (($n = $this->repo->logisticsApproved($from, $to)) > 0) $out = ['done' => true, 'summary' => $n . ' ' . self::pl($n, 'tydzień zatwierdzony', 'tygodnie zatwierdzone', 'tygodni zatwierdzonych')] + $out;
                break;
            case 'group_day_closed':
                $group = self::POSITION_GROUP[$positionCode ?? ''] ?? null;
                if ($group === null) break;
                $r = $this->repo->groupDay($day, $group);
                if ($r['closed_at']) $out = ['done' => true, 'summary' => 'dzień zatwierdzony ' . $hm($r['closed_at']) . ($r['lines'] ? ' · ' . $r['lines'] . ' ' . self::pl($r['lines'], 'pozycja', 'pozycje', 'pozycji') : '')] + $out;
                elseif ($r['lines'] > 0) $out = ['step' => 1, 'step_label' => $r['lines'] . ' ' . self::pl($r['lines'], 'pozycja wpisana', 'pozycje wpisane', 'pozycji wpisanych') . ', dzień niezatwierdzony'] + $out;
                break;
            case 'shift_day_validated':
                $r = $this->repo->shiftDay($day);
                if ($r['groups'] > 0 && $r['closed'] >= $r['groups']) $out = ['done' => true, 'summary' => $r['groups'] . ' ' . self::pl($r['groups'], 'grupa zatwierdziła', 'grupy zatwierdziły', 'grup zatwierdziło') . ' dzień'] + $out;
                elseif ($r['groups'] > 0) $out = ['step' => $r['closed'] + 1, 'steps' => $r['groups'], 'step_label' => $r['closed'] . ' z ' . $r['groups'] . ' grup zatwierdziło'] + $out;
                break;
            case 'packing_done':
                $r = $this->repo->packingDay($day);
                if ($r['products'] === 0 && $r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak wysyłek tego dnia'] + $out;
                elseif ($r['products'] > 0 && $r['done'] >= $r['products']) $out = ['done' => true, 'summary' => $r['products'] . ' ' . self::pl($r['products'], 'produkt', 'produkty', 'produktów') . ' · ' . self::num($r['packed']) . ' szt. spakowane'] + $out;
                elseif ($r['packed'] > 0) $out = ['step' => 1, 'step_label' => self::num($r['packed']) . ' z ' . self::num($r['base']) . ' szt. spakowane'] + $out;
                break;
            case 'route_done':
                $r = $this->repo->routeDay($day);
                if ($r['runs'] === 0 && $r['pallets'] === 0) $out = ['done' => true, 'summary' => 'brak trasy tego dnia'] + $out;
                elseif ($r['runs'] > 0 && $r['finished'] >= $r['runs']) $out = ['done' => true, 'summary' => $r['delivered'] . ' ' . self::pl($r['delivered'], 'dostawa', 'dostawy', 'dostaw') . ' · trasa zakończona ' . $hm($r['last'])
                    . ($r['problems'] ? ' · ' . $r['problems'] . ' ' . self::pl($r['problems'], 'problem', 'problemy', 'problemów') : '')] + $out;
                elseif ($r['started'] > 0) $out = ['step' => 1, 'step_label' => $r['delivered'] . ' ' . self::pl($r['delivered'], 'dostawa potwierdzona', 'dostawy potwierdzone', 'dostaw potwierdzonych') . ', trasa w toku'] + $out;
                break;
            case 'receiving_checked':
                $r = $this->repo->receivingDay($day);
                if ($r['due'] === 0) $out = ['done' => true, 'summary' => 'brak dostaw tego dnia'] + $out;
                elseif ($r['checked'] >= $r['due']) $out = ['done' => true, 'summary' => $r['due'] . ' ' . self::pl($r['due'], 'dostawa', 'dostawy', 'dostaw') . ' · ' . $r['ok'] . ' OK · ' . $r['nc'] . ' NC'
                    . ($r['missing'] ? ' · ' . $r['missing'] . ' nie ' . self::pl($r['missing'], 'dotarła', 'dotarły', 'dotarło') : '')] + $out;
                elseif ($r['checked'] > 0) $out = ['step' => 2, 'step_label' => $r['checked'] . ' z ' . $r['due'] . ' dostaw sprawdzone'] + $out;
                break;
            case 'payments_reviewed':
                if ($r = $this->repo->wizardDone($code, $day, date('Y-m-d', strtotime($day . ' +1 day')))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->paymentsRecorded($day)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'wpłata zapisana', 'wpłaty zapisane', 'wpłat zapisanych')] + $out;
                break;
            case 'suppliers_reviewed':
                if ($r = $this->repo->wizardDone($code, substr($from, 0, 10), substr($to, 0, 10))) $out = ['done' => true, 'summary' => $r['summary']] + $out;
                elseif (($n = $this->repo->reviewsSaved($from, $to)) > 0) $out = ['step' => 2, 'step_label' => $n . ' ' . self::pl($n, 'dostawca oceniony', 'dostawcy ocenieni', 'dostawców ocenionych')] + $out;
                break;
            case 'month_closed':
                $ym = date('Y-m', strtotime(substr($from, 0, 7) . '-01 -1 month'));
                $m = $this->repo->costMonth((int) substr($ym, 0, 4), (int) substr($ym, 5, 2));
                if ($m && $m['status'] === 'LOCKED') {
                    $out = ['done' => true, 'summary' => self::monthLabel($ym) . ' zamknięty'] + $out;
                } elseif ($m) {
                    try {
                        $s = $this->container->get(MonthCloseService::class)->status($ym);
                        $next = $s['next'] ?? null;
                        $out = ['step' => min(8, (int) $s['done'] + 1), 'step_label' => ($next ? self::MONTH_STEPS[$next] ?? $next : 'Zamknięcie') . ' — ' . self::monthLabel($ym)] + $out;
                    } catch (\Throwable) {
                        $out = ['step' => 1, 'step_label' => self::monthLabel($ym)] + $out;
                    }
                }
                break;
            case 'services_closed':
                $ym = date('Y-m', strtotime(substr($from, 0, 7) . '-01 -1 month'));
                if ($r = $this->repo->servicesClosed($ym)) $out = ['done' => true, 'summary' => self::monthLabel($ym) . ' · ' . self::num((int) round((float) $r['total'])) . ' zł'] + $out;
                break;
        }
        return $out;
    }

    public static function monthLabel(string $ym): string
    {
        return self::MONTHS[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
    }

    private static function dm(?string $d): string
    {
        return $d ? substr($d, 8, 2) . '.' . substr($d, 5, 2) : '—';
    }

    private static function num(int $v): string
    {
        return number_format($v, 0, ',', "\u{00A0}");
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
