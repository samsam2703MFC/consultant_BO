<?php
namespace App\Services\Communication;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Communication\MailRuleRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Document\InvoiceService;
use App\Services\Document\ProformaService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;

/**
 * E-mails automatiques : pour chaque règle active, chaque nouvel événement devient un message
 * en file (communication_message, avec sa pièce jointe PDF), envoyé ensuite par le worker
 * existant selon MailDeliveryPolicy. Un événement n'est traité qu'une fois par règle ; ce qui
 * n'a pas pu partir (pas d'adresse, pas de modèle, pas de compte) est noté avec sa raison.
 */
final class MailAutomationService
{
    public const EVENTS = ['order_received', 'delivery_planned', 'shipment_sent', 'wdt_issued', 'invoice_issued', 'proforma_issued'];
    public const RECIPIENTS = ['client_head', 'client_accountant', 'client_salesman', 'fixed'];
    public const LANGUAGES = ['client', 'fr', 'nl', 'pl'];

    public function __construct(
        private MailRuleRepository $rules,
        private MailEventSource $events,
        private CommunicationTemplateRepository $templates,
        private OutgoingMessageService $outgoing,
        private TemplateRenderer $renderer,
        private EmailAccountService $accounts,
        private MailDeliveryPolicy $policy,
        private ParamRepository $params,
        private TransactionManager $tx,
        private ProformaService $proformas,
        private InvoiceService $invoices,
        private WDTService $wdts,
        private WZService $wzs,
    ) {
    }

    /** @return array{queued:int,skipped:int,failed:int} */
    public function dispatch(): array
    {
        $result = ['queued' => 0, 'skipped' => 0, 'failed' => 0];
        $batch = max(1, (int) $this->params->get('mail.dispatch_batch', '50'));
        foreach ($this->rules->enabled() as $rule) {
            if ($rule['event'] !== 'delivery_planned' && $rule['start_ref_id'] === null) {
                // Règle activée sans point de départ : on part d'aujourd'hui, jamais de l'historique.
                $this->rules->update((int) $rule['id'], ['start_ref_id' => $this->events->lastRefId($rule['event'])]);
                continue;
            }
            foreach ($this->events->candidates($rule, $batch) as $candidate) {
                $status = $this->processCandidate($rule, $candidate);
                $result[$status]++;
            }
        }
        return $result;
    }

    /** Aperçu d'une règle sur le cas le plus récent, sans rien enregistrer. */
    public function preview(int $ruleId): array
    {
        $rule = $this->rules->find($ruleId) ?? throw new CustomException('Nie znaleziono reguły.', 'errors', 404);
        $candidate = $this->events->latest($rule['event']);
        if ($candidate === null) {
            return ['empty' => true];
        }
        $prepared = $this->prepare($rule, $candidate);
        return [
            'empty' => false,
            'ref_key' => $candidate['key'],
            'recipient' => $prepared['recipient'],
            'language' => $prepared['language'],
            'template' => $prepared['template']['name'] ?? null,
            'subject' => $prepared['subject'] ?? null,
            'body' => $prepared['body'] ?? null,
            'attachment' => $prepared['attachment'],
            'problem' => $prepared['problem'],
        ];
    }

