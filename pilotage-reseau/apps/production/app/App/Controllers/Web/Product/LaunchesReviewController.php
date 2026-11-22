<?php
declare(strict_types=1);

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\LaunchesReviewService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Nowe produkty » [384] : przegląd wprowadzeń (cele vs wynik), do wyjaśnienia (decyzja dla produktu),
 * zatwierdzenie. La page est sous /products (droit existant « products » : receptury, produkty w rozwoju — là où naissent
 * les lancements et où se font les corrections de recette), appels ajax sous le même droit (App ne contrôle que les pages) :
 * le module Kierownik produkcji, où vivent les objectifs, est réservé à l'administrateur. Montants (coûts, prix, marges) :
 * règle des écrans de marges (droit « margins », canSee sur Marża produktów) — sans elle, ils ne sont pas renvoyés. Les
 * liens (Marża produktu, module Kierownik produkcji) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_launch_review et n_wizard_done ; n_mgr_launch.decision n'est jamais écrite, aucun e-mail ni SMS.
 */
final class LaunchesReviewController extends BaseController
{
    use JsonErrors;

    public const PATH = LaunchesReviewService::PATH;

    public function __construct(private LaunchesReviewService $launches, private PageAccessService $access)
    {
    }

    public function index()
    {
        $uid = $this->userId();
        $this->view('product/launches_review', ['data' => $uid !== null && $this->access->canSee($uid, self::PATH) ? $this->withLinks($this->launches->data(null, $this->values($uid))) : null]);
    }

    public function data()
    {
        try {
            $uid = $this->guard();
            return $this->json(['data' => $this->withLinks($this->launches->data(null, $this->values($uid)))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {id_product, decision: keep|fix|stop, note, fix_area?} — krok 2 : decyzja dla produktu. */
    public function decision()
    {
        try {
            $uid = $this->guard();
            $data = $this->launches->saveDecision($this->getJson(Request::createFromGlobals()), $uid, null, $this->values($uid));
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        try {
            $uid = $this->guard();
            $r = $this->launches->finish($uid, null, $this->values($uid));
            return $this->json(['message' => 'Nowe produkty przejrzane — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): int
    {
        $uid = $this->userId();
        if ($uid === null || !$this->access->canSee($uid, self::PATH)) throw new CustomException('Brak dostępu do produktów (asystent « Nowe produkty »).', 'errors', 403);
        return $uid;
    }

    /** Montants : comme les écrans de marges (droit « margins »). */
    private function values(int $uid): bool
    {
        return $this->access->canSee($uid, LaunchesReviewService::MARGIN_LINK);
    }

    /** Liens vers les écrans d'origine : seulement ceux que l'utilisateur peut ouvrir (droits des stanowisk). */
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
            foreach ($d[$list] as &$it) if (!$can($it['link'])) $it['link'] = null;
            unset($it);
        }
        if (!$can($d['manager_link'])) $d['manager_link'] = null;
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
