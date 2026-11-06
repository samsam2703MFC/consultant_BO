<?php
namespace App\Controllers\Web\Settings;

use App\Core\Web\BaseController;
use App\Exceptions\CustomException;
use App\Services\Mail\MailFooterService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Ustawienia › Stopki e-maili i tłumacz : stopka firmy w językach klientów, gdzie ją dodawać, stan tłumacza DeepL. */
class MailFooterController extends BaseController
{
    public function __construct(private MailFooterService $footers) {}

    public function index()
    {
        return $this->view('settings/mail_footers', []);
    }

    public function state()
    {
        return $this->run(fn() => $this->footers->state($this->userId()));
    }

    public function save()
    {
        return $this->run(fn() => $this->footers->save($this->userId(), $this->getJson(Request::createFromGlobals())));
    }

    public function saveKey()
    {
        return $this->run(fn() => $this->footers->saveKey($this->userId(), (string) ($this->getJson(Request::createFromGlobals())['key'] ?? '')));
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return $this->json($action());
        } catch (CustomException $e) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage(), 'fields' => $e->getErrors()['fields'] ?? null], ($code >= 400 && $code < 600) ? $code : 422);
        } catch (\Throwable $e) {
            error_log('[mail-footers] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
