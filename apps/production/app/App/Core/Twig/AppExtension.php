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

    public function getFilters(): array
    {
        return [
            new \Twig\TwigFilter('currency', [ $this, 'formatCurrency' ]),
            new \Twig\TwigFilter('plural_pl', [ $this, 'pluralPl' ]),
            new \Twig\TwigFilter('day_pl', 'day_label_pl'),
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