<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Core\Web\PageAccess;
use App\Exceptions\CustomException;
use App\Repositories\Organization\PositionRepository;
use App\Services\Utils\TCPDFService;

/**
 * Karta stanowiska (fiche de poste) dla każdego stanowiska — biuro i pracownia : cel, obowiązki, kompetencje,
 * wymagania, warunki, wskaźniki, higiena i bezpieczeństwo, współpraca (PL i FR, jedna linia = jeden punkt), do tego
 * z ERP : przełożony i podwładni, osoby na stanowisku (użytkownicy ERP i pracownicy produkcji), zadania cykliczne
 * i strony ERP stanowiska. Zmiana treści = nowa wersja do ponownego zatwierdzenia. Wydruk PDF w stylu dokumentów.
 * [422] Feuille de fonction : « Zadania i jak je wykonać » — un bloc par tâche (TaskSheet : kiedy, gdzie, jak, gotowe,
 * problem), à l'écran et dans le PDF ; PDF de toutes les fiches des postes actifs (un groupe de pages par poste).
 */
final class PositionSheetService
{
    /** pole => [PL, FR] */
    public const LABELS = [
        'mission' => ['Cel stanowiska', 'Mission'],
        'responsibilities' => ['Zakres obowiązków', 'Responsabilités'],
        'skills' => ['Kompetencje', 'Compétences'],
        'requirements' => ['Wymagania', 'Exigences'],
        'conditions' => ['Warunki pracy', 'Conditions de travail'],
        'indicators' => ['Wskaźniki : jak mierzymy wyniki', 'Indicateurs : mesure des résultats'],
        'safety' => ['Higiena i bezpieczeństwo', 'Hygiène et sécurité'],
        'relations' => ['Współpraca', 'Relations de travail'],
    ];
    public const KINDS = ['erp' => ['Biuro', 'Bureau'], 'atelier' => ['Pracownia', 'Atelier']];
    private const MAX_TEXT = 4000;

    public function __construct(private PositionRepository $positions, private TransactionManager $tx)
    {
    }

    public function sheet(int $id): array
    {
        $position = $this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $sheet = $this->positions->sheet($id) ?? [];
        $all = $this->positions->positions();
        $byId = array_column($all, null, 'id');
        $groups = array_column($this->positions->groups(), null, 'id');

        $texts = [];
        foreach (PositionRepository::SHEET_FIELDS as $field) {
            $texts[$field] = [];
            foreach (['pl', 'fr'] as $lang) {
                $raw = (string) ($sheet[$field . '_' . $lang] ?? '');
                $texts[$field][$lang] = self::lines($raw);
                $texts[$field][$lang . '_raw'] = $raw;
            }
        }
        $boss = $position['id_reports_to'] !== null ? ($byId[$position['id_reports_to']] ?? null) : null;
        $group = $position['id_group'] !== null ? ($groups[$position['id_group']] ?? null) : null;
        $kind = $position['kind'] ?? 'erp';

        return [
            'position' => $position + [
                'kind_label' => self::KINDS[$kind] ?? self::KINDS['erp'],
                'group' => $group,
                'reports_to' => $boss ? ['id' => (int) $boss['id'], 'name_pl' => $boss['name_pl'], 'name_fr' => $boss['name_fr']] : null,
            ],
            'texts' => $texts,
            'filled' => $sheet !== [] && array_filter($texts, fn($t) => $t['pl'] !== [] || $t['fr'] !== []) !== [],
            'version' => (int) ($sheet['version'] ?? 0),
            'validated_at' => $sheet['validated_at'] ?? null,
            'validated_by_name' => $sheet['validated_by_name'] ?? null,
            'updated_at' => $sheet['updated_at'] ?? null,
            'updated_by_name' => $sheet['updated_by_name'] ?? null,
            'subordinates' => array_values(array_map(fn($p) => ['id' => (int) $p['id'], 'name_pl' => $p['name_pl'], 'name_fr' => $p['name_fr']],
                // [422] un poste inactif (fusionné, ex. Jakość) n'est plus un poste rattaché
                array_filter($all, fn($p) => (int) ($p['id_reports_to'] ?? 0) === $id && (int) ($p['active'] ?? 1) === 1))),
            'users' => array_values(array_filter($this->positions->users(), fn($u) => (int) $u['id_position'] === $id)),
            'employees' => array_values(array_filter($this->positions->employees(), fn($e) => (int) $e['id_position'] === $id)),
            // [422] un bloc par tâche : kiedy, gdzie, jak, gotowe, problem (PL, FR)
            'tasks' => TaskSheet::blocks($this->positions->tasks($id, true), $boss ? [(string) $boss['name_pl'], (string) ($boss['name_fr'] ?: $boss['name_pl'])] : null, $kind === 'atelier'),
            'no_steps' => TaskSheet::NO_STEPS,
            'pages' => array_values(array_filter(array_map(fn($k) => PageAccess::PAGES[$k][0] ?? null, $this->positions->pageGrants($id)))),
            'manager_module' => $position['code'] === 'KIEROWNIK_PRODUKCJI',
        ];
    }

