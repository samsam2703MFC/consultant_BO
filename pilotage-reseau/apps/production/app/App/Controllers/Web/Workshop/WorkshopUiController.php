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
        $this->view('workshop/products', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Magazyn › Stan magazynu (zakładki Magazyn) : widok « levels » aplikacji kart produktów. */
    public function stock()
    {
        $this->view('workshop/products', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id'), 'start_view' => 'levels', 'page_title' => 'Stan magazynu']);
    }

    /** Magazyn › Zapas MTS : kartony, palety i wartość zapasu wg grupy i kategorii. */
    public function stockMts()
    {
        $this->view('workshop/products', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id'), 'start_view' => 'stock', 'page_title' => 'Zapas MTS']);
    }

    /** Arkusz tygodnia : cała produkcja tygodnia na jednej kartce (jeden wiersz na produkt). */
    public function sheet()
    {
        $this->view('workshop/sheet', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Kierownik produkcji : karta stanowiska, cele, raport miesięczny, premia, rozmowa, audyt kompetencji. */
    public function manager()
    {
        $this->view('workshop/manager', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Zaopatrzenie : potrzeby surowców i opakowań z produkcji, stany, zamówienia do dostawców (mailem), przyjęcia. */
    public function supply()
    {
        $this->view('workshop/supply', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Zakupy tygodnia : co kupić na uzupełnienie stanów, zapytania ofertowe do 1–3 dostawców, oferty z minimum, wyniki operatora. */
    public function buy()
    {
        $this->view('workshop/buy', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Finanse › Faktury i koszty : przypisz nowe pozycje, zatwierdź faktury, sprawdź wzrosty cen (stanowisko Administracja i fakturowanie). */
    public function financeInvoices()
    {
        $this->view('workshop/finance', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id'), 'finance_view' => 'workspace']);
    }

    /** Finanse › Koszty usług miesiąca : faktury usług po kategoriach, brakujące koszty, zamknięcie → kalkulator kosztów. */
    public function financeCosts()
    {
        $this->view('workshop/finance', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id'), 'finance_view' => 'costs']);
    }

    /** Dostawy surowców (Produkcja) : kto dostarcza co i kiedy — tydzień produkcji i dostawcy. */
    public function deliveries()
    {
        $this->view('workshop/deliveries', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Plan zespołów na tydzień : kalendarz, produkcja, dostawy — PDF do druku i link na tablet zespołu. */
    public function teamSheet()
    {
        $this->view('workshop/team_sheet', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Stanowiska i coboty : typ, tryb robota (cobot = część osoby), pojemność, koszt godziny, aktywność. */
    public function workstations()
    {
        $this->view('workshop/workstations', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Tabela do wypełnienia : plik Excel z danymi pracowni (stanowiska, zmiany, zespoły, trasy, parametry) i jego import. */
    public function dataSheet()
    {
        $this->view('workshop/data_sheet', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    public function gantt()
    {
        $this->view('workshop/gantt', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Hala : tablety, identyfikatory, godziny pracy, wskaźniki (kierownik). */
    public function execution()
    {
        $this->view('workshop/execution', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /**
     * Tablety › Zatwierdzenia produkcji : dni zatwierdzone przez zespoły na tabletach (linie, straty ze
     * zdjęciami) ; kierownik produkcji poprawia ilość zrobioną i anuluje straty (tablet po zatwierdzeniu tylko czyta).
     */
    public function productionDays()
    {
        $this->view('workshop/production_days', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Tablety › Zamówienia między grupami : kompozycje zamawiane u grupy, która je robi (makieta 4). */
    public function groupOrders()
    {
        $this->view('workshop/group_orders', ['api_base' => $this->apiBase(), 'session_user' => (int) user('id')]);
    }

    /** Ekran tabletu (bez logowania do ERP : tablet ma własny token, osoba identyfikator + PIN). */
    public function kiosk()
    {
        $this->view('workshop/kiosk', ['api_base' => $this->apiBase()]);
    }

    /** Tablet kierowcy (PWA) : trasa dnia i potwierdzenia dostaw ; sama powłoka, dane przez API z tokenem tabletu. */
    public function driver()
    {
        $this->view('workshop/driver', ['api_base' => $this->apiBase()]);
    }

    /** Tablet pakowania (PWA) : pakowanie dnia wysyłki (kartony, sztuki, czas, licznik) ; sama powłoka, dane przez API z tokenem tabletu. */
    public function packing()
    {
        $this->view('workshop/packing', ['api_base' => $this->apiBase()]);
    }

    /** Tablet biurowy (PWA) : dział → nazwisko → osobisty kod QR (osoba + dzień) zatwierdzany telefonem ; sama powłoka. */
    public function office()
    {
        $this->view('workshop/office', ['api_base' => $this->apiBase()]);
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
        $ids = array_filter([...array_column($sheet['days'], 'signature_id'), ...array_column($sheet['days'], 'signature_in_id'), $sheet['month_signature']['id'] ?? null]);
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

    /**
     * Adresse de l'API pour les pages de l'ERP. Même hôte que l'ERP : on reprend ROOT, qui suit le schéma
     * de la requête (http / https) — une API restée en http derrière une page en https serait bloquée par
     * le navigateur (« Failed to fetch »). Autre hôte (API séparée) : API_BASE_URL tel quel.
     */
    private function apiBase(): string
    {
        return $this->config->webApiBase(ROOT);
    }
}
