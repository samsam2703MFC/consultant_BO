<?php
declare(strict_types=1);

namespace App\Services\Workshop\Finance;

use App\Repositories\Workshop\FinanceRepository;
use App\Services\Production\Cost\CostMonthService;

/**
 * Kategorie kosztów usług = kategorie kalkulatora kosztów : koszty stałe (fixed:RENT…), media (media:POWER…, typy
 * cost_media_type), logistyka (log:FUEL…, typy cost_logistics_type). Zamknięcie miesiąca usług zapisuje sumy dokładnie tam.
 * Podpowiedź kategorii faktury : sprzedawca (Orlen → paliwo, Orange → telefon…) albo nazwy linii (≥ 60 % wartości netto).
 */
final class CostCategories
{
    public const GROUPS = ['fixed' => 'Koszty stałe', 'media' => 'Media', 'log' => 'Logistyka'];
    /** Media i logistyka mają w kalkulatorze własne typy : bez ich kategorii zbiorczych w kosztach stałych. */
    private const FIXED_EXCLUDED = ['UTILITIES', 'LOGISTICS'];
    /** Udział wartości netto linii pasujących do kategorii, od którego faktura wygląda na usługę. */
    private const LINE_SHARE = 0.6;

    /** [kategoria, sprzedawca, nazwy linii] — tekst bez polskich znaków, małe litery. */
    private const GUESS = [
        ['log:FUEL', '/\b(orlen|bp|shell|circle ?k|lotos|amic|moya|statoil|avia)\b/u', '/\b(paliw\w*|benzyn\w*|diesel\w*|olej napedowy|pb ?9[58]|e95|lpg|adblue|verva|efecta|v-power)\b/u'],
        ['media:POWER', '/\b(tauron|pge|enea|energa|innogy|e\.?on|stoen|polenergia)\b/u', '/\b(energi\w* elektryczn\w*|prad\w*|dystrybucj\w* energii|oplat\w* mocow\w*)\b/u'],
        ['media:GAZ', '/\b(pgnig|psg|gazownictw\w*|hermes energy)\b/u', '/\b(gaz\w* ziemn\w*|paliw\w* gazow\w*|dystrybucj\w* gazu)\b/u'],
        ['media:WATER', '/\b(wodociag\w*|mpwik|aquanet|wodkan)\b/u', '/\b(scieki|sciekow|dostaw\w* wody|zaopatrzeni\w* w wode)\b/u'],
        ['media:AZOT', '/\b(linde|air liquide|messer)\b/u', '/\b(azot\w*)\b/u'],
        ['media:OIL', null, '/\b(olej\w* opalow\w*)\b/u'],
        ['fixed:TELECOM', '/\b(orange|play|t-mobile|polkomtel|netia|vectra|upc|inea)\b/u', '/\b(abonament\w*|internet\w*|telefon\w*|telekomunikac\w*|transmisj\w* danych)\b/u'],
        ['log:COURIER', '/\b(dpd|dhl|inpost|gls|ups|fedex|poczta polska|pocztex)\b/u', '/\b(kurier\w*|przesylk\w*|paczk\w*)\b/u'],
        ['log:FORWARDER', null, '/\b(spedycj\w*)\b/u'],
        ['log:FREIGHT_OUT', null, '/\b(transport\w*|fracht\w*|przewoz\w*)\b/u'],
        ['fixed:RENT', null, '/\b(czynsz\w*|najem\w*|najmu|dzierzaw\w*|wynajem\w*|leasing\w*)\b/u'],
        ['fixed:WASTE', null, '/\b(odpad\w*|wywoz\w*|utylizac\w*|smieci)\b/u'],
        ['fixed:CLEANING', null, '/\b(sprzatani\w*|deratyzac\w*|dezynsekc\w*)\b/u'],
        ['fixed:MAINTENANCE', null, '/\b(napraw\w*|serwis\w*|przeglad\w*|konserwac\w*)\b/u'],
        ['fixed:IT', '/\b(microsoft|google|ovh|atman|cyberfolks|adobe)\b/u', '/\b(licencj\w*|oprogramowani\w*|hosting\w*|domen\w*|subskrypc\w*|saas)\b/u'],
        ['fixed:INSURANCE', '/\b(pzu|warta|allianz|hestia|generali|uniqa|compensa|link4|interrisk)\b/u', '/\b(ubezpieczeni\w*|polis\w*)\b/u'],
        ['fixed:FINANCE_LEGAL', null, '/\b(ksiegow\w*|biuro rachunkowe|uslug\w* prawn\w*|notar\w*|prowizj\w* bankow\w*|oplat\w* bankow\w*)\b/u'],
        ['fixed:MARKETING', '/\b(meta platforms|facebook)\b/u', '/\b(reklam\w*|marketing\w*)\b/u'],
        ['fixed:HR_TRAINING', null, '/\b(szkoleni\w*|rekrutac\w*|medycyn\w* pracy|badani\w* okresow\w*)\b/u'],
    ];

