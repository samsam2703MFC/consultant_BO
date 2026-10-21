#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des promotions du magasin (/exploitation/promos-magasin) — sans base, sans réseau, sans token.
 *
 *   php bin/tests_promos.php
 *
 * Les prix et coûts de recette du magasin sont une doublure de /analyse/produits/coefficients ;
 * les réglages vivent en mémoire. On vérifie le coefficient du lot (le cas le plus serré, la pièce
 * offerte la moins chère), les règles du magasin (une fin, un an, deux à la fois) et le parcours
 * d'une promotion, toute au panel (18/10/2026, plus de copie au cockpit) : brouillon inactif,
 * enregistrement (créée inactive, relue, activée), modification, suppression, la liste relue.
 */

// --- doublures : pas de base, pas de réseau ------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void
    {
        if (str_starts_with($sql, 'INSERT INTO ceo_app_setting')) { $GLOBALS['reglages'][$a[0]] = json_decode($a[1], true); }
    }
}
function setting(string $k, mixed $d = null): mixed { return $GLOBALS['reglages'][$k] ?? $d; }
function body(): array { return $GLOBALS['corps']; }
// Les produits du magasin 4 : prix HT, coût de recette, TVA.
function ep_analyse_produits_coefficients(): array
{
    $sid = $_GET['shop'] ?? '';
    // Le magasin 5 vend le pain au chocolat moins cher : 1,40 € HT.
    if ($sid === '5') { return ['produits' => [['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.40, 'cout' => 0.60, 'tva' => 6]]]; }
    if ($sid !== '4') { return ['produits' => []]; }
    return ['produits' => [
        ['id' => 1, 'nom' => 'Croissant', 'catId' => 10, 'prixHt' => 2.00, 'cout' => 0.50, 'tva' => 6],
        ['id' => 2, 'nom' => 'Pain au chocolat', 'catId' => 10, 'prixHt' => 1.50, 'cout' => 0.60, 'tva' => 6],
        ['id' => 3, 'nom' => 'Tarte sans fiche', 'catId' => 20, 'prixHt' => 3.00, 'cout' => null, 'tva' => 6],
        ['id' => 4, 'nom' => 'Éclair', 'catId' => 20, 'prixHt' => 4.00, 'cout' => 1.00, 'tva' => 6],
        // Pain : pas de coût calculé pour le magasin (le panel rend 0), le réseau en a un.
        ['id' => 6, 'nom' => 'Pain', 'catId' => 30, 'prixHt' => 2.50, 'cout' => 0, 'tva' => 6],
        ['id' => 7, 'nom' => 'Baguette', 'catId' => 30, 'prixHt' => 1.50, 'cout' => null, 'tva' => 6],
        ['id' => 8, 'nom' => 'Sans prix', 'catId' => 30, 'prixHt' => null, 'cout' => 0.30, 'tva' => 6],
    ]];
}

// Le panel, en doublure : il garde les promotions écrites et les rend relues comme sa fiche (17/10/2026).
final class ErpApi
{
    public static bool $actif = false;
    public static ?string $lastError = null;
    public static array $promos = [];
    public static array $appels = [];
    public static ?string $refus = null;
    public static bool $perdCreneaux = false;
    public static bool $sansPut = false;
    public static bool $perdPortion = false;
    public static int $suivant = 30;
    public static ?string $formatHeure = null;   // 'H:i' : le panel refuse l'heure des créneaux avec secondes
    public static bool $sansDelete = false;
    public static ?int $parPage = null;          // la liste en pages de N (Laravel : data + meta.last_page)
    public static ?int $listeMax = null;         // la liste ne rend pas les numéros au-delà (une promotion toute récente)
    public static function disponible(): bool { return self::$actif; }
    public static function ecrire(string $m, string $path, ?array $b = null): array
    {
        self::$appels[] = $m . ' ' . $path;
        $ok = static fn ($c = []) => ['code' => 200, 'corps' => $c, 'erreur' => null];
        if (self::$formatHeure !== null && in_array($m, ['POST', 'PUT'], true)) {
            foreach ((array) ($b['schedule'] ?? []) as $i => $c) {
                if (preg_match(self::$formatHeure === 'H:i' ? '/^\d\d:\d\d$/' : '/^\d\d:\d\d:\d\d$/', (string) ($c['time_from'] ?? '')) !== 1) {
                    return ['code' => 422, 'corps' => null, 'erreur' => "HTTP 422 : INVALID_PROMOTION_DATA — schedule.$i.time_from : The schedule.$i.time_from field must match the format " . self::$formatHeure . '.'];
                }
            }
        }
        if ($m === 'POST' && $path === '/admin/promotions/buy-x-get-y') {
            if (self::$refus !== null) { return ['code' => 422, 'corps' => null, 'erreur' => self::$refus]; }
            $id = self::$suivant++;
            self::$promos[$id] = self::fiche($id, $b);
            return $ok(['data' => ['id' => $id]]);
        }
        if (preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) && isset(self::$promos[(int) $x[1]])) {
            $id = (int) $x[1];
            if ($m === 'PUT') {
                if (self::$sansPut) { return ['code' => 405, 'corps' => null, 'erreur' => 'HTTP 405']; }
                self::$promos[$id] = self::fiche($id, $b);
                return $ok();
            }
            if ($m === 'PATCH') { self::$promos[$id]['status'] = $b['status']; return $ok(); }
            if ($m === 'DELETE') {
                if (self::$sansDelete) { return ['code' => 405, 'corps' => null, 'erreur' => 'HTTP 405']; }
                unset(self::$promos[$id]);
                return $ok();
            }
        }
        return ['code' => 404, 'corps' => null, 'erreur' => 'HTTP 404'];
    }
    public static function get(string $path): mixed
    {
        self::$appels[] = 'GET ' . $path;
        return preg_match('#^/admin/promotions/buy-x-get-y/(\d+)$#', $path, $x) ? (self::$promos[(int) $x[1]] ?? null) : null;
    }
    /** Les fiches lues ensemble (pvRegles, 21/10/2026) : ici, une à une par get(). */
    public static function getParallele(array $paths, int $front = 4): array
    {
        $o = [];
        foreach ($paths as $k => $p) { $o[$k] = self::get($p); }
        return $o;
    }
    public static function sondeGet(string $path): array
    {
        if (!str_starts_with($path, '/admin/promotions/buy-x-get-y?')) { return ['code' => 404, 'corps' => null, 'erreur' => 'HTTP 404']; }
        parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
        $l = array_values(array_filter(self::$promos, static fn ($f) => self::$listeMax === null || $f['id'] <= self::$listeMax));
        usort($l, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $l = array_map(static fn ($f) => ['id' => $f['id'], 'name' => $f['name'] ?? '', 'status' => $f['status'] ?? '', 'promotion_type' => 'BUY_X_GET_Y'], $l);
        if (self::$parPage === null) { return ['code' => 200, 'corps' => ['data' => $l], 'erreur' => null]; }
        $page = max(1, (int) ($q['page'] ?? 1));
        return ['code' => 200, 'corps' => ['data' => array_slice($l, ($page - 1) * self::$parPage, self::$parPage),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($l) / self::$parPage))]], 'erreur' => null];
    }
    private static function fiche(int $id, array $b): array
    {
        $f = ['id' => $id] + $b;
        if (self::$perdCreneaux) { $f['schedule'] = []; }
        if (self::$perdPortion) { foreach (['trigger', 'reward'] as $k) { foreach (['category_targets', 'product_targets'] as $c) { if (isset($f[$k][$c])) { $f[$k][$c] = array_map(static fn ($t) => is_array($t) ? array_diff_key($t, ['portion_scope' => 1]) : $t, $f[$k][$c]); } } } }
        return $f;
    }
}

// Le coût du réseau (la moyenne des magasins du compte, sinon la recette locale) : le pain seulement.
function catalogueCouts(): array { return [6 => ['mat' => 0.50, 'source' => 'panel (products/available)'], 4 => ['mat' => 9.99]]; }

require __DIR__ . '/../src/promos_magasin.php';
require __DIR__ . '/../src/promos_ventes.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$P = pmProduits(4);
$pp = array_column($P, null, 'id');
verifie('coût du magasin gardé, même quand le réseau en a un autre', $pp[4]['cout'] === 1.0 && $pp[4]['source'] === 'magasin');
verifie('coût à 0 au magasin : celui du réseau, marqué', $pp[6]['cout'] === 0.5 && $pp[6]['source'] === 'reseau', json_encode($pp[6]));
verifie('ni magasin ni réseau : pas de coût', $pp[7]['cout'] === null && $pp[7]['source'] === null && $pp[3]['cout'] === null);
$_GET = ['shop' => '4']; $r = ep_promos_magasin_produits(); $_GET = [];
verifie('la liste de l’assistant compte les sources', $r['nMagasin'] === 4 && $r['nReseau'] === 1 && $r['nSansCout'] === 2, json_encode(array_diff_key($r, ['produits' => 1])));
$cote = static fn (int $q, array $cats = [], array $prods = [], array $ex = []) => ['q' => $q, 'cats' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $cats),
    'prods' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $prods), 'exclus' => array_map(static fn ($i) => ['id' => $i, 'nom' => ''], $ex)];

