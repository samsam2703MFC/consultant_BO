<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Repositories\Workshop\ComplaintBoardRepository;
use App\Services\Client\Complaint\ComplaintCollectorService;
use App\Services\Translation\DeepLTranslator;

/**
 * Tablet grupy, « Reklamacje » (informacyjnie) : reklamacje klientów przyjęte przez biuro na produkty grupy — produkt, sklep,
 * powód, ilość, komentarz klienta i odpowiedź biura (przetłumaczone na język tabletu, gdy DeepL jest włączony ; tłumaczenie
 * raz na reklamację i język), zdjęcia na żądanie (link czasowy z systemu franczyzy). Nic się tu nie zmienia.
 */
final class KioskComplaintService
{
    public const DAYS = [30, 90, 365];
    private const WEEK_DAYS = 7;
    private const MAX_TRANSLATE = 40;
    private const MAX_PHOTOS = 6;

    public function __construct(
        private ComplaintBoardRepository $repo,
        private DeepLTranslator $translator,
        private ComplaintCollectorService $collector,
    ) {
    }

    /** Ekran startowy tabletu : liczba w 30 dniach, z tego w 7 dniach, sztuki, trzy ostatnie (bez tłumaczenia) ; year : w 12 miesiącach (zakładka). */
    public function summary(ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $year = $this->repo->accepted($groupId, date('Y-m-d', strtotime('-364 days')));
        $from = date('Y-m-d', strtotime('-29 days'));
        $rows = array_values(array_filter($year, fn($r) => substr((string) $r['received_at'], 0, 10) >= $from));
        $weekFrom = date('Y-m-d', strtotime('-' . (self::WEEK_DAYS - 1) . ' days'));
        return [
            'days' => 30,
            'count' => count($rows),
            'year' => count($year),
            'week' => count(array_filter($rows, fn($r) => substr((string) $r['received_at'], 0, 10) >= $weekFrom)),
            'pieces' => round(array_sum(array_map(fn($r) => $this->quantity($r), $rows)), 1),
            'latest' => array_map(fn($r) => ['id' => (int) $r['id'], 'product' => $r['product'], 'reason' => $r['reason'], 'date' => substr((string) $r['received_at'], 0, 10),
                'quantity' => $this->quantity($r)], array_slice($rows, 0, 3)),
        ];
    }

