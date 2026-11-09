<?php
namespace App\Controllers\Web\Client;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Client\ClientSpaceService;
use App\Services\Client\SalesGoalPdfService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Klienci › Strona klienta : dokumenty klienta i cel obrotu franczyzy (opcje w karcie klienta). */
class ClientSpaceController extends BaseController
{
    use JsonErrors;

    public function __construct(private ClientSpaceService $space, private SalesGoalPdfService $goalPdf) {}

    public function index($id)
    {
        $id = (int) $id;
        try {
            $client = $this->space->client($id);
        } catch (\Throwable $e) {
            header('Location: ' . ROOT . '/clients');
            exit;
        }
        $settings = $this->space->settings($id);
        $tab = in_array($_GET['tab'] ?? '', ['docs', 'goal'], true) ? $_GET['tab'] : ($settings['docs'] ? 'docs' : 'goal');
        $data = ['client' => $client, 'settings' => $settings, 'tab' => $tab];
        if ($tab === 'docs' && $settings['docs']) $data['docs'] = $this->space->documents($id, $_GET);
        if ($tab === 'goal' && $settings['goal']) {
            $data['goal'] = $this->space->goal($id, (string) ($_GET['month'] ?? $this->space->defaultMonth()), (string) ($_GET['source'] ?? 'wdt'));
            $data['months'] = $this->space->months();
            $data['category_coefficients'] = $this->space->categoryCoefficients();
        }
        $this->view('client/space', $data);
    }

    /** Zaznaczone dokumenty klienta w jednym PDF (?docs=wdt:12,proforma:5). */
    public function documentsPdf($id)
    {
        try {
            $docs = array_filter(explode(',', (string) ($_GET['docs'] ?? '')));
            return $this->pdf($this->space->documentsPdf((int) $id, $docs));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** PDF celu obrotu dla franczyzobiorcy, w jego języku. */
    public function goalPdf($id)
    {
        try {
            return $this->pdf($this->goalPdf->generate((int) $id, (string) ($_GET['month'] ?? ''), (string) ($_GET['source'] ?? 'wdt')));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** Współczynniki sprzedaży kategorii (dla wszystkich klientów). */
    public function saveCategoryCoefficients()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $saved = $this->space->saveCategoryCoefficients((array) ($input['items'] ?? []), $this->author());
            return $this->json(['message' => "Zapisano współczynniki kategorii : {$saved}."]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function pdf(array $file): Response
    {
        $name = preg_replace('/[^A-Za-z0-9_.\-]/', '_', (string) $file['filename']);
        return new Response($file['file_string'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    private function author(): ?string
    {
        $user = GlobalRegistry::get('user');
        return $user ? $user->getDisplayName() : (user('displayed_name') ?: user('login') ?: null);
    }
}