echo "Coefficient du lot\n";
$y = $cote(1, [10]) + ['type' => 'FREE', 'valeur' => null];
$c = pmCoef($P, $cote(2, [10]), $y);
// Le cas le plus serré : 3 pains au chocolat, le 3e offert — 3,00 € HT payés pour 1,80 € de matière.
verifie('2 achetés + 1 offert dans la catégorie : le cas le plus serré', $c['lot'] === 1.67 && $c['x'] === 'Pain au chocolat' && $c['y'] === 'Pain au chocolat', json_encode($c));
verifie('sans la promotion, le même lot', $c['sans'] === 2.5);
verifie('la paire impossible en caisse écartée (un croissant offert contre un pain au chocolat acheté)', $c['paires'] === 3, (string) $c['paires']);
$c = pmCoef($P, $cote(2, [10], [], [2]), $y);
verifie('le produit exclu ne compte plus', $c['lot'] === 2.5 && $c['nX'] === 1 && $c['nY'] === 2, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_PRICE', 'valeur' => 2.12]);
verifie('prix fixe saisi TTC, ramené HT (2,12 € → 2,00 €)', $c['lot'] === 2.67, json_encode($c));
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 50]);
verifie('remise de 50 %', $c['lot'] === 2.67);
$c = pmCoef($P, $cote(1, [], [1]), $cote(1, [], [4]) + ['type' => 'FIXED_AMOUNT_DISCOUNT', 'valeur' => 1.06]);
verifie('remise de 1,06 € TTC', $c['lot'] === 3.33, json_encode($c));
$c = pmCoef($P, $cote(1, [20]), $cote(1, [20]) + ['type' => 'FREE', 'valeur' => null]);
verifie('un produit sans coût de recette nommé, pas chiffré', $c['sansCout'] === ['Tarte sans fiche'] && $c['lot'] === 2.0, json_encode($c));
$c = pmCoef($P, $cote(2, [30]), $cote(1, [30]) + ['type' => 'FREE', 'valeur' => null]);
// Le pain, chiffré au réseau : 3 pains, le 3e offert — 5,00 € HT pour 1,50 € de matière.
verifie('chiffré au coût du réseau, nommé ; sans coût et sans prix nommés à part', $c['lot'] === 3.33 && $c['coutReseau'] === ['Pain'] && $c['sansCout'] === ['Baguette'] && $c['sansPrix'] === ['Sans prix'], json_encode($c, JSON_UNESCAPED_UNICODE));
$c = pmCoef($P, $cote(1, [], [7]), $cote(1, [], [8]) + ['type' => 'FREE', 'valeur' => null]);
verifie('rien de chiffrable : la raison dit quels produits et pourquoi', $c['lot'] === null && pmManqueTxt($c) === 'sans coût de recette au panel, ni pour le magasin ni pour le réseau — Baguette ; sans prix au panel — Sans prix', pmManqueTxt($c));
$c = pmCoef($P, $cote(1, [], [3]), $cote(1, [], [3]) + ['type' => 'FREE', 'valeur' => null]);
verifie('rien de chiffrable : pas de coefficient', $c['lot'] === null && $c['paires'] === 0);

