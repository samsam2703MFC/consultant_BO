<?php
declare(strict_types=1);

namespace App\Services\Shipping;

use App\Exceptions\CustomException;
use App\Repositories\Shipping\Difference\ShippingIssueRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Purchasing\NcService;
use App\Services\Logistics\DriverService;
use DateTimeImmutable;

/**
 * File des écarts d'expédition : chaque différence proforma payée ↔ WDT (montant à rembourser ou à faire payer)
 * et chaque problème signalé par le chauffeur devient un écart avec un responsable (Administracja pour les
 * différences, Logistyka pour les livraisons, par défaut), une échéance de décision (shipping_issue.decision_days),
 * une décision et un statut : à décider → en cours → clos.
 */
final class ShippingIssueService
{
    public const STATUSES = ['open' => 'Do decyzji', 'decided' => 'W realizacji', 'done' => 'Zamknięte'];
    /** Décisions : libellé, puis pour quels écarts (refund = on doit au client, collect = le client doit, delivery = livraison). */
    public const DECISIONS = [
        'refund' => ['Zwrot dla klienta (przelew)', ['refund', 'delivery']],
        'credit_next' => ['Rozliczenie przy następnym zamówieniu', ['refund', 'collect', 'delivery']],
        'collect' => ['Dopłata klienta (przypomnienie o płatności)', ['collect']],
        'resend' => ['Dosłanie brakujących produktów', ['refund']],
        'redeliver' => ['Ponowna dostawa', ['delivery']],
        'complaint' => ['Przekazanie do reklamacji', ['delivery']],
        'correction' => ['Korekta dokumentu (błąd)', ['refund', 'collect']],
        'waive' => ['Bez rozliczenia', ['refund', 'collect', 'delivery']],
    ];
    /** Décisions qui demandent une note (pourquoi). */
    private const NOTE_REQUIRED = ['waive', 'correction'];

    public function __construct(private ShippingIssueRepository $repo, private ParamRepository $params, private NcService $nc) {}

    /** Ajoute à la file les nouvelles différences et les nouveaux problèmes de livraison (idempotent). */
    public function sync(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $since = $now->modify('-' . $this->int('shipping_issue.lookback_days', 45) . ' days')->format('Y-m-d');
        // Échéance comptée depuis l'entrée dans la file (une ancienne différence reprise n'est pas déjà en retard).
        $due = $now->modify('+' . $this->int('shipping_issue.decision_days', 3) . ' days')->format('Y-m-d');
        $min = max(0.0, (float) str_replace(',', '.', $this->params->get('shipping_issue.min_amount', '1')));
        $out = ['differences' => 0, 'deliveries' => 0];
        $admin = null;
        foreach ($this->repo->newDifferences($since, $min) as $d) {
            $admin ??= $this->repo->ownerFor('ADMINISTRACJA') ?? 0;
            $this->insert('difference', ['id_difference' => (int) $d['id']], $d['id_customer'], (string) $d['established_date'], $due, $admin ?: null);
            $out['differences']++;
        }
        $logistics = null;
        foreach ($this->repo->newDeliveryProblems($since) as $s) {
            $logistics ??= $this->repo->ownerFor('LOGISTYKA') ?? 0;
            $this->insert('delivery', ['id_delivery_stop' => (int) $s['id']], $s['id_customer'], (string) $s['ship_date'], $due, $logistics ?: null);
            $out['deliveries']++;
        }
        // P7 : la domówienie (dosłanie, ponowna dostawa) a sa WDT → l'écart est réglé.
        $out['closed'] = 0;
        foreach ($this->repo->shippedSupplements() as $done) {
            $this->repo->update((int) $done['id'], ['status' => 'done', 'closed_at' => $now->format('Y-m-d H:i:s')]);
            $out['closed']++;
        }
        return $out;
    }

    /** Page de la file : écarts filtrés, compteurs, utilisateurs, décisions possibles. */
    public function overview(string $filter, bool $mine, ?int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $status = isset(self::STATUSES[$filter]) ? $filter : null;
        $items = array_map(fn($r) => $this->present($r, $today), $this->repo->list($status, $mine && $userId ? $userId : null));
        return [
            'filter' => $status ?? 'all',
            'mine' => $mine,
            'items' => $items,
            'counters' => $this->repo->counters($today),
            'users' => $this->repo->users(),
            'statuses' => self::STATUSES,
            'decisions' => array_map(fn($d) => $d[0], self::DECISIONS),
            'decision_days' => $this->int('shipping_issue.decision_days', 3),
        ];
    }

    /** Lignes qui diffèrent (document de différences) ou détail du problème de livraison. */
    public function lines(int $id): array
    {
        $issue = $this->find($id);
        return $issue['source'] === 'difference' ? $this->repo->differenceLines((int) $issue['id_difference']) : [];
    }

