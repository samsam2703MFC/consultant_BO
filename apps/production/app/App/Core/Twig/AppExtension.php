<?php
namespace App\Core\Twig;

use App\Core\Helpers\GlobalRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    private $old;

    public function __construct(array $old)
    {
        $this->old = $old;

    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('old', [ $this, 'getOld' ]),
            // Wersja zasobów (?v=) : JS, CSS i etykiety mają długą pamięć w przeglądarce (stale-while-revalidate),
            // nowy adres po każdym wdrożeniu — inaczej stary skrypt spotyka nowe etykiety (klucze zamiast tekstów).
            new TwigFunction('asset_v', [ self::class, 'assetVersion' ]),
            // Zakładki grupy stron (BDO, PPWR, zwroty, archiwum) nad nagłówkiem ; lista grup dla railu i wyszukiwarki.
            new TwigFunction('page_tabs_html', fn() => \App\Core\Web\PageTabs::html(ROOT), ['is_safe' => ['html']]),
            new TwigFunction('page_tab_groups', fn() => \App\Core\Web\PageTabs::GROUPS),
            new TwigFunction('can', function(string $perm, array $perms = null) {
                $perms = $perms ?? (GlobalRegistry::get('user')['permissions'] ?? []);
                return in_array($perm, $perms, true);
            }),
            new TwigFunction('canAny', function(array $required, array $perms = null) {
                $perms = $perms ?? (GlobalRegistry::get('user')['permissions'] ?? []);
                return count(array_intersect($required, $perms)) > 0;
            }),
        ];
    }

    /** Najnowsza data zmiany skryptów modułu, arkuszy stylów i etykiet (raz na żądanie). */
    public static function assetVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $public = dirname(__DIR__, 3) . '/public';
            $files = array_merge(glob($public . '/assets/js/workshop/*.js') ?: [], glob($public . '/assets/css/*.css') ?: [], glob($public . '/resources/lang/ui/*/*.json') ?: []);
            $version = (string) max(array_map(fn($f) => (int) @filemtime($f), $files ?: [__FILE__]));
        }
        return $version;
    }

    public function getFilters(): array
    {
        return [
            new \Twig\TwigFilter('currency', [ $this, 'formatCurrency' ]),
            new \Twig\TwigFilter('plural_pl', [ $this, 'pluralPl' ]),
            new \Twig\TwigFilter('day_pl', 'day_label_pl'),
            new \Twig\TwigFilter('eur', [ $this, 'formatEur' ]),
            new \Twig\TwigFilter('pct', [ $this, 'formatPct' ]),
            new \Twig\TwigFilter('pln', [ $this, 'formatPln' ]),
        ];
    }

    /**
     * Format number as currency (PLN)
     * @param float $amount
     * @param int $decimals
     * @return string
     */
    public function formatCurrency($amount, int $decimals = 2): string
    {
        return number_format((float)$amount, $decimals, ',', ' ') . ' zł';
    }

    /** Kwota w euro bez groszy: 12 345 € (null → —). */
    public function formatEur($amount, int $decimals = 0): string
    {
        return $amount === null ? '—' : number_format((float) $amount, $decimals, ',', ' ') . ' €';
    }

    /** Kwota w złotych bez groszy: 4 806 zł (null → —). */
    public function formatPln($amount, int $decimals = 0): string
    {
        return $amount === null ? '—' : number_format((float) $amount, $decimals, ',', ' ') . ' zł';
    }

    /** Procent z jednym miejscem po przecinku: 49,1 % (null → —). */
    public function formatPct($value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format((float) $value, $decimals, ',', ' ') . ' %';
    }

    /**
     * Liczba z polską odmianą: {{ n|plural_pl('zamówienie', 'zamówienia', 'zamówień') }}
     */
    public function pluralPl($count, string $one, string $few, string $many): string
    {
        $n = abs((int) $count);
        $word = $n === 1 ? $one
            : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many);
        return number_format((int) $count, 0, ',', ' ') . ' ' . $word;
    }

    public function getOld(string $key, $default = '')
    {
        return $this->old[$key] ?? $default;
    }
    public function getGlobals(): array
    {
        return ['old' => $this->old];
    }
}