echo "Les règles du magasin\n";
$now = date('Y-m-d H:i');
$j = static fn (int $n, string $h = '00:00') => date('Y-m-d', strtotime("+$n day")) . ' ' . $h;
$base = pmPropre(['nom' => 'Viennoiseries 2+1', 'x' => ['q' => 2, 'cats' => [['id' => 10]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 10]]], 'du' => $j(1), 'au' => $j(7, '23:59')], 4);
$coefOk = ['lot' => 2.4, 'x' => 'a', 'y' => 'b', 'paires' => 1, 'nX' => 1, 'nY' => 1];
verifie('une demande complète passe', pmErreurs($base, [], $coefOk, $now) === [], json_encode(pmErreurs($base, [], $coefOk, $now), JSON_UNESCAPED_UNICODE));
verifie('sans fin : refusée', count(pmErreurs(['au' => null] + $base, [], $coefOk, $now)) === 1);
verifie('15 jours : acceptée (plus de limite à 14 jours, 18/10/2026)', pmErreurs(['au' => $j(15)] + $base, [], $coefOk, $now) === []);
verifie('un an (366 jours) : acceptée', pmErreurs(['au' => $j(366, '23:59')] + $base, [], $coefOk, $now) === []);
$e = pmErreurs(['au' => $j(367)] + $base, [], $coefOk, $now);
verifie('367 jours : refusée, la durée dite', count($e) === 1 && str_contains($e[0], '367 jours') && str_contains($e[0], 'un an'), json_encode($e, JSON_UNESCAPED_UNICODE));
$autres = [['shop' => 4, 'nom' => 'A', 'statut' => 'validee', 'du' => $j(0), 'au' => $j(3)], ['shop' => 4, 'nom' => 'B', 'statut' => 'a_valider', 'du' => $j(2), 'au' => $j(9)], ['shop' => 4, 'nom' => 'C', 'statut' => 'brouillon', 'du' => $j(1), 'au' => $j(5)],
    ['shop' => 7, 'nom' => 'D', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(5)]];
