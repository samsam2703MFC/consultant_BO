<?php
/*
 * La documentation des promotions du panel, faite par le cockpit (23/10/2026, demande de Sam : « fais une sonde et crée
 * ta documentation »).
 *
 * La documentation publique (`api.php`) ne dit rien du seuil de ticket ni de la destination (comptoir, webshop), et
 * l'adresse que charge le Swagger officiel (atelier.tfbuddy.com/documentation/api.php) tombe en erreur PHP. Cette page
 * dit ce que le panel rend VRAIMENT : pour chaque sorte de promotion, chaque champ relu sur les fiches, son type, sa
 * fréquence, un exemple, s'il est documenté ou non, et ce que le cockpit y comprend (seuil, canal). Rien de nominatif
 * (pmSondePropre).
 *
 * `GET /ventes/promos/documentation?shop=4` — la page (HTML) ; `&format=json` — les mêmes données en JSON.
 * `&essai=1` — en plus, un ESSAI D'ÉCRITURE : une promotion « X + Y » de test est créée au panel avec les noms de seuil
 * et de canal plausibles, relue, puis supprimée aussitôt. Elle ne peut s'appliquer à aucun ticket : brouillon (DRAFT),
 * sur bon seulement (VOUCHER_ONLY), datée du 1er janvier 2020, nommée « SONDE COCKPIT — à supprimer ». Chaque nom de
 * seuil porte sa propre valeur (11,01 €, 11,02 €…) : la relecture dit lequel le panel a gardé.
 */

/** Les champs que la documentation publique décrit, par sorte (relue le 23/10/2026, empreinte 5b57f425…). */
const PD_DOCUMENTES = [
    'buy-x-get-y' => ['activation_mode', 'category_ids', 'day_of_week', 'description', 'excluded_product_ids', 'id', 'id_product', 'id_product_portion', 'is_exclusive', 'is_repeatable', 'max_applications_per_transaction', 'name', 'priority', 'product_id', 'product_ids', 'product_portion_id', 'promotion_type', 'quantity', 'reward', 'schedule', 'selection_strategy', 'shop_ids', 'shop_scope_type', 'status', 'time_from', 'time_to', 'trigger', 'type', 'valid_from', 'valid_to', 'value', 'voucher_campaign_id'],
    'bundles' => ['activation_mode', 'bundle_price', 'bundle_promotion', 'calculation_shop_id', 'day_of_week', 'description', 'id_product', 'id_product_portion', 'items', 'name', 'priority', 'promotion_type', 'quantity', 'schedule', 'shop_ids', 'shop_scope_type', 'sort_order', 'status', 'time_from', 'time_to'],
    'quantity' => ['calculation_shop_id', 'description', 'eligible_category_ids', 'eligible_product_ids', 'items', 'name', 'quantity_promotion', 'shop_ids', 'status'],
    'scheduled-product-discount' => ['activation_mode', 'day_of_week', 'description', 'discount_type', 'discount_value', 'excluded_product_ids', 'is_exclusive', 'is_repeatable', 'items', 'max_applications_per_transaction', 'name', 'priority', 'promotion_type', 'reward_category_ids', 'reward_product_ids', 'schedule', 'shop_ids', 'shop_scope_type', 'status', 'time_from', 'time_to', 'valid_from', 'valid_to', 'voucher_campaign_id'],
    'voucher-campaigns' => ['code_type', 'description', 'items', 'name', 'planned_promotion_type', 'requires_customer', 'shared_code', 'status', 'usage_limit_per_code', 'usage_limit_per_customer', 'usage_limit_total', 'valid_from', 'valid_to'],
];
/** Les champs techniques de toute fiche : ni documentés ni nouveaux. */
const PD_TECHNIQUES = ['id', 'created_at', 'updated_at', 'deleted_at', 'id_promotion', 'promotion_id'];
/** L'essai d'écriture : les noms de seuil plausibles, chacun avec sa valeur pour savoir lequel le panel garde. */
const PD_ESSAI_SEUIL = ['minimum_receipt_gross' => 11.13, 'minimum_basket_value' => 11.01, 'min_basket_value' => 11.02, 'min_order_amount' => 11.03, 'minimum_order_value' => 11.04,
    'min_ticket_amount' => 11.05, 'min_transaction_amount' => 11.06, 'trigger_amount' => 11.07, 'threshold_amount' => 11.08, 'min_amount' => 11.09,
    'minimum_amount' => 11.10, 'min_spend' => 11.11, 'threshold' => 11.12];
