<?php
declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\Translator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Purchasing\ReceivingWizardRepository;
use App\Repositories\Workshop\SupplyRepository;
use App\Services\Notification\AppNotificationService;
use App\Services\Workshop\Supply\SupplyService;

/**
 * Kreator « Kontrola przyjęć dnia » [230] (Jakość, codziennie ; nikt na Jakości → Kierownik produkcji) : dostawy dnia
 * z zamówień dostawców, pozycja po pozycji OK / niezgodność. Każda pozycja zapisuje przyjęcie w zamówieniu (ilość,
 * partia, termin, temperatura — SupplyService::receive, stan surowca i partie od razu aktualne) ; niezgodność (rodzaj,
 * opis, zdjęcia, decyzja) trafia do rejestru NC, « zwrot » i « do decyzji kierownika » — do dzwonka. Po ostatniej
 * dostawie : podsumowanie i szkic e-maila do dostawcy (wysyła użytkownik). Zadanie zalicza się samo, gdy wszystkie
 * dostawy dnia są sprawdzone (TaskWizards : receiving_checked).
 */
final class ReceivingWizardService
{
    public const KINDS = ['qty' => 'Ilość', 'quality' => 'Jakość / wygląd', 'temp' => 'Temperatura', 'package' => 'Opakowanie uszkodzone',
        'date' => 'Termin ważności', 'doc' => 'Brak dokumentu'];
    public const DECISIONS = ['accepted' => 'Przyjęte', 'conditional' => 'Przyjęte warunkowo', 'return' => 'Zwrot do dostawcy', 'manager' => 'Do decyzji kierownika'];
    private const KINDS_FR = ['qty' => 'quantité', 'quality' => 'qualité / aspect', 'temp' => 'température', 'package' => 'emballage endommagé',
        'date' => 'date de péremption', 'doc' => 'document manquant'];
    private const DECISIONS_FR = ['accepted' => 'acceptée', 'conditional' => 'acceptée sous réserve', 'return' => 'retour au fournisseur', 'manager' => 'en attente de décision'];
    /** Surowce do chłodni / mroźni (temperatura przy przyjęciu) : kategoria albo nazwa. */
    private const COLD = '/nabia|mi[eę]so|ryb|t[lł]uszcz|mro[zż]|ch[lł]od|dro[zż]d[zż]|mas[lł]o|[sś]mietan|mleko|jaj|ser |twar[oó]g|jogurt/iu';
    private const PHOTO_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    private const PHOTO_MAX = 4;
    private const PHOTO_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private ReceivingWizardRepository $repo,
        private SupplyRepository $supply,
        private SupplyService $supplyService,
        private NcService $nc,
        private AppNotificationService $notifications,
        private TransactionManager $tx,
    ) {
    }

    public static function isCold(?string $category, ?string $name): bool
    {
        return (bool) preg_match(self::COLD, ' ' . ($category ?? '') . ' ' . ($name ?? '') . ' ');
    }

    /** Dzień kontroli : dziś albo do 30 dni wstecz. */
    public static function day(?string $day, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if ($day === null || $day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) return $today;
        if ($day > $today) return $today;
        return $day < date('Y-m-d', strtotime($today . ' -30 days')) ? $today : $day;
    }

    /** Krok 1 : dostawy dnia i zaległe, liczniki dnia (panel « Dziś »). */
    public function overview(string $day): array
    {
        $orders = [];
        $stats = ['due' => 0, 'checked' => 0, 'lines' => 0, 'ok' => 0, 'nc' => 0, 'overdue' => 0];
        foreach ($this->repo->orders($day) as $o) {
            $due = (bool) $o['due'];
            $checked = $o['checked_at'] !== null;
            $orders[] = [
                'id' => (int) $o['id'], 'code' => $o['code'], 'status' => $o['status'], 'supplier' => trim((string) $o['supplier_name']),
                'delivery_date' => $o['delivery_date'], 'due' => $due, 'lines' => (int) $o['line_count'],
                'receivable' => in_array($o['status'], ['sent', 'partial'], true),
                'check' => $checked ? ['ok' => (int) $o['lines_ok'], 'nc' => (int) $o['lines_nc'], 'arrived' => (bool) $o['arrived'], 'at' => substr((string) $o['checked_at'], 11, 5),
                    'by' => $o['checked_by_name'] ?: null] : null,
            ];
            if ($due) {
                $stats['due']++;
                $stats['lines'] += (int) $o['line_count'];
                if ($checked) { $stats['checked']++; $stats['ok'] += (int) $o['lines_ok']; $stats['nc'] += (int) $o['lines_nc']; }
            } else {
                $stats['overdue']++;
            }
        }
        return ['day' => $day, 'orders' => $orders, 'stats' => $stats, 'kinds' => self::KINDS, 'decisions' => self::DECISIONS];
    }

    /** Krok 2 : pozycje zamówienia (jednostka wyświetlana : kg / l / szt). */
    public function order(int $id, string $day): array
    {
        $o = $this->supply->order($id) ?? throw new CustomException('Nie znaleziono zamówienia.', 'errors', 404);
        $today = [];
        foreach ($this->repo->receiptsOn($id, $day) as $r) {
            $lid = (int) $r['id_po_line'];
            $t = $today[$lid] ?? ['qty' => 0.0, 'lot' => null, 'best_before' => null, 'temperature' => null, 'nc' => null];
            $t['qty'] += (float) $r['quantity'];
            $t['lot'] = $r['lot_number'] ?? $t['lot'];
            $t['best_before'] = $r['best_before'] ?? $t['best_before'];
            $t['temperature'] = $r['temperature_c'] !== null ? (float) $r['temperature_c'] : $t['temperature'];
            $t['nc'] = $r['id_nc'] !== null ? (int) $r['id_nc'] : $t['nc'];
            $today[$lid] = $t;
        }
        $lines = [];
        foreach ($this->repo->lines($id) as $l) {
            $u = CostOptimizationService::displayUnit($l['unit']);
            $f = (float) $u['factor'];
            $ordered = (float) $l['quantity'];
            $received = (float) $l['received_qty'];
            $t = $today[(int) $l['id']] ?? null;
            $pack = $l['package_size'] !== null && (float) $l['package_size'] > 0 ? (float) $l['package_size'] : null;
            $lines[] = [
                'id' => (int) $l['id'], 'material_id' => (int) $l['id_raw_material'], 'name' => $l['name'], 'unit' => $u['label'], 'factor' => $f,
                'ordered' => round($ordered / $f, 3), 'received' => round($received / $f, 3), 'remaining' => round(max(0.0, $ordered - $received) / $f, 3),
                'package' => $pack ? round($pack / $f, 3) : null, 'cold' => self::isCold($l['category'], $l['name']),
                'today' => $t ? ['qty' => round($t['qty'] / $f, 3), 'lot' => $t['lot'], 'best_before' => $t['best_before'], 'temperature' => $t['temperature'], 'nc' => $t['nc']] : null,
            ];
        }
        $check = $this->repo->check($id, $day);
        return [
            'id' => (int) $o['id'], 'code' => $o['code'], 'status' => $o['status'], 'supplier' => trim((string) $o['supplier_name']),
            'delivery_date' => $o['delivery_date'], 'receivable' => in_array($o['status'], ['sent', 'partial'], true), 'lines' => $lines,
            'check' => $check ? ['ok' => (int) $check['lines_ok'], 'nc' => (int) $check['lines_nc'], 'arrived' => (bool) $check['arrived'], 'at' => substr((string) $check['checked_at'], 11, 5)] : null,
        ];
    }

    /**
     * Zapis kontroli zamówienia : { day, arrived, lines: [{id, status: ok|nc, qty, lot, best_before, temperature}],
     * problems: [{line_id, kinds: [...], description, decision, photos: [data URL]}] }.
     * @return array{ok:int, nc:int, nc_numbers:string[]}
     */
    public function save(int $id, array $body, ?int $userId, ?int $employeeId = null): array
    {
        $o = $this->supply->order($id) ?? throw new CustomException('Nie znaleziono zamówienia.', 'errors', 404);
        $day = self::day(is_string($body['day'] ?? null) ? $body['day'] : null);
        if (($body['arrived'] ?? true) === false) {
            $this->repo->saveCheck($id, $day, 0, 0, false, [], $userId, $employeeId);
            return ['ok' => 0, 'nc' => 0, 'nc_numbers' => []];
        }
        $own = [];
        foreach ($this->repo->lines($id) as $l) $own[(int) $l['id']] = $l;
        $rows = is_array($body['lines'] ?? null) ? array_values($body['lines']) : [];
        if (!$rows) throw new CustomException('Sprawdź co najmniej jedną pozycję (OK albo niezgodność).', 'errors', 422);
        $problems = [];
        foreach (is_array($body['problems'] ?? null) ? $body['problems'] : [] as $p) {
            if (is_array($p) && is_int($p['line_id'] ?? null)) $problems[$p['line_id']] = $p;
        }
        $receivable = in_array($o['status'], ['sent', 'partial'], true);
        $receive = [];
        $checked = [];
        foreach ($rows as $i => $r) {
            $lid = is_array($r) ? ($r['id'] ?? null) : null;
            if (!is_int($lid) || !isset($own[$lid])) throw new CustomException('Pozycja ' . ($i + 1) . ' nie należy do zamówienia.', 'errors', 422);
            $status = $r['status'] ?? null;
            if (!in_array($status, ['ok', 'nc'], true)) throw new CustomException('Pozycja « ' . $own[$lid]['name'] . ' » : wybierz OK albo Niezgodność.', 'errors', 422);
            $f = (float) CostOptimizationService::displayUnit($own[$lid]['unit'])['factor'];
            $qty = $r['qty'] ?? null;
            if ($qty !== null && !is_int($qty) && !is_float($qty)) throw new CustomException('Pozycja « ' . $own[$lid]['name'] . ' » : ilość musi być liczbą.', 'errors', 422);
            $qty = $qty === null ? 0.0 : (float) $qty;
            if ($qty < 0) throw new CustomException('Pozycja « ' . $own[$lid]['name'] . ' » : ilość nie może być ujemna.', 'errors', 422);
            $temp = $r['temperature'] ?? null;
            if ($temp !== null && !is_int($temp) && !is_float($temp)) throw new CustomException('Pozycja « ' . $own[$lid]['name'] . ' » : temperatura musi być liczbą.', 'errors', 422);
            if ($status === 'nc' && !isset($problems[$lid])) throw new CustomException('Pozycja « ' . $own[$lid]['name'] . ' » : opisz niezgodność (co jest nie tak, decyzja).', 'errors', 422);
            $base = round($qty * $f, 3);
            if ($receivable && $base > 0) {
                $receive[] = ['id' => $lid, 'quantity' => $base, 'lot_number' => is_string($r['lot'] ?? null) ? $r['lot'] : null,
                    'best_before' => is_string($r['best_before'] ?? null) && $r['best_before'] !== '' ? $r['best_before'] : null,
                    'temperature_c' => $temp !== null ? (float) $temp : null];
            }
            $checked[$lid] = ['status' => $status, 'qty' => $base, 'temperature' => $temp];
        }
        $clean = [];
        foreach ($checked as $lid => $c) {
            if ($c['status'] !== 'nc') continue;
            $p = $problems[$lid];
            $kinds = array_values(array_intersect(array_keys(self::KINDS), is_array($p['kinds'] ?? null) ? $p['kinds'] : []));
            if (!$kinds) throw new CustomException('Niezgodność « ' . $own[$lid]['name'] . ' » : wybierz, co jest nie tak.', 'errors', 422);
            $decision = $p['decision'] ?? null;
            if (!isset(self::DECISIONS[$decision])) throw new CustomException('Niezgodność « ' . $own[$lid]['name'] . ' » : wybierz decyzję.', 'errors', 422);
            $desc = trim((string) ($p['description'] ?? ''));
            if (mb_strlen($desc) > 1000) throw new CustomException('Opis niezgodności : najwyżej 1000 znaków.', 'errors', 422);
            $photos = [];
            foreach (is_array($p['photos'] ?? null) ? array_slice($p['photos'], 0, self::PHOTO_MAX + 1) : [] as $ph) $photos[] = self::photo($ph);
            if (count($photos) > self::PHOTO_MAX) throw new CustomException('Najwyżej ' . self::PHOTO_MAX . ' zdjęcia na niezgodność.', 'errors', 422);
            $clean[$lid] = ['kinds' => $kinds, 'decision' => $decision, 'description' => $desc, 'photos' => $photos];
        }

        $ctx = new ApiContext('receiving-' . bin2hex(random_bytes(4)), new Translator('pl'), $userId, [], ['prod.supply.write' => true], [], null, $employeeId);
        $by = $employeeId !== null ? $this->supply->employeeName($employeeId) : null;
        $numbers = [];
        $this->tx->execute(function () use ($id, $o, $day, $receive, $checked, $clean, $own, $ctx, $userId, $employeeId, $by, &$numbers) {
            if ($receive) $this->supplyService->receive($id, ['received_on' => $day, 'lines' => $receive], $ctx);
            $ncIds = [];
            foreach ($clean as $lid => $p) {
                $line = $own[$lid];
                $f = (float) CostOptimizationService::displayUnit($line['unit'])['factor'];
                $remaining = max(0.0, (float) $line['quantity'] - (float) $line['received_qty']);
                $accepted = $checked[$lid]['qty'];
                $affected = $p['decision'] === 'return' ? max($remaining, $accepted) : (in_array('qty', $p['kinds'], true) ? max(0.0, $remaining - $accepted) : $accepted);
                $unit = CostOptimizationService::displayUnit($line['unit'])['label'];
                $kindLabels = implode(', ', array_map(fn($k) => self::KINDS[$k], $p['kinds']));
                $decisionLabel = self::DECISIONS[$p['decision']] . ($p['decision'] === 'accepted' || $p['decision'] === 'conditional' ? ' (' . self::qty($accepted / $f) . ' ' . $unit . ')' : '');
                $ncId = $this->nc->save([
                    'reported_at' => $day, 'source' => 'supplier_delivery', 'supplier_id' => (int) $o['id_supplier'], 'material_id' => (int) $line['id_raw_material'],
                    'description' => mb_substr('Przyjęcie ' . $o['code'] . ' — ' . $line['name'] . ' [' . $kindLabels . ']' . ($p['description'] !== '' ? ' : ' . $p['description'] : '')
                        . ($by ? ' (tablet : ' . $by . ')' : ''), 0, 5000),
                    'severity' => $p['decision'] === 'return' || array_intersect($p['kinds'], ['quality', 'temp', 'date']) ? 'major' : 'minor',
                    'quantity' => $affected > 0 ? round($affected, 3) : null, 'unit' => $line['unit'] ?: null,
                    'immediate_action' => 'Decyzja przy przyjęciu : ' . $decisionLabel,
                    'status' => in_array($p['decision'], ['return', 'manager'], true) ? 'open' : 'in_progress',
                ], null, $userId);
                $ncIds[] = $ncId;
                if ($accepted > 0 && ($rid = $this->repo->lastReceiptOf($lid, $day))) $this->supply->linkReceiptNc($rid, $ncId);
                foreach ($p['photos'] as [$mime, $bytes]) $this->repo->addPhoto($ncId, $mime, $bytes, $userId);
                $number = $this->repo->ncs([$ncId])[0]['number'] ?? ('#' . $ncId);
                $numbers[] = $number;
                if (in_array($p['decision'], ['return', 'manager'], true)) {
                    $this->notifications->publish('receiving_nc', 'warning',
                        ($p['decision'] === 'return' ? 'Zwrot do dostawcy : ' : 'Do decyzji kierownika : ') . $line['name'] . ' (' . $kindLabels . ')',
                        trim((string) $o['supplier_name']) . ' · ' . $o['code'] . ' · ' . $number, '/purchasing/nc', ['nc' => $ncId, 'po' => $id], 'receiving_nc:' . $ncId);
                }
            }
            $ok = count(array_filter($checked, fn($c) => $c['status'] === 'ok'));
            $this->repo->saveCheck($id, $day, $ok, count($clean), true, $ncIds, $userId, $employeeId);
        });
        return ['ok' => count(array_filter($checked, fn($c) => $c['status'] === 'ok')), 'nc' => count($clean), 'nc_numbers' => $numbers];
    }

    /** Krok 3 : sprawdzone dostawy dnia, niezgodności ze zdjęciami i szkic e-maila do dostawcy (w jego języku). */
    public function summary(string $day): array
    {
        $ov = $this->overview($day);
        $ncIds = [];
        foreach ($this->repo->orders($day) as $o) foreach (json_decode((string) ($o['nc_ids'] ?? ''), true) ?: [] as $nid) $ncIds[] = (int) $nid;
        $photos = $this->repo->photoIds($ncIds);
        $bySupplier = [];
        $langOf = [];
        foreach ($this->repo->orders($day) as $o) $langOf[(int) $o['id_supplier']] = ['lang' => $o['order_lang'] === 'fr' ? 'fr' : 'pl', 'email' => $o['supplier_email'], 'code' => $o['code']];
        $ncs = [];
        foreach ($this->repo->ncs($ncIds) as $n) {
            $sid = (int) $n['id_supplier'];
            $row = ['id' => (int) $n['id'], 'number' => $n['number'], 'supplier' => $n['supplier_name'], 'material' => $n['material_name'],
                'description' => $n['description'], 'decision' => $n['immediate_action'], 'status' => $n['status'], 'photos' => $photos[(int) $n['id']] ?? []];
            $ncs[] = $row;
            $bySupplier[$sid][] = $row;
        }
        $mails = [];
        foreach ($bySupplier as $sid => $rows) {
            $info = $langOf[$sid] ?? ['lang' => 'pl', 'email' => null, 'code' => ''];
            $mails[] = ['supplier' => $rows[0]['supplier'], 'email' => filter_var(trim((string) $info['email']), FILTER_VALIDATE_EMAIL) ? trim((string) $info['email']) : null]
                + self::mail($rows, $info['lang'], $day);
        }
        return $ov + ['ncs' => $ncs, 'mails' => $mails];
    }

    public function photoFile(int $id): ?array
    {
        return $this->repo->photo($id);
    }

    /** Szkic e-maila do dostawcy (PL / FR) — wysyła użytkownik. */
    private static function mail(array $ncs, string $lang, string $day): array
    {
        $d = substr($day, 8, 2) . '.' . substr($day, 5, 2) . '.' . substr($day, 0, 4);
        $numbers = implode(', ', array_column($ncs, 'number'));
        if ($lang === 'fr') {
            $lines = array_map(fn($n) => '– ' . $n['material'] . ' : ' . preg_replace('/^Przyjęcie [^—]+— [^\[]+/u', '', (string) $n['description']) . ' — ' . self::decisionFr((string) $n['decision']), $ncs);
            return ['subject' => 'Non-conformité à la réception du ' . $d . ' (' . $numbers . ')',
                'body' => "Bonjour,\n\nlors de la réception de votre livraison du " . $d . " nous avons constaté :\n" . implode("\n", $lines)
                    . "\n\nLes photos sont disponibles sur demande. Merci de nous indiquer la suite donnée (avoir, remplacement, reprise).\n\nCordialement"];
        }
        $lines = array_map(fn($n) => '– ' . $n['material'] . ' : ' . preg_replace('/^Przyjęcie [^—]+— [^\[]+/u', '', (string) $n['description']) . ' — ' . preg_replace('/^Decyzja przy przyjęciu : /u', 'decyzja : ', (string) $n['decision']), $ncs);
        return ['subject' => 'Niezgodność przy przyjęciu dostawy ' . $d . ' (' . $numbers . ')',
            'body' => "Dzień dobry,\n\nprzy przyjęciu Państwa dostawy z dnia " . $d . " stwierdziliśmy :\n" . implode("\n", $lines)
                . "\n\nZdjęcia prześlemy na życzenie. Prosimy o informację, jak sprawa zostanie rozwiązana (korekta faktury, wymiana, odbiór).\n\nPozdrawiamy"];
    }

    private static function decisionFr(string $pl): string
    {
        foreach (self::DECISIONS as $k => $label) if (str_contains($pl, $label)) return 'décision : ' . self::DECISIONS_FR[$k] . (preg_match('/\(([^)]+)\)/u', $pl, $m) ? ' (' . $m[1] . ')' : '');
        return $pl;
    }

    /** Zdjęcie z przeglądarki (data URL JPEG / PNG / WebP, do 3 MB) → [mime, bajty]. */
    private static function photo(mixed $dataUrl): array
    {
        if (!is_string($dataUrl) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
            throw new CustomException('Zdjęcie : dozwolone JPEG, PNG albo WebP.', 'errors', 422);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::PHOTO_BYTES) throw new CustomException('Zdjęcie : za duże albo uszkodzone (najwyżej 3 MB).', 'errors', 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (!in_array($mime, self::PHOTO_MIME, true)) throw new CustomException('Zdjęcie : dozwolone JPEG, PNG albo WebP.', 'errors', 422);
        return [$mime, $bytes];
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', "\u{00A0}"), '0'), ',');
    }
}
