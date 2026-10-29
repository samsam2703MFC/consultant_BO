<?php
declare(strict_types=1);

/*
 * SONDE — les clients d'un magasin pour le bon d'une promotion (29/10/2026, demande de Sam : « quand je fais un voucher,
 * choisir un client B2B ou un client normal (rattaché au magasin) … check la sonde API »).
 *
 * Question : comment l'API admin TFBuddy rend-elle les clients d'un magasin, B2B et particuliers ? Le Swagger donne
 * `GET /shops/{shopId}/clients?limit=&offset=&type=&vat_id=` sans dire ce que fait `type` sur cette route (sur
 * `GET /clients`, « b2b selects B2B; every other supplied value selects B2C »). La sonde du 24/10 a vu 8 626 clients
 * au magasin 2 (704 B2B) avec `limit=5`, mais 500 avec `limit=500` — et le bon n'en lit aujourd'hui que 2 000 au plus.
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-clients-bon.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_clients_bon.php
 *
 * LECTURE SEULE : des GET, rien d'autre. Rien de nominatif : des comptes, des clés, des durées.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'endpoints.php', 'installer.php', 'writes.php', 'erp_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require "$dir/src/$f";
}

function scTitre(string $t): void { echo "\n=== $t ===\n"; }

/** Les éléments d'une réponse ({items}, {data: {items}}, {data: […]}, ou une liste nue). */
function scItems($corps): array
{
    if (!is_array($corps)) { return []; }
    foreach ([$corps['items'] ?? null, $corps['data']['items'] ?? null, $corps['data'] ?? null, $corps] as $n) {
        if (is_array($n) && array_is_list($n)) { return $n; }
    }
    return [];
}

/** Un GET chronométré : [code, éléments, secondes, octets, clés du corps hors liste]. */
function scGet(string $ch): array
{
    $t = microtime(true);
    $r = ErpApi::sondeGet($ch);
    $s = microtime(true) - $t;
    $it = scItems($r['corps']);
    $octets = is_array($r['corps']) ? strlen((string) json_encode($r['corps'])) : 0;
    $meta = is_array($r['corps']) && !array_is_list($r['corps']) ? array_keys(array_diff_key($r['corps'], array_flip(['items', 'data']))) : [];
    return [$r['code'], $it, $s, $octets, $meta, $r['erreur']];
}

/** La répartition d'un champ : {valeur: nombre}. */
function scRep(array $items, string $cle): string
{
    $r = [];
    foreach ($items as $it) {
        $v = is_array($it) ? ($it[$cle] ?? null) : null;
        $k = $v === null ? 'null' : (is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : 'objet'));
        $r[$k] = ($r[$k] ?? 0) + 1;
    }
    arsort($r);
    return json_encode(array_slice($r, 0, 8, true), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
}

function scLigne(string $ch, array $g, int $shop): void
{
    [$code, $it, $s, $o, $meta, $err] = $g;
    $b2b = count(array_filter($it, static fn ($x) => is_array($x) && !empty($x['is_b2b'])));
    $main = count(array_filter($it, static fn ($x) => is_array($x) && (int) ($x['id_main_shop'] ?? 0) === $shop));
    printf("  %-58s HTTP %3d · %5d ligne(s) · b2b %5d · id_main_shop=%d %5d · %5.1f s · %6d ko%s%s\n", $ch, $code, count($it), $b2b, $shop, $main, $s, intdiv($o, 1024),
        $meta !== [] ? ' · méta ' . implode(',', $meta) : '', $err !== null ? ' · ' . $err : '');
}