    /** Lista z okresu (30 / 90 / 365 dni), najczęstsze produkty i powody. */
    public function list(?int $days, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $days = in_array($days, self::DAYS, true) ? $days : 30;
        $rows = $this->repo->accepted($groupId, date('Y-m-d', strtotime('-' . ($days - 1) . ' days')));
        $lang = $context->translator->lang() === 'fr' ? 'fr' : 'pl';
        $translations = $this->translations($rows, $lang);
        $weekFrom = date('Y-m-d', strtotime('-' . (self::WEEK_DAYS - 1) . ' days'));
        $items = [];
        $products = [];
        $reasons = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $qty = $this->quantity($r);
            $tr = $translations[$id] ?? null;
            $items[] = [
                'id' => $id, 'date' => substr((string) $r['received_at'], 0, 10), 'reported_at' => $r['reported_at'] ? substr((string) $r['reported_at'], 0, 10) : null,
                'new' => substr((string) $r['received_at'], 0, 10) >= $weekFrom,
                'product' => ['id' => (int) $r['product_id'], 'name' => $r['product']], 'customer' => $r['customer'], 'reason' => $r['reason'],
                'quantity' => $qty, 'compensation' => $r['compensation_method'],
                'comment' => $this->text($r['client_comment']), 'comment_tr' => $tr['comment'] ?? null,
                'response' => $this->text($r['production_response']), 'response_tr' => $tr['response'] ?? null,
                'source_lang' => $tr['source'] ?? null, 'photos' => (int) $r['photos'],
            ];
            $pid = (int) $r['product_id'];
            $products[$pid] ??= ['id' => $pid, 'name' => $r['product'], 'count' => 0, 'pieces' => 0.0];
            $products[$pid]['count']++;
            $products[$pid]['pieces'] = round($products[$pid]['pieces'] + $qty, 1);
            $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + 1;
        }
        usort($products, fn($a, $b) => [$b['count'], $b['pieces']] <=> [$a['count'], $a['pieces']]);
        arsort($reasons);
        return [
            'group' => ['id' => $groupId, 'name' => $this->repo->groupName($groupId, $lang)],
            'days' => $days, 'periods' => self::DAYS, 'lang' => $lang, 'translator' => $this->translator->configured(),
            'totals' => ['count' => count($items), 'week' => count(array_filter($items, fn($i) => $i['new'])), 'pieces' => round(array_sum(array_column($items, 'quantity')), 1)],
            'products' => array_slice(array_values($products), 0, 5),
            'reasons' => array_map(fn($code, $n) => ['code' => $code, 'count' => $n], array_keys($reasons), array_values($reasons)),
            'items' => $items,
        ];
    }

    /** Zdjęcia reklamacji : linki czasowe z systemu franczyzy (url null, gdy niedostępne). */
    public function photos(int $complaintId, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        if ($this->repo->acceptedOne($groupId, $complaintId) === null) throw ApiException::notFound('complaint', $complaintId);
        $out = [];
        foreach (array_slice($this->repo->attachments($complaintId), 0, self::MAX_PHOTOS) as $a) {
            $url = null;
            try {
                $res = $this->collector->getAttachmentPresigned((int) $a['id']);
                $url = is_array($res) ? ($res['data']['url'] ?? $res['url'] ?? null) : null;
            } catch (\Throwable $e) {
                error_log('[kiosk-complaint-photo] ' . $e->getMessage());
            }
            $out[] = ['id' => (int) $a['id'], 'url' => is_string($url) && preg_match('#^https://#i', $url) ? $url : null];
        }
        return ['id' => $complaintId, 'photos' => $out];
    }

    /**
     * Tłumaczenia komentarza i odpowiedzi na język tabletu : z pamięci, brakujące (albo po zmianie tekstu) przez DeepL —
     * najwyżej MAX_TRANSLATE reklamacji naraz ; bez klucza albo przy błędzie : oryginał. Ten sam język : brak tłumaczenia.
     * @return array<int, array{comment:?string, response:?string, source:?string}>
     */
    private function translations(array $rows, string $lang): array
    {
        $rows = array_values(array_filter($rows, fn($r) => $this->text($r['client_comment']) !== null || $this->text($r['production_response']) !== null));
        if (!$rows) return [];
        $hash = fn($r) => sha1(($this->text($r['client_comment']) ?? '') . "\x1F" . ($this->text($r['production_response']) ?? ''));
        $saved = $this->repo->translations(array_column($rows, 'id'), $lang);
        $out = [];
        $todo = [];
        foreach ($rows as $r) {
            $s = $saved[(int) $r['id']] ?? null;
            if ($s !== null && $s['source_hash'] === $hash($r)) {
                $out[(int) $r['id']] = ['comment' => $s['comment_text'], 'response' => $s['response_text'], 'source' => $s['source_lang']];
            } elseif (count($todo) < self::MAX_TRANSLATE) {
                $todo[] = $r;
            }
        }
        if (!$todo || !$this->translator->configured()) return $out;
        $texts = [];
        foreach ($todo as $r) { $texts[] = $this->text($r['client_comment']) ?? ''; $texts[] = $this->text($r['production_response']) ?? ''; }
        try {
            $res = $this->translator->translate($texts, $lang, 'complaint', null);
        } catch (\Throwable $e) {
            error_log('[kiosk-complaint-translate] ' . $e->getMessage());
            return $out;
        }
        foreach ($todo as $k => $r) {
            $c = $res[2 * $k];
            $p = $res[2 * $k + 1];
            $source = $c['source'] ?? $p['source'] ?? null;
            // Tekst już w języku tabletu : bez tłumaczenia.
            $comment = $c['source'] !== null && $c['source'] !== $lang && $c['text'] !== '' ? $c['text'] : null;
            $response = $p['source'] !== null && $p['source'] !== $lang && $p['text'] !== '' ? $p['text'] : null;
            $this->repo->saveTranslation((int) $r['id'], $lang, $hash($r), $source, $comment, $response);
            $out[(int) $r['id']] = ['comment' => $comment, 'response' => $response, 'source' => $source];
        }
        return $out;
    }

    private function quantity(array $r): float
    {
        $accepted = (float) ($r['accepted_quantity'] ?? 0);
        return round($accepted > 0 ? $accepted : (float) ($r['product_quantity'] ?? 0), 1);
    }

    private function text(?string $s): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : mb_substr($s, 0, 2000);
    }

    private function groupId(ApiContext $context): int
    {
        return $context->deviceGroupId ?? throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
    }
}
