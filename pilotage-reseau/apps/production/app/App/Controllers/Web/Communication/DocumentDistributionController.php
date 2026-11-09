<?php
namespace App\Controllers\Web\Communication;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\DocumentDistributionService;
use App\Services\Communication\EmailAccountService;
use App\Services\Communication\MailDeliveryPolicy;
use Symfony\Component\HttpFoundation\Request;

/**
 * Komunikacja › Dystrybucja dokumentów : tabela klient × dokument (e-mail / papier z dostawą / SMS, adres, moment)
 * i historia wysyłek automatycznych. Zastępuje stronę « Automatyczne e-maile ».
 */
class DocumentDistributionController extends BaseController
{
    use JsonErrors;

    public function __construct(
        private DocumentDistributionService $distribution,
        private EmailAccountService $accounts,
        private MailDeliveryPolicy $policy,
        private ParamRepository $params,
    ) {
    }

    public function index()
    {
        $this->view('communication/distribution', [
            'matrix' => $this->distribution->matrix(),
            'recipient_labels' => DocumentDistributionService::RECIPIENT_LABELS,
            'recipient_short' => DocumentDistributionService::RECIPIENT_SHORT,
            'max_copies' => DocumentDistributionService::MAX_COPIES,
            'doc_labels' => array_map(fn($d) => $d['label'], DocumentDistributionService::DOCS),
            'delivery_mode' => $this->policy->mode(),
            'delivery_label' => $this->policy->label(),
            'has_account' => $this->accounts->active() !== [],
            'accounting_address' => $this->params->get('mail.accounting_address', ''),
            'client_language' => $this->params->get('mail.client_default_language', 'fr'),
        ]);
    }

    public function saveCell()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $row = $this->distribution->saveCell((int) ($input['customer'] ?? 0), (string) ($input['doc'] ?? ''), $input, $this->author());
            return $this->json(['message' => 'Zapisano.', 'data' => $row]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function saveMoment()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $moments = $this->distribution->saveMoment((string) ($input['doc'] ?? ''), (string) ($input['moment'] ?? ''), $this->author());
            return $this->json(['message' => 'Zapisano : moment wysłania dla wszystkich klientów.', 'data' => $moments]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function copy()
    {
        try {
            $input = $this->getJson(Request::createFromGlobals());
            $count = $this->distribution->copyClient((int) ($input['from'] ?? 0), (array) ($input['to'] ?? []), $this->author());
            return $this->json(['message' => "Skopiowano ustawienia do klientów : {$count}.", 'count' => $count]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    private function author(): ?string
    {
        $user = GlobalRegistry::get('user');
        return $user ? $user->getDisplayName() : (user('displayed_name') ?: null);
    }
}
