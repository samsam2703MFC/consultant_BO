<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\DevProductRepository;
use App\Services\Utils\DataUrlImage;

/**
 * Nowe produkty (produkty testowe ERP) na tablecie produkcji : lista produktów pokazanych grupie, karta produktu
 * z etapem rozwoju, notatkami, filmem (YouTube, Vimeo, plik wideo albo link), zdjęciami i drzewem receptury.
 * Biuro ustawia na stronie produktu testowego : pokaż na tablecie, film, notatki, zdjęcia.
 */
final class DevProductService
{
    public const MAX_PHOTO_BYTES = 3_000_000;
    public const MAX_PHOTOS = 12;

    public function __construct(private DevProductRepository $repo, private KioskRecipeService $recipes)
    {
    }

    // --- Tablet ------------------------------------------------------------------------------------

    public function list(ApiContext $context): array
    {
        $rows = $this->repo->forGroup($this->groupId($context));
        $stages = $this->repo->lastStages(array_map(fn($r) => (int) $r['id'], $rows));
        return ['products' => array_map(fn($r) => $this->row($r) + ['stage' => $this->stage($stages[(int) $r['id']] ?? null)], $rows)];
    }

    public function show(int $id, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $visible = array_column($this->repo->forGroup($groupId), null, 'id');
        $p = $visible[$id] ?? throw ApiException::notFound('test_product', $id);
        $fr = $context->translator->lang() === 'fr';
        return $this->row($p) + [
            'notes' => $p['notes'],
            'video' => self::video($p['video_url']),
            'stages' => array_map(fn($s) => $this->stage($s), $this->repo->stages($id)),
            'photos' => array_map(fn($ph) => ['id' => (int) $ph['id'], 'caption' => $ph['caption'],
                'src' => DataUrlImage::toDataUrl($ph['mime'], $ph['image'])], $this->repo->photosWithImage($id)),
            'recipe' => $p['id_recipe'] !== null ? $this->recipes->tree((int) $p['id_recipe'], $fr) : null,
        ];
    }

    // --- Biuro ---------------------------------------------------------------------------------------

    public function sheet(int $id): array
    {
        $p = $this->repo->product($id) ?? throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        return ['product' => $this->row($p) + ['notes' => $p['notes'], 'video_url' => $p['video_url'], 'video' => self::video($p['video_url'])],
            'photos' => $this->repo->photos($id), 'stages' => $this->repo->stages($id)];
    }

    public function save(int $id, array $input, ?int $userId): array
    {
        if ($this->repo->product($id) === null) throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        $url = trim((string) ($input['video_url'] ?? ''));
        if ($url !== '' && (strlen($url) > 500 || !preg_match('~^https?://[^\s]+$~i', $url))) throw new CustomException('Link do filmu : pełny adres http(s)://…', 'errors', 422);
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) throw new CustomException('Notatki : najwyżej 5000 znaków.', 'errors', 422);
        $this->repo->saveSettings($id, filter_var($input['on_tablet'] ?? false, FILTER_VALIDATE_BOOLEAN), $url !== '' ? $url : null, $notes !== '' ? $notes : null, $userId);
        return $this->sheet($id);
    }

    /** Zdjęcie jako data URL (JPEG / PNG / WebP, zmniejszone w przeglądarce). */
    public function addPhoto(int $id, array $input, ?int $userId): array
    {
        if ($this->repo->product($id) === null) throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        if ($this->repo->photoCount($id) >= self::MAX_PHOTOS) throw new CustomException('Najwyżej ' . self::MAX_PHOTOS . ' zdjęć na produkt.', 'errors', 422);
        $image = DataUrlImage::parse((string) ($input['image'] ?? ''), self::MAX_PHOTO_BYTES);
        $caption = trim((string) ($input['caption'] ?? ''));
        $this->repo->addPhoto($id, $image['mime'], $image['bytes'], $caption !== '' ? mb_substr($caption, 0, 200) : null, $userId);
        return $this->sheet($id);
    }

    public function deletePhoto(int $id, int $photoId): array
    {
        if (!$this->repo->deletePhoto($id, $photoId)) throw new CustomException('Nie znaleziono zdjęcia.', 'errors', 404);
        return $this->sheet($id);
    }

    public function photo(int $photoId): ?array
    {
        return $this->repo->photo($photoId);
    }

    /**
     * Film : YouTube (watch, youtu.be, shorts) i Vimeo → odtwarzacz osadzony ; plik mp4 / webm → wideo ; inny adres → link.
     * @return array{url:string, kind:string, embed:?string}|null
     */
    public static function video(?string $url): ?array
    {
        if ($url === null || $url === '') return null;
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return ['url' => $url, 'kind' => 'youtube', 'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1]];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return ['url' => $url, 'kind' => 'vimeo', 'embed' => 'https://player.vimeo.com/video/' . $m[1]];
        if (preg_match('~\.(mp4|webm|m4v|mov)(\?.*)?$~i', $url)) return ['url' => $url, 'kind' => 'file', 'embed' => null];
        return ['url' => $url, 'kind' => 'link', 'embed' => null];
    }

    // ---------------------------------------------------------------------------------------------

    private function row(array $p): array
    {
        return ['id' => (int) $p['id'], 'name' => trim((string) $p['name']), 'status' => $p['status_name'], 'status_id' => $p['id_status'] !== null ? (int) $p['id_status'] : null,
            'type' => $p['type_name'], 'section' => $p['section_name'], 'recipe_id' => $p['id_recipe'] !== null ? (int) $p['id_recipe'] : null,
            'recipe_name' => $p['recipe_name'], 'on_tablet' => (bool) $p['on_tablet'], 'has_video' => !empty($p['video_url']),
            'photo_count' => (int) $p['photo_count'], 'has_notes' => !empty($p['notes'])];
    }

    private function stage(?array $s): ?array
    {
        if ($s === null) return null;
        return ['name' => $s['stage'], 'description' => $s['description'] !== '' ? $s['description'] : null,
            'deadline' => $s['deadline'] !== null ? substr((string) $s['deadline'], 0, 10) : null];
    }

    private function groupId(ApiContext $context): int
    {
        if ($context->deviceGroupId === null) throw new ApiException('DEVICE_WITHOUT_GROUP', 409);
        return $context->deviceGroupId;
    }
}
