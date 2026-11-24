<?php
declare(strict_types=1);

namespace App\Controllers\Web\Stock;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\StockMonthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asystent « Stany magazynowe na koniec miesiąca » [403] (Kierownik produkcji, co miesiąc) : zestawienie (instantané
 * enregistré), decyzja dla pozycji z sygnałem, « Przekazane do księgowości », zatwierdzenie ; PDF et CSV de l'instantané.
 * Droit propre « stock_month » (page, appels ajax et fichiers : App ne contrôle que les pages, les appels vérifient le même
 * droit). Les liens vers les écrans existants (Stany i palety, Ruchy magazynowe, Zaopatrzenie, Ceny surowców, Karta
 * produktu) ne sont donnés que si l'utilisateur les voit. Lecture seule hors de n_stock_month_* et n_wizard_done ; aucun
 * stock ni prix changé, aucun e-mail envoyé (la remise à la comptabilité est faite par l'humain).
 */
final class StockMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = StockMonthService::PATH;

    public function __construct(private StockMonthService $stock, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('stock/stock_month', ['data' => $this->withLinks($this->stock->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->stock->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 1 : zapis (albo odświeżenie) zestawienia na koniec ocenianego miesiąca. */
    public function snapshot()
    {
        try {
            $this->guard();
            return $this->json(['message' => 'Zestawienie zapisane.', 'data' => $this->withLinks($this->stock->snapshot($this->userId()))]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key: product:<id>|material:<id>|statement, decision: confirmed|to_correct|handed, note} — krok 2. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->stock->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
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
            $r = $this->stock->finish($this->userId());
            return $this->json(['message' => 'Stany magazynowe zestawione i przekazane — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET — PDF zapisanego zestawienia. */
    public function pdf()
    {
        try {
            $this->guard();
            $f = $this->stock->pdf();
            return new Response($f['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $f['name'] . '"', 'Content-Length' => (string) strlen($f['content'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET — CSV zapisanego zestawienia. */
    public function csv()
    {
        try {
            $this->guard();
            $f = $this->stock->csv();
            return new Response($f['content'], 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $f['name'] . '"', 'Content-Length' => (string) strlen($f['content'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Stany magazynowe na koniec miesiąca ».', 'errors', 403);
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
        foreach (['items', 'review'] as $list) {
            foreach ($d[$list] as &$it) $it['links'] = array_values(array_filter($it['links'], fn($l) => $can($l['href'])));
            unset($it);
        }
        $d['links'] = array_filter($d['links'], fn($l) => $can($l['href']));
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
