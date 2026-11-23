<?php
declare(strict_types=1);

namespace App\Services\Organization\Wizards;

use App\Exceptions\CustomException;
use App\Repositories\Organization\LightWizardRepository;
use App\Repositories\Workshop\ShippingDocsMonthRepository;
use App\Services\Organization\TaskWizards;

/**
 * Asystent « Dokumenty wysyłek miesiąca » [402] (co miesiąc ; zakres obowiązków « produkcja » 237 : Kierownik produkcji
 * 4.1 « Prowadzenie i przechowywanie dokumentacji dotyczącej wywozu produktów », 4.2 « Systematyczne kompletowanie
 * dokumentów na potrzeby kontroli i audytów », 4.4 « Kontrola poprawności i kompletności dokumentów związanych z
 * transportem »). Mois examiné = le mois écoulé (wysyłki de ce mois, shipment_log.shipment_date). « Complet » = ce que
 * l'ERP produit pour chaque wysyłka (ShippingService::insertNewShipping crée WZ, CMR, WDT et document de różnice ;
 * ShippingDocumentService::buildPaper les imprime), sans en inventer :
 *   - WZ / CMR / WDT absente ou introuvable, sans ligne (WZ, WDT) ou sans colis (CMR), CMR sans przewoźnik ni numer
 *     rejestracyjny, quantités WZ ≠ WDT par produit (sauf WDT remplacée : la correction change les quantités) ;
 *   - document de różnice avec un écart (quantité ou prix) dont l'affaire n'est pas close dans « Różnice i problemy
 *     dostaw », problème de livraison non clos ;
 *   - potwierdzenie odbioru : seulement les jours où la tablette chauffeur a servi (n_delivery_run) — arrêt du client
 *     absent ou non livré, ou livré sans podpis odbiorcy.
 * Le dowód opakowań (BDO / PPWR) n'est PAS contrôlé ici : c'est l'assistant « PPWR i opakowania » (compté, lien seulement).
 * Décisions (n_shipping_docs_review) : Uzupełnione — sprawdzone (seulement sans braki maintenant), Dokument papierowy
 * kompletny (note : où il est classé — le papier signé fait foi), Nie dotyczy (note). « Zakończ » écrit n_wizard_done ;
 * la tâche du mois se valide seule. Lecture seule hors de n_shipping_docs_review et n_wizard_done : aucun document changé,
 * rien n'est envoyé (ni e-mail ni KSeF).
 */
