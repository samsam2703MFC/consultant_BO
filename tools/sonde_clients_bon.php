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

scTitre('compte admin ERP du cockpit');
if (!ErpApi::disponible()) { echo "  ⇒ aucun identifiant ni jeton : sonde impossible\n"; exit(1); }
echo '  base=', ErpApi::config()['base'], "\n";

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
