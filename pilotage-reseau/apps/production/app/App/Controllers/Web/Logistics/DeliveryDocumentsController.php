<?php
namespace App\Controllers\Web\Logistics;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Communication\DocumentDistributionService;
use App\Services\Shipping\ShippingDocumentService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logistyka › Dokumenty dostawy : wysyłki dnia według Dystrybucji dokumentów — papier, który jedzie z towarem
 * (plik dla kierowców), i e-maile, które system wysyła sam.
 */
class DeliveryDocumentsController extends BaseController
{
    use JsonErrors;

    public function __construct(private DocumentDistributionService $distribution, private ShippingDocumentService $documents) {}

    public function index()
    {
        $this->view('logistics/documents', $this->distribution->shipmentDay(isset($_GET['date']) ? (string) $_GET['date'] : null));
    }

    /** Jeden plik z papierem wszystkich klientów dnia. */
    public function dayPdf()
    {
        try {
            $date = (string) ($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return $this->json(['message' => 'Nieprawidłowa data.'], 422);
            $file = $this->documents->generateDayFile($date);
            return new Response($file['file_string'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=' . $file['filename'],
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
