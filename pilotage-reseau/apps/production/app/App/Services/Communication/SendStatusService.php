<?php
declare(strict_types=1);

namespace App\Services\Communication;

use App\Exceptions\CustomException;
use App\Repositories\Communication\MailRuleRepository;
use App\Repositories\Communication\SendStatusRepository;
use App\Repositories\Workshop\ParamRepository;
use DateTimeImmutable;

/**
 * Stan wysyłek : ce qui n'est pas parti, file par file. « Błąd » = toutes les tentatives faites (à reprendre ou à
 * classer), « Czeka » = en file au-delà de send_status.stuck_minutes après son heure (la tâche planifiée ne tourne
 * pas ?). « Ponów » remet une seule position en file pour une tentative de plus — jamais tout d'un coup, et rien
 * n'est envoyé depuis la page. « Rozwiązane » retire une erreur de la liste avec un motif obligatoire (journal).
 */
final class SendStatusService
{
    public const SOURCES = [
        'email' => ['label' => 'E-maile do klientów', 'icon' => 'bi-envelope', 'worker' => 'Bin/communication_email_worker.php'],
        'franchise' => ['label' => 'Sklepy franczyzy (proformy, reklamacje)', 'icon' => 'bi-shop', 'worker' => 'Bin/worker_outbox.php'],
        'shipment' => ['label' => 'Sklepy franczyzy (wysyłki)', 'icon' => 'bi-truck', 'worker' => 'Bin/shipments_retry.php'],
        'automation' => ['label' => 'E-maile automatyczne (reguły)', 'icon' => 'bi-lightning-charge', 'worker' => 'Bin/mail_dispatch.php'],
        'monitoring' => ['label' => 'Monitoring zadań (Discord)', 'icon' => 'bi-activity', 'worker' => 'Bin/cron_discord_notification_worker.php'],
        'shops' => ['label' => 'Zamówienia ze sklepów (pobieranie)', 'icon' => 'bi-cloud-download', 'worker' => 'Bin/monitored_poller.php'],
        'supplement' => ['label' => 'Domówienia → sklep', 'icon' => 'bi-cart-plus', 'worker' => 'Bin/mail_dispatch.php'],
    ];
    public const FILTERS = ['todo' => 'Do obsłużenia', 'error' => 'Błędy', 'stuck' => 'Czeka', 'resolved' => 'Rozwiązane'];
    private const KINDS = [
        'proforma.upload' => 'Proforma → sklep (plik PDF)', 'proforma.notify' => 'Proforma → sklep (powiadomienie)',
        'complaint.update' => 'Reklamacja → sklep (decyzja)', 'shipment.sent' => 'Wysyłka → sklep', 'cron.notify' => 'Powiadomienie o zadaniu',
        'config' => 'Sklep : konfiguracja', 'pull' => 'Sklep : pobieranie zamówień', 'import' => 'Sklep : zamówienie odrzucone',
        'unknown_product' => 'Sklep : nieznany produkt', 'supplement.push' => 'Domówienie → sklep',
    ];
    private const ERROR_HINTS = [
        'callback_failed' => ' — sklep nie przyjął powiadomienia (adres albo klucz sklepu)', 'UPDATE_FAILED' => ' — sklep odrzucił aktualizację',
    ];
    private const AWS_HINTS = [
        'AccessDenied' => ' — brak uprawnień do zapisu w S3 (klucze AWS / polityka IAM)', 'InvalidAccessKeyId' => ' — nieznany klucz AWS',
        'SignatureDoesNotMatch' => ' — zły sekret AWS', 'NoSuchBucket' => ' — brak takiego bucketu S3',
    ];
    /** Cas sautés qui ne demandent rien (ne concernent pas ce client). */
    private const IGNORED_SKIPS = [MailAutomationService::NOT_APPLICABLE];

    public function __construct(
        private SendStatusRepository $repo,
        private MailRuleRepository $rules,
        private ParamRepository $params,
    ) {}

