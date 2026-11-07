<?php
declare(strict_types=1);

namespace App\Services\Product;

use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Workshop\RecipeUnitCheckRepository;
use App\Services\Recipe\RecipeService;

/**
 * Receptura produktu = 1 karton (cały produkt sprzedawany) [201]. Kalkulacja i marże czytają recepturę produktu jako
 * 1 karton ; kreator prosił kiedyś o « 1 sztukę », więc część receptur ma koszt kartonu ÷ sztuk w kartonie.
 * Lista do sprawdzenia + decyzja operatora : « × sztuk w kartonie » (ilości surowców i półproduktów, wersja receptury,
 * spłaszczenie, etykieta, koszt, marże) albo « już na karton ». Nic nie zmienia się bez decyzji.
 */
final class RecipeUnitCheckService
{
    public function __construct(
        private RecipeUnitCheckRepository $repo,
        private RecipeService $recipes,
        private TransactionManager $tx,
    ) {
    }

    /** @return list<array> produkty do sprawdzenia, z kosztem kartonu teraz i po × sztuk w kartonie */
    public function list(): array
    {
        return array_map(fn(array $r) => self::present($r), $this->repo->candidates());
    }

    /**
     * Decyzja : scale (ilości × sztuk w kartonie) albo keep (receptura już na karton).
     * @return array{id:int, decision:string, cost_before:float, cost_after:float}
     */
    public function decide(int $productId, string $action, ?int $userId): array
    {
        if (!in_array($action, ['scale', 'keep'], true)) throw new CustomException('Nieznana decyzja.', 'errors', 422);
        $row = $this->repo->candidates($productId)[0] ?? throw new CustomException('Produkt nie jest na liście do sprawdzenia (odśwież).', 'errors', 404);
        $p = self::present($row);
        $recipeId = (int) $row['id_recipe'];
        $before = (float) $row['cost'];
        if ($action === 'keep') {
            $this->repo->decide($productId, $recipeId, 'kept', null, $before, $userId);
            return ['id' => $productId, 'decision' => 'kept', 'cost_before' => $before, 'cost_after' => $before];
        }
        if ($p['blocked']) throw new CustomException($p['blocked'], 'errors', 422);
        $factor = (int) $row['ppc'];
        $materials = [];
        $children = [];
        foreach ($this->repo->connections($recipeId) as $c) {
            $qty = (int) round((float) $c['quantity'] * $factor);
            if ($c['child_ingredient_id'] !== null) $materials[] = ['id' => (int) $c['child_ingredient_id'], 'quantity' => $qty];
            elseif ($c['child_recipe_id'] !== null) $children[] = ['id' => (int) $c['child_recipe_id'], 'quantity' => $qty];
        }
        if (!$materials && !$children) throw new CustomException('Receptura nie ma składników.', 'errors', 422);
        $data = array_filter(['material_data' => $materials, 'sfp_data' => $children, 'preparation_type' => array_map(fn($x) => [
            'id_preparation' => (int) $x['id_preparation'], 'duration_second' => (int) $x['duration_second']], $this->repo->preparations($recipeId))]);
        // Zmiana receptury tą samą drogą co edycja (wersja « obowiązuje od », spłaszczenie, etykieta, koszt → marże) + decyzja.
        $this->tx->execute(function () use ($recipeId, $data, $productId, $factor, $before, $userId) {
            if (!$this->recipes->update($recipeId, $data)) throw new CustomException('UPDATE_RECIPE_ERROR', 'errors', 500);
            $this->repo->decide($productId, $recipeId, 'scaled', $factor, $before, $userId);
        });
        return ['id' => $productId, 'decision' => 'scaled', 'cost_before' => $before, 'cost_after' => $this->repo->cost($productId)];
    }

    /** Wiersz listy : masa składników vs sztuka i karton, powód, koszt po × sztuk w kartonie, blokada. */
    private static function present(array $r): array
    {
        $ppc = (int) $r['ppc'];
        $weight = (float) $r['weight'];
        $mass = (float) $r['mass'];
        $cost = (float) $r['cost'];
        $blocked = null;
        if ((int) $r['shared'] > 1) $blocked = 'Receptura wspólna dla ' . (int) $r['shared'] . ' produktów — popraw ją ręcznie w edycji receptury.';
        elseif ((int) $r['used_in'] > 0) $blocked = 'Receptura jest składnikiem innej receptury — popraw ją ręcznie w edycji receptury.';
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'id_recipe' => (int) $r['id_recipe'], 'ppc' => $ppc, 'weight' => $weight,
            'mass' => round($mass), 'carton_weight' => round($weight * $ppc), 'piece_ratio' => $weight > 0 ? round($mass / $weight, 2) : null,
            'from_wizard' => (bool) $r['from_wizard'], 'created' => (string) $r['create_timestamp'],
            'reason' => ((bool) $r['from_wizard'] ? 'utworzony w kreatorze · ' : '') . 'masa składników ' . number_format($mass, 0, ',', ' ') . ' g ≈ '
                . ($weight > 0 && abs($mass / $weight - 1) < abs($mass / ($weight * $ppc) - 1) ? '1 szt.' : 'mniej niż karton') . ' (karton : ' . number_format($weight * $ppc, 0, ',', ' ') . ' g)',
            'cost' => round($cost, 2), 'cost_scaled' => round($cost * $ppc, 2), 'blocked' => $blocked];
    }
}
