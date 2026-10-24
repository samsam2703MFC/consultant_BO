<?php
declare(strict_types=1);

/*
 * SONDE — campagnes de bons de l'API admin TFBuddy (24/10/2026, demande de Sam : « fais-le via sonde pour être sûr,
 * et ensuite crée ta documentation »).
 *
 * Question : le webshop peut-il créer ses bons par l'API ERP (`/admin/voucher-campaigns`, `/admin/voucher-codes`)
 * au lieu d'écrire en SQL dans `voucher_campaign` / `voucher_code` ? La spécification publique
 * (atelierby.tfbuddy.com/api/documentation/api.php) donne les corps ; cette sonde dit ce que l'API rend VRAIMENT
 * avec le compte admin du cockpit, et si ce compte a le droit d'écrire.
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-vouchers.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_vouchers.php
 *
 * AUCUNE ÉCRITURE ABOUTIE. Trois essais d'écriture seulement, construits pour être refusés avant tout
 * enregistrement : création sans `name` (422 attendu), génération de codes et changement de statut sur des
 * identifiants qui n'existent pas (404 attendu). Un 403 dit que le compte n'a pas le droit. Si, contre toute
 * attente, la création sans nom réussissait, la campagne est aussitôt passée INACTIVE et son numéro affiché.
 *
 * Rien de nominatif : les codes sont masqués (3 premiers caractères), les clients ne sont que comptés.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'endpoints.php', 'installer.php', 'writes.php', 'erp_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require "$dir/src/$f";
}

/** Un code de bon : ses trois premiers caractères et sa longueur. */
function svMasque(string $s): string
{
    return mb_strlen($s) <= 3 ? '***' : mb_substr($s, 0, 3) . '…(' . mb_strlen($s) . ')';
}

/** Une valeur affichable : les codes masqués, les e-mails et téléphones retirés, le reste tronqué. */
function svValeur(string $cle, $v)
{
    if (is_string($v)) {
        if (preg_match('/(^|_)code$|shared_code|token/i', $cle)) { return svMasque($v); }
        if (preg_match('/mail|phone|tel/i', $cle)) { return '<masqué>'; }
        // Les noms de campagne du webshop portent le code (« Webshop — BIENVENUE10 ») : on le masque aussi.
        if ($cle === 'name') { $v = preg_replace_callback('/\b[A-Z0-9][A-Z0-9-]{5,}\b/', static fn ($m) => svMasque($m[0]), $v); }
        return mb_substr($v, 0, 80);
    }
    return $v;
}

/** Les champs d'un objet, aplatis (« promotion.status ») : [chemin => [type, exemple]]. */
function svAplatir($x, string $pre = '', int $prof = 0): array
{
    $out = [];
    if (!is_array($x) || $prof > 3) { return $out; }
    if (array_is_list($x)) {
        $out[rtrim($pre, '.') . '[]'] = ['liste(' . count($x) . ')', null];
        if (isset($x[0]) && is_array($x[0])) { $out += svAplatir($x[0], rtrim($pre, '.') . '[].', $prof + 1); }
        return $out;
    }
    foreach ($x as $k => $v) {
        $ch = $pre . $k;
        if (is_array($v) && $v !== []) { $out += svAplatir($v, $ch . '.', $prof + 1); continue; }
        $t = is_array($v) ? 'liste(0)' : (is_null($v) ? 'null' : gettype($v));
        $out[$ch] = [$t, is_scalar($v) ? svValeur((string) $k, $v) : null];
    }
    return $out;
}

/** Les éléments d'une réponse paginée {items, pagination} (ou `data.items`, ou une liste nue). */
function svItems($corps): array
{
    if (!is_array($corps)) { return []; }
    foreach ([$corps['items'] ?? null, $corps['data']['items'] ?? null, $corps['data'] ?? null, $corps] as $c) {
        if (is_array($c) && array_is_list($c)) { return $c; }
    }
    return [];
}

function svPagination($corps): ?array
{
    if (!is_array($corps)) { return null; }
    $p = $corps['pagination'] ?? ($corps['data']['pagination'] ?? null);
    return is_array($p) ? $p : null;
}

