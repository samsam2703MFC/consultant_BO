<?php
declare(strict_types=1);

namespace App\Services\Workshop\Execution;

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Validator;
use App\Repositories\Workshop\ClockQuestionRepository;

/**
 * Pointage : questions à confirmer avant « OK, zatwierdzam » (tablette fixe au bureau, QR scanné au téléphone,
 * ou tablette du groupe). Paramétrables par le responsable ; une question obligatoire non cochée bloque le pointage.
 * Les réponses sont gardées avec le pointage (texte de la question figé).
 * [263] Genre « yes_no » : réponse Tak / Nie (les deux valables ; obligatoire = il faut choisir), information (i) ;
 * « Tak » demande ce qui est pris (note obligatoire), par exemple la marchandise reprise à la sortie.
 * [264] picker « products » : au lieu du texte, choix rapide catégorie → produit → quantité (pièces, pavé numérique),
 * plusieurs lignes, « z braków » par ligne ; « Inne » garde un texte court. Lignes gardées dans n_clock_goods, résumé dans la note.
 */
final class ClockQuestionService
{
    /** Lignes de marchandise par réponse, pièces par ligne. */
    private const MAX_GOODS_LINES = 30;
    private const MAX_GOODS_QUANTITY = 999;

    public function __construct(private ClockQuestionRepository $repo)
    {
    }

    public function list(): array
    {
        return array_map(fn($q) => self::row($q), $this->repo->list());
    }

    public function create(array $body, ApiContext $context): array
    {
        $c = $this->validate($body, false);
        $id = $this->repo->insert($c + ['sort_order' => $c['sort_order'] ?? $this->repo->nextSort(), 'updated_by' => $context->userId]);
        return self::row($this->repo->find($id));
    }

    public function update(int $id, array $body, ApiContext $context): array
    {
        if ($this->repo->find($id) === null) throw ApiException::notFound('clock_question', $id);
        $this->repo->update($id, $this->validate($body, true) + ['updated_by' => $context->userId]);
        return self::row($this->repo->find($id));
    }

    /** Les réponses déjà données gardent le texte de la question. */
    public function delete(int $id): array
    {
        if ($this->repo->find($id) === null) throw ApiException::notFound('clock_question', $id);
        $this->repo->delete($id);
        return ['id' => $id, 'deleted' => true];
    }

    /** Tablette / téléphone : questions de l'entrée et de la sortie, dans la langue de l'écran (polonais sans traduction). */
    public function forKiosk(bool $fr): array
    {
        $pick = fn(array $q, string $field) => $fr && trim((string) ($q[$field . '_fr'] ?? '')) !== '' ? (string) $q[$field . '_fr'] : (string) ($q[$field . '_pl'] ?? '');
        $map = fn(array $rows) => array_map(fn($q) => ['id' => (int) $q['id'], 'text' => $pick($q, 'text'),
            'required' => (bool) $q['required'], 'kind' => (string) ($q['kind'] ?? 'confirm'), 'picker' => self::picker($q),
            'info' => $pick($q, 'info') !== '' ? $pick($q, 'info') : null], $rows);
        $out = ['in' => $map($this->repo->active('in')), 'out' => $map($this->repo->active('out'))];
        // Le catalogue n'est envoyé que si une question le demande.
        foreach ([...$out['in'], ...$out['out']] as $q) {
            if ($q['picker'] === 'products') { $out['catalog'] = $this->catalog(); break; }
        }
        return $out;
    }

    /** @return list<array{id:int, name:string, products:list<array{id:int, name:string}>}> catégories (ordre alphabétique) et leurs produits actifs */
    public function catalog(): array
    {
        $cats = [];
        foreach ($this->repo->goodsCatalog() as $p) {
            $c = (int) $p['category_id'];
            $cats[$c] ??= ['id' => $c, 'name' => (string) $p['category'], 'products' => []];
            $cats[$c]['products'][] = ['id' => (int) $p['id'], 'name' => (string) $p['name']];
        }
        return array_values($cats);
    }

