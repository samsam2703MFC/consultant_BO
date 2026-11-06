<?php
namespace App\Services\Dashboard;

use App\Core\Api\Workshop\Validator;
use App\Exceptions\CustomException;
use App\Repositories\Dashboard\GrowthActionRepository;
use App\Repositories\Dashboard\ProfitabilityRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Finances\StorePrice\StoreSellabilityService;

/**
 * Actions de développement du chiffre d'affaires d'un groupe de produits.
 * Par mois : pièces = pièces par magasin × magasins ; CA = pièces × prix de vente prévu ;
 * marge = CA × marge cible. Retour sur investissement : délai = investissement ÷ marge mensuelle,
 * ROI sur n mois = (marge × n − investissement) ÷ investissement.
 * Pour le groupe : CA mensuel actuel face au CA qu'il faut pour tenir l'objectif de coût du
 * personnel, et situation « si les actions sont développées » (actions non abandonnées).
 */
final class GrowthActionService
{
    public const STATUSES = ['idea', 'planned', 'in_progress', 'developed', 'dropped'];
    private const FIELDS = ['section_id', 'title', 'final_product_id', 'product_name', 'pieces_per_shop_month', 'shops', 'selling_price_eur',
        'target_margin_pct', 'investment_eur', 'start_month', 'status', 'owner_user', 'notes'];

    public function __construct(
        private GrowthActionRepository $actions,
        private ProfitabilityService $profitability,
        private ProfitabilityRepository $profitRepo,
        private ParamRepository $params,
        private StoreSellabilityService $sellability,
    ) {
    }

    /** Calcul d'une action (sans base). */
    public static function metrics(array $a, int $horizonMonths = 36): array
    {
        $pieces = (float) $a['pieces_per_shop_month'] * (int) $a['shops'];
        $revenue = $pieces * (float) $a['selling_price_eur'];
        $margin = $revenue * (float) $a['target_margin_pct'] / 100;
        $investment = (float) $a['investment_eur'];
        $roi = fn(int $months) => $investment > 0 ? ($margin * $months - $investment) / $investment * 100 : null;
        return [
            'pieces_month' => $pieces,
            'revenue_month' => $revenue,
            'margin_month' => $margin,
            'revenue_year' => $revenue * 12,
            'margin_year' => $margin * 12,
            'payback_months' => $investment <= 0 ? 0.0 : ($margin > 0 ? $investment / $margin : null),
            'roi_12' => $roi(12),
            'roi_horizon' => $roi($horizonMonths),
            'horizon_months' => $horizonMonths,
        ];
    }

