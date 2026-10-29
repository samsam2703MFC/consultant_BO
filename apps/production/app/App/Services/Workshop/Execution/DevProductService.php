<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\DevProductRepository;
use App\Services\DevCard\CardSchema;
use App\Services\DevCard\DevCardService;
use App\Services\Notification\AppNotificationService;
use App\Services\Utils\DataUrlImage;

/**
 * Nowe produkty : rozwój od pomysłu do wprowadzenia (CRM w biurze) i tablet grupy, która je testuje.
 *
 * Etapy : pomysł → test 1 (receptura i zdjęcia) → test 2 → receptura do zatwierdzenia → wprowadzenie → wprowadzony
 * (albo wstrzymany). Biuro planuje test (data, wskazówki, grupa) ; zespół robi go na tablecie i potwierdza JEDEN raz :
 * zdjęcia, ilość, wynik (OK / do poprawy / nie), co zmienić — bez ponownego wpisywania w biurze. Wynik przesuwa etap
 * (OK po teście 1 → test 2 ; OK po teście 2 i dalej → receptura ; do poprawy → kolejny test). Zespół potwierdza
 * recepturę na tablecie → wprowadzenie ; biuro łączy produkt ERP → wprowadzony (data w module kierownika produkcji).
 * Każdy zapis z tabletu → dzwonek w ERP. Produkt widzi tylko tablet przypisanej grupy (bez grupy : według działu).
 * Na tablecie także karta produktu : etap, notatki, film, zdjęcia wzorcowe, drzewo receptury.
 */
final class DevProductService
{
    public const MAX_PHOTO_BYTES = 3_000_000;
    public const MAX_PHOTOS = 12;
    public const MAX_TRIAL_PHOTOS = 6;
    private const MAX_TRIAL_PHOTO_BYTES = 2_500_000;
    /** etap => [PL, FR] */
    public const STAGES = [
        'idea' => ['Pomysł', 'Idée'],
        'test1' => ['Test 1 : receptura i zdjęcia', 'Test 1 : recette et photos'],
        'test2' => ['Test 2', 'Second test'],
        'recipe' => ['Receptura do zatwierdzenia', 'Recette à valider'],
        'launch' => ['Wprowadzenie', 'Lancement'],
        'launched' => ['Wprowadzony', 'Lancé'],
        'stopped' => ['Wstrzymany', 'Arrêté'],
    ];
    /** etapy w kolejności (tablica i postęp) */
    public const FLOW = ['idea', 'test1', 'test2', 'recipe', 'launch', 'launched'];
    public const RESULTS = ['ok' => ['OK', 'OK'], 'fix' => ['Do poprawy', 'À corriger'], 'ko' => ['Nie', 'Non']];

    public function __construct(
        private DevProductRepository $repo,
        private KioskRecipeService $recipes,
        private TransactionManager $tx,
        private AppNotificationService $notifications,
        private DevCardService $cards,
    ) {
    }

    // --- Tablet ------------------------------------------------------------------------------------

    public function list(ApiContext $context): array
    {
        $rows = $this->repo->forGroup($this->groupId($context));
        $stages = $this->repo->lastStages(array_map(fn($r) => (int) $r['id'], $rows));
        $i = $context->translator->lang() === 'fr' ? 1 : 0;
        return ['products' => array_map(fn($r) => $this->row($r) + ['stage' => $this->stage($stages[(int) $r['id']] ?? null), 'dev' => $this->devState($r, $i)], $rows)];
    }

