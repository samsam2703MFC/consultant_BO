<?php
namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Communication\CommunicationMessageRepository;
use App\Repositories\Communication\CommunicationMessageAttachmentRepository;
use App\Repositories\Communication\CommunicationIncomingMessageRepository;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Document\Proforma\ProformaRepository;
use App\Services\Document\InvoiceService;
use App\Services\Document\OrderShippingDifferenceService;
use App\Services\Document\ProformaService;
use App\Services\Document\WDTService;

class CommunicationService
{
    private const LANGUAGES = [
        'fr' => 'Francuski',
        'nl' => 'Niderlandzki',
    ];

    private const TEMPLATE_TYPES = [
        'invoice' => 'Faktura',
        'proforma' => 'Proforma',
        'wdt' => 'WDT',
        'delivery_difference' => 'Dokument różnic',
        'payment_reminder' => 'Przypomnienie',
        'payment_reminder_final' => 'Stanowcze przypomnienie',
        'proforma_payment_reminder' => 'Przypomnienie o płatności — proforma',
        'proforma_payment_reminder_final' => 'Stanowcze przypomnienie o płatności — proforma',
    ];

    public function __construct(
        private CommunicationTemplateRepository $templates,
        private CommunicationMessageRepository $messages,
        private PaymentReminderContextService $contexts,
        private ProformaEmailContextService $proformaContexts,
        private DocumentEmailContextService $documentContexts,
        private TemplateRenderer $renderer,
        private EmailAccountService $emailAccounts,
        private ProformaService $proformas,
        private InvoiceService $invoices,
        private WDTService $wdts,
        private OrderShippingDifferenceService $differences,
        private ProformaRepository $proformaRepository,
        private CommunicationMessageAttachmentRepository $attachments,
        private CommunicationIncomingMessageRepository $incoming
    ) {}

    public function templates(): array { return $this->templates->getAllForManagement(); }
    public function selectableDifferences(): array
    {
        return array_values(array_filter($this->contexts->selectableDifferences(), static fn(array $row): bool => (float) ($row['difference_amount'] ?? 0) > 0));
    }
    public function selectableUnpaidProformas(): array { return $this->proformaRepository->getUnpaidForReminder(); }
    public function languages(): array { return self::LANGUAGES; }
    public function templateTypes(): array { return self::TEMPLATE_TYPES; }

    public function saveTemplate(array $data, ?int $id, ?string $author): bool
    {
        foreach (['template_type', 'language_code', 'name', 'subject_template', 'body_template'] as $key) if (trim((string)($data[$key] ?? '')) === '') throw new CustomException('Uzupełnij pole: ' . $key, 'errors', 422);
        if (!array_key_exists($data['language_code'], self::LANGUAGES)) throw new CustomException('Nieprawidłowy język szablonu.', 'errors', 422);
        if (!array_key_exists($data['template_type'], self::TEMPLATE_TYPES)) throw new CustomException('Nieprawidłowy typ szablonu.', 'errors', 422);
        $payload = [
            'template_type' => $data['template_type'], 'language_code' => $data['language_code'], 'name' => $data['name'],
            'subject_template' => $data['subject_template'], 'body_template' => $data['body_template'], 'is_active' => !empty($data['is_active']),
            'is_default' => !empty($data['is_default']), 'created_by' => $author
        ];
        if ($id !== null) return $this->templates->updateTemplate($id, $payload);
        return (bool)$this->templates->insert($payload);
    }

    public function composeReminder(int $differenceId, string $type, string $language = 'fr'): array
    {
        if (!in_array($type, ['payment_reminder', 'payment_reminder_final'], true)) throw new CustomException('Nieprawidłowy typ przypomnienia.', 'errors', 422);
        if (!array_key_exists($language, self::LANGUAGES)) throw new CustomException('Nieprawidłowy język szablonu.', 'errors', 422);
        $template = $this->templates->getDefault($type, $language);
        if (!$template) throw new CustomException('Brak aktywnego szablonu dla wybranego przypomnienia.', 'errors', 404);
        $context = $this->contexts->forDifference($differenceId);
        return array_merge($context, ['template' => $template, 'message_type' => $type,
            'subject' => $this->renderer->render($template['subject_template'], $context['variables']),
            'body' => $this->renderer->render($template['body_template'], $context['variables'])]);
    }

    public function prepareProformaComposer(int $proformaId): array
    {
        return $this->prepareComposer('proforma', $proformaId, 'proforma');
    }

