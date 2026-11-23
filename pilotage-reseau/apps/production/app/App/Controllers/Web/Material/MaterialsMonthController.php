<?php
declare(strict_types=1);

namespace App\Controllers\Web\Material;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\MaterialsMonthService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asystent « Kartoteka surowców » [401] (Kierownik produkcji, co miesiąc) : przegląd (surowce aktywnych receptur z brakami
 * w karcie, nowe surowce), decyzja dla surowca, zatwierdzenie. Droit propre « materials_month » (page et appels ajax : App
 * ne contrôle que les pages, les appels vérifient le même droit). Les liens vers les écrans où l'on complète (Karta
 * surowca, Ceny surowców, Dostawcy, Wartości odżywcze) ne sont donnés que si l'utilisateur les voit.
 * Lecture seule hors de n_materials_month_review, n_materials_month_mark et n_wizard_done ; aucune fiche changée, aucun e-mail.
 */
final class MaterialsMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = MaterialsMonthService::PATH;

    public function __construct(private MaterialsMonthService $materials, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('material/materials_month', ['data' => $this->withLinks($this->materials->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->materials->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: checked|supplier_asked|not_applicable, note, seen?} — krok 2 : decyzja dla surowca. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->materials->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->materials->finish($this->userId());
            return $this->json(['message' => 'Kartoteka surowców sprawdzona — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Kartoteka surowców ».', 'errors', 403);
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
        $d['links'] = array_values(array_filter($d['links'], fn($l) => $can($l['href'])));
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