    /** Tout ce que la fenêtre d'un groupe affiche : situation mensuelle, actions, produits, valeurs par défaut. */
    public function group(int $sectionId, string $period = 'month'): array
    {
        $section = $this->actions->section($sectionId) ?? throw new CustomException('Nie znaleziono grupy.', 'errors', 404);
        $horizon = $this->horizon();
        $actions = array_map(fn($a) => $this->present($a, $horizon), $this->actions->list($sectionId));
        $overview = $this->profitability->overview($period);
        $group = null;
        foreach ($overview['groups'] as $g) if ($g['id'] === $sectionId) $group = $g;
        $fractions = array_sum($overview['period']['months']);
        $monthCount = count($overview['period']['months']);

        $context = null;
        if ($group) {
            $revenueMonth = $fractions > 0 ? $group['revenue'] / $fractions : 0.0;
            $staffMonth = $monthCount > 0 ? $group['staff_full'] / $monthCount : 0.0;
            $neededMonth = $staffMonth / ($group['staff_target'] / 100);
            $active = array_filter($actions, fn($a) => $a['status'] !== 'dropped');
            $byStatus = array_fill_keys(self::STATUSES, 0.0);
            foreach ($actions as $a) $byStatus[$a['status']] += $a['metrics']['revenue_month'];
            $added = array_sum(array_map(fn($a) => $a['metrics']['revenue_month'], $active));
            $revenueIf = $revenueMonth + $added;
            $context = [
                'period_label' => $overview['period']['label'],
                'revenue_month' => $revenueMonth,
                'staff_month' => $staffMonth,
                'staff_target' => $group['staff_target'],
                'staff_pct' => $revenueMonth > 0 ? $staffMonth / $revenueMonth * 100 : null,
                'needed_month' => $neededMonth,
                'gap_month' => max(0.0, $neededMonth - $revenueMonth),
                'actions_revenue_month' => $added,
                'actions_margin_month' => array_sum(array_map(fn($a) => $a['metrics']['margin_month'], $active)),
                'actions_investment' => $investment = array_sum(array_map(fn($a) => $a['investment_eur'], $active)),
                'actions_payback_months' => $investment <= 0 ? 0.0 : (($m = array_sum(array_map(fn($a) => $a['metrics']['margin_month'], $active))) > 0 ? $investment / $m : null),
                'revenue_by_status' => $byStatus,
                'revenue_if' => $revenueIf,
                'staff_pct_if' => $revenueIf > 0 ? $staffMonth / $revenueIf * 100 : null,
                'gap_after' => max(0.0, $neededMonth - $revenueIf),
            ];
        }

        $rate = $this->profitRepo->eurRate() ?: 1.0;
        $productRows = $this->actions->products($sectionId);
        // Belgia (franczyza) : cena fabryczna za sztukę × mnożnik wobec ceny w sklepie — dla produktów grupy i każdego działania.
        $belgium = $this->sellability->forGrowth(array_map(fn($p) => (int) $p['id'], $productRows), $actions);
        foreach ($actions as &$a) $a['belgium'] = $belgium['actions'][$a['id']] ?? null;
        unset($a);
        return [
            'section' => ['id' => (int) $section['id'], 'name' => $section['name']],
            'context' => $context,
            'actions' => $actions,
            'belgium' => ['params' => $belgium['params'], 'synced_at' => $belgium['synced_at']],
            'products' => array_map(fn($p) => [
                'id' => (int) $p['id'],
                'name' => $p['name'],
                'ppc' => (int) $p['ppc'],
                'price_piece_eur' => (int) $p['ppc'] > 0 && $p['standard_price'] !== null ? round((float) $p['standard_price'] / (int) $p['ppc'], 4) : null,
                'material_piece_eur' => (int) $p['ppc'] > 0 && $p['pre_calculated_raw_price_per_carton'] !== null ? round((float) $p['pre_calculated_raw_price_per_carton'] / (int) $p['ppc'] / $rate, 4) : null,
                'target_margin_pct' => $p['target_margin_percentage'] !== null ? (float) $p['target_margin_percentage'] : null,
                'store' => $belgium['products'][(int) $p['id']] ?? null,
            ], $productRows),
            'defaults' => ['shops' => $this->defaultShops(), 'start_month' => date('Y-m', strtotime('first day of next month')), 'horizon_months' => $horizon],
            'users' => $this->actions->users(),
        ];
    }

    /** Résumé par groupe pour le tableau de la page : nombre d'actions actives et CA mensuel visé. */
    public function summaryBySection(): array
    {
        $out = [];
        foreach ($this->actions->list(null) as $a) {
            if ($a['status'] === 'dropped') continue;
            $id = (int) $a['id_section'];
            $out[$id] ??= ['count' => 0, 'revenue_month' => 0.0];
            $out[$id]['count']++;
            $out[$id]['revenue_month'] += self::metrics($a)['revenue_month'];
        }
        return $out;
    }

