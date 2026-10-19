<?php
namespace App\Controllers\Web\Finances\Product;
use App\Core\Web\BaseController;
use App\Services\Finances\SalesByDepartmentService;
use Symfony\Component\HttpFoundation\Request;
class SalesByDepartmentController extends BaseController
{
    public function __construct(
        private SalesByDepartmentService $service
    ) {
    }
    /**
     * GET /finances/sales-by-department
     */
    public function overview(): void
    {
        $request    = Request::createFromGlobals();
        $defaults   = $this->service->getDefaultDateRange();
        $dateFrom   = $request->query->get('date_from', $defaults['from']);
        $dateTo     = $request->query->get('date_to',   $defaults['to']);
        $sectionId  = $request->query->get('section_id') ? (int)$request->query->get('section_id') : null;
        $departments    = $this->service->getDepartments();
        $sections       = $this->service->getSalesByDepartment($dateFrom, $dateTo, $sectionId);
        $sectionSummaries = $this->service->getSectionSummaries($dateFrom, $dateTo);
        $monthlyChart   = $this->service->getMonthlyChartData($dateFrom, $dateTo, $sectionId);
        $data = [
            'date_from'         => $dateFrom,
            'date_to'           => $dateTo,
            'section_id'        => $sectionId,
            'departments'       => $departments,
            'sections'          => $sections,
            'section_summaries' => $sectionSummaries,
            'monthly_chart'     => $monthlyChart,
        ];
        $this->view('finances/sales-by-department/overview', $data);
    }
}
