<?php
declare(strict_types=1);

/*
 * ESSAI DE BOUT EN BOUT — le bon du dashboard magasin (#50) contre l'ERP réel (24/10/2026, accord de Sam : « oui fais
 * l'essai »). Il passe par les routes EN LIGNE du cockpit, comme le dashboard, et relit l'ERP après chaque étape :
 *   1. brouillon avec un bon « un code pour tous » → promotion INACTIVE en VOUCHER_ONLY, campagne SHARED rattachée ;
 *   2. « Voir les codes » (GET …/bon) ;
 *   3. envoi en caisse → promotion ACTIVE, campagne ACTIVE et complète, la même campagne ;
 *   4. le code à la caisse de l'ERP (POST /vouchers/validate) ;
 *   5. la bascule : hors service (le code refusé), puis réactivé ;
 *   6. le ménage (finally) : bon hors service et détaché, promotion INACTIVE puis supprimée par le cockpit.
 *
 * La promotion ne peut servir à personne : 1 % sur UN produit, au magasin ESSAI_SHOP seul, jusqu'à demain, par code
 * seulement ; le code est généré (8 signes, masqué dans le journal), une utilisation au plus, actif une minute environ.
 * Rien n'est encaissé (pas de /vouchers/redeem). Reste dans l'ERP : la campagne, hors service et détachée (l'API ne
 * supprime pas les campagnes).
 *
 *   php -- /var/www/consulant_bo essai       — l'essai, ménage compris
 *   php -- /var/www/consulant_bo nettoyage   — le ménage seul, d'une promotion d'essai restée (retrouvée par son nom)
 */

$dir  = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
$mode = (string) ($argv[2] ?? 'essai');
foreach (['Db.php', 'endpoints.php', 'installer.php', 'writes.php', 'erp_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require "$dir/src/$f";
}
if (!ErpApi::disponible() || !method_exists('ErpApi', 'ecrire')) { echo "compte admin ERP indisponible : essai impossible\n"; exit(1); }

define('EB_APP', getenv('EB_APP') ?: 'http://185.180.206.46/consulant_bo/api/cockpit');
const EB_SHOP = 4;
const EB_NOM  = '[ESSAI COCKPIT — à ignorer] bon du dashboard 24/10';

$GLOBALS['ebCodes'] = [];
/** Un code ou un jeton se masque : les trois premiers signes et la longueur. */
function ebMasque(string $s): string { return mb_strlen($s) <= 3 ? '***' : mb_substr($s, 0, 3) . '…(' . mb_strlen($s) . ')'; }
function ebPropre(string $s): string
{
    foreach ($GLOBALS['ebCodes'] as $c) { if ($c !== '') { $s = str_replace($c, ebMasque($c), $s); } }
    return preg_replace('/("(?:token|validation_token|request_key)"\s*:\s*")[^"]+/', '$1…', $s);
}
function ebTitre(string $t): void { echo "\n=== $t ===\n"; }

$GLOBALS['ebBilan'] = [];
function ebVerif(bool $ok, string $quoi, string $detail = ''): bool
{
    $GLOBALS['ebBilan'][] = [$ok, $quoi];
    printf("  %s  %s%s\n", $ok ? 'ok' : 'KO', $quoi, $detail !== '' ? ' — ' . ebPropre($detail) : '');
    return $ok;
}

/** Une route du cockpit en ligne : [code HTTP, corps décodé]. */
function ebCockpit(string $m, string $chemin, ?array $corps = null): array
{
    $ch = curl_init(EB_APP . $chemin);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
    if ($corps !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps, JSON_UNESCAPED_UNICODE)); }
    $brut = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($brut) ? json_decode($brut, true) : null;
    printf("  %-6s %-58s → HTTP %d\n", $m, preg_replace('/\?.*/', '', $chemin), $code);
    if ($code >= 300 || !is_array($j)) { echo '         ', ebPropre(mb_substr((string) $brut, 0, 600)), "\n"; }
    return [$code, is_array($j) ? $j : []];
}

/** Une fiche de l'ERP, relue telle quelle (le corps sous `data` quand il y en a un). */
function ebErp(string $chemin): ?array
{
    $r = ErpApi::sondeGet($chemin);
    $c = $r['corps'] ?? null;
    if (is_array($c) && isset($c['data']) && is_array($c['data'])) { $c = $c['data']; }
    return ($r['code'] ?? 0) >= 200 && ($r['code'] ?? 0) < 300 && is_array($c) ? $c : null;
}
function ebCampagne(int $id): ?array { $c = ebErp('/admin/voucher-campaigns/' . $id); return is_array($c['campaign'] ?? null) ? $c['campaign'] + $c : $c; }
function ebPromo(int $id): ?array { return ebErp('/admin/promotions/buy-x-get-y/' . $id); }

