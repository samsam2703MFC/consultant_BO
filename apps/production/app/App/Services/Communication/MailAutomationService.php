<?php
namespace App\Services\Communication;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Client\ClientRepository;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Communication\MailRuleRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Logistics\DeliveryNoticeService;
use App\Services\Utils\DocumentLabels;
use App\Services\Document\InvoiceService;
use App\Services\Document\ProformaService;
use App\Services\Document\WDTService;
use App\Services\Document\WZService;

/**
 * E-mails automatiques : pour chaque règle active, chaque nouvel événement devient un message
 * en file (communication_message, avec sa pièce jointe PDF), envoyé ensuite par le worker
 * existant selon MailDeliveryPolicy. Un événement n'est traité qu'une fois par règle ; ce qui
 * n'a pas pu partir (pas d'adresse, pas de modèle, pas de compte) est noté avec sa raison.
 * Règles « distribution » : destinataires, documents joints et copies pour la comptabilité selon
 * la Dystrybucja dokumentów (un message par adresse avec tous les documents du moment).
 */
final class MailAutomationService
{
    public const EVENTS = ['order_received', 'delivery_planned', 'shipment_sent', 'wdt_issued', 'invoice_issued', 'proforma_issued',
        'wdt_ksef_accepted', 'difference_issued', 'price_list_changed', 'month_start'];
    public const RECIPIENTS = ['client_head', 'client_accountant', 'client_salesman', 'fixed', 'distribution'];
    /** Cas sans rien à envoyer pour ce client (masqué dans l'historique). */
    public const NOT_APPLICABLE = 'Nie dotyczy klienta (dystrybucja dokumentów).';
    public const LANGUAGES = ['client', 'fr', 'nl', 'pl', 'en'];

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
        private ClientRepository $clients,
        private DocumentDistributionService $distribution,
        private DistributionDocuments $files,
        private DeliveryNoticeService $notices,
    ) {
    }

    /** @return array{queued:int,skipped:int,failed:int,waiting:int} */
    public function dispatch(): array
    {
        $result = ['queued' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0];
        $batch = max(1, (int) $this->params->get('mail.dispatch_batch', '50'));
        foreach ($this->rules->enabled() as $rule) {
            if (!in_array($rule['event'], MailEventSource::DATED, true) && $rule['start_ref_id'] === null) {
                // Règle activée sans point de départ : on part d'aujourd'hui, jamais de l'historique.
                $this->rules->update((int) $rule['id'], ['start_ref_id' => $this->events->lastRefId($rule['event'])]);
                continue;
            }
            foreach ($this->events->candidates($rule, $batch) as $candidate) {
                $status = $rule['recipient_kind'] === 'distribution'
                    ? $this->processDistribution($rule, $candidate)
                    : $this->processCandidate($rule, $candidate);
                $result[$status]++;
            }
        }
        return $result;
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

    public function recentDispatches(int $limit = 50): array
    {
        return $this->rules->recentDispatches($limit, self::NOT_APPLICABLE);
    }

    public function retry(int $dispatchId): void
    {
        $this->rules->forgetDispatch($dispatchId);
    }

    /**
     * Un cas d'une règle « distribution » : un message par adresse du client (tous ses documents du moment),
     * une copie par document pour la comptabilité, l'avis de livraison (e-mail et/ou SMS). Chaque envoi est noté
     * à part (« clé>… ») : un nouveau passage ou « Ponów » ne renvoie jamais ce qui est déjà parti.
     */
    private function processDistribution(array $rule, array $candidate): string
    {
        $key = $candidate['key'];
        try {
            $context = $this->events->context($rule['event'], $candidate['ref']);
            if (!empty($context['wait'])) return 'waiting';
            $customerId = (int) $context['id_customer'];
            $plan = $this->distribution->plan($rule['event'], $customerId, $context['emails'], array_keys($context['docs']));
            $problems = [];
            foreach ($plan['unreachable'] as $doc => $who) {
                $problems[] = DocumentDistributionService::DOCS[$doc]['label'] . ' : klient nie ma adresu (' . $who . ').';
            }
            $wanted = $plan['client'] || $plan['accounting'] || $plan['sms'];
            if (!$wanted) {
                $this->rules->recordDispatch((int) $rule['id'], $key, null, 'skipped', $problems ? implode(' ', $problems) : self::NOT_APPLICABLE);
                $this->events->acknowledge($rule['event'], $candidate['ref']);
                return 'skipped';
            }
            if ($this->accounts->active() === [] && $this->policy->requiresAccount()) {
                $this->rules->recordDispatch((int) $rule['id'], $key, null, 'skipped', 'Brak aktywnego konta e-mail.');
                return 'skipped';
            }

            $outcomes = [];
            if ($rule['event'] === 'delivery_planned') {
                $outcomes[] = $this->sendNotice($rule, $key, $customerId, $candidate['ref'], $plan);
            } else {
                $language = $this->clients->communicationLang($customerId);
                foreach ($plan['client'] as $group) {
                    $outcomes[] = $this->sendOnce($rule, $key . '>' . substr(md5(strtolower($group['address'])), 0, 10),
                        fn() => $this->queueForClient($rule, $context, $group['address'], $group['docs'], $language));
                }
                foreach ($plan['accounting'] as $copy) {
                    $address = $copy['address'] !== '' ? $copy['address'] : trim((string) $this->params->get('mail.accounting_address', ''));
                    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                        $problems[] = 'Kopia ' . DocumentDistributionService::DOCS[$copy['doc']]['label'] . ' : brak adresu biura rachunkowego.';
                        continue;
                    }
                    $outcomes[] = $this->sendOnce($rule, $key . '>acc:' . $copy['doc'],
                        fn() => $this->queueForAccounting($rule, $context, $address, $copy['doc']));
                }
            }

            $messageId = null;
            $failed = false;
            $queued = false;
            foreach ($outcomes as $o) {
                if ($o['status'] === 'failed') { $failed = true; $problems[] = $o['reason']; }
                if ($o['status'] === 'skipped' && $o['reason'] !== null) $problems[] = $o['reason'];
                if ($o['status'] === 'queued') { $queued = true; $messageId ??= $o['id']; }
            }
            $status = $failed ? 'failed' : ($queued ? 'queued' : 'skipped');
            $reason = $problems ? implode(' ', array_unique($problems)) : ($queued ? null : self::NOT_APPLICABLE);
            $this->rules->recordDispatch((int) $rule['id'], $key, $messageId, $status, $reason);
            if ($status !== 'failed') $this->events->acknowledge($rule['event'], $candidate['ref']);
            return $status;
        } catch (\Throwable $e) {
            $this->rules->recordDispatch((int) $rule['id'], $key, null, 'failed', $e->getMessage());
            return 'failed';
        }
    }

    /**
     * Un envoi noté sous sa propre clé : déjà en file = rien à refaire ; échec précédent = on retente.
     * @param callable(): ?array{id:?int, note:string}|array{skip:string} $queue null = rien à joindre pour ce cas
     * @return array{status:string, id:?int, reason:?string}
     */
    private function sendOnce(array $rule, string $subKey, callable $queue): array
    {
        $previous = $this->rules->findDispatch((int) $rule['id'], $subKey);
        if ($previous && $previous['status'] === 'queued') return ['status' => 'queued', 'id' => $previous['id_message'] ? (int) $previous['id_message'] : null, 'reason' => null];
        if ($previous) $this->rules->deleteDispatchKey((int) $rule['id'], $subKey);
        try {
            $sent = $queue();
            if ($sent === null) return ['status' => 'skipped', 'id' => null, 'reason' => null];
            if (isset($sent['skip'])) return ['status' => 'skipped', 'id' => null, 'reason' => $sent['skip']];
            $this->rules->recordDispatch((int) $rule['id'], $subKey, $sent['id'], 'queued', $sent['note']);
            return ['status' => 'queued', 'id' => $sent['id'], 'reason' => null];
        } catch (\Throwable $e) {
            $this->rules->recordDispatch((int) $rule['id'], $subKey, null, 'failed', $e->getMessage());
            return ['status' => 'failed', 'id' => null, 'reason' => $e->getMessage()];
        }
    }

    /** Message au client : modèle de la règle, langue du client, tous les documents disponibles en pièces jointes. */
    private function queueForClient(array $rule, array $context, string $address, array $docs, string $language): ?array
    {
        $files = [];
        $attached = [];
        foreach ($docs as $doc) {
            $file = $this->files->pdf($doc, $context['docs'][$doc]);
            if ($file === null) continue;
            $files[] = $file;
            $attached[] = $doc;
        }
        if (!$attached) return null;
        $template = $this->template($rule['template_type'], $language);
        $variables = $context['variables'];
        $variables['documents'] = ['list' => DocumentDistributionService::names($attached, $language)];
        if (isset($variables['report'])) {
            $variables['report']['period'] = DocumentLabels::month((string) $variables['report']['month'], $language);
            $variables['report']['documents'] = $variables['documents']['list'];
        }
        $id = $this->tx->execute(fn() => $this->outgoing->queue([
            'message_type' => $rule['template_type'],
            'template' => $template,
            'recipient' => $address,
            'subject' => $this->renderer->render($template['subject_template'], $variables),
            'body' => $this->renderer->render($template['body_template'], $variables),
            'variables' => $variables,
            'id_customer' => $context['id_customer'],
            'links' => $context['links'],
            'created_by' => 'automat:' . $rule['code'],
        ], $files));
        return ['id' => (int) $id, 'note' => DocumentDistributionService::names($attached, 'pl')];
    }

    /** Copie pour le bureau comptable : un message par document (modèle accounting_document, en polonais). */
    private function queueForAccounting(array $rule, array $context, string $address, string $doc): ?array
    {
        $file = $this->files->pdf($doc, $context['docs'][$doc]);
        if ($file === null) return null;
        $template = $this->template('accounting_document', 'pl');
        $variables = $this->events->documentVariables($doc, (int) $context['docs'][$doc]['id']);
        $id = $this->tx->execute(fn() => $this->outgoing->queue([
            'message_type' => 'accounting_document',
            'template' => $template,
            'recipient' => $address,
            'subject' => $this->renderer->render($template['subject_template'], $variables),
            'body' => $this->renderer->render($template['body_template'], $variables),
            'variables' => $variables,
            'id_customer' => $context['id_customer'],
            'links' => $context['links'],
            'created_by' => 'automat:' . $rule['code'] . ':biuro',
        ], [$file]));
        return ['id' => (int) $id, 'note' => 'Kopia : ' . DocumentDistributionService::DOCS[$doc]['label']];
    }

    /** Avis de livraison : même message que Logistyka › Powiadomienia klientów (camion, chauffeur, palettes), e-mail et/ou SMS. */
    private function sendNotice(array $rule, string $key, int $customerId, array $ref, array $plan): array
    {
        $address = '';
        foreach ($plan['client'] as $group) if (in_array('notice', $group['docs'], true)) $address = $group['address'];
        return $this->sendOnce($rule, $key . '>notice', function () use ($rule, $customerId, $ref, $plan, $address) {
            $result = $this->notices->send((string) $ref['day'], [['id' => $customerId, 'mail' => $address !== '', 'email' => $address, 'sms' => $plan['sms']]], 'automat:' . $rule['code']);
            $sent = $result['sent'];
            if (!$sent) return ['skip' => 'Powiadomienie o dostawie : ' . (implode(' ', $result['problems']) ?: 'nic do wysłania.')];
            $messageId = null;
            foreach ($sent as $s) $messageId ??= $s['id_message'] ?? null;
            $note = implode(', ', array_map(fn($s) => ($s['channel'] === 'sms' ? 'SMS ' : 'e-mail ') . $s['recipient'], $sent));
            return ['id' => $messageId !== null ? (int) $messageId : null, 'note' => trim($note . ' ' . implode(' ', $result['problems']))];
        });
    }

    private function template(string $type, string $language): array
    {
        $template = $this->templates->getDefault($type, $language) ?: $this->templates->getDefault($type, 'fr');
        if (!$template) throw new \RuntimeException('Brak aktywnego szablonu „' . $type . '”.');
        return $template;
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
        // język klienta z jego karty ; parametr = język zapasowy (wysyłka bez klienta)
        $language = $rule['language'] === 'client'
            ? (!empty($context['id_customer']) ? $this->clients->communicationLang((int) $context['id_customer']) : $this->params->get('mail.client_default_language', 'fr'))
            : $rule['language'];
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