    public function save(array $input, ?int $id, ?int $userId): array
    {
        $current = $id === null ? null : ($this->actions->find($id) ?? throw new CustomException('Nie znaleziono działania.', 'errors', 404));
        $v = (new Validator($input, $current !== null))->only(self::FIELDS)
            ->integer('section_id', $current === null, 1)
            ->string('title', 150, $current === null)
            ->integer('final_product_id', false, 1, null, true)
            ->string('product_name', 200, false, true)
            ->decimal('pieces_per_shop_month', $current === null, 0, 10_000_000)
            ->integer('shops', $current === null, 1, 100_000)
            ->decimal('selling_price_eur', $current === null, 0, 100_000)
            ->decimal('target_margin_pct', $current === null, -99.99, 99.99)
            ->decimal('investment_eur', false, 0, 100_000_000)
            ->string('start_month', 7, false, true, '/^\d{4}-(0[1-9]|1[0-2])$/', 'INVALID_DATE')
            ->choice('status', self::STATUSES, false)
            ->integer('owner_user', false, 1, null, true)
            ->string('notes', 2000, false, true);

        $sectionId = $v->provided('section_id') ? $v->value('section_id') : ($current['id_section'] ?? null);
        if ($v->provided('section_id') && !$this->actions->section($v->value('section_id'))) {
            $v->add('section_id', 'REFERENCE_NOT_FOUND', ['id' => $v->value('section_id')]);
        }
        $productId = $v->provided('final_product_id') ? $v->value('final_product_id') : ($current['id_final_product'] ?? null);
        $productName = $v->provided('product_name') ? $v->value('product_name') : ($current['product_name'] ?? null);
        if ($productId === null && ($productName === null || trim($productName) === '')) {
            $v->add('final_product_id', 'PRODUCT_REQUIRED');
        } elseif ($productId !== null && !$v->hasError('final_product_id')) {
            $product = $this->actions->product($productId);
            if (!$product) $v->add('final_product_id', 'REFERENCE_NOT_FOUND', ['id' => $productId]);
            elseif ($sectionId !== null && (int) $product['id_section'] !== (int) $sectionId) $v->add('final_product_id', 'PRODUCT_NOT_IN_GROUP');
        }
        if ($v->value('owner_user') !== null && !$this->actions->userExists($v->value('owner_user'))) {
            $v->add('owner_user', 'REFERENCE_NOT_FOUND', ['id' => $v->value('owner_user')]);
        }
        $v->throwIfInvalid();

        $data = $v->clean();
        foreach (['section_id' => 'id_section', 'final_product_id' => 'id_final_product'] as $field => $column) {
            if (array_key_exists($field, $data)) { $data[$column] = $data[$field]; unset($data[$field]); }
        }
        // Un produit existant remplace un nom de nouveau produit, et inversement.
        if (array_key_exists('id_final_product', $data) && $data['id_final_product'] !== null) $data['product_name'] = null;
        if (array_key_exists('product_name', $data) && $data['product_name'] !== null && !array_key_exists('id_final_product', $data)) $data['id_final_product'] = null;

        if ($current === null) {
            $data['created_by'] = $userId;
            $data['investment_eur'] ??= 0;
            $data['status'] ??= 'idea';
            $id = $this->actions->insert($data);
        } else {
            $this->actions->update($id, $data);
        }
        foreach ($this->actions->list((int) ($data['id_section'] ?? $current['id_section'])) as $row) {
            if ((int) $row['id'] === $id) return $this->present($row, $this->horizon());
        }
        throw new CustomException('Nie znaleziono działania.', 'errors', 404);
    }

    private function present(array $a, int $horizon): array
    {
        return [
            'id' => (int) $a['id'],
            'section_id' => (int) $a['id_section'],
            'title' => $a['title'],
            'final_product_id' => $a['id_final_product'] !== null ? (int) $a['id_final_product'] : null,
            'product' => $a['id_final_product'] !== null ? $a['erp_product_name'] : $a['product_name'],
            'is_new_product' => $a['id_final_product'] === null,
            'pieces_per_shop_month' => (float) $a['pieces_per_shop_month'],
            'shops' => (int) $a['shops'],
            'selling_price_eur' => (float) $a['selling_price_eur'],
            'target_margin_pct' => (float) $a['target_margin_pct'],
            'investment_eur' => (float) $a['investment_eur'],
            'start_month' => $a['start_month'],
            'status' => $a['status'],
            'owner_user' => $a['owner_user'] !== null ? (int) $a['owner_user'] : null,
            'owner' => $a['owner_name'],
            'notes' => $a['notes'],
            'metrics' => self::metrics($a, $horizon),
        ];
    }

    private function defaultShops(): int
    {
        $param = (int) $this->params->get('growth.default_shops', '0');
        return $param > 0 ? $param : max(1, $this->actions->activeShops());
    }

    private function horizon(): int
    {
        return max(1, min(120, (int) $this->params->get('growth.roi_horizon_months', '36')));
    }
}