    public function prepareComposer(string $source, int $sourceId, string $messageType): array
    {
        $context = $this->contextFor($source, $sourceId);
        if (!array_key_exists($messageType, self::TEMPLATE_TYPES)) {
            throw new CustomException('Nieprawidłowy typ wiadomości.', 'errors', 422);
        }
        $this->assertReminderEligibility($source, $messageType, $context);
        $templates = [];

        foreach ($this->templates->getActiveForType($messageType) ?: [] as $template) {
            try {
                $templates[] = array_merge($template, [
                    'subject' => $this->renderer->render($template['subject_template'], $context['variables']),
                    'body' => $this->renderer->render($template['body_template'], $context['variables']),
                    'render_error' => null,
                ]);
            } catch (CustomException $exception) {
                $templates[] = array_merge($template, [
                    'subject' => '',
                    'body' => '',
                    'render_error' => $exception->getMessage(),
                ]);
            }
        }

        $labels = [
            'invoice' => 'Faktura', 'wdt' => 'WDT', 'proforma' => 'Proforma',
            'payment_reminder' => 'Przypomnienie o płatności',
            'payment_reminder_final' => 'Stanowcze przypomnienie',
            'proforma_payment_reminder' => 'Przypomnienie o płatności za proformę',
            'proforma_payment_reminder_final' => 'Stanowcze przypomnienie za proformę',
        ];
        return array_merge($context, ['templates' => $templates, 'message_type' => $messageType, 'message_type_label' => $labels[$messageType] ?? 'Wiadomość']);
    }

    public function saveDraft(array $data, ?string $author): int
    {
        $composed = $this->composeReminder((int)$data['difference_id'], $data['message_type'], $data['language_code'] ?? 'fr');
        $subject = trim((string)($data['subject'] ?? $composed['subject']));
        $body = trim((string)($data['body'] ?? $composed['body']));
        $recipient = trim((string)($data['recipient_email'] ?? $composed['recipient_email']));
        if ($subject === '' || $body === '' || $recipient === '') throw new CustomException('Temat, treść i adres odbiorcy są wymagane.', 'errors', 422);
        $id = $this->messages->insert([
            'message_type' => $composed['message_type'], 'template_id' => $composed['template']['id'], 'template_version' => $composed['template']['version'],
            'id_customer' => $composed['id_customer'], 'id_difference' => $composed['id_difference'], 'id_wdt' => $composed['id_wdt'],
            'recipient_email' => $recipient, 'subject_text' => $subject, 'body_text' => $body,
            'variables_json' => json_encode($composed['variables'], JSON_UNESCAPED_UNICODE),
            'was_edited' => $subject !== $composed['subject'] || $body !== $composed['body'], 'status' => 'draft', 'created_by' => $author
        ]);
        return (int)$id;
    }

    /** Saves an immutable copy of the composed proforma. Queueing is deliberately separate from SMTP delivery. */
    public function saveProformaMessage(int $proformaId, array $data, ?string $author): array
    {
        return $this->saveMessage('proforma', $proformaId, 'proforma', $data, $author);
    }