function ebValider(string $code): array
{
    $r = ErpApi::ecrire('POST', '/vouchers/validate', ['code' => $code, 'id_shop' => EB_SHOP]);
    $c = is_array($r['corps'] ?? null) ? $r['corps'] : [];
    $d = is_array($c['data'] ?? null) ? $c['data'] : $c;
    printf("  POST   %-58s → HTTP %d %s\n", '/vouchers/validate {code, id_shop}', $r['code'],
        ebPropre(mb_substr((string) json_encode(['valid' => $d['valid'] ?? null, 'promotion' => $d['promotion']['id'] ?? ($d['id_promotion'] ?? null),
            'erreur' => $c['error']['code'] ?? ($c['code'] ?? ($r['erreur'] ?? null))], JSON_UNESCAPED_UNICODE), 0, 300)));
    return [$r['code'], $d, $c];
}

/** Le ménage d'une promotion d'essai et de sa campagne. Chaque pas se tente, quoi qu'il arrive au précédent. */
function ebMenage(?int $promo, ?int $camp): void
{
    ebTitre('ménage');
    if ($camp !== null) {
        $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $camp . '/status', ['status' => 'INACTIVE']);
        if ($w['erreur'] !== null) {
            $c = ebCampagne($camp);
            $w = ErpApi::ecrire('PATCH', '/admin/voucher-campaigns/' . $camp, ['status' => 'INACTIVE', 'code_type' => $c['code_type'] ?? 'SHARED', 'shared_code' => $c['shared_code'] ?? null]);
        }
        printf("  campagne #%d hors service : HTTP %d %s\n", $camp, $w['code'], $w['erreur'] ?? '');
    }
    if ($promo !== null) {
        $w = ErpApi::ecrire('PATCH', '/admin/promotions/buy-x-get-y/' . $promo, ['status' => 'INACTIVE']);
        printf("  promotion n° %d INACTIVE : HTTP %d %s\n", $promo, $w['code'], $w['erreur'] ?? '');
    }
    if ($camp !== null) {
        $w = ErpApi::ecrire('DELETE', '/admin/voucher-campaigns/' . $camp . '/promotion');
        printf("  campagne #%d détachée : HTTP %d %s\n", $camp, $w['code'], $w['erreur'] ?? '');
    }
    if ($promo !== null) {
        // Le cockpit relit le panel (?frais=1, une relecture toutes les 20 s au plus), puis supprime le brouillon.
        sleep(21);
        ebCockpit('GET', '/exploitation/promos-magasin?shop=' . EB_SHOP . '&frais=1');
        [$c] = ebCockpit('DELETE', '/exploitation/promos-magasin?shop=' . EB_SHOP . '&id=p' . $promo);
        if ($c >= 300) {
            $w = ErpApi::ecrire('DELETE', '/admin/promotions/buy-x-get-y/' . $promo);
            printf("  supprimée directement dans l'ERP : HTTP %d %s\n", $w['code'], $w['erreur'] ?? '');
        }
        ebVerif(ebPromo($promo) === null, 'la promotion d’essai n’existe plus dans l’ERP');
        sleep(21);
        [, $l] = ebCockpit('GET', '/exploitation/promos-magasin?shop=' . EB_SHOP . '&frais=1');
        $reste = array_filter((array) ($l['demandes'] ?? []), static fn ($d) => (int) ($d['panelId'] ?? 0) === $promo);
        ebVerif($reste === [], 'le dashboard ne la montre plus');
    }
    if ($camp !== null) {
        $c = ebCampagne($camp);
        ebVerif($c !== null && strtoupper((string) ($c['status'] ?? '')) !== 'ACTIVE' && empty($c['id_promotion']),
            'la campagne reste hors service et détachée', 'statut ' . ($c['status'] ?? '?') . ', promotion ' . json_encode($c['id_promotion'] ?? null));
    }
}

function ebBilan(): void
{
    ebTitre('bilan');
    $ko = array_filter($GLOBALS['ebBilan'], static fn ($v) => !$v[0]);
    printf("  %d vérification(s), %d KO\n", count($GLOBALS['ebBilan']), count($ko));
    foreach ($ko as $v) { echo '  KO  ', $v[1], "\n"; }
    if ($ko !== []) { exit(1); }
}

