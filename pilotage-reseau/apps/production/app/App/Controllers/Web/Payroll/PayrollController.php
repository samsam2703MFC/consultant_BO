<?php
declare(strict_types=1);

namespace App\Controllers\Web\Payroll;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Exceptions\CustomException;
use App\Services\Organization\PageAccessService;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\PayslipPdf;
use Symfony\Component\HttpFoundation\Request;

/**
 * [277] Finanse › Płace : Lista płac (mois), Ustawienia płac (barèmes de l'année, compte de la société, données des
 * contrats). Accès « payroll » : administrateur, Administracja i fakturowanie. Barèmes et réouverture : administrateur.
 */
final class PayrollController extends BaseController
{
    use JsonErrors;

    public function __construct(private PayrollService $pay, private PageAccessService $access)
    {
    }

    public function index()
    {
        $this->view('payroll/index', ['tab' => 'month', 'is_admin' => $this->isAdmin()]);
    }

    public function settingsPage()
    {
        $this->view('payroll/index', ['tab' => 'settings', 'is_admin' => $this->isAdmin()]);
    }

    public function month()
    {
        return $this->guarded(fn() => $this->pay->month($this->monthParam(), $this->userId()));
    }

    public function saveAdjust($id)
    {
        return $this->guarded(fn() => $this->pay->saveAdjust($this->monthParam(), (int) $id, $this->input(), $this->userId()));
    }

    public function approve()
    {
        return $this->guarded(fn() => $this->pay->approve($this->monthParam(), $this->userId()));
    }

    public function reopen()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Listę płac otwiera ponownie administrator.', 'errors', 403);
            return $this->pay->reopen($this->monthParam(), $this->userId());
        });
    }

    public function rates()
    {
        return $this->guarded(fn() => ['rates' => $this->pay->rates($this->yearParam()), 'settings' => $this->pay->settings(), 'is_admin' => $this->isAdmin()]);
    }

    public function saveRates()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Stawki zmienia administrator.', 'errors', 403);
            return $this->pay->saveRates($this->yearParam(), $this->input(), $this->userId());
        });
    }

    public function copyRates()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Stawki zmienia administrator.', 'errors', 403);
            return $this->pay->copyRates($this->yearParam(), $this->userId());
        });
    }

    public function saveSettings()
    {
        return $this->guarded(function () {
            if (!$this->isAdmin()) throw new CustomException('Ustawienia płac zmienia administrator.', 'errors', 403);
            return $this->pay->saveSettings($this->input(), $this->userId());
        });
    }

    /** Paski PDF : ?month=RRRR-MM [&employee=ID]. */
    public function payslips()
    {
        return $this->file(function () {
            $id = (int) ($_GET['employee'] ?? 0);
            return PayslipPdf::render($this->pay->printable($this->monthParam(), $id ? [$id] : null, $this->userId())) + ['mime' => 'application/pdf', 'inline' => true];
        });
    }

    public function bankFile()
    {
        return $this->file(fn() => $this->pay->bankFile($this->monthParam(), ($_GET['date'] ?? '') ?: null, $this->userId()) + ['mime' => 'application/xml']);
    }

    public function csv()
    {
        return $this->file(fn() => $this->pay->csv($this->monthParam(), $this->userId()) + ['mime' => 'text/csv; charset=utf-8']);
    }

    private function file(callable $fn)
    {
        try {
            if (!$this->canPay()) throw new CustomException('Brak dostępu do płac.', 'errors', 403);
            $f = $fn();
        } catch (CustomException $e) {
            http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
            header('Content-Type: text/plain; charset=utf-8');
            return $e->getMessage();
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['filename']) ?: 'plik';
        header('Content-Type: ' . $f['mime']);
        header('Content-Disposition: ' . (!empty($f['inline']) ? 'inline' : 'attachment') . '; filename="' . $ascii . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        return $f['content'];
    }

    private function guarded(callable $fn)
    {
        try {
            if (!$this->canPay()) throw new CustomException('Brak dostępu do płac.', 'errors', 403);
            return $this->json(['data' => $fn()]);
        } catch (CustomException $e) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage(), 'fields' => $e->getErrors()['fields'] ?? null], $code >= 400 && $code < 600 ? $code : 422);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function monthParam(): string
    {
        $m = (string) ($_GET['month'] ?? '');
        return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : date('Y-m', strtotime('first day of last month'));
    }

    private function yearParam(): int
    {
        $y = (int) ($_GET['year'] ?? 0);
        return $y >= 2024 && $y <= 2100 ? $y : (int) date('Y');
    }

    private function canPay(): bool
    {
        return $this->access->canSee($this->userId(), '/payroll');
    }

    private function isAdmin(): bool
    {
        return $this->access->isAdmin($this->userId()) && $this->access->viewPosition($this->userId()) === null;
    }

    private function input(): array
    {
        return $this->getJson(Request::createFromGlobals());
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