    /**
     * Réponses envoyées avec le pointage : [{question_id, yes, note?}]. Une question obligatoire non confirmée (confirm)
     * ou sans réponse (yes_no), ou « Tak » sans préciser quoi (yes_no) → 422.
     * @return list<array{id:int, text:string, answer:bool, note:?string}> réponses à garder
     */
    public function check(string $direction, mixed $answers, bool $fr): array
    {
        $given = [];
        foreach (is_array($answers) ? $answers : [] as $a) {
            if (!is_array($a) || !isset($a['question_id'])) continue;
            $note = isset($a['note']) && is_scalar($a['note']) ? trim((string) $a['note']) : '';
            $given[(int) $a['question_id']] = ['yes' => filter_var($a['yes'] ?? false, FILTER_VALIDATE_BOOLEAN), 'note' => $note === '' ? null : mb_substr($note, 0, 200),
                'items' => isset($a['items']) && is_array($a['items']) ? $a['items'] : []];
        }
        $out = [];
        $missing = [];
        foreach ($this->repo->active($direction) as $q) {
            $text = $fr && (string) $q['text_fr'] !== '' ? $q['text_fr'] : $q['text_pl'];
            $g = $given[(int) $q['id']] ?? null;
            $yes = $g['yes'] ?? false;
            if (($q['kind'] ?? 'confirm') === 'yes_no') {
                if (self::picker($q) === 'products') {
                    $goods = $yes ? $this->goodsLines($g['items'], $g['note']) : [];
                    if ($q['required'] && $g === null) $missing[] = $text;
                    elseif ($yes && $goods === []) $missing[] = $text . ' → ' . ($fr ? 'quoi ?' : 'co ?');
                    if ($g !== null) $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes, 'note' => $yes && $goods ? self::goodsSummary($goods) : null, 'goods' => $goods];
                    continue;
                }
                if ($q['required'] && $g === null) $missing[] = $text;
                elseif ($yes && $g['note'] === null) $missing[] = $text . ' → ' . ($fr ? 'quoi ?' : 'co ?');
                if ($g !== null) $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes, 'note' => $yes ? $g['note'] : null];
                continue;
            }
            if ($q['required'] && !$yes) $missing[] = $text;
            $out[] = ['id' => (int) $q['id'], 'text' => (string) $q['text_pl'], 'answer' => $yes, 'note' => null];
        }
        if ($missing) throw new ApiException('CLOCK_QUESTIONS_REQUIRED', 422, [], ['questions' => implode(' · ', $missing)]);
        return $out;
    }

    /**
     * Lignes choisies sur l'écran : [{product_id, quantity, waste?}] + texte « Inne ». Produit inconnu ou quantité hors
     * 1–999 → 422 ; le même produit choisi deux fois (même origine) est additionné.
     * @return list<array{id_final_product:?int, product:string, quantity:int, from_waste:bool}>
     */
    private function goodsLines(array $items, ?string $other): array
    {
        if (count($items) > self::MAX_GOODS_LINES) throw ApiException::validation([['field' => 'items', 'code' => 'too_many']]);
        $names = $this->repo->productNames(array_map(fn($i) => is_array($i) ? (int) ($i['product_id'] ?? 0) : 0, $items));
        $lines = [];
        foreach ($items as $i) {
            $id = is_array($i) ? (int) ($i['product_id'] ?? 0) : 0;
            $qty = is_array($i) && is_numeric($i['quantity'] ?? null) ? (int) $i['quantity'] : 0;
            if (!isset($names[$id])) throw ApiException::validation([['field' => 'items.product_id', 'code' => 'unknown']]);
            if ($qty < 1 || $qty > self::MAX_GOODS_QUANTITY) throw ApiException::validation([['field' => 'items.quantity', 'code' => 'out_of_range']]);
            $waste = filter_var($i['waste'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $key = $id . ':' . (int) $waste;
            if (isset($lines[$key])) $lines[$key]['quantity'] = min(self::MAX_GOODS_QUANTITY, $lines[$key]['quantity'] + $qty);
            else $lines[$key] = ['id_final_product' => $id, 'product' => (string) $names[$id], 'quantity' => $qty, 'from_waste' => $waste];
        }
        if ($other !== null) $lines['other'] = ['id_final_product' => null, 'product' => $other, 'quantity' => 1, 'from_waste' => false];
        return array_values($lines);
    }

    /** « 2 × Tarta jabłkowa · 1 × Quiche (braki) · inne : mąka 1 kg » — lisible dans Czas pracy › Dzień. */
    private static function goodsSummary(array $lines): string
    {
        return mb_substr(implode(' · ', array_map(fn($l) => $l['id_final_product'] === null ? 'inne : ' . $l['product']
            : $l['quantity'] . ' × ' . $l['product'] . ($l['from_waste'] ? ' (braki)' : ''), $lines)), 0, 600);
    }

    private static function picker(array $q): ?string
    {
        return ($q['kind'] ?? 'confirm') === 'yes_no' && ($q['picker'] ?? 'text') === 'products' ? 'products' : null;
    }

    public function save(int $clockId, string $direction, array $answers): void
    {
        if ($answers) $this->repo->saveAnswers($clockId, $direction, $answers);
    }

    private function validate(array $body, bool $partial): array
    {
        $v = (new Validator($body, $partial))->only(['text_pl', 'text_fr', 'direction', 'kind', 'picker', 'info_pl', 'info_fr', 'required', 'active', 'sort_order'])
            ->string('text_pl', 200)->string('text_fr', 200, false, true)->choice('direction', ['in', 'out', 'both'], false)
            ->choice('kind', ['confirm', 'yes_no'], false)->choice('picker', ['text', 'products'], false)->string('info_pl', 1000, false, true)->string('info_fr', 1000, false, true)
            ->boolean('required')->boolean('active')->integer('sort_order', false, 0, 100000);
        $v->throwIfInvalid();
        $c = $v->clean();
        foreach (['required', 'active'] as $k) if (array_key_exists($k, $c)) $c[$k] = (int) (bool) $c[$k];
        foreach (['text_fr', 'info_pl', 'info_fr'] as $k) if (array_key_exists($k, $c) && ($c[$k] === null || trim((string) $c[$k]) === '')) $c[$k] = null;
        return $c;
    }

    private static function row(array $q): array
    {
        return ['id' => (int) $q['id'], 'text_pl' => $q['text_pl'], 'text_fr' => $q['text_fr'], 'direction' => $q['direction'],
            'kind' => (string) ($q['kind'] ?? 'confirm'), 'picker' => (string) ($q['picker'] ?? 'text'), 'info_pl' => $q['info_pl'] ?? null, 'info_fr' => $q['info_fr'] ?? null,
            'required' => (bool) $q['required'], 'active' => (bool) $q['active'], 'sort_order' => (int) $q['sort_order']];
    }
}
