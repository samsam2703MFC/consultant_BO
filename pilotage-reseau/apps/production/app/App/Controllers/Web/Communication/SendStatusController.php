<?php
namespace App\Controllers\Web\Communication;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Communication\SendStatusService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stan wysyłek : e-maile do klientów, wysyłki do sklepów franczyzy, e-maile automatyczne i monitoring — co nie
 * wyszło, co czeka ; « Ponów » jednej pozycji, « Rozwiązane » z powodem.
 */
class SendStatusController extends BaseController
{
    use JsonErrors;

    public function __construct(private SendStatusService $status)
    {
    }

    /** GET /communication/outbox?filter=todo|error|stuck|resolved|all&source=&days= */
    public function index()
    {
        $q = fn(string $k) => is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        $days = ctype_digit((string) $q('days')) ? (int) $q('days') : null;
        $this->view('communication/outbox', $this->status->overview($q('filter') ?? 'todo', $q('source'), $days));
    }

    /** POST /ajax/communication/outbox/{source}/{id}/retry */
    public function retry($source, $id)
    {
        try {
            return $this->json($this->status->retry((string) $source, (int) $id, $this->userId()));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    /** POST /ajax/communication/outbox/{source}/{id}/resolve {reason} */
    public function resolve($source, $id)
    {
        try {
            $reason = (string) ($this->getJson(Request::createFromGlobals())['reason'] ?? '');
            return $this->json($this->status->resolve((string) $source, (int) $id, $reason, $this->userId()));
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
