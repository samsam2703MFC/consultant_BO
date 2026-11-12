<?php
declare(strict_types=1);

/*
 * SONDE — la fidélité à Halle : les ventes du magasin portent-elles l'identifiant du client ? (12/11/2026, demande de
 * Sam : « on a mis le système de fidélité en place. À Halle cette semaine, vérifier avec les transactions et les clients
 * si on a eu des liens croisés (vente magasin identifiée avec un id client) ».)
 *
 * Ce qui est lu :
 *   1. le Swagger du panel : les routes et les champs du ticket qui parlent de fidélité ;
 *   2. les listes de tickets du jour (`/shops/{id}/transactions?date=`) de Halle (magasin 4), du lundi 12/10 à
 *      aujourd'hui, jour par jour : tickets, tickets pro, tickets particuliers rattachés à un client (`id_client`),
 *      clients distincts, CA ; et les champs que portent les tickets de la semaine (taux de remplissage) ;
 *   3. les mêmes comptes pour les trois autres magasins, cette semaine et la précédente : le point de comparaison ;
 *   4. la fiche de chaque client rattaché à un ticket de Halle cette semaine (`/shops/4/clients/{id}`) : compte dans
 *      l'app, fidélité active, membre depuis, canal d'origine, magasin principal (un client d'un autre magasin) ;
 *   5. les membres fidélité (fidelity_active, loyalty_scan_code, fidelity_linked_at) parmi les clients de Halle
 *      (`/shops/4/clients`) et de tout le réseau (`/clients`), et combien d'entre eux sont sur un ticket de Halle ;
 *   6. les tables du cockpit : le relevé du cron (`ceo_pwa_jour`) et les visites carte (`ceo_fid_visite`).
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-fidelite-halle.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_fidelite_halle.php
 *
 * LECTURE SEULE : des GET au panel, des SELECT sur les tables du cockpit — le relevé ceo_pwa_* n'est pas regravé.
 * Rien de nominatif : des comptes, des noms de champ, des répartitions de valeurs courtes ; ni nom, ni téléphone,
 * ni e-mail, ni identifiant de client.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require_once "$dir/src/$f";
}

const SF_SHOP = 4;                                  // Halle (src/benchmark.php : 2 Corbais, 3 Gosselies, 4 Halle, 5 Sombreffe)
const SF_AUTRES = [2 => 'Corbais', 3 => 'Gosselies', 5 => 'Sombreffe'];
const SF_DEBUT_HISTO = '2026-10-12';                // quatre semaines pleines avant celle-ci
const SF_FICHES_MAX = 500;                          // fiches clients lues au plus
const SF_PAGES_MAX = 40;                            // pages de 500 clients lues au plus
/** Un champ dont la valeur peut être nominative : jamais affichée, seulement comptée. */
const SF_PRIVE = '/name|nom|mail|phone|tel|address|adresse|street|city|zip|postal|vat|iban|bic|note|comment|birth|naiss|token|hash|code|password|card_number|qr/i';

$auj = date('Y-m-d');
$lundi = date('Y-m-d', strtotime('monday this week'));
$lundiAvant = date('Y-m-d', strtotime($lundi . ' -7 days'));

function sfTitre(string $t): void { echo "\n=== $t ===\n"; }
function sfEur(float $v): string { return number_format($v, 0, ',', ' ') . ' €'; }
function sfPct(int $a, int $b): string { return $b > 0 ? number_format(100 * $a / $b, 1, ',', '') . ' %' : '—'; }

/** Les jours de $du à $au inclus. */
function sfJours(string $du, string $au): array
{
    $out = [];
    for ($j = $du; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $out[] = $j; }
    return $out;
}

/** Le montant d'un ticket, quel que soit le nom du champ. */
function sfMontant(array $t): float
{
    foreach (['total_gross_amount_after_discount', 'total_value_after_discount', 'total_gross_amount', 'total'] as $k) {
        if (isset($t[$k]) && is_numeric($t[$k])) { return (float) $t[$k]; }
    }
    return 0.0;
}

/** Un identifiant de client utilisable, ou 0. */
function sfClient(array $t): int
{
    $k = $t['id_client'] ?? null;
    return is_scalar($k) && ctype_digit((string) $k) ? (int) $k : 0;
}

