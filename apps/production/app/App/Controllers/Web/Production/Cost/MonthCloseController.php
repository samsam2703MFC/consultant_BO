<?php
namespace App\Controllers\Web\Production\Cost;

use App\Core\Api\Workshop\WorkshopConfig;
use App\Core\Web\BaseController;
use App\Services\Production\Cost\MonthCloseService;

/**
 * Kreator « Zamknięcie miesiąca » (audit 2, P14) : /month-close/RRRR-MM — 8 kroków zamknięcia miesiąca kosztów na
 * jednej stronie. Strona tylko czyta stan ; akcje to istniejące wywołania (kalkulator kosztów, marże, karty
 * produktów, raport miesięczny) i dwie własne : rozpoczęcie miesiąca i kopia kategorii kosztów z poprzedniego miesiąca.
 */
class MonthCloseController extends BaseController
{
    use \App\Core\Web\JsonErrors;

    public function __construct(private MonthCloseService $close, private WorkshopConfig $config)
    {
    }

    /** GET /month-close : poprzedni miesiąc. */
    public function index()
    {
        header('Location: ' . ROOT . '/month-close/' . MonthCloseService::defaultMonth());
        exit;
    }

    public function show($ym)
    {
        try {
            $status = $this->close->status((string) $ym);
        } catch (\App\Exceptions\CustomException $e) {
            header('Location: ' . ROOT . '/month-close/' . MonthCloseService::defaultMonth());
            exit;
        }
        $this->view('production/month_close', [
            'mc' => $status,
            'api_base' => $this->config->webApiBase(ROOT),
            'session_user' => (int) user('id'),
        ]);
    }

    /** POST /ajax/month-close/{ym}/start : miesiąc w kalkulatorze + personel z budżetu. */
    public function ajaxStart($ym)
    {
        try {
            $r = $this->close->start((string) $ym);
            return $this->json(['message' => 'Miesiąc rozpoczęty : personel i koszty z budżetu.', 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/month-close/{ym}/copy/{category} : media, fixed, logistics z ostatniego miesiąca z kosztami. */
    public function ajaxCopy($ym, $category)
    {
        try {
            $r = $this->close->copyCategory((string) $ym, (string) $category);
            $n = $r['copied'];
            $word = $n === 1 ? 'pozycję' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 'pozycje' : 'pozycji');
            return $this->json(['message' => "Skopiowano {$n} {$word} z " . MonthCloseService::labelGenitive($r['from']) . '.', 'data' => $r]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
