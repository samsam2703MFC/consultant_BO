<?php
namespace App\Controllers\Web\Communication;

use App\Core\Helpers\GlobalRegistry;
use App\Core\Web\BaseController;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\EmailAccountService;
use App\Services\Communication\CommunicationEmailDeliveryWorker;
use Symfony\Component\HttpFoundation\Request;

class CommunicationController extends BaseController
{
    public function __construct(private CommunicationService $service, private EmailAccountService $emailAccounts, private CommunicationEmailDeliveryWorker $deliveryWorker) {}
    private function author(): ?string { $user = GlobalRegistry::get('user'); return $user ? $user->getDisplayName() : null; }
    public function templates() { $this->view('communication/templates', ['templates' => $this->service->templates(), 'languages' => $this->service->languages(), 'templateTypes' => $this->service->templateTypes()]); }
    public function emailSettings() { $this->view('communication/email-settings', ['account' => $this->emailAccounts->active()]); }
    public function saveEmailSettings()
    {
        try { $this->emailAccounts->save($_POST); header('Location: ' . ROOT . '/communication/email-settings?saved=1'); exit; }
        catch (\Throwable $e) { $this->errors[] = $e->getMessage(); $this->emailSettings(); }
    }
    public function testEmailSettings()
    {
        try { return $this->json(['message' => 'Wiadomość testowa została przekazana do SMTP.', 'provider_message_id' => $this->emailAccounts->test((string)($_POST['recipient'] ?? ''))]); }
        catch (\Throwable $e) { return $this->json(['message' => $e->getMessage()], 422); }
    }
    public function composeProforma(int $id)
    {
        $this->compose('proforma', $id, 'proforma', ROOT . '/proforma-documents');
    }
    public function composeInvoice(int $id) { $this->compose('invoice', $id, 'invoice', ROOT . '/invoice-documents'); }
    public function composeWdt(int $id) { $this->compose('wdt', $id, 'wdt', ROOT . '/shipments'); }
    public function composeReminderProforma(int $id) { $this->compose('proforma', $id, $this->proformaReminderType((string)($_GET['type'] ?? 'payment_reminder')), ROOT . '/communication/payment-reminders'); }
    public function composeReminderDifference(int $id) { $this->compose('difference', $id, $_GET['type'] ?? 'payment_reminder', ROOT . '/communication/payment-reminders'); }

    private function compose(string $source, int $id, string $messageType, string $backUrl): void
    {
        try {
            $prepared = $this->service->prepareComposer($source, $id, $messageType);
            $prepared['document']['pdf_url'] = match ($source) {
                'proforma' => ROOT . '/ajax/proforma-documents/' . $id . '/document',
                'invoice' => ROOT . '/ajax/invoice-documents/' . $id . '/document',
                'wdt' => ROOT . '/ajax/wdt-documents/' . $id . '/document',
                'difference' => ROOT . '/ajax/difference-documents/' . $id . '/document',
            };
            $savePath = match (true) {
                $source === 'proforma' && in_array($messageType, ['proforma_payment_reminder', 'proforma_payment_reminder_final'], true) => 'reminder/proforma',
                $source === 'difference' && in_array($messageType, ['payment_reminder', 'payment_reminder_final'], true) => 'reminder/difference',
                default => $source,
            };
            $this->view('communication/compose', array_merge($prepared, [
                'languages' => $this->service->languages(),
                'selected_language' => $_GET['language'] ?? 'fr',
                'back_url' => $backUrl,
                'queue_success_redirect' => match ($source) {
                    'proforma' => ROOT . '/proforma-documents',
                    'invoice' => ROOT . '/invoice-documents',
                    'wdt' => ROOT . '/shipments',
                    default => ROOT . '/communication/payment-reminders',
                },
                'queue_success_redirect_label' => match ($source) {
                    'proforma' => 'Przejdź do przeglądu proform',
                    'invoice' => 'Przejdź do przeglądu faktur',
                    'wdt' => 'Przejdź do przeglądu wysyłek',
                    default => 'Wróć do przypomnień płatności',
                },
                'save_endpoint' => ROOT . '/ajax/communication/compose/' . $savePath . '/' . $id . '?message_type=' . rawurlencode($messageType),
            ]));
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->view('communication/compose', ['templates' => [], 'languages' => $this->service->languages(), 'message_type_label' => 'Wiadomość', 'back_url' => $backUrl]);
        }
    }
    public function saveProformaMessage(int $id)
    {
        return $this->saveMessage('proforma', $id, 'proforma');
    }
    public function saveInvoiceMessage(int $id) { return $this->saveMessage('invoice', $id, 'invoice'); }
    public function saveWdtMessage(int $id) { return $this->saveMessage('wdt', $id, 'wdt'); }
    public function saveReminderProformaMessage(int $id) { return $this->saveMessage('proforma', $id, $this->proformaReminderType((string)($_GET['message_type'] ?? 'payment_reminder'))); }
    public function saveReminderDifferenceMessage(int $id) { return $this->saveMessage('difference', $id, $_GET['message_type'] ?? 'payment_reminder'); }

