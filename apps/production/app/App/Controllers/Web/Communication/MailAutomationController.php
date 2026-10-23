<?php
namespace App\Controllers\Web\Communication;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\CommunicationEmailDeliveryWorker;
use App\Services\Communication\EmailAccountService;
use App\Services\Communication\MailAutomationService;
use App\Services\Communication\MailDeliveryPolicy;
use Symfony\Component\HttpFoundation\Request;

/** Page « Automatyczne e-maile » : règles, adresse de la comptabilité, aperçu, lancement, journal. */
class MailAutomationController extends BaseController
{
    public function __construct(
        private MailAutomationService $automation,
        private CommunicationEmailDeliveryWorker $worker,
        private EmailAccountService $accounts,
        private MailDeliveryPolicy $policy,
        private ParamRepository $params,
    ) {
    }

    public function index()
    {
        $this->view('communication/automations', [
            'rules' => $this->automation->rules(),
            'dispatches' => $this->automation->recentDispatches(),
            'delivery_mode' => $this->policy->mode(),
            'delivery_label' => $this->policy->label(),
            'has_account' => $this->accounts->active() !== [],
            'accounting_address' => $this->params->get('mail.accounting_address', ''),
            'client_language' => $this->params->get('mail.client_default_language', 'fr'),
        ]);
    }

    public function updateRule($id)
    {
        try {
            $rule = $this->automation->updateRule((int) $id, $this->getJson(Request::createFromGlobals()), $this->author());
            return $this->json(['message' => 'Zapisano.', 'data' => $rule]);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 422);
        }
    }

    public function preview($id)
    {
        try {
            return $this->json(['data' => $this->automation->preview((int) $id)]);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        }
    }

    public function saveSettings()
    {
        $input = $this->getJson(Request::createFromGlobals());
        $address = trim((string) ($input['accounting_address'] ?? ''));
        $language = (string) ($input['client_language'] ?? 'fr');
        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['message' => 'Nieprawidłowy adres biura rachunkowego.'], 422);
        }
        if (!in_array($language, ['fr', 'nl', 'pl', 'en'], true)) {
            return $this->json(['message' => 'Nieprawidłowy język.'], 422);
        }
        $user = GlobalRegistry::get('user');
        $this->params->setValue('mail.accounting_address', $address, null);
        $this->params->setValue('mail.client_default_language', $language, null);
        return $this->json(['message' => 'Zapisano.']);
    }

    /** Traite tout de suite les événements en attente, puis passe la file d'envoi. */
    public function runNow()
    {
        try {
            $dispatch = $this->automation->dispatch();
            $delivery = $this->worker->processDue(50);
            return $this->json(['message' => sprintf('W kolejce: %d, pominięte: %d, błędy: %d. Wysyłka: %d, nieudane: %d.',
                $dispatch['queued'], $dispatch['skipped'], $dispatch['failed'], $delivery['sent'], $delivery['failed']), 'dispatch' => $dispatch, 'delivery' => $delivery]);
        } catch (\Throwable $e) {
            error_log('[mail-automation] ' . $e->getMessage());
            return $this->json(['message' => $e->getMessage()], 500);
        }
    }

    public function retry($id)
    {
        $this->automation->retry((int) $id);
        return $this->json(['message' => 'Zdarzenie zostanie ponowione przy następnym przebiegu.']);
    }

    private function author(): ?string
    {
        $user = GlobalRegistry::get('user');
        return $user ? $user->getDisplayName() : (user('displayed_name') ?: null);
    }
}
