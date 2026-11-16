<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Repositories\Organization\PageAccessRepository;
use App\Repositories\Settings\PageViewRepository;

/** Ustawienia › Licznik stron : które strony ERP są używane, przez kogo (osoba, profil [314]) i jak często (średnio na dzień i tydzień). */
class PageUsageController extends BaseController
{
    private const PERIODS = [7, 30, 90, 0];

    public function __construct(private PageViewRepository $views, private PageAccessRepository $positions)
    {
    }

    /** Kod profilu → nazwa (stanowiska, CEO, administrator). */
    private function profileNames(): array
    {
        $names = ['ceo' => 'CEO (kontrola)', 'admin' => 'Administrator (pełny widok)', 'none' => 'Bez stanowiska'];
        try {
            foreach ($this->positions->positions() as $p) $names[$p['code']] = $p['name_pl'];
        } catch (\Throwable) {
        }
        return $names;
    }

    public function index()
    {
        $days = (int) ($_GET['days'] ?? 30);
        if (!in_array($days, self::PERIODS, true)) $days = 30;
        $from = $days ? date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) : null;
        $profile = trim((string) ($_GET['profile'] ?? '')) ?: null;
        try {
            $rows = $this->views->report($from, $profile);
            $totals = $this->views->totals($from, $profile);
            $profiles = $this->views->profiles($from);
        } catch (\Throwable $e) {
            error_log('[page-usage] ' . $e->getMessage());
            $rows = $profiles = [];
            $totals = ['since' => null, 'first' => null, 'views' => 0, 'users' => 0];
        }
        // [314] Średnie : dni kalendarzowe okresu (od początku : od pierwszego dnia licznika), tydzień = 7 dni.
        $span = $days ?: ($totals['first'] ? (int) ((strtotime('today') - strtotime($totals['first'])) / 86400) + 1 : 1);
        $span = max(1, min($span, $totals['first'] ? (int) ((strtotime('today') - strtotime($totals['first'])) / 86400) + 1 : $span));
        $names = $this->profileNames();
        $this->view('settings/page_usage', ['rows' => $rows, 'totals' => $totals, 'days' => $days, 'periods' => self::PERIODS, 'from' => $from,
            'profiles' => $profiles, 'profile' => $profile, 'profile_names' => $names, 'span' => $span]);
    }
}
