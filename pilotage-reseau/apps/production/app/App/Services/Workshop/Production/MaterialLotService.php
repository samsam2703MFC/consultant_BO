<?php
declare(strict_types=1);

namespace App\Services\Workshop\Production;

use App\Exceptions\CustomException;
use App\Repositories\Workshop\MaterialLotRepository;

/**
 * [352] Partie surowców (makieta 68, zakładka « Partie surowców ») :
 *  - synchronizacja : każda pozycja faktury towarowej przypisana do surowca = partia (bez numeru → nieaktywna) ; przyjęcie
 *    dostawy z numerem partii uzupełnia partię z faktury tego samego dostawcy i surowca (±14 dni), inaczej nowa partia ;
 *    partia po DLC wyłącza się sama ;
 *  - numer / DLC wpisane raz w rejestrze, przełącznik partii (na liście tabletu albo nie) i surowca (śledzony albo nie) ;
 *  - lista tabletu : partie aktywne, ważne, najkrótsza DLC najpierw.
 */
final class MaterialLotService
{
    public const DAYS = 180;
    private const MATCH_DAYS = 14;

    public function __construct(private MaterialLotRepository $repo)
    {
    }

    /** @return array{invoices:int, receipts:int, expired:int} */
    public function sync(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $invoices = $this->repo->addInvoiceLines(self::DAYS);
        $receipts = 0;
        foreach ($this->repo->newReceipts(self::DAYS) as $r) {
            $lot = self::normalize((string) $r['lot_number']);
            if ($lot === null) continue;
            $bb = $r['best_before'] ?: null;
            $on = $bb === null || $bb >= $today;
            $data = ['lot_number' => $lot, 'best_before' => $bb, 'id_po_receipt' => (int) $r['id'],
                'active' => $on ? 1 : 0, 'off_reason' => $on ? null : 'expired'];
            $match = $this->repo->invoiceLotFor((int) $r['id_raw_material'], $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null,
                (string) $r['received_on'], self::MATCH_DAYS);
            if ($match !== null) $this->repo->update($match, $data);
            else $this->repo->insert($data + ['id_raw_material' => (int) $r['id_raw_material'], 'source' => 'receipt',
                'id_supplier' => $r['id_supplier'] !== null ? (int) $r['id_supplier'] : null, 'received_on' => (string) $r['received_on']]);
            $receipts++;
        }
        return ['invoices' => $invoices, 'receipts' => $receipts, 'expired' => $this->repo->expire($today)];
    }

    /** Rejestr pogrupowany po surowcu (makieta 68 · 1). */
    public function register(?string $q = null, bool $activeOnly = false): array
    {
        $this->sync();
        $materials = [];
        $noNumber = 0;
        foreach ($this->repo->register(self::DAYS, $q !== null ? mb_substr(trim($q), 0, 80) : null, $activeOnly) as $r) {
            $m = (int) $r['id_raw_material'];
            $materials[$m] ??= ['id' => $m, 'name' => (string) $r['material'], 'unit' => $r['unit'], 'tracked' => (bool) (int) $r['tracked'],
                'quantity' => 0.0, 'used' => 0, 'active' => 0, 'lots' => []];
            $hasNumber = $r['lot_number'] !== null && $r['lot_number'] !== '';
            if (!$hasNumber) $noNumber++;
            $materials[$m]['quantity'] += (float) $r['quantity'];
            $materials[$m]['used'] += (int) $r['used'];
            if ((int) $r['active']) $materials[$m]['active']++;
            $materials[$m]['lots'][] = [
                'id' => (int) $r['id'], 'lot_number' => $hasNumber ? $r['lot_number'] : null, 'best_before' => $r['best_before'],
                'source' => $r['source'], 'invoice' => $r['invoice_number'], 'ksef' => $r['invoice_source'] === 'ksef',
                'receipt_on' => $r['receipt_on'], 'supplier' => $r['supplier'], 'received_on' => $r['received_on'],
                'quantity' => $r['quantity'] !== null ? round((float) $r['quantity'], 3) : null, 'used' => (int) $r['used'],
                'active' => (bool) (int) $r['active'], 'off_reason' => $r['off_reason'],
                'expired' => $r['best_before'] !== null && $r['best_before'] < date('Y-m-d'),
            ];
        }
        return ['materials' => array_values($materials), 'no_number' => $noNumber, 'days' => self::DAYS];
    }