$e = pmErreurs($base, $autres, $coefOk, $now);
verifie('deux autres en même temps : refusée, les deux nommées (pas le brouillon)', count($e) === 1 && str_contains($e[0], '« A », « B »'), json_encode($e, JSON_UNESCAPED_UNICODE));
verifie('une seule autre en même temps : acceptée (celle d’un autre magasin ne compte pas)', pmErreurs($base, array_slice($autres, 1), $coefOk, $now) === []);
$autres[] = ['shop' => 9, 'shops' => [9, 4], 'nom' => 'E', 'statut' => 'validee', 'du' => $j(1), 'au' => $j(2)];
verifie('une promotion d’un autre magasin qui couvre aussi celui-ci compte', count(pmErreurs($base, array_slice($autres, 1), $coefOk, $now)) === 1);
$e = pmErreurs(['shops' => [4, 7]] + $base, [$autres[1], $autres[3], ['shop' => 7, 'nom' => 'F', 'statut' => 'a_valider', 'du' => $j(1), 'au' => $j(3)]], $coefOk, $now);
verifie('deux magasins : la règle des deux à la fois tenue magasin par magasin', count($e) === 1 && str_contains($e[0], '« D », « F »'), json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['shops' => [5, 4, 6, 7, 8, 9]], 4);
verifie('le magasin qui écrit, lui seul : les autres magasins envoyés sont ignorés', $d['shops'] === [4]);
$e = pmErreurs($base, [], ['lot' => 1.67, 'x' => 'Pain au chocolat', 'y' => 'Pain au chocolat'] + $coefOk, $now);
verifie('coefficient sous ×2 : plus de refus, il ne bloque plus (18/10/2026)', $e === [], json_encode($e, JSON_UNESCAPED_UNICODE));
$e = pmErreurs(pmPropre(['y' => ['type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 120]], 4), [], null, $now);
verifie('vide : nom, X, Y, valeur, dates demandés', count($e) === 6, json_encode($e, JSON_UNESCAPED_UNICODE));
$d = pmPropre(['creneaux' => [['jours' => [5, 1, 9], 'de' => '07:00', 'a' => '10:00'], ['jours' => [2], 'de' => '12:00', 'a' => '11:00']], 'maxParTicket' => '3', 'du' => '2026-10-20T08:30'], 4);
verifie('créneaux nettoyés (jours triés, 9 et le créneau à l’envers écartés)', $d['creneaux'] === [['jours' => [1, 5], 'de' => '07:00', 'a' => '10:00']], json_encode($d['creneaux']));
verifie('date du navigateur relue, nombre max par ticket', $d['du'] === '2026-10-20 08:30' && $d['maxParTicket'] === 3);

echo "Le parcours d'une promotion, toute au panel (18/10/2026)\n";
$GLOBALS['reglages'] = [];
$oublie = static function (): void { unset($GLOBALS['pvReglesMemo']); };
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Pains au chocolat 2+1', 'du' => $j(1), 'au' => $j(5),
    'x' => ['q' => 2, 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2, 'nom' => 'Pain au chocolat']]]];
$r = @wr_promos_magasin();
verifie('sans le compte admin du panel : rien ne s’enregistre (503)', str_contains((string) ($r['error'] ?? ''), 'panel') && !isset($r['demande']), json_encode($r, JSON_UNESCAPED_UNICODE));
ErpApi::$actif = true; $oublie();
$r = wr_promos_magasin();
verifie('un brouillon : écrit au panel, inactif, coefficient calculé', ($r['demande']['statut'] ?? '') === 'brouillon' && $r['demande']['id'] === 'p30' && $r['coef']['lot'] === 1.67
    && (ErpApi::$promos[30]['status'] ?? '') === 'INACTIVE' && !in_array('PUT /admin/promotions/buy-x-get-y/30', ErpApi::$appels, true), json_encode([$r, ErpApi::$appels], JSON_UNESCAPED_UNICODE));
verifie('rien gardé au cockpit', !isset($GLOBALS['reglages']['promosMagasin']));
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => ''];
$r = @wr_promos_magasin();
verifie('un brouillon incomplet : ce que le panel exige est demandé (422), rien créé', ($r['error'] ?? '') === 'à compléter' && count($r['erreurs']) >= 3 && count(ErpApi::$promos) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '4']; $l = ep_promos_magasin_demandes();
verifie('la liste du magasin la relit au panel, dans la forme de l’assistant', count($l['demandes']) === 1 && $l['demandes'][0]['id'] === 'p30' && $l['demandes'][0]['statut'] === 'brouillon'
    && $l['demandes'][0]['x']['prods'][0]['id'] === 2 && $l['demandes'][0]['du'] === $j(1) && $l['panel'] === [] && $l['regles']['coefMin'] === 2.0, json_encode($l, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '5']; $l = ep_promos_magasin_demandes(); $_GET = [];
verifie('un autre magasin ne la voit pas', $l['demandes'] === []);
ErpApi::$appels = [];
$GLOBALS['corps'] = ['shop' => 4, 'id' => 'p30', 'action' => 'envoyer', 'nom' => 'Pains au chocolat 3+1', 'du' => $j(1), 'au' => $j(5),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('le brouillon enregistré : la même promotion du panel (PUT), relue, activée — en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['panelId'] === 30 && $r['panelErreur'] === null
    && !in_array('POST /admin/promotions/buy-x-get-y', ErpApi::$appels, true) && ErpApi::$promos[30]['status'] === 'ACTIVE' && ErpApi::$promos[30]['name'] === 'Pains au chocolat 3+1', json_encode([$r, ErpApi::$appels], JSON_UNESCAPED_UNICODE));
$GLOBALS['corps']['action'] = 'brouillon';
$r = @wr_promos_magasin();
verifie('en caisse : pas de retour en brouillon (409)', str_contains((string) ($r['error'] ?? ''), 'en caisse'));
$_GET = ['shop' => '4', 'id' => 'p30']; $r = @wr_promos_magasin_retirer(); $_GET = [];
verifie('en caisse : ne se supprime pas d’ici', str_contains((string) ($r['error'] ?? ''), 'en caisse') && isset(ErpApi::$promos[30]));
$GLOBALS['corps'] = ['shop' => 5, 'id' => 'p30', 'action' => 'envoyer'] + $GLOBALS['corps'];
$r = @wr_promos_magasin();
verifie('la promotion d’un autre magasin ne se modifie pas (404)', ($r['error'] ?? '') === 'promotion introuvable' && ErpApi::$promos[30]['shop_ids'] === [4]);
$GLOBALS['corps'] = ['shop' => 4, 'id' => 'pm1a2b3c4d', 'action' => 'brouillon'];
$r = @wr_promos_magasin();
verifie('un ancien numéro du cockpit (« pm… ») : introuvable', ($r['error'] ?? '') === 'promotion introuvable');
// Un brouillon, puis supprimé : il quitte le panel et la liste.
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'brouillon', 'nom' => 'Essai', 'du' => $j(20), 'au' => $j(22),
    'x' => ['q' => 1, 'prods' => [['id' => 1]]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 1]]]];
$r = wr_promos_magasin();
$idb = $r['demande']['id'] ?? '';
$_GET = ['shop' => '5', 'id' => $idb]; $r2 = @wr_promos_magasin_retirer();
verifie('le brouillon d’un autre magasin ne se supprime pas', ($r2['error'] ?? '') === 'promotion introuvable');
ErpApi::$sansDelete = true;
$_GET = ['shop' => '4', 'id' => $idb]; $r2 = @wr_promos_magasin_retirer();
verifie('le panel ne supprime pas : dit, il reste inactif', str_contains((string) ($r2['error'] ?? ''), 'ne supprime pas') && isset(ErpApi::$promos[31]));
ErpApi::$sansDelete = false;
$r2 = wr_promos_magasin_retirer();
$_GET = ['shop' => '4']; $l = ep_promos_magasin_demandes(); $_GET = [];
verifie('un brouillon se supprime au panel (DELETE), et quitte la liste', ($r2['ok'] ?? false) === true && !isset(ErpApi::$promos[31]) && array_column($l['demandes'], 'id') === ['p30'], json_encode([$r2, array_column($l['demandes'], 'id')]));
// Un produit sans coût (la tarte) seul : rien ne se chiffre, refusée.
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Tarte 1+1', 'du' => $j(1), 'au' => $j(5),
    'x' => ['q' => 1, 'prods' => [['id' => 3]]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 3]]]];
