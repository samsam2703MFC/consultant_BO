<?php
namespace App\Employee\core\Twig;

use App\Employee\core\Support\GlobalRegistry;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension implements GlobalsInterface
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
            // Le nom d'un mois « AAAA-MM » dans la langue de la personne : « octobre 2026 », ou « oct. » en court.
            new TwigFunction('mois', [ $this, 'mois' ]),
        ];
    }

    public function getOld(string $key, $default = '')
    {
        return $this->old[$key] ?? $default;
    }
    public function getGlobals(): array
    {
        return ['old' => $this->old, 'langue' => $this->langue()];
    }

    private function langue(): string
    {
        $l = class_exists(GlobalRegistry::class) ? GlobalRegistry::get('lang_code') : null;
        $l = is_string($l) ? strtolower(substr($l, 0, 2)) : 'en';
        return in_array($l, ['fr', 'en', 'nl', 'it', 'pl'], true) ? $l : 'en';
    }

    private const MOIS = [
        'fr' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'nl' => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'],
        'it' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
        'pl' => ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'],
    ];

    /** « 2026-10 » → « octobre 2026 » (ou « oct. » en court), dans la langue de la personne ; tel quel si ce n'est pas un mois. */
    public function mois(?string $m, bool $court = false): string
    {
        if (!is_string($m) || !preg_match('/^(\d{4})-(\d{2})/', $m, $x)) { return (string) $m; }
        $n = (int) $x[2];
        if ($n < 1 || $n > 12) { return $m; }
        $nom = self::MOIS[$this->langue()][$n - 1];
        if ($court) { return mb_strlen($nom) > 4 ? mb_substr($nom, 0, 3) . '.' : $nom; }
        return $nom . ' ' . $x[1];
    }
}