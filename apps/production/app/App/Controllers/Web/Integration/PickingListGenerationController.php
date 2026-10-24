<?php
declare(strict_types=1);

namespace App\Controllers\Web\Integration;

use App\Core\Web\BaseController;
use App\Services\Integration\PickingListAuditService;

final class PickingListGenerationController extends BaseController
{
    public function __construct(private PickingListAuditService $audit)
    {
    }

    public function overview(): void
    {
        $this->view('integration/picking_list_generation/overview', ['generations' => $this->audit->getRecent()]);
    }

    public function show(int $id): void
    {
        $generation = $this->audit->getById($id);
        if ($generation === []) {
            http_response_code(404);
            $this->errors[] = 'Nie znaleziono wpisu historii wydruku.';
        }
        $this->view('integration/picking_list_generation/show', ['generation' => $generation]);
    }
}