/** … et les noms de canal plausibles, chacun pour « webshop seulement », dans la forme qu'il a d'habitude. */
const PD_ESSAI_CANAL = ['sales_channel_scope' => 'WEBSHOP', 'destination' => 'WEBSHOP', 'destinations' => ['WEBSHOP'], 'sales_channels' => ['WEBSHOP'], 'channels' => ['WEBSHOP'],
    'sales_channel' => 'WEBSHOP', 'channel' => 'WEBSHOP', 'order_source' => 'WEBSHOP', 'sale_destination' => 'WEBSHOP',
    'is_webshop' => true, 'webshop_only' => true, 'is_pos' => false, 'available_on_pos' => false, 'available_on_webshop' => true];

/** Une fiche du panel : l'objet lui-même, qu'il vienne seul ou dans `data`. */
function pdFiche($f): ?array
{
    if (!is_array($f)) { return null; }
    return is_array($f['data'] ?? null) && isset($f['data']['id']) ? $f['data'] : $f;
}

/** Ce que le panel rend pour une sorte : la liste, quelques fiches, chaque champ avec son type, sa fréquence et un exemple. */
function pdSorte(string $sorte, int $shop): array
{
    $l = ErpApi::sondeGet('/admin/' . ($sorte === 'voucher-campaigns' ? '' : 'promotions/') . $sorte . '?limit=100');
    $items = pvItems($l['corps']);
    $ids = [];
    foreach ($items as $it) { if ((int) ($it['id'] ?? 0) > 0) { $ids[] = (int) $it['id']; } }
    rsort($ids);
    $choisis = array_slice($ids, 0, 8);
    if ($shop > 0) {
        foreach ($items as $it) { if (in_array($shop, array_map('intval', (array) ($it['shop_ids'] ?? [])), true)) { $choisis[] = (int) $it['id']; } }
    }
    $choisis = array_slice(array_values(array_unique($choisis)), 0, 12);
    $base = '/admin/' . ($sorte === 'voucher-campaigns' ? '' : 'promotions/') . $sorte . '/';
    $chemins = [];
    foreach ($choisis as $id) { $chemins[$id] = $base . $id; }
    $lues = $chemins !== [] ? ErpApi::getParallele($chemins, 4) : [];
    $fiches = [];
    foreach ($lues as $id => $f) { if (($f = pdFiche($f)) !== null) { $fiches[$id] = $f; } }
    // Une fiche illisible : la ligne de la liste en tient lieu.
    if ($fiches === []) { foreach (array_slice($items, 0, 8) as $it) { $fiches[(int) ($it['id'] ?? 0)] = $it; } }

    $champs = [];
    foreach ($fiches as $f) {
        foreach (pmSondeAplatir(pmSondePropre($f, true)) as $ch => [$type, $ex]) {
            $c = $champs[$ch] ?? ['types' => [], 'n' => 0, 'exemple' => null];
            $c['types'][$type] = true; $c['n']++;
            if ($c['exemple'] === null && $ex !== null && $ex !== '' && $type !== 'null') { $c['exemple'] = $ex; }
            $champs[$ch] = $c;
        }
    }
    ksort($champs);
    $doc = PD_DOCUMENTES[$sorte] ?? [];
    $out = [];
    foreach ($champs as $ch => $c) {
        $f = pmSondeFeuille($ch);
        $statut = in_array($f, PD_TECHNIQUES, true) ? 'technique' : (in_array($f, $doc, true) ? 'documenté' : 'non documenté');
        $out[] = ['champ' => $ch, 'types' => implode(' | ', array_keys($c['types'])), 'n' => $c['n'], 'exemple' => $c['exemple'], 'statut' => $statut,
            'condition' => preg_match(PM_MOTIF_CONDITION, $f) === 1 && !in_array($f, $doc, true)];
    }
    // Ce que le cockpit comprend de chaque fiche : un seuil, un canal, les noms des champs.
    $compris = [];
    foreach ($fiches as $id => $f) {
        $c = pvConditions($f);
        if ($c['seuil'] !== null || $c['canaux'] !== null || $c['cles']['canal'] !== null) {
            $compris[] = ['id' => $id, 'seuil' => $c['seuil'], 'canaux' => $c['canaux'], 'cles' => $c['cles']];
        }
    }
    return ['sorte' => $sorte, 'chemin' => '/api/v1' . rtrim($base, '/'), 'code' => $l['code'], 'erreur' => $l['erreur'], 'total' => count($items),
        'fichesLues' => count($fiches), 'champs' => $out, 'compris' => $compris,
        'nonDocumentes' => count(array_filter($out, static fn ($x) => $x['statut'] === 'non documenté'))];
}

