<?php

namespace App\Controllers\Web\Notification;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Notification\AppNotificationService;
use Symfony\Component\HttpFoundation\Request;

class AppNotificationController extends BaseController
{
    public function __construct(private AppNotificationService $service) {}

    public function active()
    {
        return $this->json(['data' => $this->service->active()]);
    }

    public function acknowledge($id)
    {
        try {
            $payload = $this->getJson(Request::createFromGlobals());
            $this->service->acknowledge((int) $id, $payload['via'] ?? 'read');
            return $this->json(['message' => 'Powiadomienie oznaczono jako odczytane.']);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return $this->json(['message' => 'Nie udało się zaktualizować powiadomienia.'], 500);
        }
    }

    public function acknowledgeAll()
    {
        try {
            $this->service->acknowledgeAll();
            return $this->json(['message' => 'Wszystkie powiadomienia oznaczono jako odczytane.']);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return $this->json(['message' => 'Nie udało się zaktualizować powiadomień.'], 500);
        }
    }
}