/** Une fiche : l'objet lui-même, qu'il vienne seul ou dans `data`. */
function svFiche($corps): ?array
{
    if (!is_array($corps)) { return null; }
    return is_array($corps['data'] ?? null) && !array_is_list($corps['data']) ? $corps['data'] : $corps;
}

function svTitre(string $t): void { echo "\n=== $t ===\n"; }

function svChamps(array $objets): void
{
    $champs = [];
    foreach ($objets as $o) {
        foreach (svAplatir($o) as $ch => [$t, $ex]) {
            $c = $champs[$ch] ?? ['types' => [], 'n' => 0, 'ex' => null];
            $c['types'][$t] = true; $c['n']++;
            if ($c['ex'] === null && $ex !== null && $ex !== '') { $c['ex'] = $ex; }
            $champs[$ch] = $c;
        }
    }
    ksort($champs);
    foreach ($champs as $ch => $c) {
        printf("  %-46s %-22s %3d/%d  %s\n", $ch, implode('|', array_keys($c['types'])), $c['n'], count($objets),
            $c['ex'] === null ? '' : 'ex: ' . json_encode($c['ex'], JSON_UNESCAPED_UNICODE));
    }
}

function svRepartition(array $items, string $cle): string
{
    $r = [];
    foreach ($items as $it) { $v = $it[$cle] ?? null; $k = $v === null ? 'null' : (is_scalar($v) ? (string) $v : 'objet'); $r[$k] = ($r[$k] ?? 0) + 1; }
    arsort($r);
    return json_encode($r, JSON_UNESCAPED_UNICODE);
}

function svErreur(array $r): string
{
    $c = $r['corps'];
    $d = is_array($c) ? ($c['description'] ?? $c['message'] ?? $c['code'] ?? '') : '';
    $e = is_array($c) && isset($c['errors']) ? ' errors=' . mb_substr((string) json_encode($c['errors'], JSON_UNESCAPED_UNICODE), 0, 300) : '';
    return 'HTTP ' . $r['code'] . ($d !== '' ? ' · ' . (is_scalar($d) ? $d : json_encode($d)) : '') . $e;
}

// ── 0. Le compte ─────────────────────────────────────────────────────────────
svTitre('compte admin ERP du cockpit');
$st = ErpApi::statut();
printf("  base=%s  configuré=%s  mot de passe=%s  jeton en cache valide=%s  téléphone=%s\n", $st['base'],
    $st['configure'] ? 'oui' : 'non', $st['motDePasseDefini'] ? 'défini' : 'absent', $st['jetonValide'] ? 'oui' : 'non',
    $st['phone'] !== '' ? '…' . substr((string) $st['phone'], -2) : '—');
if (!ErpApi::disponible()) { echo "  ⇒ aucun identifiant ni jeton : sonde impossible\n"; exit(1); }
foreach (['sondeGet', 'ecrire'] as $m) {
    if (!method_exists('ErpApi', $m)) { echo "  ⇒ ErpApi::$m absent sur ce serveur (cockpit trop ancien) : sonde impossible\n"; exit(1); }
}