/**
 * Un produit pour l'essai : le premier relu dans une promotion « X + Y » du panel — dans `product_ids`, `product_targets`
 * ou `products[]`, côté déclencheur ou avantage. Le 23/10/2026, les trois premières fiches n'étaient que des catégories :
 * on en lit jusqu'à douze.
 */
function pdProduitModele(): ?int
{
    $items = pvItems(ErpApi::sondeGet('/admin/promotions/buy-x-get-y?limit=50')['corps']);
    $premier = static function (array $fiches): ?int {
        foreach ($fiches as $it) {
            if (!is_array($it)) { continue; }
            foreach (['trigger', 'reward'] as $cote) {
                foreach (['product_ids', 'product_targets', 'products'] as $champ) {
                    foreach ((array) ($it[$cote][$champ] ?? []) as $x) {
                        $id = is_array($x) ? (int) ($x['id_product'] ?? $x['product_id'] ?? $x['id'] ?? 0) : (int) $x;
                        if ($id > 0) { return $id; }
                    }
                }
            }
        }
        return null;
    };
    // La ligne de liste ne porte pas toujours le déclencheur : sinon, les fiches.
    if (($id = $premier($items)) !== null) { return $id; }
    $chemins = [];
    foreach (array_slice($items, 0, 12) as $it) { if ((int) ($it['id'] ?? 0) > 0) { $chemins[] = '/admin/promotions/buy-x-get-y/' . (int) $it['id']; } }
    return $chemins ? $premier(array_map('pdFiche', ErpApi::getParallele($chemins, 4))) : null;
}

/** La valeur relue est-elle celle envoyée ? Les montants reviennent en texte (« 11.13 »), les listes dans le même ordre. */
function pdMeme($envoye, $relu): bool
{
    if (is_bool($envoye)) { return $relu === $envoye || $relu === (int) $envoye; }
    if (is_float($envoye) || is_int($envoye)) { return is_numeric($relu) && abs((float) $relu - (float) $envoye) < 0.005; }
    if (is_array($envoye)) { return is_array($relu) && array_map('strval', array_values($relu)) === array_map('strval', array_values($envoye)); }
    return is_scalar($relu) && (string) $relu === (string) $envoye;
}

/** Une tentative : créer, relire, supprimer. Rend ce que le panel a répondu et gardé. */
function pdTentative(string $nom, array $corps, array $candidats): array
{
    $r = ErpApi::ecrire('POST', '/admin/promotions/buy-x-get-y', $corps);
    $t = ['tentative' => $nom, 'code' => $r['code'], 'erreur' => $r['erreur'], 'cree' => null, 'garde' => [], 'ignore' => [], 'autre' => [], 'supprimee' => null, 'nouveaux' => []];
    if ($r['code'] < 200 || $r['code'] >= 300) {
        // Le détail du refus (les champs nommés) dit souvent ce que le panel connaît.
        $t['reponse'] = is_array($r['corps']) ? pmSondePropre($r['corps'], true) : null;
        return $t;
    }
    $c = pdFiche($r['corps']);
    $id = (int) ($c['id'] ?? $r['corps']['id'] ?? 0);
    $t['cree'] = $id ?: null;
    if ($id <= 0) { return $t; }
    $relu = pdFiche(ErpApi::get('/admin/promotions/buy-x-get-y/' . $id)) ?? $c ?? [];
    // Gardé : la valeur envoyée revient telle quelle. Un champ qui revient avec une autre valeur (le
    // `minimum_receipt_gross` vide de toute fiche) existe, mais n'a pas pris celle-ci.
    foreach ($candidats as $k => $v) {
        if (!array_key_exists($k, $relu)) { $t['ignore'][] = $k; }
        elseif (pdMeme($v, $relu[$k])) { $t['garde'][$k] = $relu[$k]; }
        else { $t['autre'][$k] = $relu[$k]; }
    }
    foreach (array_keys($relu) as $k) {
        if (!in_array($k, PD_DOCUMENTES['buy-x-get-y'], true) && !in_array($k, PD_TECHNIQUES, true) && !array_key_exists($k, $candidats)) {
            $t['nouveaux'][$k] = is_scalar($relu[$k]) || $relu[$k] === null ? $relu[$k] : '[' . gettype($relu[$k]) . ']';
        }
    }
    $d = ErpApi::ecrire('DELETE', '/admin/promotions/buy-x-get-y/' . $id);
    $t['supprimee'] = $d['code'] >= 200 && $d['code'] < 300;
    if (!$t['supprimee']) { $t['erreurSuppression'] = $d['erreur']; }
    return $t;
}

