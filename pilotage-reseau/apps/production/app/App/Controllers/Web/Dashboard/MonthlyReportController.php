<?php
declare(strict_types=1);

namespace App\Controllers\Web\Dashboard;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Repositories\Analysis\MonthlyReportRepository;
use App\Services\Analysis\MonthlyReportService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Raport miesięczny zarządu : strona, PDF (PL / FR) i wysyłka e-mailem. */
class MonthlyReportController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private MonthlyReportService $reports,
        private MonthlyReportRepository $repo,
    ) {}

    public function index()
    {
        $report = $this->reports->build(isset($_GET['month']) ? (string) $_GET['month'] : null);
        $this->view('dashboard/monthly_report', [
            'report' => $report,
            'months' => $this->reports->months(),
            'settings' => $this->reports->settings(),
            'history' => $this->repo->sentHistory(MonthlyReportService::MESSAGE_TYPE, 8),
        ]);
    }

    public function pdf()
    {
        $file = $this->reports->pdf(isset($_GET['month']) ? (string) $_GET['month'] : null, (string) ($_GET['lang'] ?? 'pl'));
        return new Response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
            'Content-Length' => strlen($file['content']),
        ]);
    }

    public function saveSettings()
    {
        try {
            $id = user('id');
            $settings = $this->reports->saveSettings($this->getJson(Request::createFromGlobals()), $id ? (int) $id : null);
            return $this->json(['message' => $settings['recipients'] === '' ? 'Wysyłka automatyczna wyłączona.' : 'Zapisano wysyłkę automatyczną.', 'data' => $settings]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function send()
    {
        try {
            $user = GlobalRegistry::get('user');
            $author = mb_substr($user ? (string) $user->getDisplayName() : 'ERP', 0, 160);
            $result = $this->reports->send($this->getJson(Request::createFromGlobals()), $author);
            return $this->json(['message' => 'Raport w kolejce do wysłania : ' . implode(', ', $result['recipients']) . '.', 'data' => $result]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }
}
