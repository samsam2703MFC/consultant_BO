<?php
declare(strict_types=1);

namespace App\Services\Material\Nutrition;

use App\Exceptions\CustomException;

/**
 * Open Food Facts (baza otwarta, bez klucza) : taksonomia kategorii (nazwy pl / fr / en) i produkty kategorii
 * z wartościami odżywczymi na 100 g. Tylko odczyt ; limit OFF : 10 wyszukiwań na minutę (pilnuje przeglądarka).
 * Klasa nie jest final : testy podstawiają odpowiedzi bez sieci.
 */
class OffClient
{
    public const TAXONOMY_URL = 'https://static.openfoodfacts.org/data/taxonomies/categories.json';
    public const SEARCH_URL = 'https://world.openfoodfacts.org/api/v2/search';
    public const LANGS = ['pl', 'fr', 'en'];
    private const USER_AGENT = 'TFB-ERP-Atelier/1.0 (production module)';

    /** @return list<array{tag: string, lang: string, name: string}> nazwy kategorii w językach LANGS */
    public function categories(): array
    {
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 256) @ini_set('memory_limit', '256M');
        [$code, $raw] = $this->get(self::TAXONOMY_URL, 90);
        $data = $code === 200 ? json_decode($raw, true) : null;
        unset($raw);
        if (!is_array($data) || !$data) throw new CustomException('Open Food Facts : nie udało się pobrać kategorii (HTTP ' . $code . ').', 'errors', 502);
        $out = [];
        foreach ($data as $tag => $c) {
            foreach (self::LANGS as $lang) {
                $name = trim((string) ($c['name'][$lang] ?? ''));
                if ($name !== '' && strlen((string) $tag) <= 191) $out[] = ['tag' => (string) $tag, 'lang' => $lang, 'name' => mb_substr($name, 0, 255)];
            }
        }
        return $out;
    }

    /** @return array{count: int, products: list<array>} produkty kategorii (najczęściej skanowane), z polem nutriments */
    public function products(string $tag, int $size = 50): array
    {
        $url = self::SEARCH_URL . '?' . http_build_query(['categories_tags' => $tag, 'fields' => 'code,product_name,nutriments', 'page_size' => $size, 'sort_by' => 'unique_scans_n']);
        [$code, $raw] = $this->get($url, 25);
        $data = json_decode($raw, true);
        if ($code === 429) throw new CustomException('Open Food Facts : za dużo zapytań — spróbuj za minutę.', 'errors', 429);
        if ($code !== 200 || !is_array($data)) throw new CustomException('Open Food Facts niedostępne (HTTP ' . $code . ').', 'errors', 502);
        return ['count' => (int) ($data['count'] ?? 0), 'products' => array_values(array_filter($data['products'] ?? [], 'is_array'))];
    }

    /** @return array{0: int, 1: string} */
    protected function get(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . self::USER_AGENT]]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new CustomException('Open Food Facts niedostępne : ' . mb_substr($err, 0, 160) . '.', 'errors', 502);
        return [$code, (string) $raw];
    }
}
