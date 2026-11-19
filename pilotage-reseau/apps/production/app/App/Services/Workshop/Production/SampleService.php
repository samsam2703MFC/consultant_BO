<?php
declare(strict_types=1);

namespace App\Services\Workshop\Production;

use App\Exceptions\CustomException;
use App\Repositories\Workshop\ParamRepository;
use App\Repositories\Workshop\SampleRepository;
use App\Services\Utils\TCPDFService;

/**
 * [352] Próbki produkcji (makieta 68 · 3, 68 B · 6) — zadanie dnia z asystentem :
 *  1. wybierz partie dnia : propozycja wg częstotliwości kategorii produktu (partia, dzień, tydzień, miesiąc, bez próbek ;
 *     kategoria bez reguły : samples.default_frequency) — produkt już z próbką w tym oknie : bez propozycji ;
 *  2. arkusz A4 (PDF, styl dokumentów) : jedna próbka = blok z kodem QR (adres próbki w ERP), partią, produktem,
 *     przechowywać do = DLC produktu (karta : trwałość w dniach od produkcji), partiami surowców ;
 *  3. potwierdź : skan QR (albo przycisk) przy odłożeniu — zadanie zaliczone, gdy wszystkie próbki dnia odłożone.
 */
final class SampleService
{
    public const CODE = 'samples_done';
    public const FREQUENCIES = ['lot', 'day', 'week', 'month', 'none'];
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private SampleRepository $repo, private ParamRepository $params)
    {
    }

    /** Strona asystenta : reguły, partie dnia z propozycją, próbki dnia, stan zadania. */
    public function page(string $day): array
    {
        self::assertDay($day);
        $rules = $this->rules();
        $byCategory = array_column($rules['categories'], 'frequency', 'id');
        $batches = $this->repo->batches($day);
        $ts = strtotime($day);
        $windows = [
            'week' => [date('Y-m-d', strtotime('monday this week', $ts)), date('Y-m-d', strtotime('sunday this week', $ts))],
            'month' => [date('Y-m-01', $ts), date('Y-m-t', $ts)],
            'day' => [$day, $day],
        ];
        $before = [];
        foreach (['week', 'month'] as $w) $before[$w] = $this->repo->sampledItems($windows[$w][0], $windows[$w][1], $day);
        $taken = [];
        // Próbki już zrobione dziś zajmują swoje okno (kolejna partia tego produktu dziś : bez propozycji).
        foreach ($batches as $b) if ($b['sample_id'] !== null) foreach (['day', 'week', 'month'] as $w) $taken[$w][(int) $b['id_item']] = true;
        $rows = [];
        foreach ($batches as $b) {
            $item = (int) $b['id_item'];
            $freq = $b['id_category'] !== null && isset($byCategory[(int) $b['id_category']]) ? $byCategory[(int) $b['id_category']] : $rules['default'];
            $suggest = false;
            $why = $freq;
            if ($b['sample_id'] === null) {
                if ($freq === 'lot') $suggest = true;
                elseif ($freq !== 'none') {
                    $already = !empty($taken[$freq][$item]) || ($freq !== 'day' && !empty($before[$freq][$item]));
                    if ($already) $why = 'already_' . $freq;
                    else { $suggest = true; $taken[$freq][$item] = true; }
                }
            }
            $rows[] = [
                'output_id' => (int) $b['id'], 'item_id' => $item, 'lot_code' => $b['lot_code'], 'product' => $b['name'], 'category' => $b['category'], 'group' => $b['group_name'],
                'employee' => $b['employee'] ?: null, 'quantity' => (float) $b['quantity'], 'frequency' => $freq, 'reason' => $why, 'suggested' => $suggest,
                'keep_until' => self::keepUntil($day, $b['shelf_life']),
                'sample' => $b['sample_id'] !== null ? ['id' => (int) $b['sample_id'], 'status' => $b['sample_status'], 'code' => $b['sample_code']] : null,
            ];
        }
        $samples = $this->samples($day);
        $stored = count(array_filter($samples, fn($s) => $s['status'] === 'stored'));
        $done = $this->repo->wizardDone(self::CODE, $day);
        return [
            'date' => $day, 'rows' => $rows, 'samples' => $samples, 'rules' => $rules,
            'counts' => ['batches' => count($rows), 'suggested' => count(array_filter($rows, fn($r) => $r['suggested'])),
                'samples' => count($samples), 'printed' => count(array_filter($samples, fn($s) => $s['printed_at'] !== null)), 'stored' => $stored],
            'step' => !$samples ? 1 : ($stored < count($samples) ? (count(array_filter($samples, fn($s) => $s['printed_at'] !== null)) < count($samples) ? 2 : 3) : 3),
            'done' => $done !== null ? ['summary' => $done['summary'], 'at' => $done['done_at']] : null,
            'location' => $this->params->get('samples.location', 'Zamrażarka próbek'),
        ];
    }

    /** @return array{default:string, categories:list<array{id:int, name:string, frequency:?string}>} */
    public function rules(): array
    {
        $default = $this->params->get('samples.default_frequency', 'week');
        if (!in_array($default, self::FREQUENCIES, true)) $default = 'week';
        return ['default' => $default, 'categories' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'frequency' => $r['frequency']], $this->repo->rules())];
    }

    /** Reguła kategorii ; null = jak domyślna. */
    public function setRule(int $categoryId, ?string $frequency, ?int $userId): void
    {
        if (!$this->repo->categoryExists($categoryId)) throw new CustomException('Nie znaleziono kategorii.', 'errors', 404);
        if ($frequency !== null && !in_array($frequency, self::FREQUENCIES, true)) throw new CustomException('Nieznana częstotliwość.', 'errors', 422);
        $this->repo->setRule($categoryId, $frequency, $userId);
    }

    /** Krok 1 : próbki wybranych partii dnia (partia bez próbki, nieanulowana). @return int utworzone */
    public function create(string $day, array $outputIds, ?int $userId): int
    {
        self::assertDay($day);
        $ids = array_values(array_unique(array_map('intval', $outputIds)));
        if (!$ids) throw new CustomException('Wybierz co najmniej jedną partię.', 'errors', 422);
        $page = $this->page($day);
        $byOutput = array_column($page['rows'], null, 'output_id');
        $made = 0;
        foreach ($ids as $id) {
            $r = $byOutput[$id] ?? throw new CustomException('Partia spoza dnia ' . $day . ' albo anulowana.', 'errors', 422);
            if ($r['sample'] !== null) continue;
            $this->repo->insert(['id_output' => $id, 'id_item' => $r['item_id'], 'sample_date' => $day, 'keep_until' => $r['keep_until'],
                'code' => $this->newCode(), 'reason' => $r['suggested'] ? $r['frequency'] : 'manual', 'status' => 'planned', 'created_by' => $userId]);
            $made++;
        }
        return $made;
    }

    /** Usunięcie próbki jeszcze nieodłożonej (pomyłka w kroku 1). */
    public function remove(int $id): void
    {
        $s = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono próbki.', 'errors', 404);
        if ($s['status'] === 'stored') throw new CustomException('Próbka już odłożona — nie można jej usunąć.', 'errors', 409);
        $this->repo->deleteSample($id);
    }

    /** Krok 3 : próbka odłożona — kod z QR (cały adres albo sam kod) albo id. */
    public function store(string $codeOrUrl, ?int $userId, ?string $location = null): array
    {
        $code = self::codeFrom($codeOrUrl) ?? throw new CustomException('Nie rozpoznano kodu próbki.', 'errors', 422);
        $s = $this->repo->byCode($code) ?? throw new CustomException('Nie znaleziono próbki ' . $code . '.', 'errors', 404);
        if ($s['status'] !== 'stored') {
            $this->repo->update((int) $s['id'], ['status' => 'stored', 'stored_at' => date('Y-m-d H:i:s'), 'stored_by' => $userId,
                'location' => $location !== null && trim($location) !== '' ? mb_substr(trim($location), 0, 80) : $this->params->get('samples.location', 'Zamrażarka próbek'),
                'printed_at' => $s['printed_at'] ?? date('Y-m-d H:i:s')]);
        }
        return ($this->repo->find((int) $s['id']) ?? $s) + ['already' => $s['status'] === 'stored'];
    }

    /** Zadanie zaliczone : wszystkie próbki dnia odłożone (albo dzień bez próbek do zrobienia). */
    public function finish(string $day, ?int $userId): array
    {
        $page = $this->page($day);
        $c = $page['counts'];
        if ($c['samples'] > $c['stored']) throw new CustomException('Zostało ' . ($c['samples'] - $c['stored']) . ' próbek do odłożenia (skan QR).', 'errors', 409);
        if ($c['samples'] === 0 && $c['suggested'] > 0) throw new CustomException('Są partie do próbek (' . $c['suggested'] . ') — utwórz próbki albo odznacz je.', 'errors', 409);
        $n = $c['stored'];
        $summary = $c['samples'] ? $n . ' ' . ($n === 1 ? 'próbka odłożona' : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? 'próbki odłożone' : 'próbek odłożonych')) : 'dzień bez próbek';
        $this->repo->markDone(self::CODE, $day, $summary, $userId);
        return $this->page($day);
    }

    /** Krok 2 : arkusz A4 próbek dnia (QR = adres próbki w ERP). @return array{filename:string, file_string:string} */
    public function pdf(string $day, string $baseUrl): array
    {
        $samples = $this->samples($day);
        if (!$samples) throw new CustomException('Brak próbek w tym dniu — utwórz je w kroku 1.', 'errors', 404);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ERP SL Food');
        $pdf->SetTitle('Próbki produkcji ' . $day);
        $pdf->brandPage('Próbki produkcji', ['ref' => date('d.m.Y', strtotime($day))]);
        $pdf->brandHeader('Próbki produkcji', [['Dzień produkcji', date('d.m.Y', strtotime($day))], ['Próbek', (string) count($samples)]],
            'Próbka archiwalna : odłożyć, zeskanować kod QR przy odłożeniu, przechowywać do daty na próbce.');
        $lm = 12.0;
        $w = $pdf->contentWidth();
        $h = 40.0;
        $qr = 32.0;
        $y = $pdf->GetY() + 3;
        foreach ($samples as $s) {
            if ($y + $h > $pdf->getPageHeight() - 20) { $pdf->AddPage(); $y = 26.0; }
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,1.5', 'color' => [160, 150, 140]]);
            $pdf->RoundedRect($lm, $y, $w, $h, 2, '1111', 'D');
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
            $pdf->write2DBarcode(rtrim($baseUrl, '/') . '/workshop/traceability/samples/s/' . $s['code'], 'QRCODE,M', $lm + 3, $y + 4, $qr, $qr,
                ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
            $x = $lm + $qr + 8;
            $tw = $w - $qr - 11;
            $pdf->SetTextColorArray(TCPDFService::BRAND);
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetXY($x, $y + 3);
            $pdf->Cell($tw, 4, 'PRÓBKA ARCHIWALNA · ' . $s['code'], 0, 1);
            $pdf->SetTextColorArray(TCPDFService::INK);
            $pdf->SetFont('dejavusansmono', 'B', 13);
            $pdf->SetX($x);
            $pdf->Cell($tw, 6, (string) $s['lot_code'], 0, 1);
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetX($x);
            $pdf->Cell($tw, 5, (string) $s['name'], 0, 1);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetX($x);
            $pdf->Cell($tw, 4, 'Produkcja : ' . date('d.m.Y', strtotime((string) $s['work_date'])) . ($s['group_name'] ? ' · ' . $s['group_name'] : '')
                . ($s['employee'] ? ' · ' . $s['employee'] : '') . '   ·   Przechowywać do : ' . ($s['keep_until'] ? date('d.m.Y', strtotime((string) $s['keep_until'])) : '— (brak trwałości na karcie)'), 0, 1);
            $pdf->SetX($x);
            $pdf->MultiCell($tw, 3.6, 'Partie surowców : ' . ($s['lots'] ?: '—'), 0, 'L', false, 1, '', '', true, 0, false, true, 8, 'T', true);
            $pdf->SetXY($x, $y + $h - 7);
            $pdf->Cell($tw, 4, 'Miejsce : ' . ($s['location'] ?: $this->params->get('samples.location', 'Zamrażarka próbek')) . '      Odłożył(a) : ____________________      Podpis : ____________', 0, 1);
            $y += $h + 3;
        }
        $this->repo->markPrinted(array_column($samples, 'id'));
        return ['filename' => 'probki-' . $day . '.pdf', 'file_string' => $pdf->Output('probki-' . $day . '.pdf', 'S')];
    }

    /** Próbka po kodzie (strona skanu : partia, produkt, stan). */
    public function byCode(string $codeOrUrl): ?array
    {
        $code = self::codeFrom($codeOrUrl);
        return $code === null ? null : $this->repo->byCode($code);
    }

    private function samples(string $day): array
    {
        return array_map(fn($s) => [
            'id' => (int) $s['id'], 'output_id' => (int) $s['id_output'], 'code' => $s['code'], 'lot_code' => $s['lot_code'], 'name' => $s['name'],
            'group_name' => $s['group_name'], 'employee' => $s['employee'] ?: null, 'work_date' => $s['work_date'], 'keep_until' => $s['keep_until'],
            'status' => $s['status'], 'reason' => $s['reason'], 'location' => $s['location'], 'lots' => $s['lots'],
            'printed_at' => $s['printed_at'], 'stored_at' => $s['stored_at'],
        ], $this->repo->forDay($day));
    }

    private function newCode(): string
    {
        do {
            $c = '';
            for ($i = 0; $i < 12; $i++) $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        } while ($this->repo->codeExists($c));
        return $c;
    }

    /** Kod z treści QR : adres …/workshop/traceability/samples/s/KOD albo sam kod (12 znaków). */
    public static function codeFrom(string $text): ?string
    {
        $t = strtoupper(trim($text));
        return preg_match('~(?:^|/)([A-HJ-NP-Z2-9]{12})/?$~', $t, $m) ? $m[1] : null;
    }

    /** DLC produktu = dzień produkcji + trwałość karty (dni) ; brak trwałości : null. */
    public static function keepUntil(string $day, mixed $shelfLife): ?string
    {
        $d = (int) $shelfLife;
        return $d > 0 ? date('Y-m-d', strtotime($day . ' +' . $d . ' days')) : null;
    }

    private static function assertDay(string $day): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !strtotime($day)) throw new CustomException('Nieprawidłowa data.', 'errors', 422);
    }
}
