<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionDutyRepository;
use App\Repositories\Organization\PositionRepository;

/**
 * [368] Administracja › Struktura i obowiązki (makiety 70 i 70 B) :
 *  - organigram : stanowiska z przełożonym (n_position.id_reports_to), przeciągnij i upuść = nowy przełożony (te same
 *    zasady co karta stanowiska : bez cykli), stanowiska nieaktywne w palecie ;
 *  - matryca : każdy obowiązek (zakres prac i obowiązków) → stanowisko → asystent kontroli (TaskWizards::CATALOG) albo
 *    asystent proponowany « do utworzenia », rytm, ostatnie wykonanie, zadanie stanowiska (dodawane jednym przyciskiem) ;
 *  - wczytanie obowiązków z kart stanowisk albo z pliku Word (.docx : obszary = nagłówki numerowane, obowiązki = punkty).
 */
final class StructureService
{
    public const RHYTHMS = ['daily' => 'codziennie', 'weekly' => 'co tydzień', 'monthly' => 'co miesiąc', 'quarterly' => 'co kwartał', 'none' => '—'];
    private const MAX_DOCX_XML = 5_000_000;

    public function __construct(private PositionDutyRepository $duties, private PositionRepository $positions, private PositionSheetService $sheets,
        private PositionTaskService $tasks, private TransactionManager $tx)
    {
    }

    /** Organigram : wszystkie stanowiska (aktywne w drzewie, nieaktywne w palecie) z osobami i pokryciem obowiązków. */
    public function chart(): array
    {
        $stats = $this->duties->stats();
        $out = [];
        foreach ($this->positions->positions() as $p) {
            $id = (int) $p['id'];
            $s = $stats[$id] ?? ['duties' => 0, 'covered' => 0, 'proposed' => 0];
            $out[] = ['id' => $id, 'code' => $p['code'], 'name' => $p['name_pl'], 'kind' => $p['kind'] ?? 'erp', 'active' => (bool) (int) $p['active'],
                'reports_to' => $p['id_reports_to'] !== null ? (int) $p['id_reports_to'] : null,
                'people' => (int) ($p['user_count'] ?? 0) + (int) ($p['employee_count'] ?? 0), 'tasks' => (int) ($p['task_count'] ?? 0)] + $s;
        }
        return $out;
    }

    /** Nowy przełożony (null = góra struktury) ; stanowisko z palety (nieaktywne) zostaje włączone, gdy activate. */
    public function move(int $id, array $input, ?int $userId): void
    {
        $position = $this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $boss = $input['reports_to_id'] ?? null;
        $this->tx->execute(function () use ($id, $boss, $position, $input, $userId) {
            $this->sheets->save($id, ['reports_to_id' => $boss === null || $boss === '' ? null : (int) $boss], $userId);
            if (!(int) $position['active'] && !empty($input['activate'])) $this->positions->update($id, ['active' => 1]);
        });
    }