    /** Numer partii i DLC (wpisane raz) ; z numerem i ważna → aktywna. */
    public function setNumber(int $id, ?string $lot, ?string $bestBefore, ?int $userId): array
    {
        $row = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono partii.', 'errors', 404);
        $lot = self::normalize((string) $lot);
        if ($lot === null) throw new CustomException('Wpisz numer partii.', 'errors', 422);
        if ($bestBefore !== null && $bestBefore !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bestBefore)) throw new CustomException('Data ważności : RRRR-MM-DD.', 'errors', 422);
        $bb = $bestBefore ?: null;
        $valid = $bb === null || $bb >= date('Y-m-d');
        $this->repo->update($id, ['lot_number' => $lot, 'best_before' => $bb, 'updated_by' => $userId,
            'active' => $valid ? 1 : 0, 'off_reason' => $valid ? null : 'expired']);
        return $this->repo->find($id) ?? $row;
    }

    /** Partia na liście tabletu albo nie (bez numeru albo po DLC : nie da się włączyć). */
    public function setActive(int $id, bool $active, ?int $userId): array
    {
        $row = $this->repo->find($id) ?? throw new CustomException('Nie znaleziono partii.', 'errors', 404);
        if ($active && ($row['lot_number'] === null || $row['lot_number'] === '')) throw new CustomException('Najpierw wpisz numer partii.', 'errors', 422);
        if ($active && $row['best_before'] !== null && $row['best_before'] < date('Y-m-d')) throw new CustomException('Partia po dacie ważności — nie można jej włączyć.', 'errors', 422);
        $this->repo->update($id, ['active' => $active ? 1 : 0, 'off_reason' => $active ? null : 'manual', 'updated_by' => $userId]);
        return $this->repo->find($id) ?? $row;
    }

    /** Surowiec śledzony (partia wymagana na tablecie) albo nie. */
    public function setTracked(int $materialId, bool $tracked, ?int $userId): void
    {
        if (!$this->repo->materialExists($materialId)) throw new CustomException('Nie znaleziono surowca.', 'errors', 404);
        $this->repo->setTracked($materialId, $tracked, $userId);
    }

    /** @return array<int, bool> */
    public function tracked(array $materialIds): array
    {
        return $this->repo->tracked($materialIds);
    }

    /** Lista tabletu (makieta 68 B) : partie aktywne, ważne, FEFO. @return array<int, list<array>> */
    public function forTablet(array $materialIds): array
    {
        $this->sync();
        $out = [];
        foreach ($this->repo->forTablet($materialIds, date('Y-m-d')) as $m => $list) {
            $out[$m] = array_map(fn($r) => ['id' => (int) $r['id'], 'lot_number' => $r['lot_number'], 'best_before' => $r['best_before'],
                'received_on' => $r['received_on'], 'supplier' => $r['supplier'], 'invoice' => $r['invoice_number'], 'source' => $r['source']], $list);
        }
        return $out;
    }

    /** Partia z listy dla surowca (wybór na tablecie) : aktywna, z numerem. */
    public function pick(int $lotId, int $materialId): ?array
    {
        $r = $this->repo->find($lotId);
        if ($r === null || (int) $r['id_raw_material'] !== $materialId || !(int) $r['active'] || $r['lot_number'] === null || $r['lot_number'] === '') return null;
        return $r;
    }

    /** Numer wpisany ręcznie na tablecie, którego nie ma w rejestrze : partia « manual » (do sprawdzenia w rejestrze). */
    public function rememberManual(int $materialId, string $lot, ?string $bestBefore, string $day): int
    {
        $lot = self::normalize($lot);
        if ($lot === null) return 0;
        $known = $this->repo->byNumber($materialId, $lot);
        if ($known !== null) return (int) $known['id'];
        $valid = $bestBefore === null || $bestBefore >= date('Y-m-d');
        return $this->repo->insert(['id_raw_material' => $materialId, 'lot_number' => $lot, 'best_before' => $bestBefore, 'source' => 'manual',
            'received_on' => $day, 'active' => $valid ? 1 : 0, 'off_reason' => $valid ? null : 'expired']);
    }

    public static function normalize(string $lot): ?string
    {
        $v = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($lot)));
        return $v === null || $v === '' ? null : mb_substr($v, 0, 64);
    }
}