    /** Ekran główny tabletu (bez logowania osoby) : nowe produkty grupy i następny krok. */
    public function summary(ApiContext $context): array
    {
        if ($context->deviceGroupId === null) return ['products' => []];
        $i = $context->translator->lang() === 'fr' ? 1 : 0;
        return ['products' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => trim((string) $r['name'])] + $this->devState($r, $i), $this->repo->forGroup($context->deviceGroupId))];
    }

    public function show(int $id, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $visible = array_column($this->repo->forGroup($groupId), null, 'id');
        $p = $visible[$id] ?? throw ApiException::notFound('test_product', $id);
        $fr = $context->translator->lang() === 'fr';
        $i = $fr ? 1 : 0;
        $trials = $this->repo->trials($id);
        $photos = $this->repo->trialPhotos(array_column($trials, 'id'), true);
        return $this->row($p) + [
            'dev' => $this->devState($p, $i),
            'flow' => array_map(fn($code) => ['code' => $code, 'label' => self::STAGES[$code][$i], 'state' => $this->flowState($code, $p['stage'])], self::FLOW),
            'trials' => array_map(fn($tr) => $this->presentTrial($tr, $i) + ['photos' => array_map(fn($ph) => ['id' => (int) $ph['id'], 'taken_at' => $ph['taken_at'],
                'src' => DataUrlImage::toDataUrl($ph['mime'], $ph['image'])], $photos[(int) $tr['id']] ?? [])], $trials),
            'recipe_confirmed' => $p['recipe_confirmed_at'] ? ['at' => $p['recipe_confirmed_at'], 'by' => $p['recipe_confirmed_name'] ?: null] : null,
            'max_trial_photos' => self::MAX_TRIAL_PHOTOS,
            'notes' => $p['notes'],
            'video' => self::video($p['video_url']),
            'stages' => array_map(fn($s) => $this->stage($s), $this->repo->stages($id)),
            'photos' => array_map(fn($ph) => ['id' => (int) $ph['id'], 'caption' => $ph['caption'],
                'src' => DataUrlImage::toDataUrl($ph['mime'], $ph['image'])], $this->repo->photosWithImage($id)),
            'recipe' => $p['id_recipe'] !== null ? $this->recipes->tree((int) $p['id_recipe'], $fr) : null,
            'card' => $this->cards->forTablet($id),
        ];
    }

    /**
     * Test zrobiony na tablecie (osoba zalogowana) : kończy najbliższy zaplanowany test albo dodaje kolejny. Zdjęcia
     * (1 do MAX_TRIAL_PHOTOS), ilość, wynik, co zmienić (obowiązkowe przy « do poprawy » i « nie »). Przesuwa etap.
     */
    public function recordTrial(int $id, array $body, ApiContext $context): array
    {
        $groupId = $this->groupId($context);
        $visible = array_column($this->repo->forGroup($groupId), null, 'id');
        $p = $visible[$id] ?? throw ApiException::notFound('test_product', $id);
        if (!in_array($p['stage'], ['idea', 'test1', 'test2', 'recipe'], true)) throw new ApiException('DEV_TRIAL_NOT_ALLOWED', 409, [], ['stage' => self::STAGES[$p['stage']][$context->translator->lang() === 'fr' ? 1 : 0]]);
        $v = (new Validator($body))->only(['quantity', 'result', 'notes', 'photos'])
            ->decimal('quantity', false, 0, 100000, true)->choice('result', array_keys(self::RESULTS))->string('notes', 2000, false, true);
        if (in_array($v->value('result'), ['fix', 'ko'], true) && trim((string) $v->value('notes')) === '') $v->add('notes', 'REQUIRED');
        $raw = $v->raw('photos');
        $images = [];
        if (!is_array($raw) || !$raw) {
            $v->add('photos', 'REQUIRED');
        } elseif (count($raw) > self::MAX_TRIAL_PHOTOS) {
            $v->add('photos', 'TOO_MANY', ['max' => self::MAX_TRIAL_PHOTOS]);
        } else {
            foreach (array_values($raw) as $k => $photo) {
                try {
                    $images[] = DataUrlImage::parse(is_array($photo) ? (string) ($photo['image'] ?? '') : (string) $photo, self::MAX_TRIAL_PHOTO_BYTES)
                        + ['taken_at' => is_array($photo) && is_string($photo['taken_at'] ?? null) && strtotime($photo['taken_at']) && abs(strtotime($photo['taken_at']) - time()) < 86400
                            ? date('Y-m-d H:i:s', strtotime($photo['taken_at'])) : date('Y-m-d H:i:s')];
                } catch (CustomException) {
                    $v->add("photos.{$k}", 'INVALID_PHOTO');
                }
            }
        }
        $v->throwIfInvalid();
        $result = $v->value('result');
        $notes = $v->value('notes') !== null ? trim((string) $v->value('notes')) : null;
        $employeeId = $context->employeeId;

        $trialNo = $this->tx->execute(function () use ($id, $p, $groupId, $v, $result, $notes, $images, $employeeId) {
            $planned = $this->repo->plannedTrial($id);
            $data = ['status' => 'done', 'done_at' => date('Y-m-d H:i:s'), 'id_employee' => $employeeId, 'id_group' => $groupId,
                'quantity' => $v->value('quantity'), 'result' => $result, 'notes' => $notes !== '' ? $notes : null];
            if ($planned) {
                $trialId = (int) $planned['id'];
                $no = (int) $planned['trial_no'];
                $this->repo->updateTrial($trialId, $data);
            } else {
                $no = $this->repo->nextTrialNo($id);
                $trialId = $this->repo->insertTrial(['id_test_product' => $id, 'trial_no' => $no] + $data);
            }
            foreach ($images as $img) $this->repo->addTrialPhoto($trialId, $img['mime'], $img['bytes'], $img['taken_at']);
            // Etap : OK po pierwszym teście → drugi test ; OK po drugim (i dalej) → receptura ; do poprawy → kolejny test.
            $stage = match ($result) {
                'ok' => $no >= 2 ? 'recipe' : 'test2',
                'fix' => 'test2',
                default => $p['stage'] === 'idea' ? 'test1' : $p['stage'],
            };
            if ($stage !== $p['stage']) $this->repo->saveDev($id, ['stage' => $stage, 'stage_changed_at' => date('Y-m-d H:i:s')]);
            return $no;
        });
        $who = $context->employeeId !== null ? ($this->employeeName($context->employeeId) ?? '') : '';
        $this->notifications->publish('dev_trial', $result === 'ok' ? 'info' : 'warning', 'Nowy produkt : test ' . $trialNo,
            trim($p['name']) . ' — ' . self::RESULTS[$result][0] . ($notes ? ' : ' . mb_substr($notes, 0, 140) : '') . ($who !== '' ? ' (' . $who . ')' : '') . '.',
            '/test-products/' . $id . '/tablet', ['id_test_product' => $id, 'trial_no' => $trialNo], 'dev_trial:' . $id . ':' . $trialNo);
        return $this->show($id, $context);
    }

    /** Receptura potwierdzona na tablecie (etap « receptura do zatwierdzenia ») → wprowadzenie. */
    public function confirmRecipe(int $id, ApiContext $context): array
    {
        $visible = array_column($this->repo->forGroup($this->groupId($context)), null, 'id');
        $p = $visible[$id] ?? throw ApiException::notFound('test_product', $id);
        if ($p['stage'] !== 'recipe') throw new ApiException('DEV_RECIPE_NOT_READY', 409, [], ['stage' => self::STAGES[$p['stage']][$context->translator->lang() === 'fr' ? 1 : 0]]);
        if ($p['id_recipe'] === null) throw new ApiException('DEV_RECIPE_MISSING', 409);
        $this->tx->execute(function () use ($id, $context) {
            $this->repo->saveDev($id, ['stage' => 'launch', 'stage_changed_at' => date('Y-m-d H:i:s'), 'recipe_confirmed_at' => date('Y-m-d H:i:s'), 'recipe_confirmed_by' => $context->employeeId]);
            $this->repo->setTestProductStatus($id, 2);
        });
        $who = $context->employeeId !== null ? ($this->employeeName($context->employeeId) ?? '') : '';
        $this->notifications->publish('dev_trial', 'info', 'Nowy produkt : receptura potwierdzona', trim($p['name']) . ($who !== '' ? ' (' . $who . ')' : '') . ' — można wprowadzić produkt.',
            '/test-products/' . $id . '/tablet', ['id_test_product' => $id], 'dev_recipe:' . $id);
        return $this->show($id, $context);
    }

    // --- Biuro ---------------------------------------------------------------------------------------

    /** Tablica etapów : produkty w kolumnach, grupy, następny krok. */
    public function pipeline(?int $groupId = null): array
    {
        $rows = array_values(array_filter($this->repo->pipeline(), fn($r) => $groupId === null || (int) $r['id_group'] === $groupId));
        $columns = [];
        foreach (array_merge(self::FLOW, ['stopped']) as $code) $columns[$code] = ['code' => $code, 'label' => self::STAGES[$code][0], 'items' => []];
        foreach ($rows as $r) $columns[$r['stage']]['items'][] = $this->row($r) + ['dev' => $this->devState($r, 0)];
        return ['columns' => array_values($columns), 'groups' => $this->repo->groups(), 'group_id' => $groupId, 'count' => count($rows)];
    }

    /** Nowy produkt w rozwoju : nazwa, grupa (jej tablet), notatki, data docelowa — jeden zapis. */
    public function create(array $input, ?int $userId): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) throw new CustomException('Nazwa produktu : od 1 do 255 znaków.', 'errors', 422);
        $group = $this->group($input['id_group'] ?? null);
        $target = $this->date($input['target_date'] ?? null);
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) throw new CustomException('Notatki : najwyżej 5000 znaków.', 'errors', 422);
        return $this->tx->execute(function () use ($name, $group, $target, $notes, $userId) {
            $id = $this->repo->createTestProduct($name, $group['id_section'] !== null ? (int) $group['id_section'] : null);
            $this->repo->saveDev($id, ['on_tablet' => 1, 'id_group' => (int) $group['id'], 'stage' => 'idea', 'stage_changed_at' => date('Y-m-d H:i:s'),
                'target_date' => $target, 'notes' => $notes !== '' ? $notes : null, 'updated_by' => $userId]);
            return $id;
        });
    }

    /** Test zaplanowany przez biuro : numer kolejny, data, wskazówki, grupa (jej tablet) ; produkt pokazany na tablecie. */
    public function planTrial(int $id, array $input, ?int $userId): array
    {
        $p = $this->repo->product($id) ?? throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        if (in_array($p['stage'], ['launch', 'launched', 'stopped'], true)) throw new CustomException('Test można zaplanować przed wprowadzeniem (etap : ' . self::STAGES[$p['stage']][0] . ').', 'errors', 422);
        $group = $this->group($input['id_group'] ?? $p['id_group']);
        $date = $this->date($input['planned_date'] ?? null);
        $instructions = trim((string) ($input['instructions'] ?? ''));
        if (mb_strlen($instructions) > 2000) throw new CustomException('Wskazówki : najwyżej 2000 znaków.', 'errors', 422);
        $this->tx->execute(function () use ($id, $p, $group, $date, $instructions, $userId) {
            $no = $this->repo->nextTrialNo($id);
            $this->repo->insertTrial(['id_test_product' => $id, 'trial_no' => $no, 'status' => 'planned', 'planned_date' => $date,
                'instructions' => $instructions !== '' ? $instructions : null, 'id_group' => (int) $group['id'], 'created_by' => $userId]);
            $stage = in_array($p['stage'], ['idea', 'test1'], true) ? ($no >= 2 ? 'test2' : 'test1') : $p['stage'];
            $this->repo->saveDev($id, ['on_tablet' => 1, 'id_group' => (int) $group['id'], 'updated_by' => $userId]
                + ($stage !== $p['stage'] ? ['stage' => $stage, 'stage_changed_at' => date('Y-m-d H:i:s')] : []));
        });
        return $this->sheet($id);
    }

    public function cancelTrial(int $id, int $trialId): array
    {
        if (!$this->repo->deletePlannedTrial($id, $trialId)) throw new CustomException('Można usunąć tylko test zaplanowany (jeszcze nie zrobiony).', 'errors', 404);
        return $this->sheet($id);
    }

    /** Ręczna zmiana etapu (np. wstrzymanie, wznowienie, powrót do testu). */
    public function setStage(int $id, array $input, ?int $userId): array
    {
        if ($this->repo->product($id) === null) throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        $stage = (string) ($input['stage'] ?? '');
        if (!isset(self::STAGES[$stage]) || $stage === 'launched') throw new CustomException('Nieprawidłowy etap (wprowadzenie : przycisk « Wprowadź »).', 'errors', 422);
        $this->repo->saveDev($id, ['stage' => $stage, 'stage_changed_at' => date('Y-m-d H:i:s'), 'updated_by' => $userId]);
        return $this->sheet($id);
    }

    /** Wprowadzenie : produkt ERP powiązany (data wprowadzenia dla kierownika produkcji i raportu), produkt testowy zakończony. */
    public function launch(int $id, array $input, ?int $userId): array
    {
        $p = $this->repo->product($id) ?? throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        $productId = filter_var($input['final_product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || !$this->repo->finalProductExists($productId)) throw new CustomException('Wybierz produkt ERP do powiązania.', 'errors', 422);
        $this->tx->execute(function () use ($id, $productId, $userId) {
            $this->repo->saveDev($id, ['stage' => 'launched', 'stage_changed_at' => date('Y-m-d H:i:s'), 'id_final_product' => $productId, 'on_tablet' => 0, 'updated_by' => $userId]);
            $this->repo->setTestProductStatus($id, 3);
            $this->repo->recordLaunch($productId, date('Y-m-d'), $userId);
        });
        return $this->sheet($id);
    }

    /**
     * « Wprowadź » (audyt 2, lot C) : dane produktu testowego do kreatora produktu — nazwa, kategoria, dział, etykieta
     * i receptura testu (bez ponownego wpisywania) ; null, gdy test już wprowadzony.
     * @return array{test_id:int, name:string, id_category:?int, id_section:?int, label_type:int, id_recipe:?int, recipe_name:?string}|null
     */
    public function launchPrefill(int $id): ?array
    {
        $p = $this->repo->product($id);
        if (!$p || $p['stage'] === 'launched') return null;
        $raw = $this->repo->testProductRaw($id);
        return ['test_id' => $id, 'name' => (string) $p['name'], 'id_category' => isset($raw['id_category']) ? (int) $raw['id_category'] : null,
            'id_section' => $p['id_section'] !== null ? (int) $p['id_section'] : null, 'label_type' => (int) ($raw['label_type'] ?? 0),
            'id_recipe' => $p['id_recipe'] !== null ? (int) $p['id_recipe'] : null, 'recipe_name' => $p['recipe_name']];
    }

    public function trialPhoto(int $photoId): ?array
    {
        return $this->repo->trialPhoto($photoId);
    }


    public function sheet(int $id): array
    {
        $p = $this->repo->product($id) ?? throw new CustomException('Nie znaleziono produktu testowego.', 'errors', 404);
        $trials = $this->repo->trials($id);
        $photos = $this->repo->trialPhotos(array_column($trials, 'id'));
        return ['product' => $this->row($p) + ['notes' => $p['notes'], 'video_url' => $p['video_url'], 'video' => self::video($p['video_url']),
                'dev' => $this->devState($p, 0), 'target_date' => $p['target_date'], 'group_id' => $p['id_group'] !== null ? (int) $p['id_group'] : null,
                'recipe_confirmed_at' => $p['recipe_confirmed_at'], 'recipe_confirmed_name' => $p['recipe_confirmed_name'] ?: null,
                'final_product_id' => $p['id_final_product'] !== null ? (int) $p['id_final_product'] : null, 'final_product_name' => $p['final_product_name'],
                'forecast' => CardSchema::forecast($p['weekly_sales'] !== null ? (int) $p['weekly_sales'] : null, $p['season_quarters'])],
            'card' => $this->cards->forProduct($id),
            'flow' => array_map(fn($code) => ['code' => $code, 'label' => self::STAGES[$code][0], 'state' => $this->flowState($code, $p['stage'])], self::FLOW),
            'stage_choices' => array_map(fn($code) => ['code' => $code, 'label' => self::STAGES[$code][0]], array_diff(array_keys(self::STAGES), ['launched'])),
            'trials' => array_map(fn($tr) => $this->presentTrial($tr, 0) + ['photos' => array_map(fn($ph) => ['id' => (int) $ph['id'], 'taken_at' => $ph['taken_at']], $photos[(int) $tr['id']] ?? [])], $trials),
            'groups' => $this->repo->groups(),
            'final_products' => $p['stage'] === 'launched' ? [] : $this->repo->finalProducts(),
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
        // Grupa (jej tablet) i data docelowa : gdy podane w formularzu.
        $extra = [];
        if (array_key_exists('id_group', $input)) $extra['id_group'] = $input['id_group'] === null || $input['id_group'] === '' ? null : (int) $this->group($input['id_group'])['id'];
        if (array_key_exists('target_date', $input)) $extra['target_date'] = $this->date($input['target_date']);
        $this->repo->saveDev($id, $extra);
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
            'photo_count' => (int) $p['photo_count'], 'has_notes' => !empty($p['notes']), 'has_card' => !empty($p['has_card']),
            'weekly_sales' => $p['weekly_sales'] !== null ? (int) $p['weekly_sales'] : null, 'seasonal' => $p['season_quarters'] !== null];
    }

    /** Etap rozwoju i następny krok (tablet, biuro). */
    private function devState(array $p, int $i): array
    {
        $stage = $p['stage'] ?? 'idea';
        $nextTrial = $p['next_trial_no'] !== null ? (int) $p['next_trial_no'] : null;
        $doneTrials = (int) ($p['trials_done'] ?? 0);
        $next = match ($stage) {
            'idea', 'test1', 'test2' => ['kind' => 'trial', 'trial_no' => $nextTrial ?? $doneTrials + 1, 'date' => $p['next_trial_date'],
                'label' => sprintf($i ? 'Faire le test %d' : 'Zrobić test %d', $nextTrial ?? $doneTrials + 1) . ($p['next_trial_date'] ? ' · ' . date('d.m', strtotime($p['next_trial_date'])) : '')],
            'recipe' => ['kind' => 'recipe', 'label' => $i ? 'Confirmer la recette' : 'Potwierdzić recepturę'],
            'launch' => ['kind' => 'office', 'label' => $i ? 'Lancement au bureau' : 'Wprowadzenie w biurze'],
            default => null,
        };
        return [
            'stage' => $stage, 'stage_label' => self::STAGES[$stage][$i], 'step' => array_search($stage, self::FLOW, true) === false ? null : array_search($stage, self::FLOW, true) + 1,
            'steps' => count(self::FLOW), 'next' => $next, 'trials_done' => $doneTrials,
            'last_result' => $p['last_result'] ? ['code' => $p['last_result'], 'label' => self::RESULTS[$p['last_result']][$i], 'at' => $p['last_trial_at']] : null,
            'group' => $p['id_group'] !== null ? ['id' => (int) $p['id_group'], 'name' => $i ? $p['group_fr'] : $p['group_pl'], 'color' => $p['group_color']] : null,
            'target_date' => $p['target_date'], 'stage_since' => $p['stage_changed_at'],
        ];
    }

    private function flowState(string $code, string $stage): string
    {
        if ($stage === 'stopped') return 'todo';
        $a = array_search($code, self::FLOW, true);
        $b = array_search($stage, self::FLOW, true);
        return $a < $b ? 'done' : ($a === $b ? 'current' : 'todo');
    }

    private function presentTrial(array $tr, int $i): array
    {
        return [
            'id' => (int) $tr['id'], 'no' => (int) $tr['trial_no'], 'status' => $tr['status'], 'planned_date' => $tr['planned_date'], 'instructions' => $tr['instructions'],
            'group' => $i ? $tr['group_fr'] : $tr['group_pl'], 'done_at' => $tr['done_at'], 'employee' => trim((string) $tr['employee']) ?: null,
            'quantity' => $tr['quantity'] !== null ? (float) $tr['quantity'] : null, 'result' => $tr['result'],
            'result_label' => $tr['result'] ? self::RESULTS[$tr['result']][$i] : null, 'notes' => $tr['notes'],
        ];
    }

    /** Aktywna grupa produkcji (obowiązkowa). */
    private function group(mixed $value): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        foreach ($this->repo->groups() as $g) if ($id !== false && (int) $g['id'] === $id) return $g;
        throw new CustomException('Wybierz grupę produkcji (jej tablet pokaże produkt).', 'errors', 422);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $value = (string) $value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new CustomException('Nieprawidłowa data.', 'errors', 422);
        }
        return $value;
    }

    private function employeeName(int $id): ?string
    {
        return $this->repo->employeeName($id);
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
