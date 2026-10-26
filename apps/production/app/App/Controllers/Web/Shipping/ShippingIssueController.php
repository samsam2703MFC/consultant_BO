<?php
namespace App\Controllers\Web\Shipping;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Shipping\ShippingIssueService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Różnice i problemy dostaw : kolejka spraw do rozliczenia z klientem (różnica proforma ↔ WDT, problem zgłoszony
 * przez kierowcę) — odpowiedzialny, decyzja, termin, zamknięcie.
 */
class ShippingIssueController extends BaseController
{
    use JsonErrors;

    public function __construct(private ShippingIssueService $issues)
    {
    }

    /** GET /shipments/differences?status=open|decided|done|all&mine=1 */
    public function index()
    {
        $this->issues->sync();
        $this->view('shipping/issues', $this->issues->overview((string) ($_GET['status'] ?? 'open'), ($_GET['mine'] ?? '') === '1', $this->userId()));
    }

    public function lines($id)
    {
        try {
            return $this->json(['data' => $this->issues->lines((int) $id)]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function owner($id)
    {
        try {
            $owner = $this->getJson(Request::createFromGlobals())['owner_id'] ?? null;
            $this->issues->assign((int) $id, $owner === null || $owner === '' ? null : (int) $owner, $this->userId());
            return $this->json(['message' => 'Zapisano odpowiedzialnego.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function decision($id)
    {
        try {
            $data = $this->getJson(Request::createFromGlobals());
            $this->issues->decide((int) $id, (string) ($data['decision'] ?? ''), (string) ($data['note'] ?? ''), $this->userId());
            return $this->json(['message' => 'Zapisano decyzję — sprawa w realizacji.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function close($id)
    {
        try {
            $this->issues->close((int) $id, $this->userId());
            return $this->json(['message' => 'Sprawa zamknięta.']);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function reopen($id)
    {
        try {
            $this->issues->reopen((int) $id);
            return $this->json(['message' => 'Sprawa ponownie otwarta.']);
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
