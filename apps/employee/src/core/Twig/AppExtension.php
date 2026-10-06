<?php
namespace App\Employee\core\Twig;

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
            // Trigonométrie pour les jauges dessinées en SVG (écran Mes primes).
            new TwigFunction('cos', static fn (float $a): float => cos($a)),
            new TwigFunction('sin', static fn (float $a): float => sin($a)),
        ];
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