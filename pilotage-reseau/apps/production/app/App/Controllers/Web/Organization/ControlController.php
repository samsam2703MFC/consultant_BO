<?php
declare(strict_types=1);

namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Services\Organization\ControlService;

/** [281] Kontrola (profil CEO, makieta 47) : tableau de contrôle de toute l'entreprise, en lecture seule. */
final class ControlController extends BaseController
{
    public function __construct(private ControlService $control)
    {
    }

    /** GET /control */
    public function index()
    {
        $userId = user('id') ? (int) user('id') : null;
        if (!$this->control->allowed($userId)) {
            http_response_code(403);
            $this->view('organization/forbidden', ['page_label' => 'Kontrola (profil CEO)', 'admin_only' => true]);
            return;
        }
        $this->view('organization/control', ['c' => $this->control->overview($userId)]);
    }
}