    public function __construct(private FinanceRepository $repo)
    {
    }

    /**
     * @return array<string, array{code: string, group: string, group_label: string, label: string, ref: string, type_id: ?int}>
     *   ref : kod w kalkulatorze (kategoria kosztu stałego, kod medium, kod logistyki), type_id : typ medium / logistyki
     */
    public function all(): array
    {
        $out = [];
        foreach (CostMonthService::FIXED_COST_CATEGORIES as $code => $label) {
            if (in_array($code, self::FIXED_EXCLUDED, true)) continue;
            $out['fixed:' . $code] = ['code' => 'fixed:' . $code, 'group' => 'fixed', 'group_label' => self::GROUPS['fixed'], 'label' => $label, 'ref' => $code, 'type_id' => null];
        }
        foreach ($this->repo->mediaTypes() as $t) {
            $out['media:' . $t['code']] = ['code' => 'media:' . $t['code'], 'group' => 'media', 'group_label' => self::GROUPS['media'], 'label' => $t['name'], 'ref' => $t['code'], 'type_id' => (int) $t['id']];
        }
        foreach ($this->repo->logisticsTypes() as $t) {
            $out['log:' . $t['code']] = ['code' => 'log:' . $t['code'], 'group' => 'log', 'group_label' => self::GROUPS['log'], 'label' => $t['name'], 'ref' => $t['code'], 'type_id' => (int) $t['id']];
        }
        return $out;
    }

    /** Lista do wyboru (grupy w kolejności : koszty stałe, media, logistyka). */
    public static function present(array $all): array
    {
        return array_values(array_map(fn($c) => ['code' => $c['code'], 'group' => $c['group'], 'group_label' => $c['group_label'], 'label' => $c['label']], $all));
    }

    /**
     * Podpowiedź : faktura wygląda na usługę (kategoria i powód) albo null. Linie przypisane do surowców = zakup surowców.
     * @param array<string, mixed> $all kategorie (all())
     * @return ?array{code: string, label: string, reason: string}
     */
    public static function guess(array $inv, array $lines, array $all): ?array
    {
        foreach ($lines as $l) if (($l['id_raw_material'] ?? null) !== null) return null;
        $seller = self::fold((string) ($inv['seller_name'] ?? ''));
        foreach (self::GUESS as [$code, $sellerRe, $lineRe]) {
            if ($sellerRe !== null && $seller !== '' && isset($all[$code]) && preg_match($sellerRe, $seller)) {
                return ['code' => $code, 'label' => $all[$code]['label'], 'reason' => 'seller'];
            }
        }
        if (!$lines) return null;
        $total = 0.0;
        $byCode = [];
        foreach ($lines as $l) {
            $value = abs((float) ($l['net_value'] ?? ((float) ($l['unit_price'] ?? 0) * (float) ($l['quantity'] ?? 0))));
            $weight = $value > 0 ? $value : 1.0;
            $total += $weight;
            $name = self::fold((string) ($l['name'] ?? ''));
            foreach (self::GUESS as [$code, , $lineRe]) {
                if (isset($all[$code]) && preg_match($lineRe, $name)) { $byCode[$code] = ($byCode[$code] ?? 0) + $weight; break; }
            }
        }
        if (!$byCode || $total <= 0) return null;
        arsort($byCode);
        $code = array_key_first($byCode);
        return $byCode[$code] / $total >= self::LINE_SHARE ? ['code' => $code, 'label' => $all[$code]['label'], 'reason' => 'lines'] : null;
    }

    /** Małe litery bez polskich znaków (dopasowanie słów kluczowych). */
    public static function fold(string $s): string
    {
        return strtr(mb_strtolower($s), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    }
}
