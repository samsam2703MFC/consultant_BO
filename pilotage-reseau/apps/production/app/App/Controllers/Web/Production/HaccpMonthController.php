<?php
declare(strict_types=1);

namespace App\Controllers\Web\Production;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\HaccpMonthService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « HACCP miesiąca » [380] : przegląd miesiąca (zdarzenia HACCP, przeglądy SOP, odchylenia SOP, temperatura w
 * dostawach), do wyjaśnienia (decyzja dla elementu), zatwierdzenie. La page est sous /production (droit existant
 * « production » : produkcja, partie, niezgodności — le même que le registre NC vers lequel l'assistant renvoie, accordé
 * à Jakość et au Kierownik produkcji) ; les appels ajax vérifient le même droit (App ne contrôle que les pages). Les liens
 * vers les écrans d'origine (module Kierownik produkcji, SOP, dostawy, NC) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_haccp_month_review et n_wizard_done ; aucun événement HACCP marqué « obsłużone », aucune NC
 * créée, aucun e-mail ni SMS.
 */
final class HaccpMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = HaccpMonthService::PATH;

    public function __construct(private HaccpMonthService $haccp, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('production/haccp_month', ['data' => $this->withLinks($this->haccp->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->haccp->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: explained|corrective|nc, note, id_responsible?, id_position?} — krok 2 : decyzja dla elementu. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->haccp->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->haccp->finish($this->userId());
            return $this->json(['message' => 'HACCP miesiąca przejrzany — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do produkcji (HACCP miesiąca).', 'errors', 403);
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
        foreach ($d['review'] as &$it) if (!$can($it['link']['href'])) $it['link'] = null;
        unset($it);
        foreach ($d['info'] as &$it) if (!$can($it['link']['href'])) $it['link'] = null;
        unset($it);
        if (!$can($d['nc_link'])) $d['nc_link'] = null;
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