    public function assign(int $id, ?int $ownerId, ?int $userId): array
    {
        $issue = $this->find($id);
        if ($ownerId !== null && !$this->repo->userExists($ownerId)) throw new CustomException('Nie ma takiego użytkownika.', 'errors', 422);
        $this->repo->update($id, ['id_owner' => $ownerId]);
        return $this->find((int) $issue['id']);
    }

    /** Décision (modifiable tant que l'écart n'est pas clos) : l'écart passe « en cours ». */
    public function decide(int $id, string $decision, string $note, ?int $userId, ?DateTimeImmutable $now = null): array
    {
        $issue = $this->find($id);
        if ($issue['status'] === 'done') throw new CustomException('Sprawa jest zamknięta — otwórz ją ponownie, aby zmienić decyzję.', 'errors', 409);
        $kind = self::kind($issue);
        if (!isset(self::DECISIONS[$decision]) || !in_array($kind, self::DECISIONS[$decision][1], true)) {
            throw new CustomException('Ta decyzja nie pasuje do tej sprawy.', 'errors', 422);
        }
        $note = trim($note);
        if (in_array($decision, self::NOTE_REQUIRED, true) && $note === '') throw new CustomException('Dopisz uzasadnienie (dlaczego bez rozliczenia / co poprawić).', 'errors', 422);
        $this->repo->update($id, ['decision' => $decision, 'note' => mb_substr($note, 0, 2000) ?: null, 'status' => 'decided',
            'decided_by' => $userId, 'decided_at' => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return $this->find($id);
    }

    /** Clôture : décision prise et exécutée (remboursement fait, dopłata reçue, nouvelle livraison faite…). */
    public function close(int $id, ?int $userId, ?DateTimeImmutable $now = null): array
    {
        $issue = $this->find($id);
        if ($issue['decision'] === null) throw new CustomException('Najpierw wybierz decyzję.', 'errors', 422);
        $this->repo->update($id, ['status' => 'done', 'closed_by' => $userId, 'closed_at' => ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return $this->find($id);
    }

    /**
     * Domówienie pré-remplie (P7) pour « Dosłanie » (lignes manquantes du document de différences, déjà payées : GRATIS)
     * et « Ponowna dostawa » (produits du jour de livraison, GRATIS) : à relire dans le formulaire avant l'enregistrement.
     * @return array{issue:int, customer:int, order_type:string, is_freebie:bool, parent_order_id:?int, note:string, products:list<array>}
     */
    public function supplementPrefill(int $id): array
    {
        $issue = $this->find($id);
        if (!in_array($issue['decision'], ['resend', 'redeliver'], true)) throw new CustomException('Domówienie tylko dla decyzji « dosłanie » albo « ponowna dostawa ».', 'errors', 422);
        if ($issue['status'] === 'done') throw new CustomException('Sprawa jest zamknięta.', 'errors', 409);
        if ($issue['id_order']) throw new CustomException('Domówienie dla tej sprawy już istnieje (zamówienie #' . (int) $issue['id_order'] . ').', 'errors', 409);
        if ($issue['decision'] === 'resend') {
            $lines = $this->repo->missingLines((int) $issue['id_difference']);
            $parent = $this->repo->orderOfDifference((int) $issue['id_difference']);
            $note = 'Dosłanie brakujących produktów (opłacone w proformie) — sprawa różnic #' . $id;
        } else {
            $lines = $this->repo->deliveryLines((int) $issue['id_delivery_stop']);
            $parent = $lines ? (int) $lines[0]['id_order'] : null;
            $note = 'Ponowna dostawa po problemie przy dostawie — sprawa #' . $id;
        }
        if (!$lines) throw new CustomException('Brak produktów do dosłania w tej sprawie.', 'errors', 422);
        return ['issue' => $id, 'customer' => (int) $issue['id_customer'], 'order_type' => 'SUPPLEMENT', 'is_freebie' => true, 'parent_order_id' => $parent, 'note' => $note,
            'products' => array_map(fn($l) => ['id_product' => (int) $l['id_product'], 'product_name' => (string) $l['product_name'],
                'quantity_carton' => max(1, (int) round((float) $l['quantity'])), 'price_net' => round((float) $l['price'], 2)], $lines)];
    }

    /** La domówienie créée depuis le formulaire est reliée à l'écart (il se clôt à sa WDT). */
    public function linkOrder(int $id, int $orderId): void
    {
        $issue = $this->find($id);
        if (!$issue['id_order']) $this->repo->update($id, ['id_order' => $orderId]);
    }

    /** « Przekazanie do reklamacji » (P7) : non-conformité client pré-remplie (une par écart). */
    public function createNc(int $id, ?int $userId): array
    {
        $issue = $this->find($id);
        if ($issue['decision'] !== 'complaint') throw new CustomException('Niezgodność tylko dla decyzji « przekazanie do reklamacji ».', 'errors', 422);
        if ($issue['id_nc']) return ['id_nc' => (int) $issue['id_nc'], 'created' => false];
        $p = $this->present($this->repo->list(null, null, 1, $id)[0], date('Y-m-d'));
        $text = 'Problem przy dostawie (sprawa #' . $id . ') — klient : ' . ($p['customer'] ?? '—') . ', dzień wysyłki : ' . ($p['ship_date'] ?? '—')
            . ($p['reason'] ? ', przyczyna : ' . $p['reason'] : '') . ($p['stop_note'] ? ', kierowca : ' . $p['stop_note'] : '') . ($issue['note'] ? '. Decyzja : ' . $issue['note'] : '') . '.';
        $ncId = $this->nc->save(['source' => 'customer', 'description' => mb_substr($text, 0, 5000), 'severity' => 'major',
            'due_date' => date('Y-m-d', strtotime('+7 days'))], null, $userId);
        $this->repo->update($id, ['id_nc' => $ncId]);
        return ['id_nc' => $ncId, 'created' => true];
    }

        public function reopen(int $id): array
    {
        $issue = $this->find($id);
        $this->repo->update($id, ['status' => $issue['decision'] ? 'decided' : 'open', 'closed_by' => null, 'closed_at' => null]);
        return $this->find($id);
    }

    /** Sorte d'écart : refund (on doit au client), collect (le client doit), delivery (problème de livraison). */
    public static function kind(array $issue): string
    {
        if ($issue['source'] === 'delivery') return 'delivery';
        return (float) $issue['amount'] < 0 ? 'refund' : 'collect';
    }

    private function present(array $r, string $today): array
    {
        $kind = self::kind($r);
        $reason = $r['reason'] ? (DriverService::REASON_LABELS[$r['reason']][0] ?? $r['reason']) : null;
        return [
            'id' => (int) $r['id'],
            'source' => $r['source'],
            'kind' => $kind,
            'kind_label' => ['refund' => 'Zwrot dla klienta', 'collect' => 'Dopłata klienta', 'delivery' => 'Problem przy dostawie'][$kind],
            'customer' => $r['customer'] ?: '—',
            'id_customer' => $r['id_customer'] ? (int) $r['id_customer'] : null,
            'opened_on' => $r['opened_on'],
            'due_on' => $r['due_on'],
            'late' => $r['status'] === 'open' && $r['due_on'] < $today,
            'status' => $r['status'],
            'status_label' => self::STATUSES[$r['status']],
            'decision' => $r['decision'],
            'decision_label' => $r['decision'] ? self::DECISIONS[$r['decision']][0] : null,
            'decisions' => array_keys(array_filter(self::DECISIONS, fn($d) => in_array($kind, $d[1], true))),
            'note' => $r['note'],
            'owner_id' => $r['id_owner'] ? (int) $r['id_owner'] : null,
            'owner_name' => $r['owner_name'],
            'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
            'currency' => strtoupper((string) ($r['currency'] ?: 'EUR')),
            'lines_changed' => (int) ($r['lines_changed'] ?? 0),
            'id_difference' => $r['id_difference'] ? (int) $r['id_difference'] : null,
            'wdt_number' => $r['wdt_number'],
            'id_delivery_stop' => $r['id_delivery_stop'] ? (int) $r['id_delivery_stop'] : null,
            'reason' => $reason,
            'stop_note' => $r['stop_note'],
            'ship_date' => $r['ship_date'],
            'driver_name' => $r['driver_name'],
            'pallets' => $r['source'] === 'delivery' ? [(int) $r['pallets_delivered'], (int) $r['pallets_returned']] : null,
            'id_order' => isset($r['id_order']) && $r['id_order'] ? (int) $r['id_order'] : null,
            'id_nc' => isset($r['id_nc']) && $r['id_nc'] ? (int) $r['id_nc'] : null,
            'nc_number' => $r['nc_number'] ?? null,
            'last_message_at' => $r['last_message_at'],
            'last_message_type' => $r['last_message_type'],
        ];
    }

    private function find(int $id): array
    {
        $issue = $this->repo->one($id);
        if (!$issue) throw new CustomException('Nie znaleziono sprawy.', 'errors', 404);
        return $issue;
    }

    private function insert(string $source, array $ref, $customerId, string $openedOn, string $dueOn, ?int $ownerId): void
    {
        try {
            $this->insertRow($source, $ref, $customerId, $openedOn, $dueOn, $ownerId);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // déjà ajouté par une synchronisation parallèle
        }
    }

    private function insertRow(string $source, array $ref, $customerId, string $openedOn, string $dueOn, ?int $ownerId): void
    {
        $this->repo->insert($ref + [
            'source' => $source,
            'id_customer' => $customerId !== null ? (int) $customerId : null,
            'opened_on' => $openedOn,
            'due_on' => $dueOn,
            'id_owner' => $ownerId,
            'status' => 'open',
        ]);
    }

    private function int(string $code, int $default): int
    {
        $v = $this->params->get($code, (string) $default);
        return is_numeric($v) && (int) $v >= 1 ? (int) $v : $default;
    }
}
