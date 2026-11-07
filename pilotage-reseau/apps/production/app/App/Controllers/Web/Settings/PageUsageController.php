<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Repositories\Settings\PageViewRepository;

/** Ustawienia › Licznik stron : które strony ERP są używane, przez kogo i jak często. */
class PageUsageController extends BaseController
{
    private const PERIODS = [7, 30, 90, 0];

    public function __construct(private PageViewRepository $views)
    {
    }

    public function index()
    {
        $days = (int) ($_GET['days'] ?? 30);
        if (!in_array($days, self::PERIODS, true)) $days = 30;
        $from = $days ? date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) : null;
        try {
            $rows = $this->views->report($from);
            $totals = $this->views->totals($from);
        } catch (\Throwable $e) {
            error_log('[page-usage] ' . $e->getMessage());
            $rows = [];
            $totals = ['since' => null, 'first' => null, 'views' => 0, 'users' => 0];
        }
        $this->view('settings/page_usage', ['rows' => $rows, 'totals' => $totals, 'days' => $days, 'periods' => self::PERIODS, 'from' => $from]);
    }
}