    /** Wybór do formularza : stanowiska (przełożony), grupy, pracownicy produkcji. */
    public function choices(): array
    {
        return [
            'positions' => $this->positions->positions(),
            'groups' => $this->positions->groups(),
            'employees' => $this->positions->employees(),
            'kinds' => self::KINDS,
            'labels' => self::LABELS,
        ];
    }

    /** Zapis : rodzaj, grupa, przełożony i treść karty (PL / FR). Zmieniona treść = nowa wersja do zatwierdzenia. */
    public function save(int $id, array $input, ?int $userId): void
    {
        $this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $textFields = [];
        foreach (PositionRepository::SHEET_FIELDS as $field) {
            $textFields[] = $field . '_pl';
            $textFields[] = $field . '_fr';
        }
        $v = (new Validator($input, true))->only(['kind', 'group_id', 'reports_to_id', ...$textFields])
            ->choice('kind', array_keys(self::KINDS))
            ->integer('group_id', false, 1, null, true)
            ->integer('reports_to_id', false, 1, null, true);
        foreach ($textFields as $field) $v->string($field, self::MAX_TEXT, false, true);
        if ($v->value('group_id') !== null && !$this->positions->groupExists($v->value('group_id'))) {
            $v->add('group_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('group_id')]);
        }
        if ($v->value('reports_to_id') !== null && !$v->hasError('reports_to_id')) {
            $boss = (int) $v->value('reports_to_id');
            if ($boss === $id || $this->reportsTo($boss, $id)) {
                $v->add('reports_to_id', 'POSITION_CYCLE');
            } elseif (!$this->positions->exists($boss)) {
                $v->add('reports_to_id', 'REFERENCE_NOT_FOUND', ['id' => $boss]);
            }
        }
        $v->throwIfInvalid();

        $clean = $v->clean();
        $meta = [];
        foreach (['kind' => 'kind', 'group_id' => 'id_group', 'reports_to_id' => 'id_reports_to'] as $in => $column) {
            if (array_key_exists($in, $clean)) $meta[$column] = $clean[$in];
        }
        $texts = [];
        foreach ($textFields as $field) {
            if (array_key_exists($field, $clean)) $texts[$field] = self::normalize($clean[$field]);
        }
        $current = $this->positions->sheet($id) ?? [];
        $texts = array_filter($texts, fn($value, $field) => ($current[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

        $this->tx->execute(function () use ($id, $meta, $texts, $userId) {
            if ($meta) $this->positions->update($id, $meta);
            if ($texts) $this->positions->saveSheet($id, $texts, $userId);
        });
    }

    public function validate(int $id, ?int $userId): void
    {
        $this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        if ($this->positions->sheet($id) === null) throw new CustomException('Karta stanowiska jest pusta — najpierw ją wypełnij.', 'errors', 422);
        $this->positions->validateSheet($id, $userId);
    }

    /** Pracownicy produkcji na stanowisku (lista zastępuje poprzednią). */
    public function setEmployees(int $id, array $input): int
    {
        $position = $this->positions->find($id) ?? throw new CustomException('Nie znaleziono stanowiska.', 'errors', 404);
        $raw = $input['employee_ids'] ?? null;
        $v = (new Validator($input))->only(['employee_ids']);
        if (!is_array($raw) || !array_is_list($raw)) {
            $v->add('employee_ids', $raw === null ? 'REQUIRED' : 'NOT_ARRAY');
            $v->throwIfInvalid();
        }
        $ids = [];
        foreach ($raw as $value) {
            $peId = filter_var($value, FILTER_VALIDATE_INT);
            if ($peId === false || !$this->positions->productionEmployeeExists($peId)) {
                $v->add('employee_ids', 'REFERENCE_NOT_FOUND', ['id' => (string) $value]);
                continue;
            }
            $ids[$peId] = $peId;
        }
        $v->throwIfInvalid();
        $this->tx->execute(fn() => $this->positions->setEmployees($id, array_values($ids), $position['id_group'] !== null ? (int) $position['id_group'] : null));
        return count($ids);
    }

    /** @return array{filename:string, content:string} PDF : pl, fr albo both (PL potem FR). */
    public function pdf(int $id, string $lang): array
    {
        $data = $this->sheet($id);
        $code = preg_replace('/[^A-Z0-9_]+/', '_', (string) $data['position']['code']);
        return ['filename' => 'KARTA_STANOWISKA_' . $code . ($lang === 'both' ? '' : '_' . strtoupper($lang)) . '.pdf', 'content' => $this->document([$data], $lang)];
    }

    /**
     * [422] Toutes les fiches des postes actifs (bureau puis atelier, ordre de la liste des postes) : chaque poste est un
     * groupe de pages (pagination « Strona 1 / 2 » par poste). @return array{filename:string, content:string}
     */
    public function pdfAll(string $lang): array
    {
        $sheets = [];
        foreach ($this->positions->positions() as $p) {
            if ((int) $p['active'] === 1) $sheets[] = $this->sheet((int) $p['id']);
        }
        if (!$sheets) throw new CustomException('Brak aktywnych stanowisk.', 'errors', 404);
        return ['filename' => 'KARTY_STANOWISK' . ($lang === 'both' ? '' : '_' . strtoupper($lang)) . '.pdf', 'content' => $this->document($sheets, $lang)];
    }

    /** PDF de fiches déjà calculées (sheet()) : pl, fr ou both (chaque fiche en PL puis en FR). */
    public function document(array $sheets, string $lang): string
    {
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetTitle('Karta stanowiska');
        foreach ($sheets as $data) {
            foreach ($lang === 'both' ? ['pl', 'fr'] : [$lang === 'fr' ? 'fr' : 'pl'] as $l) $this->render($pdf, $data, $l);
        }
        return $pdf->Output('', 'S');
    }

    private function render(TCPDFService $pdf, array $d, string $lang): void
    {
        $i = $lang === 'fr' ? 1 : 0;
        $p = $d['position'];
        $t = fn(string $pl, string $fr) => $i ? $fr : $pl;
        $title = $t('Karta stanowiska', 'Fiche de poste');
        $name = (string) $p['name_' . $lang];
        $pdf->brandPage($title, ['ref' => (string) $p['code'], 'lang' => $lang]);
        $version = $d['version'] ? 'v' . $d['version'] . ($d['validated_at']
            ? ' · ' . $t('zatwierdzona', 'validée') . ' ' . date('d.m.Y', strtotime($d['validated_at']))
            : ' · ' . $t('do zatwierdzenia', 'à valider')) : '—';
        $pdf->brandHeader($title, [
            [$t('Stanowisko', 'Poste'), $name],
            [$t('Dział', 'Service'), $p['kind_label'][$i] . ($p['group'] ? ' · ' . $p['group']['name_' . $lang] : '')],
            [$t('Przełożony', 'Responsable'), $p['reports_to'] ? $p['reports_to']['name_' . $lang] : '—'],
            [$t('Wersja', 'Version'), $version],
        ], $name);

        foreach (self::LABELS as $field => $labels) {
            $lines = $d['texts'][$field][$lang] ?: $d['texts'][$field]['pl'];
            if (!$lines) continue;
            $pdf->brandSection($labels[$i]);
            $pdf->brandText($field === 'mission' ? implode(' ', $lines) : implode("\n", array_map(fn($l) => '•  ' . $l, $lines)));
        }
        if ($d['tasks']) {
            $pdf->brandSection($t('Zadania i jak je wykonać', 'Tâches et comment les faire'),
                $t('Każde zadanie : kiedy, gdzie, krok po kroku, kiedy jest zrobione — te same zadania co « Moje zadania » w ERP',
                    'Chaque tâche : quand, où, pas à pas, quand elle est faite — les mêmes tâches que « Moje zadania » dans l’ERP'));
            $pdf->SetFont(TCPDFService::TABLE_FONT, '', 8.5);
            $pdf->SetTextColorArray(TCPDFService::INK);
            $html = '';
            foreach ($d['tasks'] as $n => $task) $html .= self::taskHtml($n + 1, $task, $i);
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->SetTextColorArray(TCPDFService::INK);
        }
        if ($d['subordinates']) {
            $pdf->brandSection($t('Podległe stanowiska', 'Postes rattachés'));
            $pdf->brandText(implode(', ', array_map(fn($s) => $s['name_' . $lang], $d['subordinates'])));
        }
        $people = [...array_map(fn($u) => (string) ($u['displayed_name'] ?: $u['login']), $d['users']),
            ...array_map(fn($e) => trim($e['name'] . ' ' . $e['surname']), $d['employees'])];
        if ($people) {
            $pdf->brandSection($t('Osoby na stanowisku', 'Titulaires du poste'));
            $pdf->brandText(implode(', ', $people));
        }
        $pdf->brandSignatures([$t('Pracownik', 'Salarié'), $t('Przełożony', 'Responsable')]);
    }

    /**
     * [422] Bloc d'une tâche (maquette validée) : en-tête titre + rythme · durée, puis Kiedy, Gdzie, Jak (étapes
     * numérotées ou ligne neutre), Gotowe, gdy, Problem ? — un tableau insécable (nobr) pour ne pas couper une tâche.
     */
    private static function taskHtml(int $n, array $task, int $i): string
    {
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $hex = fn(array $c) => sprintf('#%02X%02X%02X', ...$c);
        [$brand, $muted, $line, $soft] = [$hex(TCPDFService::BRAND), $hex(TCPDFService::MUTED), $hex(TCPDFService::LINE), $hex(TCPDFService::BRAND_SOFT)];
        $lang = $i ? 'fr' : 'pl';
        $L = $i ? ['Quand', 'Où', 'Comment', 'Fait quand', 'Problème ?'] : ['Kiedy', 'Gdzie', 'Jak', 'Gotowe, gdy', 'Problem ?'];
        $row = fn(string $k, string $v) => '<tr><td width="18%" style="color:' . $muted . '">' . $e($k) . '</td><td width="82%">' . $v . '</td></tr>';
        $steps = $task['steps'][$lang] ?? [];
        if ($steps) {
            $how = '<table cellpadding="2" cellspacing="1">';
            foreach ($steps as $k => $step) {
                $how .= '<tr><td width="6%" align="center" style="color:#FFFFFF;background-color:' . $brand . ';font-weight:bold">' . ($k + 1) . '</td><td width="94%">' . $e($step) . '</td></tr>';
            }
            $how .= '</table>';
        } else {
            $how = '<i style="color:' . $muted . '">' . $e(TaskSheet::NO_STEPS[$i]) . '</i>';
        }
        $rhythm = $task['rhythm'][$i] . ($task['duration'] ? ' · ' . $task['duration'] : '');
        return '<table cellpadding="3" cellspacing="0" style="border:0.4px solid ' . $line . '" nobr="true">'
            . '<tr style="background-color:' . $soft . '"><td width="70%"><b>' . $n . '. ' . $e($task['title'][$i]) . '</b></td>'
            . '<td width="30%" align="right" style="color:' . $brand . '">' . $e($rhythm) . '</td></tr>'
            . '<tr><td colspan="2"><table cellpadding="2" cellspacing="0">'
            . $row($L[0], $e($task['when'][$i])) . $row($L[1], $e($task['where'][$i]))
            . '<tr><td width="18%" style="color:' . $muted . '">' . $e($L[2]) . '</td><td width="82%">' . $how . '</td></tr>'
            . $row($L[3], '<b>' . $e($task['done'][$i]) . '</b>') . $row($L[4], $e($task['problem'][$i]))
            . '</table></td></tr></table><br>';
    }

    /** Czy stanowisko $from podlega (pośrednio) stanowisku $target. */
    private function reportsTo(int $from, int $target): bool
    {
        $seen = [];
        $current = $this->positions->find($from);
        while ($current && $current['id_reports_to'] !== null && !isset($seen[(int) $current['id']])) {
            $seen[(int) $current['id']] = true;
            if ((int) $current['id_reports_to'] === $target) return true;
            $current = $this->positions->find((int) $current['id_reports_to']);
        }
        return false;
    }

    /** @return list<string> punkty (jedna linia = jeden punkt, bez pustych i znaków wypunktowania). */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/^\s*(?:[-•*·]|\d+[.)])\s*/u', '', $line));
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    private static function normalize(?string $text): ?string
    {
        if ($text === null) return null;
        $lines = self::lines($text);
        return $lines ? implode("\n", $lines) : null;
    }
}