// ── nettoyage seul ───────────────────────────────────────────────────────────
if ($mode === 'nettoyage') {
    [, $l] = ebCockpit('GET', '/exploitation/promos-magasin?shop=' . EB_SHOP . '&frais=1');
    $trouve = false;
    foreach ((array) ($l['demandes'] ?? []) as $d) {
        if (($d['nom'] ?? '') !== EB_NOM) { continue; }
        $trouve = true;
        $camp = (int) ($d['bon']['campagne'] ?? 0) ?: null;
        if ($camp === null) {
            $lc = ErpApi::sondeGet('/admin/voucher-campaigns?limit=10&id_promotion=' . (int) $d['panelId']);
            $items = $lc['corps']['items'] ?? ($lc['corps']['data']['items'] ?? ($lc['corps']['data'] ?? []));
            foreach ((array) $items as $it) { if (is_array($it) && (int) ($it['id_promotion'] ?? 0) === (int) $d['panelId']) { $camp = (int) $it['id']; break; } }
        }
        ebMenage((int) $d['panelId'], $camp);
    }
    if (!$trouve) { echo "  aucune promotion « " . EB_NOM . " » au magasin " . EB_SHOP . "\n"; }
    ebBilan();
    exit(0);
}

// ── l'essai ──────────────────────────────────────────────────────────────────
$promo = null; $camp = null;
try {
    ebTitre('0. avant');
    [$c, $l] = ebCockpit('GET', '/exploitation/promos-magasin?shop=' . EB_SHOP . '&frais=1');
    if ($c !== 200) { throw new RuntimeException('liste des promotions illisible (HTTP ' . $c . ')'); }
    foreach ((array) ($l['demandes'] ?? []) as $d) {
        if (($d['nom'] ?? '') === EB_NOM) { throw new RuntimeException('une promotion d’essai est restée (p' . $d['panelId'] . ') : lancer « nettoyage » d’abord'); }
    }
    printf("  %d promotion(s) du magasin %d au panel, avant l'essai\n", count((array) ($l['demandes'] ?? [])), EB_SHOP);

    [$c, $pr] = ebCockpit('GET', '/exploitation/promos-magasin/produits?shop=' . EB_SHOP);
    $choix = array_values(array_filter((array) ($pr['produits'] ?? []), static fn ($p) => ($p['source'] ?? '') === 'magasin' && (float) ($p['cout'] ?? 0) > 0 && (float) ($p['prixTtc'] ?? 0) > 0));
    if ($choix === []) { throw new RuntimeException('aucun produit chiffré au magasin ' . EB_SHOP); }
    usort($choix, static fn ($a, $b) => (float) $a['prixTtc'] <=> (float) $b['prixTtc']);
    $p = $choix[0];
    printf("  produit : n° %d « %s », %.2f € TTC, coût %.2f €\n", $p['id'], $p['nom'], $p['prixTtc'], $p['cout']);

    $corps = ['shop' => EB_SHOP, 'nom' => EB_NOM, 'description' => 'Essai technique du cockpit, supprimé à la fin de l’essai.',
        'x' => ['q' => 1, 'prods' => [['id' => (int) $p['id']]]],
        'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 1, 'prods' => [['id' => (int) $p['id']]]],
        'du' => date('Y-m-d') . ' 00:00', 'au' => date('Y-m-d', strtotime('+1 day')) . ' 23:00',
        'creneaux' => [['jours' => [1, 2, 3, 4, 5, 6, 7], 'de' => '00:00', 'a' => '23:59']],
        'repetable' => false, 'maxParTicket' => 1,
        'bon' => ['on' => true, 'cible' => 'code', 'code' => '', 'limite' => 1]];

    ebTitre('1. brouillon avec un bon « un code pour tous »');
    [$c, $r] = ebCockpit('POST', '/exploitation/promos-magasin', $corps + ['action' => 'brouillon']);
    $promo = (int) ($r['demande']['panelId'] ?? 0) ?: null;
    $camp = (int) ($r['bon']['campagne'] ?? 0) ?: null;
    $code = (string) ($r['bon']['code'] ?? '');
    $GLOBALS['ebCodes'][] = $code;
    ebVerif($c === 200 && $promo !== null, 'la promotion est créée', 'p' . ($promo ?? '?') . ($r['panelErreur'] ?? null ? ' · ' . $r['panelErreur'] : ''));
    if ($promo === null) { throw new RuntimeException('pas de promotion : essai arrêté'); }
    ebVerif(($r['demande']['statut'] ?? '') === 'brouillon', 'elle reste en brouillon', (string) ($r['demande']['statut'] ?? '?'));
    ebVerif($camp !== null && ($r['bon']['cible'] ?? '') === 'code' && $code !== '', 'son bon : une campagne, un code pour tous, un code généré',
        'campagne #' . ($camp ?? '?') . ', code ' . ($code !== '' ? ebMasque($code) : '—'));
    $f = ebPromo($promo);
    ebVerif(strtoupper((string) ($f['status'] ?? '')) === 'INACTIVE' && strtoupper((string) ($f['activation_mode'] ?? '')) === 'VOUCHER_ONLY',
        'dans l’ERP : promotion INACTIVE, par code seulement', ($f['status'] ?? '?') . ' / ' . ($f['activation_mode'] ?? '?'));
    if ($camp !== null) {
        $k = ebCampagne($camp);
        ebVerif(($k['code_type'] ?? '') === 'SHARED' && (int) ($k['id_promotion'] ?? 0) === $promo && strtoupper((string) ($k['status'] ?? '')) !== 'ACTIVE',
            'dans l’ERP : campagne SHARED, rattachée, pas encore active',
            ($k['code_type'] ?? '?') . ', promotion ' . json_encode($k['id_promotion'] ?? null) . ', ' . ($k['status'] ?? '?') . ', limite ' . json_encode($k['usage_limit_total'] ?? null) . ', fin ' . ($k['valid_to'] ?? '?'));
    }

    ebTitre('2. « Voir les codes »');
    [$c, $v] = ebCockpit('GET', '/exploitation/promos-magasin/bon?shop=' . EB_SHOP . '&id=p' . $promo);
    ebVerif($c === 200 && (int) ($v['bon']['campagne'] ?? 0) === $camp && ($v['bon']['code'] ?? '') === $code,
        'le bon relu : la même campagne, le même code', 'statut ' . ($v['bon']['statut'] ?? '?') . ', actif ' . json_encode($v['bon']['actif'] ?? null));

    ebTitre('3. envoi en caisse');
    [$c, $r] = ebCockpit('POST', '/exploitation/promos-magasin', $corps + ['action' => 'envoyer', 'id' => 'p' . $promo]);
    ebVerif($c === 200 && ($r['demande']['statut'] ?? '') === 'en_caisse' && ($r['panelErreur'] ?? null) === null,
        'la promotion passe en caisse', ($r['demande']['statut'] ?? '?') . (($r['panelErreur'] ?? null) ? ' · ' . $r['panelErreur'] : '') . (($r['erreurs'] ?? null) ? ' · ' . implode(' ', $r['erreurs']) : ''));
    ebVerif((int) ($r['bon']['campagne'] ?? 0) === $camp && !empty($r['bon']['actif']) && !empty($r['bon']['complete']),
        'son bon : la même campagne, active et complète', 'campagne #' . ($r['bon']['campagne'] ?? '?') . ', manque ' . json_encode($r['bon']['manque'] ?? null));
    if ((int) ($r['bon']['campagne'] ?? 0) > 0 && (int) $r['bon']['campagne'] !== $camp) { ebMenage(null, $camp); $camp = (int) $r['bon']['campagne']; }
    $f = ebPromo($promo);
    ebVerif(strtoupper((string) ($f['status'] ?? '')) === 'ACTIVE' && strtoupper((string) ($f['activation_mode'] ?? '')) === 'VOUCHER_ONLY',
        'dans l’ERP : promotion ACTIVE, par code seulement', ($f['status'] ?? '?') . ' / ' . ($f['activation_mode'] ?? '?'));
    $k = ebCampagne($camp);
    ebVerif(strtoupper((string) ($k['status'] ?? '')) === 'ACTIVE' && !empty($k['configuration_complete']),
        'dans l’ERP : campagne ACTIVE, configuration complète', ($k['status'] ?? '?') . ', manque ' . json_encode($k['missing_requirements'] ?? null));

    ebTitre('4. le code à la caisse de l’ERP');
    [$c, $d] = ebValider($code);
    ebVerif($c === 200 && ($d['valid'] ?? true) !== false, 'le code est accepté au magasin ' . EB_SHOP);

    ebTitre('5. la bascule de la liste');
    [$c, $r] = ebCockpit('POST', '/exploitation/promos-magasin/bon', ['shop' => EB_SHOP, 'id' => 'p' . $promo, 'actif' => false]);
    ebVerif($c === 200 && empty($r['bon']['actif']), 'hors service', 'statut ' . ($r['bon']['statut'] ?? '?'));
    [$c, $d] = ebValider($code);
    ebVerif($c >= 400 || ($d['valid'] ?? null) === false, 'le code est refusé à la caisse');
    [$c, $r] = ebCockpit('POST', '/exploitation/promos-magasin/bon', ['shop' => EB_SHOP, 'id' => 'p' . $promo, 'actif' => true]);
    ebVerif($c === 200 && !empty($r['bon']['actif']) && !empty($r['bon']['complete']), 'réactivé, complet', 'statut ' . ($r['bon']['statut'] ?? '?'));
    [$c] = ebValider($code);
    ebVerif($c === 200, 'le code est de nouveau accepté');
} catch (Throwable $e) {
    echo "\n  ⇒ essai interrompu : ", ebPropre($e->getMessage()), "\n";
    ebVerif(false, 'essai mené jusqu’au bout');
} finally {
    ebMenage($promo, $camp);
    ebBilan();
}