$r = @wr_promos_magasin();
verifie('rien ne se chiffre (la tarte seule, sans coût) : refusée, la tarte nommée', ($r['error'] ?? '') === 'à compléter' && count($r['erreurs']) === 1 && str_contains($r['erreurs'][0], 'ne se chiffre') && str_contains($r['erreurs'][0], 'Tarte sans fiche'), json_encode($r, JSON_UNESCAPED_UNICODE));
// La règle des deux à la fois, sur les promotions du magasin au panel.
$pc = static fn (string $nom, int $de, int $a, array $plus = []) => $plus + ['shop' => 4, 'action' => 'envoyer', 'nom' => $nom, 'du' => $j($de, '07:00'), 'au' => $j($a, '19:00'),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$GLOBALS['corps'] = $pc('Deuxième', 3, 8);
$r = wr_promos_magasin();
$GLOBALS['corps'] = $pc('Troisième', 4, 6);
$r2 = @wr_promos_magasin();
verifie('deux en caisse sur ces dates : la troisième refusée, les deux nommées', ($r['demande']['statut'] ?? '') === 'en_caisse' && ($r2['error'] ?? '') === 'à compléter' && str_contains($r2['erreurs'][0], '« Deuxième », « Pains au chocolat 3+1 »'), json_encode($r2, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = $pc('Troisième', 4, 6, ['action' => 'brouillon']);
$r2 = wr_promos_magasin();
verifie('en brouillon (inactive au panel), elle ne compte pas : gardée', ($r2['demande']['statut'] ?? '') === 'brouillon');
$GLOBALS['corps'] = $pc('Troisième', 4, 6, ['id' => $r2['demande']['id']]);
$r3 = @wr_promos_magasin();
verifie('et ne passe en caisse que si la règle tient', ($r3['error'] ?? '') === 'à compléter' && ErpApi::$promos[$r2['demande']['panelId']]['status'] === 'INACTIVE');

echo "L'écriture au panel\n";
ErpApi::$appels = [];
$GLOBALS['corps'] = $pc('Panel 3+1', 30, 32);
$r = wr_promos_magasin();
$P30 = ErpApi::$promos[$r['demande']['panelId'] ?? 0] ?? [];
verifie('enregistrée → créée au panel, relue, activée : en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['panelErreur'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
verifie('créée inactive, puis activée (POST, puis PUT)', array_values(array_filter(ErpApi::$appels, static fn ($a) => !str_starts_with($a, 'GET'))) === ['POST /admin/promotions/buy-x-get-y', 'PUT /admin/promotions/buy-x-get-y/' . $P30['id']] && ($P30['status'] ?? '') === 'ACTIVE', json_encode(ErpApi::$appels));
verifie('au format de la fiche du panel', ($P30['promotion_type'] ?? '') === 'BUY_X_GET_Y' && $P30['shop_scope_type'] === 'SELECTED_SHOPS' && $P30['shop_ids'] === [4]
    && $P30['trigger'] === ['quantity' => 3, 'product_ids' => [2], 'category_ids' => [], 'excluded_product_ids' => []]
    && $P30['reward']['type'] === 'PERCENTAGE_DISCOUNT' && (float) $P30['reward']['value'] === 30.0 && $P30['reward']['selection_strategy'] === 'CHEAPEST'
    && $P30['valid_from'] === $j(30, '07:00') . ':00' && $P30['valid_to'] === $j(32, '19:00') . ':00' && $P30['activation_mode'] === 'AUTOMATIC' && $P30['priority'] === 100, json_encode($P30, JSON_UNESCAPED_UNICODE));
$n = count(ErpApi::$promos);
ErpApi::$refus = 'HTTP 422 : The trigger.product ids field is invalid';
$GLOBALS['corps'] = $pc('Panel refusée', 40, 42);
$r = @wr_promos_magasin();
verifie('le panel refuse : l’erreur du panel dite (502), rien de créé, rien gardé', !isset($r['demande']) && str_contains((string) ($r['error'] ?? ''), 'trigger.product ids') && count(ErpApi::$promos) === $n, json_encode($r, JSON_UNESCAPED_UNICODE));
ErpApi::$refus = null; ErpApi::$perdCreneaux = true;
$GLOBALS['corps'] = $pc('Panel créneaux', 50, 52, ['creneaux' => [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']]]);
$r = wr_promos_magasin();
$idc = $r['demande']['id'] ?? '';
verifie('le panel ne reprend pas les créneaux : laissée inactive au panel (un brouillon), l’écart dit', ($r['demande']['statut'] ?? '') === 'brouillon' && str_contains((string) $r['panelErreur'], 'créneaux') && ErpApi::$promos[$r['demande']['panelId']]['status'] === 'INACTIVE', json_encode($r, JSON_UNESCAPED_UNICODE));
ErpApi::$perdCreneaux = false; ErpApi::$appels = [];
$GLOBALS['corps'] = $pc('Panel créneaux', 50, 52, ['id' => $idc, 'creneaux' => [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']]]);
$r = wr_promos_magasin();
verifie('reprise depuis les brouillons : la même promotion mise à jour (PUT), en caisse, les créneaux relus', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['demande']['id'] === $idc
    && !in_array('POST /admin/promotions/buy-x-get-y', ErpApi::$appels, true) && $r['demande']['creneaux'] === [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']], json_encode([$r, ErpApi::$appels], JSON_UNESCAPED_UNICODE));
ErpApi::$sansPut = true; ErpApi::$appels = [];
$GLOBALS['corps'] = $pc('Panel sans PUT', 60, 62);
$r = wr_promos_magasin();
$i = $r['demande']['panelId'] ?? 0;
verifie('pas de PUT au panel : activée par PATCH status', ($r['demande']['statut'] ?? '') === 'en_caisse' && array_values(array_filter(ErpApi::$appels, static fn ($a) => !str_starts_with($a, 'GET'))) === ['POST /admin/promotions/buy-x-get-y', 'PUT /admin/promotions/buy-x-get-y/' . $i, 'PATCH /admin/promotions/buy-x-get-y/' . $i], json_encode(ErpApi::$appels));
ErpApi::$sansPut = false;
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Pâtisserie, panel', 'du' => $j(70), 'au' => $j(72),
    'x' => ['q' => 1, 'cats' => [['id' => 20]]], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]]]];
$r = wr_promos_magasin();
$pid = $r['demande']['panelId'] ?? 0;
verifie('la tarte sans coût sort d’office : au panel dans les exclusions, en caisse, nommée', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['exclusAuto'] === ['Tarte sans fiche'] && $r['coef']['lot'] === 2.0
    && (ErpApi::$promos[$pid]['trigger']['excluded_product_ids'] ?? null) === [3] && ErpApi::$promos[$pid]['reward']['excluded_product_ids'] === [3] && array_column($r['demande']['x']['exclus'], 'id') === [3], json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = ['shop' => 4, 'shops' => [4, 5], 'action' => 'envoyer', 'nom' => 'Pains au chocolat, Halle', 'du' => $j(76), 'au' => $j(78),
    'x' => ['q' => 3, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 30, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('un autre magasin demandé : ignoré, la promotion ne vaut que pour Halle (×2,31, ses prix)', (ErpApi::$promos[$r['demande']['panelId'] ?? 0]['shop_ids'] ?? null) === [4] && $r['coef']['lot'] === 2.31 && $r['coef']['magasin'] === 4, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "La liste du panel, page après page (18/10/2026)\n";
verifie('le numéro du navigateur relu (p21, 21), un autre écarté', pmNumero('p21') === 21 && pmNumero('21') === 21 && pmNumero(21) === 21 && pmNumero('pm1a2b3c') === null && pmNumero('') === null && pmNumero('p0') === null);
$tous = array_keys(ErpApi::$promos); sort($tous);
ErpApi::$parPage = 2; $oublie();
$L = pvListe();
verifie('une liste en pages de 2 : toutes les pages lues', array_keys($L['ids']) === $tous, json_encode([array_keys($L['ids']), $tous]));
ErpApi::$parPage = null; ErpApi::$listeMax = $tous[count($tous) - 3];
ErpApi::$appels = [];
$L = pvListe();
verifie('les deux plus récentes que la liste ne rend pas : lues aux numéros qui suivent', array_keys($L['ids']) === $tous && count($L['fiches']) === 2
    && count(array_filter(ErpApi::$appels, static fn ($a) => str_starts_with($a, 'GET'))) === 5, json_encode([array_keys($L['ids']), ErpApi::$appels]));
$R = pvRegles(true);
verifie('les règles relues à frais les comptent', count($R['regles']) === count($tous) && $R['erreur'] === null);
$oublie(); $_GET = ['shop' => '4', 'frais' => '1']; $l = ep_promos_magasin_demandes(); $_GET = [];
verifie('l’onglet relu au panel (?frais=1) dit l’heure de la lecture', preg_match('/^\d\d:\d\d$/', (string) ($l['lu'] ?? '')) === 1 && ($l['luTs'] ?? 0) > time() - 60 && ($l['luJour'] ?? '') === date('Y-m-d'), json_encode([$l['lu'] ?? null, $l['luTs'] ?? null]));
ErpApi::$listeMax = null;

echo "La vue de la marque\n";
$_GET = []; $m = ep_promos_magasin_marque();
$p1 = array_column($m['demandes'], null, 'id')['p30'] ?? [];
verifie('les promotions de chaque magasin, lues au panel, avec le coefficient d’aujourd’hui', count($m['demandes']) === count(array_filter(ErpApi::$promos, static fn ($f) => count($f['shop_ids']) === 1))
    && ($p1['coef']['lot'] ?? null) === 2.31 && ($p1['statut'] ?? '') === 'en_caisse' && isset($m['noms']['4']), json_encode($p1, JSON_UNESCAPED_UNICODE));
$_GET = ['statut' => 'brouillon']; $m = ep_promos_magasin_marque(); $_GET = [];
verifie('filtrées par statut', $m['demandes'] !== [] && array_unique(array_column($m['demandes'], 'statut')) === ['brouillon']);

echo "Les portions (½, ¼)\n";
$d = pmPropre(['x' => ['q' => 4, 'cats' => [['id' => 20]], 'portion' => 'one_quarter'], 'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]], 'portion' => 'TROIS_QUARTS']], 4);
verifie('la portion gardée (¼), une inconnue écartée', $d['x']['portion'] === 'ONE_QUARTER' && $d['y']['portion'] === null);
$q4 = $cote(4, [20]) + ['portion' => 'ONE_QUARTER']; $q1 = $cote(1, [20]) + ['type' => 'FREE', 'valeur' => null, 'portion' => 'ONE_QUARTER'];
$c = pmCoef($P, $q4, $q1);
// 4 quarts d'éclair payés (4 × ¼ × 4,00 € = 4,00 €) pour 5 quarts de matière (5 × ¼ × 1,00 € = 1,25 €) : ×3,20 — le coefficient de la pièce ÷ 1,25.
verifie('4 quarts achetés, le 5e offert : le coefficient de la pièce ÷ 1,25', $c['lot'] === 3.2 && $c['paye'] === 4.0 && $c['cout'] === 1.25, json_encode($c));
$c = pmCoef($P, $q4, $cote(1, [20]) + ['type' => 'FIXED_PRICE', 'valeur' => 0.53, 'portion' => 'ONE_QUARTER']);
verifie('un prix fixe s’applique à la part (le quart à 0,53 € TTC = 0,50 € HT)', $c['lot'] === 3.6, json_encode($c));
ErpApi::$appels = [];
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Quarts 4+1', 'du' => $j(90), 'au' => $j(92), 'x' => ['q' => 4, 'cats' => [['id' => 20]], 'portion' => 'ONE_QUARTER'],
    'y' => ['q' => 1, 'type' => 'FREE', 'cats' => [['id' => 20]], 'portion' => 'ONE_QUARTER']];
$r = wr_promos_magasin();
$pp = ErpApi::$promos[$r['demande']['panelId'] ?? 0] ?? [];
verifie('au panel : chaque cible porte sa portion, comme « 4 Quarts + 1 Gratuit »', ($r['demande']['statut'] ?? '') === 'en_caisse' && ($pp['trigger']['category_targets'] ?? null) === [['id_category' => 20, 'portion_scope' => 'ONE_QUARTER']]
    && $pp['reward']['category_targets'] === [['id_category' => 20, 'portion_scope' => 'ONE_QUARTER']] && $pp['trigger']['category_ids'] === [20], json_encode([$r['demande'] ?? $r, $pp['trigger'] ?? null], JSON_UNESCAPED_UNICODE));
ErpApi::$perdPortion = true;
$GLOBALS['corps']['nom'] = 'Quarts perdus'; $GLOBALS['corps']['du'] = $j(100); $GLOBALS['corps']['au'] = $j(102);
$r = wr_promos_magasin();
verifie('le panel ne reprend pas la portion : laissée inactive, l’écart dit', ($r['demande']['statut'] ?? '') === 'brouillon' && str_contains((string) $r['panelErreur'], 'portion'), json_encode($r, JSON_UNESCAPED_UNICODE));
ErpApi::$perdPortion = false;

echo "Le coefficient ne bloque plus (18/10/2026)\n";
ErpApi::$actif = true;
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Sous ×2 au panel', 'du' => $j(110), 'au' => $j(112),
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'FREE', 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('un lot à ×1,67 part au panel et passe en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && $r['coef']['lot'] === 1.67, json_encode($r, JSON_UNESCAPED_UNICODE));

echo "Les créneaux au panel, modifier une promotion en caisse (18/10/2026)\n";
$GLOBALS['corps'] = ['shop' => 4, 'action' => 'envoyer', 'nom' => 'Goûter du samedi', 'du' => $j(120), 'au' => $j(130),
    'creneaux' => [['jours' => [6, 7], 'de' => '15:00', 'a' => '18:00']],
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 50, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
$pid = $r['demande']['panelId'] ?? 0;
verifie('les créneaux partent en time_from / time_to, un par jour', ($r['demande']['statut'] ?? '') === 'en_caisse'
    && (ErpApi::$promos[$pid]['schedule'] ?? null) === [['day_of_week' => 6, 'time_from' => '15:00:00', 'time_to' => '18:00:00'], ['day_of_week' => 7, 'time_from' => '15:00:00', 'time_to' => '18:00:00']], json_encode([$r['demande'] ?? $r, ErpApi::$promos[$pid]['schedule'] ?? null]));
ErpApi::$formatHeure = 'H:i';
$GLOBALS['corps']['nom'] = 'Goûter sans secondes'; $GLOBALS['corps']['du'] = $j(140); $GLOBALS['corps']['au'] = $j(150);
$r = wr_promos_magasin();
$pid2 = $r['demande']['panelId'] ?? 0;
verifie('le panel refuse l’heure avec secondes : renvoyée sans, en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && (ErpApi::$promos[$pid2]['schedule'][0]['time_from'] ?? null) === '15:00', json_encode([$r['panelErreur'] ?? $r, ErpApi::$promos[$pid2]['schedule'] ?? null]));
ErpApi::$formatHeure = null;
// La première, en caisse, modifiée : le même numéro au panel, mis à jour (PUT), toujours en caisse.
ErpApi::$appels = [];
$GLOBALS['corps'] = ['id' => 'p' . $pid, 'shop' => 4, 'action' => 'envoyer', 'nom' => 'Goûter du samedi, −40 %', 'du' => $j(120), 'au' => $j(130),
    'creneaux' => [['jours' => [6], 'de' => '15:00', 'a' => '18:00']],
    'x' => ['q' => 2, 'prods' => [['id' => 2]]], 'y' => ['q' => 1, 'type' => 'PERCENTAGE_DISCOUNT', 'valeur' => 40, 'prods' => [['id' => 2]]]];
$r = wr_promos_magasin();
verifie('une promotion en caisse se modifie : même n° au panel (PUT), toujours en caisse', ($r['demande']['statut'] ?? '') === 'en_caisse' && ($r['demande']['panelId'] ?? 0) === $pid
    && (ErpApi::$promos[$pid]['name'] ?? '') === 'Goûter du samedi, −40 %' && (ErpApi::$promos[$pid]['reward']['value'] ?? null) === 40.0 && !in_array('POST /admin/promotions/buy-x-get-y', ErpApi::$appels, true), json_encode([$r['demande'] ?? $r, ErpApi::$appels]));

echo "Les promotions par canal\n";
$regle = pvNormaliser(['id' => 11, 'name' => "Cookie's !", 'promotion_type' => 'BUY_X_GET_Y', 'status' => 'ACTIVE', 'priority' => 100, 'shop_scope_type' => 'ALL_SHOPS',
    'trigger' => ['quantity' => 2, 'category_ids' => [10]], 'reward' => ['quantity' => 1, 'category_ids' => [10], 'type' => 'FIXED_AMOUNT_DISCOUNT', 'value' => 1.40]]);
// Une ligne de ticket : [clé, pid, q, prix unitaire, remise, total] ; le produit 9 n'est dans aucune promotion.
$tk = static fn (int $t, float $rem, int $q = 3, int $pid = 1) => ['t' => $t, 'mn' => '10:00', 'l' => [[(string) $pid, $pid, $q, 2.40, $rem, $q * 2.40 - $rem]]];
$jours = ['2026-10-10' => [$tk(501, 1.40), $tk(502, 1.40), $tk(503, 2.80, 6), $tk(504, 0.99, 1, 9)], '2026-10-11' => [$tk(505, 1.40)], '2026-10-12' => null];
// 501 : une précommande au comptoir ; 502 : click & collect encaissé au magasin ; 505 : livraison ; une commande payée en ligne, sans ticket.
$cmd = [['canal' => 'compt', 'tx' => 501, 'statut' => 'remise'], ['canal' => 'cc', 'tx' => 502, 'statut' => 'remise'], ['canal' => 'liv', 'tx' => 505, 'statut' => 'livrée'], ['canal' => 'cc', 'tx' => 0, 'statut' => 'à préparer']];
$r = pmCanaux($jours, [$regle], $cmd, [1 => 10], [], 4);
$p = array_column($r['promos'], null, 'id');
verifie('au comptoir : 3 fois sur 2 tickets (6 cookies = 2 fois), 4,20 € de remise', ($p[11]['compt'] ?? null) === ['n' => 3, 'r' => 4.2, 't' => 2], json_encode($p[11] ?? $r));
verifie('le ticket d’une commande webshop : dans son canal (click & collect, livraison)', $p[11]['cc']['n'] === 1 && $p[11]['liv']['n'] === 1 && $p[11]['cc']['r'] === 1.4);
verifie('une remise qu’aucune promotion n’explique : à part, en dernier', $r['promos'][1]['id'] === 'x' && $r['promos'][1]['compt']['n'] === 1 && $r['promos'][1]['compt']['r'] === 0.99, json_encode($r['promos']));
verifie('jour par jour : comptoir et web, le jour pas lu marqué', $r['parJour'][0]['compt']['n'] === 4 && $r['parJour'][0]['web']['n'] === 1 && $r['parJour'][1]['web']['n'] === 1
    && $r['parJour'][2]['lu'] === false && $r['joursSans'] === ['2026-10-12'], json_encode($r['parJour']));
verifie('les commandes payées en ligne comptées à part', $r['enLigne'] === ['cc' => 1, 'liv' => 0] && $r['commandes'] === true && $r['total']['compt']['n'] === 4, json_encode([$r['enLigne'], $r['total']]));
$r = pmCanaux($jours, [$regle], null, [1 => 10], [], 4);
verifie('sans les commandes du panel : tout au comptoir, dit', $r['commandes'] === false && $r['total']['cc']['n'] === 0 && $r['total']['compt']['n'] === 6, json_encode($r['total']));

echo "La sonde des commandes payées en ligne\n";
$cmd = ['id' => 812, 'name' => 'Marie Dupont', 'customer_email' => 'marie@example.com', 'phone' => '0470', 'delivery_address' => ['street' => 'Rue X 1', 'city' => 'Halle'],
    'note' => 'sonner deux fois', 'is_webshop' => true, 'total_value' => 18.4, 'id_transaction' => null,
    'products' => [['id_product' => 101, 'name' => 'Cookie chocolat', 'quantity' => 3, 'unit_price' => 2.4, 'discount_value' => 1.4, 'customer_note' => 'sans noix']]];
$p = pmSondePropre($cmd);
verifie('rien de nominatif : nom, e-mail, téléphone, adresse, note retirés (la clé reste)', $p['name'] === '[retiré]' && $p['customer_email'] === '[retiré]' && $p['phone'] === '[retiré]'
    && $p['delivery_address'] === '[retiré]' && $p['note'] === '[retiré]' && !str_contains(json_encode($p, JSON_UNESCAPED_UNICODE), 'Marie') && !str_contains(json_encode($p), 'example.com'), json_encode($p, JSON_UNESCAPED_UNICODE));
verifie('dans les lignes, le nom du produit reste, la note du client part', $p['products'][0]['name'] === 'Cookie chocolat' && $p['products'][0]['customer_note'] === '[retiré]' && $p['total_value'] === 18.4);
$ch = pmSondeChemins($p, '/discount|promo/i');
verifie('les chemins des remises', $ch === ['products.0.discount_value' => 1.4], json_encode($ch));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
