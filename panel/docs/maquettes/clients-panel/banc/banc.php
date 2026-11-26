<?php
declare(strict_types=1);
/* Banc d'essai de Vendre › Clients (25/11/2026) : le vrai module serveur (pclRoute) et le vrai écran (clients.js), devant
 * un TF Buddy simulé — magasin 3, Gosselies, données fictives (les entreprises et les bureaux des maquettes). Sans base.
 *   BANC_ACCES=panel : les routes /panel/shops/{s}/… répondent (ce que l'accès de service R1 ouvrira) ;
 *   sinon elles refusent les comptes du cockpit (404 ORDER_NOT_FOUND, mesuré le 24/11/2026) et l'écran se rabat.
 *   Le compte TF Buddy du magasin se connecte au banc avec le PIN 4321 et le code SMS 000000 (panel simulé, /tfb-sim/).
 *     PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8098 panel/docs/maquettes/clients-panel/banc/banc.php   (depuis la racine du dépôt)
 *   puis http://127.0.0.1:8098/banc/ */
define('RACINE', dirname(__DIR__, 5));
$u = parse_url($_SERVER['REQUEST_URI']);
$chemin = $u['path'];
// Le panel simulé : la connexion d'un employé du magasin (téléphone, PIN 4321, code SMS 000000 : valeurs fictives du banc).
if (str_starts_with($chemin, '/tfb-sim/panel/auth/')) {
    header('Content-Type: application/json');
    $b = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $jwt = static fn () => 'eyJhbGciOiJub25lIn0.' . rtrim(strtr(base64_encode(json_encode(['exp' => time() + 300, 'shop' => 3])), '+/', '-_'), '=') . '.banc-magasin';
    $r = match (basename($chemin)) {
        // 60 s entre deux SMS, comme TF Buddy (PANEL_AUTH_RESEND_WAIT) ; le PIN 9999 simule le blocage (PANEL_AUTH_LIMIT).
        'login' => ($b['pin'] ?? '') === '9999' ? [429, ['status' => 429, 'error' => 'PANEL_AUTH_LIMIT']]
            : (($b['pin'] ?? '') !== '4321' ? [401, ['status' => 401, 'error' => 'PANEL_INVALID_CREDENTIALS']]
            : ((int) @file_get_contents(sys_get_temp_dir() . '/banc-tfb-sms') > time() - 60 ? [429, ['status' => 429, 'error' => 'PANEL_AUTH_RESEND_WAIT']]
            : (file_put_contents(sys_get_temp_dir() . '/banc-tfb-sms', (string) time()) !== false ? [202, ['status' => 202, 'challenge' => 'banc-challenge']] : [503, []]))),
        'confirm' => ($b['code'] ?? '') === '000000' ? [200, ['status' => 200, 'token' => $jwt(), 'refresh_token' => 'banc-r' . time(), 'expires_at' => time() + 43200]] : [401, ['status' => 401, 'error' => 'PANEL_SMS_CODE_INVALID']],
        'refresh' => [200, ['status' => 200, 'token' => $jwt(), 'refresh_token' => 'banc-r' . time(), 'expires_at' => time() + 43200]],
        default => [200, ['status' => 200]],
    };
    http_response_code($r[0]); echo json_encode($r[1]); return true;
}
// Les fichiers : le banc, l'écran du dashboard, le design system.
if (!str_contains($chemin, '/api/cockpit')) {
    $f = match (true) {
        $chemin === '/banc/' || $chemin === '/banc' => __DIR__ . '/index.html',
        str_starts_with($chemin, '/banc/') => __DIR__ . '/' . basename($chemin),
        str_starts_with($chemin, '/dashboard/') => RACINE . '/panel/public/dashboard/' . basename($chemin),
        $chemin === '/bureau/' || $chemin === '/bureau' => RACINE . '/panel/public/bureau/index.html',
        str_starts_with($chemin, '/bureau/') => RACINE . '/panel/public/bureau/' . basename($chemin),
        str_starts_with($chemin, '/assets/') => RACINE . '/shared/public' . $chemin,
        default => '',
    };
    if ($f === '' || !is_file($f) || str_contains($chemin, '..')) { http_response_code(404); echo 'introuvable'; return true; }
    $types = ['html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript', 'otf' => 'font/otf', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($f); return true;
}

// Une vraie base si COCKPIT_DB_NAME est donnée (les bureaux en développement gardent leurs demandes), sinon une doublure.
if (getenv('COCKPIT_DB_NAME')) { require RACINE . '/shared/src/Db.php'; }
else { eval('final class Db { public static function exec(string $s, array $a = []): int { return 1; } public static function row(string $s, array $a = []): ?array { return null; } public static function rows(string $s, array $a = []): array { return []; } public static function pdo() { return new class { public function lastInsertId() { return "1"; } }; } }'); }
function accesNomMagasin(string $s): string { return 'Gosselies'; }
/* Les bons (module Promotions, marketing/src/promos_*.php) : une promotion du magasin avec un bon à codes personnels. */
function pmMiennes(int $shop): array { return [[['panelId' => 321, 'nom' => '−20 % sur le premier lunch', 'description' => 'une fois, sur le webshop ou en caisse', 'statut' => 'en_caisse', 'bon' => ['campagne' => 900]]], null]; }
function pmMienne(int $shop, int $id): ?array { return $id === 321 ? pmMiennes($shop)[0][0] : null; }
function pbLire(int $camp, array $c = []): ?array { return ['campagne' => $camp, 'cible' => 'clients', 'code' => null]; }
function pbGenerer(int $n = 8, string $p = ''): string { $c = ''; for ($i = 0; $i < $n; $i++) { $c .= 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)]; } return $p . $c; }
final class ErpApi { public static function disponible(): bool { return true; } public static function ecrire(string $m, string $p, ?array $b = null): array { return ['code' => 200, 'corps' => ['id' => 5], 'erreur' => null]; } public static function sondeGet(string $p): array { return ['code' => 404, 'corps' => null, 'erreur' => 'banc']; } }
function setting(string $k, mixed $d = null): mixed { return $d; }
function body(): array { static $b = null; if ($b === null) { $b = json_decode(file_get_contents('php://input') ?: '[]', true) ?: []; } return $b; }
function journalAdd(string $a, string $k, ?string $p, string $m): void { error_log('journal : ' . $m); }

