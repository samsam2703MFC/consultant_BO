<?php
declare(strict_types=1);

namespace App\Controllers\Web\Finances;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\LiquidityCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Płynność i gotówka » [373] : sytuacja 4 tygodni, plan (saldo, decyzje dla sygnałów), podsumowanie.
 * La page est sous /finances (droit « margins » : Finanse — faktury i koszty) ; les appels ajax vérifient le même droit
 * (App ne contrôle que les pages). Montants : profil Finanse et CEO, et Kierownik operacyjny (PageAccessService::seesLiquidityValues, [312] [373]).
 * Lecture seule hors de n_liquidity_balance et n_liquidity_decision ; aucun e-mail ni SMS.
 */
final class LiquidityCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = '/finances/liquidity';

    public function __construct(private LiquidityCheckService $liquidity, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('finances/liquidity_check', ['data' => $this->liquidity->data(null, $this->values())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->liquidity->data(null, $this->values())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {bank_pln, bank_eur, cash_pln, other: [4], date, note} — krok 2 : saldo dostępne. */
    public function balance()
    {
        try {
            $this->guard();
            $data = $this->liquidity->saveBalance($this->getJson(Request::createFromGlobals()), $this->userId(), $this->values());
            return $this->json(['message' => 'Saldo zapisane.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: monitor|action|no_risk, note} — krok 2 : decyzja dla sygnału. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->liquidity->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId(), $this->values());
            return $this->json(['message' => 'Zapisano.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie tygodnia zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->liquidity->finish($this->userId(), $this->values());
            return $this->json(['message' => 'Płynność sprawdzona — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $r['data']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do finansów.', 'errors', 403);
    }

    /** [312] [373] Kwoty : profil Finanse i CEO oraz Kierownik operacyjny (jego zadanie). */
    private function values(): bool
    {
        return $this->access->seesLiquidityValues($this->userId());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