/** La route du bon en ligne, telle que l'écran l'appelle : des comptes et des durées, aucun nom. */
function scEnLigne(array $appels): void
{
    scTitre('la route du bon en ligne : GET /exploitation/promos-magasin/clients');
    foreach ($appels as [$qs, $lib]) {
        $t = microtime(true);
        $ctx = stream_context_create(['http' => ['timeout' => 90, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://185.180.206.46/consulant_bo/api/cockpit/exploitation/promos-magasin/clients?' . $qs, false, $ctx);
        $s = microtime(true) - $t;
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || !isset($j['clients'])) { printf("  %-46s HTTP %d · %5.1f s · %s\n", $lib, $code, $s, mb_substr((string) $raw, 0, 200)); continue; }
        $cl = $j['clients'];
        printf("  %-46s HTTP %d · %5.1f s (serveur %s ms) · lue à %s · total %5d · trouvés %5d · rendus %2d · B2B %2d · nB2b %s · nParticuliers %s\n", $lib, $code, $s,
            json_encode($j['ms'] ?? null), json_encode($j['lu'] ?? null), $j['total'] ?? -1, $j['trouves'] ?? -1, count($cl),
            count(array_filter($cl, static fn ($c) => !empty($c['b2b']))), json_encode($j['nB2b'] ?? null), json_encode($j['nParticuliers'] ?? null));
    }
}

scTitre('compte admin ERP du cockpit');
if (!ErpApi::disponible()) { echo "  ⇒ aucun identifiant ni jeton : sonde impossible\n"; exit(1); }
echo '  base=', ErpApi::config()['base'], "\n";

// ── La vitesse de la liste des particuliers (29/10/2026, après #76) ──────────────────────────────────────────────
// La route en ligne a mis 12,8 s à froid pour les 7 935 particuliers du magasin 2 (16 pages de 500, quatre de front).
// D'où vient le temps, et la mémoire de PHP-FPM tient-elle une lecture d'un seul tenant (10 Mo) ? Lecture seule.
// « vitesse » : la mesure ci-dessous ; « en-ligne » : la route après une mise en ligne ; « tout » : aussi les relevés du matin.
$passage = 'en-ligne';
if ($passage === 'en-ligne') {
    // La sonde peut partir pendant la mise en ligne : elle attend que la route rende `ms` (#77), sept minutes au plus.
    for ($i = 0, $pret = false; $i < 28 && !$pret; $i++) {
        $j = json_decode((string) @file_get_contents('http://185.180.206.46/consulant_bo/api/cockpit/exploitation/promos-magasin/clients?shop=4&type=b2b&q=',
            false, stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]])), true);
        $pret = is_array($j) && array_key_exists('ms', $j);
        if (!$pret) { sleep(15); }
    }
    echo $pret ? "  la route rend « ms » : #77 est en ligne\n" : "  ⇒ #77 pas en ligne après sept minutes : mesure de l'ancienne route\n";
    // Après #77 : lue d'un seul tenant, gardée une heure. « frais » force la première lecture à froid.
    scEnLigne([['shop=2&type=particulier&q=&frais=1', 'particuliers du magasin 2, relus (froid)'], ['shop=2&type=particulier&q=ma', 'particuliers du magasin 2, « ma » (gardé)'],
        ['shop=2&type=particulier&q=mar', 'particuliers du magasin 2, « mar » (gardé)'], ['shop=2&type=b2b&q=&frais=1', 'B2B du magasin 2, relus (froid)'],
        ['shop=2&type=b2b&q=', 'B2B du magasin 2 (gardé)'], ['shop=4&type=particulier&q=an&frais=1', 'particuliers du magasin 4, « an » (froid)'],
        ['shop=4&type=b2b&q=', 'B2B du magasin 4']]);
    printf("\n  mémoire PHP au plus haut : %d Mo\n\n=== FIN SONDE CLIENTS DU BON ===\n", intdiv(memory_get_peak_usage(true), 1048576));
    exit(0);
}
scTitre('la mémoire de PHP-FPM (fichiers de configuration, en lecture)');
foreach (array_merge(glob('/etc/php/*/fpm/php.ini') ?: [], glob('/etc/php/*/fpm/pool.d/*.conf') ?: []) as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (preg_match('/^\s*[^;#].*memory_limit/', $l)) { printf("  %-40s %s\n", $f, trim($l)); }
    }
}
echo '  cockpit : ', trim((string) shell_exec("grep -rn 'memory_limit' " . escapeshellarg($dir) . "/.htaccess " . escapeshellarg($dir) . "/.user.ini 2>/dev/null | head -3")) ?: 'rien dans .htaccess ni .user.ini', "\n";
if (!function_exists('pbClientCourt')) { require "$dir/src/promos_bons.php"; }
try {
    $g = Db::row("SELECT LENGTH(value) n FROM ceo_app_setting WHERE `key` = 'pbClients:2:particulier'");
    echo '  garde pbClients:2:particulier dans ceo_app_setting : ', $g === null ? 'absente' : intdiv((int) $g['n'], 1024) . ' ko', "\n";
} catch (Throwable $e) { echo '  garde illisible : ', mb_substr($e->getMessage(), 0, 120), "\n"; }

