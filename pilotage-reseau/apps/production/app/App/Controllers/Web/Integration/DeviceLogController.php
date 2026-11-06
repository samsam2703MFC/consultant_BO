<?php
declare(strict_types=1);

namespace App\Controllers\Web\Integration;

use App\Core\Web\BaseController;

/** Reserved UI route; device request logging will be added in a later increment. */
final class DeviceLogController extends BaseController
{
    public function overview(): void
    {
        $this->view('integration/api_device/logs');
    }
}
