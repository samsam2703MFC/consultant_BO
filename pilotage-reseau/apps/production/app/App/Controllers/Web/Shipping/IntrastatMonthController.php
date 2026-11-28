<?php
declare(strict_types=1);

namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Organization\Wizards\IntrastatMonthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asystent « Intrastat miesiąca » [398] (Kierownik produkcji, co miesiąc) : przegląd (wywóz z WDT, przywóz z faktur
 * dostawców UE, tabele i CSV), decyzja dla sygnału (kod CN, masa, kurs, VAT odbiorcy, deklaracja), zatwierdzenie. Droit
 * propre « intrastat » (page et appels ajax : App ne contrôle que les pages, les appels vérifient le même droit). Les liens
 * vers les écrans d'origine (karta klienta, karta surowca, faktury zakupowe, wagi produktów, parametry) ne sont donnés que si
 * l'utilisateur les voit. Écrit seulement les tables de l'assistant (n_intrastat_*) et n_wizard_done ; aucun XML officiel,
 * aucun appel extérieur, aucun envoi.
 */
final class IntrastatMonthController extends BaseController
{
    use JsonErrors;

    public const PATH = IntrastatMonthService::PATH;

    public function __construct(private IntrastatMonthService $intrastat, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('shipping/intrastat_month', ['data' => $this->withLinks($this->intrastat->data())]);
    }

    public function data()
    {
        try {
            $this->guard();
            return $this->json(['data' => $this->withLinks($this->intrastat->data())]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key, decision: completed|not_applicable|declared|below_threshold, note, seen?} — krok 2 : decyzja. */
    public function decision()
    {
        try {
            $this->guard();
            $data = $this->intrastat->saveDecision($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {key: product:<id>|material:<id>, cn_code, unit_mass_kg?} — kod CN (8 cyfr) i masa jednostki surowca. */
    public function code()
    {
        try {
            $this->guard();
            $data = $this->intrastat->saveCode($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano kod CN.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST {currency, rate} — kurs waluty na oceniany miesiąc (PLN za 1 jednostkę). */
    public function rate()
    {
        try {
            $this->guard();
            $data = $this->intrastat->saveRate($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano kurs.', 'data' => $this->withLinks($data)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** GET ?decl=decl:<nip>:<dispatch|arrival> — plik CSV deklaracji (do przepisania w PUESC). */
    public function csv()
    {
        try {
            $this->guard();
            $f = $this->intrastat->csv((string) ($_GET['decl'] ?? ''));
            return new Response($f['content'], 200, ['Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $f['name'] . '"', 'Content-Length' => (string) strlen($f['content'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST — krok 3 « Zakończ » : n_wizard_done, zadanie miesiąca zaliczone. */
    public function finish()
    {
        try {
            $this->guard();
            $r = $this->intrastat->finish($this->userId());
            return $this->json(['message' => 'Intrastat miesiąca gotowy — ' . $r['summary'] . '.', 'result' => ['summary' => $r['summary']], 'data' => $this->withLinks($r['data'])]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function guard(): void
    {
        if (!$this->access->canSee($this->userId(), self::PATH)) throw new CustomException('Brak dostępu do asystenta « Intrastat miesiąca ».', 'errors', 403);
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
        if (!$can($d['settings_link'])) $d['settings_link'] = null;
        return $d;
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
