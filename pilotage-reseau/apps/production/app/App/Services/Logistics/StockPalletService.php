<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Repositories\Logistics\StockPalletRepository;
use App\Exceptions\CustomException;
use DateTimeImmutable;

/**
 * [296] Stany i palety (makieta 53) : la route du carton lue de gauche à droite — magasin (par groupe, parties à date
 * courte) → palettes en préparation → palettes prêtes → palettes parties (jour, heure de départ, camion, chez qui).
 * Une palette choisie : sa composition (produits, parties, dates) et ses couches. Une partie : chez quels clients elle est.
 * Partie = produit + jour d'étiquette du carton (carton_stock.date_add) : le numéro batch_number des cartons est une valeur
 * par défaut, pas une fabrication.
 */
final class StockPalletService
{
    /** Parties signalées : date limite à moins de N jours. */
    public const EXPIRY_DAYS = 30;
    /** Jours de palettes parties montrés (puis « Historia » : Wysyłki i dokumenty). */
    public const SHIPPED_DAYS = 31;
    public const SHIPPED_DATES = 6;
    /** Couches dessinées : les N produits les plus nombreux, le reste en une couche. */
    public const LAYERS = 5;

    public function __construct(private StockPalletRepository $repo)
    {
    }

    /**
     * @param string $q recherche : palette (« P-123 » ou numéro) ou nom de client
     * @param ?int $productId suivi de partie : produit ; $madeOn : jour d'étiquette (AAAA-MM-JJ)
     */
    public function overview(string $q = '', ?int $palletId = null, ?int $productId = null, ?string $madeOn = null, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        [$palletId, $client] = $this->readQuery($q, $palletId);

        $groups = array_map(static fn(array $g) => [
            'name' => (string) $g['grp'], 'in_store' => (int) $g['in_store'], 'on_pallet' => (int) $g['on_pallet'], 'products' => (int) $g['products'],
        ], $this->repo->stockByGroup());
        $inStore = array_sum(array_column($groups, 'in_store'));
        foreach ($groups as &$g) $g['share'] = $inStore > 0 ? round($g['in_store'] / $inStore * 100, 1) : 0.0;
        unset($g);
        $groups = array_values(array_filter($groups, static fn($g) => $g['in_store'] > 0 || $g['on_pallet'] > 0));

        $expiring = $this->repo->expiring($today->format('Y-m-d'), $today->modify('+' . self::EXPIRY_DAYS . ' days')->format('Y-m-d'));

        $open = $this->repo->openPallets($client);
        $building = array_values(array_filter($open, static fn($p) => (int) $p['status'] === 1));
        $ready = array_values(array_filter($open, static fn($p) => (int) $p['status'] === 2));
        $shipped = $this->shipped($today, $client);

        return [
            'today' => $today->format('Y-m-d'),
            'q' => $q,
            'client' => $client,
            'in_store' => $inStore,
            'products' => (int) array_sum(array_column($groups, 'products')),
            'groups' => $groups,
            'expiring' => $expiring,
            'expiry_days' => self::EXPIRY_DAYS,
            'building' => array_map([$this, 'card'], $building),
            'ready' => array_map([$this, 'card'], $ready),
            'building_cartons' => (int) array_sum(array_column($building, 'cartons')),
            'ready_cartons' => (int) array_sum(array_column($ready, 'cartons')),
            'shipped' => $shipped,
            'pallet' => $palletId !== null ? $this->pallet($palletId) : null,
            'trace' => $productId !== null ? $this->trace($productId, $madeOn) : null,
            'trace_products' => $this->repo->productsWithCartons(),
        ];
    }

    /** Une palette : en-tête, composition, couches, étapes (commencée, fermée, départ, livraison). */
    public function pallet(int $id): ?array
    {
        $p = $this->repo->pallet($id);
        if ($p === null) return null;
        $lines = array_map(static fn(array $r) => [
            'id_product' => (int) $r['id_product'], 'name' => (string) $r['name'], 'made_on' => self::date($r['made_on']),
            'expires' => self::date($r['expires']), 'cartons' => (int) $r['cartons'],
        ], $this->repo->composition($id));
        $dep = $this->repo->departures([[(string) $p['delivery_date'], (int) $p['id_customer']]])[0] ?? null;
        $card = $this->card($p);
        $card['lines'] = $lines;
        $card['layers'] = self::layers($lines);
        $card['vehicle'] = $dep['vehicle'] ?? null;
        $card['steps'] = [
            ['label' => 'zaczęta', 'at' => $p['first_at'] ?? null],
            ['label' => 'zamknięta', 'at' => (int) $p['status'] >= 2 ? ($p['last_at'] ?? null) : null],
            ['label' => 'wyjazd', 'at' => $dep['left_at'] ?? null],
            ['label' => 'dostarczona', 'at' => $dep['delivered_at'] ?? null],
        ];
        return $card;
    }