/** Un ticket lié à une commande (webshop, click & collect, précommande) plutôt qu'une vente au comptoir. */
function sfCommande(array $t): bool
{
    if (strtolower((string) ($t['source'] ?? '')) === 'webshop') { return true; }
    foreach (['order_ref', 'id_client_order', 'client_order_id', 'id_order', 'order_id'] as $k) {
        if (isset($t[$k]) && is_scalar($t[$k]) && (int) $t[$k] > 0) { return true; }
    }
    return trim((string) ($t['po_hash'] ?? '')) !== '';
}

/** Les comptes d'une liste de tickets du jour. */
function sfComptes(array $liste): array
{
    $c = ['tickets' => 0, 'pro' => 0, 'part' => 0, 'ident' => 0, 'identComptoir' => 0, 'identCommande' => 0,
        'ca' => 0.0, 'caPart' => 0.0, 'caIdent' => 0.0, 'clients' => []];
    foreach ($liste as $t) {
        if (!is_array($t)) { continue; }
        $c['tickets']++;
        $m = sfMontant($t);
        $c['ca'] += $m;
        if (!empty($t['is_client_b2b'])) { $c['pro']++; continue; }
        $c['part']++;
        $c['caPart'] += $m;
        $cli = sfClient($t);
        if ($cli <= 0) { continue; }
        $c['ident']++;
        $c['caIdent'] += $m;
        sfCommande($t) ? $c['identCommande']++ : $c['identComptoir']++;
        $c['clients'][$cli] = ($c['clients'][$cli] ?? 0) + 1;
    }
    return $c;
}

/** Lit les listes de tickets [clé => chemin] et rend [clé => liste|null] avec le code HTTP en cas d'échec. */
function sfLire(array $chemins): array
{
    $res = PanelApi::getParalleleDetail($chemins, 4, 40);
    $out = [];
    foreach ($chemins as $k => $ch) {
        $r = $res[$k] ?? null;
        $ok = is_array($r) && (int) ($r['code'] ?? 0) >= 200 && (int) ($r['code'] ?? 0) < 300 && is_array($r['data'] ?? null);
        $out[$k] = $ok ? analyseListe($r['data']) : ['__erreur' => 'HTTP ' . (int) ($r['code'] ?? 0) . ' ' . (string) ($r['erreur'] ?? '')];
    }
    return $out;
}

/** Une valeur montrable : courte, et jamais pour un champ qui peut être nominatif. */
function sfValeur(string $cle, mixed $v): ?string
{
    if (preg_match(SF_PRIVE, $cle)) { return null; }
    if ($v === null) { return 'null'; }
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    if (is_array($v)) { return 'objet'; }
    $s = (string) $v;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) { return 'date'; }
    if (preg_match('/(^|_)id(_|$)|^id[a-z]/i', $cle) && is_numeric($s)) { return (int) $s > 0 ? '>0' : $s; }
    if (is_numeric($s)) { return (float) $s > 0 ? '>0' : ((float) $s < 0 ? '<0' : '0'); }
    return mb_strlen($s) > 24 ? 'texte(' . mb_strlen($s) . ')' : $s;
}

/** Les champs d'une série d'objets : remplissage et, pour les champs sûrs, les valeurs les plus fréquentes. */
function sfChamps(array $objets, string $filtre = ''): void
{
    $n = count($objets);
    $plein = []; $vals = [];
    foreach ($objets as $o) {
        if (!is_array($o)) { continue; }
        foreach ($o as $k => $v) {
            $k = (string) $k;
            if ($filtre !== '' && !preg_match($filtre, $k)) { continue; }
            $plein[$k] ??= 0;
            if ($v !== null && $v !== '' && $v !== [] && $v !== 0 && $v !== '0' && $v !== false) { $plein[$k]++; }
            $s = sfValeur($k, $v);
            if ($s !== null) { $vals[$k][$s] = ($vals[$k][$s] ?? 0) + 1; }
        }
    }
    ksort($plein);
    foreach ($plein as $k => $p) {
        $rep = '';
        if (isset($vals[$k])) {
            arsort($vals[$k]);
            $rep = count($vals[$k]) <= 12 ? json_encode(array_slice($vals[$k], 0, 8, true), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)
                : count($vals[$k]) . ' valeurs distinctes';
        } else {
            $rep = '(valeur non affichée)';
        }
        printf("  %-34s rempli %5d / %-5d %s\n", $k, $p, $n, $rep);
    }
}

