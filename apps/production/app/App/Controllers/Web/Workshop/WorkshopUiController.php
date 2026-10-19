<?php
namespace App\Controllers\Web\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Routes\Api\WorkshopApiRoutes;
use App\Core\Web\BaseController;
use App\Services\Workshop\Auth\AuthService;
use App\Services\Workshop\Execution\TimesheetService;

/**
 * Pages web du module atelier. Elles ne lisent rien en base : le navigateur appelle l'API
 * /api/v1/production avec un jeton d'accès court délivré à l'utilisateur connecté à l'ERP.
 */
class WorkshopUiController extends BaseController
{
    public function __construct(private AuthService $auth, private WorkshopConfig $config, private TimesheetService $timesheets)
    {
    }

    public function products()
    {
        $this->view('workshop/products', ['api_base' => $this->apiBase()]);
    }

    public function planning()
    {
        $this->view('workshop/planning', ['api_base' => $this->apiBase()]);
    }

    public function gantt()
    {
        $this->view('workshop/gantt', ['api_base' => $this->apiBase()]);
    }

    public function optimizer()
    {
        $this->view('workshop/optimizer', ['api_base' => $this->apiBase()]);
    }

    /** Hala : tablety, identyfikatory, godziny pracy, wskaźniki (kierownik). */
    public function execution()
    {
        $this->view('workshop/execution', ['api_base' => $this->apiBase()]);
    }

    /** Ekran tabletu (bez logowania do ERP : tablet ma własny token, osoba identyfikator + PIN). */
    public function kiosk()
    {
        $this->view('workshop/kiosk', ['api_base' => $this->apiBase()]);
    }

    /** Telefon : wejście / wyjście po zeskanowaniu kodu QR z tabletu (strona publiczna, dane przez API). */
    public function kioskScan()
    {
        $this->view('workshop/kiosk_scan', ['api_base' => $this->apiBase()]);
    }

    /** Karta czasu pracy do druku, z podpisami z tabletu. */
    public function timesheet()
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
        try {
            $sheet = $this->timesheets->month((int) ($_GET['employee'] ?? 0), $month);
        } catch (ApiException $e) {
            http_response_code(404);
            echo 'Nie znaleziono pracownika.';
            return;
        }
        $images = [];
        $ids = array_filter([...array_column($sheet['days'], 'signature_id'), $sheet['month_signature']['id'] ?? null]);
        foreach ($ids as $id) {
            $png = $this->timesheets->signatureImage((int) $id);
            if ($png !== null) $images[$id] = 'data:image/png;base64,' . base64_encode($png);
        }
        $this->view('workshop/timesheet', ['sheet' => $sheet, 'images' => $images]);
    }

    /** Jeton d'accès pour la session ERP en cours (POST, même origine). */
    public function token()
    {
        header('Cache-Control: no-store');
        $userId = user('id');
        if (!$userId) return $this->json(['message' => 'Sesja wygasła — zaloguj się ponownie.'], 401);
        try {
            return $this->json(['data' => $this->auth->sessionToken((int) $userId)]);
        } catch (ApiException $e) {
            $lang = Translator::negotiate($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
            return $this->json(['description' => $e->errorCode(), 'message' => (new Translator($lang))->error($e->errorCode(), $e->params())], $e->httpStatus());
        }
    }

    private function apiBase(): string
    {
        $base = rtrim($this->config->apiBaseUrl(), '/');
        return ($base !== '' ? $base : rtrim(ROOT, '/')) . WorkshopApiRoutes::PREFIX;
    }
}