scTitre('magasin 2, particuliers : une lecture, des pages');
$m0 = memory_get_usage(); $t = microtime(true);
$r = ErpApi::sondeGet('/shops/2/clients?type=b2c');
$s = microtime(true) - $t; $l = scItems($r['corps']);
printf("  d'un seul tenant (?type=b2c)          HTTP %d · %5d lignes · %5.1f s · mémoire +%d Mo (pic %d Mo)\n", $r['code'], count($l), $s,
    intdiv(memory_get_usage() - $m0, 1048576), intdiv(memory_get_peak_usage() - $m0, 1048576));
$t = microtime(true); $court = [];
foreach ($l as $x) { if (is_array($x)) { $court[] = pbClientCourt($x, 2); } }
$sc = microtime(true) - $t; $t = microtime(true); $js = json_encode(['lu' => time(), 'l' => $court], JSON_UNESCAPED_UNICODE); $se = microtime(true) - $t;
printf("  réduction pbClientCourt %5.2f s · JSON de la garde %d ko en %5.2f s\n", $sc, intdiv(strlen($js), 1024), $se);
unset($r, $l, $court, $js);
$t = microtime(true); $n = 0; $p = 0;
for ($off = 0; $off < 20000; $off += 500) {
    $r = ErpApi::sondeGet('/shops/2/clients?type=b2c&limit=500&offset=' . $off); $p++;
    $k = count(scItems($r['corps'])); $n += $k; unset($r);
    if ($k < 500) { break; }
}
printf("  pages de 500, l'une après l'autre      %2d pages · %5d lignes · %5.1f s\n", $p, $n, microtime(true) - $t);
foreach ([2, 4] as $front) {
    $ch = [];
    for ($i = 0; $i < 4; $i++) { $ch[$i] = '/shops/2/clients?type=b2c&limit=500&offset=' . ($i * 500); }
    $t = microtime(true); $o = ErpApi::getParallele($ch, $front);
    printf("  4 pages, %d de front (getParallele)     %5d lignes · %5.1f s\n", $front, array_sum(array_map(static fn ($c) => count(scItems($c)), $o)), microtime(true) - $t);
    unset($o);
}
$t = microtime(true); $r = ErpApi::sondeGet('/shops/2/clients?type=b2c&limit=500&offset=7500');
printf("  une page du fond (offset 7500)         %5d lignes · %5.1f s\n", count(scItems($r['corps'])), microtime(true) - $t);
unset($r);
if ($passage !== 'tout') { printf("\n  mémoire PHP au plus haut : %d Mo\n\n=== FIN SONDE CLIENTS DU BON ===\n", intdiv(memory_get_peak_usage(true), 1048576)); exit(0); }

