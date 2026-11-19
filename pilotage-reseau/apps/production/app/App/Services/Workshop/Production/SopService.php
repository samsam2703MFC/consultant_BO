<?php
declare(strict_types=1);

namespace App\Services\Workshop\Production;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\SopRepository;
use App\Services\Organization\TaskWizards;
use App\Services\Purchasing\NcService;
use App\Services\Utils\TCPDFService;

/**
 * [353] Procedury SOP (makiety 69 i 69 B) :
 *  - tworzy i zatwierdza Kierownik produkcji (asystent : opis → etapy ze zdjęciem / zrzutem ekranu → powiązanie → zatwierdź) ;
 *  - etap : polecenie, opis, zdjęcie (w bazie), BHP / HACCP / jakość, czas, potwierdzenie : odhaczenie, wartość w zakresie,
 *    zdjęcie przy wykonaniu ;
 *  - procedura asystowana : przycisk « Procedura » na stronach i asystentach ERP, do których jest przypięta — wykonanie krok
 *    po kroku zapisane (kto, kiedy, wartości, zdjęcia) ; wartość poza zakresem = niezgodność w rejestrze NC ;
 *  - zadanie dnia « SOP dnia » : zatwierdzona dziś wersja procedury (poprawionej albo nowej) ;
 *  - asystenci ERP bez procedury : propozycje nowych SOP.
 */
final class SopService
{
    public const CODE_DAILY = 'sop_daily';
    public const PREFIXES = ['PROD' => 'Produkcja', 'JAK' => 'Jakość', 'ZAK' => 'Zakupy', 'LOG' => 'Logistyka', 'BHP' => 'BHP', 'ADM' => 'Administracja'];
    private const TAGS = ['bhp', 'haccp', 'quality'];
    private const CONFIRMS = ['check', 'value', 'photo'];
    private const PHOTO_TYPES = ['image/jpeg' => "\xFF\xD8\xFF", 'image/png' => "\x89PNG", 'image/webp' => 'RIFF'];
    private const PHOTO_MAX = 2_500_000;

    public function __construct(private SopRepository $repo, private TransactionManager $tx, private NcService $nc)
    {
    }