// ── 1. La liste des campagnes ────────────────────────────────────────────────
svTitre('GET /admin/voucher-campaigns (toutes les pages, 100 par page)');
$campagnes = []; $page = 1; $pg = null; $l = null;
do {
    $l = ErpApi::sondeGet('/admin/voucher-campaigns?limit=100&page=' . $page);
    if ($l['code'] < 200 || $l['code'] >= 300) { echo '  page ' . $page . ' : ' . svErreur($l) . "\n"; break; }
    $campagnes = array_merge($campagnes, svItems($l['corps']));
    $pg = svPagination($l['corps']);
    $page++;
} while ($pg !== null && $page <= (int) ($pg['pages'] ?? 1) && $page <= 20);
printf("  HTTP %d · %d campagne(s) lue(s) · pagination=%s\n", $l['code'] ?? 0, count($campagnes), json_encode($pg));
if ($campagnes !== []) {
    echo '  status : ', svRepartition($campagnes, 'status'), "\n";
    echo '  code_type : ', svRepartition($campagnes, 'code_type'), "\n";
    echo '  planned_promotion_type : ', svRepartition($campagnes, 'planned_promotion_type'), "\n";
    foreach (['id_shop', 'target_kind', 'reason_kind', 'id_brand', 'channel', 'channels'] as $k) {
        $n = count(array_filter($campagnes, static fn ($c) => is_array($c) && array_key_exists($k, $c)));
        printf("  champ « %s » présent sur %d/%d\n", $k, $n, count($campagnes));
    }
    $ws = array_values(array_filter($campagnes, static fn ($c) => is_array($c) && preg_match('/^(Webshop|Boutique \d+) — /u', (string) ($c['name'] ?? ''))));
    printf("  campagnes créées par le webshop (nom « Webshop — … » / « Boutique N — … ») : %d\n", count($ws));
    echo "  champs d'une ligne de liste :\n";
    svChamps(array_slice($campagnes, 0, 40));
}

// ── 2. Quelques fiches : détail, codes, promotions rattachables ──────────────
$choix = [];
$ajoute = static function (array $c) use (&$choix): void { $id = (int) ($c['id'] ?? 0); if ($id > 0 && count($choix) < 6) { $choix[$id] = $c; } };
foreach (array_slice($ws ?? [], 0, 2) as $c) { $ajoute($c); }
foreach ($campagnes as $c) { if (($c['code_type'] ?? '') === 'UNIQUE') { $ajoute($c); break; } }
foreach ($campagnes as $c) { if (!empty($c['id_promotion'])) { $ajoute($c); break; } }
foreach ($campagnes as $c) { $ajoute($c); }

$fiches = []; $codes = []; $promos = [];
foreach ($choix as $id => $c) {
    svTitre("campagne #$id (" . svValeur('name', (string) ($c['name'] ?? '')) . ')');
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$id");
    echo '  GET /{id} : ', $f['code'] >= 200 && $f['code'] < 300 ? 'HTTP ' . $f['code'] : svErreur($f), "\n";
    if (($fi = svFiche($f['corps'])) !== null && $f['code'] < 300) {
        $fiches[] = $fi;
        printf("    configuration_complete=%s · missing_requirements=%s · id_promotion=%s · promotion=%s\n",
            json_encode($fi['configuration_complete'] ?? null), json_encode($fi['missing_requirements'] ?? null, JSON_UNESCAPED_UNICODE),
            json_encode($fi['campaign']['id_promotion'] ?? null), json_encode(is_array($fi['promotion'] ?? null) ? array_keys($fi['promotion']) : ($fi['promotion'] ?? null)));
    }
    $k = ErpApi::sondeGet("/admin/voucher-campaigns/$id/codes?limit=5");
    $ki = svItems($k['corps']);
    echo '  GET /{id}/codes : ', $k['code'] < 300 ? 'HTTP ' . $k['code'] . ' · total=' . json_encode(svPagination($k['corps'])['total'] ?? count($ki)) : svErreur($k), "\n";
    foreach ($ki as $x) {
        if (is_array($x)) {
            $codes[] = $x;
            printf("    code %s · status=%s · usage=%s/%s · client=%s · valid_to=%s\n", svMasque((string) ($x['code'] ?? '')), $x['status'] ?? '?',
                json_encode($x['usage_count'] ?? null), json_encode($x['usage_limit'] ?? null), empty($x['id_customer']) ? 'non' : 'oui', json_encode($x['valid_to'] ?? null));
        }
    }
    $a = ErpApi::sondeGet("/admin/voucher-campaigns/$id/available-promotions?limit=10");
    $ai = svItems($a['corps']);
    echo '  GET /{id}/available-promotions : ', $a['code'] < 300 ? 'HTTP ' . $a['code'] . ' · total=' . json_encode(svPagination($a['corps'])['total'] ?? count($ai)) : svErreur($a), "\n";
    if ($ai !== []) {
        echo '    promotion_type : ', svRepartition($ai, 'promotion_type'), ' · activation_mode : ', svRepartition($ai, 'activation_mode'), "\n";
        $promos = array_merge($promos, $ai);
    }
}
if ($fiches !== []) { svTitre('champs d\'une fiche campagne (GET /admin/voucher-campaigns/{id})'); svChamps($fiches); }
if ($codes !== []) { svTitre('champs d\'un code (GET /admin/voucher-campaigns/{id}/codes)'); svChamps($codes); }
if ($promos !== []) { svTitre('champs d\'une promotion rattachable (GET …/available-promotions)'); svChamps(array_slice($promos, 0, 20)); }

