<?php
declare(strict_types=1);

/**
 * Un faux ERP pour la fidélité : les routes que src/fidelite.php appelle, avec les formes de l'openapi de l'ERP
 * (test.tfbuddy.com/docs/openapi.json, lu le 13/11/2026) et les règles qu'elle documente. Il sert aux tests
 * (bin/tests_fidelite.php) et aux captures de l'écran (docs/maquettes/fidelite-bingo/code-*.png). Les clients sont
 * ILLUSTRÉS : une population tirée d'une graine fixe, jour par jour, rien de réel.
 *
 *   $erp = new FidFauxErp(['clients' => 600]);  $GLOBALS['FID_ERP'] = $erp;
 *
 * Il garde ce qu'on lui écrit (`$erp->ecrits`) et refuse comme le vrai : revision périmée → 409 ; carte figée dont on
 * change le barème → 409 ; code de bon déjà pris → 422.
 */
final class FidFauxErp
{
    public array $ecrits = [];
    public array $cartes = [];
    public array $campagnes = [];
    public array $codes = [];          // campagne → [ {id, code, id_customer, status, valid_to} ]
    private array $clients = [];
    private const PRENOMS = ['Julie', 'Thomas', 'Marc', 'Sofia', 'Claire', 'Nadia', 'Emma', 'Lucas', 'Inès', 'Hugo', 'Léa', 'Louis', 'Chloé', 'Nathan',
        'Manon', 'Arthur', 'Camille', 'Jules', 'Sarah', 'Victor', 'Laura', 'Adam', 'Zoé', 'Noah', 'Alice', 'Tom', 'Elise', 'Simon', 'Lina', 'Maxime'];
    private const NOMS = ['Verhaegen', 'Renard', 'Dubois', 'Lambert', 'Goossens', 'Bertrand', 'Peeters', 'Martin', 'Janssens', 'Leroy', 'Maes',
        'Dupont', 'Claes', 'Mertens', 'Willems', 'Lemaire', 'Simon', 'Wouters', 'Collard', 'Michel'];
    public const SHOPS = [2 => 'Atelier by Berlo - Corbais', 3 => 'Atelier by - Gosselies', 4 => 'Atelier by - Halle', 5 => 'Atelier by - Sombreffe'];

