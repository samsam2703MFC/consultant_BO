<?php
declare(strict_types=1);

/*
 * ESSAI D'ÉCRITURE — un bon de test complet dans l'ERP TFBuddy (24/10/2026, accord de Sam : « oui crée la campagne
 * de test »). Il finit le contrat de caisse que la sonde (tools/sonde_vouchers.php) n'a pas pu lire sans vrai code :
 * ce que /vouchers/validate attend et rend, ce que /vouchers/redeem exige et rend, comment /vouchers/reverse annule.
 *
 * Le bon ne peut servir à personne :
 *   - promotion « remise programmée » de 1 % sur UN produit, dans UNE boutique (ESSAI_SHOP), activation par code seul ;
 *   - campagne UNIQUE, trois utilisations au plus (une par code), valable jusqu'à demain ;
 *   - un code aléatoire de 12 caractères préfixé ZZSONDE (masqué dans le journal) ;
 *   - actif seulement le temps de l'essai : un `finally` remet code DISABLED, campagne INACTIVE, promotion INACTIVE.
 * Les passages suivants REPRENNENT la même campagne (retrouvée par son nom) : aucun doublon.
 *
 *   php -- /var/www/consulant_bo essai      (défaut) — créer ou reprendre, activer, essayer, désactiver
 *   php -- /var/www/consulant_bo visible    — un bon complet mais hors service (promotion INACTIVE rattachée, code
 *                                             DISABLED, campagne INACTIVE), pour le voir dans le panel
 *   php -- /var/www/consulant_bo nettoyage  — désactiver, détacher puis supprimer la promotion ; la campagne reste
 *                                             INACTIVE (l'API ne supprime pas les campagnes)
 *
 * Aucun identifiant de transaction réel n'est jamais envoyé : seulement 999999999, qui n'existe pas.
 */

