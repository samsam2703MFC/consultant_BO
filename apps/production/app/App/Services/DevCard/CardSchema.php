<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use App\Exceptions\CustomException;

/**
 * Treść karty technologicznej [196] : jeden format dla odpowiedzi asystenta AI, zaimportowanej karty .docx i poprawek
 * w kreatorze — tytuł, oryginalna nazwa, autor, wydajność, pieczenie, czas, składniki w podprzepisach (ilość + nazwa),
 * kroki, zdjęcia etapów (klatka + podpis), uwagi. Tekst zawsze przycięty i ograniczony długością.
 */
final class CardSchema
{
    public const MAX_PHOTOS = 12;
    public const FOOTER = 'Zdjęcia etapów pochodzą z filmu źródłowego. Gramatury do zweryfikowania w próbie przed wdrożeniem do produkcji.';

    /** Schemat odpowiedzi asystenta AI (structured outputs : JSON zgodny ze schematem). */
    public static function aiSchema(): array
    {
        $str = fn(string $d) => ['type' => 'string', 'description' => $d];
        return [
            'type' => 'object',
            'properties' => [
                'title' => $str('Polska nazwa wyrobu (tytuł karty)'),
                'original_name' => $str('Oryginalna nazwa w języku źródła albo pusty tekst'),
                'author' => $str('Autor : nazwa strony / konta albo pusty tekst'),
                'source_language' => $str('Kod języka opisu, np. tr, en, fr, pl'),
                'yield' => $str('WYDAJNOŚĆ : ile sztuk / jaka forma'),
                'baking' => $str('PIECZENIE : temperatura °C, tryb, czas, nagrzany czy nie'),
                'time' => $str('CZAS : wyrabianie, rozrosty, pieczenie'),
                'ingredients' => ['type' => 'array', 'description' => 'Składniki według podprzepisów', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'group' => $str('Nazwa podprzepisu WIELKIMI LITERAMI, np. CIASTO, NADZIENIE'),
                        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'qty' => $str('Ilość z jednostką, np. 500 g, 2 łyżki, 1 ; pusty tekst gdy brak'),
                            'name' => $str('Składnik, np. mąki pszennej'),
                        ], 'required' => ['qty', 'name'], 'additionalProperties' => false]],
                    ],
                    'required' => ['group', 'items'], 'additionalProperties' => false,
                ]],
                'steps' => ['type' => 'array', 'description' => 'Kroki w kolejności produkcyjnej, jeden krok = jedna czynność', 'items' => ['type' => 'string']],
                'photos' => ['type' => 'array', 'description' => '6–9 klatek kluczowych etapów w kolejności filmu', 'items' => [
                    'type' => 'object', 'properties' => [
                        'frame' => ['type' => 'integer', 'description' => 'Numer klatki z wiadomości'],
                        'caption' => $str('Podpis po polsku, 2–4 słowa'),
                    ], 'required' => ['frame', 'caption'], 'additionalProperties' => false,
                ]],
                'notes' => ['type' => 'array', 'description' => 'Uwagi : braki, źródło pełnego przepisu, jakość', 'items' => ['type' => 'string']],
                'has_quantities' => ['type' => 'boolean', 'description' => 'Czy źródło podaje gramatury'],
            ],
            'required' => ['title', 'original_name', 'author', 'source_language', 'yield', 'baking', 'time', 'ingredients', 'steps', 'photos', 'notes', 'has_quantities'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Odpowiedź asystenta → karta : numery klatek z wiadomości zamienione na id klatek (nieznane i powtórzone pominięte).
     * @param array<int,int> $frameIds numer klatki (1…) → id w n_dev_card_frame
     */
    public static function fromAi(array $ai, array $frameIds): array
    {
        $photos = [];
        $seen = [];
        foreach ((array) ($ai['photos'] ?? []) as $p) {
            $no = (int) ($p['frame'] ?? 0);
            if (!isset($frameIds[$no]) || isset($seen[$no])) continue;
            $seen[$no] = true;
            $photos[] = ['frame_id' => $frameIds[$no], 'caption' => (string) ($p['caption'] ?? '')];
        }
        $card = self::normalize(['title' => $ai['title'] ?? '', 'original_name' => $ai['original_name'] ?? '', 'author' => $ai['author'] ?? '',
            'source_language' => $ai['source_language'] ?? '', 'yield' => $ai['yield'] ?? '', 'baking' => $ai['baking'] ?? '', 'time' => $ai['time'] ?? '',
            'ingredients' => $ai['ingredients'] ?? [], 'steps' => $ai['steps'] ?? [], 'photos' => $photos, 'notes' => $ai['notes'] ?? []]);
        if (empty($ai['has_quantities']) && !preg_grep('~gramatur~iu', $card['notes'])) $card['notes'][] = 'Brak gramatur w źródle.';
        return $card;
    }

    /** Karta z poprawionymi / dowolnymi danymi : typy, długości, puste pozycje usunięte. */
    public static function normalize(array $c): array
    {
        $s = fn($v, int $max) => mb_substr(trim(preg_replace('~[ \t]+~u', ' ', str_replace(["\r\n", "\r"], "\n", (string) $v))), 0, $max);
        // Nagłówek (wydajność, pieczenie, czas) : za długi tekst ucięty na ostatnim słowie, z « … ».
        $head = function ($v, int $max) use ($s): string {
            $full = $s($v, 10000);
            if (mb_strlen($full) <= $max) return $full;
            $cut = mb_substr($full, 0, $max - 1);
            $sp = mb_strrpos($cut, ' ');
            return rtrim($sp !== false && $sp > $max / 2 ? mb_substr($cut, 0, $sp) : $cut, ' ,;:–-') . '…';
        };
        $groups = [];
        foreach (array_slice((array) ($c['ingredients'] ?? []), 0, 12) as $g) {
            if (!is_array($g)) continue;
            $items = [];
            foreach (array_slice((array) ($g['items'] ?? []), 0, 40) as $it) {
                if (is_string($it)) $it = self::splitQty($it);
                $qty = $s($it['qty'] ?? '', 60);
                $name = $s($it['name'] ?? '', 200);
                if ($qty !== '' || $name !== '') $items[] = ['qty' => $qty, 'name' => $name];
            }
            $name = mb_strtoupper($s($g['group'] ?? '', 80));
            if ($items) $groups[] = ['group' => $name, 'items' => $items];
        }
        $steps = array_values(array_filter(array_map(fn($x) => $s(is_array($x) ? ($x['text'] ?? '') : $x, 600), array_slice((array) ($c['steps'] ?? []), 0, 30)), fn($x) => $x !== ''));
        $photos = [];
        foreach (array_slice((array) ($c['photos'] ?? []), 0, self::MAX_PHOTOS) as $p) {
            $id = (int) ($p['frame_id'] ?? 0);
            if ($id > 0) $photos[] = ['frame_id' => $id, 'caption' => $s($p['caption'] ?? '', 60)];
        }
        $notes = array_values(array_filter(array_map(fn($x) => $s($x, 300), array_slice((array) ($c['notes'] ?? []), 0, 10)), fn($x) => $x !== ''));
        return ['v' => 1, 'title' => $s($c['title'] ?? '', 160), 'original_name' => $s($c['original_name'] ?? '', 160), 'author' => $s($c['author'] ?? '', 120),
            'source_language' => $s($c['source_language'] ?? '', 8), 'yield' => $head($c['yield'] ?? '', 160), 'baking' => $head($c['baking'] ?? '', 160),
            'time' => $head($c['time'] ?? '', 200), 'ingredients' => $groups, 'steps' => $steps, 'photos' => $photos, 'notes' => $notes];
    }

    /** Karta gotowa do zapisu : tytuł, składniki i kroki. */
    public static function assertComplete(array $card): void
    {
        $f = [];
        if ($card['title'] === '') $f['title'] = 'Podaj tytuł karty.';
        if (!$card['ingredients']) $f['ingredients'] = 'Dodaj składniki.';
        if (!$card['steps']) $f['steps'] = 'Dodaj kroki.';
        if ($f) throw new CustomException('Karta jest niepełna : ' . implode(' ', $f), 'errors', 422, ['fields' => $f]);
    }

    /** « 500 g mąki » → qty « 500 g », name « mąki » ; bez ilości → cała linia jako nazwa. @return array{qty:string, name:string} */
    public static function splitQty(string $line): array
    {
        $line = trim(preg_replace('~^[•●\-–*]\s*~u', '', trim($line)));
        $unit = '(?:kg|g|dag|mg|ml|l|dl|cl|szt\.?|łyżk[aię]|łyżek|łyżeczk[aię]|łyżeczek|ząb(?:ek|ki|ków)|szczypt[aęy]|opak\.?|plastr(?:y|ów)?|płat(?:y|ów)?|kostk[aię]|garść|szklank[aię]|%)';
        if (preg_match('~^((?:ok\.\s*|≈\s*)?[\d½¼¾⅓⅔⅛]+(?:[.,/]\d+)?(?:\s*[½¼¾⅓⅔])?(?:\s*[–\-]\s*[\d½¼¾⅓⅔]+(?:[.,]\d+)?)?(?:\s*' . $unit . '(?=\s|$))?)\s+(.+)$~u', $line, $m)) {
            return ['qty' => trim($m[1]), 'name' => trim($m[2])];
        }
        return ['qty' => '', 'name' => $line];
    }

    /** Składniki ↔ pole tekstowe w kreatorze : « CIASTO: » nagłówek, potem linie « 500 g mąki ». */
    public static function ingredientsToText(array $groups): string
    {
        $out = [];
        foreach ($groups as $g) {
            if ($g['group'] !== '') $out[] = $g['group'] . ':';
            foreach ($g['items'] as $it) $out[] = trim($it['qty'] . ' ' . $it['name']);
            $out[] = '';
        }
        return trim(implode("\n", $out));
    }

    public static function ingredientsFromText(string $text): array
    {
        $groups = [];
        $cur = null;
        foreach (preg_split('~\R~u', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('~^(.{1,80}):$~u', $line, $m) || (mb_strtoupper($line) === $line && !preg_match('~\d~', $line) && mb_strlen($line) <= 40 && preg_match('~\p{L}~u', $line))) {
                if ($cur !== null) $groups[] = $cur;
                $cur = ['group' => mb_strtoupper(rtrim($m[1] ?? $line, ':')), 'items' => []];
                continue;
            }
            $cur ??= ['group' => '', 'items' => []];
            $cur['items'][] = self::splitQty($line);
        }
        if ($cur !== null) $groups[] = $cur;
        return $groups;
    }

    /** Kroki z pola tekstowego : jedna linia = jeden krok, numeracja « 1. » usunięta. */
    public static function stepsFromText(string $text): array
    {
        return array_values(array_filter(array_map(fn($l) => trim(preg_replace('~^\s*\d+[.)]\s*~u', '', $l)), preg_split('~\R~u', $text)), fn($l) => $l !== ''));
    }

    /** NN_Nazwa_przepisu.docx (ASCII, najwyżej 6 słów). */
    public static function fileName(int $no, string $title, string $ext): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', 'ş' => 's', 'ğ' => 'g', 'ı' => 'i',
            'ç' => 'c', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ß' => 'ss'];
        $t = strtr(mb_strtolower($title), $map);
        $t = (string) preg_replace('~[^a-z0-9]+~', ' ', $t);
        $words = array_slice(array_values(array_filter(explode(' ', $t), fn($w) => $w !== '')), 0, 6);
        while (count($words) > 1 && strlen(end($words)) <= 2) array_pop($words); // bez « z », « i », « w » na końcu
        $slug = $words ? implode('_', $words) : 'karta';
        return sprintf('%02d_%s.%s', max(1, $no), ucfirst($slug), $ext);
    }

    /** Prognoza : sztuk na tydzień × 13 w kwartałach sezonu (NULL / pusty = cały rok). @return array{weekly:?int, quarters:list<int>, per_quarter:array<int,int>, year:int, seasonal:bool} */
    public static function forecast(?int $weekly, ?string $quarters): array
    {
        $q = self::quarters($quarters);
        $per = [];
        foreach ([1, 2, 3, 4] as $i) $per[$i] = $weekly !== null && in_array($i, $q, true) ? $weekly * 13 : 0;
        return ['weekly' => $weekly, 'quarters' => $q, 'per_quarter' => $per, 'year' => array_sum($per), 'seasonal' => count($q) < 4];
    }

    /** « 1,4 » → [1, 4] ; NULL / pusty → [1, 2, 3, 4]. */
    public static function quarters(?string $value): array
    {
        $q = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $value)), fn($x) => $x >= 1 && $x <= 4)));
        sort($q);
        return $q ?: [1, 2, 3, 4];
    }
}