    /** Matryca : obowiązki, kafelki, listy do wyboru. */
    public function matrix(): array
    {
        $catalog = TaskWizards::CATALOG;
        $last = $this->duties->lastDone();
        $tasks = [];
        $byLink = [];
        foreach ($catalog as $code => $w) $byLink[$w[1]] = $code;
        foreach ($this->duties->positionTasks() as $t) {
            $code = $t['auto_code'] ?: (PositionTaskService::LINK_AUTO[(string) $t['link_path']] ?? ($byLink[(string) $t['link_path']] ?? null));
            if ($code) $tasks[(int) $t['id_position'] . ':' . $code] = true;
        }
        $rows = [];
        foreach ($this->duties->duties() as $d) {
            $code = $d['wizard_code'] !== null && isset($catalog[$d['wizard_code']]) ? $d['wizard_code'] : null;
            $rows[] = [
                'id' => (int) $d['id'], 'position_id' => (int) $d['id_position'], 'position' => $d['position_name'], 'position_active' => (bool) (int) $d['position_active'],
                'number' => $d['number'], 'area_no' => $d['area_no'] !== null ? (int) $d['area_no'] : null, 'area' => $d['area'], 'text' => $d['text_pl'],
                'wizard_code' => $code, 'wizard' => $code ? $catalog[$code][0] : null, 'wizard_link' => $code ? $catalog[$code][1] : null,
                'proposed' => $d['proposed_wizard'], 'rhythm' => $d['rhythm'], 'source' => $d['source'],
                'last_done' => $code ? ($last[$code] ?? null) : null,
                'has_task' => $code ? isset($tasks[(int) $d['id_position'] . ':' . $code]) : false,
                'state' => $code ? 'ok' : ($d['proposed_wizard'] ? 'proposed' : 'none'),
            ];
        }
        $count = fn(string $s) => count(array_filter($rows, fn($r) => $r['state'] === $s));
        return [
            'rows' => $rows,
            'tiles' => ['duties' => count($rows), 'positions' => count(array_unique(array_column($rows, 'position_id'))), 'ok' => $count('ok'),
                'proposed' => $count('proposed'), 'none' => $count('none'), 'missing_task' => count(array_filter($rows, fn($r) => $r['state'] === 'ok' && !$r['has_task'])),
                'proposed_names' => array_values(array_unique(array_filter(array_column(array_filter($rows, fn($r) => $r['state'] === 'proposed'), 'proposed'))))],
            'wizards' => array_map(fn($code, $w) => ['code' => $code, 'name' => $w[0], 'link' => $w[1]], array_keys($catalog), array_values($catalog)),
            'positions' => array_values(array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name_pl'], 'active' => (bool) (int) $p['active']], $this->positions->positions())),
            'rhythms' => self::RHYTHMS,
        ];
    }

    /** Nowy obowiązek albo zmiana (stanowisko, treść, asystent, asystent proponowany, rytm). @return int id */
    public function saveDuty(?int $id, array $input, ?int $userId): int
    {
        $current = $id !== null ? ($this->duties->find($id) ?? throw new CustomException('Nie znaleziono obowiązku.', 'errors', 404)) : null;
        $v = (new Validator($input, $current !== null))->only(['position_id', 'number', 'area_no', 'area', 'text', 'wizard_code', 'proposed', 'rhythm'])
            ->integer('position_id', $current === null, 1)
            ->string('number', 12, false, true)
            ->integer('area_no', false, 0, 99, true)
            ->string('area', 160, false, true)
            ->string('text', 2000, $current === null)
            ->choice('wizard_code', array_keys(TaskWizards::CATALOG), false, true)
            ->string('proposed', 160, false, true)
            ->choice('rhythm', array_keys(self::RHYTHMS));
        if ($v->value('position_id') !== null && !$this->positions->exists((int) $v->value('position_id'))) $v->add('position_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('position_id')]);
        $v->throwIfInvalid();
        $c = $v->clean();
        $data = ['updated_by' => $userId];
        foreach (['position_id' => 'id_position', 'number' => 'number', 'area_no' => 'area_no', 'area' => 'area', 'text' => 'text_pl', 'wizard_code' => 'wizard_code',
                     'proposed' => 'proposed_wizard', 'rhythm' => 'rhythm'] as $in => $col) {
            if (array_key_exists($in, $c)) $data[$col] = is_string($c[$in]) && trim($c[$in]) === '' ? null : (is_string($c[$in]) ? trim($c[$in]) : $c[$in]);
        }
        if ($current === null) {
            $data += ['sort_order' => $this->duties->nextSort((int) $data['id_position']), 'rhythm' => $data['rhythm'] ?? 'none'];
            return $this->duties->insert($data);
        }
        $this->duties->update($id, $data);
        return $id;
    }

    public function deleteDuty(int $id, ?int $userId): void
    {
        $this->duties->find($id) ?? throw new CustomException('Nie znaleziono obowiązku.', 'errors', 404);
        $this->duties->update($id, ['active' => 0, 'updated_by' => $userId]);
    }

    /** Zadanie stanowiska dla asystenta obowiązku (tytuł, link i zamknięcie przez asystenta, częstotliwość z rytmu). */
    public function addTask(int $id): int
    {
        $d = $this->duties->find($id) ?? throw new CustomException('Nie znaleziono obowiązku.', 'errors', 404);
        $code = $d['wizard_code'] ?? null;
        if ($code === null || !isset(TaskWizards::CATALOG[$code])) throw new CustomException('Najpierw wybierz asystenta kontroli.', 'errors', 422);
        $w = TaskWizards::CATALOG[$code];
        $frequency = in_array($d['rhythm'], ['daily', 'weekly', 'monthly', 'quarterly'], true) ? $d['rhythm'] : 'daily';
        return $this->tasks->saveTask(['title_pl' => $w[0], 'frequency' => $frequency, 'link_path' => $w[1], 'auto_code' => $code], null, (int) $d['id_position']);
    }

    /** Obowiązki z kart stanowisk (« Zakres obowiązków », jedna linia = jeden punkt) dla stanowisk bez obowiązków w matrycy. */
    public function importSheets(?int $userId): int
    {
        $n = 0;
        foreach ($this->positions->positions() as $p) {
            $id = (int) $p['id'];
            if (!(int) $p['active'] || $this->duties->hasDuties($id)) continue;
            $lines = PositionSheetService::lines((string) ($this->positions->sheet($id)['responsibilities_pl'] ?? ''));
            foreach ($lines as $i => $line) {
                $this->duties->insert(['id_position' => $id, 'number' => (string) ($i + 1), 'text_pl' => mb_substr($line, 0, 2000), 'rhythm' => 'none',
                    'source' => 'Karta stanowiska', 'sort_order' => ($i + 1) * 10, 'updated_by' => $userId]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Plik Word (.docx) → obszary i obowiązki do sprawdzenia : nagłówek « 1. PRODUKCJA — … » = obszar, punkty listy
     * (albo linie « 1.2 … ») pod nim = obowiązki ; tytuł i części bez numeru (np. podsumowanie) : pominięte domyślnie.
     */
    public function parseDocx(string $path, string $name): array
    {
        if (!class_exists(\ZipArchive::class)) throw new CustomException('Brak obsługi plików .docx na serwerze (zip).', 'errors', 500);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new CustomException('To nie jest plik Word (.docx).', 'errors', 422);
        $stat = $zip->statName('word/document.xml');
        if ($stat === false || $stat['size'] > self::MAX_DOCX_XML) { $zip->close(); throw new CustomException('Plik Word bez treści albo za duży.', 'errors', 422); }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) throw new CustomException('Nie udało się odczytać pliku Word.', 'errors', 422);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $title = null;
        $areas = [];
        $ci = null; // obszar bieżący (indeks w $areas) ; null = część bez numeru
        foreach ($xp->query('//w:body//w:p') as $p) {
            $text = '';
            foreach ($xp->query('.//w:t|.//w:tab', $p) as $t) $text .= $t->localName === 'tab' ? ' ' : $t->textContent;
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') continue;
            $style = (string) ($xp->query('./w:pPr/w:pStyle/@w:val', $p)->item(0)?->nodeValue ?? '');
            $listed = $xp->query('./w:pPr/w:numPr', $p)->length > 0 || preg_match('/^[-•–·▪]\s+/u', $text);
            $heading = (bool) preg_match('/^(heading|nag[łl]?[oó]?wek|titre|title)/iu', $style);
            if (preg_match('/^(\d{1,2})\.\s+(\p{Lu}.{2,})$/u', $text, $m) && ($heading || mb_strtoupper(mb_substr($m[2], 0, 6)) === mb_substr($m[2], 0, 6))) {
                $areas[] = ['no' => (int) $m[1], 'title' => $m[2], 'items' => []];
                $ci = count($areas) - 1;
                continue;
            }
            if ($heading || (!$listed && mb_strtoupper($text) === $text && mb_strlen($text) < 120)) {
                if ($title === null && $areas === []) $title = $text;
                $ci = null; // część bez numeru (np. PODSUMOWANIE) : jej punkty nie są obowiązkami
                continue;
            }
            if ($ci !== null && ($listed || preg_match('/^\d+\.\d+\.?\s+/u', $text))) {
                $areas[$ci]['items'][] = trim(preg_replace('/^([-•–·▪]|\d+\.\d+\.?)\s+/u', '', $text));
            }
        }
        $areas = array_values(array_filter($areas, fn($a) => $a['items'] !== []));
        if ($areas === []) throw new CustomException('W pliku nie znaleziono obszarów (« 1. NAZWA ») z punktami obowiązków.', 'errors', 422);
        return ['title' => $title, 'source' => mb_substr(($title ?: 'Zakres obowiązków') . ' (' . $name . ')', 0, 200),
            'areas' => array_map(fn($a) => ['no' => $a['no'], 'title' => $a['title'], 'items' => array_map(fn($t, $i) => ['number' => $a['no'] . '.' . ($i + 1), 'text' => $t],
                $a['items'], array_keys($a['items']))], $areas),
            'count' => array_sum(array_map(fn($a) => count($a['items']), $areas))];
    }

    /** Zapis obowiązków z pliku (sprawdzonych) dla stanowiska. @return int liczba */
    public function importItems(array $input, ?int $userId): int
    {
        $positionId = (int) ($input['position_id'] ?? 0);
        if (!$this->positions->exists($positionId)) throw ApiException::validation([['field' => 'position_id', 'code' => 'REFERENCE_NOT_FOUND', 'params' => ['id' => $positionId]]]);
        $items = is_array($input['items'] ?? null) ? array_slice($input['items'], 0, 300) : [];
        $source = mb_substr(trim((string) ($input['source'] ?? '')), 0, 200) ?: null;
        $n = 0;
        $this->tx->execute(function () use ($items, $positionId, $source, $userId, &$n) {
            $sort = $this->duties->nextSort($positionId);
            foreach ($items as $it) {
                $text = trim((string) ($it['text'] ?? ''));
                if ($text === '') continue;
                $this->duties->insert(['id_position' => $positionId, 'number' => mb_substr((string) ($it['number'] ?? ''), 0, 12) ?: null,
                    'area_no' => isset($it['area_no']) ? (int) $it['area_no'] : null, 'area' => mb_substr((string) ($it['area'] ?? ''), 0, 160) ?: null,
                    'text_pl' => mb_substr($text, 0, 2000), 'rhythm' => 'none', 'source' => $source, 'sort_order' => $sort, 'updated_by' => $userId]);
                $sort += 10;
                $n++;
            }
        });
        return $n;
    }
}
