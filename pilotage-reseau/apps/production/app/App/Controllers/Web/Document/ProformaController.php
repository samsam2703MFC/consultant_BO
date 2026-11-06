<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Client\Order\OrderService;
use App\Services\Document\ProformaService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProformaController extends BaseController
{
    private ProformaService $proformaService;

    public function __construct(
        ProformaService $proformaService,
        private OrderService $orderService
    )
    {
        $this->proformaService = $proformaService;
    }

    public function overview()
    {
        $data['rows'] = $this->proformaService->getGenerated();
        $this->view("document/proforma/overview", $data);
    }

    public function ajaxInsertProformaFromOrder($idOrder)
    {
        if (empty($idOrder)) {
            return $this->json(['message' => 'Brak danych wejściowych'], 400);
        }

        $result = $this->handleAction(function () use ($idOrder) {
            return $this->proformaService->insertFromOrder($idOrder);
        }, default: false);

        return $this->json($result, $result ? 200 : 500);
    }

    public function ajaxGenerateProformaDocument($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->proformaService->generateDocumentPDF($id);
        }, default: false);

        if (!$respDoc || !isset($respDoc['file_string'], $respDoc['filename'])) {
            return $this->json(['message' => $this->errors], 500);
        }

        $pdfData  = $respDoc['file_string'];
        $filename = htmlspecialchars($respDoc['filename']);

        return new Response(
            $pdfData,
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Content-Length'      => strlen($pdfData),
            ]
        );
    }

    public function ajaxMarkProformaPaid($id): Response
    {
        $result = $this->handleAction(function () use ($id) {
            return $this->proformaService->markPaid((int) $id);
        }, default: false);

        $status = (int) ($result['code'] ?? 500);
        if (!empty($result['ok'])) {
            return $this->json(['message' => 'Proforma została oznaczona jako opłacona. Jej pozycje są teraz niezmienne.'], 200);
        }

        return $this->json(['message' => $result['error'] ?? 'Nie udało się oznaczyć proformy jako opłaconej.'], $status);
    }

    public function ajaxRegenerateUnpaidProforma($idOrder): Response
    {
        try {
            $newProformaId = $this->orderService->regenerateUnpaidProforma((int) $idOrder);
            return $this->json([
                'data' => $newProformaId,
                'message' => 'Poprzednia proforma została anulowana, a nowa została wygenerowana z aktualnego zamówienia.'
            ], 200);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return $this->json(['message' => 'Nie udało się wygenerować nowej proformy.'], 500);
        }
    }
}
