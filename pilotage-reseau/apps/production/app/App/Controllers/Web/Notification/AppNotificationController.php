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
        $limit = (int) ($_GET['limit'] ?? 3);
        return $this->json([
            'data' => $this->service->active($limit, $this->userId()),
            'total' => $this->service->countActive($this->userId()),
        ]);
    }

    public function acknowledge($id)
    {
        try {
            $payload = $this->getJson(Request::createFromGlobals());
            $this->service->acknowledge((int) $id, $payload['via'] ?? 'read', $this->userId());
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
            $this->service->acknowledgeAll($this->userId());
            return $this->json(['message' => 'Wszystkie powiadomienia oznaczono jako odczytane.']);
        } catch (CustomException $exception) {
            return $this->json(['message' => $exception->getMessage()], $exception->getCode() ?: 422);
        } catch (\Throwable $exception) {
            error_log((string) $exception);
            return $this->json(['message' => 'Nie udało się zaktualizować powiadomień.'], 500);
        }
    }

    /** Zalogowany użytkownik : dzwonek pokazuje wspólne powiadomienia i jego własne (przypomnienia zadań [232]). */
    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