// ── 3. Le droit d'écrire, sans rien enregistrer ──────────────────────────────
svTitre('droit d\'écriture (essais construits pour être refusés)');
$e1 = ErpApi::ecrire('POST', '/admin/voucher-campaigns', ['description' => null]);
echo '  POST /admin/voucher-campaigns sans name (422 attendu) : ', svErreur($e1), "\n";
if ($e1['code'] >= 200 && $e1['code'] < 300) {
    $nid = (int) (svFiche($e1['corps'])['id'] ?? 0);
    echo "  ⚠ CRÉÉE malgré l'absence de nom : campagne #$nid — passage INACTIVE…\n";
    if ($nid > 0) { echo '    PATCH status INACTIVE : ', svErreur(ErpApi::ecrire('PATCH', "/admin/voucher-campaigns/$nid/status", ['status' => 'INACTIVE'])), "\n"; }
}
$e2 = ErpApi::ecrire('POST', '/admin/voucher-campaigns/999999999/codes/generate', ['count' => 1]);
echo '  POST /admin/voucher-campaigns/999999999/codes/generate (404 attendu) : ', svErreur($e2), "\n";
$e3 = ErpApi::ecrire('PATCH', '/admin/voucher-codes/999999999/status', ['status' => 'DISABLED']);
echo '  PATCH /admin/voucher-codes/999999999/status (404 attendu) : ', svErreur($e3), "\n";
$droit = in_array(403, [$e1['code'], $e2['code'], $e3['code']], true) ? 'NON (403 : permission MENU.BUNDLE.MANAGE manquante)'
       : (in_array($e1['code'], [400, 422], true) ? 'OUI (validation atteinte : le compte passe la garde)' : 'indéterminé');
echo "  ⇒ le compte peut-il écrire ? $droit\n";

// ── 4. La base que lit le cockpit : les colonnes ajoutées par le webshop ─────
svTitre('base du cockpit : colonnes des tables de bons (information_schema, lecture seule)');
try {
    $db = Db::rows('SELECT DATABASE() AS db')[0]['db'] ?? '?';
    echo "  base : $db\n";
    foreach (['promotion', 'voucher_campaign', 'voucher_code', 'voucher_campaign_channel', 'voucher_campaign_target', 'voucher_redemption', 'promotion_order_discount'] as $t) {
        $cols = array_map(static fn ($c) => (string) $c['COLUMN_NAME'],
            Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]));
        echo '  ', str_pad($t, 26), $cols === [] ? 'absente' : implode(', ', $cols), "\n";
    }
    $canaux = Db::rows('SELECT channel, COUNT(*) n FROM voucher_campaign_channel GROUP BY channel ORDER BY n DESC');
    echo '  campagnes par canal : ', json_encode($canaux, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo '  lecture impossible : ', mb_substr($e->getMessage(), 0, 200), "\n";
}

