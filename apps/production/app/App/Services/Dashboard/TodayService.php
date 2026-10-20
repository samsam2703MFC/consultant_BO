<?php

namespace App\Services\Dashboard;

use App\Repositories\Dashboard\TodayRepository;

/**
 * Strona „Dzisiaj”: co wysyłamy, co trzeba wyprodukować i co czeka na decyzję.
 */
class TodayService
{
    public function __construct(private TodayRepository $repository) {}

    public function overview(): array
    {
        $days = $this->repository->getShippingDays();
        $pallets = $this->repository->getPalletStates(array_column($days, 'day'));
        foreach ($days as &$day) {
            $state = $pallets[$day['day']] ?? [];
            $day['scanning'] = (int) ($state['scanning'] ?? 0);
            $day['ready'] = (int) ($state['ready'] ?? 0);
            $day['shipped'] = (int) ($state['shipped'] ?? 0);
            $day['overdue'] = $day['day'] < date('Y-m-d');
        }
        unset($day);

        $next = null;
        foreach ($days as $day) {
            if (!$day['overdue']) { $next = $day; break; }
        }
        $missing = $next ? $this->repository->getMissingForDay($next['day']) : ['products' => 0, 'cartons' => 0];

        $complaints = $this->repository->getOpenComplaints();
        $proformas = $this->repository->getOpenOrderProformas();
        $readyPallets = $this->repository->countPalletsReadyForDocuments();
        $belowMinimum = $this->repository->countProductsBelowMinimum();
        $withoutRecipe = $this->repository->countActiveProductsWithoutRecipe();

        $oldestNew = $complaints['oldest_new'] ?? null;
        $complaintAgeDays = $oldestNew ? (int) floor((time() - strtotime($oldestNew)) / 86400) : null;

        return [
            'today' => date('Y-m-d'),
            'days' => $days,
            'next' => $next,
            'missing' => ['products' => (int) $missing['products'], 'cartons' => (int) $missing['cartons']],
            'complaints' => [
                'new' => (int) ($complaints['new_count'] ?? 0),
                'review' => (int) ($complaints['review_count'] ?? 0),
                'oldest_days' => $complaintAgeDays,
            ],
            'proformas' => [
                'orders' => (int) ($proformas['orders'] ?? 0),
                'missing' => (int) ($proformas['missing'] ?? 0),
                'unpaid' => (int) ($proformas['unpaid'] ?? 0),
                'not_sent' => (int) ($proformas['not_sent'] ?? 0),
            ],
            'todo' => $this->todo($days, $complaints, $proformas, $readyPallets, $belowMinimum, $withoutRecipe, $complaintAgeDays),
            'latest' => $this->repository->getLatestOrders(),
        ];
    }

    public function counters(): array
    {
        $complaints = $this->repository->getOpenComplaints();
        return [
            'complaints' => (int) ($complaints['new_count'] ?? 0) + (int) ($complaints['review_count'] ?? 0),
        ];
    }

    /**
     * „Do zrobienia” oznaczone według stanowisk : każda pozycja ma stanowiska odpowiedzialne (kody
     * tfb_position) ; pozycje stanowiska użytkownika są oznaczone jako jego i idą na początek listy.
     * @param array<string,string> $positionNames kod => nazwa
     */
    public function markTodo(array $todo, ?string $positionCode, array $positionNames): array
    {
        foreach ($todo as $i => &$item) {
            $item['mine'] = $positionCode !== null && in_array($positionCode, $item['positions'] ?? [], true);
            $item['owners'] = array_values(array_filter(array_map(fn($code) => $positionNames[$code] ?? null, $item['positions'] ?? [])));
            $item['order'] = $i;
        }
        unset($item);
        usort($todo, fn($a, $b) => [$a['mine'] ? 0 : 1, $a['order']] <=> [$b['mine'] ? 0 : 1, $b['order']]);
        return $todo;
    }

