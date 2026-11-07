<?php
namespace App\Controllers\Web\Communication;

use App\Core\Web\BaseController;
use App\Services\Document\DocumentSampleService;
use App\Services\Utils\DocumentLabels;
use Symfony\Component\HttpFoundation\Response;

/** Strona « Wzory dokumentów » : przykład każdego wydruku PDF w językach klientów (dane fikcyjne). */
class DocumentTemplateController extends BaseController
{
    public function __construct(private DocumentSampleService $samples)
    {
    }

    public function index()
    {
        $this->view('communication/document_templates', [
            'groups' => $this->samples->catalogue(),
            'languages' => ['pl' => 'Polski', 'fr' => 'Français', 'nl' => 'Nederlands', 'en' => 'English'],
        ]);
    }

    public function preview($key)
    {
        try {
            $doc = $this->samples->render((string) $key, DocumentLabels::lang((string) ($_GET['lang'] ?? 'fr'), 'fr'));
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], $e->getCode() === 404 ? 404 : 500);
        }
        return new Response($doc['file_string'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename=' . $doc['filename'],
            'Content-Length' => strlen($doc['file_string']),
        ]);
    }
}