/* Le TF Buddy simulé. */
function bancDonnees(): array
{
    static $D = null;
    if ($D !== null) { return $D; }
    mt_srand(3);
    $ent = [
        ['Hainaut Assurances', 'BE0412338901', 'A', 'standard', 'delivery', 46, 5040, [2500, 1550], 'Charleroi', 'bd Tirou', '45'],
        ['Centre de formation Horizon', 'BE0789114256', 'A', 'standard', 'delivery', 61, 7980, null, 'Jumet', 'rue de la Station', '14'],
        ['Fiduciaire Delvaux & associés', 'BE0455902117', 'A', 'vip', 'both', 52, 6120, [3000, 820], 'Gosselies', 'rue Circulaire', '2'],
        ['Techni-Sol SRL', 'BE0634551090', 'B', 'standard', 'delivery', 34, 2410, null, 'Jumet', 'chaussée de Bruxelles', '210'],
        ['Atelier numérique Charleroi', 'BE0711004663', 'B', 'standard', 'delivery', 29, 2215, [1500, 1260], 'Charleroi', 'quai de Brabant', '8'],
        ['Bureau d’études Lambot', 'BE0567203448', 'B', 'standard', 'delivery', 22, 1940, null, 'Courcelles', 'rue du Temple', '33'],
        ['Brasserie du Centre', 'BE0598771205', 'B', 'restaurant', 'pickup', 18, 3360, null, 'Gosselies', 'place Albert Ier', '5'],
        ['Biolab Hainaut', 'BE0822660391', 'C', 'standard', 'delivery', 9, 420, null, 'Gosselies', 'avenue Jean Mermoz', '18'],
        ['Garage Dumont', 'BE0477318552', 'C', 'standard', 'delivery', 6, 312, [500, 520], 'Courcelles', '', ''],
        ['Hôtel du Parc', '', 'D', 'hotel', 'pickup', 2, 96, null, 'Charleroi', 'boulevard Audent', '1'],
    ];
    $D = ['b2b' => [], 'b2c' => [], 'riche' => [], 'bureaux' => []];
    foreach ($ent as $i => $e) {
        $id = 800 + $i;
        $c = ['id' => $id, 'id_main_shop' => 3, 'is_b2b' => 1, 'name' => null, 'surname' => null, 'company_name' => $e[0], 'tax_number' => $e[1], 'can_deferral' => $e[7] ? 1 : 0,
            'street' => $e[9], 'street_number' => $e[10], 'city' => $e[8], 'zip' => '60' . (40 + $i), 'phone' => $i === 9 ? '' : '+32 71 ' . (20 + $i) . ' 11 ' . (30 + $i), 'email' => $i === 8 ? '' : 'compta@' . strtolower(preg_replace('/\W+/', '', $e[0])) . '.be',
            'payment_terms' => $e[7] ? 14 : 0, 'personal_discount_percent' => $i < 3 ? 5 : 0, 'create_timestamp' => '2025-0' . (1 + $i % 9) . '-12 10:00:00', 'password_hash' => '$2y$10$secret', 'peppol_identifier' => null];
        $D['b2b'][$id] = $c;
        $D['riche'][] = ['id' => $id, 'company_name' => $e[0], 'absolute_name' => $e[0], 'tax_number' => $e[1], 'phone' => $c['phone'], 'email' => $c['email'], 'street' => $e[9], 'street_number' => $e[10], 'city' => $e[8], 'zip' => $c['zip'],
            'orders_12m' => $e[5], 'ca_12m' => $e[6], 'avg_ticket' => round($e[6] / $e[5], 2), 'last_order_at' => '2026-11-' . str_pad((string) (24 - $i), 2, '0', STR_PAD_LEFT), 'can_deferral' => (bool) $e[7],
            'profile' => ['score' => $e[2], 'client_type' => $e[3], 'service_mode' => $e[4], 'credit_enabled' => (bool) $e[7], 'credit_limit' => $e[7][0] ?? 0],
            'recurrence' => ['status' => $e[5] > 5 ? 'regulier' : 'inactif', 'median_days' => max(3, intdiv(300, $e[5]))],
            'credit' => $e[7] ? ['enabled' => true, 'limit' => $e[7][0], 'used' => $e[7][1], 'percent_used' => round(100 * $e[7][1] / $e[7][0], 1)] : ['enabled' => false]];
        $D['profil'][$id] = ['score' => $e[2], 'client_type' => $e[3], 'service_mode' => $e[4], 'credit_enabled' => $e[7] ? 1 : 0, 'credit_limit' => $e[7][0] ?? null, 'status' => 'active', 'joined_at' => '2025-03-01'];
    }
    $pre = ['Isabelle', 'Marc', 'Sophie', 'Laura', 'Céline', 'Anne', 'Thomas', 'Julie', 'Nicolas', 'Camille', 'Pierre', 'Sarah', 'Antoine', 'Emma', 'Lucas', 'Chloé', 'Hugo', 'Léa', 'Louis', 'Manon'];
    $nom = ['Bertrand', 'Gilles', 'Vandenberghe', 'Martin', 'Dubois', 'Peeters', 'Lambert', 'Dupont', 'Renard', 'Simon', 'Claes', 'Leroy', 'Hermans', 'Mertens', 'Jacobs', 'Wouters', 'Goossens', 'Lemaire', 'Maes', 'Willems'];
    for ($i = 0; $i < 120; $i++) {
        $id = 1000 + $i;
        $D['b2c'][$id] = ['id' => $id, 'id_main_shop' => $i % 7 === 0 ? 2 : 3, 'is_b2b' => 0, 'name' => $pre[$i % 20], 'surname' => $nom[($i * 7) % 20], 'phone' => '+32 4' . (70 + $i % 29) . ' ' . str_pad((string) (10 + $i), 2, '0') . ' ' . (20 + $i % 70) . ' ' . str_pad((string) ($i % 97), 2, '0', STR_PAD_LEFT),
            'email' => $i % 3 ? strtolower($pre[$i % 20]) . '.' . strtolower($nom[($i * 7) % 20]) . '@exemple.be' : '', 'street' => 'rue de Namur', 'street_number' => (string) (1 + $i), 'zip' => '6041', 'city' => 'Gosselies',
            'personal_discount_percent' => $i % 9 === 0 ? 5 : ($i % 13 === 0 ? 10 : 0), 'can_deferral' => 0, 'create_timestamp' => date('Y-m-d H:i:s', strtotime('2026-11-24 -' . ($i * 5) . ' day')), 'password_hash' => 'x', 'api_key' => 'k'];
    }
    $bur = [['Lunchroom Hainaut Assurances', 800, 'Charleroi', 3, 'active'], ['Horizon · Jumet', 801, 'Jumet', 2, 'active'], ['Fiduciaire Delvaux', 802, 'Gosselies', 1, 'active'], ['Techni-Sol · atelier', 803, 'Jumet', 0, 'active'],
        ['Atelier numérique', 804, 'Charleroi', 1, 'active'], ['Bureau Lambot', 805, 'Courcelles', 0, 'pending'], ['Biolab · labo 2', 807, 'Gosselies', 2, 'active']];
    foreach ($bur as $i => $b) {
        $D['bureaux'][] = ['id' => 40 + $i, 'id_shop' => 3, 'id_company_client' => $b[1], 'name' => $b[0], 'company_name' => $ent[$b[1] - 800][0], 'tax_number' => $ent[$b[1] - 800][1], 'status' => $b[4], 'assortment_mode' => $i % 3 ? 'all' : 'restricted',
            'show_prices' => 1, 'delivery_days' => [1, 2, 3, 4, 5], 'site_count' => $b[4] === 'pending' ? 0 : 1, 'department_count' => $b[3] + 1, 'offer_count' => $b[3], 'catalog_count' => 1, 'primary_city' => $b[2], 'primary_address' => 'adresse ' . ($i + 1), 'contact_person' => 'Office manager', 'billing_mode' => $i % 2 ? 'person' : 'company'];
    }
    return $D;
}
/** Le relevé des tickets pro (consultant/src/ventes_pro.php) : des jours fictifs, les clients B2B qui reviennent. */
function vpLu(int $sid, string $j): ?array
{
    if ($j < '2025-01-01') { return null; }
    $s = crc32($j); $dow = (int) date('N', strtotime($j));
    if ($dow === 7) { return ['ca' => 0.0, 'n' => 0, 't' => []]; }
    $ca = 900 + $s % 700; $t = [];
    $poids = [800 => 4, 801 => 5, 802 => 4, 803 => 3, 804 => 3, 805 => 2, 806 => 2, 807 => 1, 808 => 1, 809 => 0];
    foreach ($poids as $id => $p) {
        if ((($s >> ($id % 13)) % 11) < $p * (str_starts_with($j, '2026') ? 1.25 : 1)) { $t[] = ['10:' . ($id % 60), 'c' . $id, round(40 + (($s >> 3) % 120) + $p * 8, 2), $p > 2 ? 1 : 0, $p > 3 ? 1 : 0]; }
    }
    return ['ca' => (float) $ca, 'n' => 70 + $s % 40, 't' => $t];
}
function bancPanel(): bool { return getenv('BANC_ACCES') === 'panel'; }
function bancRep(string $m, string $p, ?array $b): array
{
    $D = bancDonnees();
    $q = parse_url($p); $chem = $q['path']; parse_str($q['query'] ?? '', $Q);
    // Le jeton d'un employé du magasin connecté (tfb_magasin.php) ouvre les routes du panel, comme en vrai.
    $magasin = PanelApi::$jetonImpose !== null && str_ends_with(PanelApi::$jetonImpose, '.banc-magasin');
    if (PanelApi::$jetonImpose !== null && !$magasin) { return [401, ['status' => 401, 'error' => 'PANEL_SESSION_EXPIRED']]; }
    if (str_starts_with($chem, '/panel/') && !bancPanel() && !$magasin) { return [404, ['status' => 'error', 'description' => 'ORDER_NOT_FOUND', 'errors' => []]]; }
    if ($m === 'GET' && $chem === '/shops/3/clients') { $t = ($Q['type'] ?? '') === 'b2b' ? 'b2b' : 'b2c'; return [200, array_values($D[$t])]; }
    if ($m === 'POST' && $chem === '/shops/3/clients') { return [201, ['success' => true, 'client_created' => true, 'shop_assignment_created' => true, 'data' => ['id' => isset($b['vat_number']) ? 2999 : 1999]]]; }
    if (preg_match('#^/shops/3/clients/(\d+)$#', $chem, $mm)) {
        $c = $D['b2b'][(int) $mm[1]] ?? $D['b2c'][(int) $mm[1]] ?? null;
        if (!$c) { return [404, ['description' => 'CLIENT_NOT_FOUND']]; }
        return $m === 'GET' ? [200, ['client' => $c, 'profile' => $D['profil'][$c['id']] ?? null]] : [200, ['ok' => true]];
    }
    if (preg_match('#^/shops/3/clients/(\d+)/invoices$#', $chem, $mm)) {
        $L = []; for ($i = 0; $i < 8; $i++) { $L[] = ['id' => 5000 + $i, 'invoiced_date' => date('Y-m-d', strtotime('2026-11-20 -' . (14 * $i) . ' day')), 'due_date' => date('Y-m-d', strtotime('2026-12-04 -' . (14 * $i) . ' day')), 'invoice_value' => 180 + 20 * $i, 'invoice_value_after_corrections' => 180 + 20 * $i, 'was_paid' => $i > 1 ? 1 : 0, 'deferral_invoice' => 1]; }
        return [200, $L];
    }
    if (preg_match('#^/clients/(\d+)$#', $chem)) { return [200, ['ok' => true]]; }
    if ($chem === '/shops/3/clients/vat-lookup') { return [200, ['found' => true, 'source' => 'vies', 'client_exists' => false, 'assigned_to_shop' => false, 'data' => ['company_name' => 'Imprimerie Lefèvre SRL', 'tax_number' => $b['vat_number'] ?? '', 'street' => 'rue de Gosselies', 'street_number' => '12', 'zip' => '6040', 'city' => 'Jumet']]]; }
    if ($chem === '/panel/shops/3/b2b/clients') { return [200, $D['riche']]; }
    if (preg_match('#^/panel/shops/3/b2b/clients/(\d+)/orders$#', $chem)) { return [200, [['id' => 91, 'pick_up_datetime' => '2026-11-24 12:00:00', 'total_value' => 118.3, 'description' => '14 lunchs'], ['id' => 88, 'pick_up_datetime' => '2026-11-19 12:00:00', 'total_value' => 101.4, 'description' => '12 lunchs'], ['id' => 85, 'pick_up_datetime' => '2026-11-17 12:00:00', 'total_value' => 164.0, 'description' => 'Réunion · 20 pers.']]]; }
    if ($chem === '/panel/shops/3/b2b/dashboard') {
        $cur = [6.2, 6.8, 7.4, 7.1, 7.9, 6.4, 4.8, 5.2, 8.1, 8.6, 9.4, 0]; $prev = [5.1, 5.4, 6.0, 6.3, 6.6, 5.9, 4.1, 4.6, 6.9, 7.2, 8.0, 7.6];
        return [200, ['period' => '2026-11', 'kpi' => ['b2b_sales' => ['value' => 7012, 'mom_pct' => 9, 'yoy_pct' => 14], 'b2b_transactions' => ['value' => 212, 'mom_pct' => 6, 'yoy_pct' => 11], 'b2b_avg_ticket' => ['value' => 33.08, 'mom_pct' => 3, 'yoy_pct' => 2],
            'b2b_unique_clients' => ['value' => 58, 'mom_pct' => 4, 'yoy_pct' => 18], 'b2b_share_pct' => ['value' => 21.4, 'mom_pct' => 1.2, 'yoy_pct' => 3.1], 'b2b_invoices' => ['value' => 31, 'mom_pct' => -5, 'yoy_pct' => 7]],
            'chart' => ['months' => array_map(static fn ($i) => ['month' => $i + 1, 'curr_sales' => $cur[$i] * 1000, 'prev_sales' => $prev[$i] * 1000, 'curr_txn' => $cur[$i] * 30, 'prev_txn' => $prev[$i] * 30, 'curr_uniq' => $cur[$i] * 7, 'prev_uniq' => $prev[$i] * 7, 'curr_share' => $cur[$i] * 2.3, 'prev_share' => $prev[$i] * 2.3], range(0, 11))]]];
    }
    if ($chem === '/panel/shops/3/offices') { return $m === 'GET' ? [200, ['offices' => $D['bureaux']]] : [200, ['id' => 99]]; }
    if ($chem === '/panel/shops/3/offices/options') { return [200, ['companies' => array_map(static fn ($c) => ['id' => $c['id'], 'name' => $c['company_name'], 'tax_number' => $c['tax_number']], array_values($D['b2b'])), 'routes' => [['id' => 1, 'name' => 'Midi · Gosselies – Charleroi', 'start_time' => '11:15:00'], ['id' => 2, 'name' => 'Matin · Jumet', 'start_time' => '08:30:00']],
        'delivery_sites' => [['id' => 5, 'name' => 'Lunchroom', 'address' => 'bd Tirou 45, Charleroi', 'route_id' => 1]], 'vouchers' => [['id' => 3, 'code' => 'BIENVENUE5', 'name' => '5 % la première commande']], 'department_presets' => ['Direction', 'Comptabilité', 'Ressources humaines', 'Commercial', 'Informatique']]]; }
    // Les adresses (geo) : suggestions, une adresse vérifiée, les localités d'un code postal (fictif).
    if ($chem === '/panel/shops/3/offices/geo/autocomplete') {
        $qq = (string) ($Q['query'] ?? '');
        return [200, ['available' => true, 'suggestions' => [['id' => 'banc-1', 'label' => 'Rue de la Station 14, 6040 Jumet, Belgique'], ['id' => 'banc-2', 'label' => 'Rue de la Station 14, 6041 Gosselies, Belgique'], ['id' => 'banc-3', 'label' => $qq . ' (autre)']]]];
    }
    if ($chem === '/panel/shops/3/offices/geo/place') {
        return [200, ['place' => ['verified' => true, 'place_id' => (string) ($Q['id'] ?? ''), 'street' => 'Rue de la Station', 'street_number' => '14', 'postal_code' => ($Q['id'] ?? '') === 'banc-2' ? '6041' : '6040',
            'city' => ($Q['id'] ?? '') === 'banc-2' ? 'Gosselies' : 'Jumet', 'country' => 'BE', 'lat' => 50.43, 'lng' => 4.43, 'formatted_address' => 'Rue de la Station 14, 6040 Jumet']]];
    }
    if ($chem === '/panel/shops/3/offices/geo/postcodes') {
        $cp = (string) ($Q['postal_code'] ?? '');
        return [200, ['localities' => $cp === '6000' ? [['city' => 'Charleroi']] : [['city' => 'Gosselies'], ['city' => 'Thiméon']]]];
    }
    if ($chem === '/panel/shops/3/offices/onboard') {
        // Ce que l'assistant envoie, gardé pour le contrôle du banc (le logo réduit à sa longueur).
        $bb = $b ?? []; if (isset($bb['office']['logo_data_url'])) { $bb['office']['logo_data_url'] = 'data-url ' . strlen((string) $bb['office']['logo_data_url']) . ' car.'; }
        file_put_contents(sys_get_temp_dir() . '/banc-onboard.json', json_encode($bb, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return [200, ['office' => ['id' => 40], 'onboarding' => ['notifications' => ['status' => 'queued', 'membership' => 0]]]];
    }
    // Un bureau sans site : TF Buddy refuse sa livraison (422 OFFICE_DELIVERY_SITE_REQUIRED, vu à Corbais le 26/11/2026).
    if (preg_match('#^/panel/shops/3/offices/(\d+)/(delivery-settings|sites)$#', $chem, $mm) && $m === 'GET') {
        $o = array_values(array_filter($D['bureaux'], static fn ($x) => (int) $x['id'] === (int) $mm[1]))[0] ?? null;
        if ($o && (int) $o['site_count'] === 0) { return $mm[2] === 'sites' ? [200, []] : [422, ['status' => 'error', 'description' => 'OFFICE_DELIVERY_SITE_REQUIRED']]; }
    }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/sites$#', $chem) && $m === 'POST') { return [200, ['id' => 77]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/delivery-settings$#', $chem)) { return [200, ['route' => ['id' => 1, 'name' => 'Midi · Gosselies – Charleroi'], 'delivery_window_start' => '12:00:00', 'delivery_window_end' => '12:30:00', 'cutoff_time' => '16:00:00', 'cutoff_days_before' => 1, 'drop_minutes' => 5]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/sites$#', $chem)) { return [200, [['id' => 5, 'name' => 'Lunchroom', 'address' => 'bd Tirou 45', 'postal_code' => '6000', 'city' => 'Charleroi', 'access_minutes' => 5]]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/members$#', $chem)) {
        $P = ['Marie Dupont', 'Jean Lambert', 'Sophie Renard', 'Thomas Peeters', 'Julie Simon', 'Nicolas Claes', 'Camille Leroy', 'Pierre Hermans', 'Sarah Mertens', 'Antoine Jacobs', 'Emma Wouters', 'Lucas Maes'];
        $Dp = ['Direction', 'Comptabilité', 'Ressources humaines', 'Sinistres'];
        return [200, array_map(static fn ($i) => ['id' => $i + 1, 'name' => explode(' ', $P[$i])[0], 'surname' => explode(' ', $P[$i])[1], 'department_name' => $Dp[$i % 4]], array_keys($P))];
    }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/members/invite$#', $chem)) { return [200, ['invite' => ['status' => 'queued']]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/invite$#', $chem)) { return [200, ['invite' => ['expires_at' => '2026-12-31', 'uses' => 23, 'url' => 'https://webshop.exemple/rejoindre/abc123']]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)/assortment$#', $chem)) { return [200, ['mode' => 'restricted', 'products' => array_fill(0, 38, ['id' => 1])]]; }
    if (preg_match('#^/panel/shops/3/offices/(\d+)$#', $chem)) { return [200, ['offers' => [['id' => 1, 'name' => 'Lunch du vendredi', 'discount_percent' => 10, 'valid_to' => '2026-12-31']]]]; }
    return [404, ['description' => 'NOT_FOUND']];
}
final class PanelApi
{
    public static ?string $jetonImpose = null;
    public static function configured(): bool { return true; }
    public static function sondeGet(string $p, int $t = 12): array { [$c, $b] = bancRep('GET', $p, null); return ['code' => $c, 'corps' => $b, 'erreur' => null]; }
    public static function requete(string $m, string $p, ?array $b = null, int $t = 20): array { [$c, $r] = bancRep($m, $p, $b); return ['code' => $c, 'data' => $r, 'erreur' => null]; }
    public static function jetonSonde(): ?string { return null; }
    public static function config(): array { return ['base' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/tfb-sim']; }
}
require RACINE . '/panel/src/tfb_magasin.php';
require RACINE . '/panel/src/clients_panel.php';
require RACINE . '/panel/src/bureaux_dev.php';
header('Content-Type: application/json; charset=utf-8');
$path = preg_replace('#^.*/api/cockpit#', '', $chemin);
parse_str($u['query'] ?? '', $_GET);
if ($path === '/dashboard/clients/bureaux/pdf') { ep_clients_bureaux_pdf(); }   // comme shared/public/api/index.php : sort le PDF, puis exit
if (preg_match('#^/bureau-questionnaire/([a-f0-9]{32})$#', $path, $mq)) { echo json_encode(bdvPublic($_SERVER['REQUEST_METHOD'], $mq[1]), JSON_UNESCAPED_UNICODE); return true; }
try { $r = pclRoute($_SERVER['REQUEST_METHOD'], $path) ?? tfbmRoute($_SERVER['REQUEST_METHOD'], $path) ?? bdvRoute($_SERVER['REQUEST_METHOD'], $path); }
catch (Throwable $e) { http_response_code(500); $r = ['error' => get_class($e) . ' : ' . $e->getMessage() . ' @' . $e->getLine()]; }
echo json_encode($r ?? ['error' => 'route inconnue'], JSON_UNESCAPED_UNICODE);
return true;
