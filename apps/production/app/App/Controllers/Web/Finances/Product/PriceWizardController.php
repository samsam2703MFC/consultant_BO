<?php
declare(strict_types=1);

namespace App\Controllers\Web\Finances\Product;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Finances\PricePublicationService;
use App\Services\Finances\PriceWizardService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kreator cen [218] (Finanse › Marże i koszty › Kreator cen) : 1. koszty i marże po fakturach, 2. nowe ceny wg marży
 * bazowej i współczynnika surowców kategorii, 3. publikacja od daty (ERP, strona franczyzy, e-mail z cennikiem).
 * Dostęp : strona « Marże i koszty » (PageAccess) ; parametry reguły : administrator.
 */
final class PriceWizardController extends BaseController
{
    use JsonErrors;

    public function __construct(private PriceWizardService $wizard, private PricePublicationService $publications)
    {
    }

    public function index()
    {
        $boot = ['is_admin' => $this->wizard->isAdmin($this->userId()), 'params' => $this->wizard->params()];
        $this->view('finances/price-wizard', ['boot' => json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)]);
    }

    /** GET /ajax/price-wizard/costs : krok 1. */
    public function costs()
    {
        return $this->respond(fn() => ['data' => $this->wizard->costs($this->months())]);
    }

    /** POST /ajax/price-wizard/recalculate : przeliczenie kosztów receptur, potem krok 1. */
    public function recalculate()
    {
        return $this->respond(function () {
            $this->wizard->assertAccess($this->userId());
            $r = $this->wizard->recalculate($this->userId());
            return ['message' => $r['message'], 'result' => $r, 'data' => $this->wizard->costs($this->months())];
        });
    }

    /** GET /ajax/price-wizard/prices : krok 2. */
    public function prices()
    {
        return $this->respond(fn() => ['data' => $this->wizard->prices($this->months())]);
    }

    /** PUT /ajax/price-wizard/categories {rows: [{id, margin, coef}]} : marża bazowa i współczynnik kategorii. */
    public function saveCategories()
    {
        return $this->respond(function () {
            $n = $this->wizard->saveCategories((array) ($this->input()['rows'] ?? []), $this->userId());
            return ['message' => $n === 1 ? 'Zapisano regułę kategorii.' : "Zapisano reguły kategorii : {$n}.", 'data' => $this->wizard->prices($this->months())];
        });
    }

    /** PUT /ajax/price-wizard/params : limit, zaokrąglenie, domyślny współczynnik, wyprzedzenie e-maila (administrator). */
    public function saveParams()
    {
        return $this->respond(fn() => ['message' => 'Zapisano parametry reguły cen.', 'params' => $this->wizard->saveParams($this->input(), $this->userId())]);
    }

    /** POST /ajax/price-wizard/preview {items} : krok 3 — ceny klientów, klienci, kanały. */
    public function preview()
    {
        return $this->respond(function () {
            $this->wizard->assertAccess($this->userId());
            return ['data' => $this->wizard->build((array) ($this->input()['items'] ?? []))];
        });
    }

    /** POST /ajax/price-wizard/publications {date, items, exclude, notify} : zaplanowanie publikacji. */
    public function plan()
    {
        return $this->respond(function () {
            $r = $this->wizard->plan($this->input(), $this->userId());
            return ['message' => $r['message'], 'data' => $r, 'publications' => $this->publications->publications()];
        }, 201);
    }

    /** GET /ajax/price-wizard/publications : zaplanowane i wysłane. */
    public function list()
    {
        return $this->respond(fn() => ['data' => $this->publications->publications()]);
    }

    /** GET /ajax/price-wizard/publications/{id} : ceny standardowe i klienci. */
    public function detail($id)
    {
        return $this->respond(fn() => ['data' => $this->publications->detail((int) $id)]);
    }

    /** POST /ajax/price-wizard/publications/{id}/cancel */
    public function cancel($id)
    {
        return $this->respond(function () use ($id) {
            $this->wizard->assertAccess($this->userId());
            return ['message' => $this->publications->cancel((int) $id, $this->userId()), 'publications' => $this->publications->publications()];
        });
    }

    /** GET /finances/price-wizard/publications/{id}/pdf?customer= : cennik klienta od daty. */
    public function pdf($id)
    {
        try {
            $this->wizard->assertAccess($this->userId());
            return $this->pdfResponse($this->publications->clientPdf((int) $id, (int) ($_GET['customer'] ?? 0)));
        } catch (\Throwable $e) {
            return $this->jsonError($this->pdfError($e));
        }
    }

    /** POST /ajax/price-wizard/preview-pdf {items, customer, date} : cennik klienta od daty przed zaplanowaniem. */
    public function previewPdf()
    {
        try {
            $this->wizard->assertAccess($this->userId());
            $in = $this->input();
            $customer = (int) ($in['customer'] ?? 0);
            $b = $this->wizard->build((array) ($in['items'] ?? []));
            $prices = [];
            foreach ($b['items'] as $i) if ($i['id_customer'] === $customer) $prices[$i['id_product']] = $i['new'];
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['date'] ?? '')) ? (string) $in['date'] : $b['default_date'];
            return $this->pdfResponse($this->publications->previewPdf($customer, $prices, $date));
        } catch (\Throwable $e) {
            return $this->jsonError($this->pdfError($e));
        }
    }

    private function pdfResponse(array $file): Response
    {
        $content = (string) $file['file_string'];
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['filename']);
        return new Response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Content-Length' => (string) strlen($content)]);
    }

    private function pdfError(\Throwable $e): \Throwable
    {
        if ($e instanceof CustomException && $e->getMessage() === 'PRICE_LIST_DATA_NOT_FOUND') return new CustomException('Klient nie ma cennika z cenami.', 'errors', 404);
        return $e;
    }

    private function months(): int
    {
        return max(1, min(24, (int) ($_GET['months'] ?? 12)));
    }

    private function respond(callable $fn, int $status = 200)
    {
        try {
            $this->wizard->assertAccess($this->userId());
            return $this->json($fn() + ['ok' => true], $status);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