final class ShippingDocsMonthService
{
    public const CODE = 'shipping_docs_reviewed';
    public const PATH = '/shipments/documents-month';
    public const DECISIONS = ['checked' => 'Uzupełnione — sprawdzone', 'paper_ok' => 'Dokument papierowy kompletny', 'not_applicable' => 'Nie dotyczy'];
    public const NOTE_REQUIRED = ['paper_ok', 'not_applicable'];
    /** Braki : code => [libellé, écran (clé de LINKS)]. Ordre = priorité d'affichage. */
    public const SIGNALS = [
        'WZ_MISSING' => ['brak WZ', 'wz'],
        'WZ_EMPTY' => ['WZ bez pozycji', 'wz'],
        'CMR_MISSING' => ['brak CMR', 'cmr'],
        'CMR_CARRIER' => ['CMR bez przewoźnika albo numeru rejestracyjnego', 'cmr'],
        'CMR_PACKAGES' => ['CMR bez listy opakowań (kolumny 6–9)', 'cmr'],
        'WDT_MISSING' => ['brak WDT', 'wdt'],
        'WDT_EMPTY' => ['WDT bez pozycji', 'wdt'],
        'WZ_WDT_QTY' => ['ilości WZ i WDT się różnią', 'wdt'],
        'DIFF_OPEN' => ['różnica dostawy nierozliczona (dokument różnic)', 'differences'],
        'DELIVERY_ISSUE_OPEN' => ['problem przy dostawie nierozliczony', 'differences'],
        'RECEIPT_MISSING' => ['brak potwierdzenia odbioru (tablet kierowcy)', 'deliveries'],
        'RECEIPT_NO_SIGNATURE' => ['dostawa bez podpisu odbiorcy (tablet kierowcy)', 'deliveries'],
    ];
    public const LINKS = [
        'wz' => ['/shipments?type=wz', 'Wysyłki i dokumenty › WZ'],
        'cmr' => ['/shipments?type=cmr', 'Wysyłki i dokumenty › CMR'],
        'wdt' => ['/shipments?type=wdt', 'Wysyłki i dokumenty › WDT'],
        'differences' => ['/shipments/differences', 'Różnice i problemy dostaw'],
        'deliveries' => ['/logistics/deliveries', 'Potwierdzenia dostaw'],
        'pdf' => ['/ajax/shipments/%d/documents/grouped?all=1', 'Komplet dokumentów (PDF)'],
        'ppwr' => ['/ppwr/month', 'PPWR i opakowania (asystent)'],
    ];
    private const DAY_ABBR = [1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.'];

    public function __construct(private ShippingDocsMonthRepository $repo, private LightWizardRepository $wizards)
    {
    }

    /** Premier jour du mois examiné (le mois écoulé) : clé des décisions et de l'état de la tâche du mois. */
    public static function reviewedMonth(string $today): string
    {
        return date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    }

    /** Données des trois étapes ($withAll : aussi toutes les wysyłki — pour enregistrer « uzupełnione » d'une wysyłka complétée). */
    public function data(?string $today = null, bool $withAll = false): array
    {
        $today ??= date('Y-m-d');
        $month = self::reviewedMonth($today);
        $end = date('Y-m-t', strtotime($month));
        $rows = $this->repo->shipments($month, $end);
        $checks = $this->repo->checks();
        $wzQ = $checks['wz'] && $checks['wdt'] ? $this->repo->quantities('wz', $month, $end) : [];
        $wdtQ = $checks['wz'] && $checks['wdt'] ? $this->repo->quantities('wdt', $month, $end) : [];

        $items = [];
        $all = [];
        $stats = ['total' => 0, 'incomplete' => 0, 'transport' => 0, 'settlement' => 0, 'receipt' => 0, 'no_packaging' => 0, 'receipt_days' => 0];
        $days = [];
        foreach ($rows ?? [] as $s) {
            $it = $this->shipmentItem($s, $checks, $wzQ[(int) $s['id']] ?? [], $wdtQ[(int) $s['id']] ?? []);
            $stats['total']++;
            if ($it['codes'] !== []) $stats['incomplete']++;
            if (array_intersect($it['codes'], ['WZ_MISSING', 'WZ_EMPTY', 'CMR_MISSING', 'CMR_CARRIER', 'CMR_PACKAGES', 'WDT_MISSING', 'WDT_EMPTY', 'WZ_WDT_QTY'])) $stats['transport']++;
            if (array_intersect($it['codes'], ['DIFF_OPEN', 'DELIVERY_ISSUE_OPEN'])) $stats['settlement']++;
            if (array_intersect($it['codes'], ['RECEIPT_MISSING', 'RECEIPT_NO_SIGNATURE'])) $stats['receipt']++;
            if ($s['packaging_records'] !== null && (int) $s['packaging_records'] === 0) $stats['no_packaging']++;
            if ($s['run_day'] !== null && (int) $s['run_day'] > 0) $days[(string) $s['shipment_date']] = true;
            if ($withAll) $all[$it['key']] = $it;
            $items[] = $it;
        }
        $stats['receipt_days'] = count($days);

        $dec = $this->repo->decisions($month);
        $items = array_values(array_filter($items, fn($i) => $i['codes'] !== [] || isset($dec[$i['key']])));
        foreach ($items as &$it) {
            $mine = $dec[$it['key']] ?? null;
            $covers = $mine !== null && array_diff($it['codes'], array_filter(explode(',', (string) $mine['flags']))) === [];
            $covered = $mine !== null && ($mine['decision'] === 'checked' ? $it['codes'] === [] : $covers);
            $it['decision'] = $mine ? $this->presentDecision($mine) : null;
            $it['waiting'] = $it['codes'] !== [] && !$covered;
            $it['reason'] = $it['waiting'] && $mine !== null ? ($mine['decision'] === 'checked' ? 'brak wrócił po « Uzupełnione »' : 'nowy brak po decyzji') : null;
        }
        unset($it);
        usort($items, fn($a, $b) => [$b['waiting'], count($b['codes']), $a['date'], $a['key']] <=> [$a['waiting'], count($a['codes']), $b['date'], $b['key']]);

        $count = fn(callable $f) => count(array_filter($items, $f));
        $byCode = [];
        foreach (array_keys(self::SIGNALS) as $c) $byCode[$c] = $count(fn($i) => in_array($c, $i['codes'], true));
        $totals = ['review' => count($items), 'waiting' => $count(fn($i) => $i['waiting']), 'resolved' => $count(fn($i) => !$i['waiting']), 'by_code' => $byCode, 'shipments' => $stats];
        foreach (array_keys(self::DECISIONS) as $k) $totals[$k] = $count(fn($i) => $i['decision'] !== null && $i['decision']['code'] === $k);
        $label = TaskWizards::monthLabel(substr($month, 0, 7));
        $taskMonth = substr($today, 0, 7) . '-01';
        $done = $this->wizards->done(self::CODE, $taskMonth, date('Y-m-01', strtotime($taskMonth . ' +1 month')));
        return ($withAll ? ['all' => $all] : []) + ['today' => $today, 'label' => self::label($today), 'month_start' => $month, 'month_end' => $end, 'month_label' => $label,
            'source' => ['available' => $rows !== null && $rows !== [], 'rows' => $rows === null ? null : count($rows),
                'note' => $rows === null ? 'brak danych — brak tabeli wysyłek w bazie' : ($rows === [] ? 'brak danych — brak wysyłek w miesiącu ' . $label : null)],
            'checks' => $checks,
            'review' => $items, 'totals' => $totals, 'can_finish' => $totals['waiting'] === 0, 'summary' => self::summary($label, $totals),
            'decisions' => self::DECISIONS, 'note_required' => self::NOTE_REQUIRED, 'signal_labels' => array_map(fn($s) => $s[0], self::SIGNALS),
            'ppwr_link' => ['href' => self::LINKS['ppwr'][0], 'label' => self::LINKS['ppwr'][1]],
            'done' => $done ? ['summary' => $done['summary'], 'at' => self::label((string) $done['day']) . ', ' . self::hm((string) $done['done_at']), 'by' => $done['by_login'] ?? null] : null];
    }

    /** Wysyłka : braki de ses documents de transport, de la file des écarts et de la confirmation de livraison. */
    private function shipmentItem(array $s, array $checks, array $wzQ, array $wdtQ): array
    {
        $id = (int) $s['id'];
        $codes = [];
        $detail = [];
        $has = fn($v) => $v !== null && (int) $v > 0;
        if ($checks['wz']) {
            if (!$has($s['id_wz']) || (int) $s['wz_found'] === 0) $codes[] = 'WZ_MISSING';
            elseif ((int) $s['wz_lines'] === 0) $codes[] = 'WZ_EMPTY';
        }
        if ($checks['cmr']) {
            if (!$has($s['id_cmr']) || (int) $s['cmr_found'] === 0) $codes[] = 'CMR_MISSING';
            else {
                [$carrier, $plate] = array_pad(explode('|', (string) $s['cmr_carrier'], 2), 2, '');
                if (trim($carrier) === '' || trim($plate) === '') { $codes[] = 'CMR_CARRIER'; $detail['CMR_CARRIER'] = trim($carrier) === '' ? 'brak przewoźnika' : 'brak numeru rejestracyjnego'; }
                if ((int) $s['cmr_packages'] === 0) $codes[] = 'CMR_PACKAGES';
            }
        }
        if ($checks['wdt']) {
            if (!$has($s['id_wdt']) || (int) $s['wdt_found'] === 0) $codes[] = 'WDT_MISSING';
            elseif ((int) $s['wdt_lines'] === 0) $codes[] = 'WDT_EMPTY';
        }
        // Quantités par produit : seulement deux documents avec lignes et une WDT non remplacée (la correction change les quantités).
        if ($wzQ !== [] && $wdtQ !== [] && (int) $s['wdt_superseded'] === 0) {
            $diff = [];
            foreach (array_unique(array_merge(array_keys($wzQ), array_keys($wdtQ))) as $p) {
                if (abs(($wzQ[$p] ?? 0.0) - ($wdtQ[$p] ?? 0.0)) >= 0.001) $diff[] = $p;
            }
            if ($diff) { $codes[] = 'WZ_WDT_QTY'; $detail['WZ_WDT_QTY'] = count($diff) . ' ' . self::pl(count($diff), 'produkt', 'produkty', 'produktów') . ' z inną ilością'; }
        }
        if ($checks['diff'] && $has($s['id_diff']) && (int) $s['diff_lines'] > 0 && $s['diff_issue'] !== 'done') {
            $codes[] = 'DIFF_OPEN';
            $detail['DIFF_OPEN'] = (int) $s['diff_lines'] . ' ' . self::pl((int) $s['diff_lines'], 'pozycja', 'pozycje', 'pozycji') . ' z różnicą · ' . ($s['diff_issue'] === null ? 'sprawa nie założona' : ($s['diff_issue'] === 'open' ? 'do decyzji' : 'w toku'));
        }
        if ($checks['issues'] && $s['stop_issue'] !== null && $s['stop_issue'] !== 'done') $codes[] = 'DELIVERY_ISSUE_OPEN';
        if ($checks['receipt'] && (int) $s['run_day'] > 0) {
            if ($s['stop_status'] === null || $s['stop_status'] === 'arrived') { $codes[] = 'RECEIPT_MISSING'; $detail['RECEIPT_MISSING'] = $s['stop_status'] === 'arrived' ? 'kierowca na miejscu, dostawa niezamknięta' : 'brak przystanku klienta tego dnia'; }
            elseif ($s['stop_status'] === 'delivered' && (int) $s['stop_signatures'] === 0) $codes[] = 'RECEIPT_NO_SIGNATURE';
        }
        $date = substr((string) $s['shipment_date'], 0, 10);
        $docs = array_filter(['WZ ' . ($s['wz_num'] ?? null), 'CMR ' . ($s['cmr_num'] ?? null), 'WDT ' . ($s['wdt_num'] ?? null)], fn($x) => !in_array($x, ['WZ ', 'CMR ', 'WDT '], true));
        $keys = array_values(array_unique(array_map(fn($c) => self::SIGNALS[$c][1], $codes)));
        $keys[] = 'pdf';
        return ['key' => 'shipment:' . $id, 'source_id' => $id, 'date' => $date, 'title' => trim((string) ($s['customer'] ?? '')) ?: 'klient #' . (int) $s['id_customer'],
            'sub' => 'wysyłka ' . self::label($date) . '.' . substr($date, 0, 4) . ($docs ? ' · ' . implode(' · ', $docs) : '') . ((int) $s['wdt_superseded'] > 0 ? ' · WDT zastąpiona' : ''),
            'codes' => $codes, 'signals' => array_map(fn($c) => ['code' => $c, 'label' => self::SIGNALS[$c][0], 'detail' => $detail[$c] ?? null], $codes),
            'links' => array_map(fn($k) => ['href' => str_replace('%d', (string) $id, self::LINKS[$k][0]), 'label' => self::LINKS[$k][1]], $keys)];
    }

    private function presentDecision(array $d): array
    {
        return ['code' => (string) $d['decision'], 'label' => self::DECISIONS[$d['decision']] ?? (string) $d['decision'], 'note' => $d['note'],
            'flags' => array_values(array_filter(explode(',', (string) $d['flags']))), 'by' => $d['decided_by'],
            'at' => self::label(substr((string) $d['updated_at'], 0, 10)) . ', ' . self::hm((string) $d['updated_at'])];
    }

    /** Krok 2 : décision pour une wysyłka (une par mois examiné, la dernière compte). Aucun document n'est changé. */
    public function saveDecision(array $in, ?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $key = (string) ($in['key'] ?? '');
        $decision = (string) ($in['decision'] ?? '');
        $note = trim((string) ($in['note'] ?? ''));
        if (!isset(self::DECISIONS[$decision])) throw new CustomException('Wybierz decyzję : uzupełnione — sprawdzone, dokument papierowy kompletny albo nie dotyczy.', 'errors', 422);
        if (mb_strlen($note) > 500) throw new CustomException('Notatka : najwyżej 500 znaków.', 'errors', 422);
        $d = $this->data($today, true);
        $it = array_values(array_filter($d['review'], fn($x) => $x['key'] === $key))[0] ?? null;
        $seen = array_values(array_intersect(array_keys(self::SIGNALS), array_map('strval', (array) ($in['seen'] ?? []))));
        if ($it === null && $decision === 'checked' && $seen !== [] && isset($d['all'][$key]) && $d['all'][$key]['codes'] === []) $it = $d['all'][$key];
        if ($it === null) {
            if (isset($d['all'][$key]) && $decision === 'checked') throw new CustomException('Dokumenty tej wysyłki nie miały braków.', 'errors', 422);
            throw new CustomException('Tej wysyłki nie ma na liście (inny miesiąc albo dokumenty bez braków).', 'errors', 422);
        }
        if ($decision === 'paper_ok' && mb_strlen($note) < 3) throw new CustomException('Dokument papierowy kompletny : wpisz, gdzie jest (np. segregator CMR listopad, podpisany przez odbiorcę).', 'errors', 422);
        if ($decision === 'not_applicable' && mb_strlen($note) < 3) throw new CustomException('Nie dotyczy : wpisz dlaczego (np. odbiór własny klienta, przewoźnik zewnętrzny).', 'errors', 422);
        if ($decision === 'checked' && $it['codes'] !== []) {
            throw new CustomException('Nadal brakuje : ' . implode(', ', array_map(fn($c) => self::SIGNALS[$c][0], $it['codes'])) . '. Uzupełnij na ekranie ' . $it['links'][0]['label'] . ' albo wybierz « Dokument papierowy kompletny ».', 'errors', 422);
        }
        if ($decision !== 'checked' && $it['codes'] === []) throw new CustomException('Ta wysyłka nie ma już braków — wybierz « Uzupełnione — sprawdzone ».', 'errors', 422);
        $flags = $it['codes'] !== [] ? $it['codes'] : ($seen ?: ($it['decision']['flags'] ?? []));
        $this->repo->saveDecision($d['month_start'], $key, $it['source_id'], $decision, $note !== '' ? $note : null, implode(',', $flags), $userId);
        return $this->data($today);
    }

    /** Krok 3 : « Zakończ » — chaque wysyłka signalée a sa décision ; la tâche du mois se valide seule. */
    public function finish(?int $userId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $d = $this->data($today);
        $w = $d['totals']['waiting'];
        if ($w > 0) throw new CustomException('Najpierw zapisz decyzję dla ' . $w . ' ' . self::pl($w, 'wysyłki', 'wysyłek', 'wysyłek') . ' z kroku 2.', 'errors', 422);
        $this->wizards->markDone(self::CODE, $today, $d['summary'], $userId);
        return ['summary' => $d['summary'], 'data' => $this->data($today)];
    }

    /** « październik 2026 : wysyłki 3 z brakami / 18 · dokumenty 2 · rozliczenia 1 · potwierdzenia 1 · decyzje : … » */
    public static function summary(string $month, array $t): string
    {
        $s = $t['shipments'];
        $out = $month . ' : wysyłki ' . $s['incomplete'] . ' z brakami / ' . $s['total'] . ' · dokumenty ' . $s['transport'] . ' · rozliczenia ' . $s['settlement'] . ' · potwierdzenia ' . $s['receipt'];
        $dec = [];
        if ($t['checked']) $dec[] = $t['checked'] . ' sprawdzone';
        if ($t['paper_ok']) $dec[] = $t['paper_ok'] . ' na papierze';
        if ($t['not_applicable']) $dec[] = $t['not_applicable'] . ' nie dotyczy';
        return $dec ? $out . ' · decyzje : ' . implode(' · ', $dec) : $out;
    }

    public static function label(string $day): string
    {
        $t = strtotime($day);
        return $t === false ? $day : self::DAY_ABBR[(int) date('N', $t)] . ' ' . date('j.m', $t);
    }

    private static function hm(string $ts): string
    {
        return strlen($ts) >= 16 ? (int) substr($ts, 11, 2) . substr($ts, 13, 3) : '';
    }

    private static function pl(int $n, string $one, string $few, string $many): string
    {
        return $n === 1 ? $one : (in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true) ? $few : $many);
    }
}