/* ─────────────────────────────────────────────────────────────────────────────────────────────────────────────── */
sfTitre('compte du panel');
if (!PanelApi::configured()) { echo "  ⇒ aucun identifiant TF Buddy : sonde impossible\n"; exit(1); }
echo '  base=', PanelApi::config()['base'], " · aujourd'hui $auj · semaine du lundi $lundi\n";

/* 1. Le Swagger ─────────────────────────────────────────────────────────────────────────────────────────────── */
sfTitre('1. Swagger : les routes et schémas qui parlent de fidélité');
$doc = PanelApi::sondeGet('/../swagger/openapi.json', 30);
$paths = is_array($doc['corps']['paths'] ?? null) ? $doc['corps']['paths'] : [];
echo '  openapi.json : HTTP ', $doc['code'], ' · ', count($paths), " routes\n";
foreach ($paths as $ch => $ops) {
    if (!is_array($ops) || !preg_match('#loyal|fidel|stamp|reward|points|pwa|scan#i', (string) $ch)) { continue; }
    $verbes = array_map('strtoupper', array_keys(array_filter($ops, 'is_array')));
    printf("  %-60s %s\n", $ch, implode(' ', $verbes));
}
foreach ($paths as $ch => $ops) {
    if (!is_array($ops) || !preg_match('#^(/api/v1)?(/shops/\{[^}]+\})?/clients$#', (string) $ch) || !is_array($ops['get'] ?? null)) { continue; }
    $prm = array_map(static fn ($x) => is_array($x) ? (string) ($x['name'] ?? '?') : '?', (array) ($ops['get']['parameters'] ?? []));
    printf("  GET %-56s paramètres : %s\n", $ch, $prm ? implode(', ', $prm) : '—');
}
$sch = is_array($doc['corps']['components']['schemas'] ?? null) ? $doc['corps']['components']['schemas'] : [];
foreach ($sch as $nom => $s) {
    if (!preg_match('/transaction|loyal|fidel|client|customer/i', (string) $nom) || !is_array($s['properties'] ?? null)) { continue; }
    $props = array_keys($s['properties']);
    $fid = array_values(array_filter($props, static fn ($p) => preg_match('/loyal|fidel|point|stamp|card|pwa|member|id_client|id_customer|voucher|scan/i', (string) $p)));
    printf("  schéma %-38s %3d champs · liés client/fidélité : %s\n", $nom, count($props), $fid ? implode(', ', $fid) : '—');
}

/* 2. Halle, jour par jour ──────────────────────────────────────────────────────────────────────────────────── */
sfTitre('2. Halle (magasin ' . SF_SHOP . ') — les tickets jour par jour, du ' . SF_DEBUT_HISTO . ' à aujourd\'hui');
$jours = sfJours(SF_DEBUT_HISTO, $auj);
$chemins = [];
foreach ($jours as $j) { $chemins[$j] = '/shops/' . SF_SHOP . '/transactions?date=' . $j; }
$t0 = microtime(true);
$listes = sfLire($chemins);
printf("  %d listes lues en %.1f s\n\n", count($listes), microtime(true) - $t0);
printf("  %-10s %-3s %7s %5s %7s %7s %6s %6s %6s %8s %9s %9s\n", 'jour', '', 'tickets', 'pro', 'partic.', 'ident.', 'taux', 'compt.', 'cmde', 'clients', 'CA', 'CA ident.');
$JS = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
$semaines = [];
$ticketsSemaine = [];
$clientsSemaine = [];
foreach ($jours as $j) {
    $l = $listes[$j];
    if (isset($l['__erreur'])) { printf("  %-10s %-3s %s\n", $j, $JS[(int) date('N', strtotime($j)) - 1], $l['__erreur']); continue; }
    $c = sfComptes($l);
    $sem = date('Y-m-d', strtotime('monday this week', strtotime($j)));
    $s = &$semaines[$sem];
    $s ??= ['jours' => 0, 'tickets' => 0, 'pro' => 0, 'part' => 0, 'ident' => 0, 'identComptoir' => 0, 'identCommande' => 0, 'ca' => 0.0, 'caPart' => 0.0, 'caIdent' => 0.0, 'clients' => []];
    $s['jours']++;
    foreach (['tickets', 'pro', 'part', 'ident', 'identComptoir', 'identCommande', 'ca', 'caPart', 'caIdent'] as $k) { $s[$k] += $c[$k]; }
    foreach ($c['clients'] as $cli => $n) { $s['clients'][$cli] = ($s['clients'][$cli] ?? 0) + $n; }
    unset($s);
    if ($j >= $lundi) {
        foreach ($l as $t) { if (is_array($t)) { $ticketsSemaine[] = $t; } }
        foreach ($c['clients'] as $cli => $n) { $clientsSemaine[$cli] = ($clientsSemaine[$cli] ?? 0) + $n; }
    }
    printf("  %-10s %-3s %7d %5d %7d %7d %6s %6d %6d %8d %9s %9s\n", $j, $JS[(int) date('N', strtotime($j)) - 1], $c['tickets'], $c['pro'], $c['part'], $c['ident'],
        sfPct($c['ident'], $c['part']), $c['identComptoir'], $c['identCommande'], count($c['clients']), sfEur($c['ca']), sfEur($c['caIdent']));
}
echo "\n  Par semaine (taux = tickets particuliers rattachés à un client ÷ tickets particuliers) :\n";
foreach ($semaines as $sem => $s) {
    $rep = count(array_filter($s['clients'], static fn ($n) => $n >= 2));
    printf("  semaine du %s (%d j) : %5d tickets · %4d pro · %5d particuliers · %4d identifiés (%s ; comptoir %d, commande %d) · %4d clients distincts, dont %d revenus 2 fois ou plus · CA identifié %s / %s\n",
        $sem, $s['jours'], $s['tickets'], $s['pro'], $s['part'], $s['ident'], sfPct($s['ident'], $s['part']), $s['identComptoir'], $s['identCommande'],
        count($s['clients']), $rep, sfEur($s['caIdent']), sfEur($s['caPart']));
}

