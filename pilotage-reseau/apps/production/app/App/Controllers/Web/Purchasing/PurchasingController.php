<?php
namespace App\Controllers\Web\Purchasing;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Purchasing\NcService;
use App\Services\Purchasing\OfferService;
use App\Services\Purchasing\TenderService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Zakupy surowców : koszty i oszczędności, oferty cenowe, zapytania ofertowe (e-mail do
 * dostawców), rejestr niezgodności.
 */
class PurchasingController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private CostOptimizationService $costs,
        private OfferService $offers,
        private TenderService $tenders,
        private NcService $nc,
        private MaterialCostRepository $lookup,
    ) {
    }

    public function overview()
    {
        $this->view('purchasing/overview', $this->costs->overview() + ['filter' => (string) ($_GET['filter'] ?? '')]);
    }

    public function material($id)
    {
        $this->view('purchasing/material', [
            'm' => $this->costs->material((int) $id),
            'suppliers' => $this->lookup->suppliers(),
        ]);
    }

    public function simulate($id)
    {
        try {
            $price = str_replace(',', '.', (string) ($_GET['price'] ?? ''));
            if (!is_numeric($price) || (float) $price < 0) return $this->json(['message' => 'Podaj cenę.'], 422);
            return $this->json(['data' => $this->costs->simulate((int) $id, (float) $price)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function offers()
    {
        $status = in_array($_GET['status'] ?? '', ['received', 'accepted', 'rejected'], true) ? $_GET['status'] : null;
        $this->view('purchasing/offers', [
            'offers' => $this->offers->list(null, null, $status),
            'status' => $status,
            'materials' => $this->costs->overview()['rows'],
            'suppliers' => $this->lookup->suppliers(),
            'preselect' => (int) ($_GET['material'] ?? 0),
        ]);
    }

    public function addOffer()
    {
        try {
            $id = $this->offers->add($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Zapisano ofertę.', 'id' => $id]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function applyOffer($id)
    {
        try {
            $this->offers->apply((int) $id);
            return $this->json(['message' => 'Cena surowca zaktualizowana, koszty receptur przeliczone.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function rejectOffer($id)
    {
        try {
            $this->offers->reject((int) $id);
            return $this->json(['message' => 'Oferta odrzucona.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function tenders()
    {
        $this->view('purchasing/tenders', [
            'tenders' => $this->tenders->list(),
            'materials' => $this->costs->overview()['rows'],
            'suppliers' => $this->lookup->suppliers(),
        ]);
    }

    public function createTender()
    {
        try {
            $id = $this->tenders->create($this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => 'Utworzono zapytanie.', 'id' => $id]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function tender($id)
    {
        $this->view('purchasing/tender', $this->tenders->detail((int) $id));
    }

    public function previewTender($id)
    {
        try {
            return $this->json(['data' => $this->tenders->preview((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function sendTender($id)
    {
        try {
            $count = $this->tenders->send((int) $id);
            return $this->json(['message' => $count ? "E-maile w kolejce wysyłki: {$count}." : 'Wszyscy dostawcy zostali już zaproszeni.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function recordReply($id)
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $count = $this->tenders->recordReply((int) $id, (int) ($input['supplier_id'] ?? 0), (array) ($input['rows'] ?? []), $this->userId());
            return $this->json(['message' => $count ? "Zapisano ofert: {$count}." : 'Brak cen do zapisania.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function awardTender($id)
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $result = $this->tenders->award((int) $id, (array) ($input['awards'] ?? []), (bool) ($input['notify'] ?? false));
            return $this->json(['message' => 'Rozstrzygnięto. Nowe ceny: ' . (count($result['applied']) ?: 'brak') . ($result['notified'] ? ', e-maile do dostawców: ' . $result['notified'] : '') . '.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function nc()
    {
        $status = in_array($_GET['status'] ?? '', NcService::STATUSES, true) ? $_GET['status'] : null;
        $this->view('purchasing/nc', [
            'items' => $this->nc->list($status),
            'counts' => $this->nc->counts(),
            'status' => $status,
            'materials' => $this->costs->overview()['rows'],
            'suppliers' => $this->lookup->suppliers(),
            'products' => $this->nc->products(),
            'users' => $this->nc->users(),
        ]);
    }

    public function saveNc($id = null)
    {
        try {
            $ncId = $this->nc->save($this->getJson(Request::createFromGlobals()), $id === null ? null : (int) $id, $this->userId());
            return $this->json(['message' => 'Zapisano niezgodność.', 'id' => $ncId]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