    public function __construct(array $o = [])
    {
        $n = (int) ($o['clients'] ?? 600);
        $this->cartes = [
            12 => ['id' => 12, 'code' => 'points-atelier', 'name' => 'Carte L’Atelier', 'version' => 1, 'revision' => 7, 'terms_locked' => true, 'active' => 1,
                'scope' => 'NETWORK', 'shop_ids' => [2, 3, 4, 5], 'starts_at' => '2026-01-04 23:00:00', 'ends_at' => null, 'sort_order' => 0,
                'color_tint' => '#F8E2CB', 'color_fill' => '#8D1D2C', 'color_accent' => '#F2C9A0', 'reward_label' => 'Bon de 5 €', 'image_path' => '',
                'stamps_required' => 1, 'reward_validity_days' => 60, 'keywords' => ['tout'], 'reward_product_ids' => [502],
                'points_config' => ['stamps_enabled' => false, 'points_enabled' => true, 'points_per_slice' => 1, 'slice_cents' => 100,
                    'voucher_validity_days' => 60, 'points_validity_days' => 365, 'shop_coefficients' => ['2' => '1', '3' => '1', '4' => '1', '5' => '1'],
                    'bonuses' => [['name' => 'Jours creux', 'days' => [1, 2, 3], 'from' => '14:00', 'to' => '17:00', 'coefficient' => '2'],
                        ['name' => 'Fin de journée', 'days' => [0, 1, 2, 3, 4, 5], 'from' => '17:30', 'to' => '19:00', 'coefficient' => '2']],
                    'tiers' => [['points' => 100, 'voucher_cents' => 500]]]],
        ];
        foreach ([[3, 'Carte Pain', 'pain', 10, '#9a5b16', '1 pain offert'], [4, 'Carte Viennoiserie', 'viennoiserie', 10, '#eb6834', '1 croissant offert'],
            [5, 'Carte Café', 'cafe', 8, '#5c4033', '1 café offert'], [6, 'Carte Tarte', 'tarte', 6, '#c2336a', '1 part de tarte offerte']] as [$id, $nom, $code, $t, $c, $r]) {
            $this->cartes[$id] = ['id' => $id, 'code' => $code, 'name' => $nom, 'version' => 1, 'revision' => 3, 'terms_locked' => true, 'active' => 1, 'scope' => 'NETWORK',
                'shop_ids' => [2, 3, 4, 5], 'starts_at' => '2025-09-01 00:00:00', 'ends_at' => null, 'stamps_required' => $t, 'reward_label' => $r,
                'reward_validity_days' => 30, 'color_fill' => $c, 'points_config' => null];
        }
        foreach ([[41, 'Bingo hiver · 2 €', 'ACTIVE'], [45, 'Bingo hiver · 10 €', 'DRAFT'], [51, 'Bingo printemps · 2 €', 'DRAFT']] as [$id, $nom, $st]) {
            $this->campagnes[$id] = ['id' => $id, 'name' => $nom, 'status' => $st, 'code_type' => 'UNIQUE', 'requires_customer' => true];
            $this->codes[$id] = [];
        }
        // La population : six comportements, un magasin chacun (un sur dix passe aussi dans un second).
        mt_srand((int) ($o['graine'] ?? 20261113));
        $poids = [2 => 45, 3 => 22, 4 => 20, 5 => 13];
        for ($i = 0; $i < $n; $i++) {
            $u = mt_rand(0, 99);
            [$type, $rythme] = $u < 9 ? ['H', 4.5] : ($u < 38 ? ['R', 1.6] : ($u < 63 ? ['O', 0.45] : ($u < 75 ? ['X', 1.4] : ($u < 88 ? ['N', 1.1] : ['E', 0.05]))));
            $t = mt_rand(1, 100); $home = 2; $acc = 0;
            foreach ($poids as $s => $p) { $acc += $p; if ($t <= $acc) { $home = $s; break; } }
            $id = 10000 + $i;
            $this->clients[$id] = ['id' => $id, 'name' => self::PRENOMS[$i % count(self::PRENOMS)], 'surname' => self::NOMS[($i * 7) % count(self::NOMS)],
                'home' => $home, 'autre' => mt_rand(0, 9) === 0 ? ($home === 2 ? 3 : 2) : null, 'type' => $type, 'rythme' => $rythme,
                'panier' => [5.6, 8.4, 11.2, 14.8, 24.5][mt_rand(0, 4)], 'stop' => $type === 'X' ? '2026-' . (mt_rand(0, 1) ? '12-' . str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT) : '11-30') : null,
                'debut' => $type === 'N' ? '2026-' . ['10', '11', '12'][mt_rand(0, 2)] . '-' . str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT) : '2025-06-01',
                'graine' => mt_rand(1, 1 << 30)];
        }
        mt_srand();
    }

    /** Un nombre stable entre 0 et 1 pour (client, jour, sel). */
    private function h(int $c, string $jour, int $sel): float { return (crc32($c . '|' . $jour . '|' . $sel) % 100000) / 100000; }

    /** Les points (et le montant, le nombre de tickets) d'un client un jour, dans un magasin. */
    private function jour(array $c, string $jour, int $shop): array
    {
        if ($jour < $c['debut'] || ($c['stop'] !== null && $jour > $c['stop'])) { return [0, 0.0, 0]; }
        $dow = (int) date('N', (int) strtotime($jour)) - 1;
        $saison = $jour >= '2026-11-23' ? 1.3 : 1.0;                      // l'hiver du bingo : on revient plus souvent
        $md = substr($jour, 5);
        $fete = ($md >= '12-21' && $md <= '12-24') || in_array($md, ['12-05', '12-06', '01-06', '02-02'], true) ? 1.6 : ($md >= '12-28' || $md <= '01-03' ? 0.55 : 1.0);
        $p = $c['rythme'] / 7 * $saison * $fete * ($dow === 6 ? 0.6 : 1.0);
        if ($this->h($c['id'], $jour, 1) >= $p) { return [0, 0.0, 0]; }
        $ici = $c['autre'] !== null && $this->h($c['id'], $jour, 2) < 0.2 ? $c['autre'] : $c['home'];
        if ($ici !== $shop) { return [0, 0.0, 0]; }
        $m = round($c['panier'] * (0.6 + 0.8 * $this->h($c['id'], $jour, 3)), 2);
        $x2 = in_array($dow, [1, 2, 3], true) && $this->h($c['id'], $jour, 4) < 0.25 ? 2 : 1;
        $coef = (float) ($this->cartes[12]['points_config']['shop_coefficients'][(string) $shop] ?? 1);
        return [(int) floor(floor($m) * $x2 * $coef), $m, 1];
    }

    private function periode(array $c, string $du, string $au, int $shop): array
    {
        $pts = 0; $m = 0.0; $n = 0;
        for ($d = $du; $d <= $au; $d = date('Y-m-d', (int) strtotime($d . ' +1 day'))) { [$p, $x, $k] = $this->jour($c, $d, $shop); $pts += $p; $m += $x; $n += $k; }
        return [$pts, $m, $n];
    }

    private function solde(array $c): int { return (int) (($c['graine'] % 1000) / 1000 * ($c['rythme'] * 70 + 40)); }

    private function fiche(array $c, string $du, string $au, int $shop): array
    {
        [$pts, $m, $n] = $this->periode($c, $du, $au, $shop);
        $solde = $this->solde($c);
        $tiers = array_map(static fn ($t) => ['points' => $t['points'], 'voucher_cents' => $t['voucher_cents'], 'reached' => $solde >= $t['points'], 'remaining' => max(0, $t['points'] - $solde)],
            $this->cartes[12]['points_config']['tiers']);
        $der = null;
        for ($d = $au; $d >= date('Y-m-d', (int) strtotime($au . ' -40 days')); $d = date('Y-m-d', (int) strtotime($d . ' -1 day'))) {
            if ($this->jour($c, $d, $shop)[2]) { $der = $d . 'T' . sprintf('%02d:%02d:00', 7 + ($c['graine'] % 11), ($c['graine'] >> 3) % 60) . '+01:00'; break; }
        }
        return ['id' => $c['id'], 'name' => $c['name'], 'surname' => $c['surname'], 'active' => true, 'blocked' => false,
            'programs' => [['id' => 12, 'name' => 'Carte L’Atelier', 'active' => true,
                'global_progress' => ['stamps' => null, 'points' => ['balance' => $solde, 'earned_total' => $solde + 300, 'spent_total' => 300, 'expired_remaining' => 0, 'tiers' => $tiers]],
                'shop_activity' => ['stamps_earned' => 0, 'points_earned' => $pts]]],
            'shop_activity' => ['transactions' => $n, 'gross_sales_before_corrections' => number_format($m, 2, '.', ''), 'deferred_transactions' => 0,
                'undated_transactions_all_time' => 0, 'recognitions' => $n, 'last_transaction_at' => $der, 'last_recognition_at' => $der]];
    }

    private function membresDe(int $shop): array
    {
        return array_values(array_filter($this->clients, static fn ($c) => $c['home'] === $shop || $c['autre'] === $shop));
    }

    public function __invoke(string $method, string $path, ?array $body): array
    {
        $u = parse_url($path); parse_str($u['query'] ?? '', $q); $p = $u['path'];
        $ok = static fn ($corps, int $code = 200) => ['code' => $code, 'corps' => $corps, 'erreur' => null];
        $ko = static fn (int $code, string $msg, array $champs = []) => ['code' => $code, 'corps' => ['status' => 'error', 'description' => $msg] + ($champs ? ['code' => $msg, 'errors' => $champs] : []),
            'erreur' => 'HTTP ' . $code . ' : ' . $msg . ($champs ? ' — ' . implode(' ; ', array_map(static fn ($k, $v) => $k . ' : ' . $v, array_keys($champs), $champs)) : '')];
        if ($method !== 'GET') { $this->ecrits[] = [$method, $path, $body]; }
        if ($method === 'GET' && $p === '/admin/loyalty/cards') { return $ok(['items' => array_values($this->cartes), 'pagination' => ['page' => 1, 'limit' => 100, 'total' => count($this->cartes), 'pages' => 1]]); }
        if (preg_match('#^/admin/loyalty/cards/(\d+)$#', $p, $m)) {
            $id = (int) $m[1];
            if (!isset($this->cartes[$id])) { return $ko(404, 'card not found'); }
            if ($method === 'GET') { return $ok($this->cartes[$id]); }
            $c = $this->cartes[$id];
            if ((int) ($body['revision'] ?? 0) !== (int) $c['revision']) { return $ko(409, 'revision conflict'); }
            if (!empty($c['terms_locked']) && array_key_exists('points_config', $body)) { return $ko(409, 'used card terms are locked'); }
            unset($body['revision']);
            $this->cartes[$id] = array_merge($c, $body, ['revision' => $c['revision'] + 1]);
            return $ok($this->cartes[$id]);
        }
        if ($method === 'POST' && $p === '/admin/loyalty/cards') {
            foreach (['code', 'name', 'starts_at', 'shop_ids'] as $k) { if (!isset($body[$k])) { return $ko(422, $k . ' required'); } }
            // Ce que l'ERP réel a répondu le 13/11/2026 : « code : INVALID_CODE ; reward_product_ids : SELECT_AT_LEAST_ONE ».
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', (string) $body['code'])) { return $ko(422, 'LOYALTY_INVALID_CONFIGURATION', ['code' => 'INVALID_CODE']); }
            if (empty($body['reward_product_ids']) && empty($body['reward_portion_ids'])) { return $ko(422, 'LOYALTY_INVALID_CONFIGURATION', ['reward_product_ids' => 'SELECT_AT_LEAST_ONE']); }
            $ids = !empty($body['earning_product_ids']) || !empty($body['earning_category_ids']) || !empty($body['earning_category_group_ids']);
            if ($ids === !empty($body['keywords'])) { return $ko(422, 'earning IDs or normalized keywords are required and cannot mix'); }
            foreach ($this->cartes as $c) { if ($c['code'] === $body['code'] && (int) ($c['version'] ?? 1) === (int) ($body['version'] ?? 1)) { return $ko(409, 'code/version already exists'); } }
            $id = max(array_keys($this->cartes)) + 1;
            $this->cartes[$id] = $body + ['id' => $id, 'revision' => 1, 'terms_locked' => false];
            return $ok(['status' => 'success', 'data' => $this->cartes[$id]], 201);
        }
        if ($method === 'GET' && $p === '/admin/loyalty/options') {
            return $ok(['shops' => array_map(static fn ($id, $n) => ['id' => $id, 'name' => $n], array_keys(self::SHOPS), self::SHOPS), 'products' => [['id' => 501, 'name' => 'Baguette tradition'], ['id' => 502, 'name' => 'Croissant au beurre'], ['id' => 503, 'name' => 'Café'], ['id' => 504, 'name' => 'Tartelette aux fruits']], 'reward_portions' => [], 'categories' => [['id' => 31, 'name' => 'Pains'], ['id' => 32, 'name' => 'Viennoiseries'], ['id' => 33, 'name' => 'Pâtisseries']], 'category_groups' => []]);
        }
        if ($method === 'POST' && $p === '/admin/loyalty/simulate') {
            $cfg = $body['config'] ?? null; $shop = (string) ($body['shop_id'] ?? '');
            if (!is_array($cfg) || empty($cfg['points_enabled'])) { return $ko(422, 'points must be enabled'); }
            $t = (string) ($body['local_datetime'] ?? ''); $dow = (int) date('N', (int) strtotime(substr($t, 0, 10))) - 1; $hm = substr($t, 11, 5);
            $slices = intdiv((int) $body['amount_cents'], (int) $cfg['slice_cents']);
            $sc = (float) ($cfg['shop_coefficients'][$shop] ?? 0); $bc = 1.0;
            foreach ($cfg['bonuses'] as $b) { if (in_array($dow, $b['days'], true) && $hm >= $b['from'] && $hm < $b['to']) { $bc = max($bc, (float) $b['coefficient']); } }
            return $ok(['points' => (int) floor($slices * $cfg['points_per_slice'] * $sc * $bc), 'base_cents' => (int) $body['amount_cents'], 'slices' => $slices,
                'shop_coefficient' => rtrim(rtrim(number_format($sc, 4, '.', ''), '0'), '.'), 'bonus_coefficient' => rtrim(rtrim(number_format($bc, 4, '.', ''), '0'), '.'), 'local_time' => str_replace('T', ' ', $t) . ':00']);
        }
        if ($method === 'POST' && $p === '/admin/voucher-campaigns') {
            if (empty($body['name'])) { return $ko(422, 'name required'); }
            $id = max(array_keys($this->campagnes) ?: [100]) + 1;
            $this->campagnes[$id] = ['id' => $id, 'name' => $body['name'], 'status' => $body['status'] ?? 'DRAFT', 'code_type' => $body['code_type'] ?? 'SHARED', 'requires_customer' => !empty($body['requires_customer'])];
            $this->codes[$id] = [];
            return $ok(['status' => 'success', 'data' => $this->campagnes[$id]], 201);
        }
        if ($method === 'GET' && $p === '/admin/voucher-campaigns') {
            $L = array_values(array_filter($this->campagnes, static fn ($c) => !isset($q['name']) || stripos($c['name'], (string) $q['name']) !== false));
            return $ok(['items' => $L, 'pagination' => ['page' => 1, 'limit' => 100, 'total' => count($L), 'pages' => 1]]);
        }
        if (preg_match('#^/admin/voucher-campaigns/(\d+)/codes$#', $p, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') { return $ok(['items' => $this->codes[$id] ?? [], 'pagination' => ['page' => 1, 'limit' => 200, 'total' => count($this->codes[$id] ?? []), 'pages' => 1]]); }
            foreach ($this->codes as $L) { foreach ($L as $k) { if ($k['code'] === ($body['code'] ?? '')) { return $ko(422, 'code already exists'); } } }
            $this->codes[$id][] = ['id' => 900 + count($this->codes[$id]), 'code' => $body['code'], 'id_customer' => $body['id_customer'] ?? null, 'status' => 'ACTIVE', 'valid_to' => $body['valid_to'] ?? null];
            return $ok(['status' => 'success'], 201);
        }
        if ($method === 'GET' && preg_match('#^/shops/(\d+)/loyalty/(customers|statistics)$#', $p, $m)) {
            $shop = (int) $m[1];
            if (!isset(self::SHOPS[$shop])) { return $ko(404, 'shop'); }
            $du = (string) ($q['from'] ?? date('Y-m-01')); $au = (string) ($q['to'] ?? date('Y-m-d'));
            $M = $this->membresDe($shop);
            $periode = ['from' => $du, 'to' => $au, 'timezone' => 'Europe/Brussels', 'from_utc' => $du . ' 00:00:00', 'until_utc' => $au . ' 23:00:00'];
            $scope = ['progress' => 'current_global_program_balance', 'activity' => 'selected_shop_in_period', 'participants' => 'existing_program_accounts_of_shop_members_or_historical_customers', 'recognitions' => 'successful_resolution_requests_not_transactions'];
            if ($m[2] === 'customers') {
                $page = max(1, (int) ($q['page'] ?? 1)); $lim = min(100, max(1, (int) ($q['limit'] ?? 25)));
                $L = array_slice($M, ($page - 1) * $lim, $lim);
                return $ok(['shop_id' => $shop, 'as_of' => date('c'), 'period' => $periode, 'scope' => $scope,
                    'pagination' => ['page' => $page, 'limit' => $lim, 'total' => count($M)], 'items' => array_map(fn ($c) => $this->fiche($c, $du, $au, $shop), $L)]);
            }
            $tk = 0; $pts = 0; $ventes = 0.0; $actifs = 0;
            foreach ($M as $c) { [$a, $b, $n] = $this->periode($c, $du, $au, $shop); $pts += $a; $ventes += $b; $tk += $n; if ($n) { $actifs++; } }
            return $ok(['shop_id' => $shop, 'as_of' => date('c'), 'period' => $periode, 'scope' => $scope,
                'participants' => ['unique_customers' => count($M), 'stamp_customers' => (int) (count($M) * 0.7), 'point_customers' => count($M), 'both_customers' => (int) (count($M) * 0.7), 'active_in_period' => $actifs],
                'programs' => [['id' => 12, 'name' => 'Carte L’Atelier', 'active' => 1, 'customers' => count($M), 'stamp_customers' => 0, 'point_customers' => count($M), 'customers_active_in_shop' => $actifs]],
                'recognitions' => ['count' => (int) round($tk * 1.08), 'unique_customers' => $actifs, 'devices' => $shop === 2 ? 3 : 2, 'short_code' => (int) round($tk * 1.05), 'legacy_fid1' => (int) round($tk * 0.03), 'by_device' => []],
                'shop_activity' => ['transactions' => $tk, 'gross_sales_before_corrections' => number_format($ventes, 2, '.', ''), 'deferred_transactions' => 0,
                    'undated_transactions_all_time' => 0, 'purchasing_customers' => $actifs, 'stamps_earned' => 0, 'points_earned' => $pts]]);
        }
        return $ko(404, 'route inconnue du faux ERP : ' . $method . ' ' . $p);
    }
}