sfTitre('2b. Halle cette semaine — les champs des tickets (' . count($ticketsSemaine) . ' tickets)');
sfChamps($ticketsSemaine);
$ident = array_values(array_filter($ticketsSemaine, static fn ($t) => empty($t['is_client_b2b']) && sfClient($t) > 0));
sfTitre('2c. Halle cette semaine — les champs des seuls tickets particuliers rattachés à un client (' . count($ident) . ')');
sfChamps($ident);
if ($ident !== [] || $ticketsSemaine !== []) {
    // Le détail d'un ticket (lignes, paiements) : les champs seulement, pour voir si la fidélité s'y inscrit (points, tampons).
    $ex = $ident[0] ?? $ticketsSemaine[0];
    $d = PanelApi::sondeGet('/transactions/' . (int) ($ex['id'] ?? 0) . '?include=products', 20);
    echo "\n  détail d'un ticket", $ident !== [] ? ' identifié' : '', ' (/transactions/{id}?include=products) : HTTP ', $d['code'], "\n";
    $f = is_array($d['corps']) ? (isset($d['corps']['data']) && is_array($d['corps']['data']) && !array_is_list($d['corps']['data']) ? $d['corps']['data'] : $d['corps']) : [];
    if ($f !== []) {
        echo '  champs : ', implode(', ', array_keys($f)), "\n";
        $fid = array_filter(array_keys($f), static fn ($k) => preg_match('/loyal|fidel|point|stamp|card|pwa|member|voucher|reward|scan/i', (string) $k));
        echo '  liés fidélité : ', $fid ? implode(', ', $fid) : '—', "\n";
        foreach ($fid as $k) { $s = sfValeur((string) $k, $f[$k]); echo "    $k = ", $s ?? '(non affiché)', "\n"; }
    }
}

/* 3. Les autres magasins ───────────────────────────────────────────────────────────────────────────────────── */
sfTitre('3. Comparaison — les autres magasins, semaine passée et cette semaine');
$ch3 = [];
foreach (SF_AUTRES as $sid => $nom) {
    foreach (sfJours($lundiAvant, $auj) as $j) { $ch3[$sid . '|' . $j] = '/shops/' . $sid . '/transactions?date=' . $j; }
}
$l3 = sfLire($ch3);
foreach (SF_AUTRES as $sid => $nom) {
    foreach ([[$lundiAvant, date('Y-m-d', strtotime($lundi . ' -1 day')), 'semaine passée'], [$lundi, $auj, 'cette semaine']] as [$du, $au, $lib]) {
        $tot = ['tickets' => 0, 'part' => 0, 'ident' => 0, 'identComptoir' => 0, 'clients' => [], 'err' => 0];
        foreach (sfJours($du, $au) as $j) {
            $l = $l3[$sid . '|' . $j] ?? [];
            if (isset($l['__erreur'])) { $tot['err']++; continue; }
            $c = sfComptes($l);
            foreach (['tickets', 'part', 'ident', 'identComptoir'] as $k) { $tot[$k] += $c[$k]; }
            $tot['clients'] += $c['clients'];
        }
        printf("  %-10s %-14s %5d tickets · %5d particuliers · %4d identifiés (%s, dont comptoir %d) · %4d clients%s\n", $nom, $lib, $tot['tickets'], $tot['part'],
            $tot['ident'], sfPct($tot['ident'], $tot['part']), $tot['identComptoir'], count($tot['clients']), $tot['err'] ? ' · ' . $tot['err'] . ' jour(s) illisible(s)' : '');
    }
}

