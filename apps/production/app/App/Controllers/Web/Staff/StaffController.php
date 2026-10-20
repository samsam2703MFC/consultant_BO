<?php
namespace App\Controllers\Web\Staff;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Staff\CompetencyMatrixService;
use App\Services\Staff\StaffBudgetService;
use Symfony\Component\HttpFoundation\Request;

/** Zespół : budżet personelu (minimum + premie z % sprzedaży działu) i matryca kompetencji. */
class StaffController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private StaffBudgetService $budget,
        private CompetencyMatrixService $competencies,
        private ProfitabilityService $profitability,
    ) {}

    public function budget()
    {
        $data = $this->budget->overview((string) ($_GET['period'] ?? 'month'));
        $this->view('staff/budget', $data + ['selected' => $data['period']['key']]);
    }

    public function competencies()
    {
        $this->view('staff/competencies', $this->competencies->matrix());
    }

    public function savePay($id)
    {
        try {
            $result = $this->budget->savePay((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            unset($_SESSION['tfb_profit_badge']);
            return $this->json(['message' => $result['warning'] ?? 'Zapisano.', 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveDefaultPct()
    {
        try {
            $this->budget->saveDefaultPct($this->getJson(Request::createFromGlobals())['value'] ?? null, $this->userId());
            return $this->json(['message' => 'Zapisano % premii.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveSectionPct($id)
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $this->profitability->saveTarget((int) $id, ['bonus_pct' => $input['bonus_pct'] ?? ''], $this->userId());
            return $this->json(['message' => 'Zapisano % premii działu.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function setLevel($id, $competencyId)
    {
        try {
            $this->competencies->setLevel((int) $id, (int) $competencyId, $this->getJson(Request::createFromGlobals())['level'] ?? null, $this->userId());
            return $this->json(['message' => 'Zapisano.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function addCompetency()
    {
        try {
            $competency = $this->competencies->addCompetency($this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => 'Dodano kompetencję.', 'data' => $competency], 201);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function renameCompetency($id)
    {
        try {
            $competency = $this->competencies->renameCompetency((int) $id, $this->getJson(Request::createFromGlobals()));
            return $this->json(['message' => 'Zapisano kompetencję.', 'data' => $competency]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function deleteCompetency($id)
    {
        try {
            $this->competencies->deleteCompetency((int) $id);
            return $this->json(['message' => 'Usunięto kompetencję.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