    /**
     * @param string $filter todo | error | stuck | resolved | all
     * @param ?int $days fenêtre en jours (null = send_status.lookback_days, 0 = tout)
     */
    public function overview(string $filter = 'todo', ?string $source = null, ?int $days = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $filter = array_key_exists($filter, self::FILTERS) || $filter === 'all' ? $filter : 'todo';
        $source = isset(self::SOURCES[$source]) ? $source : null;
        $lookback = $this->int('send_status.lookback_days', 30, 1);
        $days = $days === null ? $lookback : max(0, $days);
        $all = $this->items($now, $days);

        $tiles = [];
        $last = $this->repo->lastSuccess();
        foreach (self::SOURCES as $key => $s) {
            $mine = array_filter($all, fn($i) => $i['source'] === $key);
            $tiles[$key] = $s + [
                'errors' => count(array_filter($mine, fn($i) => $i['state'] === 'error')),
                'stuck' => count(array_filter($mine, fn($i) => $i['state'] === 'stuck')),
                'resolved' => count(array_filter($mine, fn($i) => $i['state'] === 'resolved')),
                'last_success' => $last[$key] ?? null,
            ];
        }
        $counts = ['todo' => 0, 'error' => 0, 'stuck' => 0, 'resolved' => 0, 'all' => 0];
        foreach ($all as $i) {
            if ($source !== null && $i['source'] !== $source) continue;
            $counts[$i['state']]++;
            $counts['all']++;
            if ($i['state'] !== 'resolved') $counts['todo']++;
        }
        $items = array_values(array_filter($all, fn($i) => ($source === null || $i['source'] === $source) && match ($filter) {
            'todo' => $i['state'] !== 'resolved',
            'all' => true,
            default => $i['state'] === $filter,
        }));
        usort($items, fn($a, $b) => [$a['state'] === 'stuck' ? 1 : 0, $b['last_at']] <=> [$b['state'] === 'stuck' ? 1 : 0, $a['last_at']]);
        return [
            'filter' => $filter, 'source' => $source, 'days' => $days, 'lookback' => $lookback,
            'stuck_minutes' => $this->int('send_status.stuck_minutes', 30, 5),
            'filters' => self::FILTERS, 'sources' => self::SOURCES,
            'tiles' => $tiles, 'counts' => $counts, 'items' => $items,
        ];
    }

    /** Une tentative de plus pour une position en erreur (journalisée). */
    public function retry(string $source, int $id, ?int $userId): array
    {
        $this->assertSource($source);
        if ($source === 'shops') {
            throw new CustomException('Sklep jest odpytywany automatycznie co minutę — popraw przyczynę (karta klienta, produkt) albo oznacz jako rozwiązane.', 'errors', 409);
        }
        $this->repo->inTransaction(function () use ($source, $id, $userId) {
            $ok = $source === 'automation' ? $this->forgetDispatch($id) : $this->repo->requeue($source, $id);
            if (!$ok) {
                $status = $this->repo->status($source, $id);
                throw new CustomException($status === null ? 'Nie znaleziono pozycji.' : 'Pozycja nie jest w błędzie (status : ' . $status . ') — nic nie zmieniono.', 'errors', $status === null ? 404 : 409);
            }
            $this->repo->record($source, $id, 'retry', null, $userId);
        });
        return ['message' => in_array($source, ['automation', 'supplement'], true)
            ? ($source === 'automation' ? 'Reguła rozpatrzy ten przypadek ponownie przy następnym przebiegu (co 5 minut).' : 'Domówienie zostanie wysłane do sklepu przy następnym przebiegu (co 5 minut).')
            : 'Pozycja wróciła do kolejki : jedna próba więcej przy następnym przebiegu zadania planowanego.'];
    }

