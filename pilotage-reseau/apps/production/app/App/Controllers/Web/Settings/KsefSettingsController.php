<?php

namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Services\Ksef\KsefEventLogService;
use App\Services\Ksef\KsefIntegrationSettingsService;
use Symfony\Component\HttpFoundation\Request;

final class KsefSettingsController extends BaseController
{
    public function __construct(private KsefIntegrationSettingsService $settings, private KsefEventLogService $events)
    {
    }

    public function index(): void
    {
        $this->view('settings/ksef', ['ksef' => $this->settings->getPublicSettings(), 'events' => $this->events->latest()]);
    }

    public function ajaxSave()
    {
        try {
            return $this->json($this->settings->save($this->getJson(Request::createFromGlobals())));
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function ajaxCheck()
    {
        try {
            return $this->json($this->settings->checkConnection());
        } catch (\Throwable $exception) {
            return $this->json(['ok' => false, 'message' => $exception->getMessage()], 502);
        }
    }
}