$dir  = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$mode = (string) ($argv[2] ?? 'essai');
foreach (['Db.php', 'endpoints.php', 'installer.php', 'writes.php', 'erp_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require "$dir/src/$f";
}
foreach (['sondeGet', 'ecrire'] as $m) {
    if (!method_exists('ErpApi', $m)) { echo "ErpApi::$m absent sur ce serveur : essai impossible\n"; exit(1); }
}

const ESSAI_NOM  = '[SONDE COCKPIT — à désactiver] essai bons webshop 24/10';
const ESSAI_SHOP = 4;
const FAUX_ID    = 999999999;

function evMasque(string $s): string { return mb_strlen($s) <= 3 ? '***' : mb_substr($s, 0, 3) . '…(' . mb_strlen($s) . ')'; }

/** Un corps de réponse lisible : les codes et jetons masqués, le reste tronqué. */
function evPropre($x, string $cle = '', int $prof = 0)
{
    if (is_array($x)) {
        if ($prof > 4) { return '…'; }
        $o = [];
        foreach (array_slice($x, 0, 25, true) as $k => $v) { $o[$k] = evPropre($v, (string) $k, $prof + 1); }
        return $o;
    }
    if (is_string($x)) {
        if (str_starts_with($x, 'ZZSONDE')) { return evMasque($x); }
        // Un code ou un jeton se masque ; une PHRASE (message d'erreur rangé sous `shared_code`, 2e passage) reste lisible.
        if (preg_match('/(^|_)code$|shared_code|token|request_key/i', $cle) && !preg_match('/^[A-Z_]+$/', $x) && !str_contains($x, ' ')) { return evMasque($x); }
        if (preg_match('/mail|phone/i', $cle)) { return '<masqué>'; }
        return mb_substr($x, 0, 120);
    }
    return $x;
}

function evJson($x): string { return (string) json_encode(evPropre($x), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

/** Une écriture, journalisée : méthode, chemin, champs envoyés, code et corps de réponse. */
function evEcrire(string $m, string $p, ?array $b = null): array
{
    $r = ErpApi::ecrire($m, $p, $b);
    printf("  %-6s %-62s %s → HTTP %d %s\n", $m, $p, $b === null ? '' : json_encode(array_keys($b)), $r['code'], mb_substr(evJson($r['corps']), 0, 700));
    return $r;
}

function evLire(string $p): array
{
    $r = ErpApi::sondeGet($p);
    printf("  GET    %-62s → HTTP %d\n", $p, $r['code']);
    return $r;
}

/** Le premier `id` trouvé dans une réponse : à la racine, dans `data`, `campaign`, `promotion`, `code`, `item`. */
function evId($c): ?int
{
    if (!is_array($c)) { return null; }
    foreach ([$c, $c['data'] ?? null, $c['campaign'] ?? null, $c['data']['campaign'] ?? null, $c['promotion'] ?? null,
              $c['data']['promotion'] ?? null, $c['code'] ?? null, $c['data']['code'] ?? null, $c['item'] ?? null] as $n) {
        if (is_array($n) && isset($n['id']) && is_numeric($n['id'])) { return (int) $n['id']; }
    }
    return null;
}

function evItems($c): array
{
    if (!is_array($c)) { return []; }
    foreach ([$c['items'] ?? null, $c['data']['items'] ?? null, $c['data'] ?? null, $c] as $n) {
        if (is_array($n) && array_is_list($n)) { return $n; }
    }
    return [];
}

function evTitre(string $t): void { echo "\n=== $t ===\n"; }

// ── Retrouver l'essai d'un passage précédent ─────────────────────────────────
evTitre("essai de bon — mode « $mode » — boutique " . ESSAI_SHOP);
$campId = null; $promoId = null; $codeId = null; $code = null;
foreach (evItems(ErpApi::sondeGet('/admin/voucher-campaigns?limit=100&name=' . rawurlencode(ESSAI_NOM))['corps']) as $c) {
    if (is_array($c) && ($c['name'] ?? '') === ESSAI_NOM) { $campId = (int) $c['id']; $promoId = !empty($c['id_promotion']) ? (int) $c['id_promotion'] : null; break; }
}
if ($promoId === null) {
    foreach (evItems(ErpApi::sondeGet('/admin/promotions/scheduled-product-discount?limit=50&search=' . rawurlencode('SONDE COCKPIT'))['corps']) as $pr) {
        if (is_array($pr) && ($pr['name'] ?? '') === ESSAI_NOM) { $promoId = (int) $pr['id']; break; }
    }
}
printf("  repris d'un passage précédent : campagne=%s · promotion=%s\n", json_encode($campId), json_encode($promoId));

$tz = new DateTimeZone('Europe/Brussels');
// 3e passage : « Voucher campaign has not started yet » avec un début à l'heure de Bruxelles moins 10 minutes —
// l'ERP compare en UTC (ses activated_at / disabled_at sont en UTC, ses created_at à l'heure locale). Un début
// trois heures plus tôt est passé dans les deux fuseaux.
$debut = (new DateTimeImmutable('now', $tz))->modify('-3 hours')->format('Y-m-d H:i:s');
$fin   = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d H:i:s');

// ── Nettoyage final ──────────────────────────────────────────────────────────
if ($mode === 'nettoyage') {
    if ($campId !== null) {
        foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50")['corps']) as $k) {
            if (is_array($k) && ($k['status'] ?? '') !== 'DISABLED') { evEcrire('PATCH', '/admin/voucher-codes/' . (int) $k['id'] . '/status', ['status' => 'DISABLED']); }
        }
        evEcrire('PATCH', "/admin/voucher-campaigns/$campId/status", ['status' => 'INACTIVE']);
        if ($promoId !== null) { evEcrire('DELETE', "/admin/voucher-campaigns/$campId/promotion"); }
    }
    if ($promoId !== null) {
        $d = evEcrire('DELETE', "/admin/promotions/scheduled-product-discount/$promoId");
        if ($d['code'] >= 300) { evEcrire('PATCH', "/admin/promotions/scheduled-product-discount/$promoId", ['name' => ESSAI_NOM, 'status' => 'INACTIVE']); }
    }
    if ($campId !== null) {
        $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
        echo '  état final de la campagne : ', evJson(['status' => $f['campaign']['status'] ?? null, 'id_promotion' => $f['campaign']['id_promotion'] ?? null,
            'code_stats' => $f['code_stats'] ?? null, 'redemptions_total' => $f['redemptions_total'] ?? null]), "\n";
    }
    echo "\n=== FIN ESSAI (nettoyage) ===\n";
    exit(0);
}

// ── 1. Un produit de la boutique ─────────────────────────────────────────────
evTitre('1. un produit vendu par la boutique ' . ESSAI_SHOP);
$pid = null; $pnom = null; $prix = null;
foreach (['/shops/' . ESSAI_SHOP . '/products/available', '/franchisee-shop/' . ESSAI_SHOP . '/products'] as $ch) {
    $r = evLire($ch);
    foreach (evItems($r['corps']) as $it) {
        if (!is_array($it)) { continue; }
        $id = $it['id_product'] ?? $it['product_id'] ?? $it['id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            $pid = (int) $id; $pnom = (string) ($it['name'] ?? $it['product_name'] ?? '');
            $prix = $it['price'] ?? $it['gross_price'] ?? $it['price_gross'] ?? null;
            echo '  clés d\'un produit : ', implode(', ', array_slice(array_keys($it), 0, 30)), "\n";
            break 2;
        }
    }
}
if ($pid === null) { echo "  ⇒ aucun produit lisible : essai arrêté avant toute écriture\n"; exit(1); }
printf("  produit retenu : #%d « %s » prix=%s\n", $pid, mb_substr($pnom, 0, 60), json_encode($prix));

$promoCorps = [
    'name' => ESSAI_NOM, 'description' => 'Essai technique du cockpit — ne pas utiliser. 1 % sur un produit, une utilisation.',
    'promotion_type' => 'SCHEDULED_PRODUCT_DISCOUNT', 'status' => 'DRAFT', 'priority' => 0, 'is_exclusive' => false,
    'valid_from' => $debut, 'valid_to' => $fin, 'is_repeatable' => false, 'max_applications_per_transaction' => 1,
    'shop_scope_type' => 'SELECTED_SHOPS', 'shop_ids' => [ESSAI_SHOP], 'activation_mode' => 'VOUCHER_ONLY',
    'reward_product_ids' => [$pid], 'reward_category_ids' => [], 'excluded_product_ids' => [],
    'discount_type' => 'PERCENTAGE_DISCOUNT', 'discount_value' => 1,
    // Une promotion ACTIVE exige au moins un créneau (1er passage : « At least one schedule window is required for
    // ACTIVE promotions. ») : toute la semaine, toute la journée — la durée de vie reste bornée par valid_to.
    'schedule' => array_map(static fn ($j) => ['day_of_week' => $j, 'time_from' => '00:00', 'time_to' => '23:59'], range(1, 7)),
];

// ── Mode « visible » : un bon COMPLET mais hors service, pour le voir dans le panel ──
// 24/10, Sam ne voit rien dans le panel : le nettoyage avait laissé la campagne #9 vide (DRAFT, sans promotion,
// codes désactivés). Ici : promotion recréée puis INACTIVE, rattachée, un code DISABLED, campagne INACTIVE jusqu'au
// 31/12. Rien n'est jamais ACTIVE : le bon se voit, il ne s'utilise pas.
if ($mode === 'visible') {
    $finVis = '2026-12-31 23:59:59';
    $pc = ['description' => 'Essai technique du cockpit — visible pour contrôle, désactivé. 1 % sur un produit.',
           'valid_to' => $finVis] + $promoCorps;
    if ($campId === null) { echo "  ⇒ campagne d'essai introuvable : arrêt avant toute écriture\n"; exit(1); }
    evTitre('visible : promotion');
    if ($promoId === null) {
        $r = evEcrire('POST', '/admin/promotions/scheduled-product-discount', $pc);
        $promoId = evId($r['corps']);
        if ($promoId === null) { echo "  ⇒ promotion non créée : arrêt\n"; exit(1); }
    }
    evEcrire('PATCH', "/admin/promotions/scheduled-product-discount/$promoId", ['status' => 'INACTIVE'] + $pc);
    evTitre('visible : campagne #' . $campId);
    evEcrire('PATCH', "/admin/voucher-campaigns/$campId", ['name' => ESSAI_NOM, 'description' => 'Essai technique du cockpit — visible pour contrôle, désactivé.',
        'code_type' => 'UNIQUE', 'shared_code' => null, 'valid_from' => $debut, 'valid_to' => $finVis, 'usage_limit_total' => 3, 'usage_limit_per_code' => 1]);
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
    if ((int) ($f['campaign']['id_promotion'] ?? 0) !== $promoId) {
        evEcrire('PUT', "/admin/voucher-campaigns/$campId/promotion", ['id_promotion' => $promoId, 'confirm_activation_mode_change' => false]);
    }
    evTitre('visible : un code (désactivé)');
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $rnd = '';
    for ($i = 0; $i < 12; $i++) { $rnd .= $alpha[random_int(0, strlen($alpha) - 1)]; }
    evEcrire('POST', "/admin/voucher-campaigns/$campId/codes", ['code' => 'ZZSONDE' . $rnd, 'status' => 'DISABLED', 'usage_limit' => 1, 'valid_from' => null, 'valid_to' => $finVis]);
    evTitre('visible : campagne INACTIVE');
    $r = evEcrire('PATCH', "/admin/voucher-campaigns/$campId", ['status' => 'INACTIVE', 'code_type' => 'UNIQUE', 'shared_code' => null]);
    if ($r['code'] >= 300) { evEcrire('PATCH', "/admin/voucher-campaigns/$campId/status", ['status' => 'INACTIVE']); }
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
    echo '  état final : ', evJson(['status' => $f['campaign']['status'] ?? null, 'id_promotion' => $f['campaign']['id_promotion'] ?? null,
        'valid_to' => $f['campaign']['valid_to'] ?? null, 'promotion_status' => $f['promotion']['status'] ?? null,
        'code_stats' => $f['code_stats'] ?? null, 'redemptions_total' => $f['redemptions_total'] ?? null,
        'configuration_complete' => $f['configuration_complete'] ?? null, 'missing_requirements' => $f['missing_requirements'] ?? null]), "\n";
    echo "\n=== FIN ESSAI (visible) ===\n";
    exit(0);
}

try {
    // ── 2. La promotion (la remise) ──────────────────────────────────────────
    evTitre('2. promotion VOUCHER_ONLY (DRAFT)');
    if ($promoId === null) {
        $r = evEcrire('POST', '/admin/promotions/scheduled-product-discount', $promoCorps);
        $promoId = evId($r['corps']);
        if ($promoId === null) { throw new RuntimeException('promotion non créée'); }
    } else {
        evEcrire('PATCH', "/admin/promotions/scheduled-product-discount/$promoId", $promoCorps);
    }
    echo "  promotion #$promoId\n";

    // ── 3. La campagne ───────────────────────────────────────────────────────
    evTitre('3. campagne UNIQUE (DRAFT)');
    $campCorps = ['name' => ESSAI_NOM, 'description' => 'Essai technique du cockpit — ne pas utiliser.', 'status' => 'DRAFT',
        'code_type' => 'UNIQUE', 'valid_from' => $debut, 'valid_to' => $fin, 'usage_limit_total' => 3, 'usage_limit_per_code' => 1,
        'requires_customer' => false];
    if ($campId === null) {
        $r = evEcrire('POST', '/admin/voucher-campaigns', $campCorps);
        $campId = evId($r['corps']);
        if ($campId === null) { throw new RuntimeException('campagne non créée'); }
    } else {
        $cc = $campCorps; unset($cc['status']);
        evEcrire('PATCH', "/admin/voucher-campaigns/$campId", $cc);
    }
    echo "  campagne #$campId\n";

    // ── 4. Rattacher la promotion ────────────────────────────────────────────
    evTitre('4. rattacher la promotion à la campagne');
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
    if ((int) ($f['campaign']['id_promotion'] ?? 0) !== $promoId) {
        evEcrire('PUT', "/admin/voucher-campaigns/$campId/promotion", ['id_promotion' => $promoId, 'confirm_activation_mode_change' => false]);
    } else { echo "  déjà rattachée\n"; }

    // ── 5. Les codes ─────────────────────────────────────────────────────────
    evTitre('5. codes');
    // Le code d'essai d'un passage précédent : ZZSONDE…, mais pas celui de la génération (ZZSONDE-G…).
    foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50")['corps']) as $k) {
        $kc = is_array($k) ? (string) ($k['code'] ?? '') : '';
        // Les dates d'un code ne se modifient pas (seul son statut) : on ne reprend qu'un code sans date de début.
        // … et encore utilisable (4e passage : le code #12 a servi une fois, sa limite).
        if ($code === null && str_starts_with($kc, 'ZZSONDE') && !str_starts_with($kc, 'ZZSONDE-G') && empty($k['valid_from'])
            && (int) ($k['usage_count'] ?? 0) < (int) ($k['usage_limit'] ?? 1)) { $code = $kc; $codeId = (int) $k['id']; }
    }
    if ($code === null) {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $rnd = '';
        for ($i = 0; $i < 12; $i++) { $rnd .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        $r = evEcrire('POST', "/admin/voucher-campaigns/$campId/codes", ['code' => 'ZZSONDE' . $rnd, 'status' => 'DISABLED', 'usage_limit' => 1, 'valid_from' => null, 'valid_to' => $fin]);
        $codeId = evId($r['corps']); $code = 'ZZSONDE' . $rnd;
        // La génération, une seule fois (1er passage), pour en lire la réponse ; le code généré est aussitôt désactivé.
        $dejaGenere = false;
        foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50")['corps']) as $k) { if (is_array($k) && str_starts_with((string) ($k['code'] ?? ''), 'ZZSONDE-G')) { $dejaGenere = true; } }
        if (!$dejaGenere) { evEcrire('POST', "/admin/voucher-campaigns/$campId/codes/generate", ['count' => 1, 'length' => 12, 'prefix' => 'ZZSONDE-G']); }
        foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50")['corps']) as $k) {
            if (is_array($k) && str_starts_with((string) ($k['code'] ?? ''), 'ZZSONDE-G') && ($k['status'] ?? '') !== 'DISABLED') {
                evEcrire('PATCH', '/admin/voucher-codes/' . (int) $k['id'] . '/status', ['status' => 'DISABLED']);
            }
        }
    }
    if ($codeId === null) {
        foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50&code=" . rawurlencode($code))['corps']) as $k) {
            if (is_array($k) && ($k['code'] ?? '') === $code) { $codeId = (int) $k['id']; }
        }
    }
    printf("  code d'essai %s (#%s)\n", evMasque($code), json_encode($codeId));

    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
    echo '  avant activation : configuration_complete=', json_encode($f['configuration_complete'] ?? null),
         ' missing_requirements=', json_encode($f['missing_requirements'] ?? null), "\n";

    // ── 6. Activer ───────────────────────────────────────────────────────────
    evTitre('6. activer (promotion, campagne, code)');
    // Ordre imposé par l'ERP (1er passage) : une campagne UNIQUE ne passe ACTIVE qu'avec un code déjà actif.
    if ($codeId !== null) { evEcrire('PATCH', "/admin/voucher-codes/$codeId/status", ['status' => 'ACTIVE']); }
    evEcrire('PATCH', "/admin/promotions/scheduled-product-discount/$promoId", ['status' => 'ACTIVE'] + $promoCorps);
    // 3e passage : PATCH /status refuse une campagne UNIQUE (« shared_code is not supported for UNIQUE campaigns » —
    // la fiche rend un shared_code calculé) ; PATCH /{id} avec shared_code vide passe.
    evEcrire('PATCH', "/admin/voucher-campaigns/$campId", ['status' => 'ACTIVE', 'code_type' => 'UNIQUE', 'shared_code' => null]);
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
    echo '  shared_code de la fiche : ', json_encode(isset($f['shared_code']) ? evMasque((string) $f['shared_code']) : null), "\n";
    echo '  après activation : configuration_complete=', json_encode($f['configuration_complete'] ?? null),
         ' missing_requirements=', json_encode($f['missing_requirements'] ?? null), ' · fiche : ', mb_substr(evJson($f), 0, 900), "\n";
    $act = evLire('/shops/' . ESSAI_SHOP . '/scheduled-product-discounts/active');
    $vu = false;
    foreach (evItems($act['corps']) as $it) { if (is_array($it) && (int) ($it['id'] ?? $it['id_promotion'] ?? 0) === $promoId) { $vu = true; } }
    echo '  la promotion VOUCHER_ONLY apparaît-elle dans les remises actives de la boutique ? ', $vu ? 'oui' : 'non', "\n";

    // ── 7. Valider (4e passage : code + id_shop suffisent) ───────────────────
    evTitre('7. POST /vouchers/validate {code, id_shop}');
    $r = evEcrire('POST', '/vouchers/validate', ['code' => $code, 'id_shop' => ESSAI_SHOP]);
    $c = is_array($r['corps']) ? $r['corps'] : [];
    printf("      valid=%s · jeton=%s · cart_addition_offer=%s · product_selection=%s\n", json_encode($c['valid'] ?? null),
        is_string($c['validation_token'] ?? null) ? 'oui (' . strlen($c['validation_token']) . ' car.)' : 'non',
        mb_substr(evJson($c['cart_addition_offer'] ?? null), 0, 300), mb_substr(evJson($c['product_selection'] ?? null), 0, 300));
    if (is_array($c['promotion'] ?? null)) { echo '      clés de promotion : ', implode(', ', array_keys($c['promotion'])), "\n"; }

    // ── 8. Encaisser AVEC une transaction, puis rejouer (idempotence) ────────
    // 4e passage : encaissé sans transaction, l'encaissement ne s'annule plus (reverse exige id_transaction et
    // compare : VOUCHER_TRANSACTION_MISMATCH). Ici : la transaction 999999999, qui n'existe pas.
    evTitre('8. POST /vouchers/redeem {request_key, code, id_shop, id_transaction} puis le même une 2e fois');
    $cle = 'SONDE-COCKPIT-ESSAI-' . gmdate('YmdHis') . '-RT';
    $corps = ['request_key' => $cle, 'code' => $code, 'id_shop' => ESSAI_SHOP, 'id_transaction' => FAUX_ID];
    $r1 = evEcrire('POST', '/vouchers/redeem', $corps);
    $ok = $r1['code'] >= 200 && $r1['code'] < 300;
    if ($ok) {
        $r2 = evEcrire('POST', '/vouchers/redeem', $corps);
        echo '      2e envoi : idempotent=', json_encode($r2['corps']['idempotent'] ?? null), ' · même encaissement=',
            json_encode(($r2['corps']['redemption']['id'] ?? null) === ($r1['corps']['redemption']['id'] ?? null)), "\n";
        $k = ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50&code=" . rawurlencode($code))['corps'];
        foreach (evItems($k) as $x) { if (is_array($x) && ($x['code'] ?? '') === $code) { echo '      usage du code après encaissement : ', json_encode($x['usage_count'] ?? null), "\n"; } }
    }

    // ── 9. Annuler ───────────────────────────────────────────────────────────
    evTitre('9. POST /vouchers/reverse {request_key, id_transaction}');
    if ($ok) {
        $rv = evEcrire('POST', '/vouchers/reverse', ['request_key' => $cle, 'id_transaction' => FAUX_ID]);
        if ($rv['code'] >= 300) { evEcrire('POST', '/vouchers/reverse', ['request_key' => $cle . '-REV', 'id_transaction' => FAUX_ID, 'code' => $code]); }
        $rv2 = evEcrire('POST', '/vouchers/reverse', ['request_key' => $cle, 'id_transaction' => FAUX_ID]);
        echo '      2e annulation : HTTP ', $rv2['code'], "\n";
        $k = ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50&code=" . rawurlencode($code))['corps'];
        foreach (evItems($k) as $x) { if (is_array($x) && ($x['code'] ?? '') === $code) { echo '      usage du code après annulation : ', json_encode($x['usage_count'] ?? null), "\n"; } }
        $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
        echo '      redemptions_total : ', json_encode($f['redemptions_total'] ?? null), "\n";
    } else {
        echo "  encaissement avec transaction refusé : rien à annuler\n";
    }
} catch (Throwable $e) {
    echo "\n  ⇒ essai interrompu : ", mb_substr($e->getMessage(), 0, 300), "\n";
} finally {
    // ── Toujours : tout remettre hors service ────────────────────────────────
    evTitre('fin : désactivation (toujours exécutée)');
    if ($campId !== null) {
        foreach (evItems(ErpApi::sondeGet("/admin/voucher-campaigns/$campId/codes?limit=50")['corps']) as $k) {
            if (is_array($k) && ($k['status'] ?? '') !== 'DISABLED') { evEcrire('PATCH', '/admin/voucher-codes/' . (int) $k['id'] . '/status', ['status' => 'DISABLED']); }
        }
        evEcrire('PATCH', "/admin/voucher-campaigns/$campId/status", ['status' => 'INACTIVE']);
    }
    if ($promoId !== null) { evEcrire('PATCH', "/admin/promotions/scheduled-product-discount/$promoId", ['status' => 'INACTIVE'] + $promoCorps); }
    if ($campId !== null) {
        $f = ErpApi::sondeGet("/admin/voucher-campaigns/$campId")['corps'];
        echo '  état final : ', evJson(['status' => $f['campaign']['status'] ?? null, 'code_stats' => $f['code_stats'] ?? null,
            'redemptions_total' => $f['redemptions_total'] ?? null]), "\n";
    }
    echo "\n=== FIN ESSAI ===\n";
}
