<?php
declare(strict_types=1);

namespace App\Controllers\Web\Integration;

use App\Core\Web\BaseController;
use App\Services\Integration\InternalApiDeviceService;

final class InternalApiDeviceController extends BaseController
{
    public function __construct(private InternalApiDeviceService $devices)
    {
    }

    public function overview(): void
    {
        $this->view('integration/api_device/overview', ['devices' => $this->devices->getAll()]);
    }

    public function create(): void
    {
        $data = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $data = $this->devices->create($_POST, user('id') !== '' ? (int) user('id') : null);
                $this->successes[] = 'Urządzenie utworzone. Skopiuj klucz teraz — nie będzie ponownie widoczny.';
            } catch (\Throwable $exception) {
                $this->errors[] = $exception->getMessage();
            }
        }
        $this->view('integration/api_device/new', $data);
    }

    public function rotate(int $id): void
    {
        try {
            $data = $this->devices->rotateKey($id);
            $this->successes[] = 'Klucz został zrotowany. Poprzedni klucz przestał działać.';
            $this->view('integration/api_device/new', $data);
            return;
        } catch (\Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
        $this->overview();
    }

    public function toggle(int $id): void
    {
        try {
            $this->devices->toggle($id);
            $this->successes[] = 'Status urządzenia został zmieniony.';
        } catch (\Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }
        $this->overview();
    }
}
