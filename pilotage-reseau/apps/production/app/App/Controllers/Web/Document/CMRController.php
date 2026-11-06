<?php
namespace App\Controllers\Web\Document;

use App\Core\Web\BaseController;
use App\Services\Document\CMRService;
use App\Services\Document\WDTService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CMRController extends BaseController
{
    private CMRService $CMRService;

    public function __construct(CMRService $CMRService)
    {
        $this->CMRService = $CMRService;
    }

    public function ajaxGenerateDocument($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->CMRService->generateDocumentPDF($id);
        }, default: false);

        if (!$respDoc || !is_array($respDoc)) {
            return $this->json(['error' => implode('; ', $this->errors)], 500);
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

    public function ajaxGenerateDocumentAsia($id)
    {
        $respDoc = $this->handleAction(function () use ($id) {
            return $this->CMRService->generateDocumentPDFAsia($id);
        }, default: false);

        if (!$respDoc || !is_array($respDoc)) {
            return $this->json(['error' => implode('; ', $this->errors)], 500);
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
}