/**
 * L'essai d'écriture. D'abord tout ensemble ; si le panel refuse, le seuil seul, le canal seul, puis rien (pour savoir
 * si c'est un nom qui gêne ou la promotion elle-même). Quatre créations au plus, chacune supprimée aussitôt.
 */
function pdEssai(int $shop): array
{
    $pid = pdProduitModele();
    if ($pid === null) { return ['fait' => false, 'motif' => 'aucun produit relu dans les promotions « X + Y » du panel : l’essai a besoin d’un produit existant']; }
    $base = ['name' => 'SONDE COCKPIT — à supprimer', 'description' => 'Essai automatique du cockpit (documentation de l’API) : créé puis supprimé aussitôt.',
        'promotion_type' => 'BUY_X_GET_Y', 'status' => 'DRAFT', 'priority' => 1, 'is_exclusive' => false,
        'valid_from' => '2020-01-01 00:00:00', 'valid_to' => '2020-01-01 00:01:00', 'is_repeatable' => false, 'max_applications_per_transaction' => 1,
        'shop_scope_type' => 'SELECTED_SHOPS', 'activation_mode' => 'VOUCHER_ONLY', 'shop_ids' => [$shop], 'schedule' => [],
        'trigger' => ['quantity' => 1, 'product_ids' => [$pid], 'category_ids' => [], 'excluded_product_ids' => []],
        'reward' => ['quantity' => 1, 'product_ids' => [$pid], 'category_ids' => [], 'excluded_product_ids' => [], 'type' => 'FREE', 'value' => null, 'selection_strategy' => 'CHEAPEST']];
    $tout = PD_ESSAI_SEUIL + PD_ESSAI_CANAL;
    $T = [pdTentative('seuil et canal ensemble', $base + $tout, $tout)];
    if ($T[0]['cree'] === null) {
        $T[] = pdTentative('le seuil seul', $base + PD_ESSAI_SEUIL, PD_ESSAI_SEUIL);
        $T[] = pdTentative('le canal seul', $base + PD_ESSAI_CANAL, PD_ESSAI_CANAL);
        $T[] = pdTentative('sans rien de plus (témoin)', $base, []);
    }
    // Les valeurs du canal à une seule valeur (`sales_channel_scope`) : chaque côté essayé jusqu'à la première gardée.
    $jetons = null;
    if (array_key_exists('sales_channel_scope', array_merge(...array_map(static fn ($t) => $t['garde'] + $t['autre'], $T)))) {
        $jetons = [];
        foreach (['webshop' => ['WEBSHOP', 'ONLINE', 'WEB'], 'comptoir' => ['POS', 'SHOP', 'STORE', 'IN_STORE']] as $cote => $L) {
            foreach ($L as $jeton) {
                $t = pdTentative('sales_channel_scope = ' . $jeton, $base + ['sales_channel_scope' => $jeton], ['sales_channel_scope' => $jeton]);
                $T[] = $t;
                if (isset($t['garde']['sales_channel_scope'])) { $jetons[$cote] = $jeton; break; }
            }
        }
    }
    $garde = [];
    foreach ($T as $t) { $garde += $t['garde']; }
    $seuil = array_values(array_intersect(array_keys($garde), array_keys(PD_ESSAI_SEUIL)));
    $canal = array_values(array_intersect(array_keys($garde), array_keys(PD_ESSAI_CANAL)));
    return ['fait' => true, 'produit' => $pid, 'tentatives' => $T, 'seuilGarde' => $seuil, 'canalGarde' => $canal, 'jetons' => $jetons,
        'restees' => array_values(array_filter(array_map(static fn ($t) => $t['cree'] !== null && !$t['supprimee'] ? $t['cree'] : null, $T)))];
}

