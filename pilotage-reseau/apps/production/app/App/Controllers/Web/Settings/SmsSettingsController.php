<?php
namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Communication\SmsSettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** [313] Ustawienia › SMS (SMSAPI, jak w CRM). */
class SmsSettingsController extends BaseController
{
    public function __construct(private SmsSettingsService $settings) {}

    public function index()
    {
        return $this->view('settings/sms', []);
    }

    public function state()
    {
        return $this->run(fn() => $this->settings->state($this->userId(), ($_GET['check'] ?? '') === '1'));
    }

    public function save()
    {
        return $this->run(fn() => $this->settings->save($this->userId(), $this->getJson(Request::createFromGlobals())));
    }

    public function saveKey()
    {
        return $this->run(fn() => $this->settings->saveKey($this->userId(), (string) ($this->getJson(Request::createFromGlobals())['key'] ?? '')));
    }

    public function test()
    {
        return $this->run(fn() => $this->settings->test($this->userId(), (string) ($this->getJson(Request::createFromGlobals())['phone'] ?? '')));
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (CustomException $e) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage()], ($code >= 400 && $code < 600) ? $code : 422);
        } catch (\Throwable $e) {
            error_log('[sms-settings] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->json(['message' => 'Błąd serwera — zmiany nie zostały zapisane.'], 500);
        }
    }

    private function userId(): int
    {
        $id = (int) user('id');
        if ($id <= 0) throw new CustomException('Zaloguj się ponownie.', 'errors', 401);
        return $id;
    }
}
