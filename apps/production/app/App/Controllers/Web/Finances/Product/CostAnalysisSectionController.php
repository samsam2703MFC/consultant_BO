<?php
namespace App\Controllers\Web\Finances\Product;

use App\Core\Route;
use App\Core\Web\BaseController;
use App\Services\Finances\CostAnalysisSectionService;
use Symfony\Component\HttpFoundation\Request;

class CostAnalysisSectionController extends BaseController
{
    public function __construct(
        private CostAnalysisSectionService $costAnalysisSectionService
    ) {
    }

    #[Route('GET', '/finances/cost-analysis/section')]
    public function overview()
    {
        $request = Request::createFromGlobals();
        $sectionId = (int)$request->query->get('section_id', 0);
        $monthId = (int)$request->query->get('month_id', 0);

        $data = [
            'sections' => $this->costAnalysisSectionService->getSections(),
            'months' => $this->costAnalysisSectionService->getCostMonths(),
            'selected_section_id' => $sectionId,
            'selected_month_id' => $monthId,
            'analysis' => null
        ];

        if ($sectionId > 0) {
            $data['analysis'] = $this->costAnalysisSectionService->calculate($sectionId, $monthId);
        }

        $this->view('finances/cost-analysis/section_overview', $data);
    }
}

