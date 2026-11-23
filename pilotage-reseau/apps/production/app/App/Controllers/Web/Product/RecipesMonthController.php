<?php
declare(strict_types=1);

namespace App\Controllers\Web\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\RecipesMonthService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Receptury i dane produktów » [400] (Kierownik produkcji, co miesiąc) : przegląd (produkty aktywne bez
 * receptury, z niepełną recepturą albo danymi), decyzja dla produktu, zatwierdzenie. Droit propre « recipes_month » (page
 * et appels ajax : App ne contrôle que les pages, les appels vérifient le même droit). Les liens vers les écrans où l'on
 * complète (Karta produktu, receptura, Wagi produktów…) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_recipes_month_review et n_wizard_done ; aucune recette ni fiche produit changée.
 */
final class RecipesMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = RecipesMonthService::PATH;

    public function __construct(private RecipesMonthService $recipes, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('product/recipes_month', ['data' => $this->withLinks($this->recipes->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->recipes->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: checked|assigned|not_applicable, note, seen?} — krok 2 : decyzja dla produktu. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->recipes->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->recipes->finish($this->userId());
            return $this->json(['message' => 'Receptury i dane produktów sprawdzone — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Receptury i dane produktów ».', 'errors', 403);
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
        if (!$can($d['pdf']['href'])) $d['pdf'] = null;
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