    public function updateRule(int $ruleId, array $input, ?string $author): array
    {
        $rule = $this->rules->find($ruleId) ?? throw new CustomException('Nie znaleziono reguły.', 'errors', 404);
        $data = ['updated_by' => $author];
        if (array_key_exists('recipient_kind', $input)) {
            if (!in_array($input['recipient_kind'], self::RECIPIENTS, true)) throw new CustomException('Nieprawidłowy odbiorca.', 'errors', 422);
            $data['recipient_kind'] = $input['recipient_kind'];
        }
        if (array_key_exists('fixed_address', $input)) {
            $address = trim((string) $input['fixed_address']);
            if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) throw new CustomException('Nieprawidłowy adres e-mail.', 'errors', 422);
            $data['fixed_address'] = $address === '' ? null : $address;
        }
        if (array_key_exists('language', $input)) {
            if (!in_array($input['language'], self::LANGUAGES, true)) throw new CustomException('Nieprawidłowy język.', 'errors', 422);
            $data['language'] = $input['language'];
        }
        if (array_key_exists('attach_document', $input)) $data['attach_document'] = (int) (bool) $input['attach_document'];
        if (array_key_exists('days_before', $input)) {
            $days = filter_var($input['days_before'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 14]]);
            if ($days === false) throw new CustomException('Liczba dni: od 0 do 14.', 'errors', 422);
            $data['days_before'] = $days;
        }
        if (array_key_exists('enabled', $input)) {
            $enabled = (bool) $input['enabled'];
            $data['enabled'] = (int) $enabled;
            if ($enabled && !$rule['enabled']) {
                // Activer = à partir de maintenant : les documents déjà émis ne partent pas.
                $data['start_ref_id'] = $this->events->lastRefId($rule['event']);
                $data['enabled_at'] = date('Y-m-d H:i:s');
            }
        }
        $this->rules->update($ruleId, $data);
        return $this->rules->find($ruleId);
    }

    public function rules(): array
    {
        return $this->rules->list();
    }

    public function recentDispatches(): array
    {
        return $this->rules->recentDispatches();
    }

    public function retry(int $dispatchId): void
    {
        $this->rules->forgetDispatch($dispatchId);
    }

    private function processCandidate(array $rule, array $candidate): string
    {
        try {
            $prepared = $this->prepare($rule, $candidate);
            if ($prepared['problem'] !== null) {
                $this->rules->recordDispatch((int) $rule['id'], $candidate['key'], null, 'skipped', $prepared['problem']);
                return 'skipped';
            }
            $messageId = $this->tx->execute(function () use ($rule, $prepared) {
                return $this->queueMessage($rule, $prepared);
            });
            $this->rules->recordDispatch((int) $rule['id'], $candidate['key'], $messageId, 'queued', null);
            return 'queued';
        } catch (\Throwable $e) {
            $this->rules->recordDispatch((int) $rule['id'], $candidate['key'], null, 'failed', $e->getMessage());
            return 'failed';
        }
    }

    /** Destinataire, langue, modèle et texte d'un cas ; « problem » dit pourquoi rien ne partirait. */
    private function prepare(array $rule, array $candidate): array
    {
        $context = $this->events->context($rule['event'], $candidate['ref']);
        $recipient = $this->recipient($rule, $context['emails']);
        $language = $rule['language'] === 'client' ? $this->params->get('mail.client_default_language', 'fr') : $rule['language'];
        $template = $this->templates->getDefault($rule['template_type'], $language) ?: $this->templates->getDefault($rule['template_type'], 'fr');
        $prepared = [
            'context' => $context,
            'recipient' => $recipient,
            'language' => $language,
            'template' => $template ?: null,
            'attachment' => $rule['attach_document'] ? $context['attachment'] : null,
            'problem' => null,
        ];
        if ($recipient === '') {
            $prepared['problem'] = $rule['recipient_kind'] === 'fixed'
                ? 'Brak adresu (ustaw adres biura rachunkowego).'
                : 'Klient nie ma adresu e-mail tego rodzaju.';
            return $prepared;
        }
        if (!$template) {
            $prepared['problem'] = 'Brak aktywnego szablonu „' . $rule['template_type'] . '”.';
            return $prepared;
        }
        if ($this->accounts->active() === [] && $this->policy->requiresAccount()) {
            $prepared['problem'] = 'Brak aktywnego konta e-mail.';
            return $prepared;
        }
        $prepared['subject'] = $this->renderer->render($template['subject_template'], $context['variables']);
        $prepared['body'] = $this->renderer->render($template['body_template'], $context['variables']);
        return $prepared;
    }

    private function recipient(array $rule, array $emails): string
    {
        return match ($rule['recipient_kind']) {
            'fixed' => trim((string) ($rule['fixed_address'] ?: $this->params->get('mail.accounting_address', ''))),
            'client_accountant' => $emails['accountant'] ?: $emails['head'] ?: $emails['salesman'],
            'client_salesman' => $emails['salesman'] ?: $emails['head'],
            default => $emails['head'] ?: $emails['salesman'],
        };
    }

    private function queueMessage(array $rule, array $prepared): int
    {
        $files = [];
        if ($prepared['attachment'] !== null) {
            $pdf = match ($prepared['attachment']['source']) {
                'proforma' => $this->proformas->generateDocumentPDF($prepared['attachment']['id']),
                'invoice' => $this->invoices->generateDocumentPDF($prepared['attachment']['id']),
                'wdt' => $this->wdts->generateDocumentPDF($prepared['attachment']['id']),
                'wz' => $this->wzs->generateDocumentPDF($prepared['attachment']['id']),
            };
            $content = (string) ($pdf['file_string'] ?? '');
            if (!str_starts_with(ltrim($content), '%PDF')) {
                throw new \RuntimeException('Generator dokumentu nie zwrócił pliku PDF.');
            }
            $files[] = ['filename' => (string) ($pdf['filename'] ?? 'dokument.pdf'), 'mime_type' => 'application/pdf', 'content' => $content];
        }
        return $this->outgoing->queue([
            'message_type' => $rule['template_type'],
            'template' => $prepared['template'],
            'recipient' => $prepared['recipient'],
            'subject' => $prepared['subject'],
            'body' => $prepared['body'],
            'variables' => $prepared['context']['variables'],
            'id_customer' => $prepared['context']['id_customer'],
            'links' => $prepared['context']['links'],
            'created_by' => 'automat:' . $rule['code'],
        ], $files);
    }
}