    /**
     * [334] Stan ogólny (kartony) : stan = magazyn + na paletach ; zamówione = zamówienia otwarte ; zostaje po wysyłce =
     * stan − zamówione ; wskaźnik : zostaje względem stanu maks. produktu (karta produktu, stany w kartonach = sztuki ÷ ppc).
     * @return array{categories: list<array>, totals: array}
     */
    public function generalStock(): array
    {
        $cats = [];
        $tot = ['stock' => 0, 'ordered' => 0, 'pallet' => 0, 'left' => 0];
        foreach ($this->repo->generalStock() as $r) {
            $ppc = (int) $r['ppc'];
            $stock = (int) $r['free'] + (int) $r['pallet'];
            $ordered = (int) round((float) $r['ordered']);
            $left = $stock - $ordered;
            $max = $r['stock_max'] !== null && $ppc > 0 ? (int) round((float) $r['stock_max'] / $ppc) : null;
            $min = $r['stock_min'] !== null && $ppc > 0 ? (int) round((float) $r['stock_min'] / $ppc) : null;
            $row = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'stock' => $stock, 'ordered' => $ordered, 'pallet' => (int) $r['pallet'],
                'left' => $left, 'max' => $max ?: null, 'min' => $min,
                'pct' => $max ? (int) round(max(0, $left) / $max * 100) : null,
                'state' => $left < 0 ? 'short' : ($max && $left > $max ? 'over' : ($min !== null && $left < $min ? 'low' : 'ok'))];
            $cats[$r['category']] ??= ['name' => (string) $r['category'], 'rows' => []];
            $cats[$r['category']]['rows'][] = $row;
            foreach (['stock', 'ordered', 'pallet', 'left'] as $k) $tot[$k] += $row[$k];
        }
        return ['categories' => array_values($cats), 'totals' => $tot];
    }

    /**
     * [333] Usunięcie palety (w budowie albo gotowej) : $mode « stock » — kartony wracają do magazynu ; « delete » — kartony
     * usunięte razem z paletą. Paleta wysłana : odmowa. Dziennik : n_pallet_deletion.
     * @return array{cartons:int, mode:string}
     */
    public function deletePallet(int $id, string $mode, ?string $reason, ?int $userId): array
    {
        if (!in_array($mode, ['stock', 'delete'], true)) throw new CustomException('Wybierz : kartony do magazynu albo usuń kartony.', 'errors', 422);
        $p = $this->repo->pallet($id) ?? throw new CustomException('Nie znaleziono palety.', 'errors', 404);
        if (!in_array((int) $p['status'], [1, 2], true)) throw new CustomException('Paleta wysłana — nie można jej usunąć.', 'errors', 409);
        $ids = $this->repo->palletCartonIds($id);
        $detail = array_map(static fn(array $r) => ['product' => (int) $r['id_product'], 'name' => (string) $r['name'], 'made_on' => (string) $r['made_on'], 'cartons' => (int) $r['cartons']],
            $this->repo->composition($id));
        try {
            $this->repo->deletePallet($id, $ids, $mode === 'delete', [
                'id_customer' => (int) $p['id_customer'] ?: null, 'customer' => $p['customer'] ?? null, 'delivery_date' => $p['delivery_date'] ?? null,
                'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
                'user' => $userId,
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'pallet-not-open') throw new CustomException('Paleta została już wysłana albo usunięta.', 'errors', 409);
            throw $e;
        }
        return ['cartons' => count($ids), 'mode' => $mode];
    }

    /**
     * Une partie (produit + jour d'étiquette) : palettes et clients, cartons encore au magasin. Sans jour : les derniers
     * jours de fabrication du produit (le plus récent est choisi).
     */
    public function trace(int $productId, ?string $madeOn = null): ?array
    {
        $name = $this->repo->productName($productId);
        if ($name === null) return null;
        $days = array_map(static fn(array $d) => [
            'made_on' => (string) $d['made_on'], 'cartons' => (int) $d['cartons'], 'in_store' => (int) $d['in_store'],
            'on_pallet' => (int) $d['on_pallet'], 'shipped' => (int) $d['shipped'], 'expires' => self::date($d['expires']),
        ], $this->repo->lotDays($productId));
        $byDay = array_column($days, null, 'made_on');
        if ($madeOn === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $madeOn)) $madeOn = $days[0]['made_on'] ?? null;
        $day = $madeOn !== null ? ($byDay[$madeOn] ?? null) : null;

        $pallets = [];
        if ($day !== null) {
            $pallets = array_map(fn(array $p) => $this->card($p) + ['lot_cartons' => (int) $p['cartons']], $this->repo->lotPallets($productId, $madeOn));
            $deps = [];
            foreach ($this->repo->departures(array_map(static fn($p) => [$p['delivery_date'], $p['id_customer']], $pallets)) as $d) {
                $deps[$d['ship_date'] . '|' . $d['id_customer']] = $d;
            }
            foreach ($pallets as &$p) {
                $d = $deps[$p['delivery_date'] . '|' . $p['id_customer']] ?? null;
                $p['left_at'] = $d['left_at'] ?? null;
                $p['delivered_at'] = $d['delivered_at'] ?? null;
            }
            unset($p);
        }
        $customers = [];
        foreach ($pallets as $p) if ($p['status'] === 3) $customers[$p['id_customer']] = $p['customer'];
        return [
            'id_product' => $productId,
            'name' => $name,
            'made_on' => $madeOn,
            'day' => $day,
            'days' => $days,
            'pallets' => $pallets,
            'customers' => array_values($customers),
        ];
    }

    /** Palettes parties sur SHIPPED_DAYS jours : par jour, clients, camion et heure de départ. */
    private function shipped(DateTimeImmutable $today, ?string $client): array
    {
        $rows = $this->repo->shippedSince($today->modify('-' . self::SHIPPED_DAYS . ' days')->format('Y-m-d'), $client);
        $deps = [];
        foreach ($this->repo->departures(array_map(static fn($r) => [$r['delivery_date'], $r['id_customer']], $rows)) as $d) {
            $deps[$d['ship_date'] . '|' . $d['id_customer']] = $d;
        }
        $days = [];
        foreach ($rows as $r) {
            $day = (string) $r['delivery_date'];
            $d = $deps[$day . '|' . $r['id_customer']] ?? null;
            $days[$day] ??= ['date' => $day, 'pallets' => 0, 'customers' => [], 'vehicles' => [], 'left_at' => null];
            $days[$day]['pallets'] += (int) $r['pallets'];
            $days[$day]['customers'][] = ['name' => (string) ($r['customer'] ?? '—'), 'pallets' => (int) $r['pallets'], 'delivered_at' => $d['delivered_at'] ?? null];
            if (!empty($d['vehicle'])) $days[$day]['vehicles'][$d['vehicle']] = true;
            if (!empty($d['left_at']) && ($days[$day]['left_at'] === null || $d['left_at'] < $days[$day]['left_at'])) $days[$day]['left_at'] = $d['left_at'];
        }
        foreach ($days as &$d) $d['vehicles'] = array_keys($d['vehicles']);
        unset($d);
        $all = array_values($days);
        return [
            'total' => (int) array_sum(array_column($all, 'pallets')),
            'customers' => count(array_unique(array_column($rows, 'id_customer'))),
            'days' => array_slice($all, 0, self::SHIPPED_DATES),
            'more' => max(0, count($all) - self::SHIPPED_DATES),
            'since' => $today->modify('-' . self::SHIPPED_DAYS . ' days')->format('Y-m-d'),
        ];
    }

    /** Carte d'une palette (colonnes, suivi de partie). */
    private function card(array $p): array
    {
        $status = (int) $p['status'];
        return [
            'id' => (int) $p['id_palet'],
            'code' => 'P-' . (int) $p['id_palet'],
            'status' => $status,
            'state' => [1 => 'w budowie', 2 => 'gotowa', 3 => 'wysłana'][$status] ?? 'nieznany',
            'delivery_date' => (string) $p['delivery_date'],
            'id_customer' => (int) $p['id_customer'],
            'customer' => (string) ($p['customer'] ?? '—'),
            'cartons' => (int) ($p['cartons'] ?? 0),
            'products' => (int) ($p['products'] ?? 0),
            'first_at' => $p['first_at'] ?? null,
            'last_at' => $p['last_at'] ?? null,
        ];
    }

    /** Couches : les LAYERS produits les plus nombreux, puis « N innych » ; hauteur proportionnelle aux cartons. */
    public static function layers(array $lines): array
    {
        $byProduct = [];
        foreach ($lines as $l) {
            $byProduct[$l['id_product']] ??= ['name' => $l['name'], 'cartons' => 0];
            $byProduct[$l['id_product']]['cartons'] += $l['cartons'];
        }
        usort($byProduct, static fn($a, $b) => $b['cartons'] <=> $a['cartons'] ?: strcmp($a['name'], $b['name']));
        $top = array_slice($byProduct, 0, self::LAYERS);
        $rest = array_slice($byProduct, self::LAYERS);
        if ($rest) $top[] = ['name' => count($rest) . ' innych', 'cartons' => (int) array_sum(array_column($rest, 'cartons')), 'other' => true];
        $total = max(1, (int) array_sum(array_column($top, 'cartons')));
        foreach ($top as &$t) $t['pct'] = round($t['cartons'] / $total * 100, 1);
        return $top;
    }

    /**
     * Recherche : « P-123 » ou un numéro → palette ; un texte → filtre client des colonnes de palettes.
     * @return array{0: ?int, 1: ?string}
     */
    private function readQuery(string $q, ?int $palletId): array
    {
        $q = trim($q);
        if (preg_match('/^(?:p-?\s*)?(\d+)$/i', $q, $m)) return [(int) $m[1], null];
        return [$palletId, $q !== '' ? $q : null];
    }

    private static function date(mixed $d): ?string
    {
        $d = (string) $d;
        return $d === '' || str_starts_with($d, '2000-01-01') ? null : substr($d, 0, 10);
    }
}