/** GET /ventes/promos/documentation?shop=4[&format=json][&essai=1] */
function ep_promos_documentation(): array
{
    if (!class_exists('ErpApi') || !ErpApi::disponible()) { http_response_code(503); return ['error' => 'compte admin du panel indisponible']; }
    $shop = max(0, (int) ($_GET['shop'] ?? 0));
    $D = ['lu' => date('Y-m-d H:i:s'), 'shop' => $shop ?: null, 'sortes' => []];
    foreach (['buy-x-get-y', 'bundles', 'quantity', 'scheduled-product-discount', 'voucher-campaigns'] as $s) { $D['sortes'][$s] = pdSorte($s, $shop); }
    $g = ErpApi::sondeGet('/admin/promotions?limit=20');
    $D['listeGenerale'] = ['code' => $g['code'], 'n' => count(pvItems($g['corps']))];
    $A = pmChampsAppris();
    $D['appris'] = ['seuil' => $A['seuil'], 'canal' => $A['canal'], 'jetons' => $A['jetons'], 'scalaire' => $A['scalaire'] ?? false];
    if (($_GET['essai'] ?? '') === '1') {
        $D['essai'] = $shop > 0 ? pdEssai($shop) : ['fait' => false, 'motif' => 'il faut un magasin (shop=…) pour l’essai'];
        // Ce que l'essai a appris ne vaut que pour cette page : le cockpit apprend des promotions réelles du panel.
    }
    if (($_GET['format'] ?? '') === 'json') { return $D; }
    header('Content-Type: text/html; charset=utf-8');
    echo pdHtml($D);
    exit;
}