    /** Lista (zakładka « Procedury ») z oznaczeniem przeglądu i brakujących zdjęć. */
    public function list(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $soon = date('Y-m-d', strtotime($today . ' +30 days'));
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'code' => $r['code'], 'title' => $r['title'], 'area' => $r['area'], 'status' => $r['status'],
            'version' => (int) $r['version'], 'changed' => (bool) (int) $r['changed'], 'review_at' => $r['review_at'],
            'review_due' => $r['status'] === 'active' && $r['review_at'] !== null && $r['review_at'] <= $soon,
            'steps' => (int) $r['steps'], 'photos' => (int) $r['photos'], 'links' => $r['links'] ? explode('|', (string) $r['links']) : [],
            'last_run' => $r['last_run'], 'runs' => (int) $r['runs'],
        ], $this->repo->list());
    }

    public function get(int $id): array
    {
        $s = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono procedury.', 'errors', 404);
        return [
            'id' => (int) $s['id'], 'code' => $s['code'], 'title' => $s['title'], 'purpose' => $s['purpose'], 'area' => $s['area'],
            'performer' => $s['performer'], 'ppe' => $s['ppe'], 'status' => $s['status'], 'version' => (int) $s['version'],
            'changed' => (bool) (int) $s['changed'], 'review_months' => (int) $s['review_months'], 'review_at' => $s['review_at'],
            'approved_at' => $s['approved_at'],
            'steps' => array_map(fn($t) => [
                'id' => (int) $t['id'], 'title' => $t['title'], 'body' => $t['body'], 'tag' => $t['tag'], 'minutes' => $t['minutes'] !== null ? (int) $t['minutes'] : null,
                'confirm' => $t['confirm'], 'value_min' => $t['value_min'] !== null ? (float) $t['value_min'] : null,
                'value_max' => $t['value_max'] !== null ? (float) $t['value_max'] : null, 'value_unit' => $t['value_unit'],
                'photo_id' => $t['id_photo'] !== null ? (int) $t['id_photo'] : null,
            ], $this->repo->steps($id)),
            'links' => $this->repo->links($id),
        ];
    }

    /** Asystent : zapis całości (opis, etapy, powiązania). Nowa procedura : szkic, kod z prefiksu. @return int id */
    public function save(?int $id, array $in, ?int $userId): int
    {
        $current = $id !== null ? ($this->repo->find($id) ?? throw new CustomException('Nie znaleziono procedury.', 'errors', 404)) : null;
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') throw new CustomException('Podaj tytuł procedury.', 'errors', 422);
        $txt = fn(string $k, int $max) => isset($in[$k]) && trim((string) $in[$k]) !== '' ? mb_substr(trim((string) $in[$k]), 0, $max) : null;
        $months = (int) ($in['review_months'] ?? 12);
        if ($months < 1 || $months > 36) throw new CustomException('Przegląd : co 1–36 miesięcy.', 'errors', 422);
        $steps = [];
        foreach ((array) ($in['steps'] ?? []) as $i => $s) {
            $n = $i + 1;
            $t = trim((string) ($s['title'] ?? ''));
            if ($t === '') throw new CustomException("Etap {$n} : wpisz polecenie.", 'errors', 422);
            $tag = isset($s['tag']) && $s['tag'] !== '' ? (string) $s['tag'] : null;
            if ($tag !== null && !in_array($tag, self::TAGS, true)) throw new CustomException("Etap {$n} : nieznane oznaczenie.", 'errors', 422);
            $confirm = (string) ($s['confirm'] ?? 'check');
            if (!in_array($confirm, self::CONFIRMS, true)) throw new CustomException("Etap {$n} : nieznane potwierdzenie.", 'errors', 422);
            $num = fn($v) => $v === null || $v === '' ? null : (is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : throw new CustomException("Etap {$n} : wartość musi być liczbą.", 'errors', 422));
            $min = $confirm === 'value' ? $num($s['value_min'] ?? null) : null;
            $max = $confirm === 'value' ? $num($s['value_max'] ?? null) : null;
            if ($confirm === 'value' && $min === null && $max === null) throw new CustomException("Etap {$n} : podaj zakres wartości (min. albo maks.).", 'errors', 422);
            if ($min !== null && $max !== null && $min > $max) throw new CustomException("Etap {$n} : min. większe od maks.", 'errors', 422);
            $minutes = isset($s['minutes']) && $s['minutes'] !== '' && $s['minutes'] !== null ? (int) $s['minutes'] : null;
            if ($minutes !== null && ($minutes < 0 || $minutes > 600)) throw new CustomException("Etap {$n} : czas 0–600 min.", 'errors', 422);
            $photo = isset($s['photo_id']) && $s['photo_id'] !== null && $s['photo_id'] !== '' ? (int) $s['photo_id'] : null;
            if ($photo !== null && !$this->repo->photoExists($photo)) throw new CustomException("Etap {$n} : nie znaleziono zdjęcia.", 'errors', 422);
            $steps[] = ['title' => mb_substr($t, 0, 200), 'body' => isset($s['body']) && trim((string) $s['body']) !== '' ? mb_substr(trim((string) $s['body']), 0, 4000) : null,
                'tag' => $tag, 'minutes' => $minutes, 'confirm' => $confirm, 'value_min' => $min, 'value_max' => $max,
                'value_unit' => $confirm === 'value' && isset($s['value_unit']) && trim((string) $s['value_unit']) !== '' ? mb_substr(trim((string) $s['value_unit']), 0, 16) : null,
                'photo_id' => $photo];
        }
        $links = [];
        foreach ((array) ($in['links'] ?? []) as $p) if (($n = self::path((string) $p)) !== null) $links[$n] = true;
        $data = ['title' => mb_substr($title, 0, 200), 'purpose' => $txt('purpose', 4000), 'area' => $txt('area', 160), 'performer' => $txt('performer', 160),
            'ppe' => $txt('ppe', 255), 'review_months' => $months, 'changed' => 1, 'updated_by' => $userId];
        return $this->tx->execute(function () use ($id, $current, $data, $in, $steps, $links, $userId) {
            if ($current === null) {
                $prefix = isset(self::PREFIXES[(string) ($in['prefix'] ?? '')]) ? (string) $in['prefix'] : 'PROD';
                $id = $this->repo->insert($data + ['code' => $this->repo->nextCode('SOP-' . $prefix), 'status' => 'draft', 'version' => 0, 'created_by' => $userId]);
                $this->repo->event($id, 'created', 0, $userId);
            } else {
                $this->repo->update($id, $data + ($current['status'] === 'archived' ? ['status' => 'draft'] : []));
            }
            $this->repo->replaceSteps($id, $steps);
            $this->repo->replaceLinks($id, array_keys($links));
            return $id;
        });
    }

    /** Zatwierdzenie (Kierownik produkcji) : nowa wersja obowiązuje, data przeglądu, zadanie « SOP dnia ». */
    public function approve(int $id, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $s = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono procedury.', 'errors', 404);
        if (!$this->repo->steps($id)) throw new CustomException('Procedura bez etapów — dodaj co najmniej jeden etap.', 'errors', 422);
        if ($s['status'] === 'active' && !(int) $s['changed']) throw new CustomException('Brak zmian od wersji ' . $s['version'] . '.', 'errors', 409);
        $version = (int) $s['version'] + 1;
        $this->tx->execute(function () use ($id, $s, $version, $userId, $today) {
            $this->repo->update($id, ['status' => 'active', 'version' => $version, 'changed' => 0, 'approved_by' => $userId, 'approved_at' => date('Y-m-d H:i:s'),
                'review_at' => date('Y-m-d', strtotime($today . ' +' . (int) $s['review_months'] . ' months'))]);
            $this->repo->event($id, 'approved', $version, $userId);
        });
        return $this->get($id);
    }

    public function archive(int $id, ?int $userId): void
    {
        $s = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono procedury.', 'errors', 404);
        $this->repo->update($id, ['status' => 'archived', 'updated_by' => $userId]);
        $this->repo->event($id, 'archived', (int) $s['version'], $userId);
    }

    /** Zdjęcie / zrzut ekranu (data URL JPEG, PNG albo WebP, zmniejszone w przeglądarce) → id. */
    public function uploadPhoto(mixed $dataUrl, ?int $userId): int
    {
        [$mime, $bytes] = self::decodePhoto($dataUrl);
        return $this->repo->insertPhoto($mime, $bytes, $userId);
    }

    public function photo(int $id): ?array
    {
        return $this->repo->photo($id);
    }

    /** Procedury przypięte do strony (przycisk « Procedura »). */
    public function forPath(string $path): array
    {
        $p = self::path($path);
        return $p === null ? [] : array_map(fn($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'title' => $r['title'], 'status' => $r['status']], $this->repo->forPath($p));
    }

    /**
     * Procedura asystowana : zapis wykonania. $in = {started_at, source_path, steps: [{step_id, done, value, photo}]} — każdy etap
     * potwierdzony według swojego typu ; wartość poza zakresem → wynik « odchylenie » i niezgodność (rejestr NC).
     */
    public function run(int $id, array $in, ?int $userId): array
    {
        $sop = $this->get($id);
        if ($sop['status'] === 'archived') throw new CustomException('Procedura zarchiwizowana.', 'errors', 409);
        if (!$sop['steps']) throw new CustomException('Procedura bez etapów.', 'errors', 422);
        $given = [];
        foreach ((array) ($in['steps'] ?? []) as $r) if (isset($r['step_id'])) $given[(int) $r['step_id']] = $r;
        $out = [];
        $deviations = [];
        $photos = [];
        foreach ($sop['steps'] as $i => $s) {
            $n = $i + 1;
            $r = $given[$s['id']] ?? throw new CustomException("Etap {$n} nie jest potwierdzony.", 'errors', 422);
            $row = ['step' => $n, 'title' => $s['title'], 'confirm' => $s['confirm'], 'ok' => true];
            if ($s['confirm'] === 'check' && empty($r['done'])) throw new CustomException("Etap {$n} : odhacz wykonanie.", 'errors', 422);
            if ($s['confirm'] === 'photo') {
                if (empty($r['photo'])) throw new CustomException("Etap {$n} : zrób zdjęcie.", 'errors', 422);
                $photos[$n] = self::decodePhoto($r['photo']);
            }
            if ($s['confirm'] === 'value') {
                $v = isset($r['value']) ? str_replace(',', '.', trim((string) $r['value'])) : '';
                if ($v === '' || !is_numeric($v)) throw new CustomException("Etap {$n} : wpisz wartość.", 'errors', 422);
                $v = (float) $v;
                $row['value'] = $v;
                $row['unit'] = $s['value_unit'];
                $row['ok'] = ($s['value_min'] === null || $v >= $s['value_min']) && ($s['value_max'] === null || $v <= $s['value_max']);
                if (!$row['ok']) $deviations[] = "etap {$n} « {$s['title']} » : {$v}" . ($s['value_unit'] ? ' ' . $s['value_unit'] : '')
                    . ' (wymagane ' . ($s['value_min'] !== null ? 'od ' . $s['value_min'] : '') . ($s['value_max'] !== null ? ' do ' . $s['value_max'] : '') . ')';
            }
            $out[$n] = $row;
        }
        $started = isset($in['started_at']) && strtotime((string) $in['started_at']) ? date('Y-m-d H:i:s', strtotime((string) $in['started_at'])) : date('Y-m-d H:i:s');
        $source = isset($in['source_path']) ? self::path((string) $in['source_path']) : null;
        return $this->tx->execute(function () use ($id, $sop, $out, $photos, $deviations, $started, $source, $userId) {
            foreach ($photos as $n => [$mime, $bytes]) $out[$n]['photo_id'] = $this->repo->insertPhoto($mime, $bytes, $userId);
            $ncId = null;
            if ($deviations) {
                $ncId = $this->nc->save(['source' => 'internal', 'description' => $sop['code'] . ' ' . $sop['title'] . ' — wartość poza zakresem : ' . implode(' ; ', $deviations),
                    'immediate_action' => 'Wykonanie procedury zapisane w Procedury (SOP) › Wykonania.'], null, $userId);
            }
            $runId = $this->repo->insertRun(['id_sop' => $id, 'version' => $sop['version'], 'source_path' => $source, 'id_user' => $userId, 'started_at' => $started,
                'finished_at' => date('Y-m-d H:i:s'), 'result' => $deviations ? 'deviation' : 'ok', 'steps' => json_encode(array_values($out), JSON_UNESCAPED_UNICODE), 'id_nc' => $ncId]);
            return ['id' => $runId, 'result' => $deviations ? 'deviation' : 'ok', 'deviations' => $deviations, 'nc_id' => $ncId];
        });
    }

    /** Zakładka « Wykonania ». */
    public function runs(?int $sopId = null, int $limit = 100): array
    {
        return array_map(function ($r) {
            $steps = json_decode((string) $r['steps'], true) ?: [];
            $start = strtotime((string) $r['started_at']);
            $end = strtotime((string) $r['finished_at']);
            return ['id' => (int) $r['id'], 'sop_id' => (int) $r['id_sop'], 'code' => $r['code'], 'title' => $r['title'], 'version' => (int) $r['version'],
                'user' => $r['user_name'] ?: null, 'finished_at' => $r['finished_at'], 'minutes' => $start && $end ? max(0, (int) round(($end - $start) / 60)) : null,
                'result' => $r['result'], 'steps' => count($steps), 'ok_steps' => count(array_filter($steps, fn($s) => $s['ok'] ?? true)),
                'nc' => $r['nc_number'], 'source' => $r['source_path'], 'detail' => $steps];
        }, $this->repo->runs($sopId, $limit));
    }

    /** Asystenci ERP (zadania z asystentem) bez procedury : propozycje nowych SOP. */
    public function suggestions(): array
    {
        $linked = [];
        foreach ($this->repo->list() as $r) {
            if ($r['status'] === 'archived') continue;
            foreach ($r['links'] ? explode('|', (string) $r['links']) : [] as $p) $linked[$p] = true;
        }
        $out = [];
        foreach (TaskWizards::CATALOG as $code => [$name, $link]) {
            $p = self::path((string) $link);
            if ($p === null || isset($linked[$p]) || isset($out[$p])) continue;
            $out[$p] = ['path' => $p, 'name' => $name, 'code' => $code];
        }
        return array_values($out);
    }

    /** Zadanie « SOP dnia » : zatwierdzone dziś wersje. @return array{done:bool, summary:string} */
    public function daily(string $day): array
    {
        $approved = array_values(array_filter($this->repo->eventsOn($day), fn($e) => $e['kind'] === 'approved'));
        if (!$approved) return ['done' => false, 'summary' => ''];
        return ['done' => true, 'summary' => implode(', ', array_map(fn($e) => $e['code'] . ' v' . $e['version'], $approved))];
    }

    /** SOP w PDF A4 (styl dokumentów) : etapy ze zdjęciami, potwierdzenie, QR → procedura w ERP. */
    public function pdf(int $id, string $baseUrl): array
    {
        $s = $this->get($id);
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ERP SL Food');
        $pdf->SetTitle($s['code'] . ' ' . $s['title']);
        $pdf->brandPage('Procedura ' . $s['code'], ['ref' => $s['code'] . ($s['version'] ? ' v' . $s['version'] : ' szkic')]);
        $meta = [['Wersja', $s['version'] ? 'v' . $s['version'] . ($s['approved_at'] ? ' · ' . date('d.m.Y', strtotime((string) $s['approved_at'])) : '') : 'szkic']];
        if ($s['review_at']) $meta[] = ['Przegląd', date('d.m.Y', strtotime((string) $s['review_at']))];
        $pdf->brandHeader($s['title'], $meta, $s['code']);
        $lm = 12.0;
        $w = $pdf->contentWidth();
        $pdf->write2DBarcode(rtrim($baseUrl, '/') . '/procedure/' . $s['id'], 'QRCODE,M', $lm + $w - 22, $pdf->GetY(), 22, 22, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
        $info = array_filter(['Cel' => $s['purpose'], 'Obszar' => $s['area'], 'Kto' => $s['performer'], 'Środki ochrony' => $s['ppe']]);
        $pdf->SetFont('dejavusans', '', 9);
        foreach ($info as $k => $v) {
            $pdf->SetTextColorArray(TCPDFService::MUTED);
            $pdf->Cell(30, 5, $k, 0, 0);
            $pdf->SetTextColorArray(TCPDFService::INK);
            $pdf->MultiCell($w - 56, 5, (string) $v, 0, 'L', false, 1);
        }
        $pdf->SetY(max($pdf->GetY(), $pdf->GetY()) + 4);
        $pdf->brandSection('Etapy');
        $photoW = 58.0;
        foreach ($s['steps'] as $i => $t) {
            $img = $t['photo_id'] !== null ? $this->repo->photo($t['photo_id']) : null;
            $size = $img ? @getimagesizefromstring($img['bytes']) : false;
            $h = $size ? min(60.0, $photoW * $size[1] / max(1, $size[0])) : 0.0;
            $need = max($h, 16.0) + 4;
            if ($pdf->GetY() + $need > $pdf->getPageHeight() - 20) $pdf->AddPage();
            $y = $pdf->GetY();
            $pdf->SetFillColorArray(TCPDFService::BRAND);
            $pdf->RoundedRect($lm, $y, 8, 8, 1.5, '1111', 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetXY($lm, $y + 1.6);
            $pdf->Cell(8, 5, (string) ($i + 1), 0, 0, 'C');
            $x = $lm + 11;
            if ($img && $size) {
                $pdf->Image('@' . $img['bytes'], $x, $y, $photoW, $h, '', '', '', true, 150);
                $x += $photoW + 5;
            }
            $tw = $lm + $w - $x;
            $pdf->SetTextColorArray(TCPDFService::INK);
            $pdf->SetFont('dejavusans', 'B', 10.5);
            $pdf->SetXY($x, $y);
            $tags = ['bhp' => 'BHP', 'haccp' => 'HACCP', 'quality' => 'Jakość'];
            $pdf->MultiCell($tw, 5.2, $t['title'] . ($t['tag'] ? '  [' . $tags[$t['tag']] . ']' : '') . ($t['minutes'] ? '  · ' . $t['minutes'] . ' min' : ''), 0, 'L', false, 1, $x);
            if ($t['body']) { $pdf->SetFont('dejavusans', '', 9); $pdf->MultiCell($tw, 4.6, (string) $t['body'], 0, 'L', false, 1, $x); }
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->SetTextColorArray(TCPDFService::MUTED);
            $c = ['check' => 'Potwierdzenie : odhaczenie', 'photo' => 'Potwierdzenie : zdjęcie przy wykonaniu',
                'value' => 'Wartość : ' . ($t['value_min'] !== null ? 'od ' . self::fmt($t['value_min']) . ' ' : '') . ($t['value_max'] !== null ? 'do ' . self::fmt($t['value_max']) . ' ' : '') . ($t['value_unit'] ?? '') . ' (poza zakresem : niezgodność)'][$t['confirm']];
            $pdf->MultiCell($tw, 4.4, $c, 0, 'L', false, 1, $x);
            $pdf->SetY(max($pdf->GetY(), $y + $h) + 4);
            $pdf->SetDrawColorArray(TCPDFService::LINE);
            $pdf->Line($lm, $pdf->GetY() - 2, $lm + $w, $pdf->GetY() - 2);
        }
        $file = strtolower($s['code']) . ($s['version'] ? '-v' . $s['version'] : '') . '.pdf';
        return ['filename' => $file, 'file_string' => $pdf->Output($file, 'S')];
    }

    /** Ścieżka strony bez # i ? (porównanie z bieżącą stroną). */
    public static function path(string $p): ?string
    {
        $p = trim($p);
        if ($p === '' || $p[0] !== '/') return null;
        $p = strtok($p, '?#') ?: '/';
        $p = '/' . trim($p, '/');
        return strlen($p) <= 160 && preg_match('~^/[A-Za-z0-9/_\-\.]*$~', $p) ? $p : null;
    }

    /** @return array{0:string, 1:string} [mime, bajty] */
    public static function decodePhoto(mixed $photo): array
    {
        if (!is_string($photo) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $photo, $m)) throw new CustomException('Nieprawidłowe zdjęcie.', 'errors', 422);
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) < 100 || !str_starts_with($bytes, self::PHOTO_TYPES[$m[1]])) throw new CustomException('Nieprawidłowe zdjęcie.', 'errors', 422);
        if (strlen($bytes) > self::PHOTO_MAX) throw new CustomException('Zdjęcie za duże (maks. 2,5 MB).', 'errors', 422);
        return [$m[1], $bytes];
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