// ── 5. L'API et la base parlent-elles des mêmes campagnes ? ──────────────────
// Le 24/10 au premier passage : 8 campagnes à l'API, aucune « Webshop — … », alors que la base du cockpit en a au
// canal WS ; et l'API rend un `office_id` sur les codes, colonne absente de `voucher_code` ici. On croise.
svTitre('croisement API ↔ base : mêmes campagnes ?');
try {
    $cfgDb = Db::config()['db'] ?? [];
    $hn = Db::rows('SELECT @@hostname AS h, DATABASE() AS d')[0] ?? [];
    printf("  base du cockpit : hôte configuré=%s · @@hostname=%s · base=%s\n", $cfgDb['host'] ?? '?', $hn['h'] ?? '?', $hn['d'] ?? '?');
    // La base du webshop (hôte et nom seulement — jamais l'utilisateur ni le mot de passe).
    foreach (['/var/www/html/api/config.php', '/var/www/html/webshop/api/config.php'] as $wc) {
        if (!is_file($wc)) { continue; }
        $w = (static function (string $f) { return include $f; })($wc);
        printf("  base du webshop (%s) : hôte=%s · base=%s\n", $wc, $w['db']['host'] ?? '?', $w['db']['name'] ?? '?');
    }
    $tv = array_map(static fn ($r) => (string) $r['TABLE_NAME'], Db::rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%voucher%' ORDER BY TABLE_NAME"));
    echo '  tables « voucher » de la base : ', implode(', ', $tv), "\n";
    $lignes = Db::rows("SELECT vc.id, vc.name, vc.status, vc.code_type, vc.id_shop, vc.target_kind, vc.created_by, vc.created_at,
                               (SELECT GROUP_CONCAT(ch.channel) FROM voucher_campaign_channel ch WHERE ch.id_voucher_campaign = vc.id) AS canaux,
                               p.promotion_type, p.activation_mode,
                               (SELECT COUNT(*) FROM voucher_code k WHERE k.id_voucher_campaign = vc.id) AS nb_codes
                          FROM voucher_campaign vc LEFT JOIN promotion p ON p.id = vc.id_promotion
                         ORDER BY vc.id LIMIT 80");
    printf("  %d campagne(s) en base :\n", count($lignes));
    $enBase = [];
    foreach ($lignes as $r) {
        $enBase[(int) $r['id']] = (string) $r['name'];
        printf("    #%-4d %-44s %-8s %-6s shop=%-4s cible=%-8s canaux=%-6s promo=%s/%s codes=%d par=%s le %s\n", (int) $r['id'],
            mb_substr((string) svValeur('name', (string) $r['name']), 0, 44), $r['status'], $r['code_type'], $r['id_shop'] ?? '—', $r['target_kind'] ?? '—',
            $r['canaux'] ?? '—', $r['promotion_type'] ?? '—', $r['activation_mode'] ?? '—', (int) $r['nb_codes'], $r['created_by'] ?? '—', substr((string) $r['created_at'], 0, 10));
    }
    $aApi = [];
    foreach ($campagnes as $c) { if (is_array($c) && isset($c['id'])) { $aApi[(int) $c['id']] = (string) ($c['name'] ?? ''); } }
    $communs = array_intersect_key($aApi, $enBase);
    $memeNom = count(array_filter(array_keys($communs), static fn ($id) => $aApi[$id] === $enBase[$id]));
    printf("  identifiants : API=%s · base=%s\n", json_encode(array_keys($aApi)), json_encode(array_keys($enBase)));
    printf("  communs=%d (même nom : %d) · à l'API seulement=%d · en base seulement=%d\n", count($communs), $memeNom,
        count(array_diff_key($aApi, $enBase)), count(array_diff_key($enBase, $aApi)));
    // Une campagne de la base absente de la liste : l'API la connaît-elle par son numéro ?
    foreach (array_slice(array_keys(array_diff_key($enBase, $aApi)), 0, 4) as $id) {
        $g = ErpApi::sondeGet("/admin/voucher-campaigns/$id");
        $gn = svFiche($g['corps'])['campaign']['name'] ?? null;
        printf("    GET /admin/voucher-campaigns/%d (en base seulement) : %s%s\n", $id, $g['code'] < 300 ? 'HTTP ' . $g['code'] : svErreur($g),
            $gn !== null ? ' · nom API=' . svValeur('name', (string) $gn) . ($gn === $enBase[$id] ? ' (identique à la base)' : ' (DIFFÉRENT de la base)') : '');
    }
    $verdict = $communs === [] ? 'NON — aucune campagne commune : l\'API ERP lit une autre base que le cockpit'
             : ($memeNom === count($communs) && count($communs) === count($aApi) && count($communs) === count($enBase) ? 'OUI — mêmes campagnes, mêmes noms'
             : 'EN PARTIE — des numéros communs mais des noms ou des listes qui diffèrent');
    echo "  ⇒ l'API et la base du cockpit voient-elles les mêmes campagnes ? $verdict\n";
} catch (Throwable $e) {
    echo '  croisement impossible : ', mb_substr($e->getMessage(), 0, 200), "\n";
}

// ── 6. Le passage en caisse côté ERP : valider, encaisser, annuler un bon ────
// « Rien en local » (Sam, 24/10) : le webshop devra valider et encaisser ses bons À L'ERP, pas dans sa base. La
// spécification publique liste /vouchers/validate, /redeem et /reverse sans en décrire le contrat : on envoie des
// corps VIDES, que l'API ne peut que refuser, pour lire les champs qu'elle exige. Aucun code n'est envoyé : rien ne
// peut être validé ni encaissé.
svTitre('routes de caisse /vouchers/* (corps vides : refus attendu, rien d\'encaissé)');
foreach (['/vouchers/validate', '/vouchers/redeem', '/vouchers/reverse'] as $ch) {
    $r = ErpApi::ecrire('POST', $ch, ['_sonde' => true]);
    echo '  POST ', str_pad($ch, 20), ' compte admin : ', svErreur($r), "\n";
    if ($r['code'] >= 200 && $r['code'] < 300) { echo '    ⚠ accepté — corps : ', mb_substr((string) json_encode(svAplatir($r['corps']), JSON_UNESCAPED_UNICODE), 0, 400), "\n"; }
}
$pc = ErpApi::sondeGet('/promo-codes?limit=5');
echo '  GET /promo-codes : ', $pc['code'] < 300 ? 'HTTP ' . $pc['code'] . ' · ' . count(svItems($pc['corps'])) . ' élément(s)' : svErreur($pc), "\n";
if ($pc['code'] < 300 && svItems($pc['corps']) !== []) { svChamps(array_slice(svItems($pc['corps']), 0, 5)); }

// ── 7. Le contrat de caisse, champ après champ ───────────────────────────────
// Troisième passage : validate rend {valid, validation_token, campaign, promotion, errors[]} ; redeem et reverse
// exigent d'abord un request_key. On ajoute un champ à la fois pour lire l'exigence suivante. Le code envoyé
// N'EXISTE PAS (ZZSONDE…) et le jeton est faux : rien ne peut être validé, encaissé ni annulé.
svTitre('contrat de caisse : exigence suivante, un champ à la fois (code inexistant, jeton faux)');
$cle = 'SONDE-COCKPIT-' . gmdate('YmdHis');
$essais = [
    ['/vouchers/validate', ['code' => 'ZZSONDE0000']],
    ['/vouchers/validate', ['code' => 'ZZSONDE0000', 'id_shop' => 2, 'channel' => 'WS', 'subtotal' => 10, 'items' => []]],
    ['/vouchers/redeem',   ['request_key' => $cle]],
    ['/vouchers/redeem',   ['request_key' => $cle, 'validation_token' => 'jeton-sonde-faux']],
    ['/vouchers/redeem',   ['request_key' => $cle, 'code' => 'ZZSONDE0000']],
    ['/vouchers/reverse',  ['request_key' => $cle]],
    ['/vouchers/reverse',  ['request_key' => $cle, 'id_redemption' => 999999999]],
];
foreach ($essais as [$ch, $corps]) {
    $r = ErpApi::ecrire('POST', $ch, $corps);
    printf("  POST %-18s %-70s → %s\n", $ch, json_encode(array_keys($corps)), svErreur($r));
    $c = $r['corps'];
    if (is_array($c) && isset($c['errors']) && is_array($c['errors']) && array_is_list($c['errors'])) {
        foreach ($c['errors'] as $e) { if (is_array($e)) { echo '      · ', $e['code'] ?? '?', ' — ', mb_substr((string) ($e['message'] ?? ''), 0, 160), "\n"; } }
    }
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($c)) { echo '      clés : ', implode(', ', array_keys($c)), "\n"; }
}

echo "\n=== FIN SONDE VOUCHERS ===\n";