/** La page : un résumé, l'essai, puis chaque sorte, champ par champ. */
function pdHtml(array $D): string
{
    $e = static fn ($v) => htmlspecialchars(is_scalar($v) || $v === null ? (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v) : json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $q = static fn (array $p) => '?' . http_build_query(array_filter(['shop' => $D['shop']] + $p, static fn ($v) => $v !== null && $v !== ''));
    $A = $D['appris'];
    $nonDoc = []; $conds = [];
    foreach ($D['sortes'] as $s => $S) {
        foreach ($S['champs'] as $c) {
            if ($c['statut'] === 'non documenté') { $nonDoc[] = $s . ' · ' . $c['champ']; }
            if ($c['condition']) { $conds[] = $s . ' · ' . $c['champ'] . ($c['exemple'] !== null ? ' = ' . $c['exemple'] : ''); }
        }
    }
    $oui = static fn ($b, $t) => '<span class="' . ($b ? 'ok' : 'ko') . '">' . ($b ? '✓ ' : '✗ ') . $t . '</span>';
    $h = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>API des promotions — documentation du cockpit</title><style>
body{font:14px/1.5 -apple-system,"Segoe UI",Roboto,sans-serif;color:#222;background:#f5f1ec;margin:0;padding:24px}main{max-width:1180px;margin:0 auto}
h1{font-size:24px;margin:0 0 4px}h2{font-size:17px;margin:28px 0 8px}.mu{color:#777}.ok{color:#2d7a3e;font-weight:600}.ko{color:#C0182B;font-weight:600}.att{color:#B26A00;font-weight:600}
.carte{background:#fff;border-radius:12px;padding:14px 18px;margin:12px 0;border:1px solid #e6ded3}table{border-collapse:collapse;width:100%;font-size:12.5px}
th,td{text-align:left;padding:5px 8px;border-top:1px solid #eee;vertical-align:top}th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#777;border-top:none}
code{font:12px ui-monospace,Menlo,monospace;background:#f3eee7;padding:1px 5px;border-radius:4px}tr.nd td{background:#fff7e6}tr.cd td:first-child{box-shadow:inset 3px 0 #8D1D2C}
.btn{display:inline-block;background:#8D1D2C;color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;font-weight:600}.btn.b{background:#fff;color:#222;border:1px solid #ccc}
ul{margin:6px 0;padding-left:20px}</style></head><body><main>';
    $h .= '<h1>API des promotions — la documentation du cockpit</h1><div class="mu">Lue au panel le ' . $e($D['lu']) . ($D['shop'] ? ' · magasin ' . $e($D['shop']) : '') . ' · rien de nominatif · <a href="' . $e($q(['format' => 'json'])) . '">en JSON</a></div>';
    // Le résumé
    $h .= '<div class="carte"><b>En bref</b><ul>'
        . '<li>Seuil du ticket : ' . ($A['seuil'] !== null ? $oui(true, 'le champ existe au panel : <code>' . $e($A['seuil']) . '</code>') : $oui(false, 'aucune promotion du panel n’en porte')) . '</li>'
        . '<li>Destination / canal : ' . ($A['canal'] !== null ? $oui(true, 'le champ existe au panel : <code>' . $e($A['canal']) . '</code>' . ($A['jetons'] ? ' (valeurs : ' . $e(implode(', ', array_map(static fn ($k, $v) => $k . ' = ' . $v, array_keys($A['jetons']), $A['jetons']))) . ')' : '')) : $oui(false, 'aucune promotion du panel n’en porte')) . '</li>'
        . '<li>Champs non documentés relus : ' . (count($nonDoc) ? '<span class="att">' . count($nonDoc) . '</span> — ' . $e(implode(' · ', array_slice($nonDoc, 0, 12))) . (count($nonDoc) > 12 ? '…' : '') : '<span class="mu">aucun</span>') . '</li>'
        . '<li>Champs qui ressemblent à un seuil ou un canal (hors documentation) : ' . (count($conds) ? '<span class="att">' . $e(implode(' · ', $conds)) . '</span>' : '<span class="mu">aucun</span>') . '</li>'
        . '<li>Liste générale <code>/admin/promotions</code> : ' . ($D['listeGenerale']['code'] >= 200 && $D['listeGenerale']['code'] < 300 ? $D['listeGenerale']['n'] . ' promotion(s)' : 'HTTP ' . $e($D['listeGenerale']['code'])) . '</li></ul>'
        . '<div class="mu">Le cockpit apprend les noms de champs d’une promotion du panel qui porte un seuil ou une destination : créez-en une au panel, puis rechargez cette page.</div></div>';
    // L'essai
    if (!isset($D['essai'])) {
        $h .= '<div class="carte"><b>Essai d’écriture</b> <span class="mu">— savoir si le panel GARDE un seuil et une destination envoyés par l’API.</span><p>Le cockpit crée au panel une promotion de test « SONDE COCKPIT — à supprimer », en <b>brouillon</b>, <b>sur bon seulement</b>, datée du <b>1er janvier 2020</b> (elle ne peut s’appliquer à aucun ticket), avec les noms de seuil et de destination plausibles ; il la relit, puis la <b>supprime aussitôt</b>. Quatre créations au plus.</p><a class="btn" href="' . $e($q(['essai' => '1'])) . '">Lancer l’essai d’écriture</a></div>';
    } else {
        $X = $D['essai'];
        $h .= '<div class="carte"><b>Essai d’écriture</b> ';
        if (!$X['fait']) { $h .= '<span class="ko">pas fait</span> — ' . $e($X['motif']); }
        else {
            $h .= '<ul><li>Seuil gardé par le panel : ' . ($X['seuilGarde'] ? $oui(true, $e(implode(', ', $X['seuilGarde']))) : $oui(false, 'aucun des noms essayés')) . '</li>'
                . '<li>Destination gardée par le panel : ' . ($X['canalGarde'] ? $oui(true, $e(implode(', ', $X['canalGarde']))) : $oui(false, 'aucun des noms essayés')) . '</li>'
                . (isset($X['jetons']) ? '<li>Valeurs de <code>sales_channel_scope</code> gardées : webshop ' . (isset($X['jetons']['webshop']) ? '<span class="ok">' . $e($X['jetons']['webshop']) . '</span>' : '<span class="ko">aucune des essayées</span>') . ' · comptoir ' . (isset($X['jetons']['comptoir']) ? '<span class="ok">' . $e($X['jetons']['comptoir']) . '</span>' : '<span class="ko">aucune des essayées</span>') . '</li>' : '')
                . ($X['restees'] ? '<li><span class="ko">Promotion(s) de test non supprimée(s) : n° ' . $e(implode(', ', $X['restees'])) . ' — à supprimer au panel.</span></li>' : '<li class="ok">Toutes les promotions de test ont été supprimées.</li>') . '</ul>';
            $h .= '<table><tr><th>Tentative</th><th>Réponse</th><th>Gardé</th><th>Revenu autrement</th><th>Ignoré</th><th>Autres champs relus</th></tr>';
            foreach ($X['tentatives'] as $t) {
                $h .= '<tr><td>' . $e($t['tentative']) . '</td><td>' . ($t['cree'] !== null ? '<span class="ok">créée (n° ' . $e($t['cree']) . ')' . ($t['supprimee'] ? ', supprimée' : '') . '</span>' : '<span class="ko">' . $e($t['erreur'] ?? ('HTTP ' . $t['code'])) . '</span>') . '</td>'
                    . '<td>' . $e($t['garde'] ? json_encode($t['garde'], JSON_UNESCAPED_UNICODE) : '—') . '</td><td>' . $e(($t['autre'] ?? []) ? json_encode($t['autre'], JSON_UNESCAPED_UNICODE) : '—') . '</td><td class="mu">' . $e(implode(', ', $t['ignore'])) . '</td><td>' . $e($t['nouveaux'] ? json_encode($t['nouveaux'], JSON_UNESCAPED_UNICODE) : '—') . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '</div>';
    }
    // Chaque sorte
    foreach ($D['sortes'] as $s => $S) {
        $h .= '<h2>' . $e($s) . ' <span class="mu" style="font-weight:400">— <code>GET ' . $e($S['chemin']) . '</code> · ' . ($S['code'] >= 200 && $S['code'] < 300 ? $S['total'] . ' promotion(s), ' . $S['fichesLues'] . ' fiche(s) relue(s)' : '<span class="ko">' . $e($S['erreur'] ?? ('HTTP ' . $S['code'])) . '</span>') . '</span></h2>';
        if ($S['compris']) {
            $h .= '<div class="carte"><b>Ce que le cockpit comprend</b><ul>';
            foreach ($S['compris'] as $c) {
                $h .= '<li>n° ' . $e($c['id']) . ' : seuil ' . $e($c['seuil'] ?? '—') . ', canal ' . $e($c['canaux'] === null ? 'comptoir et webshop' : implode(', ', $c['canaux'])) . ' (champs : ' . $e(json_encode(array_filter($c['cles']), JSON_UNESCAPED_UNICODE)) . ')</li>';
            }
            $h .= '</ul></div>';
        }
        if (!$S['champs']) { $h .= '<div class="mu">Aucune fiche relue.</div>'; continue; }
        $h .= '<div class="carte"><table><tr><th>Champ</th><th>Type</th><th>Fiches</th><th>Exemple</th><th>Documentation</th></tr>';
        foreach ($S['champs'] as $c) {
            $h .= '<tr class="' . ($c['statut'] === 'non documenté' ? 'nd' : '') . ($c['condition'] ? ' cd' : '') . '"><td><code>' . $e($c['champ']) . '</code></td><td>' . $e($c['types']) . '</td><td>' . $e($c['n']) . ' / ' . $e($S['fichesLues']) . '</td><td>' . $e($c['exemple'] ?? '') . '</td><td class="' . ($c['statut'] === 'non documenté' ? 'att' : 'mu') . '">' . $e($c['statut']) . '</td></tr>';
        }
        $h .= '</table></div>';
    }
    return $h . '<p class="mu">Surligné : champ absent de la documentation publique. Liseré : son nom ressemble à un seuil ou à un canal.</p></main></body></html>';
}