    /** Classe une erreur sans la renvoyer (motif obligatoire). */
    public function resolve(string $source, int $id, string $reason, ?int $userId): array
    {
        $this->assertSource($source);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) throw new CustomException('Podaj powód (co najmniej 3 znaki).', 'errors', 422);
        $status = $this->repo->status($source, $id);
        if ($status === null) throw new CustomException('Nie znaleziono pozycji.', 'errors', 404);
        if (!in_array($status, ['failed', 'dead', 'skipped'], true)) {
            throw new CustomException('Można oznaczyć tylko pozycję z błędem (status : ' . $status . ').', 'errors', 409);
        }
        $this->repo->record($source, $id, 'resolve', mb_substr($reason, 0, 500), $userId);
        return ['message' => 'Oznaczono jako rozwiązane.'];
    }

    /** « Do zrobienia » de la page Dzisiaj : erreurs à traiter et files bloquées. */
    public function todo(?DateTimeImmutable $now = null): array
    {
        $o = $this->overview('todo', null, null, $now);
        if (!$o['counts']['todo']) return [];
        return [[
            'tone' => $o['counts']['error'] ? 'danger' : 'warning', 'icon' => 'bi-send-exclamation', 'count' => $o['counts']['todo'],
            'one' => 'wysyłka do sprawdzenia', 'few' => 'wysyłki do sprawdzenia', 'many' => 'wysyłek do sprawdzenia',
            'note' => implode(' · ', array_filter([$o['counts']['error'] ? $o['counts']['error'] . ' z błędem' : null, $o['counts']['stuck'] ? $o['counts']['stuck'] . ' czeka w kolejce' : null])),
            'url' => '/communication/outbox', 'positions' => ['ADMINISTRACJA'],
        ]];
    }

    /** Toutes les positions à montrer sur la fenêtre, normalisées. */
    private function items(DateTimeImmutable $now, int $days): array
    {
        $since = $days > 0 ? $now->modify("-{$days} days")->format('Y-m-d H:i:s') : '2000-01-01 00:00:00';
        $stuckBefore = $now->modify('-' . $this->int('send_status.stuck_minutes', 30, 5) . ' minutes')->format('Y-m-d H:i:s');
        $out = [];

        $emails = $this->repo->emails($since, $stuckBefore);
        $numbers = [
            'proforma' => $this->repo->documentNumbers('document_proforma', array_column($emails, 'id_proforma')),
            'wdt' => $this->repo->documentNumbers('document_wdt', array_column($emails, 'id_wdt')),
            'invoice' => $this->repo->documentNumbers('document_invoice', array_column($emails, 'id_invoice')),
        ];
        foreach ($emails as $e) {
            $doc = null;
            foreach (['proforma' => 'Proforma', 'wdt' => 'WDT', 'invoice' => 'Faktura'] as $k => $label) {
                if (!empty($e['id_' . $k])) { $doc = $label . ' ' . ($numbers[$k][(int) $e['id_' . $k]] ?? '#' . $e['id_' . $k]); break; }
            }
            $out[] = $this->item('email', $e, [
                'kind_label' => \App\Services\Communication\CommunicationService::TEMPLATE_TYPES[$e['kind']] ?? self::humanize((string) $e['kind']),
                'what' => trim(($doc ? $doc . ' — ' : '') . (string) $e['subject']),
                'customer' => $e['customer'], 'customer_id' => $e['id_customer'] ? (int) $e['id_customer'] : null,
                'recipient' => $e['recipient'],
                'url' => '/communication/messages/' . (int) $e['id'],
                'error_state' => $e['status'] === 'failed',
            ], $now);
        }

        $franchise = $this->repo->franchise($since, $stuckBefore);
        $shops = $this->repo->customersByShop(array_column(array_column($franchise, 'payload'), 'franchisee_uid'));
        $proformas = $this->repo->proformasByUuid(array_column(array_column($franchise, 'payload'), 'proforma_uuid'));
        foreach ($franchise as $f) {
            $p = $f['payload'];
            $shop = $shops[$p['franchisee_uid'] ?? ''] ?? null;
            $what = match (true) {
                isset($p['proforma_uuid']) => 'Proforma ' . ($proformas[$p['proforma_uuid']]['doc_num'] ?? substr((string) $p['proforma_uuid'], 0, 8) . '…') . (isset($p['order_key']) ? ' · zamówienie ' . $p['order_key'] : ''),
                isset($p['complaint_key']) => 'Reklamacja ' . $p['complaint_key'] . (isset($p['acceptance_status']) ? ' · ' . $p['acceptance_status'] : ''),
                default => '',
            };
            $out[] = $this->item('franchise', $f, [
                'kind_label' => self::KINDS[$f['kind']] ?? self::humanize((string) $f['kind']),
                'what' => $what, 'customer' => $shop['company'] ?? ($p['franchisee_code'] ?? null), 'customer_id' => $shop ? (int) $shop['id'] : null,
                'url' => isset($p['complaint_key']) ? '/franchise-complaints' : '/proforma-documents',
                'error_state' => in_array($f['status'], ['dead', 'failed'], true),
            ], $now);
        }

        foreach ($this->repo->shipments($since, $stuckBefore) as $s) {
            $out[] = $this->item('shipment', $s, [
                'kind_label' => self::KINDS['shipment.sent'], 'what' => 'Zamówienie #' . (int) $s['id_order'],
                'customer' => $s['customer'], 'customer_id' => $s['id_customer'] ? (int) $s['id_customer'] : null,
                'url' => '/client-orders/' . (int) $s['id_order'] . '/edit', 'error_state' => $s['status'] === 'failed',
            ], $now);
        }

        foreach ($this->repo->automation($since, self::IGNORED_SKIPS) as $a) {
            $out[] = $this->item('automation', $a, [
                'kind_label' => \App\Services\Communication\CommunicationService::TEMPLATE_TYPES[$a['kind']] ?? self::humanize((string) $a['kind']),
                'what' => 'Reguła ' . $a['rule_code'] . ' · ' . $a['ref_key'] . ($a['status'] === 'skipped' ? ' (pominięty)' : ''),
                'url' => '/communication/distribution', 'error_state' => true,
            ], $now);
        }

        foreach ($this->repo->monitoring($since, $stuckBefore) as $m) {
            $out[] = $this->item('monitoring', $m, [
                'kind_label' => self::KINDS['cron.notify'], 'what' => trim(($m['job_name'] ?? 'zadanie') . ($m['run_status'] ? ' · ' . $m['run_status'] : '')),
                'url' => null, 'error_state' => $m['status'] === 'dead',
            ], $now);
        }

        foreach ($this->repo->shops($since) as $c) {
            $out[] = $this->item('shops', $c, [
                'kind_label' => self::KINDS[$c['kind']] ?? self::humanize((string) $c['kind']),
                'what' => trim(($c['order_key'] ? 'Zamówienie ' . $c['order_key'] : '') . ((int) $c['attempts'] > 1 ? ' · ' . (int) $c['attempts'] . '× od ' . date('d.m H:i', strtotime((string) $c['created_at'])) : '')),
                'customer' => $c['customer'], 'customer_id' => $c['id_customer'] ? (int) $c['id_customer'] : null,
                'url' => $c['id_order'] ? '/client-orders/' . (int) $c['id_order'] . '/edit' : ($c['id_customer'] ? '/clients/' . (int) $c['id_customer'] . '/edit' : null),
                'error_state' => true,
            ], $now);
        }

        foreach ($this->repo->supplements($since, $stuckBefore) as $d) {
            $log = json_decode((string) $d['error'], true);
            $d['error'] = is_array($log) ? (string) ($log['reason'] ?? '') : '';
            $out[] = $this->item('supplement', $d, [
                'kind_label' => self::KINDS['supplement.push'], 'what' => 'Domówienie #' . (int) $d['id'] . ($d['order_key'] ? ' · ' . $d['order_key'] : ''),
                'customer' => $d['customer'], 'customer_id' => $d['id_customer'] ? (int) $d['id_customer'] : null,
                'url' => '/client-orders/' . (int) $d['id'] . '/edit', 'error_state' => $d['status'] === 'failed',
            ], $now);
        }

        // État « rozwiązane » : la dernière action sur une erreur est « resolve ».
        foreach (array_keys(self::SOURCES) as $src) {
            $ids = array_column(array_filter($out, fn($i) => $i['source'] === $src), 'id');
            $actions = $this->repo->lastActions($src, $ids);
            foreach ($out as &$i) {
                if ($i['source'] !== $src || !isset($actions[$i['id']])) continue;
                $a = $actions[$i['id']];
                $i['last_action'] = ['action' => $a['action'], 'reason' => $a['reason'], 'user' => $a['user_name'], 'at' => $a['created_at']];
                // Boutiques : un cas classé qui se reproduit après le classement revient dans la liste.
                $again = $src === 'shops' && strtotime((string) $i['last_at']) > strtotime((string) $a['created_at']);
                if ($i['state'] === 'error' && $a['action'] === 'resolve' && !$again) {
                    $i['state'] = 'resolved';
                    $i['can_resolve'] = false;
                }
            }
            unset($i);
        }
        return $out;
    }

    private function item(string $source, array $row, array $extra, DateTimeImmutable $now): array
    {
        $error = (bool) $extra['error_state'];
        unset($extra['error_state']);
        $next = $row['next_try_at'] ?? null;
        return $extra + [
            'source' => $source, 'source_label' => self::SOURCES[$source]['label'], 'icon' => self::SOURCES[$source]['icon'],
            'id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'status' => (string) $row['status'],
            'state' => $error ? 'error' : 'stuck',
            'attempts' => $row['attempts'] === null ? null : (int) $row['attempts'],
            'error' => self::sanitize((string) ($row['error'] ?? '')),
            'created_at' => $row['created_at'], 'last_at' => (string) $row['last_at'],
            'waiting_minutes' => !$error && $next ? max(0, (int) floor(($now->getTimestamp() - strtotime((string) $next)) / 60)) : null,
            'customer' => $extra['customer'] ?? null, 'customer_id' => $extra['customer_id'] ?? null, 'recipient' => $extra['recipient'] ?? null,
            'can_retry' => $error && $source !== 'shops', 'can_resolve' => $error, 'last_action' => null,
        ];
    }

    /** Message d'erreur montrable : sans jetons, signatures ni paramètres de requête, 300 caractères au plus. */
    public static function sanitize(string $error): string
    {
        if (isset(self::ERROR_HINTS[trim($error)])) return trim($error) . self::ERROR_HINTS[trim($error)];
        // Erreur AWS (S3) : opération, code HTTP et code AWS seulement — le texte complet contient le compte et l'utilisateur IAM.
        if (preg_match('/^Error executing "(\w+)".*?`(\d{3})[^`]*` response.*?<Code>(\w+)<\/Code>/s', $error, $m)) {
            return 'AWS ' . $m[1] . ' : HTTP ' . $m[2] . ' ' . $m[3] . (self::AWS_HINTS[$m[3]] ?? '');
        }
        $error = preg_replace('/arn:aws:[^\s"\'<>]+/i', 'arn:aws:***', $error) ?? '';
        $error = preg_replace('/\b(AKIA|ASIA)[A-Z0-9]{12,}\b/', '$1***', $error) ?? '';
        $error = preg_replace('/([?&](?:[a-z_-]*(?:token|key|secret|signature|sig|auth|password|pass)[a-z_-]*)=)[^&\s"\']+/i', '$1***', $error) ?? '';
        $error = preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $error) ?? '';
        $error = preg_replace('/\b(X-Amz-[A-Za-z-]+=)[^&\s"\']+/i', '$1***', $error) ?? '';
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
        return mb_strlen($error) > 300 ? mb_substr($error, 0, 300) . '…' : $error;
    }

    private function forgetDispatch(int $id): bool
    {
        $status = $this->repo->status('automation', $id);
        if (!in_array($status, ['failed', 'skipped'], true)) return false;
        $this->rules->forgetDispatch($id);
        return $this->repo->status('automation', $id) === null;
    }

    private function assertSource(string $source): void
    {
        if (!isset(self::SOURCES[$source])) throw new CustomException('Nieznana kolejka.', 'errors', 422);
    }

    private function int(string $code, int $default, int $min): int
    {
        return max($min, (int) $this->params->get($code, (string) $default));
    }

    private static function humanize(string $kind): string
    {
        return $kind === '' ? '—' : ucfirst(str_replace(['_', '.'], [' ', ' · '], $kind));
    }
}
