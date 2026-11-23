<?php
declare(strict_types=1);

namespace App\Controllers\Web\Audit;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\PpwrMonthService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « PPWR i opakowania » [397] (Kierownik produkcji, co miesiąc) : przegląd (braki PPWR / BDO opakowań i produktów,
 * zmiany, dowody wysyłek), decyzja dla elementu, zatwierdzenie. Droit propre « ppwr_month » (page et appels ajax : App ne
 * contrôle que les pages, les appels vérifient le même droit). Les liens vers les écrans où l'on complète (Rejestr PPWR,
 * Kartony sprzedażowe PPWR, Wagi produktów, Dane opakowań BDO, Wysyłki) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_ppwr_month_review et n_wizard_done ; aucune donnée PPWR / BDO changée, aucun e-mail envoyé.
 */
final class PpwrMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = PpwrMonthService::PATH;

    public function __construct(private PpwrMonthService $ppwr, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('audit/ppwr_month', ['data' => $this->withLinks($this->ppwr->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->ppwr->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: checked|supplier_asked|not_applicable, note} — krok 2 : decyzja dla elementu. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->ppwr->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->ppwr->finish($this->userId());
            return $this->json(['message' => 'PPWR i opakowania sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « PPWR i opakowania ».', 'errors', 403);
    }

    /** Liens vers les écrans d'origine : seulement ceux que l'utilisateur peut ouvrir (droits des stanowisk). */
    private function withLinks(array $d): array
    {
        $uid = $this->userId();
        $seen = [];
        $can = function (string $href) use ($uid, &$seen): bool {
            $path = (string) strtok($href, '?#');
            return $seen[$path] ??= $this->access->canSee($uid, $path);
        };
        foreach ($d['review'] as &$it) $it['links'] = array_values(array_filter($it['links'], fn($l) => $can($l['href'])));
        unset($it);
        $d['links'] = array_filter($d['links'], fn($l) => $can($l['href']));
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
