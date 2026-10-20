<?php
namespace App\Services\Staff;

use App\Exceptions\CustomException;
use App\Repositories\Staff\StaffRepository;

/**
 * Kto co potrafi : matryca pracownik × kompetencja (kompetencje ERP przypisane do receptur).
 *
 * Poziom osoby : 1 w szkoleniu, 2 samodzielny, 3 ekspert (szkoli innych). Dział potrafi
 * kompetencję, gdy ma co najmniej jedną samodzielną osobę ; stan działu na kompetencję :
 *   ok       ≥ 2 osoby samodzielne
 *   risk     1 osoba samodzielna (nieobecność = brak)
 *   training nikt samodzielny, ktoś w szkoleniu
 *   missing  nikt, a receptury działu jej wymagają
 *   none     nikt i dział jej nie potrzebuje
 * „Receptury w zasięgu” = receptury aktywnych produktów działu, których wszystkie kompetencje dział
 * ma (receptura bez przypisanych kompetencji liczy się jako w zasięgu).
 */
final class CompetencyMatrixService
{
    public const LEVELS = [1 => 'w szkoleniu', 2 => 'samodzielny', 3 => 'ekspert'];

    public function __construct(private StaffRepository $staff)
    {
    }

    public function matrix(): array
    {
        $competencies = $this->staff->competencies();
        $levels = $this->staff->levels();
        $recipes = $this->staff->sectionRecipes();
        $sections = [];
        foreach ($this->staff->employees() as $e) {
            $id = (int) $e['id_section'];
            $sections[$id] ??= ['id' => $id, 'name' => $e['section_name'], 'employees' => []];
            $sections[$id]['employees'][] = [
                'id' => (int) $e['id'],
                'name' => trim($e['name'] . ' ' . $e['surname']),
                'active' => (int) $e['active'] === 1,
                'levels' => $levels[(int) $e['id']] ?? [],
            ];
        }

        $byCompetency = [];
        foreach ($sections as $id => &$section) {
            $sectionRecipes = $recipes[$id] ?? [];
            $needs = [];
            foreach ($sectionRecipes as $required) {
                foreach (array_unique($required) as $c) $needs[$c] = ($needs[$c] ?? 0) + 1;
            }
            $cells = [];
            $known = [];
            foreach ($competencies as $c) {
                $cid = (int) $c['id'];
                $autonomous = 0;
                $training = 0;
                foreach ($section['employees'] as $emp) {
                    if (!$emp['active']) continue;
                    $level = $emp['levels'][$cid] ?? 0;
                    if ($level >= 2) $autonomous++;
                    elseif ($level === 1) $training++;
                }
                $status = match (true) {
                    $autonomous >= 2 => 'ok',
                    $autonomous === 1 => 'risk',
                    $training > 0 => 'training',
                    isset($needs[$cid]) => 'missing',
                    default => 'none',
                };
                if ($autonomous > 0) $known[$cid] = true;
                $cells[$cid] = ['autonomous' => $autonomous, 'training' => $training, 'status' => $status, 'recipes' => $needs[$cid] ?? 0];
                if ($autonomous > 0) $byCompetency[$cid][] = $section['name'];
            }
            $covered = 0;
            foreach ($sectionRecipes as $required) {
                if (array_diff(array_unique($required), array_keys($known)) === []) $covered++;
            }
            $section['cells'] = $cells;
            $section['recipes'] = count($sectionRecipes);
            $section['recipes_covered'] = $covered;
            $section['risks'] = count(array_filter($cells, fn($cell) => $cell['status'] === 'risk' && $cell['recipes'] > 0));
            $section['missing'] = count(array_filter($cells, fn($cell) => in_array($cell['status'], ['missing', 'training'], true) && $cell['recipes'] > 0));
        }
        unset($section);

        foreach ($competencies as &$c) {
            $c['id'] = (int) $c['id'];
            $c['recipes'] = (int) $c['recipes'];
            $c['sections'] = $byCompetency[$c['id']] ?? [];
        }
        unset($c);

        return ['competencies' => $competencies, 'sections' => array_values($sections), 'levels' => self::LEVELS];
    }

    public function setLevel(int $employeeId, int $competencyId, mixed $level, ?int $userId): void
    {
        if ($this->staff->employee($employeeId) === null) throw new CustomException('Nie znaleziono pracownika.', 'errors', 404);
        if (!$this->staff->competencyExists($competencyId)) throw new CustomException('Nie znaleziono kompetencji.', 'errors', 404);
        $value = filter_var($level, FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > 3) throw new CustomException('Poziom: 0 (brak), 1, 2 albo 3.', 'errors', 422);
        $this->staff->setLevel($employeeId, $competencyId, $value, $userId);
    }

    public function addCompetency(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) throw new CustomException('Nazwa kompetencji jest wymagana (do 255 znaków).', 'errors', 422);
        if ($this->staff->competencyNameTaken($name)) throw new CustomException('Taka kompetencja już istnieje.', 'errors', 422);
        return ['id' => $this->staff->addCompetency($name, $description), 'name' => $name];
    }

    /** Zmiana nazwy / opisu (dawniej strona « Kompetencje recepturowe ») : bez duplikatów nazw. */
    public function renameCompetency(int $id, array $input): array
    {
        if (!$this->staff->competencyExists($id)) throw new CustomException('Nie znaleziono kompetencji.', 'errors', 404);
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) throw new CustomException('Nazwa kompetencji jest wymagana (do 255 znaków).', 'errors', 422);
        if ($this->staff->competencyNameTaken($name, $id)) throw new CustomException('Taka kompetencja już istnieje.', 'errors', 422);
        $this->staff->renameCompetency($id, $name, $description);
        return ['id' => $id, 'name' => $name];
    }

    public function deleteCompetency(int $id): void
    {
        if (!$this->staff->competencyExists($id)) throw new CustomException('Nie znaleziono kompetencji.', 'errors', 404);
        $this->staff->deleteCompetency($id);
    }
}