/* 4. Les fiches des clients rattachés ──────────────────────────────────────────────────────────────────────── */
sfTitre('4. Halle cette semaine — les fiches des ' . count($clientsSemaine) . ' clients rattachés à un ticket');
$ids = array_slice(array_keys($clientsSemaine), 0, SF_FICHES_MAX);
$ch4 = [];
foreach ($ids as $cid) { $ch4[$cid] = '/shops/' . SF_SHOP . '/clients/' . (int) $cid; }
$res4 = $ch4 ? PanelApi::getParalleleDetail($ch4, 4, 25) : [];
$fiches = []; $codes = [];
foreach ($ids as $cid) {
    $r = $res4[$cid] ?? null;
    $codes[(int) ($r['code'] ?? 0)] = ($codes[(int) ($r['code'] ?? 0)] ?? 0) + 1;
    $d = is_array($r['data'] ?? null) ? $r['data'] : null;
    if ($d === null) { continue; }
    $f = isset($d['data']) && is_array($d['data']) && !array_is_list($d['data']) ? $d['data'] : $d;
    // Les sous-objets (fidélité, compte) remontent à plat, préfixés.
    foreach ($f as $k => $v) {
        if (is_array($v) && $v !== [] && !array_is_list($v)) { foreach ($v as $k2 => $v2) { if (!is_array($v2)) { $f[$k . '.' . $k2] = $v2; } } unset($f[$k]); }
    }
    foreach ($f as $k => $v) { if (str_starts_with((string) $k, 'client.')) { $f[substr((string) $k, 7)] ??= $v; } }
    $fiches[$cid] = $f;
}
echo '  codes HTTP : ', json_encode($codes), ' · fiches lues ', count($fiches), "\n";
$cnt = static function (array $fiches, callable $fn): int { return count(array_filter($fiches, $fn)); };
$vrai = static fn ($v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';
printf("  compte dans l'app (pwa_user)          : %d oui · %d non · %d champ absent\n",
    $cnt($fiches, fn ($f) => $vrai($f['pwa_user'] ?? null)), $cnt($fiches, fn ($f) => array_key_exists('pwa_user', $f) && !$vrai($f['pwa_user'])), $cnt($fiches, fn ($f) => !array_key_exists('pwa_user', $f)));
printf("  fidélité active (fidelity_active)     : %d oui · %d non · %d champ absent\n",
    $cnt($fiches, fn ($f) => $vrai($f['fidelity_active'] ?? null)), $cnt($fiches, fn ($f) => array_key_exists('fidelity_active', $f) && !$vrai($f['fidelity_active'])), $cnt($fiches, fn ($f) => !array_key_exists('fidelity_active', $f)));
$membre = static fn (array $f): string => substr((string) ($f['member_since'] ?? $f['fidelity_linked_at'] ?? ''), 0, 10);
printf("  membres fidélité (member_since ou fidelity_linked_at) depuis le %s : %d · avant : %d · jamais : %d\n", $lundi,
    $cnt($fiches, fn ($f) => $membre($f) >= $lundi), $cnt($fiches, fn ($f) => $membre($f) !== '' && $membre($f) < $lundi), $cnt($fiches, fn ($f) => $membre($f) === ''));
printf("  magasin principal (id_main_shop)      : %d Halle · %d un autre magasin · %d sans\n",
    $cnt($fiches, fn ($f) => (int) ($f['id_main_shop'] ?? 0) === SF_SHOP), $cnt($fiches, fn ($f) => (int) ($f['id_main_shop'] ?? 0) > 0 && (int) $f['id_main_shop'] !== SF_SHOP),
    $cnt($fiches, fn ($f) => (int) ($f['id_main_shop'] ?? 0) <= 0));
$rep = []; foreach ($clientsSemaine as $n) { $k = $n >= 4 ? '4+' : (string) $n; $rep[$k] = ($rep[$k] ?? 0) + 1; }
ksort($rep);
echo '  tickets par client cette semaine      : ', json_encode($rep, JSON_FORCE_OBJECT), "\n";
echo "\n  Les champs des fiches :\n";
sfChamps(array_values($fiches));

/* 5. Les membres fidélité : à Halle, puis sur tout le réseau ───────────────────────────────────────────────── */
/** Toutes les pages d'une liste de clients (500 par page, quatre de front), dédoublonnées par id. */
function sfTousClients(string $route, int $pagesMax): array
{
    $vus = []; $pages = 0; $fin = false; $t0 = microtime(true); $premier = null;
    for ($off = 0; $pages < $pagesMax && !$fin; $off += 2000) {
        $ch = [];
        for ($i = 0; $i < 4; $i++) { $ch[$off + $i * 500] = $route . (str_contains($route, '?') ? '&' : '?') . 'limit=500&offset=' . ($off + $i * 500); }
        $r = PanelApi::getParalleleDetail($ch, 4, 60);
        foreach ($ch as $o => $c) {
            $pages++;
            $l = is_array($r[$o]['data'] ?? null) ? PanelApi::liste($r[$o]['data']) : [];
            if ($o === 0) { echo "  $route : HTTP ", (int) ($r[$o]['code'] ?? 0), ' · ', count($l), " sur la première page\n"; }
            if ($l === []) { $fin = true; continue; }
            $neufs = 0;
            foreach ($l as $x) { $id = (int) (is_array($x) ? ($x['id'] ?? 0) : 0); if ($id > 0 && !isset($vus[$id])) { $vus[$id] = $x; $neufs++; } }
            if ($neufs === 0 || count($l) < 500) { $fin = true; }
        }
    }
    printf("  %d clients lus (%d pages, %.1f s)%s\n", count($vus), $pages, microtime(true) - $t0, $fin ? '' : ' — plafond atteint, liste INCOMPLÈTE');
    return $vus;
}

/** Les comptes fidélité d'une liste de clients particuliers. */
function sfMembres(array $clients, string $lundi, array $clientsTickets): void
{
    $vrai = static fn ($v): bool => $v === true || $v === 1 || $v === '1' || $v === 'true';
    $plein = static fn ($v): bool => $v !== null && $v !== '' && $v !== '0' && $v !== 0;
    $part = array_filter($clients, static fn ($c) => is_array($c) && empty($c['is_b2b']));
    $n = ['fid' => 0, 'scan' => 0, 'lie' => 0, 'membre' => 0, 'pwa' => 0, 'connecte' => 0, 'lieSemaine' => 0, 'creeSemaine' => 0, 'membreTicket' => 0];
    $canal = []; $lieParJour = []; $membresParMag = [];
    foreach ($part as $id => $c) {
        $estFid = $vrai($c['fidelity_active'] ?? null);
        $n['fid'] += $estFid ? 1 : 0;
        $n['scan'] += $plein($c['loyalty_scan_code'] ?? null) ? 1 : 0;
        $n['lie'] += $plein($c['fidelity_linked_at'] ?? null) ? 1 : 0;
        $n['membre'] += $plein($c['member_since'] ?? null) ? 1 : 0;
        $n['pwa'] += $vrai($c['pwa_user'] ?? null) ? 1 : 0;
        $n['connecte'] += $plein($c['last_login_at'] ?? null) ? 1 : 0;
        $lie = substr((string) ($c['fidelity_linked_at'] ?? $c['member_since'] ?? ''), 0, 10);
        if ($lie !== '' && $lie >= date('Y-m-d', strtotime($lundi . ' -21 days'))) { $lieParJour[$lie] = ($lieParJour[$lie] ?? 0) + 1; }
        if ($lie !== '' && $lie >= $lundi) { $n['lieSemaine']++; }
        if (substr((string) ($c['create_timestamp'] ?? ''), 0, 10) >= $lundi) { $n['creeSemaine']++; }
        if ($estFid || $plein($c['loyalty_scan_code'] ?? null)) {
            $m = (string) ((int) ($c['preferred_shop_id'] ?? 0) ?: (int) ($c['id_main_shop'] ?? 0));
            $membresParMag[$m] = ($membresParMag[$m] ?? 0) + 1;
            if (isset($clientsTickets[(int) $id])) { $n['membreTicket']++; }
        }
        $k = (string) ($c['source_channel'] ?? 'null');
        $canal[$k] = ($canal[$k] ?? 0) + 1;
    }
    arsort($canal); ksort($lieParJour); ksort($membresParMag);
    printf("  particuliers : %d · fidelity_active=1 : %d · loyalty_scan_code rempli : %d · fidelity_linked_at rempli : %d · member_since rempli : %d\n",
        count($part), $n['fid'], $n['scan'], $n['lie'], $n['membre']);
    printf("  pwa_user=1 : %d · déjà connectés en ligne : %d · fiches créées depuis lundi : %d · cartes liées depuis lundi : %d\n",
        $n['pwa'], $n['connecte'], $n['creeSemaine'], $n['lieSemaine']);
    echo '  canal d\'origine : ', json_encode(array_slice($canal, 0, 8, true), JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), "\n";
    echo '  membres (fidélité active ou code de scan) par magasin préféré / principal : ', json_encode($membresParMag, JSON_FORCE_OBJECT), "\n";
    echo '  cartes liées par jour, trois dernières semaines : ', $lieParJour ? json_encode($lieParJour) : '—', "\n";
    printf("  membres présents sur un ticket de Halle cette semaine : %d\n", $n['membreTicket']);
}

sfTitre('5. Les clients de Halle (/shops/' . SF_SHOP . '/clients) : les membres fidélité');
$halle = sfTousClients('/shops/' . SF_SHOP . '/clients', SF_PAGES_MAX);
sfMembres($halle, $lundi, $clientsSemaine);
echo '  clients des tickets de la semaine présents dans cette liste : ', count(array_intersect_key($clientsSemaine, $halle)), ' / ', count($clientsSemaine), "\n";

sfTitre('5b. Tout le réseau (/clients) : les membres fidélité, où qu\'ils soient rattachés');
$reseau = sfTousClients('/clients', 120);
sfMembres($reseau, $lundi, $clientsSemaine);
echo '  clients des tickets de Halle de la semaine présents dans cette liste : ', count(array_intersect_key($clientsSemaine, $reseau)), ' / ', count($clientsSemaine), "\n";
/* 6. Les tables du cockpit ─────────────────────────────────────────────────────────────────────────────────── */
sfTitre('6. Les tables du cockpit');
try {
    foreach (Db::rows('SELECT jour, tickets, identifies, lu_le, essais FROM ceo_pwa_jour WHERE shop_id = ? AND jour >= ? ORDER BY jour', [SF_SHOP, $lundiAvant]) as $r) {
        printf("  ceo_pwa_jour  %s : %4d tickets · %3d identifiés · lu le %s · essais %d\n", $r['jour'], $r['tickets'], $r['identifies'], $r['lu_le'] ?? '—', $r['essais']);
    }
} catch (Throwable $e) { echo '  ceo_pwa_jour : ', $e->getMessage(), "\n"; }
try {
    $r = Db::row('SELECT COUNT(*) n, MIN(visited_at) du, MAX(visited_at) au, SUM(shop_id = ?) halle, SUM(shop_id = ? AND visited_at >= ?) halleSemaine FROM ceo_fid_visite', [(string) SF_SHOP, (string) SF_SHOP, $lundi]);
    printf("  ceo_fid_visite : %d visites carte (du %s au %s) · Halle %d · Halle cette semaine %d\n", $r['n'] ?? 0, $r['du'] ?? '—', $r['au'] ?? '—', $r['halle'] ?? 0, $r['halleSemaine'] ?? 0);
} catch (Throwable $e) { echo '  ceo_fid_visite : ', $e->getMessage(), "\n"; }
try {
    $r = Db::row('SELECT COUNT(*) n, SUM(actif = 1) actives, MAX(updated_at) maj FROM ceo_fid_regle');
    printf("  ceo_fid_regle : %d règles, %d actives, dernière modification %s\n", $r['n'] ?? 0, $r['actives'] ?? 0, $r['maj'] ?? '—');
} catch (Throwable $e) { echo '  ceo_fid_regle : ', $e->getMessage(), "\n"; }

echo "\nfin de la sonde\n";
