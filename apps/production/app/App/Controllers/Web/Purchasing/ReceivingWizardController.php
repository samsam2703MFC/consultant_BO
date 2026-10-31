<?php
namespace App\Controllers\Web\Purchasing;

use App\Core\Web\BaseController;
use App\Services\Purchasing\ReceivingWizardService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kontrola przyjęć dnia [230] w ERP : podsumowanie dnia (dostawy sprawdzone na tablecie pakowania › Przyjęcia,
 * niezgodności ze zdjęciami, szkic e-maila do dostawcy — wysyła użytkownik) i zdjęcia niezgodności.
 */
class ReceivingWizardController extends BaseController
{
    public function __construct(private ReceivingWizardService $receiving)
    {
    }

    public function index()
    {
        $day = ReceivingWizardService::day(is_string($_GET['date'] ?? null) ? $_GET['date'] : null);
        $this->view('purchasing/receiving', ['data' => $this->receiving->summary($day), 'today' => date('Y-m-d'),
            'yesterday' => date('Y-m-d', strtotime('-1 day'))]);
    }

    /** Zdjęcie niezgodności (tylko zalogowani ; nagłówki bez cache publicznego). */
    public function photo($id)
    {
        $p = $this->receiving->photoFile((int) $id);
        if ($p === null) return new Response('Nie znaleziono zdjęcia.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        return new Response($p['content'], 200, ['Content-Type' => $p['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
