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
    private const FREQUENCIES = [
        'daily' => ['codziennie', 'chaque jour'], 'weekly' => ['co tydzień', 'chaque semaine'],
        'monthly' => ['co miesiąc', 'chaque mois'], 'quarterly' => ['co kwartał', 'chaque trimestre'],
    ];
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
                array_filter($all, fn($p) => (int) ($p['id_reports_to'] ?? 0) === $id))),
            'users' => array_values(array_filter($this->positions->users(), fn($u) => (int) $u['id_position'] === $id)),
            'employees' => array_values(array_filter($this->positions->employees(), fn($e) => (int) $e['id_position'] === $id)),
            'tasks' => array_map(fn($t) => ['title_pl' => $t['title_pl'], 'title_fr' => $t['title_fr'] ?: $t['title_pl'],
                'frequency' => self::FREQUENCIES[$t['frequency']] ?? [$t['frequency'], $t['frequency']]], $this->positions->tasks($id, true)),
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
        $pdf = new TCPDFService('P', 'mm', 'A4', true, 'UTF-8', false);
        foreach ($lang === 'both' ? ['pl', 'fr'] : [$lang === 'fr' ? 'fr' : 'pl'] as $l) $this->render($pdf, $data, $l);
        $code = preg_replace('/[^A-Z0-9_]+/', '_', (string) $data['position']['code']);
        return ['filename' => 'KARTA_STANOWISKA_' . $code . ($lang === 'both' ? '' : '_' . strtoupper($lang)) . '.pdf', 'content' => $pdf->Output('', 'S')];
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
            $pdf->brandSection($t('Zadania cykliczne w ERP', 'Tâches récurrentes dans l’ERP'), $t('Na liście « Moje zadania »', 'Dans la liste « Moje zadania »'));
            $pdf->brandText(implode("\n", array_map(fn($task) => '•  ' . ($i ? $task['title_fr'] : $task['title_pl']) . ' — ' . $task['frequency'][$i], $d['tasks'])));
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
