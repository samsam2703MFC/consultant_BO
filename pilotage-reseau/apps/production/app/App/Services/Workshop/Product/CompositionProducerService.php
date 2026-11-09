<?php
declare(strict_types=1);

namespace App\Services\Workshop\Product;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\ItemRepository;
use App\Repositories\Workshop\RecipeProducerRepository;

/**
 * Kompozycja / półprodukt : grupa, która ją robi, i z jakim wyprzedzeniem się ją zamawia — jedno źródło prawdy dla
 * formularza receptury, kalkulacji, kreatora produktu, karty produkcyjnej i tabletu. Grupa robiąca dostaje kartę
 * półproduktu (n_prod_item semi, grupa główna = grupa robiąca) : kompozycja wchodzi do jej listy produkcji,
 * partii i zamówień między grupami.
 */
final class CompositionProducerService
{
    public const LEAD_MAX = 14;

    public function __construct(
        private RecipeProducerRepository $producers,
        private ItemRepository $items,
        private TransactionManager $tx,
    ) {
    }

    /**
     * @param int|null|false $leadDays false = délai inchangé ; null = sans délai
     * @return ?array{id:int, name_pl:string, name_fr:string, color:string, lead_days:?int} groupe enregistré (null = retiré)
     */
    public function set(int $recipeId, ?int $groupId, ?int $userId, int|null|false $leadDays = false): ?array
    {
        $error = $this->problem($recipeId, $groupId, $leadDays);
        if ($error !== null) throw new CustomException($error, 'errors', 422);
        $this->tx->execute(fn() => $this->apply($recipeId, $groupId, $userId, $leadDays));
        return $groupId === null ? null : ($this->producers->forRecipes([$recipeId])[$recipeId] ?? null);
    }

    /** Kod błędu (PL) albo null : kompozycja, aktywna grupa, wyprzedzenie 0–LEAD_MAX dni. */
    public function problem(int $recipeId, ?int $groupId, int|null|false $leadDays = false): ?string
    {
        if (!$this->producers->isComponent($recipeId)) return 'Grupę ustawia się dla półproduktu albo kompozycji.';
        if ($groupId !== null && !$this->producers->groupExists($groupId)) return 'Nieznana grupa.';
        if ($leadDays !== false && $leadDays !== null && ($leadDays < 0 || $leadDays > self::LEAD_MAX)) {
            return 'Wyprzedzenie zamówienia : od 0 do ' . self::LEAD_MAX . ' dni.';
        }
        return null;
    }

    /**
     * Zapis bez własnej transakcji (wywołujący ją otwiera) : grupa robiąca + wyprzedzenie, karta półproduktu grupy ;
     * grupa zdjęta → karta bez historii usunięta (pomyłka nie zostawia jej na liście grupy).
     */
    public function apply(int $recipeId, ?int $groupId, ?int $userId, int|null|false $leadDays = false): void
    {
        $this->producers->set($recipeId, $groupId, $userId, $leadDays);
        if ($groupId !== null) $this->ensureItem($recipeId, $groupId, $userId);
        elseif (($unused = $this->producers->unusedSemiItem($recipeId)) !== null) $this->producers->deleteItem($unused);
    }

    /** Karta półproduktu grupy robiącej : utworzona albo przeniesiona do grupy (grupa główna). */
    public function ensureItem(int $recipeId, int $groupId, ?int $userId): int
    {
        $id = $this->items->idForRecipe($recipeId);
        if ($id === null) {
            $unit = $this->producers->recipeUnit($recipeId);
            $id = $this->items->insert(['item_type' => 'semi', 'id_recipe' => $recipeId, 'base_unit' => in_array($unit, ['g', 'kg', 'ml', 'l'], true) ? 'kg' : 'szt']);
            $this->items->saveSettings($id, ['mode' => 'MTO', 'never_frozen' => 0, 'id_primary_group' => $groupId, 'status' => 'validated', 'wizard_step' => 1, 'updated_by' => $userId]);
            return $id;
        }
        $this->items->saveSettings($id, ['id_primary_group' => $groupId, 'updated_by' => $userId]);
        return $id;
    }
}
