<?php
declare(strict_types=1);

namespace App\Controllers\Web\Client;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\CustomersDataCheckService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Dane klientów » [385] : przegląd (klienci aktywni z brakami w karcie i tym, co psują), do wyjaśnienia (decyzja
 * dla klienta), zatwierdzenie. La page est sous /clients (droit existant « clients », celui de la fiche client
 * /clients/{id}/edit et du cennik client /clients/{id}/price-list, où se font les corrections), appels ajax sous le même
 * droit (préfixe /ajax/clients/data-check ajouté à ce droit : App ne contrôle que les pages) — aucun droit nouveau, aucun droit
 * changé. Les liens (fiche, cennik) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_customers_data_check et n_wizard_done : la fiche client n'est jamais modifiée, aucun e-mail ni SMS.
 */
final class CustomersDataCheckController extends BaseController
{
    use JsonErrors;

    public const PATH = CustomersDataCheckService::PATH;

    public function __construct(private CustomersDataCheckService $checks, private PageAccessService $access)
    {
    }

    public function index()
    {
        $uid = $this->userId();
        $this->view('client/customers_data_check', ['data' => $uid !== null && $this->access->canSee($uid, self::PATH) ? $this->withLinks($this->checks->data()) : null]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->checks->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_customer, decision: fixed|no_data|not_applicable, note, seen?: [codes]} — krok 2 : decyzja dla klienta. */
    public function decision()
    {
        try {
            $uid = $this->guard();
            $data = $this->checks->saveDecision($this->getJson(Request::createFromGlobals()), $uid);
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        try {
            $r = $this->checks->finish($this->guard());
            return $this->json(['message' => 'Dane klientów sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): int
    {
        $uid = $this->userId();
        if ($uid === null || !$this->access->canSee($uid, self::PATH)) throw new CustomException('Brak dostępu do klientów (asystent « Dane klientów »).', 'errors', 403);
        return $uid;
    }

    /** Liens vers les écrans d'origine (fiche client, cennik) : seulement ceux que l'utilisateur peut ouvrir. */
    private function withLinks(array $d): array
    {
        $uid = $this->userId();
        $seen = [];
        $can = function (?array $l) use ($uid, &$seen): bool {
            if ($l === null) return false;
            $path = (string) strtok($l['href'], '?#');
            return $seen[$path] ??= $this->access->canSee($uid, $path);
        };
        foreach (['items', 'review'] as $list) {
            foreach ($d[$list] as &$it) {
                if (!$can($it['link'])) $it['link'] = null;
                foreach ($it['problems'] as &$p) if (!$can($p['link'])) $p['link'] = null;
                unset($p);
            }
            unset($it);
        }
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
