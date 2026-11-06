<?php
declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Szybkie rozpoznanie języka wiadomości bez DeepL (słowa częste + litery charakterystyczne) : Poczta podpowiada
 * « Wiadomość po francusku — Przetłumacz » i język odpowiedzi. Bez pewności → null.
 */
final class LanguageGuess
{
    private const WORDS = [
        'pl' => ['i', 'w', 'z', 'na', 'się', 'nie', 'do', 'że', 'jest', 'to', 'dla', 'od', 'po', 'czy', 'jak', 'prosimy', 'proszę', 'dzień', 'dobry',
            'pozdrawiam', 'zamówienie', 'faktura', 'dziękuję', 'oraz', 'jako', 'tak', 'może', 'będzie', 'są', 'przez', 'mamy', 'jutro'],
        'fr' => ['le', 'la', 'les', 'des', 'du', 'et', 'est', 'un', 'une', 'pour', 'que', 'qui', 'dans', 'pas', 'nous', 'vous', 'avec', 'sur', 'au',
            'aux', 'bonjour', 'merci', 'cordialement', 'commande', 'facture', 'votre', 'notre', 'ce', 'cette', 'il', 'demain', 'livraison'],
        'nl' => ['het', 'een', 'van', 'dat', 'niet', 'op', 'te', 'voor', 'met', 'zijn', 'wij', 'ik', 'bij', 'ook', 'graag', 'dank', 'groeten',
            'bestelling', 'factuur', 'uw', 'onze', 'goedemorgen', 'alstublieft', 'hebben', 'kunnen', 'morgen', 'levering', 'wordt', 'deze', 'naar'],
        'en' => ['the', 'and', 'of', 'to', 'is', 'for', 'that', 'you', 'we', 'with', 'are', 'this', 'be', 'please', 'thank', 'thanks', 'regards',
            'order', 'invoice', 'your', 'our', 'will', 'have', 'can', 'would', 'kind', 'dear', 'hello', 'from', 'delivery', 'tomorrow'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'für', 'sie', 'wir', 'den', 'dem', 'ein', 'eine', 'zu', 'von', 'auf', 'bitte',
            'danke', 'grüße', 'bestellung', 'rechnung', 'ihre', 'unsere', 'guten', 'tag', 'haben', 'können', 'auch', 'sehr', 'lieferung', 'morgen'],
    ];

    public static function guess(?string $text): ?string
    {
        // bez cytowanych linii (« > ») : odpowiedź klienta, nie nasz tekst
        $lines = array_filter(preg_split('/\R/u', (string) $text) ?: [], fn($l) => !str_starts_with(ltrim($l), '>'));
        $t = mb_strtolower(mb_substr(implode(' ', $lines), 0, 4000));
        $tokens = preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 4) return null;
        $score = [];
        foreach (self::WORDS as $lang => $words) {
            $set = array_flip($words);
            $score[$lang] = 0;
            foreach ($tokens as $w) if (isset($set[$w])) $score[$lang]++;
        }
        $score['pl'] += min(20, preg_match_all('/[ąęłńśźżć]/u', $t));
        $score['fr'] += min(20, preg_match_all('/[éèêàçùâîôûœ]/u', $t));
        $score['de'] += min(20, preg_match_all('/[äöüß]/u', $t));
        arsort($score);
        $top = array_slice($score, 0, 2, true);
        [$best, $second] = array_values($top);
        if ($best < 3 || $best < 1.5 * $second) return null;
        return (string) array_key_first($top);
    }
}