    /** Lista „Do zrobienia”: tylko to, co wymaga ruchu; każda pozycja prowadzi do właściwej strony. */
    private function todo(array $days, array $complaints, array $proformas, int $readyPallets, int $belowMinimum, int $withoutRecipe, ?int $complaintAgeDays): array
    {
        $items = [];
        $overdue = array_filter($days, fn($d) => $d['overdue']);
        if ($overdue) {
            $items[] = ['tone' => 'danger', 'icon' => 'bi-calendar-x', 'count' => array_sum(array_column($overdue, 'orders')),
                'one' => 'zamówienie po terminie wysyłki', 'few' => 'zamówienia po terminie wysyłki', 'many' => 'zamówień po terminie wysyłki',
                'url' => '/client-orders', 'positions' => ['LOGISTYKA', 'KIEROWNIK_PRODUKCJI']];
        }
        $new = (int) ($complaints['new_count'] ?? 0);
        if ($new) {
            $items[] = ['tone' => $complaintAgeDays !== null && $complaintAgeDays > 7 ? 'danger' : 'warning', 'icon' => 'bi-exclamation-triangle',
                'count' => $new, 'one' => 'nowa reklamacja czeka na decyzję', 'few' => 'nowe reklamacje czekają na decyzję', 'many' => 'nowych reklamacji czeka na decyzję',
                'note' => $complaintAgeDays !== null ? 'najstarsza od ' . $complaintAgeDays . ' ' . ($complaintAgeDays === 1 ? 'dnia' : 'dni') : null,
                'url' => '/franchise-complaints', 'positions' => ['JAKOSC']];
        }
        $review = (int) ($complaints['review_count'] ?? 0);
        if ($review) {
            $items[] = ['tone' => 'warning', 'icon' => 'bi-hourglass-split', 'count' => $review,
                'one' => 'reklamacja w weryfikacji', 'few' => 'reklamacje w weryfikacji', 'many' => 'reklamacji w weryfikacji', 'url' => '/franchise-complaints', 'positions' => ['JAKOSC']];
        }
        if ($readyPallets) {
            $items[] = ['tone' => 'warning', 'icon' => 'bi-truck', 'count' => $readyPallets,
                'one' => 'wysyłka gotowa do wystawienia dokumentów', 'few' => 'wysyłki gotowe do wystawienia dokumentów', 'many' => 'wysyłek gotowych do wystawienia dokumentów',
                'url' => '/shipping-documents/create', 'positions' => ['LOGISTYKA']];
        }
        if ((int) ($proformas['missing'] ?? 0)) {
            $items[] = ['tone' => 'warning', 'icon' => 'bi-file-earmark-plus', 'count' => (int) $proformas['missing'],
                'one' => 'otwarte zamówienie bez proformy', 'few' => 'otwarte zamówienia bez proformy', 'many' => 'otwartych zamówień bez proformy', 'url' => '/client-orders', 'positions' => ['ADMINISTRACJA']];
        }
        if ((int) ($proformas['not_sent'] ?? 0)) {
            $items[] = ['tone' => 'info', 'icon' => 'bi-envelope', 'count' => (int) $proformas['not_sent'],
                'one' => 'proforma niewysłana do klienta', 'few' => 'proformy niewysłane do klienta', 'many' => 'proform niewysłanych do klienta', 'url' => '/client-orders', 'positions' => ['ADMINISTRACJA']];
        }
        if ($withoutRecipe) {
            $items[] = ['tone' => 'info', 'icon' => 'bi-journal-x', 'count' => $withoutRecipe,
                'one' => 'aktywny produkt bez receptury', 'few' => 'aktywne produkty bez receptury', 'many' => 'aktywnych produktów bez receptury', 'url' => '/recipes/new', 'positions' => ['KIEROWNIK_PRODUKCJI']];
        }
        if ($belowMinimum) {
            $items[] = ['tone' => 'info', 'icon' => 'bi-box-seam', 'count' => $belowMinimum,
                'one' => 'produkt poniżej stanu minimalnego', 'few' => 'produkty poniżej stanu minimalnego', 'many' => 'produktów poniżej stanu minimalnego', 'url' => '/stock', 'positions' => ['KIEROWNIK_PRODUKCJI', 'LOGISTYKA']];
        }
        return $items;
    }
}
