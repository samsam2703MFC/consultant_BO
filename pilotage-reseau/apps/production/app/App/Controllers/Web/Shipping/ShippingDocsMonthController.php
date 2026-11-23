<?php
declare(strict_types=1);

namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\ShippingDocsMonthService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Dokumenty wysyłek miesiąca » [402] (Kierownik produkcji, co miesiąc) : przegląd (wysyłki poprzedniego
 * miesiąca z brakami w WZ, CMR, WDT, różnicach i potwierdzeniach odbioru), decyzja dla wysyłki, zatwierdzenie. Droit
 * propre « shipping_docs_month » (page et appels ajax : App ne contrôle que les pages, les appels vérifient le même droit).
 * Les liens vers les écrans existants (Wysyłki i dokumenty, Różnice i problemy dostaw, Potwierdzenia dostaw, asystent PPWR)
 * ne sont donnés que si l'utilisateur les voit. Lecture seule hors de n_shipping_docs_review et n_wizard_done ; aucun
 * document changé, rien n'est envoyé (ni e-mail ni KSeF).
 */
final class ShippingDocsMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = ShippingDocsMonthService::PATH;

    public function __construct(private ShippingDocsMonthService $docs, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('shipping/shipping_docs_month', ['data' => $this->withLinks($this->docs->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->docs->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: checked|paper_ok|not_applicable, note, seen?} — krok 2 : decyzja dla wysyłki. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->docs->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->docs->finish($this->userId());
            return $this->json(['message' => 'Dokumenty wysyłek sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Dokumenty wysyłek miesiąca ».', 'errors', 403);
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
        if (!$can($d['ppwr_link']['href'])) $d['ppwr_link'] = null;
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
