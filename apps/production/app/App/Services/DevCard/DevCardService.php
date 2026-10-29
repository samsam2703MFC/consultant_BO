<?php
declare(strict_types=1);

namespace App\Services\DevCard;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\DevCardRepository;
use App\Repositories\Workshop\DevProductRepository;
use App\Services\Utils\DataUrlImage;

/**
 * Nowy produkt z filmu [196] — kreator : 1) link Facebook / Pinterest (albo opis wklejony, plik wideo, gotowa karta .docx),
 * nazwa, grupa, sprzedaż ± na tydzień, sezon (kwartały) ; 2) odczyt linku (opis, autor, okładka, adres wideo) ; 3) klatki
 * wycięte w przeglądarce z wideo (pobranego przez serwer z Facebooka / Pinteresta albo z pliku operatora) ; 4) asystent
 * AI (klucz Anthropic z Ustawień) → karta technologiczna ; 5) poprawki tekstu i zdjęć ; 6) zapis : produkt w rozwoju
 * (etap Pomysł, tablet wybranej grupy, prognoza), karta .docx / PDF i widok na tablecie.
 */
final class DevCardService
{
    public const MAX_FRAMES = 60;
    public const MAX_FRAME_BYTES = 1_500_000;
    public const MAX_TOTAL_FRAME_BYTES = 45_000_000;
    public const MAX_WEEKLY = 1_000_000;

    public function __construct(
        private DevCardRepository $cards,
        private DevProductRepository $products,
        private SourceReader $reader,
        private CardGenerator $generator,
        private AiSettings $ai,
        private TransactionManager $tx,
    ) {
    }

    // --- Kreator -------------------------------------------------------------------------------------