foreach ([2, 4] as $shop) {
    scTitre("magasin $shop : GET /shops/$shop/clients");
    $tous = scGet("/shops/$shop/clients");
    scLigne("/shops/$shop/clients", $tous, $shop);
    $it = $tous[1];
    if ($it !== []) {
        echo '    clés : ', implode(', ', array_keys((array) $it[0])), "\n";
        foreach (['is_b2b', 'active', 'blocked', 'status', 'merged_into_id', 'source_channel', 'b2b_segment', 'office_id', 'preferred_shop_id'] as $k) {
            // Les numéros (fusionné dans, bureau, magasin préféré) se comptent sans s'afficher : ce magasin, un autre, aucun.
            $num = in_array($k, ['merged_into_id', 'office_id', 'preferred_shop_id'], true);
            $vals = array_map(static fn ($x) => [$k => !is_array($x) || !array_key_exists($k, $x) ? 'absent'
                : ($num && $x[$k] !== null ? ((int) $x[$k] === $shop ? 'ce magasin' : 'autre') : $x[$k])], $it);
            printf("    %-18s %s\n", $k, scRep($vals, $k));
        }
        // id_main_shop : ce magasin, un autre, aucun — séparément pour les B2B et les particuliers.
        foreach (['B2B' => true, 'particuliers' => false] as $lib => $estB2b) {
            $sous = array_filter($it, static fn ($x) => is_array($x) && (bool) ($x['is_b2b'] ?? false) === $estB2b);
            $ici = count(array_filter($sous, static fn ($x) => (int) ($x['id_main_shop'] ?? 0) === $shop));
            $aucun = count(array_filter($sous, static fn ($x) => empty($x['id_main_shop'])));
            $soc = count(array_filter($sous, static fn ($x) => trim((string) ($x['company_name'] ?? '')) !== ''));
            $tva = count(array_filter($sous, static fn ($x) => trim((string) ($x['tax_number'] ?? '')) !== ''));
            $nom = count(array_filter($sous, static fn ($x) => trim((string) ($x['name'] ?? '') . (string) ($x['surname'] ?? '')) !== ''));
            printf("    %-13s %5d · id_main_shop : ce magasin %d, un autre %d, aucun %d · société %d · TVA %d · nom ou prénom %d\n",
                $lib, count($sous), $ici, count($sous) - $ici - $aucun, $aucun, $soc, $tva, $nom);
        }
    }
    foreach (['type=b2b', 'type=B2B', 'type=b2c', 'type=B2C', 'type=pro', 'type=private'] as $q) {
        scLigne("/shops/$shop/clients?$q", scGet("/shops/$shop/clients?$q"), $shop);
    }
    // La pagination : limit honoré ? offset avance-t-il vraiment (identifiants différents) ?
    $p0 = scGet("/shops/$shop/clients?limit=500&offset=0");
    $p1 = scGet("/shops/$shop/clients?limit=500&offset=500");
    scLigne("/shops/$shop/clients?limit=500&offset=0", $p0, $shop);
    scLigne("/shops/$shop/clients?limit=500&offset=500", $p1, $shop);
    $ids0 = array_column(array_filter($p0[1], 'is_array'), 'id');
    $ids1 = array_column(array_filter($p1[1], 'is_array'), 'id');
    $tri = $ids0; sort($tri);
    printf("    pages 0 et 500 : %d identifiant(s) en commun · première page triée par id croissant : %s\n", count(array_intersect($ids0, $ids1)), $tri === $ids0 ? 'oui' : 'non');
    foreach (['limit=5', 'limit=50', 'limit=100', 'limit=1000', 'limit=5000', 'limit=20000', 'type=b2b&limit=500&offset=0', 'type=b2c&limit=500&offset=0', 'type=b2c&limit=500&offset=500'] as $q) {
        scLigne("/shops/$shop/clients?$q", scGet("/shops/$shop/clients?$q"), $shop);
    }
    // La route du panel pour les B2B d'un magasin.
    $pb = scGet("/panel/shops/$shop/b2b/clients");
    scLigne("/panel/shops/$shop/b2b/clients", $pb, $shop);
    if ($pb[1] !== [] && is_array($pb[1][0])) { echo '    clés : ', implode(', ', array_keys($pb[1][0])), "\n"; }
}

scTitre('le réseau : GET /clients?type=b2b');
$r = scGet('/clients?type=b2b');
scLigne('/clients?type=b2b', $r, 2);
if ($r[1] !== []) {
    echo '    id_main_shop : ', scRep($r[1], 'id_main_shop'), "\n";
}
scLigne('/clients?type=b2b&limit=500&offset=0', scGet('/clients?type=b2b&limit=500&offset=0'), 2);

printf("\n  mémoire PHP au plus haut : %d Mo (memory_limit du CLI : %s)\n", intdiv(memory_get_peak_usage(true), 1048576), ini_get('memory_limit'));
echo "\n=== FIN SONDE CLIENTS DU BON ===\n";