    /** Saves a document/reminder snapshot and optionally queues it for SMTP delivery. */
    public function saveMessage(string $source, int $sourceId, string $messageType, array $data, ?string $author): array
    {
        $context = $this->contextFor($source, $sourceId);
        $this->assertReminderEligibility($source, $messageType, $context);
        $templateId = filter_var($data['template_id'] ?? null, FILTER_VALIDATE_INT);
        $template = $templateId ? $this->templates->first(['id' => $templateId, 'template_type' => $messageType, 'is_active' => 1]) : [];
        if ($template === []) throw new CustomException('Wybierz aktywny szablon odpowiedniego typu.', 'errors', 422);

        $defaultSubject = $this->renderer->render($template['subject_template'], $context['variables']);
        $defaultBody = $this->renderer->render($template['body_template'], $context['variables']);
        $recipient = trim((string)($data['recipient_email'] ?? ''));
        $subject = trim((string)($data['subject'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new CustomException('Podaj prawidłowy adres odbiorcy.', 'errors', 422);
        if ($subject === '' || $body === '') throw new CustomException('Temat i treść wiadomości są wymagane.', 'errors', 422);

        $action = ($data['action'] ?? 'queue') === 'draft' ? 'draft' : 'queue';
        $account = $this->emailAccounts->active();
        if ($action === 'queue' && $account === []) throw new CustomException('Przed przekazaniem wiadomości do wysyłki skonfiguruj aktywne konto e-mail.', 'errors', 422);

        $domain = $account !== [] && str_contains($account['from_email'], '@') ? substr(strrchr($account['from_email'], '@'), 1) : 'localhost';
        $messageId = '<slfood-' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $messageIdDb = $this->messages->insert([
            'message_type' => $messageType,
            'template_id' => $template['id'],
            'template_version' => $template['version'],
            'id_customer' => $context['id_customer'],
            'id_proforma' => $context['id_proforma'] ?? null,
            'id_invoice' => $context['id_invoice'] ?? null,
            'id_wdt' => $context['id_wdt'] ?? null,
            'id_difference' => $context['id_difference'] ?? null,
            'id_email_account' => $account['id'] ?? null,
            'recipient_email' => $recipient,
            'sender_email' => $account['from_email'] ?? null,
            'reply_to_email' => $account['reply_to_email'] ?? null,
            'subject_text' => $subject,
            'body_text' => $body,
            'variables_json' => json_encode($context['variables'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'was_edited' => $subject !== $defaultSubject || $body !== $defaultBody || $recipient !== $context['recipient_email'],
            'status' => $action === 'queue' ? 'queued' : 'draft',
            'message_id_header' => $messageId,
            'created_by' => $author,
            'queued_at' => $action === 'queue' ? date('Y-m-d H:i:s') : null,
            'next_try_at' => $action === 'queue' ? date('Y-m-d H:i:s') : null,
        ]);
        $id = (int) $messageIdDb;
        try {
            $pdf = $this->generateSourcePdf($source, $sourceId);
            $content = (string)($pdf['file_string'] ?? '');
            if (!str_starts_with(ltrim($content), '%PDF')) throw new \RuntimeException('Generator dokumentu nie zwrócił prawidłowego pliku PDF.');
            $this->attachments->insert([
                'id_message' => $id,
                'filename' => (string)($pdf['filename'] ?? ('DOKUMENT_' . $context['document']['number'] . '.pdf')),
                'mime_type' => 'application/pdf',
                'content_sha256' => hash('sha256', $content),
                'content' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->messages->update($id, ['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw new CustomException('Nie udało się przygotować załącznika PDF: ' . $e->getMessage(), 'errors', 500);
        }
        return ['id' => $id, 'status' => $action === 'queue' ? 'queued' : 'draft'];
    }

    public function messages(): array { return $this->messages->recent(); }

    public function message(int $id): array
    {
        $message = $this->messages->find($id);
        if ($message === []) throw new CustomException('Nie znaleziono wiadomości.', 'errors', 404);
        $message['incoming'] = $this->incoming->forMessage($id);
        $message['attachments'] = $this->attachments->metadataForMessage($id);
        return $message;
    }

    private function contextFor(string $source, int $sourceId): array
    {
        return match ($source) {
            'proforma' => $this->proformaContexts->forProforma($sourceId),
            'invoice' => $this->documentContexts->forInvoice($sourceId),
            'wdt' => $this->documentContexts->forWdt($sourceId),
            'difference' => $this->contexts->forDifference($sourceId),
            default => throw new CustomException('Nieprawidłowe źródło dokumentu.', 'errors', 422),
        };
    }

    private function generateSourcePdf(string $source, int $sourceId): array
    {
        return match ($source) {
            'proforma' => $this->proformas->generateDocumentPDF($sourceId),
            'invoice' => $this->invoices->generateDocumentPDF($sourceId),
            'wdt' => $this->wdts->generateDocumentPDF($sourceId),
            'difference' => $this->differences->generateDocumentPDF($sourceId),
            default => throw new CustomException('Nieprawidłowe źródło załącznika.', 'errors', 422),
        };
    }

    private function assertReminderEligibility(string $source, string $messageType, array $context): void
    {
        $differenceReminderTypes = ['payment_reminder', 'payment_reminder_final'];
        $proformaReminderTypes = ['proforma_payment_reminder', 'proforma_payment_reminder_final'];
        if (!in_array($messageType, array_merge($differenceReminderTypes, $proformaReminderTypes), true)) return;
        if (in_array($messageType, $differenceReminderTypes, true) && $source !== 'difference') {
            throw new CustomException('Szablon przypomnienia dokumentu różnic nie może zostać użyty dla proformy.', 'errors', 422);
        }
        if (in_array($messageType, $proformaReminderTypes, true) && $source !== 'proforma') {
            throw new CustomException('Szablon przypomnienia proformy może zostać użyty wyłącznie dla proformy.', 'errors', 422);
        }
        if ($source === 'proforma' && in_array($context['proforma_status'] ?? '', ['paid', 'cancelled'], true)) {
            throw new CustomException('Nie można wysłać przypomnienia dla opłaconej lub anulowanej proformy.', 'errors', 422);
        }
        if ($source === 'difference' && (float) ($context['difference_amount'] ?? 0) <= 0) {
            throw new CustomException('Przypomnienie można wysłać wyłącznie dla dodatniej dopłaty.', 'errors', 422);
        }
    }
}