    /** Strona kreatora : grupy, szkice do dokończenia, stan asystenta AI. */
    public function page(?int $userId, ?int $cardId = null): array
    {
        return ['groups' => $this->cards->groups(), 'drafts' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'status' => $c['status'],
            'updated_at' => $c['updated_at']], $this->cards->drafts()), 'ai' => $this->ai->state($userId), 'card' => $cardId ? $this->state($cardId) : null];
    }

    /** Krok 1 : szkic — źródło, nazwa, grupa, prognoza. Link sprawdzony (Facebook / Pinterest) ; bez linku : opis albo plik. */
    public function create(array $in, ?int $userId): array
    {
        $fields = $this->fields($in);
        $url = trim((string) ($in['url'] ?? ''));
        $text = trim((string) ($in['text'] ?? ''));
        $kind = 'text';
        if ($url !== '') {
            $d = SourceReader::detect($url);
            [$kind, $url] = [$d['kind'], $d['url']];
        } elseif (!empty($in['file'])) {
            $kind = 'file';
        } elseif ($text === '') {
            throw new CustomException('Wklej link do filmu albo opis przepisu (albo dodaj plik wideo).', 'errors', 422, ['fields' => ['url' => 'Link, opis albo plik.']]);
        }
        if (mb_strlen($text) > 20000) throw new CustomException('Opis : najwyżej 20 000 znaków.', 'errors', 422, ['fields' => ['text' => 'Za długi opis.']]);
        $this->cards->purgeOldDrafts();
        $id = $this->cards->create($fields + ['status' => 'draft', 'source_kind' => $kind, 'source_url' => $url !== '' ? $url : null,
            'source_text' => $text !== '' ? $text : null, 'created_by' => $userId]);
        return $this->state($id);
    }

    /** Nazwa, grupa, sprzedaż ± na tydzień, sezon (kwartały) — wspólne dla szkicu i poprawek. */
    private function fields(array $in): array
    {
        $f = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) $f['name'] = 'Nazwa produktu : od 1 do 255 znaków.';
        $group = filter_var($in['id_group'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($group === false || !in_array($group, array_map(fn($g) => (int) $g['id'], $this->cards->groups()), true)) $f['id_group'] = 'Wybierz grupę.';
        $weekly = filter_var($in['weekly_sales'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_WEEKLY]]);
        if ($weekly === false) $f['weekly_sales'] = 'Sprzedaż na tydzień : liczba sztuk od 0.';
        $seasonal = filter_var($in['seasonal'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $q = array_values(array_unique(array_filter(array_map('intval', (array) ($in['quarters'] ?? [])), fn($x) => $x >= 1 && $x <= 4)));
        sort($q);
        if ($seasonal && !$q) $f['quarters'] = 'Produkt sezonowy : zaznacz co najmniej jeden kwartał.';
        if ($f) throw new CustomException('Popraw zaznaczone pola.', 'errors', 422, ['fields' => $f]);
        return ['name' => $name, 'id_group' => $group, 'weekly_sales' => $weekly, 'season_quarters' => $seasonal && count($q) < 4 ? implode(',', $q) : null];
    }

    /** Krok 2 : odczyt linku (opis, autor, okładka, adres wideo) ; ściana logowania = uwaga, nie błąd. */
    public function readLink(int $id): array
    {
        $c = $this->card($id);
        if (!in_array($c['source_kind'], ['facebook', 'pinterest'], true) || !$c['source_url']) return $this->state($id);
        $r = $this->reader->read((string) $c['source_url']);
        $text = trim((string) $c['source_text']) !== '' ? $c['source_text'] : $r['text']; // opis wklejony przez operatora ma pierwszeństwo
        $this->cards->save($id, ['source_url' => $r['url'], 'source_author' => $r['author'], 'source_title' => $r['title'], 'source_text' => $text,
            'video_url' => $r['video'], 'source_info' => json_encode(['login_wall' => $r['login_wall'], 'has_text' => $r['text'] !== null, 'has_video' => $r['video'] !== null,
                'source_link' => $r['source_link'], 'notes' => $r['notes'], 'read_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)]);
        if ($img = $this->reader->image($r['image'])) {
            $this->cards->deleteFrames($id, 'cover');
            $size = @getimagesizefromstring($img['bytes']);
            $this->cards->addFrame($id, 0, null, 'cover', $img['mime'], $img['bytes'], is_array($size) ? (int) $size[0] : null, is_array($size) ? (int) $size[1] : null);
        }
        return $this->state($id);
    }

    /** Krok 3a : plik wideo z Facebooka / Pinteresta do przeglądarki (wycina klatki) — pobrany przez serwer, potem usunięty. */
    public function streamVideo(int $id): void
    {
        $c = $this->card($id);
        if (!$c['video_url']) throw new CustomException('Brak pliku wideo dla tego linku — dodaj plik .mp4 albo generuj z okładki.', 'errors', 404);
        $tmp = tempnam(sys_get_temp_dir(), 'devcard');
        try {
            @set_time_limit(300);
            $bytes = $this->reader->downloadVideo((string) $c['video_url'], $tmp);
            header('Content-Type: video/mp4');
            header('Content-Length: ' . $bytes);
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Krok 3b : klatki z przeglądarki (JPEG, partiami). « replace » w pierwszej partii usuwa poprzednie klatki filmu.
     * @param array{replace?:bool, frames:list<array{image:string, at_ms?:int}>} $in
     */
    public function addFrames(int $id, array $in): array
    {
        $this->card($id);
        if (filter_var($in['replace'] ?? false, FILTER_VALIDATE_BOOLEAN)) $this->cards->deleteFrames($id, 'frame');
        $frames = (array) ($in['frames'] ?? []);
        $existing = count(array_filter($this->cards->frames($id), fn($f) => $f['kind'] === 'frame'));
        if ($existing + count($frames) > self::MAX_FRAMES) throw new CustomException('Najwyżej ' . self::MAX_FRAMES . ' klatek na film.', 'errors', 422);
        $total = $this->cards->frameBytes($id);
        foreach ($frames as $f) {
            $img = DataUrlImage::parse((string) ($f['image'] ?? ''), self::MAX_FRAME_BYTES);
            $total += strlen($img['bytes']);
            if ($total > self::MAX_TOTAL_FRAME_BYTES) throw new CustomException('Za dużo danych z filmu — krótszy film albo mniej klatek.', 'errors', 413);
            $size = @getimagesizefromstring($img['bytes']);
            $at = filter_var($f['at_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 36_000_000]]);
            $this->cards->addFrame($id, ++$existing, $at === false ? null : $at, 'frame', $img['mime'], $img['bytes'], is_array($size) ? (int) $size[0] : null, is_array($size) ? (int) $size[1] : null);
        }
        return $this->state($id);
    }

    /** Krok 4 : asystent AI → karta (zdjęcia = wybrane klatki). Wymaga klucza Anthropic. */
    public function generate(int $id): array
    {
        $c = $this->card($id);
        if (!$this->ai->configured()) throw new CustomException('Asystent AI nie jest włączony : administrator dodaje klucz Anthropic w Ustawieniach › Asystent AI.', 'errors', 409);
        $frames = $this->cards->framesWithImage($id);
        if (trim((string) $c['source_text']) === '' && !$frames) {
            throw new CustomException('Brak opisu i klatek : wklej przepis ręcznie albo dodaj plik wideo.', 'errors', 422, ['fields' => ['text' => 'Wklej opis przepisu.']]);
        }
        $numbered = [];
        $map = [];
        foreach (array_slice($frames, 0, CardGenerator::MAX_FRAMES) as $i => $f) {
            $numbered[] = ['no' => $i + 1, 'at_ms' => $f['at_ms'] !== null ? (int) $f['at_ms'] : null, 'kind' => $f['kind'], 'mime' => $f['mime'], 'image' => $f['image']];
            $map[$i + 1] = (int) $f['id'];
        }
        @set_time_limit(300);
        $r = $this->generator->generate(['name' => $c['name'], 'group' => $c['group_pl'], 'kind' => $c['source_kind'], 'url' => $c['source_url'],
            'author' => $c['source_author'], 'title' => $c['source_title'], 'text' => $c['source_text']], $numbered);
        $card = CardSchema::fromAi($r['card'], $map);
        $info = json_decode((string) $c['source_info'], true) ?: [];
        foreach ((array) ($info['notes'] ?? []) as $n) if (!in_array($n, $card['notes'], true)) $card['notes'][] = $n;
        $card = CardSchema::normalize($card);
        $this->cards->save($id, ['card_json' => json_encode($card, JSON_UNESCAPED_UNICODE), 'status' => $c['status'] === 'saved' ? 'saved' : 'ready',
            'model' => $r['model'], 'id_prompt' => $r['prompt_id'], 'usage_json' => json_encode($r['usage']), 'generated_at' => date('Y-m-d H:i:s'), 'generation_ms' => $r['ms']]);
        return $this->state($id);
    }

    /** Gotowa karta z pliku .docx (np. karty testowe) : bez asystenta AI. */
    public function importDocx(array $in, string $path, ?int $userId): array
    {
        $fields = $this->fields($in);
        $r = CardDocx::read($path);
        $id = $this->tx->execute(function () use ($fields, $r, $userId) {
            $id = $this->cards->create($fields + ['status' => 'draft', 'source_kind' => 'docx', 'source_author' => $r['card']['author'] ?: null, 'created_by' => $userId]);
            $photos = [];
            foreach ($r['images'] as $i => $img) {
                $size = @getimagesizefromstring($img['image']);
                $fid = $this->cards->addFrame($id, $i + 1, null, 'docx', $img['mime'], $img['image'], is_array($size) ? (int) $size[0] : null, is_array($size) ? (int) $size[1] : null);
                $photos[] = ['frame_id' => $fid, 'caption' => $img['caption']];
            }
            $card = CardSchema::normalize(['photos' => $photos] + $r['card']);
            $this->cards->save($id, ['card_json' => json_encode($card, JSON_UNESCAPED_UNICODE), 'status' => 'ready', 'generated_at' => date('Y-m-d H:i:s')]);
            return $id;
        });
        return $this->state($id);
    }

    /**
     * Krok 5 : poprawki — produkt (nazwa, grupa, prognoza) i karta (tekst, składniki w polu tekstowym, kroki, zdjęcia).
     * Karta zapisana : zmiany przechodzą też na produkt w rozwoju.
     */
    public function update(int $id, array $in, ?int $userId): array
    {
        $c = $this->card($id);
        $data = [];
        if (array_key_exists('name', $in)) $data = $this->fields($in);
        if (isset($in['card']) && is_array($in['card'])) {
            $cur = json_decode((string) $c['card_json'], true) ?: CardSchema::normalize([]);
            $e = $in['card'];
            if (isset($e['ingredients_text'])) $e['ingredients'] = CardSchema::ingredientsFromText((string) $e['ingredients_text']);
            if (isset($e['steps_text'])) $e['steps'] = CardSchema::stepsFromText((string) $e['steps_text']);
            if (isset($e['photos'])) {
                $valid = array_map(fn($f) => (int) $f['id'], $this->cards->frames($id));
                foreach ((array) $e['photos'] as $p) {
                    if (!in_array((int) ($p['frame_id'] ?? 0), $valid, true)) throw new CustomException('Nieznane zdjęcie etapu.', 'errors', 422);
                }
            }
            $card = CardSchema::normalize(array_intersect_key($e, array_flip(['title', 'original_name', 'author', 'yield', 'baking', 'time', 'ingredients', 'steps', 'photos', 'notes'])) + $cur);
            $data['card_json'] = json_encode($card, JSON_UNESCAPED_UNICODE);
            if ($c['status'] === 'draft') $data['status'] = 'ready';
        }
        if (!$data) return $this->state($id);
        $this->tx->execute(function () use ($id, $c, $data, $userId) {
            $this->cards->save($id, $data);
            if ($c['status'] === 'saved' && $c['id_test_product'] && isset($data['name'])) $this->syncProduct((int) $c['id_test_product'], $data, $userId);
        });
        return $this->state($id);
    }

    /** Krok 6 : produkt w rozwoju (etap Pomysł, tablet grupy, prognoza, link do filmu) + karta powiązana. */
    public function save(int $id, ?int $userId): array
    {
        $c = $this->card($id);
        if ($c['status'] === 'saved' && $c['id_test_product']) return $this->state($id) + ['url' => '/test-products/' . $c['id_test_product'] . '/tablet'];
        $card = json_decode((string) $c['card_json'], true);
        if (!is_array($card)) throw new CustomException('Najpierw wygeneruj kartę (albo wgraj gotową .docx).', 'errors', 422);
        CardSchema::assertComplete($card);
        $group = null;
        foreach ($this->cards->groups() as $g) if ((int) $g['id'] === (int) $c['id_group']) $group = $g;
        if ($group === null) throw new CustomException('Wybierz grupę.', 'errors', 422, ['fields' => ['id_group' => 'Wybierz grupę.']]);
        $productId = $this->tx->execute(function () use ($id, $c, $group, $card, $userId) {
            $pid = $this->products->createTestProduct((string) $c['name'], $group['id_section'] !== null ? (int) $group['id_section'] : null);
            $notes = 'Karta technologiczna z filmu' . ($card['author'] !== '' ? ' (wg ' . $card['author'] . ')' : '') . '. Gramatury do sprawdzenia w teście.';
            $this->products->saveDev($pid, ['on_tablet' => 1, 'id_group' => (int) $group['id'], 'stage' => 'idea', 'stage_changed_at' => date('Y-m-d H:i:s'),
                'notes' => $notes, 'video_url' => in_array($c['source_kind'], ['facebook', 'pinterest'], true) ? mb_substr((string) $c['source_url'], 0, 500) : null,
                'weekly_sales' => $c['weekly_sales'] !== null ? (int) $c['weekly_sales'] : null, 'season_quarters' => $c['season_quarters'], 'updated_by' => $userId]);
            $this->cards->save($id, ['id_test_product' => $pid, 'status' => 'saved', 'video_url' => null]);
            // Klatki niewybrane na kartę : niepotrzebne po zapisie (zostają zdjęcia etapów i okładka).
            $keep = array_column($card['photos'], 'frame_id');
            foreach ($this->cards->frames($id) as $f) {
                if ($f['kind'] === 'frame' && !in_array((int) $f['id'], $keep, true)) $this->cards->deleteFrame($id, (int) $f['id']);
            }
            return $pid;
        });
        return $this->state($id) + ['url' => '/test-products/' . $productId . '/tablet'];
    }

    public function delete(int $id): void
    {
        $c = $this->card($id);
        if ($c['status'] === 'saved') throw new CustomException('Karta jest już zapisana przy produkcie — usuń produkt w rozwoju.', 'errors', 409);
        $this->cards->deleteCard($id);
    }

    private function syncProduct(int $productId, array $data, ?int $userId): void
    {
        $this->products->renameTestProduct($productId, (string) $data['name']);
        $this->products->saveDev($productId, ['id_group' => $data['id_group'], 'weekly_sales' => $data['weekly_sales'], 'season_quarters' => $data['season_quarters'], 'updated_by' => $userId]);
    }

    // --- Pliki ---------------------------------------------------------------------------------------

    /** @return array{name:string, bytes:string} */
    public function docx(int $id): array
    {
        [$c, $card, $images] = $this->printable($id);
        return ['name' => CardSchema::fileName($c['status'] === 'saved' ? $this->cards->fileNo($id) : (int) $id, $card['title'] ?: $c['name'], 'docx'), 'bytes' => CardDocx::build($card, $images)];
    }

    /** @return array{name:string, bytes:string} */
    public function pdf(int $id): array
    {
        [$c, $card, $images] = $this->printable($id);
        return ['name' => CardSchema::fileName($c['status'] === 'saved' ? $this->cards->fileNo($id) : (int) $id, $card['title'] ?: $c['name'], 'pdf'), 'bytes' => CardPdf::build($card, $images)];
    }

    private function printable(int $id): array
    {
        $c = $this->card($id);
        $card = json_decode((string) $c['card_json'], true);
        if (!is_array($card)) throw new CustomException('Karta nie jest jeszcze wygenerowana.', 'errors', 404);
        $images = [];
        foreach ($this->cards->framesWithImage($id, array_column($card['photos'], 'frame_id')) as $f) $images[(int) $f['id']] = ['mime' => $f['mime'], 'image' => $f['image']];
        return [$c, $card, $images];
    }

    public function frame(int $id, int $frameId): ?array
    {
        return $this->cards->frame($id, $frameId);
    }

    // --- Widoki --------------------------------------------------------------------------------------

    /** Stan kreatora (bez obrazów : adresy klatek). */
    public function state(int $id): array
    {
        $c = $this->card($id);
        $info = json_decode((string) $c['source_info'], true) ?: null;
        $card = json_decode((string) $c['card_json'], true);
        $frames = array_map(fn($f) => ['id' => (int) $f['id'], 'position' => (int) $f['position'], 'at_ms' => $f['at_ms'] !== null ? (int) $f['at_ms'] : null,
            'kind' => $f['kind'], 'width' => $f['width'] !== null ? (int) $f['width'] : null, 'height' => $f['height'] !== null ? (int) $f['height'] : null,
            'src' => '/ajax/dev-cards/' . $id . '/frames/' . $f['id']], $this->cards->frames($id));
        return [
            'id' => (int) $c['id'], 'status' => $c['status'], 'name' => $c['name'], 'id_group' => $c['id_group'] !== null ? (int) $c['id_group'] : null,
            'group' => $c['group_pl'], 'group_color' => $c['group_color'],
            'weekly_sales' => $c['weekly_sales'] !== null ? (int) $c['weekly_sales'] : null, 'season_quarters' => CardSchema::quarters($c['season_quarters']),
            'seasonal' => $c['season_quarters'] !== null, 'forecast' => CardSchema::forecast($c['weekly_sales'] !== null ? (int) $c['weekly_sales'] : null, $c['season_quarters']),
            'source' => ['kind' => $c['source_kind'], 'url' => $c['source_url'], 'author' => $c['source_author'], 'title' => $c['source_title'],
                'text' => $c['source_text'], 'info' => $info, 'has_video' => !empty($c['video_url'])],
            'frames' => $frames,
            'card' => is_array($card) ? $card + ['ingredients_text' => CardSchema::ingredientsToText($card['ingredients']), 'steps_text' => implode("\n", $card['steps'])] : null,
            'model' => $c['model'], 'usage' => json_decode((string) $c['usage_json'], true), 'generated_at' => $c['generated_at'],
            'generation_ms' => $c['generation_ms'] !== null ? (int) $c['generation_ms'] : null,
            'product_id' => $c['id_test_product'] !== null ? (int) $c['id_test_product'] : null, 'product_name' => $c['product_name'],
            'footer' => CardSchema::FOOTER,
        ];
    }

    /** Karta przy produkcie w rozwoju (ERP : karta produktu). */
    public function forProduct(int $productId): ?array
    {
        $c = $this->cards->forProduct($productId);
        return $c ? $this->state((int) $c['id']) : null;
    }

    /** Tablet grupy : karta z obrazami (data URL) zdjęć etapów i okładką. */
    public function forTablet(int $productId): ?array
    {
        $c = $this->cards->forProduct($productId);
        $card = $c ? json_decode((string) $c['card_json'], true) : null;
        if (!is_array($card)) return null;
        $ids = array_column($card['photos'], 'frame_id');
        $images = [];
        $cover = null;
        foreach ($this->cards->framesWithImage((int) $c['id']) as $f) {
            if (in_array((int) $f['id'], $ids, true)) $images[(int) $f['id']] = $f;
            if ($f['kind'] === 'cover') $cover = $f;
        }
        $photo = function (array $f): string {
            $img = CardGenerator::shrink($f['mime'], $f['image']); // 768 px wystarczy na tablet
            return DataUrlImage::toDataUrl($img['mime'], $img['bytes']);
        };
        $photos = [];
        foreach ($card['photos'] as $p) if (isset($images[$p['frame_id']])) $photos[] = ['caption' => $p['caption'], 'src' => $photo($images[$p['frame_id']])];
        return ['title' => $card['title'], 'original_name' => $card['original_name'], 'author' => $card['author'], 'yield' => $card['yield'],
            'baking' => $card['baking'], 'time' => $card['time'], 'ingredients' => $card['ingredients'], 'steps' => $card['steps'], 'photos' => $photos,
            'cover' => $cover ? $photo($cover) : ($photos ? $photos[count($photos) - 1]['src'] : null), 'footer' => CardSchema::FOOTER];
    }

    private function card(int $id): array
    {
        return $this->cards->card($id) ?? throw new CustomException('Nie znaleziono karty.', 'errors', 404);
    }
}
