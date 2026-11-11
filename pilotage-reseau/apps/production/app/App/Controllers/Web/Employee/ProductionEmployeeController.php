<?php
namespace App\Controllers\Web\Employee;

use App\Core\Web\BaseController;
use App\Repositories\Organization\PositionRepository;
use App\Repositories\Staff\StaffRepository;
use App\Services\Staff\StaffBudgetService;
use App\Services\Employee\EmployeeCreationService;
use App\Services\Employee\ProductionEmployeeService;
use App\Services\Employee\Section\ProductionSectionService;

class ProductionEmployeeController extends BaseController
{

    private ProductionEmployeeService $productionEmployeeService;
    private ProductionSectionService $productionSectionService;

    public function __construct(
        ProductionEmployeeService $productionEmployeeService,
        ProductionSectionService $productionSectionService,
        private EmployeeCreationService $creation,
        private PositionRepository $positions,
        private StaffBudgetService $budget,
        private StaffRepository $staff,
    )
    {
        $this->productionEmployeeService = $productionEmployeeService;
        $this->productionSectionService = $productionSectionService;
    }

    /**
     * [260] Une seule page « Pracownicy produkcji » (fusion avec l'ancien Budżet personelu) : la liste par dział,
     * recherche et ajout pour tous ; pour qui a le droit aux salaires (administrateur), le budget du personnel, les
     * primes et, dans chaque ligne, coût employeur, minimum, actif et droit à la prime — enregistrés sur place.
     * Sans ce droit, aucune valeur de salaire n'est calculée pour la vue.
     */
    public function overview()
    {
        $canPay = self::canPay();
        $data = ['can_pay' => $canPay, 'production_sections' => $this->productionSectionService->getAll()];
        if ($canPay) {
            $budget = $this->budget->overview((string) ($_GET['period'] ?? 'month'));
            $data += $budget + ['selected' => $budget['period']['key']];
        } else {
            $sections = [];
            foreach ($this->staff->employees() as $e) {
                $id = (int) $e['id_section'];
                $sections[$id] ??= ['id' => $id, 'name' => $e['section_name'], 'people' => 0, 'active' => 0, 'employees' => []];
                $sections[$id]['people']++;
                $sections[$id]['active'] += (int) $e['active'] === 1 ? 1 : 0;
                $sections[$id]['employees'][] = ['id' => (int) $e['id'], 'name' => trim($e['name'] . ' ' . $e['surname']), 'active' => (int) $e['active'] === 1, 'notes' => $e['notes']];
            }
            $data['sections'] = $sections;
        }
        $data['employee_count'] = array_sum(array_map(fn($s) => count($s['employees'] ?? []), $data['sections']));
        $this->view("employee/production/overview", $data);
    }

    public function newEmployee()
    {
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['groups'] = $this->positions->groups();
        $data['positions'] = array_values(array_filter($this->positions->positions(), fn($p) => ($p['kind'] ?? '') === 'atelier' && (int) ($p['active'] ?? 1) === 1));
        $data['can_pay'] = $canPay = self::canPay();

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            // [256] Bez prawa do płac (kierownik produkcji) : koszt 0, bez podstawy i premii — uzupełnia administrator.
            $post = $canPay ? $_POST : ['cost' => '0'] + array_diff_key($_POST, array_flip(['cost', 'base_monthly', 'bonus_eligible']));
            $this->errors = $this->productionEmployeeService->validate($post, $canPay);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($post, $canPay) {
                    // Kreator : pracownik (imię, dział, koszt), potem zespół, płaca, tablet — i karta pracownika (nie lista).
                    $id = (int) $this->productionEmployeeService->insert(array_intersect_key($post, array_flip(['name', 'surname', 'cost', 'id_section'])));
                    $problems = $this->creation->complete($id, $post, user('id') ? (int) user('id') : null);
                    if (!$canPay) $problems[] = 'Płaca : koszt pracodawcy, minimum i premię uzupełnia administrator (Płace i premie › Budżet personelu).';
                    $_SESSION['employee_created'] = ['id' => $id, 'problems' => $problems];
                    header('Location: ' . ROOT . '/production-employees/' . $id . '/edit');
                    exit;
                }, default: false);
            }
        }
        $this->view("employee/production/new", $data);
    }

    public function editEmployee($id)
    {
        $data['row'] = $this->productionEmployeeService->getById($id);
        $data['production_sections'] = $this->productionSectionService->getAll();
        $data['can_pay'] = self::canPay();
        if (($_SESSION['employee_created']['id'] ?? null) === (int) $id) {
            $data['created'] = $_SESSION['employee_created'];
            unset($_SESSION['employee_created']);
        }

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            // Koszt pracodawcy zmienia się tylko w Budżecie personelu : z karty pracownika nie przychodzi.
            $post = ['cost' => $data['row']['cost'] ?? null] + array_diff_key($_POST, ['cost' => true]);
            $this->errors = $this->productionEmployeeService->validate($post, false);

            if (empty($this->errors)) {
                $this->handleAction(function () use ($id, $post) {
                    $this->productionEmployeeService->update($id, array_diff_key($post, ['cost' => true]));
                    header('Location: ' . ROOT . '/production-employees');
                    exit;
                }, default: false);
            }
        }
        $this->view("employee/production/edit", $data);
    }

    /** [256] Płace (koszt pracodawcy, minimum, premia) widzi i wpisuje tylko osoba z dostępem do Budżetu personelu. */
    private static function canPay(): bool
    {
        return \App\Core\Web\PageAccess::visible('/staff/budget');
    }

    public function deleteEmployee($id)
    {
        $data['row'] = $this->productionEmployeeService->getById($id);

        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->handleAction(function () use ($id) {
                $this->productionEmployeeService->delete($id);
                header('Location: ' . ROOT . '/production-employees');
                exit;
            }, default: false);
        }

        $this->view("employee/production/delete", $data);
    }


}