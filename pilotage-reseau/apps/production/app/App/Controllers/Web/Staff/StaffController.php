<?php
namespace App\Controllers\Web\Staff;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Dashboard\ProfitabilityService;
use App\Services\Staff\StaffBudgetService;
use App\Services\Staff\TeamBonusService;
use Symfony\Component\HttpFoundation\Request;

/** Zespół : budżet personelu (minimum + premie z % sprzedaży działu). */
class StaffController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private StaffBudgetService $budget,
        private ProfitabilityService $profitability,
        private TeamBonusService $teamBonus,
        private PageAccessService $access,
    ) {}

    /** [256] Premie zespołów : zapis tylko dla administratora (strona poza uprawnieniami stanowisk). */
    private function guardTeamBonus(): void
    {
        if (!$this->access->canSee($this->userId(), '/staff/team-bonus')) throw new CustomException('Premie zespołów ustala administrator.', 'errors', 403);
    }

    /** [256] Płace (koszt pracodawcy, minimum, % premii) : zapis tylko dla administratora. */
    private function guardBudget(): void
    {
        if (!$this->access->canSee($this->userId(), '/staff/budget')) throw new CustomException('Płace ustala administrator.', 'errors', 403);
    }

    public function savePay($id)
    {
        try {
            $this->guardBudget();
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
            $this->guardBudget();
            $this->budget->saveDefaultPct($this->getJson(Request::createFromGlobals())['value'] ?? null, $this->userId());
            return $this->json(['message' => 'Zapisano % premii.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveSectionPct($id)
    {
        try {
            $this->guardBudget();
            $input = $this->getJson(Request::createFromGlobals());
            $this->profitability->saveTarget((int) $id, ['bonus_pct' => $input['bonus_pct'] ?? ''], $this->userId());
            return $this->json(['message' => 'Zapisano % premii działu.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Premie zespołów : minimum i progi (sprzedaż na osobę → premia), zakładka « Premia » tabletu dla wybranych grup. */
    public function teamBonus()
    {
        $this->view('staff/team_bonus', $this->teamBonus->overview() + ['info_max' => TeamBonusService::INFO_MAX]);
    }

    public function saveTeamBonus()
    {
        try {
            $this->guardTeamBonus();
            $this->teamBonus->saveSettings($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano minimum i progi premii.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function teamBonusGroup($id)
    {
        try {
            $this->guardTeamBonus();
            $visible = $this->getJson(Request::createFromGlobals())['visible'] ?? false;
            $this->teamBonus->setVisible((int) $id, $visible);
            return $this->json(['message' => filter_var($visible, FILTER_VALIDATE_BOOLEAN) ? 'Zakładka « Premia » widoczna na tablecie grupy.' : 'Zakładka « Premia » ukryta na tablecie grupy.']);
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