    private function saveMessage(string $source, int $id, string $messageType)
    {
        try {
            if (!in_array($messageType, ['invoice', 'wdt', 'proforma', 'payment_reminder', 'payment_reminder_final', 'proforma_payment_reminder', 'proforma_payment_reminder_final'], true)) throw new \InvalidArgumentException('Nieprawidłowy typ wiadomości.');
            $result = $this->service->saveMessage($source, $id, $messageType, Request::createFromGlobals()->request->all(), $this->author());
            return $this->json($result + ['message' => $result['status'] === 'queued' ? 'Wiadomość została dodana do kolejki wysyłki.' : 'Wiadomość została zapisana jako szkic.']);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        }
    }
    public function messages() { $this->view('communication/messages', ['messages' => $this->service->messages()]); }
    public function sendMessageNow(int $id)
    {
        try {
            $result = $this->deliveryWorker->processMessageNow($id);
            if ($result['status'] !== 'sent') return $this->json($result, 422);
            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        }
    }
    public function message(int $id)
    {
        try { $this->view('communication/message', ['message' => $this->service->message($id)]); }
        catch (\Throwable $e) { $this->errors[] = $e->getMessage(); $this->messages(); }
    }
    public function saveTemplate(?int $id = null)
    {
        try {
            $postedId = isset($_POST['template_id']) && ctype_digit((string)$_POST['template_id']) ? (int)$_POST['template_id'] : null;
            $templateId = $id ?: $postedId;
            $this->service->saveTemplate($_POST, $templateId, $this->author());
            $status = $templateId ? 'updated' : 'created';
            header('Location: ' . ROOT . '/communication/templates?template_saved=' . $status);
            exit;
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage();
            $this->templates();
        }
    }
    public function reminder() { $this->view('communication/reminder', ['differences' => $this->service->selectableDifferences(), 'proformas' => $this->service->selectableUnpaidProformas(), 'languages' => $this->service->languages(), 'messages' => $this->service->messages()]); }
    public function ajaxComposeReminder(int $idDifference) { try { return $this->json($this->service->composeReminder($idDifference, $_GET['type'] ?? 'payment_reminder', $_GET['language'] ?? 'fr')); } catch (\Throwable $e) { return $this->json(['message' => $e->getMessage()], 422); } }
    public function saveReminderDraft() { try { $id = $this->service->saveDraft(Request::createFromGlobals()->request->all(), $this->author()); return $this->json(['id' => $id, 'message' => 'Wiadomość zapisana w audycie jako szkic.']); } catch (\Throwable $e) { return $this->json(['message' => $e->getMessage()], 422); } }

    private function proformaReminderType(string $type): string
    {
        return $type === 'payment_reminder_final' || $type === 'proforma_payment_reminder_final'
            ? 'proforma_payment_reminder_final'
            : 'proforma_payment_reminder';
    }
}
